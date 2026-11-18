// Replays @aifinpay/gate against the fixtures and the PHP core: same bytes, or exit 1.
// The published package, or GATE_DIST=<gate>/dist for a build that is not published yet.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const version = process.env.GATE_VERSION ?? '0.11.0';
const dir = join(root, '.local', `gate-${version}`);
const built = process.env.GATE_DIST;
if (!built && !existsSync(join(dir, 'node_modules/@aifinpay/gate'))) {
  mkdirSync(dir, { recursive: true });
  execFileSync('npm', ['init', '-y'], { cwd: dir, stdio: 'ignore' });
  execFileSync('npm', ['i', `@aifinpay/gate@${version}`, '--ignore-scripts', '--no-audit', '--no-fund'], { cwd: dir, stdio: 'ignore' });
}
const entry = built ?? join(dir, 'node_modules/@aifinpay/gate/dist');
const gate = await import(pathToFileURL(join(entry, 'index.js')));
const edge = await import(pathToFileURL(join(entry, 'edge.js')));
const packaged = JSON.parse(readFileSync(join(entry, '../package.json'), 'utf8')).version;
if (!built) assert.equal(packaged, version);

const php = (code) => execFileSync('php', ['-r', `define('ABSPATH','/');require '${root}/vendor/autoload.php';${code}`], { encoding: 'utf8', maxBuffer: 1 << 24 });
const receipts = JSON.parse(readFileSync(join(root, 'tests/fixtures/gate-receipt-parity.json'), 'utf8'));
const agents = JSON.parse(readFileSync(join(root, 'tests/fixtures/agent-parity.json'), 'utf8'));

let checks = 0;
for (const { args, body } of receipts.challenges) {
  const theirs = JSON.stringify(gate.buildChallenge(args));
  assert.equal(theirs, JSON.stringify(body), 'fixture differs from the published gate');
  const ours = php(`echo AiFinPay\\Core\\Json::encode(AiFinPay\\Core\\ReceiptGate::challenge(json_decode(${phpString(JSON.stringify(args.merchantId))}), json_decode(${phpString(JSON.stringify(args.resource))}), '${args.tier}', 'https://api.aifinpay.io', json_decode(${phpString(JSON.stringify(args.detail))})));`);
  assert.equal(ours, theirs, `PHP bytes differ for ${args.tier} ${args.detail}`);
  checks++;
}
for (const { scope, resource, path, covers } of receipts.scopes) {
  assert.equal(gate.scopeCovers(scope, resource, path), covers, `${scope} ${resource} ${path}`);
  checks++;
}
assert.deepEqual(edge.GATE_OPERATORS, agents.operators);
assert.deepEqual(edge.UA_CLASSES, agents.ua_classes);
assert.deepEqual(edge.AI_AGENT_UA_TABLE.map((e) => [e.marker, e.operator, e.ua_class]), agents.table);
checks += 3;
for (const { headers, result } of agents.classify) {
  const lower = Object.fromEntries(Object.entries(headers).map(([k, v]) => [k.toLowerCase(), v]));
  assert.deepEqual(edge.classifyAgent({ path: '/', method: 'GET', header: (n) => lower[n.toLowerCase()] }), result);
  checks++;
}
// A price per path: the gate in registry mode and the PHP core quote the same rule, tier and 402 for each path.
const priced = [
  [{ '/paid/*': 'standard', '/paid/premium/*': 'premium' }, ['/paid/a', '/paid/premium/x', '/PAID/PREMIUM/x', '/paid/premium', '/paid', '/paid/premium%3Fx', '/paid/premium%23x', '/paid/premiumx']],
  [{ '/*': 'standard', '/report': 'premium' }, ['/report', '/report%3Fx', '/report%253Fx', '/Report%23x', '/report/x', '/']],
  [{ '/*': 'premium', '/report': 'standard' }, ['/report%3Fx', '/report', '/other']],
  [{ '/docs/*': 'complex', '/docs/a': 'premium', '/docs/a/*': 'standard' }, ['/docs/a', '/docs/a/b', '/docs/a%3Fb', '/docs/b', '/docs/a/b%3Fc', '/docs']],
  [{ '/wp-json/aifinpay-demo/v1/*': 'standard', '/wp-json/aifinpay-demo/v1/gpu-job': 'complex', '/wp-json/aifinpay-demo/v1/license-record': 'premium' }, ['/wp-json/aifinpay-demo/v1/catalog', '/wp-json/aifinpay-demo/v1/gpu-job', '/wp-json/aifinpay-demo/v1/license-record', '/wp-json/aifinpay-demo/v1/license-record%3Fx', '/wp-json/aifinpay-demo/v1/gpu-job/1']],
];
const merchantId = 'mrch_0123456789abcdef';
const crawler = (n) => (n.toLowerCase() === 'user-agent' ? 'Mozilla/5.0 (compatible; GPTBot/1.2; +https://openai.com/gptbot)' : undefined);
for (const [tiers, paths] of priced) {
  const resources = Object.entries(tiers).map(([route_pattern, tier]) => ({ route_pattern, tier }));
  const registry = { neverLoaded: false, match: (p, o) => gate.matchResource(resources, p, o), operatorPolicy: () => null, networkMode: () => null };
  const g = gate.createGate({ merchantId, registry, jwks: { keys: [] }, store: { incrBy: async () => 1 } });
  for (const path of paths) {
    const theirs = await g({ path, method: 'GET', header: crawler });
    const ours = JSON.parse(php(`$t=json_decode(${phpString(JSON.stringify(tiers))},true);$s=AiFinPay\\Core\\Paths::resolve([AiFinPay\\Core\\Paths::inspect(${phpString(path)})['path']],array_keys($t),[],$t);echo json_encode($s?[$s['rule'],$t[$s['rule']],AiFinPay\\Core\\Json::encode(AiFinPay\\Core\\ReceiptGate::challenge('${merchantId}',$s['rule'],$t[$s['rule']],'https://api.aifinpay.io'))]:null);`));
    const match = gate.matchResource(resources, path);
    assert.ok(match && ours, `${path}: both price it`);
    assert.deepEqual([ours[0], ours[1]], [match.route_pattern, match.tier], `${path}: rule and tier`);
    assert.equal(theirs.status, 402, path);
    assert.equal(ours[2], JSON.stringify(theirs.body), `${path}: the 402 bytes`);
    checks++;
  }
}
// `about` (gate 0.13.0 and later): the shared vectors and its place in the 402. A lone surrogate cannot cross JSON into PHP; PHPUnit covers it.
if (gate.pageAbout) {
  const wellFormed = (v) => (typeof v === 'string' ? v.isWellFormed() : v && typeof v === 'object' ? Object.values(v).every(wellFormed) : true);
  const vectors = JSON.parse(readFileSync(join(root, 'tests/fixtures/about-v1.json'), 'utf8')).vectors.filter((v) => wellFormed(v.value));
  const ours = JSON.parse(php(`echo json_encode(array_map(fn($v)=>AiFinPay\\Core\\Json::encode(AiFinPay\\Core\\About::from($v)),json_decode(${phpString(JSON.stringify(vectors.map((v) => v.value)))},true)));`));
  vectors.forEach((v, i) => {
    assert.equal(ours[i], JSON.stringify(gate.pageAbout(v.value)), `about vector ${v.name}`);
    checks++;
  });
  const about = { title: 'Fish &amp; <b>chips</b>', description: 'Fresh.' };
  const theirs = JSON.stringify(gate.buildChallenge({ merchantId, resource: '/paid', tier: 'standard', weight: 1, apiBase: 'https://api.aifinpay.io', about }));
  const mine = php(`echo AiFinPay\\Core\\Json::encode(AiFinPay\\Core\\About::insert(AiFinPay\\Core\\ReceiptGate::challenge('${merchantId}','/paid','standard','https://api.aifinpay.io'),AiFinPay\\Core\\About::from(json_decode(${phpString(JSON.stringify(about))},true))));`);
  assert.equal(mine, theirs, 'the 402 with about');
  checks++;
}
console.log(`@aifinpay/gate ${packaged}: ${checks} checks match the fixtures and the PHP core, byte for byte.`);

function phpString(s) {
  return `'${s.replaceAll('\\', '\\\\').replaceAll("'", "\\'")}'`;
}
