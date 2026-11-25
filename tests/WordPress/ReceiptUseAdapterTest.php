<?php

declare(strict_types=1);

use AiFinPay\Core\ReceiptUse;
use AiFinPay\Tests\TestCase;
use AiFinPay\WordPress\EventDelivery;
use AiFinPay\WordPress\EventQueue;
use AiFinPay\WordPress\UseDelivery;
use AiFinPay\WordPress\WpdbUseLog;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/TestCase.php';

final class ReceiptUseAdapterTest extends TestCase {

	private const RECEIPT = 'rcpt_00000000000000c4';

	protected function setUp(): void {
		parent::setUp();
		$this->connect();
	}

	private function uses(): array {
		return array_values( array_filter( $this->calls, static fn ( array $c ): bool => str_ends_with( $c['url'], '/receipt-use' ) ) );
	}

	private static function applied(): callable {
		return static fn ( string $method, string $url, ?string $body ) => [ 200, [ 'applied' => count( json_decode( (string) $body, true )['uses'] ), 'ignored' => 0 ] ];
	}

	public function testTheTableKeepsTheLowestAndARunSendsTheExactBodyWithAuth(): void {
		$log = new WpdbUseLog();
		$log->record( self::RECEIPT, 39 );
		$log->record( self::RECEIPT, 38 );
		$log->record( self::RECEIPT, 39 );
		self::assertSame( [ self::RECEIPT => 38 ], WpdbUseLog::held() );
		$this->responder = self::applied();
		UseDelivery::run();
		self::assertCount( 1, $this->uses() );
		$call = $this->uses()[0];
		self::assertSame( 'POST', $call['method'] );
		self::assertStringEndsWith( '/v1/merchants/' . self::MERCHANT . '/receipt-use', $call['url'] );
		self::assertSame( self::SECRET, $call['headers']['AIFP-Merchant-Secret'] );
		self::assertSame( 0, $call['args']['redirection'] );
		self::assertSame( '{"uses":[{"receipt_id":"' . self::RECEIPT . '","remaining":38}]}', $call['body'] );
		self::assertSame( [], WpdbUseLog::held() );
	}

	public static function vectors(): array {
		$vectors = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/receipt-use-v1.json' ), true, 16, JSON_THROW_ON_ERROR )['vectors'];
		return array_combine( array_column( $vectors, 'name' ), array_map( static fn ( array $v ): array => [ $v['spends'], $v['bodies'] ], $vectors ) );
	}

	#[DataProvider( 'vectors' )]
	public function testTheVectorBodiesThroughTheTable( array $spends, array $bodies ): void {
		$log = new WpdbUseLog();
		foreach ( $spends as $spend ) {
			if ( ReceiptUse::valid( $spend['receipt_id'], $spend['remaining'] ) ) {
				$log->record( $spend['receipt_id'], $spend['remaining'] );
			}
		}
		$this->responder = self::applied();
		UseDelivery::run();
		self::assertSame( $bodies, array_column( $this->uses(), 'body' ) );
	}

	public function testA404DropsWhatIsHeldAndLeavesGateEventsAlone(): void {
		$this->settings( [ 'report_events' => true ] );
		EventQueue::push( [ 'kind' => '402', 'operator' => 'openai', 'ua_class' => 'ai_crawler', 'verified' => false ], '/*', time() );
		( new WpdbUseLog() )->record( self::RECEIPT, 7 );
		$this->responder = static fn ( string $method, string $url ) => str_ends_with( $url, '/receipt-use' ) ? [ 404, [ 'error' => 'not_found' ] ] : [ 200, [ 'accepted' => 1 ] ];
		EventDelivery::run();
		UseDelivery::run();
		self::assertSame( [], WpdbUseLog::held() );
		self::assertCount( 1, $this->uses() );
		self::assertSame( 0, EventQueue::count() );
		self::assertSame( 200, EventDelivery::state()['last_status'] );
		UseDelivery::run();
		self::assertCount( 1, $this->uses() );
		( new WpdbUseLog() )->record( self::RECEIPT, 6 );
		UseDelivery::run();
		self::assertCount( 2, $this->uses() );
	}

	public function testUnreachableKeepsWhatIsHeldForFiveRuns(): void {
		( new WpdbUseLog() )->record( self::RECEIPT, 7 );
		$this->responder = static fn () => new WP_Error( 'down', 'down' );
		for ( $run = 1; $run < ReceiptUse::MAX_ATTEMPTS; $run++ ) {
			UseDelivery::run();
			self::assertSame( [ self::RECEIPT => 7 ], WpdbUseLog::held(), "run $run" );
		}
		UseDelivery::run();
		self::assertSame( [], WpdbUseLog::held() );
		self::assertCount( ReceiptUse::MAX_ATTEMPTS, $this->uses() );
	}

	public function testWithoutAConnectionNothingIsSent(): void {
		delete_option( \AiFinPay\WordPress\Connection::OPTION );
		( new WpdbUseLog() )->record( self::RECEIPT, 7 );
		$this->responder = self::applied();
		UseDelivery::run();
		self::assertSame( [], $this->uses() );
		self::assertSame( [ self::RECEIPT => 7 ], WpdbUseLog::held() );
	}
}
