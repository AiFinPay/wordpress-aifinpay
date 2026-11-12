// Breaks the code on purpose and shows the tests go red. Restores every file, and refuses to start on a dirty src/.
// Usage: node scripts/mutation-check.mjs [--e2e] [--only=<part of a name>]   (--e2e also runs the mutants only a real WordPress can catch; about 2 minutes each)
import { execFileSync, spawnSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const withE2e = process.argv.includes('--e2e');
const only = (process.argv.find((a) => a.startsWith('--only=')) ?? '').slice(7);
const unit = [
  // Approval
  ['approval', 'every merchant is approved', 'src/Core/MerchantRecord.php', "'approved'     => in_array( $reason, [ 'test_merchant', 'live_domain_verified' ], true ),", "'approved'     => true,"],
  ['approval', 'an unverified live domain counts as verified', 'src/Core/MerchantRecord.php', "$reason = true === $record['site_verified'] ? 'live_domain_verified' : 'live_domain_unverified';", "$reason = 'live_domain_verified';"],
  ['approval', 'the gate ignores approval', 'src/Core/ReceiptGate.php', "|| true !== $charging['approved']\n", "\n"],
  ['approval', 'a record of another merchant is accepted', 'src/Core/MerchantRecord.php', "( $record['merchant_id'] ?? null ) === $merchant;", "true;"],
  ['approval', 'the Enforce form ignores approval', 'src/Core/Eligibility.php', "if ( ! $charging['approved'] ) {", "if ( false ) {"],
  ['approval', 'a stale record serves forever', 'src/WordPress/RecordStore.php', "&& $now - (int) ( $last['fetched_at'] ?? 0 ) <= self::STALE_LIMIT ", ""],
  ['approval', 'a typo in the mode turns charging on', 'src/WordPress/Settings.php', "in_array( $s['mode'], [ 'off', 'shadow', 'enforce' ], true ) ? $s['mode'] : 'shadow'", "( $s['mode'] ? ( 'off' === $s['mode'] || 'shadow' === $s['mode'] ? $s['mode'] : 'enforce' ) : 'shadow' )"],
  // Host
  ['host', 'the gate charges on any host', 'src/Core/ReceiptGate.php', "\n\t\t\t|| null === $charging['host'] || Request::normaliseHost( $request->host ) !== $charging['host'] ) {", " ) {"],
  ['host', 'the Enforce form ignores the host', 'src/Core/Eligibility.php', "} elseif ( Request::normaliseHost( $site_host ) !== $charging['host'] ) {", "} elseif ( false ) {"],
  ['host', 'the record host is not validated', 'src/Core/MerchantRecord.php', "if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || ! isset( $parts['host'] ) ) {", "if ( ! is_array( $parts ) || ! isset( $parts['host'] ) ) {"],
  // Metering
  ['metering', 'the UPDATE has no limit', 'src/WordPress/WpdbMeter.php', "'UPDATE %i SET used = used + %d WHERE receipt_hash = %s AND used + %d <= %d', $table, $weight, $hash, $weight, $limit", "'UPDATE %i SET used = used + %d WHERE receipt_hash = %s', $table, $weight, $hash"],
  ['metering', 'a first spend may exceed the batch', 'src/WordPress/WpdbMeter.php', "if ( (int) $exists > 0 || $weight > $limit ) {", "if ( (int) $exists > 0 ) {"],
  ['metering', 'one counter per receipt across merchants', 'src/WordPress/WpdbMeter.php', "hash( 'sha256', $merchant . \"\\0\" . $receiptId )", "hash( 'sha256', $receiptId )"],
  ['metering', 'every tier meters one unit', 'src/Core/ReceiptGate.php', "$this->meter->spend( $merchant, $p['receipt_id'], $weight, $limit,", "$this->meter->spend( $merchant, $p['receipt_id'], 1, $limit,"],
  ['metering', 'an exhausted batch is served', 'src/Core/ReceiptGate.php', "if ( null === $used ) {", "if ( false ) {"],
  ['metering', 'a broken meter serves free', 'src/Core/ReceiptGate.php', "[ 'error' => 'AIFP-503-METER', 'detail' => 'quota store unavailable — retry shortly' ],\n\t\t\t\t[ 'Retry-After' => '5' ],\n\t\t\t\tnull,\n\t\t\t\t'refused'\n\t\t\t);", "[ 'error' => 'x' ],\n\t\t\t\t[],\n\t\t\t\tnull,\n\t\t\t\t'paid'\n\t\t\t) && false ?: self::result( 200, null, [], null, 'paid' );"],
  ['metering', 'any payer may spend a batch', 'src/Core/ReceiptGate.php', "if ( is_string( $payer ) && '' !== $payer && ! self::samePayer( $payer, $agent ) ) {", "if ( false ) {"],
  ['metering', 'a receipt alone spends the batch', 'src/Core/ReceiptGate.php', "if ( is_string( $payer ) && '' !== $payer && ! self::samePayer( $payer, $agent ) ) {", "if ( is_string( $payer ) && '' !== $payer && '' !== (string) $agent && ! self::samePayer( $payer, $agent ) ) {"],
  ['metering', 'a single-use receipt needs no nonce', 'src/Core/ReceiptGate.php', "if ( $limit <= 1 && ( ! is_string( $p['nonce'] ?? null ) || '' === $p['nonce'] ) ) {", "if ( false ) {"],
  ['metering', 'a kid-less token picks any key of several', 'src/Core/Receipt.php', "if ( null !== $key || 'OKP' !== ( $jwk['kty'] ?? '' )", "if ( 'OKP' !== ( $jwk['kty'] ?? '' )"],
  ['metering', 'any typ is accepted', 'src/Core/Receipt.php', "|| ( isset( $h['typ'] ) && 'JWT' !== $h['typ'] ) ", ""],
  ['metering', 'a bad unit_quota counts as one', 'src/Core/ReceiptGate.php', "if ( isset( $p['unit_quota'] ) && ! is_int( $p['unit_quota'] ) ) {", "if ( false ) {"],
  ['metering', 'the SDK section batch misses the section page', 'src/Core/ReceiptGate.php', "return $path === substr( $boundary, 0, -1 ) || str_starts_with( $path, $boundary );", "return str_starts_with( $path, $boundary );"],
  ['metering', 'a receipt opens any path', 'src/Core/ReceiptGate.php', "if ( ! self::scopeCovers( $p['scope'] ?? null, is_string( $bought ) ? Paths::spelled( $bought ) : $bought, $path ) ) {", "if ( false ) {"],
  ['metering', 'a testnet receipt opens a live site', 'src/Core/ReceiptGate.php', "if ( null !== $network && ( 'test' === ( $p['network_mode'] ?? null ) ? 'test' : 'live' ) !== $network ) {", "if ( false ) {"],
  ['metering', 'a receipt with no network claim opens a test site', 'src/Core/ReceiptGate.php', "( 'test' === ( $p['network_mode'] ?? null ) ? 'test' : 'live' ) !== $network", "isset( $p['network_mode'] ) && $p['network_mode'] !== $network"],
  ['metering', 'a key outage serves free', 'src/Core/ReceiptGate.php', "if ( 'keys_unavailable' === $e->getMessage() ) {", "if ( 'keys_unavailable' === $e->getMessage() ) {\n\t\t\t\treturn self::result( 200, null, [], null, 'paid' );"],
  ['metering', 'an action receipt is spendable', 'src/Core/ReceiptGate.php', "if ( isset( $p['typ_aifp'] ) && 'quota' !== $p['typ_aifp'] ) {", "if ( false ) {"],
  // Readers
  ['readers', 'browsers are charged', 'src/Core/AgentIdentity.php', "return 'human' === $class || ( $request->loggedIn && 'aifp_agent' !== $class );", "return $request->loggedIn && 'aifp_agent' !== $class;"],
  ['readers', 'logged-in users are charged', 'src/Core/AgentIdentity.php', "return 'human' === $class || ( $request->loggedIn && 'aifp_agent' !== $class );", "return 'human' === $class;"],
  ['readers', 'a login exempts a paying agent', 'src/Core/AgentIdentity.php', "( $request->loggedIn && 'aifp_agent' !== $class )", "$request->loggedIn"],
  ['readers', 'an unapproved site refuses readers too', 'src/Core/ReceiptGate.php', "if ( AgentIdentity::isReader( $request ) ) {\n\t\t\treturn self::result( 200, null, [ 'AIFP-Paywall' => 'exempt' ], null, 'reader' );\n\t\t}\n\t\tif ( ! preg_match", "if ( ! preg_match"],
  // Shadow and the secret
  ['shadow', 'a fresh install charges', 'src/WordPress/Settings.php', "'mode'                => 'shadow',", "'mode'                => 'enforce',"],
  ['shadow', 'shadow reports readers', 'src/Core/ReceiptGate.php', "if ( AgentIdentity::isReader( $request ) ) {\n\t\t\treturn self::result( 200, null, [], null, 'reader' );\n\t\t}\n", ""],
  ['shadow', 'shadow spends the batch', 'src/Core/ReceiptGate.php', "if ( ! $spend ) {", "if ( false ) {"],
  ['shadow', 'a refused receipt reaches the agent in shadow', 'src/Core/ReceiptGate.php', "return self::result( 200, null, $headers, $would['event'], null === $would['event'] ? 'refused' : 'shadow', $would['units'] );", "return $would;"],
  ['shadow', 'shadow takes every receipt on trust', 'src/Core/ReceiptGate.php', "$headers = 200 === $would['status'] ? [] : [ 'AIFP-Shadow-Status' => (string) $would['status'] ];", "$headers = [];"],
  ['shadow', 'reporting is on by default', 'src/WordPress/Settings.php', "'report_events'       => false,", "'report_events'       => true,"],
  ['secret', 'the secret is autoloaded', 'src/WordPress/Connection.php', "'connected_at' => time() ], false );", "'connected_at' => time() ], true );"],
  ['paths', '%3F is decoded', 'src/Core/Paths.php', "$code < 0x80 && 0x3f !== $code && 0x23 !== $code", "$code < 0x80 && 0x23 !== $code"],
  ['paths', '%23 is decoded', 'src/Core/Paths.php', "$code < 0x80 && 0x3f !== $code && 0x23 !== $code", "$code < 0x80 && 0x3f !== $code"],
  ['paths', 'a spelling still decoding is not ambiguous', 'src/Core/Paths.php', " || self::decodeRound( $path ) !== $path;", ";"],
  ['paths', 'an escaped mark walks around an exact pattern', 'src/Core/Paths.php', "\n\t\t\t?? self::firstProtected( array_filter( array_map( [ self::class, 'beforeMark' ], $candidates ) ), $protected, $excluded );", ";"],
  ['paths', 'a receipt for the page before the mark opens a page under the rule', 'src/Core/Paths.php', "return self::firstProtected( $candidates, $protected, $excluded )\n\t\t\t?? self::firstProtected( array_filter( array_map( [ self::class, 'beforeMark' ], $candidates ) ), $protected, $excluded );", "return self::firstProtected( array_filter( array_map( [ self::class, 'beforeMark' ], $candidates ) ), $protected, $excluded )\n\t\t\t?? self::firstProtected( $candidates, $protected, $excluded );"],
  ['paths', 'an escaped mark hides in a dropped parameter', 'src/Core/Paths.php', "if ( 1 === preg_match( '/%(?:3f|23)/i', substr( $piece, strlen( $segment ) ) ) ) {", "if ( false ) {"],
  ['paths', 'an escaped mark reads as ambiguous', 'src/Core/Paths.php', " || self::decodeRound( $path ) !== $path;", " || self::decodeRound( $path ) !== $path || str_contains( $path, '%3f' );"],
  ['paths', 'a raw # is not ambiguous', 'src/Core/Paths.php', "str_contains( $target, '#' ) || ", ""],
  ['paths', 'a dot segment is not ambiguous', 'src/Core/Paths.php', "$ambiguous = true;\n", "\n"],
  ['paths', 'one decoding round', 'src/Core/Paths.php', "$round < 3", "$round < 1"],
  ['paths', 'non-ASCII stays raw', 'src/Core/Paths.php', "$canonical = preg_replace_callback( '/[\\x80-\\xff]/', static fn ( array $m ): string => sprintf( '%%%02x', ord( $m[0] ) ), '/' . implode( '/', $out ) );", "$canonical = '/' . implode( '/', $out );"],
  ['paths', 'the longest pattern wins over an exact one', 'src/Core/Paths.php', ": PHP_INT_MAX;", ": strlen( $pattern );"],
  ['paths', 'the receipt resource is compared as written', 'src/Core/ReceiptGate.php', "is_string( $bought ) ? Paths::spelled( $bought ) : $bought", "$bought"],
  ['parity', 'the 402 body drifts', 'src/Core/ReceiptGate.php', "'no_minimum_fee'            => true,", "'no_minimum_fee'            => false,"],
  ['parity', 'the minimum batch drifts', 'src/Core/Pricing.php', "'standard' => 40,", "'standard' => 200,"],
  ['parity', 'a classifier marker goes missing', 'src/Core/AgentIdentity.php', "\t\t'claudebot'          => [ 'anthropic', 'ai_crawler' ],\n", ""],
  ['events', 'a rejected credential drops the events', 'src/WordPress/EventDelivery.php', " || 401 === $status || 403 === $status;", ";"],
  ['events', 'the raw User-Agent goes on the wire', 'src/WordPress/EventQueue.php', "'verified' => $event['verified'],", "'verified' => $event['verified'],\n\t\t\t'ua' => 'x',"],
];
const e2e = [
  ['cache', 'no DONOTCACHEPAGE', 'src/WordPress/CacheCompat.php', "define( 'DONOTCACHEPAGE', true );", "// removed"],
  ['cache', 'no purge on a mode change', 'src/WordPress/Admin.php', "if ( $changed( 'mode' ) || $changed( 'protected_paths' ) || $changed( 'excluded_paths' ) || $changed( 'charge_alternates' ) ) {", "if ( false ) {"],
  ['cache', 'no Cache-Control: no-store', 'src/WordPress/CacheCompat.php', "header( 'Cache-Control: private, no-store, max-age=0' );", "// removed"],
  ['cache', 'Surrogate-Control survives on a protected response', 'src/WordPress/CacheCompat.php', "'Surrogate-Control', ", ""],
  ['cache', 'no cf-edge-cache for APO', 'src/WordPress/CacheCompat.php', "header( 'cf-edge-cache: no-cache' );", "// removed"],
  ['bypass', 'the gate runs before canonical redirects', 'src/WordPress/Plugin.php', "[ FrontGate::class, 'onTemplateRedirect' ], 20, 0", "[ FrontGate::class, 'onTemplateRedirect' ], 1, 0"],
  ['bypass', 'the permalink of the queried post is not matched', 'src/WordPress/FrontGate.php', "if ( is_singular() ) {", "if ( false ) {"],
  ['bypass', 'query-string feeds are not matched', 'src/WordPress/FrontGate.php', "if ( is_feed() ) {", "if ( false ) {"],
  ['bypass', 'an ambiguous spelling gets a price', 'src/WordPress/FrontGate.php', "if ( $ambiguous && 'enforce' === $settings['mode'] && ! AgentIdentity::isReader( $request ) ) {", "if ( false ) {"],
  ['readers', 'an ambiguous spelling refuses a reader', 'src/WordPress/FrontGate.php', "if ( $ambiguous && 'enforce' === $settings['mode'] && ! AgentIdentity::isReader( $request ) ) {", "if ( $ambiguous && 'enforce' === $settings['mode'] ) {"],
  ['cache', 'Edge-Control survives on a protected response', 'src/WordPress/CacheCompat.php', "'Edge-Control', ", ""],
  ['bypass', 'OPTIONS skips the gate on a page', 'src/WordPress/FrontGate.php', "$candidates = [ $request->path ];", "if ( 'OPTIONS' === $request->method ) {\n\t\t\treturn;\n\t\t}\n\t\t$candidates = [ $request->path ];"],
  ['bypass', 'no REST gate', 'src/WordPress/Plugin.php', "add_action( 'rest_api_init', [ FrontGate::class, 'onRestInit' ], 0, 0 );", ""],
  ['bypass', 'a site-wide pattern reaches every REST route', 'src/WordPress/FrontGate.php', "$patterns = array_filter( Settings::patterns( $settings ), static fn ( string $p ): bool => str_starts_with( $p, $rest . '/' ) );", "$patterns = Settings::patterns( $settings );"],
  ['parity', 'a JSON refusal keeps the discovery Link', 'src/WordPress/FrontGate.php', "header_remove( 'Link' );", ""],
  ['approval', 'the charging host and approval are read from settings', 'src/WordPress/FrontGate.php', "$charging = $reader ? MerchantRecord::charging( '', null ) : MerchantRecord::charging( $merchant, RecordStore::get() );", "$charging = $reader ? MerchantRecord::charging( '', null ) : ( ( $settings['approved'] ?? false ) ? [ 'approved' => true, 'host' => '127.0.0.1', 'network_mode' => 'live', 'reason' => 'x' ] : MerchantRecord::charging( $merchant, RecordStore::get() ) );"],
];
if (execFileSync('git', ['-C', root, 'status', '--porcelain', 'src', 'aifinpay.php'], { encoding: 'utf8' }).trim()) {
  console.error('Commit src/ first: this script edits it in place.');
  process.exit(2);
}
const wpRoot = join(root, '.local/test/web');
const phpunit = () => {
  const run = (args, env = {}) => spawnSync('php', ['vendor/bin/phpunit', ...args], { cwd: root, encoding: 'utf8', env: { ...process.env, ...env }, maxBuffer: 1 << 26 });
  for (const r of [run(['--testsuite', 'core']), run(['--testsuite', 'wordpress'], { AIFINPAY_WP_ROOT: wpRoot })]) {
    if (r.status !== 0) {
      const first = (r.stdout + r.stderr).match(/^1\) (.+)$/m)?.[1] ?? 'failed to run';
      return first.replace(/ with data set.*/, '');
    }
  }
  return null;
};
const fullE2e = () => {
  const r = spawnSync('node', ['tests/e2e/run.mjs', 'sqlite'], { cwd: root, encoding: 'utf8', maxBuffer: 1 << 26 });
  if (r.status === 0) return null;
  return (r.stderr.match(/^FAIL (.+)$/m)?.[1] ?? 'failed to run');
};
const baseline = phpunit();
if (baseline) { console.error(`Baseline is red: ${baseline}`); process.exit(2); }
let survived = 0;
const lines = ['| Area | Broken on purpose | Result | First test that went red |', '|---|---|---|---|'];
const runAll = (mutants, runner, label) => {
  for (const [area, name, file, find, replace] of mutants) {
    if (only && !name.includes(only)) continue;
    const path = join(root, file);
    const original = readFileSync(path, 'utf8');
    if (!original.includes(find)) { console.error(`Mutant no longer applies: ${name}`); survived++; lines.push(`| ${area} | ${name} | **DOES NOT APPLY** | |`); continue; }
    writeFileSync(path, original.replace(find, replace));
    let red;
    try { red = runner(); } finally { writeFileSync(path, original); }
    if (!red) survived++;
    const result = red ? 'red' : '**SURVIVED**';
    console.log(`${red ? 'RED     ' : 'SURVIVED'} ${label} ${area}: ${name}${red ? ` <- ${red}` : ''}`);
    lines.push(`| ${area} | ${name} | ${result} (${label}) | ${red ?? ''} |`);
  }
};
runAll(unit, phpunit, 'PHPUnit');
if (withE2e) runAll(e2e, fullE2e, 'e2e');
writeFileSync(join(root, '.local/mutation.md'), lines.join('\n') + '\n');
console.log(`\n${survived} survived.`);
process.exitCode = survived ? 1 : 0;
