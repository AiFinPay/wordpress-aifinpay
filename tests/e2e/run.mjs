// A fresh real WordPress, the plugin installed from its zip, a real admin session, real HTTP.
// LOCAL MOCK API: no chain, no real payment, no live AiFinPay.
// Usage: node tests/e2e/run.mjs sqlite|mysql [--only=section,section]
import assert from 'node:assert/strict';
import { execFile, execFileSync, spawn } from 'node:child_process';
import { generateKeyPairSync, randomBytes, randomUUID, sign } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { createServer, request as httpRequest } from 'node:http';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { promisify } from 'node:util';

const exec = promisify(execFile);
const root = dirname(dirname(dirname(fileURLToPath(import.meta.url))));
const db = process.argv[2] ?? 'sqlite';
const only = (process.argv.find((a) => a.startsWith('--only=')) ?? '').slice(7).split(',').filter(Boolean);
const name = `e2e-${db}`;
const PORT = db === 'mysql' ? 8192 : 8191;
const API = PORT + 1000;
const DBHOST = '127.0.0.1:33190';
const site = join(root, '.local', name);
const web = join(site, 'web');
const base = `http://127.0.0.1:${PORT}`;
const apiBase = `http://127.0.0.1:${API}`;

const wp = async (...args) => (await exec('wp', [`--path=${web}`, ...args], { maxBuffer: 1 << 24 })).stdout.trim();
const wpEval = (code) => wp('eval', code);

const MERCHANT = 'mrch_wp_e2e';
const SECRET = 'msec_' + 'f0'.repeat(24);
const CODE = 'AIFP-7K2M-QX9D-4HNC-T0RB';
const AGENT = '0x2714ca06600db9c5167eafa37216716a8adc83fe';
const MARK = 'PAID CONTENT MARKER';
const GPTBOT = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)';
const CLAUDE = 'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)';
const CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

// ── Mock AiFinPay API ───────────────────────────────────────────────────────
const { privateKey, publicKey } = generateKeyPairSync('ed25519');
const jwk = { ...publicKey.export({ format: 'jwk' }), kid: 'e2e-key', alg: 'EdDSA', use: 'sig' };
const b64 = (v) => Buffer.from(JSON.stringify(v)).toString('base64url');
const mint = (patch = {}, key = privateKey) => {
  const now = Math.floor(Date.now() / 1000);
  const a = b64({ alg: 'EdDSA', typ: 'JWT', kid: jwk.kid });
  const claims = { iss: 'https://api.aifinpay.io', aud: MERCHANT, sub: AGENT, payer: AGENT, iat: now, exp: now + 600, resource: '/paid', scope: 'exact', network_mode: 'test', receipt_id: 'rcpt_' + randomBytes(8).toString('hex'), unit_quota: 5, ...patch };
  const b = b64(claims);
  return `${a}.${b}.${sign(null, Buffer.from(`${a}.${b}`), key).toString('base64url')}`;
};
const state = {
  record: { merchant_id: MERCHANT, name: 'E2E site', network_mode: 'test', site_url: `${base}/`, site_verified: false },
  recordDown: false, jwksDown: false, codeUsed: false, resources: new Map(), events: [], calls: [], ingest: null,
};
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const api = createServer(async (req, res) => {
  const json = (s, b) => { res.writeHead(s, { 'content-type': 'application/json' }); res.end(JSON.stringify(b)); };
  let raw = '';
  for await (const c of req) raw += c;
  state.calls.push({ method: req.method, url: req.url, headers: req.headers, body: raw });
  const authed = req.headers['aifp-merchant-secret'] === SECRET;
  if (req.url === '/v1/merchants/connect' && req.method === 'POST') {
    const ok = JSON.parse(raw || '{}').code === CODE.replaceAll('-', '') && !state.codeUsed;
    if (ok) state.codeUsed = true;
    return ok ? json(200, { merchant_id: MERCHANT, secret: SECRET }) : json(400, { error: 'invalid_connect_code' });
  }
  if (req.url === `/v1/merchants/${MERCHANT}` && req.method === 'GET') return state.recordDown ? json(503, {}) : json(200, state.record);
  if (req.url === '/.well-known/jwks.json') return state.jwksDown ? json(503, {}) : json(200, { keys: [jwk] });
  if (req.url === `/v1/merchants/${MERCHANT}/resources` && req.method === 'POST') {
    if (!authed) return json(403, {});
    const r = JSON.parse(raw);
    if (typeof r.route_pattern !== 'string' || !['page', 'api'].includes(r.type) || r.upsert !== true) return json(400, {});
    state.resources.set(r.route_pattern, r);
    return json(201, { resource: r, durable: true });
  }
  if (req.url === `/v1/merchants/${MERCHANT}/gate-events` && req.method === 'POST') {
    if (state.ingest) return json(state.ingest, {});
    if (!authed) return json(403, {});
    const body = JSON.parse(raw);
    for (const e of body.events) {
      const keys = Object.keys(e).sort().join();
      if (keys !== 'at,id,kind,operator,resource,ua_class,verified' || !UUID.test(e.id) || !['402', 'serve'].includes(e.kind)
        || !state.resources.has(e.resource) || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(e.at)) return json(400, { error: 'AIFP-400', detail: 'resource must match a registered canonical route_pattern' });
    }
    state.events.push(...body.events);
    return json(200, { accepted: body.events.length, duplicates: 0 });
  }
  // The agent's side of the flow: what the 402 tells it to call.
  if (req.url === '/v1/quote' && req.method === 'POST') {
    const q = JSON.parse(raw);
    const entry = state.resources.get(q.resource);
    const min = { standard: 40, complex: 10, premium: 4 }[q.tier];
    if (q.merchant_id !== MERCHANT || !entry || entry.tier !== q.tier || !(q.requests >= min) || !q.payer) return json(400, { error: 'AIFP-400', detail: 'bad quote' });
    return json(200, { quote_id: 'qt_' + randomBytes(8).toString('hex'), merchant_id: MERCHANT, resource: q.resource, scope: q.scope, tier: q.tier, requests: q.requests, payer: q.payer, accepted_chains: ['arc-testnet'], accepted_assets: ['USDC'] });
  }
  if (req.url === '/v1/pay' && req.method === 'POST') {
    const p = JSON.parse(raw);
    const w = { standard: 1, complex: 4, premium: 10 }[p.quote.tier];
    return json(200, { receipt: mint({ resource: p.quote.resource, scope: p.quote.scope, tier: p.quote.tier, payer: p.quote.payer, sub: p.quote.payer, unit_quota: p.quote.requests * w, quote_id: p.quote.quote_id }) });
  }
  json(404, { error: 'not_found' });
});
const outbound = () => state.calls.length;

// ── HTTP: raw paths, no redirects followed ─────────────────────────────────
const http = (method, path, headers = {}, body = null) => new Promise((resolve, reject) => {
  const req = httpRequest({ host: '127.0.0.1', port: PORT, method, path, headers: { 'User-Agent': CHROME, Accept: 'text/html,application/xhtml+xml,*/*;q=0.8', ...headers } }, (res) => {
    const chunks = [];
    res.on('data', (c) => chunks.push(c));
    res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, raw: res.rawHeaders, body: Buffer.concat(chunks).toString() }));
  });
  req.on('error', reject);
  if (body) req.write(body);
  req.end();
});
const get = (path, ua = CHROME, headers = {}) => http('GET', path, ua === null ? { 'User-Agent': '', ...headers } : { 'User-Agent': ua, ...headers });
const pay = (path, token, agent = AGENT) => http('GET', path, { 'User-Agent': 'aifinpay-agent-node/2.14', 'AIFP-Receipt': token, 'AIFP-Agent-Id': agent });
const CDN_HEADERS = ['cdn-cache-control', 'surrogate-control', 'cloudflare-cdn-cache-control', 'vercel-cdn-cache-control', 'netlify-cdn-cache-control', 'edge-control', 'akamai-cache-control'];
const noStore = (r) => assert.equal(r.headers['cache-control'], 'private, no-store, max-age=0', `cache-control: ${r.headers['cache-control']}`);
const uncacheable = (r) => { noStore(r); assert.equal(r.headers['cf-edge-cache'], 'no-cache'); assert.equal(r.headers['x-litespeed-cache-control'], 'no-cache'); for (const h of CDN_HEADERS) assert.equal(r.headers[h], 'no-store', h); assert.equal(r.headers['x-accel-expires'], '0'); };

// ── A real admin session ───────────────────────────────────────────────────
let cookies = '';
const remember = (res) => {
  const set = res.headers['set-cookie'] ?? [];
  const jar = new Map(cookies.split('; ').filter(Boolean).map((c) => c.split(/=(.*)/s).slice(0, 2)));
  for (const c of set) { const [k, v] = c.split(';')[0].split(/=(.*)/s); jar.set(k, v); }
  cookies = [...jar].map(([k, v]) => `${k}=${v}`).join('; ');
};
const login = async () => {
  cookies = 'wordpress_test_cookie=WP%20Cookie%20check';
  const form = new URLSearchParams({ log: 'admin', pwd: readFileSync(join(site, 'admin-pass'), 'utf8'), wp_submit: 'Log In', testcookie: '1' }).toString();
  const res = await http('POST', '/wp-login.php', { Cookie: cookies, 'Content-Type': 'application/x-www-form-urlencoded', 'Content-Length': Buffer.byteLength(form) }, form);
  remember(res);
  assert.equal(res.status, 302, 'login');
};
const adminPage = async () => {
  const res = await http('GET', '/wp-admin/options-general.php?page=aifinpay', { Cookie: cookies });
  assert.equal(res.status, 200, 'settings page');
  return res.body;
};
const statusRow = (html, label) => {
  const m = html.match(new RegExp(`<th scope="row">${label}</th><td>([^<]*)</td>`));
  return m ? m[1].replaceAll('&#039;', "'").replaceAll('&amp;', '&') : null;
};
const nonceIn = (html, action) => {
  const form = html.split('<form').find((f) => f.includes(`name="action" value="${action}"`));
  return form.match(/name="_wpnonce" value="([^"]+)"/)[1];
};
const defaults = { mode: 'shadow', tier: 'standard', protected_paths: '/*', excluded_paths: '', charge_alternates: '1', discovery_documents: '1', discovery_link: '1' };
let current = { ...defaults };
const submit = async (values = {}) => {
  const html = await adminPage();
  const next = { ...current, ...values };
  const form = new URLSearchParams({ action: 'aifinpay_save', _wpnonce: nonceIn(html, 'aifinpay_save'), _wp_http_referer: '/wp-admin/options-general.php?page=aifinpay', connect_code: '', ...next }).toString();
  const res = await http('POST', '/wp-admin/admin-post.php', { Cookie: cookies, 'Content-Type': 'application/x-www-form-urlencoded', 'Content-Length': Buffer.byteLength(form) }, form);
  assert.equal(res.status, 302, `save: ${res.status}`);
  const page = await adminPage();
  const notices = [...page.matchAll(/class="notice notice-(\w+)"><p>([^<]*)</g)].map((m) => [m[1], m[2]]);
  const stored = JSON.parse(await wp('option', 'get', 'aifinpay_settings', '--format=json'));
  if (notices.every(([t]) => t !== 'error')) current = next;
  current.mode = stored.mode;
  return { page, notices, stored };
};
const errors = (out) => out.notices.filter(([t]) => t === 'error').map(([, m]) => m).join(' | ');

// ── Harness ────────────────────────────────────────────────────────────────
const results = [];
const check = async (section, label, fn) => {
  if (only.length && !only.includes(section)) return;
  try { await fn(); results.push({ section, name: label, pass: true }); console.log(`PASS ${section}: ${label}`); }
  catch (e) { results.push({ section, name: label, pass: false, error: e.message }); console.error(`FAIL ${section}: ${label}\n     ${e.message}`); }
};
let server;
// php -S forks its workers; only the whole process group frees the port.
const stopServer = async () => { if (server) { const gone = new Promise((r) => server.once('exit', r)); try { process.kill(-server.pid, 'SIGKILL'); } catch {} await gone; server = null; } };
const startServer = async () => {
  await stopServer();
  server = spawn('php', ['-S', `127.0.0.1:${PORT}`, join(site, 'router.php')], { cwd: web, env: { ...process.env, PHP_CLI_SERVER_WORKERS: '6' }, stdio: 'ignore', detached: true });
  for (let i = 0; i < 100; i++) { try { await http('GET', '/wp-login.php'); return; } catch { await new Promise((r) => setTimeout(r, 100)); } }
  throw new Error('server did not start');
};
const flushRecord = () => wp('transient', 'delete', 'aifinpay_record');

// ── Run ────────────────────────────────────────────────────────────────────
const gatePkg = join(process.env.GATE_DIST ?? join(root, '.local/gate-0.10.1/node_modules/@aifinpay/gate/dist'), 'index.js');
assert.ok(existsSync(gatePkg), 'set GATE_DIST, or run scripts/check-gate-parity.mjs first');
const { createGate } = await import(pathToFileURL(gatePkg));


execFileSync(join(root, 'scripts/setup-wp.sh'), db === 'mysql' ? [name, String(PORT), 'mysql', DBHOST] : [name, String(PORT), 'sqlite'], { stdio: 'inherit' });
const zip = execFileSync(join(root, 'scripts/build-zip.sh'), { encoding: 'utf8' }).trim();
await wp('config', 'set', 'AIFINPAY_TEST_API_ORIGIN', apiBase, '--quiet');
await wp('config', 'set', 'DISABLE_WP_CRON', 'true', '--raw', '--quiet');
// wp-cli on a newer PHP can print deprecation lines before a --porcelain id.
const idOf = (out) => out.split('\n').pop().trim();
const pageId = idOf(await wp('post', 'create', '--post_type=page', '--post_title=Paid', '--post_name=paid', '--post_status=publish', `--post_content=${MARK}`, '--porcelain'));
const docs = idOf(await wp('post', 'create', '--post_type=page', '--post_title=Docs', '--post_name=docs', '--post_status=publish', `--post_content=${MARK} docs`, '--porcelain'));
await wp('post', 'create', '--post_type=page', '--post_title=A', '--post_name=a', `--post_parent=${docs}`, '--post_status=publish', `--post_content=${MARK} a`, '--porcelain');
await wp('post', 'create', '--post_type=page', '--post_title=Free', '--post_name=free', '--post_status=publish', '--post_content=FREE CONTENT', '--porcelain');
await wp('post', 'create', '--post_title=A post', '--post_name=a-post', '--post_status=publish', `--post_content=${MARK} post`, '--porcelain');
await new Promise((r) => api.listen(API, '127.0.0.1', r));
await startServer();

try {
  await check('install', 'installs from the zip on a fresh WordPress and activates', async () => {
    const out = await wp('plugin', 'install', zip, '--activate');
    assert.match(out, /Plugin installed successfully/);
    assert.equal(await wp('plugin', 'is-active', 'aifinpay').then(() => 'yes'), 'yes');
    assert.equal(existsSync(join(web, 'wp-content/plugins/aifinpay/tests')), false, 'no tests in the zip');
    assert.equal(existsSync(join(web, 'wp-content/plugins/aifinpay/vendor')), false, 'no dev dependencies in the zip');
  });
  await check('install', 'activation makes no external request', async () => assert.equal(outbound(), 0));
  await check('install', 'the secret and settings have their storage', async () => {
    const autoload = await wpEval(`global $wpdb; echo $wpdb->get_var("SELECT autoload FROM $wpdb->options WHERE option_name='aifinpay_settings'");`);
    assert.match(autoload, /^(yes|on|auto)/);
    assert.match(await wpEval('echo wp_next_scheduled("aifinpay_flush_events") ? "scheduled" : "no";'), /scheduled/);
  });

  // ── Shadow, the default ──────────────────────────────────────────────────
  await check('shadow', 'an AI crawler gets the page, marked what Enforce would have answered', async () => {
    const r = await get('/paid/', GPTBOT);
    assert.equal(r.status, 200);
    assert.match(r.body, new RegExp(MARK));
    assert.equal(r.headers['aifp-mode'], 'shadow');
    assert.equal(r.headers['aifp-shadow-status'], '402');
    uncacheable(r);
  });
  await check('shadow', 'a reader gets the page, cacheable as before, not counted', async () => {
    const r = await get('/paid/');
    assert.equal(r.status, 200);
    assert.match(r.body, new RegExp(MARK));
    assert.equal(r.headers['aifp-mode'], 'shadow');
    assert.equal(r.headers['aifp-shadow-status'], undefined);
    assert.notEqual(r.headers['cache-control'], 'private, no-store, max-age=0');
    assert.equal(r.headers['cf-edge-cache'], undefined);
  });
  await check('shadow', 'every HTML page points to the agent documents', async () => {
    const r = await get('/free/');
    assert.match(String(r.headers.link), /<\/llms\.txt>; rel="alternate"; type="text\/markdown"/);
    assert.doesNotMatch(String(r.headers.link), /x402/);
  });
  await check('shadow', '/llms.txt is served; x402.json waits for a merchant', async () => {
    const r = await get('/llms.txt', GPTBOT);
    assert.equal(r.status, 200);
    assert.match(r.headers['content-type'], /^text\/markdown/);
    assert.match(r.body, /Mode: shadow/);
    assert.equal((await get('/.well-known/x402.json', GPTBOT)).status, 404);
  });
  await check('shadow', 'a missing page, robots.txt and REST are not observed under the default', async () => {
    const before = await wpEval('echo wp_json_encode(AiFinPay\\WordPress\\TrafficLog::report());');
    assert.equal((await get('/nope/', GPTBOT)).status, 404);
    assert.equal((await get('/robots.txt', GPTBOT)).status, 200);
    assert.equal((await get('/wp-json/wp/v2/pages', GPTBOT)).status, 200);
    assert.equal(await wpEval('echo wp_json_encode(AiFinPay\\WordPress\\TrafficLog::report());'), before);
  });
  await check('shadow', 'nothing went to AiFinPay, nothing is queued', async () => {
    assert.equal(outbound(), 0);
    assert.equal(await wpEval('echo AiFinPay\\WordPress\\EventQueue::count();'), '0');
  });
  await check('shadow', 'the settings page says Shadow, not connected, and counts agent requests without an estimate', async () => {
    await login();
    await get('/paid/', CLAUDE);
    const html = await adminPage();
    assert.match(statusRow(html, 'Mode'), /^Shadow/);
    assert.equal(statusRow(html, 'Merchant'), 'Not connected');
    const row = html.match(/<td>openai<\/td>\s*<td>ai_crawler<\/td>\s*<td>(\d+)<\/td>\s*<td>(\d+)<\/td>/);
    assert.ok(row, 'traffic row');
    assert.equal(row[1], '1');
    assert.equal(row[2], '0', 'the next column is the 402 count, not a dollar estimate');
    assert.doesNotMatch(html, /Would have been charged/);
    assert.match(html, /<td>anthropic<\/td>/);
    assert.equal(outbound(), 0, 'opening the page sends nothing before a connection');
  });
  await check('shadow', 'a page may be asked for with any method without a refusal', async () => {
    assert.equal((await http('POST', '/paid/', { 'User-Agent': GPTBOT, 'Content-Length': 0 })).status, 200);
    assert.equal((await http('HEAD', '/paid/', { 'User-Agent': GPTBOT })).status, 200);
  });

  // ── Connect ──────────────────────────────────────────────────────────────
  await check('connect', 'Enforce is refused while not connected', async () => {
    const out = await submit({ mode: 'enforce', protected_paths: '/paid' });
    assert.match(errors(out), /not connected/);
    assert.equal(out.stored.mode, 'shadow');
  });
  await check('connect', 'a wrong code is refused and connects nothing', async () => {
    const out = await submit({ connect_code: 'AIFP-0000-0000-0000-0000' });
    assert.match(errors(out), /not valid/);
    assert.equal(statusRow(out.page, 'Merchant'), 'Not connected');
    current.connect_code = '';
    state.codeUsed = false;
  });
  await check('connect', 'the code alone connects: merchant, network, host and approval on the status table', async () => {
    const out = await submit({ connect_code: CODE, report_events: '1', protected_paths: '/paid\n/docs/*' });
    current.connect_code = '';
    assert.equal(errors(out), '');
    assert.equal(statusRow(out.page, 'Merchant'), `${MERCHANT} — E2E site`);
    assert.equal(statusRow(out.page, 'Network'), 'Test');
    assert.equal(statusRow(out.page, 'Charging host'), '127.0.0.1');
    assert.match(statusRow(out.page, 'Charging approved'), /^Yes: test site/);
  });
  await check('connect', 'the secret stays on the server: not in the page, not autoloaded, not in the log', async () => {
    const html = await adminPage();
    assert.equal(html.includes(SECRET), false);
    assert.equal((await get('/paid/', GPTBOT)).body.includes(SECRET), false);
    const autoload = await wpEval(`global $wpdb; echo $wpdb->get_var("SELECT autoload FROM $wpdb->options WHERE option_name='aifinpay_connection'");`);
    assert.match(autoload, /^(no|off)/);
    const log = join(web, 'wp-content/debug.log');
    assert.equal(existsSync(log) && readFileSync(log, 'utf8').includes(SECRET), false);
  });
  await check('connect', 'saving registers the patterns, because the ingest refuses any other', async () => {
    assert.deepEqual([...state.resources.keys()].sort(), ['/docs/*', '/paid']);
    assert.deepEqual(state.resources.get('/paid'), { route_pattern: '/paid', type: 'page', tier: 'standard', upsert: true });
  });
  await check('connect', 'x402.json is published for the merchant; the Link header names it', async () => {
    const r = await get('/.well-known/x402.json', GPTBOT);
    assert.equal(r.status, 200);
    const doc = JSON.parse(r.body);
    assert.equal(doc.merchant_id, MERCHANT);
    assert.equal(doc.quote_endpoint, `${apiBase}/v1/quote`);
    assert.deepEqual(doc.resources.map((x) => [x.resource, x.scope]), [['/paid', 'exact'], ['/docs/*', 'prefix']]);
    assert.match(String((await get('/free/')).headers.link), /x402\.json/);
  });
  await check('connect', 'a path the owner listed is uncacheable for readers too, and still serves them', async () => {
    const r = await get('/paid/');
    assert.equal(r.status, 200);
    assert.match(r.body, new RegExp(MARK));
    noStore(r);
    const free = await get('/free/');
    assert.notEqual(free.headers['cache-control'], 'private, no-store, max-age=0');
  });

  // ── Reporting ────────────────────────────────────────────────────────────
  await check('events', 'agent requests are queued, readers are not; WP-Cron delivers the ingest shape', async () => {
    await wpEval('AiFinPay\\WordPress\\EventQueue::clear();');
    await get('/paid/', GPTBOT);
    await get('/docs/a/', CLAUDE);
    await get('/paid/');
    await get('/free/', GPTBOT);
    assert.equal(await wpEval('echo AiFinPay\\WordPress\\EventQueue::count();'), '2');
    state.events.length = 0;
    await wp('cron', 'event', 'run', 'aifinpay_flush_events');
    assert.deepEqual(state.events.map((e) => [e.kind, e.resource, e.operator, e.ua_class, e.verified]).sort(), [['402', '/docs/*', 'anthropic', 'ai_crawler', false], ['402', '/paid', 'openai', 'ai_crawler', false]]);
    assert.equal(await wpEval('echo AiFinPay\\WordPress\\EventQueue::count();'), '0');
  });
  await check('events', 'no User-Agent, IP or raw path left the site', async () => {
    const posted = state.calls.filter((c) => c.url.endsWith('/gate-events')).map((c) => c.body).join();
    assert.ok(posted.length > 0);
    for (const leak of ['GPTBot', 'ClaudeBot', '127.0.0.1', '/docs/a']) assert.equal(posted.includes(leak), false, leak);
  });
  await check('events', 'a rejected credential keeps the events and the status page says so', async () => {
    state.ingest = 403;
    await get('/paid/', GPTBOT);
    await wp('cron', 'event', 'run', 'aifinpay_flush_events');
    assert.equal(await wpEval('echo AiFinPay\\WordPress\\EventQueue::count();'), '1');
    assert.match(statusRow(await adminPage(), 'Reporting'), /rejected the credential/);
    state.ingest = null;
    await wp('cron', 'event', 'run', 'aifinpay_flush_events');
    assert.equal(await wpEval('echo AiFinPay\\WordPress\\EventQueue::count();'), '0');
  });
  await check('events', 'with WP-Cron disabled and events overdue, an agent request wakes cron without waiting for it', async () => {
    state.events.length = 0;
    await wpEval('wp_clear_scheduled_hook("aifinpay_flush_events"); wp_schedule_event(time()-10,"aifinpay_five_minutes","aifinpay_flush_events"); delete_transient("doing_cron"); global $wpdb; $wpdb->insert(AiFinPay\\WordPress\\Schema::events(), ["id"=>$u=wp_generate_uuid4(),"payload"=>wp_json_encode(["id"=>$u,"kind"=>"402","resource"=>"/paid","at"=>gmdate("Y-m-d\\\\TH:i:s\\\\Z",time()-700),"operator"=>"openai","ua_class"=>"ai_crawler","verified"=>false],JSON_UNESCAPED_SLASHES),"created"=>time()-700]);');
    const r = await get('/paid/', GPTBOT);
    assert.equal(r.status, 200);
    for (let i = 0; i < 60 && state.events.length < 2; i++) await new Promise((res) => setTimeout(res, 100));
    assert.equal(state.events.length, 2, `delivered ${state.events.length}`);
  });
  await check('events', 'turning reporting off stops the sending and empties the queue', async () => {
    await get('/paid/', GPTBOT);
    const out = await submit({ report_events: '' });
    assert.equal(out.stored.report_events, false);
    assert.equal(await wpEval('echo AiFinPay\\WordPress\\EventQueue::count();'), '0');
    await get('/paid/', GPTBOT);
    assert.equal(await wpEval('echo AiFinPay\\WordPress\\EventQueue::count();'), '0');
    await submit({ report_events: '1' });
  });

  // ── Shadow with a receipt: checked, reported, nothing spent ──────────────
  await check('shadow-receipt', 'a good receipt is served, reported as verified, and spends nothing', async () => {
    await wpEval('AiFinPay\\WordPress\\EventQueue::clear();');
    state.events.length = 0;
    const token = mint({ unit_quota: 1, nonce: 'n-2' });
    for (let i = 0; i < 3; i++) {
      const r = await pay('/paid/', token);
      assert.equal(r.status, 200);
      assert.match(r.body, new RegExp(MARK));
      assert.equal(r.headers['aifp-mode'], 'shadow');
      assert.equal(r.headers['aifp-shadow-status'], undefined);
      assert.equal(r.headers['aifp-quota-remaining'], undefined);
      uncacheable(r);
    }
    assert.equal(await wpEval('global $wpdb; echo $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i", AiFinPay\\WordPress\\Schema::units()));'), '0');
    await wp('cron', 'event', 'run', 'aifinpay_flush_events');
    assert.deepEqual(state.events.map((e) => [e.kind, e.operator, e.ua_class, e.verified]), Array(3).fill(['serve', 'aifinpay', 'aifp_agent', true]));
  });
  await check('shadow-receipt', 'a forged receipt reads as a would-be 403 and still gets the page', async () => {
    const r = await pay('/paid/', mint({}, generateKeyPairSync('ed25519').privateKey));
    assert.equal(r.status, 200);
    assert.match(r.body, new RegExp(MARK));
    assert.equal(r.headers['aifp-shadow-status'], '403');
  });
  await check('shadow-receipt', 'an expired receipt reads as a would-be 402; no AIFP-Agent-Id as a would-be 403', async () => {
    assert.equal((await pay('/paid/', mint({ exp: Math.floor(Date.now() / 1000) - 60 }))).headers['aifp-shadow-status'], '402');
    const r = await http('GET', '/paid/', { 'User-Agent': 'aifinpay-agent-node/2.14', 'AIFP-Receipt': mint() });
    assert.equal(r.status, 200);
    assert.equal(r.headers['aifp-shadow-status'], '403');
  });
  await check('shadow-receipt', 'keys or the meter failing never reach the page', async () => {
    state.jwksDown = true;
    await wp('transient', 'delete', 'aifinpay_jwks');
    const r = await pay('/paid/', mint());
    assert.equal(r.status, 200);
    assert.match(r.body, new RegExp(MARK));
    assert.equal(r.headers['aifp-shadow-status'], '503');
    state.jwksDown = false;
    await wp('transient', 'delete', 'aifinpay_jwks_hold').catch(() => {});
    await wpEval('global $wpdb; $wpdb->suppress_errors(true); $wpdb->query("DROP TABLE " . AiFinPay\\WordPress\\Schema::units());');
    const s = await pay('/paid/', mint());
    assert.equal(s.status, 200);
    assert.equal(s.headers['aifp-shadow-status'], undefined, 'shadow never touches the meter');
    await wpEval('AiFinPay\\WordPress\\Schema::install();');
    await wpEval('AiFinPay\\WordPress\\EventQueue::clear();');
  });

  // ── Enforce: who may switch ──────────────────────────────────────────────
  await check('approval', '/* is refused in Enforce, with the reason', async () => {
    const out = await submit({ mode: 'enforce', protected_paths: '/*' });
    assert.match(errors(out), /replace \/\*/);
    assert.equal(out.stored.mode, 'shadow');
    await submit({ protected_paths: '/paid\n/docs/*', mode: 'shadow' });
  });
  await check('approval', 'a live site without a verified domain is refused on the form', async () => {
    state.record = { ...state.record, network_mode: 'live' };
    await flushRecord();
    const out = await submit({ mode: 'enforce' });
    assert.match(errors(out), /verify the domain/);
    assert.equal(out.stored.mode, 'shadow');
    assert.match(statusRow(out.page, 'Charging approved'), /^No: verify the domain/);
    state.record = { ...state.record, network_mode: 'test' };
    await flushRecord();
  });
  await check('approval', 'a site URL on another host is refused on the form', async () => {
    state.record = { ...state.record, site_url: 'https://elsewhere.test/' };
    await flushRecord();
    const out = await submit({ mode: 'enforce' });
    assert.match(errors(out), /differs from the site URL/);
    state.record = { ...state.record, site_url: `${base}/` };
    await flushRecord();
  });
  await check('approval', 'plain permalinks are refused on the form', async () => {
    await wp('rewrite', 'structure', '');
    const out = await submit({ mode: 'enforce' });
    assert.match(errors(out), /permalink/);
    await wp('rewrite', 'structure', '/%postname%/');
    await wp('rewrite', 'flush');
  });
  await check('approval', 'a test site switches to Enforce from the form', async () => {
    const out = await submit({ mode: 'enforce' });
    assert.equal(errors(out), '');
    assert.equal(out.stored.mode, 'enforce');
    assert.match(statusRow(out.page, 'Mode'), /^Enforce/);
    for (const p of ['/docs/*', '/feed/*', '/paid', '/wp-json/wp/v2/*']) assert.ok(state.resources.has(p), p);
  });

  // ── Enforce: the gate ────────────────────────────────────────────────────
  await check('enforce', 'an AI crawler gets the 402, byte for byte what @aifinpay/gate sends', async () => {
    const r = await get('/paid/', GPTBOT);
    assert.equal(r.status, 402);
    assert.equal(r.body.includes(MARK), false);
    assert.match(r.headers['content-type'], /^application\/json/);
    const gate = createGate({ merchantId: MERCHANT, resource: '/paid', tier: 'standard', apiBase, jwks: { keys: [] }, store: { incrBy: async () => 1 } });
    const theirs = await gate({ path: '/paid', header: (n) => (n.toLowerCase() === 'user-agent' ? GPTBOT : undefined) });
    assert.equal(theirs.status, 402);
    assert.equal(r.body, JSON.stringify(theirs.body));
    uncacheable(r);
    assert.equal(r.headers.link, undefined, 'a JSON answer carries no discovery Link');
  });
  await check('enforce', 'a prefix pattern and another tier give the matching 402', async () => {
    await submit({ tier: 'premium' });
    const r = await get('/docs/a/', GPTBOT);
    assert.equal(r.status, 402);
    const gate = createGate({ merchantId: MERCHANT, resource: '/docs/*', tier: 'premium', apiBase, jwks: { keys: [] }, store: { incrBy: async () => 1 } });
    const theirs = await gate({ path: '/docs/a', header: () => undefined });
    assert.equal(r.body, JSON.stringify(theirs.body));
    assert.equal(JSON.parse(r.body).min_requests, 4);
    await submit({ tier: 'standard' });
  });
  await check('enforce', 'browsers are never charged: served, exempt, uncacheable, not reported', async () => {
    await wpEval('AiFinPay\\WordPress\\EventQueue::clear();');
    for (const ua of [CHROME, 'Mozilla/5.0 (X11; Linux x86_64; rv:133.0) Gecko/20100101 Firefox/133.0', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)']) {
      const r = await get('/paid/', ua);
      assert.equal(r.status, 200, ua);
      assert.match(r.body, new RegExp(MARK));
      assert.equal(r.headers['aifp-paywall'], 'exempt');
      noStore(r);
    }
    assert.equal(await wpEval('echo AiFinPay\\WordPress\\EventQueue::count();'), '0');
  });
  await check('enforce', 'a logged-in user is never charged, whatever the User-Agent', async () => {
    const r = await http('GET', '/paid/', { 'User-Agent': GPTBOT, Cookie: cookies });
    assert.equal(r.status, 200);
    assert.match(r.body, new RegExp(MARK));
    noStore(r);
  });
  await check('enforce', 'a browser User-Agent does not exempt a paying agent', async () => {
    assert.equal((await http('GET', '/paid/', { 'User-Agent': CHROME, 'AIFP-Agent-Id': AGENT })).status, 402);
    assert.equal((await http('GET', '/paid/', { 'User-Agent': CHROME, Cookie: cookies, 'AIFP-Agent-Id': AGENT })).status, 402);
  });
  await check('enforce', 'no spelling of a paid URL gives an agent the page', async () => {
    const post = await wpEval('echo get_page_by_path("paid")->ID;');
    for (const path of ['/paid', '/paid/', '/PAID/', '/Paid', '/%70aid/', '/%2570aid/', '/paid//', '//paid/', '/./paid/', '/x/../paid/', '/paid/./', '/paid;x=1/', '/;x/paid/', '/paid%3F', '/paid%23', '/paid;a%3Fb/c', '/paid%3Ba%3Fb/c', '/x%3F/../paid/', '/paid/%2525252e%2525252e/x', '/paid/%252525252e%252525252e/x', '/paid/..;/x', '/paid\\', '/paid#/..', '/index.php/paid/', `/?page_id=${post}`, `/?p=${post}`, '/?pagename=paid', '/paid/?x=1', '/paid/embed/', '/paid/feed/', '/paid/2/']) {
      const r = await get(path, GPTBOT);
      assert.equal(r.body.includes(MARK), false, `${path} answered ${r.status} with the content`);
      assert.ok([301, 302, 400, 402, 404].includes(r.status), `${path} answered ${r.status}`);
    }
  });
  await check('enforce', 'an escaped mark is priced as the page before it, and a batch for that page opens it', async () => {
    for (const path of ['/paid%3Fx', '/paid%23x', '/paid%253Fx', '/wp-json/wp/v2/pages%3Fx', '/wp-json/wp/v2/pages%23x']) {
      const bot = await get(path, GPTBOT);
      assert.notEqual(bot.status, 400, path);
      assert.equal(bot.body.includes(MARK), false, `${path} answered ${bot.status} with the content`);
      assert.notEqual((await get(path)).status, 400, `reader on ${path}`);
    }
    const token = mint();
    for (const path of ['/paid%3Fx', '/paid%23x']) {
      const r = await pay(path, token);
      assert.ok([200, 301, 302, 404].includes(r.status), `${path} answered ${r.status}: ${r.body.slice(0, 200)}`);
    }
  });
  await check('enforce', 'a spelling no price is safe for: an agent gets a 400, uncacheable, with no Link, and a reader is not refused', async () => {
    // WordPress redirects or 404s most of these before the gate runs; what reaches it is refused.
    for (const path of ['/free/#x', '/paid/#x', '/wp-json/wp/v2/../v2/pages']) {
      const bot = await get(path, GPTBOT);
      assert.equal(bot.status, 400, `${path} answered ${bot.status}`);
      assert.deepEqual(JSON.parse(bot.body), { error: 'noncanonical_path', detail: 'this path means different things to different servers' });
      uncacheable(bot);
      assert.equal(bot.headers.link, undefined);
    }
    for (const path of ['/free/#x', '/paid/#x']) assert.equal((await get(path)).status, 200, path);
  });
  await check('enforce', 'no method gets a paid page for free', async () => {
    for (const method of ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'PROPFIND']) {
      const r = await http(method, '/paid/', { 'User-Agent': GPTBOT, 'Content-Length': 0 });
      assert.equal(r.status, 402, `${method} answered ${r.status}`);
      assert.equal(r.body.includes(MARK), false, method);
    }
    const preflight = await http('OPTIONS', '/wp-json/wp/v2/pages', { 'User-Agent': GPTBOT });
    assert.equal(preflight.body.includes(MARK), false, 'a REST preflight has no content');
  });
  await check('enforce', 'the REST API and the feed do not give the content away', async () => {
    for (const path of ['/wp-json/wp/v2/pages', '/wp-json/wp/v2/posts', `/wp-json/wp/v2/pages/${pageId}`, '/wp-json/wp/v2/pages/', '/wp-json//wp/v2/pages', '/wp-json/WP/V2/pages', '/?rest_route=/wp/v2/pages', '/index.php?rest_route=/wp/v2/search', '/feed/', '/?feed=rss2', '/?feed=atom', '/category/uncategorized/feed/', '/?s=post&feed=rss2', '/a-post/feed/']) {
      const r = await get(path, GPTBOT);
      assert.equal(r.status, 402, `${path} answered ${r.status}`);
      assert.equal(r.body.includes(MARK), false, path);
      uncacheable(r);
    }
    assert.equal(JSON.parse((await get('/wp-json/wp/v2/pages', GPTBOT)).body).resource, '/wp-json/wp/v2/*');
  });
  await check('enforce', 'readers and logged-in tools use REST as before; other routes and CORS preflight are not gated', async () => {
    const reader = await get('/wp-json/wp/v2/pages');
    assert.equal(reader.status, 200);
    assert.match(reader.body, new RegExp(MARK));
    assert.equal((await get('/wp-json/', GPTBOT)).status, 200);
    assert.equal((await get('/wp-json/oembed/1.0/embed?url=x', GPTBOT)).status !== 402, true);
    assert.equal((await http('OPTIONS', '/wp-json/wp/v2/pages', { 'User-Agent': GPTBOT })).status !== 402, true);
    assert.equal((await http('POST', '/wp-json/contact/v1/form', { 'User-Agent': '', 'Content-Length': 0 })).status !== 402, true);
  });
  await check('enforce', 'a CDN header another plugin sends cannot make a protected response cacheable', async () => {
    const mu = join(web, 'wp-content/mu-plugins');
    mkdirSync(mu, { recursive: true });
    const file = join(mu, 'aifp-e2e-cdn.php');
    const send = "foreach (['Surrogate-Control','CDN-Cache-Control','Cloudflare-CDN-Cache-Control','Vercel-CDN-Cache-Control','Netlify-CDN-Cache-Control','Edge-Control','Akamai-Cache-Control','X-Accel-Expires'] as $n) { header(\"$n: max-age=600\"); }";
    writeFileSync(file, `<?php\nadd_action('wp_headers', function ($h) { ${send} return $h; });\nadd_action('template_redirect', function () { ${send} }, 30);\n`);
    try {
      const paid = await pay('/paid/', mint());
      assert.equal(paid.status, 200);
      uncacheable(paid);
      uncacheable(await get('/paid/'));
      uncacheable(await get('/paid/', GPTBOT));
      assert.equal((await get('/free/')).headers['surrogate-control'], 'max-age=600', 'an unprotected page keeps what the site sends');
    } finally {
      rmSync(file, { force: true });
    }
  });
  await check('enforce', 'an agent on an unprotected page is untouched and cacheable', async () => {
    const r = await get('/free/', GPTBOT);
    assert.equal(r.status, 200);
    assert.notEqual(r.headers['cache-control'], 'private, no-store, max-age=0');
  });
  await check('enforce', 'the agent flow: 402 → quote → pay → retry with the receipt → 200, metered', async () => {
    const challenge = JSON.parse((await get('/docs/a/', GPTBOT)).body);
    assert.equal(challenge.error, 'AIFP-402');
    const quoteUrl = challenge.settlement_terms_from.match(/POST (\S+)/)[1];
    const call = (url, body) => fetch(url, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify(body) }).then(async (r) => { assert.equal(r.status, 200, await r.clone().text()); return r.json(); });
    const quote = await call(quoteUrl, { merchant_id: challenge.merchant_id, resource: challenge.resource, tier: challenge.tier, scope: challenge.scope, requests: challenge.min_requests, payer: AGENT });
    const { receipt } = await call(quoteUrl.replace('/v1/quote', '/v1/pay'), { quote });
    const first = await pay('/docs/a/', receipt);
    assert.equal(first.status, 200);
    assert.match(first.body, new RegExp(MARK));
    assert.equal(first.headers['aifp-quota-remaining'], String(challenge.min_requests - 1));
    assert.match(first.headers['aifp-receipt-id'], /^rcpt_/);
    uncacheable(first);
    const second = await pay('/docs/', receipt);
    assert.equal(second.status, 200, 'the section itself is covered by /docs/*');
    assert.equal(second.headers['aifp-quota-remaining'], String(challenge.min_requests - 2));
  });
  await check('enforce', 'a receipt with no AIFP-Agent-Id is refused, says what to send, and spends nothing', async () => {
    const token = mint({ unit_quota: 1, nonce: 'n-1' });
    const r = await http('GET', '/paid/', { 'User-Agent': 'aifinpay-agent-node/2.14', 'AIFP-Receipt': token });
    assert.equal(r.status, 403);
    assert.match(JSON.parse(r.body).detail, /retry with AIFP-Agent-Id .*Nothing was spent\./);
    assert.equal(r.body.includes(MARK), false);
    assert.equal((await pay('/paid/', token)).status, 200, 'the one unit is still there');
  });
  await check('enforce', 'the batch an SDK buys for a section opens the section page', async () => {
    const token = mint({ resource: '/docs/', scope: 'prefix' });
    for (const path of ['/docs/', '/docs/a/']) assert.equal((await pay(path, token)).status, 200, path);
  });
  await check('enforce', 'a batch runs out exactly, then the 402 asks for the next one', async () => {
    const token = mint({ unit_quota: 2 });
    assert.equal((await pay('/paid/', token)).headers['aifp-quota-remaining'], '1');
    assert.equal((await pay('/paid/', token)).headers['aifp-quota-remaining'], '0');
    const r = await pay('/paid/', token);
    assert.equal(r.status, 402);
    assert.equal(r.body.includes(MARK), false);
    assert.equal(JSON.parse(r.body).detail, 'quota exhausted — prepay the next batch');
  });
  await check('enforce', 'a request that ends in a redirect spends nothing', async () => {
    const token = mint({ unit_quota: 3 });
    const r = await pay('/paid', token);
    assert.equal(r.status, 301);
    assert.equal((await pay('/paid/', token)).headers['aifp-quota-remaining'], '2');
  });
  await check('enforce', 'concurrent spending never overspends a batch', async () => {
    const token = mint({ unit_quota: 5 });
    const all = await Promise.all(Array.from({ length: 24 }, () => pay('/paid/', token)));
    const served = all.filter((r) => r.status === 200);
    assert.equal(served.length, 5, `served ${served.length}: ${all.map((r) => r.status).join()}`);
    assert.ok(all.every((r) => r.status === 200 || r.status === 402), all.map((r) => r.status).join());
    assert.equal(await wpEval(`global $wpdb; echo $wpdb->get_var($wpdb->prepare("SELECT used FROM %i WHERE receipt_hash = %s", AiFinPay\\WordPress\\Schema::units(), hash("sha256", "${MERCHANT}\\0" . json_decode(base64_decode(strtr(explode(".", "${token}")[1], "-_", "+/")))->receipt_id)));`), '5');
  });
  const refused = [
    ['a forged signature', () => mint({}, generateKeyPairSync('ed25519').privateKey), 403, /verification failed/],
    ['another merchant', () => mint({ aud: 'mrch_someone_else' }), 403, /verification failed/],
    ['another issuer', () => mint({ iss: 'https://evil.test' }), 403, /verification failed/],
    ['another path', () => mint({ resource: '/docs/*' }), 403, /scoped to/],
    ['another payer', () => mint({ payer: '0x0000000000000000000000000000000000000001' }), 403, /does not match/],
    ['an action receipt', () => mint({ typ_aifp: 'action' }), 403, /not spendable/],
    ['no receipt id', () => mint({ receipt_id: undefined }), 403, /no receipt_id/],
    ['a single-use receipt with no nonce', () => mint({ unit_quota: 1 }), 403, /no nonce/],
    ['a unit_quota that is not a number', () => mint({ unit_quota: 'abc' }), 403, /no usable unit_quota/],
    ['an expired receipt', () => mint({ exp: Math.floor(Date.now() / 1000) - 60 }), 402, /expired/],
    ['a live receipt on a test site', () => mint({ network_mode: 'live' }), 403, /another network/],
    ['garbage', () => 'not.a.jwt', 403, /verification failed/],
  ];
  for (const [label, token, status, detail] of refused) {
    await check('enforce', `${label} does not open the page`, async () => {
      const r = await pay('/paid/', token());
      assert.equal(r.status, status);
      assert.match(JSON.parse(r.body).detail, detail);
      assert.equal(r.body.includes(MARK), false);
      uncacheable(r);
    });
  }
  await check('enforce', 'paid and refused requests reach the dashboard with the right identity', async () => {
    await wpEval('AiFinPay\\WordPress\\EventQueue::clear();');
    state.events.length = 0;
    await get('/paid/', GPTBOT);
    await pay('/paid/', mint({ unit_quota: 1, nonce: 'n-3' }));
    await wp('cron', 'event', 'run', 'aifinpay_flush_events');
    const byKind = Object.fromEntries(state.events.map((e) => [e.kind, e]));
    assert.deepEqual([byKind['402'].operator, byKind['402'].verified], ['openai', false]);
    assert.deepEqual([byKind.serve.operator, byKind.serve.ua_class, byKind.serve.verified], ['aifinpay', 'aifp_agent', true]);
  });

  // ── Enforce: approval and host, live from the record ─────────────────────
  await check('live', 'approval follows the record: a live unverified site refuses agents, serves readers', async () => {
    state.record = { ...state.record, network_mode: 'live' };
    await flushRecord();
    for (const r of [await get('/paid/', GPTBOT), await pay('/paid/', mint({ network_mode: 'live' }))]) {
      assert.equal(r.status, 503);
      assert.equal(JSON.parse(r.body).error, 'enforcement_not_approved');
      assert.equal(r.body.includes(MARK), false);
      uncacheable(r);
    }
    const reader = await get('/paid/');
    assert.equal(reader.status, 200);
    assert.match(reader.body, new RegExp(MARK));
  });
  await check('live', 'a settings or database edit cannot approve charging', async () => {
    await wpEval(`$s=get_option("aifinpay_settings"); $s["approved"]=true; $s["receipt_gate_approved"]=true; $s["site_host"]="127.0.0.1"; $s["site_verified"]=true; update_option("aifinpay_settings",$s);`);
    await wpEval(`$c=get_option("aifinpay_connection"); $c["site_verified"]=true; $c["approved"]=true; update_option("aifinpay_connection",$c,false);`);
    assert.equal((await pay('/paid/', mint({ network_mode: 'live' }))).status, 503);
  });
  await check('live', 'a verified live site charges; a testnet receipt does not open it', async () => {
    state.record = { ...state.record, site_verified: true };
    await flushRecord();
    assert.equal((await pay('/paid/', mint({ network_mode: 'live' }))).status, 200);
    assert.equal((await pay('/paid/', mint({ network_mode: 'test' }))).status, 403);
    assert.match(statusRow(await adminPage(), 'Charging approved'), /^Yes: live site/);
    state.record = { ...state.record, network_mode: 'test', site_verified: false };
    await flushRecord();
  });
  await check('live', 'the charging host follows the site URL: on another host agents are refused', async () => {
    state.record = { ...state.record, site_url: 'https://www.elsewhere.test/' };
    await flushRecord();
    const r = await pay('/paid/', mint());
    assert.equal(r.status, 503);
    assert.equal(r.body.includes(MARK), false);
    state.record = { ...state.record, site_url: `${base}/` };
    await flushRecord();
    assert.equal((await pay('/paid/', mint())).status, 200);
  });
  await check('live', 'an API outage: the last record serves; without one, agents get 503 and readers the page', async () => {
    state.recordDown = true;
    await flushRecord();
    assert.equal((await pay('/paid/', mint())).status, 200);
    await wp('option', 'delete', 'aifinpay_merchant_record');
    await flushRecord();
    const r = await pay('/paid/', mint());
    assert.equal(r.status, 503);
    assert.equal(r.body.includes(MARK), false);
    assert.equal((await get('/paid/')).status, 200);
    state.recordDown = false;
    await flushRecord();
  });
  await check('live', 'keys unreachable: receipts fail closed, the free path still answers', async () => {
    state.jwksDown = true;
    await wp('transient', 'delete', 'aifinpay_jwks');
    const r = await pay('/paid/', mint());
    assert.equal(r.status, 503);
    assert.equal(JSON.parse(r.body).error, 'AIFP-503-METER');
    assert.equal(r.headers['retry-after'], '30');
    assert.equal(r.body.includes(MARK), false);
    state.jwksDown = false;
  });
  await check('live', 'a broken meter fails closed: no page without counting, and readers are fine', async () => {
    await wpEval(`global $wpdb; $wpdb->query("DROP TABLE " . AiFinPay\\WordPress\\Schema::units());`);
    const r = await pay('/paid/', mint());
    assert.equal(r.status, 503);
    assert.equal(r.body.includes(MARK), false);
    assert.equal((await get('/paid/')).status, 200);
    await wpEval('AiFinPay\\WordPress\\Schema::install();');
    assert.equal((await pay('/paid/', mint())).status, 200);
    rmSync(join(web, 'wp-content/debug.log'), { force: true });
  });
  await check('live', 'excluded paths and Off', async () => {
    await submit({ excluded_paths: '/docs/a' });
    assert.equal((await get('/docs/a/', GPTBOT)).status, 200);
    assert.equal((await get('/docs/', GPTBOT)).status, 402);
    await submit({ excluded_paths: '' });
    const out = await submit({ mode: 'off' });
    assert.equal(out.stored.mode, 'off');
    const r = await get('/paid/', GPTBOT);
    assert.equal(r.status, 200);
    assert.equal(r.headers['aifp-mode'], undefined);
    assert.equal(r.headers.link?.includes('llms.txt') ?? false, false);
    assert.equal((await get('/llms.txt')).status, 404);
    await submit({ mode: 'enforce' });
  });
  await check('live', 'an admin form post without a nonce, or by a non-admin, changes nothing', async () => {
    const form = new URLSearchParams({ action: 'aifinpay_save', mode: 'off', protected_paths: '/x' }).toString();
    const noNonce = await http('POST', '/wp-admin/admin-post.php', { Cookie: cookies, 'Content-Type': 'application/x-www-form-urlencoded', 'Content-Length': Buffer.byteLength(form) }, form);
    assert.ok([403, 200].includes(noNonce.status) && /link you followed has expired|not allowed/i.test(noNonce.body), `status ${noNonce.status}`);
    await http('POST', '/wp-admin/admin-post.php', { 'Content-Type': 'application/x-www-form-urlencoded', 'Content-Length': Buffer.byteLength(form) }, form);
    assert.equal(JSON.parse(await wp('option', 'get', 'aifinpay_settings', '--format=json')).mode, 'enforce');
  });
  await check('live', 'a logged-in subscriber cannot save, disconnect or open the settings page', async () => {
    const pass = randomBytes(16).toString('hex');
    await wp('user', 'create', 'reader', 'reader@example.test', '--role=subscriber', `--user_pass=${pass}`, '--quiet');
    const login2 = new URLSearchParams({ log: 'reader', pwd: pass, wp_submit: 'Log In', testcookie: '1' }).toString();
    const res = await http('POST', '/wp-login.php', { Cookie: 'wordpress_test_cookie=WP%20Cookie%20check', 'Content-Type': 'application/x-www-form-urlencoded', 'Content-Length': Buffer.byteLength(login2) }, login2);
    assert.equal(res.status, 302);
    const jar = (res.headers['set-cookie'] ?? []).map((c) => c.split(';')[0]).join('; ');
    assert.equal((await http('GET', '/wp-admin/options-general.php?page=aifinpay', { Cookie: jar })).status, 403);
    for (const action of ['aifinpay_save', 'aifinpay_disconnect']) {
      const form = new URLSearchParams({ action, mode: 'off' }).toString();
      const r = await http('POST', '/wp-admin/admin-post.php', { Cookie: jar, 'Content-Type': 'application/x-www-form-urlencoded', 'Content-Length': Buffer.byteLength(form) }, form);
      assert.equal(r.status, 403, action);
    }
    assert.equal(JSON.parse(await wp('option', 'get', 'aifinpay_settings', '--format=json')).mode, 'enforce');
    assert.notEqual(await wpEval('echo wp_json_encode(get_option("aifinpay_connection"));'), 'false');
  });
  await check('live', 'disconnect deletes the secret and returns to Shadow', async () => {
    const html = await adminPage();
    const form = new URLSearchParams({ action: 'aifinpay_disconnect', _wpnonce: nonceIn(html, 'aifinpay_disconnect'), _wp_http_referer: '/wp-admin/options-general.php?page=aifinpay' }).toString();
    assert.equal((await http('POST', '/wp-admin/admin-post.php', { Cookie: cookies, 'Content-Type': 'application/x-www-form-urlencoded', 'Content-Length': Buffer.byteLength(form) }, form)).status, 302);
    assert.equal(await wpEval('echo wp_json_encode(get_option("aifinpay_connection"));'), 'false');
    assert.equal(JSON.parse(await wp('option', 'get', 'aifinpay_settings', '--format=json')).mode, 'shadow');
    assert.equal((await get('/paid/', GPTBOT)).status, 200);
  });
  // ── A real page cache: WP Super Cache, PHP mode, no User-Agent exclusions ──
  // The cache stamps each page when it stores it, so one stamp seen twice is a copy served from disk.
  const stamp = (r) => r.body.match(/Cached page generated by WP-Super-Cache on ([^>]+?) -->/)?.[1] ?? null;
  const second = () => new Promise((r) => setTimeout(r, 1100));
  const storedCopy = async (path) => {
    const first = stamp(await get(path));
    await second();
    const again = stamp(await get(path));
    assert.ok(first, `${path} was not stored`);
    assert.equal(again, first, `${path} was generated again, not served from the cache`);
    return first;
  };
  await check('cache', 'with a live page cache a reader on /free/ is answered from a stored copy', async () => {
    const zipPath = join(root, '.local/cache/wp-super-cache.zip');
    if (!existsSync(zipPath)) execFileSync('curl', ['-sfL', 'https://downloads.wordpress.org/plugin/wp-super-cache.latest-stable.zip', '-o', zipPath]);
    await wp('plugin', 'install', zipPath, '--activate');
    const home = join(web, 'wp-content/plugins/wp-super-cache/');
    await wp('config', 'set', 'WP_CACHE', 'true', '--raw', '--quiet');
    await wp('config', 'set', 'WPCACHEHOME', home, '--quiet');
    writeFileSync(join(web, 'wp-content/advanced-cache.php'), readFileSync(join(home, 'advanced-cache.php')));
    writeFileSync(join(web, 'wp-content/wp-cache-config.php'), readFileSync(join(home, 'wp-cache-config-sample.php'), 'utf8').replace('$cache_enabled = false;', '$cache_enabled = true;\n$wp_cache_slash_check = 1;'));
    await startServer();
    await storedCopy('/free/');
    current = { ...defaults };
    await login();
  });
  await check('cache', 'Shadow on /* leaves it alone: readers fill the cache, an agent answered from it is not counted', async () => {
    await submit({ mode: 'shadow', protected_paths: '/*', report_events: '' });
    const copy = await storedCopy('/paid/');
    const before = await wpEval('echo wp_json_encode(AiFinPay\\WordPress\\TrafficLog::report());');
    const bot = await get('/paid/', GPTBOT);
    assert.equal(bot.status, 200);
    assert.equal(stamp(bot), copy);
    assert.equal(bot.headers['aifp-shadow-status'], undefined);
    assert.equal(await wpEval('echo wp_json_encode(AiFinPay\\WordPress\\TrafficLog::report());'), before);
    assert.match(statusRow(await adminPage(), 'Page cache'), /WP Super Cache.*not counted/);
  });
  await check('cache', 'an agent response is not stored for the next reader', async () => {
    await wp('eval', 'wp_cache_clear_cache();');
    const bot = await get('/paid/', GPTBOT);
    assert.equal(bot.headers['aifp-shadow-status'], '402');
    assert.equal(stamp(bot), null);
    assert.equal(stamp(await get('/paid/', CLAUDE)), null, 'the next agent still reaches the plugin');
  });
  await check('cache', 'switching to Enforce clears the copy stored before: the agent meets the gate, not the cache', async () => {
    await storedCopy('/paid/');
    state.codeUsed = false;
    const out = await submit({ connect_code: CODE, mode: 'enforce', protected_paths: '/paid\n/docs/*' });
    current.connect_code = '';
    assert.equal(errors(out), '');
    const r = await get('/paid/', GPTBOT);
    assert.equal(r.status, 402);
    assert.equal(r.body.includes(MARK), false);
  });
  await check('cache', 'a 402 is never stored', async () => {
    for (let i = 0; i < 3; i++) {
      const r = await get('/paid/', GPTBOT);
      assert.equal(r.status, 402);
      assert.equal(stamp(r), null);
    }
    const reader = await get('/paid/');
    assert.equal(reader.status, 200, 'and no reader gets it');
    assert.match(reader.body, new RegExp(MARK));
  });
  await check('cache', 'paid content is never stored: no copy for the next agent, every read is metered', async () => {
    const token = mint({ unit_quota: 3 });
    const remaining = [];
    for (let i = 0; i < 3; i++) {
      const r = await pay('/paid/', token);
      assert.equal(r.status, 200);
      assert.equal(stamp(r), null);
      uncacheable(r);
      remaining.push(r.headers['aifp-quota-remaining']);
    }
    assert.deepEqual(remaining, ['2', '1', '0']);
    const bot = await get('/paid/', GPTBOT);
    assert.equal(bot.status, 402, 'no copy left by the paid reads');
  });
  await check('cache', 'a reader on a protected path gets a fresh page, leaving no copy for an agent', async () => {
    for (let i = 0; i < 2; i++) {
      const r = await get('/paid/');
      assert.equal(r.status, 200);
      assert.equal(stamp(r), null);
    }
    assert.equal((await get('/paid/', GPTBOT)).status, 402);
  });
  await check('cache', 'an unprotected page keeps its cache in Enforce', async () => storedCopy('/free/'));
  await check('cache', 'clean up the cache plugin', async () => {
    await wp('plugin', 'deactivate', 'wp-super-cache');
    await wp('plugin', 'delete', 'wp-super-cache');
    await wp('config', 'set', 'WP_CACHE', 'false', '--raw', '--quiet');
    await wp('config', 'delete', 'WPCACHEHOME', '--quiet').catch(() => {});
    rmSync(join(web, 'wp-content/advanced-cache.php'), { force: true });
    rmSync(join(web, 'wp-content/wp-cache-config.php'), { force: true });
    rmSync(join(web, 'wp-content/cache'), { recursive: true, force: true });
    await startServer();
  });
  await check('live', 'no PHP warnings or errors from the plugin in the log', async () => {
    const log = join(web, 'wp-content/debug.log');
    const lines = existsSync(log) ? readFileSync(log, 'utf8').split('\n').filter((l) => /plugins\/aifinpay|AiFinPay\\/.test(l)) : [];
    assert.deepEqual(lines, []);
  });
  await check('uninstall', 'uninstalling leaves no table, option, transient or scheduled event', async () => {
    await wp('plugin', 'deactivate', 'aifinpay');
    assert.match(await wpEval('echo wp_next_scheduled("aifinpay_flush_events") ? "scheduled" : "none";'), /none/);
    await wp('plugin', 'uninstall', 'aifinpay');
    const left = await wpEval(`global $wpdb; echo wp_json_encode([$wpdb->get_col("SELECT option_name FROM $wpdb->options WHERE option_name LIKE '%aifinpay%'"), $wpdb->get_col("SHOW TABLES LIKE '%aifinpay%'")]);`);
    assert.equal(left, '[[],[]]');
  });
  await check('uninstall', 'installing again starts clean, in Shadow', async () => {
    await wp('plugin', 'install', zip, '--activate');
    const r = await get('/paid/', GPTBOT);
    assert.equal(r.status, 200);
    assert.equal(r.headers['aifp-mode'], 'shadow');
  });
} finally {
  await stopServer();
  if (api.listening) await new Promise((r) => api.close(r));
  const failed = results.filter((x) => !x.pass).length;
  writeFileSync(join(root, '.local', `${name}.json`), JSON.stringify({ scope: `real WordPress + ${db}, php -S, LOCAL MOCK API, no chain and no real payment`, passed: results.length - failed, failed, results }, null, 2) + '\n');
  console.log(`\n${db}: ${results.length - failed} passed, ${failed} failed`);
  process.exitCode = failed ? 1 : 0;
}
