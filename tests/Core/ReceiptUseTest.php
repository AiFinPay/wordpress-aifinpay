<?php

declare(strict_types=1);

use AiFinPay\Core\KeySet;
use AiFinPay\Core\MerchantRecord;
use AiFinPay\Core\Meter;
use AiFinPay\Core\Receipt;
use AiFinPay\Core\ReceiptGate;
use AiFinPay\Core\ReceiptUse;
use AiFinPay\Core\Request;
use AiFinPay\Core\UseLog;
use AiFinPay\Tests\KeyPair;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/KeyPair.php';

/** The lowest remaining per receipt, in memory; WpdbUseLog keeps the same in its table. */
final class MemoryUseLog implements UseLog {
	public array $held  = [];
	public bool $broken = false;
	public function record( string $receiptId, int $remaining ): void {
		if ( $this->broken ) {
			throw new RuntimeException( 'table missing' );
		}
		$this->held[ $receiptId ] = min( $remaining, $this->held[ $receiptId ] ?? PHP_INT_MAX );
	}
}

final class UseTestKeys implements KeySet {
	public function __construct( private array $jwks ) {}
	public function keys(): array {
		return $this->jwks;
	}
	public function refresh(): ?array {
		return null;
	}
}

final class UseTestMeter implements Meter {
	public array $used = [];
	public function spend( string $merchant, string $receiptId, int $weight, int $limit, int $expires ): ?int {
		$now = ( $this->used[ $receiptId ] ?? 0 ) + $weight;
		return $now > $limit ? null : $this->used[ $receiptId ] = $now;
	}
}

final class ReceiptUseTest extends TestCase {

	private const MERCHANT = 'mrch_wp_gate_test';
	private const HOST     = 'site.test';
	private const AGENT    = '0x2714ca06600db9c5167eafa37216716a8adc83fe';
	private const RECEIPT  = 'rcpt_00000000000000c4';
	private const NOW      = 1500;

	private KeyPair $key;
	private MemoryUseLog $uses;
	private ReceiptGate $gate;

	protected function setUp(): void {
		$this->key  = new KeyPair();
		$this->uses = new MemoryUseLog();
		$this->gate = new ReceiptGate( new UseTestKeys( $this->key->jwks ), new UseTestMeter(), 'https://api.aifinpay.io', uses: $this->uses );
	}

	private function token(): string {
		return $this->key->sign(
			[
				'iss'          => Receipt::ISSUER,
				'aud'          => self::MERCHANT,
				'sub'          => self::AGENT,
				'payer'        => self::AGENT,
				'iat'          => 1000,
				'exp'          => 2000,
				'resource'     => '/paid/*',
				'scope'        => 'exact',
				'network_mode' => 'test',
				'receipt_id'   => self::RECEIPT,
				'unit_quota'   => 40,
			]
		);
	}

	private function read( string $token, bool $spend = true ): array {
		$request  = new Request( 'GET', self::HOST, '/paid/a', [ 'aifp-receipt' => $token, 'aifp-agent-id' => self::AGENT, 'user-agent' => 'aifinpay-agent-node/2' ], false );
		$charging = MerchantRecord::charging( self::MERCHANT, [ 'merchant_id' => self::MERCHANT, 'network_mode' => 'test', 'site_host' => self::HOST, 'site_verified' => false ] );
		return $spend
			? $this->gate->enforce( $request, self::MERCHANT, $charging, '/paid/*', '/paid/a', 'standard', self::NOW )
			: $this->gate->shadow( $request, self::MERCHANT, 'test', '/paid/*', '/paid/a', 'standard', self::NOW );
	}

	public function testAPaidReadRecordsOneUseWithTheRemaining(): void {
		$token = $this->token();
		$read  = $this->read( $token );
		self::assertSame( 200, $read['status'] );
		self::assertSame( [ self::RECEIPT => 39 ], $this->uses->held );
		self::assertSame( [ '{"uses":[{"receipt_id":"' . self::RECEIPT . '","remaining":39}]}' ], ReceiptUse::bodies( $this->uses->held ) );
		self::assertStringNotContainsString( $token, ReceiptUse::bodies( $this->uses->held )[0] );
	}

	public function testTwoReadsOfOneReceiptLeaveOneItemWithTheLowestRemaining(): void {
		$token = $this->token();
		$this->read( $token );
		$this->read( $token );
		self::assertSame( [ '{"uses":[{"receipt_id":"' . self::RECEIPT . '","remaining":38}]}' ], ReceiptUse::bodies( $this->uses->held ) );
	}

	public static function vectors(): array {
		$vectors = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/receipt-use-v1.json' ), true, 16, JSON_THROW_ON_ERROR )['vectors'];
		return array_combine( array_column( $vectors, 'name' ), array_map( static fn ( array $v ): array => [ $v['spends'], $v['bodies'] ], $vectors ) );
	}

	#[DataProvider( 'vectors' )]
	public function testTheVectorBodiesByteForByte( array $spends, array $bodies ): void {
		$log = new MemoryUseLog();
		foreach ( $spends as $spend ) {
			if ( ReceiptUse::valid( $spend['receipt_id'], $spend['remaining'] ) ) {
				$log->record( $spend['receipt_id'], $spend['remaining'] );
			}
		}
		self::assertSame( $bodies, ReceiptUse::bodies( $log->held ) );
	}

	public function testTheVectorsAreTheSharedFileUnchanged(): void {
		self::assertSame( '1742c2cb19fefe6cf0fa6f972d6ae2822b01456445283324dc23096cbd569dbe', hash_file( 'sha256', dirname( __DIR__ ) . '/fixtures/receipt-use-v1.json' ) );
		self::assertCount( 5, self::vectors() );
	}

	public static function answers(): array {
		$ok = '{"applied":1,"ignored":0}';
		return [
			'applied'               => [ 200, $ok, ReceiptUse::SENT ],
			'ignored counts'        => [ 200, '{"applied":0,"ignored":1}', ReceiptUse::SENT ],
			'404 from an older API' => [ 404, '{"error":"not_found"}', ReceiptUse::DROP ],
			'400'                   => [ 400, '', ReceiptUse::DROP ],
			'401'                   => [ 401, '', ReceiptUse::DROP ],
			'403'                   => [ 403, '', ReceiptUse::DROP ],
			'redirect'              => [ 302, '', ReceiptUse::DROP ],
			'not reached'           => [ null, '', ReceiptUse::KEEP ],
			'408'                   => [ 408, '', ReceiptUse::KEEP ],
			'429'                   => [ 429, '', ReceiptUse::KEEP ],
			'500'                   => [ 500, '', ReceiptUse::KEEP ],
			'503'                   => [ 503, '', ReceiptUse::KEEP ],
			'wrong count'           => [ 200, '{"applied":2,"ignored":0}', ReceiptUse::KEEP ],
			'not json'              => [ 200, 'ok', ReceiptUse::KEEP ],
		];
	}

	#[DataProvider( 'answers' )]
	public function testWhatAnAnswerMeans( ?int $status, string $answer, string $outcome ): void {
		self::assertSame( $outcome, ReceiptUse::outcome( $status, $answer, 1 ) );
	}

	public function testABrokenUseLogNeverReachesThePaidRead(): void {
		$this->uses->broken = true;
		$read               = $this->read( $this->token() );
		self::assertSame( 200, $read['status'] );
		self::assertSame( '39', $read['headers']['AIFP-Quota-Remaining'] );
	}

	public function testShadowAndTokenShapedIdsRecordNothing(): void {
		$token = $this->token();
		self::assertSame( 200, $this->read( $token, false )['status'] );
		self::assertSame( [], $this->uses->held );
		self::assertFalse( ReceiptUse::valid( $token, 3 ) );
		self::assertFalse( ReceiptUse::valid( self::RECEIPT, -1 ) );
	}
}
