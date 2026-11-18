# AiFinPay for WordPress

Shadow by default: counts AI agents. Enforce: the HTTP 402 of `@aifinpay/gate`, receipts verified locally, units metered in the site's database. End-user text: `readme.txt`.

`src/Core` is free of WordPress calls (a test fails otherwise). `src/WordPress` is the adapter.

## Unproven pays

Setting **Who reads for free** (`free_readers` in `aifinpay_settings`), applied in Enforce on protected paths:

- `named`: the default, also for sites saved before the setting existed. AI agents that name themselves pay; everyone else reads.
- `proven`: a browser that passed a Cloudflare Turnstile check (cookie `__aifp_human`, the format of `conformance/vectors/reader-proof-v1.json` in AiFinPay/sdk), a search engine verified by forward-confirmed reverse DNS, and a signed-in user read; everyone else gets the 402. A browser gets the page itself with status 402 and the paid part replaced by a holder, which fills after the check.
- `everyone`: every request without a receipt pays, except a signed-in user.

`proven` needs the Turnstile site key (in the settings) and secret key, and a reader key the plugin generates; both secrets live in the non-autoloaded option `aifinpay_reader_keys`. Without them `proven` acts as `everyone`, never as free. The check endpoint is `POST /__aifp/human`.

The paid text is held back inside WordPress: `the_content`, the excerpt, feeds and the REST API of a post whose permalink is protected, and every post shown on a protected path. A response that still carries the text when it is complete is replaced by a bare 402 page.

Search engines are verified against the client address WordPress sees (`REMOTE_ADDR`, filter `aifinpay_client_ip`). Behind a proxy or CDN the site must pass the real address, otherwise no search engine is verified and all of them pay.

## Test

Needs PHP 8.1+, Composer, Node 22+, WP-CLI, no Docker.

```bash
composer install
```

```bash
php vendor/bin/phpunit --testsuite core
```

```bash
scripts/setup-wp.sh test 8190 sqlite
```

```bash
AIFINPAY_WP_ROOT=.local/test/web php vendor/bin/phpunit --testsuite wordpress
```

```bash
node scripts/check-gate-parity.mjs
```

Both check `@aifinpay/gate` 0.11.0 from npm; for an unpublished build set `GATE_DIST=<sdk>/gate/dist`.

```bash
node tests/e2e/run.mjs sqlite
```

```bash
node tests/e2e/unproven.mjs
```

The second is "Who reads for free" over real HTTP with a page cache; DNS and Turnstile are answered by a test-only must-use plugin in `.local`.

For MySQL, start a private server first (`scripts/start-mysql.sh`), then `node tests/e2e/run.mjs mysql`.

```bash
node scripts/mutation-check.mjs
```

The fixtures in `tests/fixtures` are shared with the other AiFinPay gate adapters. `check-gate-parity.mjs` replays the published `@aifinpay/gate` against them.

## Build

```bash
scripts/build-zip.sh
```
