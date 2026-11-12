<?php

declare(strict_types=1);

use AiFinPay\Core\Json;
use AiFinPay\Core\Pricing;
use AiFinPay\Core\ReceiptGate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** tests/fixtures/gate-receipt-parity.json is recorded from @aifinpay/gate; scripts/check-gate-parity.mjs replays the published package. */
final class GateParityTest extends TestCase {

	private static function parity(): array {
		return json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/gate-receipt-parity.json' ), true, 32, JSON_THROW_ON_ERROR );
	}

	public static function challenges(): array {
		return array_map( static fn ( array $c ): array => [ $c['args'], $c['body'] ], self::parity()['challenges'] );
	}

	#[DataProvider( 'challenges' )]
	public function testChallengeIsTheGateSdkBody( array $args, array $body ): void {
		self::assertSame( Pricing::TIER_WEIGHTS[ $args['tier'] ], $args['weight'] );
		$ours = ReceiptGate::challenge( $args['merchantId'], $args['resource'], $args['tier'], 'https://api.aifinpay.io', $args['detail'] );
		self::assertSame( $body, $ours );
		self::assertSame( Json::encode( $body ), Json::encode( $ours ) );
		self::assertStringContainsString( '—', Json::encode( $ours ), 'unescaped, as JSON.stringify' );
		self::assertStringContainsString( 'https://api.aifinpay.io/v1/quote', Json::encode( $ours ), 'unescaped slashes, as JSON.stringify' );
	}

	public static function scopes(): array {
		return array_map( static fn ( array $c ): array => [ $c['scope'], $c['resource'], $c['path'], $c['covers'] ], self::parity()['scopes'] );
	}

	#[DataProvider( 'scopes' )]
	public function testScopeIsTheGateSdkScope( ?string $scope, ?string $resource, string $path, bool $covers ): void {
		self::assertSame( $covers, ReceiptGate::scopeCovers( $scope, $resource, $path ) );
	}

	public function testMinRequestsBuyTheMinimumBatchAtEveryTier(): void {
		foreach ( Pricing::UNIT_PRICE_USD as $tier => $price ) {
			$units = (int) round( (float) $price * 1000000 );
			self::assertSame( (int) ceil( Pricing::MIN_BATCH_UNITS / $units ), Pricing::MIN_REQUESTS[ $tier ], $tier );
			self::assertSame( Pricing::TIER_WEIGHTS[ $tier ], (int) ( $units / Pricing::BASE_UNIT_MICRO ), $tier );
		}
	}

	public function testUnitsToUsdIsExact(): void {
		self::assertSame( '0.00', Pricing::unitsToUsd( 0 ) );
		self::assertSame( '0.0005', Pricing::unitsToUsd( 1 ) );
		self::assertSame( '0.02', Pricing::unitsToUsd( 40 ) );
		self::assertSame( '1.00', Pricing::unitsToUsd( 2000 ) );
		self::assertSame( '1.0005', Pricing::unitsToUsd( 2001 ) );
	}
}
