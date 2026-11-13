# AiFinPay for WordPress

Shadow by default: counts AI agents. Enforce: the HTTP 402 of `@aifinpay/gate`, receipts verified locally, units metered in the site's database. End-user text: `readme.txt`.

`src/Core` is free of WordPress calls (a test fails otherwise). `src/WordPress` is the adapter.

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

For MySQL, start a private server first (`scripts/start-mysql.sh`), then `node tests/e2e/run.mjs mysql`.

```bash
node scripts/mutation-check.mjs
```

The fixtures in `tests/fixtures` are shared with the other AiFinPay gate adapters. `check-gate-parity.mjs` replays the published `@aifinpay/gate` against them.

## Build

```bash
scripts/build-zip.sh
```
