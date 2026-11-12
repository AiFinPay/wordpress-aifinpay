<?php

declare(strict_types=1);

use AiFinPay\Core\Paths;
use AiFinPay\Tests\TestCase;
use AiFinPay\WordPress\Admin;
use AiFinPay\WordPress\Api;
use AiFinPay\WordPress\CacheCompat;
use AiFinPay\WordPress\Connection;
use AiFinPay\WordPress\FrontGate;
use AiFinPay\WordPress\Maintenance;
use AiFinPay\WordPress\RecordStore;
use AiFinPay\WordPress\Resources;
use AiFinPay\WordPress\Schema;
use AiFinPay\WordPress\Settings;
use AiFinPay\WordPress\TrafficLog;
use AiFinPay\WordPress\WpdbMeter;
use AiFinPay\WordPress\WpKeySet;

require_once __DIR__ . '/TestCase.php';

final class AdapterTest extends TestCase {

	public function testAFreshSiteIsInShadowOnTheWholeSite(): void {
		$s = Settings::get();
		self::assertSame( 'shadow', $s['mode'] );
		self::assertSame( [ Paths::ALL ], $s['protected_paths'] );
		self::assertFalse( $s['report_events'] );
	}

	public function testOnlyTheExactWordTurnsChargingOn(): void {
		foreach ( [ 'ENFORCE', 'Enforce', ' enforce', 1, true, null, [] ] as $bad ) {
			update_option( Settings::OPTION, [ 'mode' => $bad ] );
			self::assertSame( 'shadow', Settings::get()['mode'], var_export( $bad, true ) );
		}
		update_option( Settings::OPTION, [ 'mode' => 'enforce' ] );
		self::assertSame( 'enforce', Settings::get()['mode'] );
	}

	public function testSettingsCannotCarryApprovalOrHost(): void {
		Settings::save( Settings::defaults() + [ 'approved' => true, 'site_host' => 'evil.test', 'receipt_gate_approved' => true ] );
		$stored = get_option( Settings::OPTION );
		self::assertArrayNotHasKey( 'approved', $stored );
		self::assertArrayNotHasKey( 'site_host', $stored );
		self::assertArrayNotHasKey( 'receipt_gate_approved', $stored );
	}

	public function testBadStoredPathsAreDropped(): void {
		self::assertSame( [ '/ok' ], $this->settings( [ 'protected_paths' => [ '/ok', '/bad//', 7 ] ] )['protected_paths'] );
	}

	public function testAlternatesOnlyInEnforce(): void {
		self::assertSame( [ '/paid' ], Settings::patterns( $this->settings( [ 'protected_paths' => [ '/paid' ] ] ) ) );
		self::assertSame( [ '/paid', '/wp-json/wp/v2/*', '/feed/*' ], Settings::patterns( $this->settings( [ 'protected_paths' => [ '/paid' ], 'mode' => 'enforce' ] ) ) );
		self::assertSame( [ '/paid' ], Settings::patterns( $this->settings( [ 'protected_paths' => [ '/paid' ], 'mode' => 'enforce', 'charge_alternates' => false ] ) ) );
	}

	public function testASubdirectorySiteKeepsItsPrefix(): void {
		$home = static fn (): string => 'https://x.test/Blog/';
		add_filter( 'home_url', $home );
		try {
			self::assertSame( '/blog', Settings::homePath() );
			self::assertSame( [ '/blog/wp-json/wp/v2/*', '/blog/feed/*' ], Settings::alternates() );
		} finally {
			remove_filter( 'home_url', $home );
		}
	}

	public function testTheSecretIsNotAutoloadedNorShown(): void {
		$this->responder = static fn () => [ 200, [ 'merchant_id' => self::MERCHANT, 'secret' => self::SECRET ] ];
		$out             = Connection::redeem( 'AIFP-7K2M-QX9D-4HNC-T0RB' );
		Connection::save( $out['merchant_id'], $out['secret'] );
		self::assertSame( 'POST', $this->calls[0]['method'] );
		self::assertSame( '{"code":"AIFP7K2MQX9D4HNCT0RB"}', $this->calls[0]['body'] );
		self::assertSame( self::MERCHANT, Connection::merchantId() );
		wp_load_alloptions( true );
		self::assertArrayNotHasKey( Connection::OPTION, wp_load_alloptions() );
		$this->responder = static fn () => [ 200, [ 'merchant_id' => self::MERCHANT, 'network_mode' => 'test', 'site_url' => 'https://s.test/' ] ];
		self::assertStringNotContainsString( self::SECRET, (string) wp_json_encode( Admin::statusRows( Settings::get() ) ) );
	}

	public function testAMalformedCodeNeverLeavesTheSite(): void {
		$this->expectExceptionMessage( 'invalid_code' );
		try {
			Connection::redeem( 'nope' );
		} finally {
			self::assertSame( [], $this->calls );
		}
	}

	public function testAStoredConnectionMustBeValid(): void {
		update_option( Connection::OPTION, [ 'merchant_id' => 'evil', 'secret' => self::SECRET ] );
		self::assertSame( '', Connection::merchantId() );
		update_option( Connection::OPTION, [ 'merchant_id' => self::MERCHANT, 'secret' => 'short' ] );
		self::assertSame( '', Connection::secret() );
	}

	public function testRequestsArePinned(): void {
		$this->responder = static fn () => 200;
		Api::request( 'GET', '/x' );
		self::assertSame( 0, $this->calls[0]['args']['redirection'] );
		self::assertTrue( $this->calls[0]['args']['sslverify'] );
		self::assertStringNotContainsString( 'http', $this->calls[0]['args']['user-agent'], 'the site URL stays out of the User-Agent' );
	}

	public function testRecordIsCachedThenRefreshed(): void {
		$this->connect();
		$this->responder = static fn () => [ 200, [ 'merchant_id' => self::MERCHANT, 'network_mode' => 'test', 'site_url' => 'https://s.test/' ] ];
		self::assertSame( 's.test', RecordStore::get()['site_host'] );
		RecordStore::get();
		self::assertCount( 1, $this->calls );
		self::assertStringEndsWith( '/v1/merchants/' . self::MERCHANT, $this->calls[0]['url'] );
		self::assertArrayNotHasKey( 'AIFP-Merchant-Secret', $this->calls[0]['headers'], 'the public record needs no secret' );
		RecordStore::get( true );
		self::assertCount( 2, $this->calls );
	}

	public function testAnOutageUsesTheLastRecordForADay(): void {
		$this->connect();
		$this->responder = static fn () => [ 200, [ 'merchant_id' => self::MERCHANT, 'network_mode' => 'test', 'site_url' => 'https://s.test/' ] ];
		RecordStore::get();
		$this->responder = static fn () => new WP_Error( 'down', 'down' );
		delete_transient( RecordStore::TRANSIENT );
		self::assertSame( 's.test', RecordStore::get()['site_host'] );
		$calls = count( $this->calls );
		RecordStore::get();
		self::assertCount( $calls, $this->calls, 'retries at most every minute' );

		$last               = get_option( RecordStore::LAST );
		$last['fetched_at'] = time() - RecordStore::STALE_LIMIT - 1;
		update_option( RecordStore::LAST, $last );
		delete_transient( RecordStore::TRANSIENT );
		self::assertNull( RecordStore::get() );
	}

	public function testARecordOfAnotherMerchantIsNotUsed(): void {
		$this->connect();
		$this->responder = static fn () => [ 200, [ 'merchant_id' => 'mrch_someone_else', 'network_mode' => 'test' ] ];
		self::assertNull( RecordStore::get() );
		set_transient( RecordStore::TRANSIENT, [ 'merchant_id' => 'mrch_someone_else', 'record' => [ 'merchant_id' => 'mrch_someone_else' ] ], 60 );
		self::assertNull( RecordStore::get() );
	}

	public function testKeysAreCachedAndARefetchHasACooldown(): void {
		$this->responder = static fn () => [ 200, [ 'keys' => [ [ 'kid' => 'k' ] ] ] ];
		$keys            = new WpKeySet();
		self::assertSame( 'k', $keys->keys()['keys'][0]['kid'] );
		$keys->keys();
		self::assertCount( 1, $this->calls );
		self::assertNotNull( $keys->refresh() );
		self::assertNull( $keys->refresh() );
		self::assertCount( 2, $this->calls );
	}

	public function testAnOversizedOrMissingKeySetIsRefused(): void {
		$this->responder = static fn () => [ 200, [ 'keys' => array_fill( 0, 17, [] ) ] ];
		$this->expectException( RuntimeException::class );
		( new WpKeySet() )->keys();
	}

	public function testMeterTakesOnlyWhatTheBatchCovers(): void {
		$m = new WpdbMeter();
		self::assertSame( 4, $m->spend( self::MERCHANT, 'r1', 4, 5, 9999 ) );
		self::assertNull( $m->spend( self::MERCHANT, 'r1', 4, 5, 9999 ), 'does not fit' );
		self::assertSame( 5, $m->spend( self::MERCHANT, 'r1', 1, 5, 9999 ), 'what remains is still spendable' );
		self::assertNull( $m->spend( self::MERCHANT, 'r1', 1, 5, 9999 ) );
		self::assertNull( $m->spend( self::MERCHANT, 'r2', 10, 5, 9999 ), 'first spend larger than the batch' );
		self::assertSame( 1, $m->spend( 'mrch_someone_else', 'r1', 1, 5, 9999 ), 'one receipt id per merchant' );
	}

	public function testMeterKeepsNoReceiptId(): void {
		global $wpdb;
		( new WpdbMeter() )->spend( self::MERCHANT, 'rcpt_plain', 1, 5, 9999 );
		$row = $wpdb->get_row( 'SELECT * FROM ' . Schema::units(), ARRAY_A );
		self::assertStringNotContainsString( 'rcpt_plain', (string) wp_json_encode( $row ) );
		self::assertSame( 64, strlen( $row['receipt_hash'] ) );
	}

	public function testExpiredCountersGoOnlyAfterTheReceiptDoes(): void {
		$m = new WpdbMeter();
		$m->spend( self::MERCHANT, 'old', 1, 5, 100 );
		$m->spend( self::MERCHANT, 'new', 1, 5, 300 );
		WpdbMeter::purgeExpired( 200 );
		self::assertNull( $m->spend( self::MERCHANT, 'new', 5, 5, 300 ), 'still counted' );
		self::assertSame( 5, $m->spend( self::MERCHANT, 'old', 5, 5, 100 ), 'row was removed' );
	}

	public function testAMissingTableFailsLoudly(): void {
		global $wpdb;
		$wpdb->suppress_errors( true );
		$wpdb->query( 'DROP TABLE ' . Schema::units() ); // phpcs:ignore
		try {
			( new WpdbMeter() )->spend( self::MERCHANT, 'r', 1, 5, 9999 );
			self::fail( 'no error' );
		} catch ( RuntimeException $e ) {
			self::assertSame( 'meter_unavailable', $e->getMessage() );
		} finally {
			$wpdb->suppress_errors( false );
			Schema::install();
		}
	}

	public function testTrafficReportSaysWhatWouldHaveBeenCharged(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			TrafficLog::record( '/*', 'openai', 'ai_crawler', 'shadow', 10 );
		}
		TrafficLog::record( '/x', 'openai', 'ai_crawler', 'shadow', 1 );
		TrafficLog::record( '/x', 'anthropic', 'ai_crawler', '402', 1 );
		TrafficLog::record( '/x', 'aifinpay', 'aifp_agent', 'paid', 4 );
		$rows = TrafficLog::report();
		self::assertSame( [ 'operator' => 'openai', 'ua_class' => 'ai_crawler', 'observed' => 4, 'challenged' => 0, 'paid' => 0, 'charged' => '0.00' ], $rows[0] );
		$by = array_column( $rows, null, 'operator' );
		self::assertSame( 1, $by['anthropic']['challenged'] );
		self::assertSame( '0.002', $by['aifinpay']['charged'] );
	}

	public function testOldTrafficIsRemoved(): void {
		TrafficLog::record( '/x', 'openai', 'ai_crawler', 'shadow', 1 );
		TrafficLog::purgeOld( time() + 40 * DAY_IN_SECONDS );
		self::assertSame( [], TrafficLog::report( 100 ) );
	}

	public function testHeadersOnFrontEndResponses(): void {
		$html = [ 'Content-Type' => 'text/html; charset=UTF-8' ];
		$this->settings( [ 'mode' => 'off' ] );
		self::assertSame( $html, FrontGate::filterHeaders( $html ) );

		$this->settings( [] );
		$h = FrontGate::filterHeaders( $html );
		self::assertSame( 'shadow', $h['AIFP-Mode'] );
		self::assertStringContainsString( '</llms.txt>', $h['Link'] );
		self::assertStringNotContainsString( 'x402', $h['Link'], 'nothing to describe before a merchant exists' );

		$this->connect();
		self::assertStringContainsString( '</.well-known/x402.json>', FrontGate::filterHeaders( $html )['Link'] );
		self::assertArrayNotHasKey( 'Link', FrontGate::filterHeaders( [ 'Content-Type' => 'application/rss+xml' ] ) );

		$this->settings( [ 'mode' => 'enforce' ] );
		self::assertArrayNotHasKey( 'AIFP-Mode', FrontGate::filterHeaders( $html ) );
		$this->settings( [ 'discovery_link' => false ] );
		self::assertArrayNotHasKey( 'Link', FrontGate::filterHeaders( $html ) );
	}

	public function testPurgeReachesEveryCache(): void {
		$seen   = [];
		$record = static function () use ( &$seen ): void {
			$seen[] = current_filter();
		};
		add_action( 'litespeed_purge_all', $record );
		add_action( CacheCompat::PURGE_ACTION, $record );
		CacheCompat::purge();
		remove_action( 'litespeed_purge_all', $record );
		remove_action( CacheCompat::PURGE_ACTION, $record );
		self::assertSame( [ 'litespeed_purge_all', CacheCompat::PURGE_ACTION ], $seen );
		self::assertContains( CacheCompat::PURGE_ACTION, CacheCompat::cloudflareActions( [ 'x' ] ) );
	}

	public function testPatternsAreRegisteredWithTheTier(): void {
		$this->connect();
		$this->responder = static fn () => [ 201, '{}' ];
		$s               = $this->settings( [ 'mode' => 'enforce', 'protected_paths' => [ '/paid' ], 'tier' => 'premium' ] );
		Resources::sync( $s );
		self::assertCount( 3, $this->calls );
		self::assertSame( self::SECRET, $this->calls[0]['headers']['AIFP-Merchant-Secret'] );
		self::assertStringEndsWith( '/v1/merchants/' . self::MERCHANT . '/resources', $this->calls[0]['url'] );
		self::assertSame( [ 'route_pattern' => '/paid', 'type' => 'page', 'tier' => 'premium', 'upsert' => true ], json_decode( $this->calls[0]['body'], true ) );
		self::assertSame( 'api', json_decode( $this->calls[1]['body'], true )['type'] );
		self::assertSame( [], Resources::failed() );
	}

	public function testNothingIsRegisteredWithoutAReasonToSendIt(): void {
		Resources::sync( $this->settings( [ 'report_events' => true ] ) );
		self::assertSame( [], $this->calls, 'not connected' );
		$this->connect();
		Resources::sync( $this->settings( [ 'report_events' => false ] ) );
		self::assertSame( [], $this->calls, 'shadow without reporting' );
	}

	public function testAFailedRegistrationIsRetried(): void {
		$this->connect();
		$s               = $this->settings( [ 'report_events' => true ] );
		$this->responder = static fn () => 503;
		Resources::sync( $s );
		self::assertSame( [ Paths::ALL ], Resources::failed() );
		$this->responder = static fn () => 200;
		$this->calls     = [];
		Resources::retryIfNeeded( $s );
		self::assertCount( 1, $this->calls );
		self::assertSame( [], Resources::failed() );
		$this->calls = [];
		Resources::retryIfNeeded( $s );
		self::assertSame( [], $this->calls, 'nothing to do' );
	}

	public function testRemovalLeavesNothing(): void {
		global $wpdb;
		$this->connect();
		$this->settings( [] );
		$this->responder = static fn () => [ 200, [ 'keys' => [] ] ];
		( new WpKeySet() )->keys();
		Maintenance::activate();
		self::assertNotFalse( wp_next_scheduled( Maintenance::CRON_EVENTS ) );
		Maintenance::removeSiteData();
		self::assertFalse( get_option( Connection::OPTION ) );
		self::assertFalse( get_option( Settings::OPTION ) );
		self::assertFalse( get_transient( WpKeySet::TRANSIENT ) );
		self::assertFalse( wp_next_scheduled( Maintenance::CRON_EVENTS ) );
		$wpdb->suppress_errors( true );
		self::assertNull( $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::units() ) );
		$wpdb->suppress_errors( false );
		Schema::install();
	}

	public function testUpgradeReinstallsWhenTheVersionDiffers(): void {
		update_option( Schema::OPTION, 'old' );
		Maintenance::maybeUpgrade();
		self::assertSame( Schema::VERSION, get_option( Schema::OPTION ) );
	}
}
