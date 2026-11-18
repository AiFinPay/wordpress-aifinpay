# AiFinPay for WordPress

Shadow by default: counts AI agents. Enforce: the HTTP 402 of `@aifinpay/gate`, receipts verified locally, units metered in the site's database. End-user text: `readme.txt`.

`src/Core` is free of WordPress calls (a test fails otherwise). `src/WordPress` is the adapter.

## Unproven pays

Setting **Who reads for free** (`free_readers` in `aifinpay_settings`), applied in Enforce on protected paths:

Shown as three modes; the stored values are unchanged.

- `named`, shown **AI agents only**: the default, also for sites saved before the setting existed. AI agents that name themselves pay; everyone else reads.
- `proven`, shown **Strict**: a browser that passed a Cloudflare Turnstile check (cookie `__aifp_human`, the format of `conformance/vectors/reader-proof-v1.json` in AiFinPay/sdk), a search engine verified by forward-confirmed reverse DNS, and a signed-in user read; everyone else gets the 402. A browser gets the page itself with status 402 and the paid part replaced by a holder, which fills after the check.
- `everyone`, shown **Everyone pays**: every request without a receipt pays, except a signed-in user.

`proven` needs the Turnstile site key (in the settings) and secret key, and a reader key the plugin generates; both secrets live in the non-autoloaded option `aifinpay_reader_keys`. Without them `proven` acts as `everyone`, never as free. The check endpoint is `POST /__aifp/human`.

The paid text is held back inside WordPress: `the_content`, the excerpt, feeds and the REST API of a post whose permalink is protected, and every post shown on a protected path. A response that still carries the text when it is complete is replaced by a bare 402 page.

Search engines are verified against the client address WordPress sees (`REMOTE_ADDR`, filter `aifinpay_client_ip`). Behind a proxy or CDN the site must pass the real address, otherwise no search engine is verified and all of them pay.

## What the page is

Setting **Tell agents what a page is** (`about_pages`): `title_summary` (the default for a new site), `title`, `off` (a site saved before the setting existed). It adds `about` right after `resource` in the 402 body and in the terms of a held page:

```json
"resource": "/docs/*", "about": { "title": "Birds & rivers", "description": "A field guide to river birds & their calls." }, "tier": "standard"
```

- `title`: the post title. `description`: the post's manual Excerpt (`post_excerpt`, empty unless the owner typed one; WordPress's automatic excerpt is never read), none for a password-protected post, and dropped when it repeats a run of the post's text.
- Only on a single post or page; an archive, a feed or the REST API gets `about` only from the filter `aifinpay_about` (`$about`, `?WP_Post $post`).
- Cleaned by `src/Core/About.php`, a port of `gate/src/about.ts` checked against `conformance/vectors/about-v1.json` of AiFinPay/sdk (copy in `tests/fixtures`). A wrong shape or a throwing filter leaves the 402 without `about` and otherwise unchanged.
- Information only: the quote, the receipt and metering do not read it. With the setting `off` the 402 is byte for byte the gate's.

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

Both check `@aifinpay/gate` 0.11.0 from npm; for an unpublished build set `GATE_DIST=<sdk>/gate/dist`. With a gate that has `about` (0.13.0), the parity check also replays its `pageAbout` and the place of `about` in the 402.

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
