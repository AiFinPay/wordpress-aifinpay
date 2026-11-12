<?php

declare(strict_types=1);

use AiFinPay\Core\KeySet;
use AiFinPay\Core\Meter;
use AiFinPay\Core\MerchantRecord;
use AiFinPay\Core\Receipt;
use AiFinPay\Core\ReceiptGate;
use AiFinPay\Core\Request;
use AiFinPay\Tests\KeyPair;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/KeyPair.php';

final class FakeKeys implements KeySet {
	public int $reads     = 0;
	public int $refreshes = 0;
	public ?array $fresh  = null;
	public bool $down     = false;
	public function __construct( private array $jwks ) {}
	public function keys(): array {
		$this->reads++;
		if ( $this->down ) {
			throw new RuntimeException( 'down' );
		}
		return $this->jwks;
	}
	public function refresh(): ?array {
		$this->refreshes++;
		return $this->fresh;
	}
}

final class FakeMeter implements Meter {
	public array $used = [];
	public bool $down  = false;
	public function spend( string $merchant, string $receiptId, int $weight, int $limit, int $expires ): ?int {
		if ( $this->down ) {
			throw new RuntimeException( 'down' );
		}
		$now = ( $this->used[ $receiptId ] ?? 0 ) + $weight;
		if ( $now > $limit ) {
			return null;
		}
		return $this->used[ $receiptId ] = $now;
	}
}

final class GateDecisionTest extends TestCase {

	private const MERCHANT = 'mrch_wp_gate_test';
	private const HOST     = 'site.test';
	private const AGENT    = '0x2714ca06600db9c5167eafa37216716a8adc83fe';
	private const GPTBOT   = 'Mozilla/5.0 AppleWebKit/537.36; compatible; GPTBot/1.2; +https://openai.com/gptbot';
	private const NOW      = 1500;

	private KeyPair $key;
	private FakeKeys $keys;
	private FakeMeter $meter;
	private ReceiptGate $gate;

	protected function setUp(): void {
		$this->key   = new KeyPair();
		$this->keys  = new FakeKeys( $this->key->jwks );
		$this->meter = new FakeMeter();
		$this->gate  = new ReceiptGate( $this->keys, $this->meter, 'https://api.aifinpay.io' );
	}

	private static function charging( array $patch = [] ): array {
		return MerchantRecord::charging(
			self::MERCHANT,
			$patch + [ 'merchant_id' => self::MERCHANT, 'network_mode' => 'test', 'site_host' => self::HOST, 'site_verified' => false ]
		);
	}

	private function token( array $patch = [] ): string {
		return $this->key->sign(
			$patch + [
				'iss'          => Receipt::ISSUER,
				'aud'          => self::MERCHANT,
				'sub'          => self::AGENT,
				'payer'        => self::AGENT,
				'iat'          => 1000,
				'exp'          => 2000,
				'resource'     => '/paid/*',
				'scope'        => 'exact',
				'network_mode' => 'test',
				'receipt_id'   => 'rcpt_1234567890abcdef',
				'unit_quota'   => 3,
			]
		);
	}

	private static function request( array $headers = [], bool $logged_in = false, string $host = self::HOST, string $path = '/paid/a' ): Request {
		return new Request( 'GET', $host, $path, $headers, $logged_in );
	}

	private function enforce( Request $request, ?array $charging = null, string $tier = 'standard' ): array {
		return $this->gate->enforce( $request, self::MERCHANT, $charging ?? self::charging(), '/paid/*', $request->path, $tier, self::NOW );
	}

	private function paying( array $patch = [] ): Request {
		return self::request( [ 'aifp-receipt' => $this->token( $patch ), 'aifp-agent-id' => self::AGENT, 'user-agent' => 'aifinpay-agent-node/2' ] );
	}

	public static function browsers(): array {
		return [
			'Chrome'    => [ 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36' ],
			'Safari'    => [ 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15' ],
			'Firefox'   => [ 'Mozilla/5.0 (X11; Linux x86_64; rv:133.0) Gecko/20100101 Firefox/133.0' ],
			'Edge'      => [ 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0' ],
			'iPhone'    => [ 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1' ],
			'Android'   => [ 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36' ],
			'Googlebot' => [ 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' ],
		];
	}

	#[DataProvider( 'browsers' )]
	public function testBrowsersAreNeverCharged( string $ua ): void {
		$bad = MerchantRecord::charging( '', null );
		foreach ( [ self::charging(), $bad ] as $charging ) {
			$d = $this->enforce( self::request( [ 'user-agent' => $ua ] ), $charging );
			self::assertSame( 200, $d['status'] );
			self::assertSame( 'reader', $d['outcome'] );
			self::assertSame( 'exempt', $d['headers']['AIFP-Paywall'] );
			self::assertNull( $d['event'] );
		}
		self::assertSame( [], $this->meter->used );
		self::assertSame( 0, $this->keys->reads );
	}

	public function testALoggedInUserIsNeverCharged(): void {
		$d = $this->enforce( self::request( [ 'user-agent' => self::GPTBOT ], true ) );
		self::assertSame( 200, $d['status'] );
		self::assertSame( 'reader', $d['outcome'] );
	}

	public function testLoggingInDoesNotExemptAPayingAgent(): void {
		$d = $this->enforce( self::request( [ 'aifp-agent-id' => self::AGENT, 'user-agent' => 'Mozilla/5.0 Chrome/131' ], true ) );
		self::assertSame( 402, $d['status'] );
	}

	public function testABrowserUserAgentDoesNotExemptAPayingAgent(): void {
		$d = $this->enforce( self::request( [ 'aifp-agent-id' => self::AGENT, 'user-agent' => 'Mozilla/5.0 Chrome/131' ] ) );
		self::assertSame( 402, $d['status'] );
		self::assertSame( 'aifp_agent', $d['event']['ua_class'] );
	}

	public function testAnAgentWithoutAReceiptGetsTheGate402(): void {
		$d = $this->enforce( self::request( [ 'user-agent' => self::GPTBOT ] ), null, 'premium' );
		self::assertSame( 402, $d['status'] );
		self::assertSame( ReceiptGate::challenge( self::MERCHANT, '/paid/*', 'premium', 'https://api.aifinpay.io' ), $d['body'] );
		self::assertSame( [ 'kind' => '402', 'operator' => 'openai', 'ua_class' => 'ai_crawler', 'verified' => false ], $d['event'] );
		self::assertSame( 10, $d['units'] );
	}

	public function testNoUserAgentIsChargedAsTheGateDoes(): void {
		self::assertSame( 402, $this->enforce( self::request() )['status'] );
	}

	public static function notApproved(): array {
		return [
			'live, domain unverified' => [ [ 'network_mode' => 'live', 'site_verified' => false ] ],
			'no site url'             => [ [ 'site_host' => null ] ],
		];
	}

	#[DataProvider( 'notApproved' )]
	public function testAnAgentOnAnUnapprovedSiteIsRefusedNotServed( array $patch ): void {
		$d = $this->enforce( $this->paying(), self::charging( $patch ) );
		self::assertSame( 503, $d['status'] );
		self::assertSame( 'enforcement_not_approved', $d['body']['error'] );
		self::assertSame( [], $this->meter->used );
	}

	public function testAnUnreadableRecordRefusesTheAgent(): void {
		$d = $this->enforce( $this->paying(), MerchantRecord::charging( self::MERCHANT, null ) );
		self::assertSame( 503, $d['status'] );
	}

	public function testAnotherHostIsRefused(): void {
		$r = self::request( [ 'aifp-receipt' => $this->token(), 'aifp-agent-id' => self::AGENT ], false, 'www.site.test' );
		$d = $this->enforce( $r );
		self::assertSame( 503, $d['status'] );
		self::assertSame( [], $this->meter->used );
	}

	public function testALiveVerifiedSiteCharges(): void {
		$d = $this->enforce( $this->paying( [ 'network_mode' => 'live' ] ), self::charging( [ 'network_mode' => 'live', 'site_verified' => true ] ) );
		self::assertSame( 200, $d['status'] );
	}

	public function testAValidReceiptIsServedAndMetered(): void {
		$d = $this->enforce( $this->paying() );
		self::assertSame( 200, $d['status'] );
		self::assertSame( 'paid', $d['outcome'] );
		self::assertSame( '2', $d['headers']['AIFP-Quota-Remaining'] );
		self::assertSame( 'rcpt_1234567890abcdef', $d['headers']['AIFP-Receipt-Id'] );
		self::assertSame( [ 'kind' => 'serve', 'operator' => 'aifinpay', 'ua_class' => 'aifp_agent', 'verified' => true ], $d['event'] );
		self::assertSame( 1, $this->meter->used['rcpt_1234567890abcdef'] );
	}

	public function testAnExhaustedBatchAsksForTheNextOne(): void {
		foreach ( [ 2, 1, 0 ] as $left ) {
			self::assertSame( (string) $left, $this->enforce( $this->paying() )['headers']['AIFP-Quota-Remaining'] );
		}
		$d = $this->enforce( $this->paying() );
		self::assertSame( 402, $d['status'] );
		self::assertSame( ReceiptGate::DETAIL_QUOTA_EXHAUSTED, $d['body']['detail'] );
		self::assertTrue( $d['event']['verified'] );
	}

	public function testATierMetersItsWeight(): void {
		$d = $this->enforce( $this->paying( [ 'unit_quota' => 25 ] ), null, 'premium' );
		self::assertSame( '15', $d['headers']['AIFP-Quota-Remaining'] );
		self::assertSame( 10, $this->meter->used['rcpt_1234567890abcdef'] );
	}

	public function testAnExpiredReceiptAsksForANewOne(): void {
		$d = $this->enforce( $this->paying( [ 'exp' => 1400 ] ) );
		self::assertSame( 402, $d['status'] );
		self::assertSame( ReceiptGate::DETAIL_RECEIPT_EXPIRED, $d['body']['detail'] );
	}

	public static function forbidden(): array {
		return [
			'another merchant' => [ [ 'aud' => 'mrch_another_one' ], 'verification failed' ],
			'action receipt'   => [ [ 'typ_aifp' => 'action' ], 'not spendable' ],
			'no receipt id'    => [ [ 'receipt_id' => '' ], 'no receipt_id' ],
			'other path'       => [ [ 'resource' => '/other' ], 'scoped to /other' ],
			'other payer'      => [ [ 'payer' => '0x0000000000000000000000000000000000000001' ], 'does not match' ],
			'no units'         => [ [ 'unit_quota' => 0 ], 'no spendable units' ],
			'testnet on live'  => [ [ 'network_mode' => 'other' ], 'another network' ],
		];
	}

	#[DataProvider( 'forbidden' )]
	public function testAReceiptThatCannotBeSpentHereIsRefused( array $patch, string $detail ): void {
		$d = $this->enforce( $this->paying( $patch ) );
		self::assertSame( 403, $d['status'] );
		self::assertStringContainsString( $detail, $d['body']['detail'] );
		self::assertSame( [], $this->meter->used );
	}

	public function testAReceiptWithNoAgentIdNamesTheHeaderAndSpendsNothing(): void {
		$d = $this->enforce( self::request( [ 'aifp-receipt' => $this->token(), 'user-agent' => 'aifinpay-agent-node/2' ] ) );
		self::assertSame( 403, $d['status'] );
		self::assertSame( 'this receipt is bound to the address that paid: retry with AIFP-Agent-Id set to it. Nothing was spent.', $d['body']['detail'] );
		self::assertSame( [], $this->meter->used );
	}

	public function testALiveReceiptDoesNotOpenATestSite(): void {
		self::assertSame( 403, $this->enforce( $this->paying( [ 'network_mode' => 'live' ] ) )['status'] );
		self::assertSame( 403, $this->enforce( $this->paying( [ 'network_mode' => null ] ) )['status'], 'no claim counts as live' );
		self::assertSame( [], $this->meter->used );
	}

	public function testAQuotaThatIsNotAWholeNumberIsRefused(): void {
		foreach ( [ 'abc', '5', 2.5, true ] as $quota ) {
			$d = $this->enforce( $this->paying( [ 'unit_quota' => $quota ] ) );
			self::assertSame( 403, $d['status'], var_export( $quota, true ) );
			self::assertSame( 'receipt carries no usable unit_quota', $d['body']['detail'] );
		}
		self::assertSame( [], $this->meter->used );
	}

	public function testASingleUseReceiptNeedsANonce(): void {
		$d = $this->enforce( $this->paying( [ 'unit_quota' => 1 ] ) );
		self::assertSame( 403, $d['status'] );
		self::assertSame( 'single-use receipt carries no nonce', $d['body']['detail'] );
		self::assertSame( 403, $this->enforce( $this->paying( [ 'unit_quota' => null, 'quota' => 1 ] ) )['status'], 'a legacy quota of one is single-use too' );
		self::assertSame( [], $this->meter->used );
		self::assertSame( '0', $this->enforce( $this->paying( [ 'unit_quota' => 1, 'nonce' => 'n' ] ) )['headers']['AIFP-Quota-Remaining'] );
		self::assertSame( 402, $this->enforce( $this->paying( [ 'unit_quota' => 1, 'nonce' => 'n' ] ) )['status'], 'served once' );
		self::assertSame( '403', $this->shadow( $this->paying( [ 'unit_quota' => 1 ] ) )['headers']['AIFP-Shadow-Status'] );
	}

	public function testAReceiptForAPageDoesNotOpenAPageNamedAfterIt(): void {
		foreach ( [ [ '/docs/what', 'exact' ], [ '/docs/what', 'prefix' ] ] as [ $resource, $scope ] ) {
			$r = self::request( [ 'aifp-receipt' => $this->token( [ 'resource' => $resource, 'scope' => $scope ] ), 'aifp-agent-id' => self::AGENT ], false, self::HOST, '/docs/what%3f' );
			self::assertSame( 403, $this->enforce( $r )['status'], $scope );
		}
		self::assertSame( [], $this->meter->used );
	}

	public function testAReceiptForThePageBeforeTheMarkOpensIt(): void {
		$seen = \AiFinPay\Core\Paths::resolve( [ '/report%3fx' ], [ '/report' ], [] );
		$r    = self::request( [ 'aifp-receipt' => $this->token( [ 'resource' => '/report' ] ), 'aifp-agent-id' => self::AGENT ], false, self::HOST, '/report%3fx' );
		self::assertSame( 200, $this->gate->enforce( $r, self::MERCHANT, self::charging(), $seen['rule'], $seen['path'], 'standard', self::NOW )['status'] );
	}

	public function testAReceiptNamesItsResourceAsAPathIsRead(): void {
		foreach ( [ '/Report/', '/report/', '/%72eport' ] as $resource ) {
			$r = self::request( [ 'aifp-receipt' => $this->token( [ 'resource' => $resource, 'receipt_id' => 'rcpt_' . md5( $resource ) ] ), 'aifp-agent-id' => self::AGENT ], false, self::HOST, '/report' );
			self::assertSame( 200, $this->enforce( $r )['status'], $resource );
		}
	}

	public function testTheBatchAnSdkBuysForASectionOpensTheSectionPage(): void {
		$r = self::request( [ 'aifp-receipt' => $this->token( [ 'resource' => '/paid/', 'scope' => 'prefix' ] ), 'aifp-agent-id' => self::AGENT ], false, self::HOST, '/paid' );
		self::assertSame( 200, $this->enforce( $r )['status'] );
	}

	public function testATestnetReceiptDoesNotOpenALiveSite(): void {
		$d = $this->enforce( $this->paying(), self::charging( [ 'network_mode' => 'live', 'site_verified' => true ] ) );
		self::assertSame( 403, $d['status'] );
		self::assertSame( [], $this->meter->used );
	}

	public function testAKeyOutageFailsClosed(): void {
		$this->keys->down = true;
		$d                = $this->enforce( $this->paying() );
		self::assertSame( 503, $d['status'] );
		self::assertSame( 'AIFP-503-METER', $d['body']['error'] );
		self::assertSame( '30', $d['headers']['Retry-After'] );
	}

	public function testAMeterOutageFailsClosed(): void {
		$this->meter->down = true;
		$d                 = $this->enforce( $this->paying() );
		self::assertSame( 503, $d['status'] );
		self::assertSame( 'AIFP-503-METER', $d['body']['error'] );
	}

	public function testARotatedKeyIsFetchedOnce(): void {
		$rotated           = new KeyPair( 'k2' );
		$this->keys->fresh = $rotated->jwks;
		$token             = $rotated->sign( [ 'iss' => Receipt::ISSUER, 'aud' => self::MERCHANT, 'exp' => 2000, 'resource' => '/paid/*', 'network_mode' => 'test', 'receipt_id' => 'rcpt_rotated', 'unit_quota' => 2 ] );
		$d                 = $this->enforce( self::request( [ 'aifp-receipt' => $token ] ) );
		self::assertSame( 200, $d['status'] );
		self::assertSame( 1, $this->keys->refreshes );
	}

	public function testAnUnknownKeyDuringTheCooldownIsRefused(): void {
		$rotated = new KeyPair( 'k2' );
		$token   = $rotated->sign( [ 'iss' => Receipt::ISSUER, 'aud' => self::MERCHANT, 'exp' => 2000, 'resource' => '/paid/*', 'network_mode' => 'test', 'receipt_id' => 'rcpt_rotated', 'unit_quota' => 2 ] );
		$d       = $this->enforce( self::request( [ 'aifp-receipt' => $token ] ) );
		self::assertSame( 403, $d['status'] );
	}

	private function shadow( Request $request, string $tier = 'standard', string $merchant = self::MERCHANT, ?string $network = 'test' ): array {
		return $this->gate->shadow( $request, $merchant, $network, '/paid/*', $request->path, $tier, self::NOW );
	}

	public function testShadowCountsAnAgentWithoutAReceiptAndReadsNothing(): void {
		$bot = $this->shadow( self::request( [ 'user-agent' => self::GPTBOT ] ), 'complex' );
		self::assertSame( 200, $bot['status'] );
		self::assertSame( 'shadow', $bot['outcome'] );
		self::assertSame( '402', $bot['headers']['AIFP-Shadow-Status'] );
		self::assertSame( [ 'kind' => '402', 'operator' => 'openai', 'ua_class' => 'ai_crawler', 'verified' => false ], $bot['event'] );
		self::assertSame( 4, $bot['units'] );
		self::assertSame( 0, $this->keys->reads );

		$reader = $this->shadow( self::request( [ 'user-agent' => 'Mozilla/5.0 Chrome/131' ] ) );
		self::assertSame( [], $reader['headers'] );
		self::assertNull( $reader['event'] );
	}

	public function testShadowWithoutAConnectionReadsNoKeys(): void {
		$d = $this->shadow( $this->paying(), 'standard', '', null );
		self::assertSame( '402', $d['headers']['AIFP-Shadow-Status'] );
		self::assertSame( 0, $this->keys->reads );
	}

	public function testAGoodReceiptInShadowIsVerifiedAndSpendsNothing(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$d = $this->shadow( $this->paying( [ 'unit_quota' => 1, 'nonce' => 'n' ] ) );
			self::assertSame( 200, $d['status'] );
			self::assertSame( [], $d['headers'] );
			self::assertSame( [ 'kind' => 'serve', 'operator' => 'aifinpay', 'ua_class' => 'aifp_agent', 'verified' => true ], $d['event'] );
			self::assertSame( 1, $d['units'] );
		}
		self::assertSame( [], $this->meter->used );
	}

	public static function wouldBe(): array {
		return [
			'forged'        => [ [ 'aud' => 'mrch_another_one' ], '403' ],
			'other payer'   => [ [ 'payer' => '0x0000000000000000000000000000000000000001' ], '403' ],
			'other network' => [ [ 'network_mode' => 'live' ], '403' ],
			'expired'       => [ [ 'exp' => 1400 ], '402' ],
		];
	}

	#[DataProvider( 'wouldBe' )]
	public function testABadReceiptInShadowIsServedAndSaysWhatEnforceWouldDo( array $patch, string $status ): void {
		$d = $this->shadow( $this->paying( $patch ) );
		self::assertSame( 200, $d['status'] );
		self::assertNull( $d['body'] );
		self::assertSame( $status, $d['headers']['AIFP-Shadow-Status'] );
		self::assertSame( [], $this->meter->used );
	}

	public function testAFailureInShadowNeverRefuses(): void {
		$this->keys->down  = true;
		$this->meter->down = true;
		$d                 = $this->shadow( $this->paying() );
		self::assertSame( 200, $d['status'] );
		self::assertSame( '503', $d['headers']['AIFP-Shadow-Status'] );
		self::assertNull( $d['event'] );
	}

	public function testAnUnknownNetworkInShadowDoesNotReadAsARefusal(): void {
		self::assertSame( [], $this->shadow( $this->paying(), 'standard', self::MERCHANT, null )['headers'] );
	}

	private const LIC = [ 'id' => 'clic_abababababababababababab', 'rev' => 2, 'v' => '2.0', 'sha256' => '3333333333333333333333333333333333333333333333333333333333333333', 'confirmed' => false ];

	public function testContentAReceiptUnlockedCarriesItsLicence(): void {
		$d   = $this->enforce( $this->paying( [ 'lic' => self::LIC ] ) );
		$url = 'https://api.aifinpay.io/v1/merchants/' . self::MERCHANT . '/license/2';
		self::assertSame( 200, $d['status'] );
		self::assertSame( 'id=' . self::LIC['id'] . '; rev=2; sha256=' . self::LIC['sha256'] . '; url="' . $url . '"', $d['headers']['AIFP-License'] );
		self::assertSame( '<' . $url . '/text>; rel="license"', $d['headers']['Link'] );
	}

	public function testAReceiptWithoutALicenceAddsNone(): void {
		$d = $this->enforce( $this->paying() );
		self::assertSame( 200, $d['status'] );
		self::assertArrayNotHasKey( 'AIFP-License', $d['headers'] );
		self::assertArrayNotHasKey( 'Link', $d['headers'] );
	}

	public function testARefusalNeverCarriesTheLicence(): void {
		$d = $this->enforce( $this->paying( [ 'lic' => self::LIC, 'aud' => 'mrch_someone_else' ] ) );
		self::assertSame( 403, $d['status'] );
		self::assertArrayNotHasKey( 'AIFP-License', $d['headers'] );
	}

	public function testAMalformedLicenceClaimIsIgnoredAndTheUrlIsOurs(): void {
		foreach ( [ null, 'x', [], [ 'id' => 'evil' ] + self::LIC, [ 'rev' => 0 ] + self::LIC, [ 'rev' => '2' ] + self::LIC, [ 'sha256' => str_repeat( 'Z', 64 ) ] + self::LIC ] as $bad ) {
			self::assertSame( [], ReceiptGate::licenseHeaders( 'https://api.aifinpay.io', self::MERCHANT, $bad ) );
		}
		$h = ReceiptGate::licenseHeaders( 'https://api.aifinpay.io/', self::MERCHANT, [ 'url' => 'https://evil.example' ] + self::LIC );
		self::assertStringNotContainsString( 'evil', $h['AIFP-License'] );
	}
}
