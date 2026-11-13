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
console.log(`@aifinpay/gate ${packaged}: ${checks} checks match the fixtures and the PHP core, byte for byte.`);

function phpString(s) {
  return `'${s.replaceAll('\\', '\\\\').replaceAll("'", "\\'")}'`;
}
