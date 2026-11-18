// "Who reads for free" over real HTTP: a fresh WordPress, the plugin from this checkout, a real theme and, last, a real page cache.
// LOCAL MOCK API, fake DNS and fake Turnstile through test-only hooks in a must-use plugin: nothing leaves the machine but downloads of WordPress and WP Super Cache.
// Usage: node tests/e2e/unproven.mjs [--only=section,section]
import assert from 'node:assert/strict';
import { execFile, execFileSync, spawn } from 'node:child_process';
import { createHmac, generateKeyPairSync, randomBytes, sign } from 'node:crypto';
import { cpSync, existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { createServer, request as httpRequest } from 'node:http';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';

const exec = promisify(execFile);
const root = dirname(dirname(dirname(fileURLToPath(import.meta.url))));
const only = (process.argv.find((a) => a.startsWith('--only=')) ?? '').slice(7).split(',').filter(Boolean);
const name = 'e2e-unproven';
const PORT = 8193;
const API = PORT + 1000;
const site = join(root, '.local', name);
const web = join(site, 'web');
const base = `http://127.0.0.1:${PORT}`;

const wp = async (...args) => (await exec('wp', [`--path=${web}`, ...args], { maxBuffer: 1 << 24 })).stdout.trim();
const wpEval = (code) => wp('eval', code);

const MERCHANT = 'mrch_wp_unproven';
const AGENT = '0x2714ca06600db9c5167eafa37216716a8adc83fe';
const MARK = 'PAID CONTENT MARKER';
const LONG = `${MARK} the quarterly figures show that revenue from the northern region doubled while costs fell`;
const CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
const FIREFOX = 'Mozilla/5.0 (X11; Linux x86_64; rv:133.0) Gecko/20100101 Firefox/133.0';
const GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
const GPTBOT = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)';
const HTML = 'text/html,application/xhtml+xml,*/*;q=0.8';
const SITEKEY = '0x4AAAAAAAe2eSiteKey';
const TS_SECRET = 'e2e-turnstile-secret';

// ── Mock AiFinPay API ───────────────────────────────────────────────────────
const { privateKey, publicKey } = generateKeyPairSync('ed25519');
const jwk = { ...publicKey.export({ format: 'jwk' }), kid: 'e2e-key', alg: 'EdDSA', use: 'sig' };
const b64 = (v) => Buffer.from(JSON.stringify(v)).toString('base64url');
const mint = (patch = {}, key = privateKey) => {
  const now = Math.floor(Date.now() / 1000);
  const a = b64({ alg: 'EdDSA', typ: 'JWT', kid: jwk.kid });
  const b = b64({ iss: 'https://api.aifinpay.io', aud: MERCHANT, sub: AGENT, payer: AGENT, iat: now, exp: now + 600, resource: '/paid', scope: 'exact', network_mode: 'test', receipt_id: 'rcpt_' + randomBytes(8).toString('hex'), unit_quota: 500, ...patch });
  return `${a}.${b}.${sign(null, Buffer.from(`${a}.${b}`), key).toString('base64url')}`;
};
const api = createServer(async (req, res) => {
  const json = (s, b) => { res.writeHead(s, { 'content-type': 'application/json' }); res.end(JSON.stringify(b)); };
  for await (const _ of req);
  if (req.url === `/v1/merchants/${MERCHANT}`) return json(200, { merchant_id: MERCHANT, name: 'Unproven e2e', network_mode: 'test', site_url: `${base}/`, site_verified: false });
  if (req.url === '/.well-known/jwks.json') return json(200, { keys: [jwk] });
  json(404, { error: 'not_found' });
});

// ── HTTP: raw paths, no redirects followed ─────────────────────────────────
const http = (method, path, headers = {}, body = null) => new Promise((resolve, reject) => {
  const req = httpRequest({ host: '127.0.0.1', port: PORT, method, path, headers: { 'User-Agent': CHROME, Accept: HTML, ...headers } }, (res) => {
    const chunks = [];
    res.on('data', (c) => chunks.push(c));
    res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, body: Buffer.concat(chunks).toString() }));
  });
  req.on('error', reject);
  if (body) req.write(body);
  req.end();
});
const get = (path, headers = {}) => http('GET', path, headers);
const has = (r) => r.body.includes(MARK);
const isJson402 = (r, label) => { assert.equal(r.status, 402, `${label}: ${r.status}`); assert.match(r.headers['content-type'], /^application\/json/, label); assert.equal(has(r), false, `${label}: paid text in a JSON 402`); assert.equal(JSON.parse(r.body).error, 'AIFP-402', label); };
const isHeld = (r, label) => {
  assert.equal(r.status, 402, `${label}: ${r.status}`);
  assert.match(r.headers['content-type'], /^text\/html/, label);
  assert.equal(has(r), false, `${label}: paid text in a held page`);
  assert.match(r.headers['cache-control'], /no-store/, label);
  assert.match(r.body, /<meta name=['"]robots['"] content=['"][^'"]*noindex/, `${label}: noindex`);
  assert.match(r.body, /<div data-aifp-paid><div id="aifp-w"><\/div>/, `${label}: the holder`);
  assert.match(r.body, /wp-block-post-title|class="wp-site-blocks"/, `${label}: the real page, not the bare one`);
  const terms = JSON.parse(r.body.match(/<script type="application\/json" id="aifp-402">([^<]*)<\/script>/)[1]);
  assert.equal(terms.error, 'AIFP-402', label);
  assert.equal(terms.merchant_id, MERCHANT, label);
  assert.match(r.body, /challenges\.cloudflare\.com\/turnstile\/v0\/api\.js/, label);
  assert.match(r.body, /appearance:"interaction-only"/, label);
};
const isBare = (r, label) => {
  assert.equal(r.status, 402, `${label}: ${r.status}`);
  assert.equal(has(r), false, `${label}: paid text in the bare page`);
  assert.ok(r.body.startsWith('<!doctype html><html lang="en"><head><meta charset="utf-8">'), `${label}: not the bare page`);
  assert.match(r.body, /id="aifp-402"/, label);
};
const isServed = (r, label) => { assert.equal(r.status, 200, `${label}: ${r.status}`); assert.ok(has(r), `${label}: no paid text`); };

// ── Harness ────────────────────────────────────────────────────────────────
const results = [];
const check = async (section, label, fn) => {
  if (only.length && !only.includes(section)) return;
  try { await fn(); results.push({ section, name: label, pass: true }); console.log(`PASS ${section}: ${label}`); }
  catch (e) { results.push({ section, name: label, pass: false, error: e.message }); console.error(`FAIL ${section}: ${label}\n     ${e.message}`); }
};
let server;
const stopServer = async () => { if (server) { const gone = new Promise((r) => server.once('exit', r)); try { process.kill(-server.pid, 'SIGKILL'); } catch {} await gone; server = null; } };
const startServer = async () => {
  await stopServer();
  server = spawn('php', ['-S', `127.0.0.1:${PORT}`, join(site, 'router.php')], { cwd: web, env: { ...process.env, PHP_CLI_SERVER_WORKERS: '6' }, stdio: 'ignore', detached: true });
  for (let i = 0; i < 100; i++) { try { await http('GET', '/wp-login.php'); return; } catch { await new Promise((r) => setTimeout(r, 100)); } }
  throw new Error('server did not start');
};
const configure = (patch) => wpEval(`AiFinPay\\WordPress\\Settings::save(array_merge(AiFinPay\\WordPress\\Settings::get(), json_decode(base64_decode('${Buffer.from(JSON.stringify(patch)).toString('base64')}'), true)));`);

// ── Site ───────────────────────────────────────────────────────────────────
execFileSync(join(root, 'scripts/setup-wp.sh'), [name, String(PORT), 'sqlite'], { stdio: 'inherit' });
const plugin = join(web, 'wp-content/plugins/aifinpay');
mkdirSync(plugin, { recursive: true });
for (const f of ['src', 'aifinpay.php', 'uninstall.php', 'readme.txt', 'LICENSE.txt']) cpSync(join(root, f), join(plugin, f), { recursive: true });
mkdirSync(join(web, 'wp-content/mu-plugins'), { recursive: true });
writeFileSync(join(web, 'wp-content/mu-plugins/aifinpay-e2e.php'), `<?php
// Test seams only: fake DNS, fake Turnstile, a client address from a header, and themes that print the text themselves.
add_filter( 'aifinpay_dns_resolver', static fn () => new class() implements AiFinPay\\Core\\Resolver {
	public function reverse( string $ip ): array {
		return [ '66.249.66.1' => [ 'crawl-66-249-66-1.googlebot.com' ], '66.249.66.9' => [ 'crawl.evilgooglebot.com' ] ][ $ip ] ?? throw new RuntimeException( 'NXDOMAIN' );
	}
	public function forward( string $name, int $family ): array {
		return [ 'crawl-66-249-66-1.googlebot.com' => [ '66.249.66.1' ], 'crawl.evilgooglebot.com' => [ '66.249.66.9' ] ][ $name ] ?? [];
	}
} );
add_filter( 'aifinpay_client_ip', static fn ( $ip ) => isset( $_SERVER['HTTP_X_TEST_IP'] ) ? $_SERVER['HTTP_X_TEST_IP'] : $ip );
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) {
	if ( 'https://challenges.cloudflare.com/turnstile/v0/siteverify' !== $url ) {
		return $pre;
	}
	$token   = $args['body']['response'] ?? '';
	$ok      = '${TS_SECRET}' === ( $args['body']['secret'] ?? '' ) && in_array( $token, [ 'good', 'elsewhere' ], true );
	$verdict = [ 'success' => $ok, 'hostname' => 'elsewhere' === $token ? 'elsewhere.test' : '127.0.0.1' ];
	return [ 'response' => [ 'code' => 200, 'message' => 'OK' ], 'body' => wp_json_encode( $verdict ), 'headers' => [], 'cookies' => [] ];
}, 10, 3 );
if ( isset( $_SERVER['HTTP_X_TEST_RAW'] ) ) {
	add_action( 'wp_footer', static function () {
		echo '<div>' . get_queried_object()->post_content . '</div>';
	} );
}
if ( isset( $_SERVER['HTTP_X_TEST_NO_CONTENT'] ) ) {
	add_filter( 'render_block_core/post-content', '__return_empty_string', PHP_INT_MAX );
}
if ( isset( $_SERVER['HTTP_X_TEST_EXCERPT'] ) ) {
	add_action( 'wp_footer', static function () {
		echo '<p id="excerpt">' . esc_html( get_the_excerpt( get_page_by_path( 'a-post', OBJECT, 'post' ) ) ) . '</p>';
	} );
}
`);
await wp('config', 'set', 'AIFINPAY_TEST_API_ORIGIN', `http://127.0.0.1:${API}`, '--quiet');
await wp('config', 'set', 'DISABLE_WP_CRON', 'true', '--raw', '--quiet');
await wp('plugin', 'activate', 'aifinpay');
const idOf = (out) => out.split('\n').pop().trim();
const pageId = idOf(await wp('post', 'create', '--post_type=page', '--post_title=Paid', '--post_name=paid', '--post_status=publish', `--post_content=<p>${LONG}</p>`, '--porcelain'));
await wp('post', 'create', '--post_type=page', '--post_title=Free', '--post_name=free', '--post_status=publish', '--post_content=FREE CONTENT', '--porcelain');
const postId = idOf(await wp('post', 'create', '--post_title=A post', '--post_name=a-post', '--post_status=publish', `--post_content=<p>${LONG} post</p>`, '--porcelain'));
await wp('post', 'delete', '1', '--force');
await wpEval(`update_option('aifinpay_connection', ['merchant_id' => '${MERCHANT}', 'secret' => 'msec_${'ab'.repeat(18)}', 'connected_at' => 1], false);`);
await new Promise((r) => api.listen(API, '127.0.0.1', r));
await startServer();

let admin = '';
const login = async () => {
  const jar = 'wordpress_test_cookie=WP%20Cookie%20check';
  const form = new URLSearchParams({ log: 'admin', pwd: readFileSync(join(site, 'admin-pass'), 'utf8'), wp_submit: 'Log In', testcookie: '1' }).toString();
  const res = await http('POST', '/wp-login.php', { Cookie: jar, 'Content-Type': 'application/x-www-form-urlencoded', 'Content-Length': Buffer.byteLength(form) }, form);
  assert.equal(res.status, 302, 'login');
  admin = (res.headers['set-cookie'] ?? []).map((c) => c.split(';')[0]).join('; ');
};
const human = (token, headers = {}) => http('POST', '/__aifp/human', { 'Content-Type': 'application/json', ...headers }, JSON.stringify({ token }));
let readerKey = null;
const forge = (ua, exp) => `__aifp_human=${exp}.${createHmac('sha256', readerKey).update(`${exp}\n${ua}`).digest('base64url')}`;
let cookie = '';
const proven = { mode: 'enforce', protected_paths: ['/paid', '/a-post'], free_readers: 'proven', turnstile_sitekey: SITEKEY, charge_alternates: true };

try {
  await check('setup', 'a site in Enforce on the default setting keeps today\'s rule', async () => {
    await configure({ mode: 'enforce', protected_paths: ['/paid', '/a-post'] });
    assert.equal(JSON.parse(await wp('option', 'get', 'aifinpay_settings', '--format=json')).free_readers, 'named');
    isServed(await get('/paid/'), 'browser');
    isJson402(await get('/paid/', { 'User-Agent': GPTBOT }), 'GPTBot');
    assert.equal((await human('good')).status, 404, 'no check endpoint outside proven');
    await login();
  });

  await check('nokeys', 'proven without keys: everyone pays, readers too, and the settings page says why', async () => {
    await configure(proven);
    isJson402(await get('/paid/'), 'browser');
    isJson402(await get('/paid/', { 'User-Agent': GOOGLEBOT, 'X-Test-Ip': '66.249.66.1' }), 'verified Googlebot');
    assert.equal(has(await get('/')), false, 'the home page lists the paid post without its text');
    assert.equal((await human('good')).status, 404, 'no endpoint without keys');
    const html = (await get('/wp-admin/options-general.php?page=aifinpay', { Cookie: admin })).body;
    assert.match(html, /id="aifinpay-readers-fallback"><strong>[^<]*needs the Turnstile site key and secret key/);
    assert.equal(html.includes(TS_SECRET), false);
  });
  await check('nokeys', 'saving the secret from the form turns proven on; it is never shown', async () => {
    const page = (await get('/wp-admin/options-general.php?page=aifinpay', { Cookie: admin })).body;
    const nonce = page.split('<form').find((f) => f.includes('value="aifinpay_save"')).match(/name="_wpnonce" value="([^"]+)"/)[1];
    const form = new URLSearchParams({ action: 'aifinpay_save', _wpnonce: nonce, _wp_http_referer: '/wp-admin/options-general.php?page=aifinpay', connect_code: '', mode: 'enforce', tier: 'standard', protected_paths: '/paid\n/a-post', excluded_paths: '', charge_alternates: '1', discovery_documents: '1', discovery_link: '1', free_readers: 'proven', turnstile_sitekey: SITEKEY, turnstile_secret: TS_SECRET }).toString();
    const res = await http('POST', '/wp-admin/admin-post.php', { Cookie: admin, 'Content-Type': 'application/x-www-form-urlencoded', 'Content-Length': Buffer.byteLength(form) }, form);
    assert.equal(res.status, 302);
    const after = (await get('/wp-admin/options-general.php?page=aifinpay', { Cookie: admin })).body;
    assert.equal(after.includes(TS_SECRET), false, 'the secret is not on the page');
    assert.doesNotMatch(after, /id="aifinpay-readers-fallback"/);
    assert.match(after, /Saved\. It is not shown again/);
    const autoload = await wpEval(`global $wpdb; echo $wpdb->get_var("SELECT autoload FROM $wpdb->options WHERE option_name='aifinpay_reader_keys'");`);
    assert.match(autoload, /^(no|off)/);
    assert.equal((await wp('option', 'get', 'aifinpay_settings', '--format=json')).includes(TS_SECRET), false);
    readerKey = Buffer.from(await wpEval('echo get_option("aifinpay_reader_keys")["reader_key"];'), 'base64');
    assert.equal(readerKey.length, 32);
  });

  // ── The check endpoint ───────────────────────────────────────────────────
  await check('endpoint', 'a passed check gives the shared cookie and nothing else', async () => {
    const r = await human('good');
    assert.equal(r.status, 204);
    const set = r.headers['set-cookie'];
    assert.equal(set.length, 1);
    assert.match(set[0], /^__aifp_human=\d+\.[A-Za-z0-9_-]{43}; Path=\/; Max-Age=2592000; HttpOnly; Secure; SameSite=Lax$/);
    assert.match(r.headers['cache-control'], /no-store/);
    cookie = set[0].split(';')[0];
    const exp = Number(cookie.split('=')[1].split('.')[0]);
    assert.equal(cookie, forge(CHROME, exp), 'the shared format, signed for this User-Agent');
  });
  await check('endpoint', 'another hostname, a failed verify, GET and a bad body are refused', async () => {
    for (const [r, s] of [[await human('elsewhere'), 403], [await human('bad'), 403], [await get('/__aifp/human'), 405], [await http('POST', '/__aifp/human', { 'Content-Type': 'application/json' }, 'nope'), 400], [await http('POST', '/__aifp/human', { 'Content-Type': 'application/json' }, '{"token":""}'), 400]]) {
      assert.equal(r.status, s);
      assert.equal(r.headers['set-cookie'], undefined);
    }
  });

  // ── The decision table on the paid page ──────────────────────────────────
  const expired = () => forge(CHROME, Math.floor(Date.now() / 1000) - 10);
  const clients = () => [
    ['receipt valid', { 'User-Agent': 'aifinpay-agent-node/2.14', 'AIFP-Receipt': mint(), 'AIFP-Agent-Id': AGENT }, 'GET'],
    ['receipt invalid', { 'User-Agent': 'aifinpay-agent-node/2.14', 'AIFP-Receipt': mint({}, generateKeyPairSync('ed25519').privateKey), 'AIFP-Agent-Id': AGENT }, 'GET'],
    ['agent header', { 'AIFP-Agent-Id': AGENT }, 'GET'],
    ['reader cookie', { Cookie: cookie }, 'GET'],
    ['forged cookie', { Cookie: cookie.replace(/\.(.)/, (m, c) => `.${c === 'A' ? 'B' : 'A'}`) }, 'GET'],
    ['expired cookie', { Cookie: expired() }, 'GET'],
    ['cookie of another User-Agent', { Cookie: cookie, 'User-Agent': FIREFOX }, 'GET'],
    ['verified search bot', { 'User-Agent': GOOGLEBOT, 'X-Test-Ip': '66.249.66.1' }, 'GET'],
    ['fake search bot', { 'User-Agent': GOOGLEBOT, 'X-Test-Ip': '66.249.66.9' }, 'GET'],
    ['browser HTML', {}, 'GET'],
    ['non-HTML', { Accept: 'application/json' }, 'GET'],
    ['POST', { 'Content-Length': 0 }, 'POST'],
    ['AI crawler', { 'User-Agent': GPTBOT }, 'GET'],
    ['signed in', { Cookie: admin }, 'GET'],
  ];
  const expect = {
    named: { 'receipt valid': 'served', 'receipt invalid': 403, 'agent header': 'json', 'reader cookie': 'served', 'forged cookie': 'served', 'expired cookie': 'served', 'cookie of another User-Agent': 'served', 'verified search bot': 'served', 'fake search bot': 'served', 'browser HTML': 'served', 'non-HTML': 'served', POST: 'served', 'AI crawler': 'json', 'signed in': 'served' },
    proven: { 'receipt valid': 'served', 'receipt invalid': 403, 'agent header': 'json', 'reader cookie': 'served', 'forged cookie': 'held', 'expired cookie': 'held', 'cookie of another User-Agent': 'held', 'verified search bot': 'served', 'fake search bot': 'held', 'browser HTML': 'held', 'non-HTML': 'json', POST: 'json', 'AI crawler': 'held', 'signed in': 'served' },
    everyone: { 'receipt valid': 'served', 'receipt invalid': 403, 'agent header': 'json', 'reader cookie': 'json', 'forged cookie': 'json', 'expired cookie': 'json', 'cookie of another User-Agent': 'json', 'verified search bot': 'json', 'fake search bot': 'json', 'browser HTML': 'json', 'non-HTML': 'json', POST: 'json', 'AI crawler': 'json', 'signed in': 'served' },
  };
  for (const setting of ['named', 'proven', 'everyone']) {
    await check(`table-${setting}`, `the paid page, every client, setting ${setting}`, async () => {
      await configure({ ...proven, free_readers: setting });
      for (const [who, headers, method] of clients()) {
        const r = await http(method, '/paid/', headers);
        const want = expect[setting][who];
        const label = `${setting} / ${who}`;
        if (want === 'served') isServed(r, label);
        else if (want === 'held') isHeld(r, label);
        else if (want === 'json') isJson402(r, label);
        else { assert.equal(r.status, want, label); assert.equal(has(r), false, label); assert.match(r.headers['content-type'], /^application\/json/, label); }
      }
    });
    await check(`table-${setting}`, `the excerpt, the feed, the REST API and the home page, setting ${setting}`, async () => {
      await configure({ ...proven, free_readers: setting, charge_alternates: false });
      const free = setting === 'named';
      for (const [who, headers, readsFree] of [['browser', {}, free], ['reader', { Cookie: cookie }, free || setting === 'proven'], ['AI crawler', { 'User-Agent': GPTBOT }, free]]) {
        const label = `${setting} / ${who}`;
        const excerpt = await get('/free/', { ...headers, 'X-Test-Excerpt': '1' });
        assert.equal(excerpt.status, 200, label);
        assert.equal(has(excerpt), readsFree, `${label}: excerpt`);
        const feed = await get('/feed/', { ...headers, Accept: 'application/rss+xml' });
        assert.equal(feed.status, 200, `${label}: feed ${feed.status}`);
        assert.match(feed.body, /<title>A post<\/title>/, label);
        assert.equal(has(feed), readsFree, `${label}: feed`);
        const rest = await get(`/wp-json/wp/v2/posts/${postId}`, { ...headers, Accept: 'application/json' });
        assert.equal(rest.status, 200, `${label}: REST ${rest.status}`);
        assert.equal(JSON.parse(rest.body).title.rendered, 'A post', label);
        assert.equal(has(rest), readsFree, `${label}: REST`);
        const page = await get(`/wp-json/wp/v2/pages/${pageId}`, { ...headers, Accept: 'application/json' });
        assert.equal(has(page), readsFree, `${label}: REST page`);
        const home = await get('/', headers);
        assert.equal(home.status, 200, label);
        assert.match(home.body, /A post/, label);
        assert.equal(has(home), readsFree, `${label}: home`);
      }
    });
  }
  await check('table-proven', 'the REST API and the feed charged as alternates: the 402 JSON, never the text', async () => {
    await configure(proven);
    for (const path of ['/feed/', `/wp-json/wp/v2/posts/${postId}`]) {
      isJson402(await get(path), `browser ${path}`);
      isServed(await get(path, { Cookie: cookie }), `reader ${path}`);
    }
  });

  // ── The held page ────────────────────────────────────────────────────────
  await check('held', 'held page: a reader gets the same page whole, the content marked where the holder was', async () => {
    await configure(proven);
    const held = await get('/paid/');
    isHeld(held, 'held');
    assert.equal(held.body.match(/<div data-aifp-paid>/g).length, 1);
    const full = await get('/paid/', { Cookie: cookie });
    isServed(full, 'reader');
    assert.equal(full.body.match(/<div data-aifp-content>/g).length, 1, 'one marked element per holder');
    assert.match(full.body, /<div data-aifp-content>[\s\S]*PAID CONTENT MARKER/);
    assert.doesNotMatch(full.body, /turnstile/, 'no check for a reader');
    assert.match(full.headers['cache-control'], /no-store/);
  });
  await check('held', 'the terms in the held page are the 402 an agent gets', async () => {
    const held = JSON.parse((await get('/paid/')).body.match(/id="aifp-402">([^<]*)</)[1]);
    const json = JSON.parse((await get('/paid/', { Accept: 'application/json' })).body);
    assert.deepEqual(held, json);
    assert.equal(json.resource, '/paid');
  });
  await check('held', 'a theme that prints the text itself, or never renders the content: the bare page', async () => {
    isBare(await get('/paid/', { 'X-Test-Raw': '1' }), 'printed raw');
    isBare(await get('/paid/', { 'X-Test-No-Content': '1' }), 'no holder');
    isServed(await get('/paid/', { Cookie: cookie, 'X-Test-Raw': '1' }), 'a reader still gets the page');
  });
  await check('held', 'a free page for a visitor without the cookie: whole, with the check running hidden', async () => {
    const r = await get('/free/');
    assert.equal(r.status, 200);
    assert.match(r.body, /FREE CONTENT/);
    assert.match(r.body, /<div id="aifp-w" hidden><\/div>/);
    assert.match(r.body, /turnstile\/v0\/api\.js/);
    assert.doesNotMatch(r.body, /id="aifp-402"/);
    assert.doesNotMatch((await get('/free/', { Cookie: cookie })).body, /turnstile/, 'not for a reader');
    assert.doesNotMatch((await get('/free/', { Accept: 'application/json' })).body, /turnstile/, 'not for a client without HTML');
  });
  await check('held', 'HEAD of a paid page is a 402 and carries no text', async () => {
    const r = await http('HEAD', '/paid/');
    assert.equal(r.status, 402);
    assert.equal(r.body, '');
  });

  // ── A real page cache ────────────────────────────────────────────────────
  const stamp = (r) => r.body.match(/Cached page generated by WP-Super-Cache on ([^>]+?) -->/)?.[1] ?? null;
  const second = () => new Promise((r) => setTimeout(r, 1100));
  await check('cache', 'with WP Super Cache live, a free page is stored', async () => {
    const zipPath = join(root, '.local/cache/wp-super-cache.zip');
    if (!existsSync(zipPath)) execFileSync('curl', ['-sfL', 'https://downloads.wordpress.org/plugin/wp-super-cache.latest-stable.zip', '-o', zipPath]);
    await wp('plugin', 'install', zipPath, '--activate');
    const home = join(web, 'wp-content/plugins/wp-super-cache/');
    await wp('config', 'set', 'WP_CACHE', 'true', '--raw', '--quiet');
    await wp('config', 'set', 'WPCACHEHOME', home, '--quiet');
    writeFileSync(join(web, 'wp-content/advanced-cache.php'), readFileSync(join(home, 'advanced-cache.php')));
    writeFileSync(join(web, 'wp-content/wp-cache-config.php'), readFileSync(join(home, 'wp-cache-config-sample.php'), 'utf8').replace('$cache_enabled = false;', '$cache_enabled = true;\n$wp_cache_slash_check = 1;'));
    await startServer();
    await configure(proven);
    const first = stamp(await get('/free/', { Cookie: cookie }));
    await second();
    assert.ok(first, 'not stored');
    assert.equal(stamp(await get('/free/', { Cookie: cookie })), first, 'not served from the cache');
  });
  await check('cache', 'a held page is never stored', async () => {
    for (let i = 0; i < 2; i++) {
      const r = await get('/paid/');
      isHeld(r, `held ${i}`);
      assert.equal(stamp(r), null);
      await second();
    }
  });
  await check('cache', 'a full page for a reader is not stored, so no unproven client is served it', async () => {
    for (let i = 0; i < 2; i++) {
      const r = await get('/paid/', { Cookie: cookie });
      isServed(r, `reader ${i}`);
      assert.equal(stamp(r), null);
    }
    isHeld(await get('/paid/'), 'next visitor');
    const home = await get('/', { Cookie: cookie });
    assert.ok(has(home));
    assert.equal(stamp(home), null, 'a free page that shows a paid post is not stored either');
    await second();
    assert.equal(has(await get('/')), false, 'the next visitor gets the home page without the text');
  });
  await check('cache', 'clean up the cache plugin', async () => {
    await wp('plugin', 'deactivate', 'wp-super-cache');
    await wp('config', 'set', 'WP_CACHE', 'false', '--raw', '--quiet');
    rmSync(join(web, 'wp-content/advanced-cache.php'), { force: true });
    rmSync(join(web, 'wp-content/cache'), { recursive: true, force: true });
    await startServer();
  });
  await check('log', 'no PHP warnings or errors from the plugin, and no secret, in the log', async () => {
    const log = join(web, 'wp-content/debug.log');
    const text = existsSync(log) ? readFileSync(log, 'utf8') : '';
    assert.deepEqual(text.split('\n').filter((l) => /plugins\/aifinpay|AiFinPay\\/.test(l)), []);
    assert.equal(text.includes(TS_SECRET), false);
  });
} finally {
  await stopServer();
  if (api.listening) await new Promise((r) => api.close(r));
  const failed = results.filter((x) => !x.pass).length;
  writeFileSync(join(root, '.local', `${name}.json`), JSON.stringify({ scope: 'real WordPress + sqlite, php -S, LOCAL MOCK API, fake DNS and Turnstile', passed: results.length - failed, failed, results }, null, 2) + '\n');
  console.log(`\nunproven: ${results.length - failed} passed, ${failed} failed`);
  process.exitCode = failed ? 1 : 0;
}
