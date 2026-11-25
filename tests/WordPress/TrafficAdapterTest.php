<?php

declare(strict_types=1);

use AiFinPay\Core\Request;
use AiFinPay\Core\Traffic;
use AiFinPay\Core\TrafficSender;
use AiFinPay\Tests\TestCase;
use AiFinPay\WordPress\Api;
use AiFinPay\WordPress\EventDelivery;
use AiFinPay\WordPress\EventQueue;
use AiFinPay\WordPress\Resources;
use AiFinPay\WordPress\Schema;
use AiFinPay\WordPress\Settings;
use AiFinPay\WordPress\TrafficCounter;
use AiFinPay\WordPress\TrafficDelivery;
use AiFinPay\WordPress\UseDelivery;
use AiFinPay\WordPress\WpdbUseLog;

require_once __DIR__ . '/TestCase.php';

final class TrafficAdapterTest extends TestCase {

	private const T       = 1800000000;
	private const GPTBOT  = 'Mozilla/5.0 (compatible; GPTBot/1.2; +https://openai.com/gptbot)';
	private const CHROME  = 'Mozilla/5.0 (Macintosh) AppleWebKit/537.36 Chrome/131.0.0.0 Safari/537.36';
	private const SERVER  = [ 'REQUEST_URI', 'HTTP_CF_IPCOUNTRY', 'HTTP_X_ASN', 'HTTP_AIFP_EDGE_COUNTED' ];
	private const RECEIPT = 'rcpt_00000000000000c4';

	protected function setUp(): void {
		parent::setUp();
		$this->connect();
		$this->responder = static fn ( string $method, string $url, ?string $body ) => str_ends_with( $url, '/receipt-use' )
			? [ 200, [ 'applied' => count( json_decode( (string) $body, true )['uses'] ), 'ignored' => 0 ] ]
			: [ 200, [ 'accepted' => true ] ];
	}

	protected function tearDown(): void {
		foreach ( self::SERVER as $key ) {
			unset( $_SERVER[ $key ] );
		}
		$_SERVER['REQUEST_URI'] = '/';
		parent::tearDown();
	}

	/** One request as the gate saw it, counted as on shutdown. */
	private function visit( string $target, string $ua, string $outcome, int $now, array $server = [], ?array $event = null ): void {
		foreach ( self::SERVER as $key ) {
			unset( $_SERVER[ $key ] );
		}
		$_SERVER = [ 'REQUEST_URI' => $target ] + $server + $_SERVER;
		TrafficCounter::note( new Request( 'GET', '127.0.0.1', '/', [ 'user-agent' => $ua ] ), $outcome, $event, Settings::get() );
		TrafficCounter::write( static function (): void {}, static function (): void {}, $now );
	}

	private function traffic(): array {
		return array_values( array_filter( $this->calls, static fn ( array $c ): bool => str_ends_with( $c['url'], '/gate-traffic' ) ) );
	}

	private static function rows(): array {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . Schema::trafficWindows() . ' ORDER BY window_from, tbl, k1, k2, k3', ARRAY_A ); // phpcs:ignore
	}

	public function testEveryKindOfMerchantSecretRequestSaysWhichGateSendsIt(): void {
		$settings = $this->settings( [ 'mode' => 'enforce', 'free_readers' => 'everyone', 'report_events' => true, 'protected_paths' => [ '/paid/*' ] ] );
		Resources::sync( $settings );
		EventQueue::push( [ 'kind' => '402', 'operator' => 'openai', 'ua_class' => 'ai_crawler', 'verified' => false ], '/paid/*', time() );
		EventDelivery::run();
		( new WpdbUseLog() )->record( self::RECEIPT, 3 );
		UseDelivery::run();
		$this->visit( '/paid/a', self::GPTBOT, 'challenged', self::T );
		TrafficDelivery::run( self::T + 320 );
		$kinds = array_unique( array_map( static fn ( array $c ): string => (string) preg_replace( '~^.*/~', '', $c['url'] ), $this->calls ) );
		sort( $kinds );
		self::assertSame( [ 'gate-events', 'gate-traffic', 'receipt-use', 'resources' ], array_values( $kinds ) );
		foreach ( $this->calls as $call ) {
			self::assertSame( self::SECRET, $call['headers']['AIFP-Merchant-Secret'] );
			self::assertSame( 'pkg=wordpress; ver=test; platform=wordpress; mode=enforce; charge=everyone', $call['headers']['AIFP-Gate'] ?? null, $call['url'] );
			self::assertSame( 'aifinpay-gate-wordpress/test', $call['args']['user-agent'], $call['url'] );
		}
	}

	public function testModeAndChargeAreTheSettingsWhenTheRequestIsMade(): void {
		foreach ( [
			[ [ 'mode' => 'shadow', 'free_readers' => 'named' ], 'mode=shadow; charge=agents' ],
			[ [ 'mode' => 'off', 'free_readers' => 'named' ], 'mode=shadow; charge=agents' ],
			[ [ 'mode' => 'enforce', 'free_readers' => 'proven' ], 'mode=enforce; charge=everyone' ],
		] as [ $patch, $tail ] ) {
			$this->settings( $patch );
			$this->calls = [];
			Api::request( 'POST', '/v1/merchants/' . self::MERCHANT . '/gate-events', [ 'AIFP-Merchant-Secret' => self::SECRET ] );
			self::assertStringEndsWith( $tail, $this->calls[0]['headers']['AIFP-Gate'] );
		}
	}

	public function testARequestWithoutTheSecretCarriesNoGateHeaderAndANotConnectedSiteSendsNothing(): void {
		Api::request( 'POST', '/v1/merchants/connect', [ 'Content-Type' => 'application/json' ], '{}' );
		self::assertArrayNotHasKey( 'AIFP-Gate', $this->calls[0]['headers'] );
		delete_option( \AiFinPay\WordPress\Connection::OPTION );
		$this->calls = [];
		$this->settings( [ 'report_events' => true ] );
		EventQueue::push( [ 'kind' => '402', 'operator' => 'openai', 'ua_class' => 'ai_crawler', 'verified' => false ], '/*', time() );
		EventDelivery::run();
		UseDelivery::run();
		$this->visit( '/blog/a', self::GPTBOT, 'challenged', self::T );
		TrafficDelivery::run( self::T + 320 );
		self::assertSame( [], $this->calls );
		self::assertSame( [], self::rows() );
	}

	public function testAWindowIsCountedInTheTableAndSentAsTheVectorsBuildIt(): void {
		$this->settings( [ 'network_header' => 'X-ASN' ] );
		$this->visit( '/blog/a', self::CHROME, 'served', self::T, [ 'HTTP_CF_IPCOUNTRY' => 'us', 'HTTP_X_ASN' => '3320' ] );
		$this->visit( '/blog/a?x=/secret', self::GPTBOT, 'challenged', self::T + 10, [ 'HTTP_CF_IPCOUNTRY' => 'US', 'HTTP_X_ASN' => '8075' ] );
		$this->visit( '/de/docs/x', self::GPTBOT, 'challenged', self::T + 20, [ 'HTTP_CF_IPCOUNTRY' => 'DE', 'HTTP_X_ASN' => 'AS8075' ] );
		$this->visit( '/blog/b', self::GPTBOT, 'paid', self::T + 30, [], [ 'kind' => 'serve', 'operator' => 'aifinpay', 'ua_class' => 'aifp_agent', 'verified' => true ] );
		TrafficDelivery::run( self::T + 300 );
		self::assertSame( [], $this->traffic(), 'not before its five minutes are over' );
		TrafficDelivery::run( self::T + 320 );
		self::assertCount( 1, $this->traffic() );
		$call = $this->traffic()[0];
		self::assertStringEndsWith( '/v1/merchants/' . self::MERCHANT . '/gate-traffic', $call['url'] );
		self::assertSame( 'POST', $call['method'] );
		self::assertSame( 5.0, $call['args']['timeout'] );
		self::assertSame( 0, $call['args']['redirection'] );
		$id = json_decode( $call['body'], true )['id'];
		self::assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id );
		self::assertSame(
			'{"v":1,"id":"' . $id . '","from":"2027-01-15T08:00:00Z","to":"2027-01-15T08:05:00Z","mode":"shadow","charge":"agents","sample":1,'
			. '"classes":[["ai_crawler","challenged",2],["aifp_agent","paid",1],["human","served",1]],'
			. '"operators":[["aifinpay","aifp_agent","paid",1],["openai","ai_crawler","challenged",2]],'
			. '"sections":[["/blog","ai_crawler",1],["/blog","aifp_agent",1],["/docs","ai_crawler",1]],'
			. '"countries":[["DE","ai_crawler",1],["US","ai_crawler",1],["US","human",1],["ZZ","aifp_agent",1]],'
			. '"networks":[["AS0","aifp_agent",1],["AS8075","ai_crawler",2]]}',
			$call['body']
		);
		self::assertStringNotContainsString( 'secret', $call['body'] );
		self::assertSame( [], self::rows(), 'a closed window leaves the table' );
		self::assertSame( [], TrafficDelivery::state()['held'] );
	}

	public function testAReaderIsNeverStoredWithANetwork(): void {
		$this->settings( [ 'network_header' => 'X-ASN' ] );
		$this->visit( '/blog/a', self::CHROME, 'served', self::T, [ 'HTTP_X_ASN' => '3320' ] );
		self::assertSame( [ 'classes', 'countries' ], array_column( self::rows(), 'tbl' ) );
	}

	public function testAnAssetServedFreeIsNotCountedAndACountryHeaderSetToNothingSendsNoCountries(): void {
		$this->settings( [ 'country_header' => '' ] );
		$this->visit( '/wp-content/uploads/a.PNG?v=1', self::GPTBOT, 'served', self::T );
		self::assertSame( [], self::rows() );
		$this->visit( '/blog/a', self::GPTBOT, 'served', self::T, [ 'HTTP_CF_IPCOUNTRY' => 'US' ] );
		self::assertSame( [ 'classes', 'operators', 'sections' ], array_column( self::rows(), 'tbl' ) );
	}

	public function testARequestAnEdgeGateCountedIsNotCountedAgain(): void {
		$this->visit( '/blog/a', self::GPTBOT, 'challenged', self::T, [ 'HTTP_AIFP_EDGE_COUNTED' => " 1\t" ] );
		self::assertSame( [], self::rows() );
		$this->visit( '/blog/a', self::GPTBOT, 'challenged', self::T, [ 'HTTP_AIFP_EDGE_COUNTED' => '1, 1' ] );
		$this->visit( '/blog/a', self::GPTBOT, 'challenged', self::T, [ 'HTTP_AIFP_EDGE_COUNTED' => '0' ] );
		self::assertSame( '2', self::rows()[0]['n'] ?? null );
	}

	public function testRequestsJoinTheOpenWindowAndTheNextOpensAtTheFirstRequestAfterIt(): void {
		$this->visit( '/a', self::GPTBOT, 'challenged', self::T );
		$this->visit( '/a', self::GPTBOT, 'challenged', self::T + 299 );
		$this->visit( '/a', self::GPTBOT, 'challenged', self::T + 450 );
		self::assertSame( [ (string) self::T, (string) ( self::T + 450 ) ], array_values( array_unique( array_column( self::rows(), 'window_from' ) ) ) );
		TrafficDelivery::run( self::T + 800 );
		$bodies = array_map( static fn ( array $c ): array => json_decode( $c['body'], true ), $this->traffic() );
		self::assertSame( [ [ [ 'ai_crawler', 'challenged', 2 ] ], [ [ 'ai_crawler', 'challenged', 1 ] ] ], array_column( $bodies, 'classes' ) );
		self::assertSame( [ '2027-01-15T08:07:30Z', '2027-01-15T08:12:30Z' ], [ $bodies[1]['from'], $bodies[1]['to'] ] );
	}

	public function testAChangedModeOrChargeStartsItsOwnWindow(): void {
		$this->visit( '/a', self::GPTBOT, 'challenged', self::T );
		$this->settings( [ 'free_readers' => 'everyone' ] );
		$this->visit( '/a', self::GPTBOT, 'challenged', self::T + 5 );
		TrafficDelivery::run( self::T + 400 );
		self::assertSame( [ 'agents', 'everyone' ], array_map( static fn ( array $c ): string => json_decode( $c['body'], true )['charge'], $this->traffic() ) );
	}

	public function testRandomPathsCannotGrowAWindowWithoutBound(): void {
		global $wpdb;
		$values = [];
		for ( $i = 0; $i < Traffic::MAX_DISTINCT; $i++ ) {
			$values[] = $wpdb->prepare( '(%d, %s, %s, %s, %s, %s, %s, 1)', self::T, 'shadow', 'agents', 'sections', '/s' . $i, 'ai_crawler', '' );
		}
		$wpdb->query( 'INSERT INTO ' . Schema::trafficWindows() . ' (window_from, mode, charge, tbl, k1, k2, k3, n) VALUES ' . implode( ', ', $values ) ); // phpcs:ignore
		$this->visit( '/brand-new/page', self::GPTBOT, 'challenged', self::T + 1 );
		$this->visit( '/s7/page', self::GPTBOT, 'challenged', self::T + 2 );
		$this->visit( '/brand-new-too', self::CHROME, 'served', self::T + 3 );
		$sections = array_column( array_filter( self::rows(), static fn ( array $r ): bool => 'sections' === $r['tbl'] ), 'n', 'k1' );
		self::assertCount( Traffic::MAX_DISTINCT + 1, $sections );
		self::assertSame( '1', $sections['_other'] );
		self::assertSame( '2', $sections['/s7'] );
		self::assertArrayNotHasKey( '/brand-new', $sections );
	}

	public function testTurnedOffItCountsNothingAndSendsNothing(): void {
		$this->visit( '/a', self::GPTBOT, 'challenged', self::T );
		update_option( TrafficDelivery::STATE, [ 'held' => [ [ 'id' => 'w0', 'to' => self::T, 'body' => '{}' ] ], 'paused_until' => null ], false );
		$this->settings( [ 'report_traffic' => false ] );
		$this->visit( '/b', self::GPTBOT, 'challenged', self::T + 1 );
		TrafficDelivery::run( self::T + 400 );
		self::assertSame( [], $this->traffic() );
		self::assertSame( [], self::rows() );
		self::assertFalse( get_option( TrafficDelivery::STATE ) );
	}

	public function testARetrySendsTheSameIdAndBytesAndWindowsAreNeverMerged(): void {
		$this->responder = static fn () => 503;
		$this->visit( '/a', self::GPTBOT, 'challenged', self::T );
		TrafficDelivery::run( self::T + 320 );
		$this->visit( '/a', self::GPTBOT, 'challenged', self::T + 330 );
		$this->responder = static fn () => [ 200, [ 'accepted' => true ] ];
		TrafficDelivery::run( self::T + 640 );
		$bodies = array_column( $this->traffic(), 'body' );
		self::assertCount( 3, $bodies );
		self::assertSame( $bodies[0], $bodies[1] );
		self::assertNotSame( json_decode( $bodies[1], true )['id'], json_decode( $bodies[2], true )['id'] );
		self::assertSame( [ [ 'ai_crawler', 'challenged', 1 ] ], json_decode( $bodies[2], true )['classes'] );
		self::assertSame( [], TrafficDelivery::state()['held'] );
	}

	public function testA404PausesTrafficAndLeavesGateEventsAndReceiptUseDelivering(): void {
		$this->settings( [ 'report_events' => true ] );
		$this->responder = static fn ( string $method, string $url, ?string $body ) => match ( true ) {
			str_ends_with( $url, '/gate-traffic' ) => [ 404, [ 'error' => 'not_found' ] ],
			str_ends_with( $url, '/receipt-use' )  => [ 200, [ 'applied' => count( json_decode( (string) $body, true )['uses'] ), 'ignored' => 0 ] ],
			default                                => [ 200, [ 'accepted' => 1 ] ],
		};
		$this->visit( '/a', self::GPTBOT, 'challenged', self::T );
		EventQueue::push( [ 'kind' => '402', 'operator' => 'openai', 'ua_class' => 'ai_crawler', 'verified' => false ], '/*', time() );
		( new WpdbUseLog() )->record( self::RECEIPT, 3 );
		TrafficDelivery::run( self::T + 320 );
		self::assertSame( self::T + 320 + TrafficSender::PAUSE_404, TrafficDelivery::state()['paused_until'] );
		EventDelivery::run();
		UseDelivery::run();
		self::assertSame( 0, EventQueue::count() );
		self::assertSame( 200, EventDelivery::state()['last_status'] );
		self::assertSame( [], WpdbUseLog::held() );
		$this->visit( '/a', self::GPTBOT, 'challenged', self::T + 330 );
		TrafficDelivery::run( self::T + 640 );
		self::assertCount( 1, $this->traffic(), 'nothing is sent in the pause' );
		self::assertSame( [], TrafficDelivery::state()['held'] );
		$this->visit( '/a', self::GPTBOT, 'challenged', self::T + 320 + TrafficSender::PAUSE_404 );
		$this->responder = static fn () => [ 200, [ 'accepted' => true ] ];
		TrafficDelivery::run( self::T + 320 + TrafficSender::PAUSE_404 + 320 );
		self::assertCount( 2, $this->traffic(), 'the first window after the pause is sent' );
	}

	public function testAFailingTableNeverReachesTheVisitor(): void {
		global $wpdb;
		$wpdb->suppress_errors( true );
		$wpdb->query( 'DROP TABLE ' . Schema::trafficWindows() ); // phpcs:ignore
		try {
			$this->visit( '/a', self::GPTBOT, 'challenged', self::T );
			TrafficDelivery::run( self::T + 320 );
			self::assertSame( [], $this->traffic() );
		} finally {
			Schema::install();
			$wpdb->suppress_errors( false );
		}
	}

	public function testCronOverdueSendsFromTheShutdownOfARequest(): void {
		$this->visit( '/a', self::GPTBOT, 'challenged', self::T );
		$this->visit( '/a', self::GPTBOT, 'challenged', self::T + 300 + TrafficCounter::CRON_OVERDUE );
		self::assertCount( 1, $this->traffic() );
		$spawned = false;
		$_SERVER['REQUEST_URI'] = '/b';
		TrafficCounter::note( new Request( 'GET', '127.0.0.1', '/', [ 'user-agent' => self::GPTBOT ] ), 'challenged', null, Settings::get() );
		TrafficCounter::write( null, static function () use ( &$spawned ): void {
			$spawned = true;
		}, self::T + 2000 );
		self::assertTrue( $spawned || function_exists( 'fastcgi_finish_request' ) );
	}
}
