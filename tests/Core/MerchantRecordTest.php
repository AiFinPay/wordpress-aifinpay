<?php

declare(strict_types=1);

use AiFinPay\Core\ConnectCode;
use AiFinPay\Core\Eligibility;
use AiFinPay\Core\MerchantRecord;
use PHPUnit\Framework\TestCase;

final class MerchantRecordTest extends TestCase {

	private const M = 'mrch_wp_gate_test';

	private static function record( array $patch = [] ): array {
		return (array) MerchantRecord::fromResponse(
			$patch + [ 'merchant_id' => self::M, 'name' => 'Site', 'network_mode' => 'test', 'site_url' => 'https://Site.test/', 'site_verified' => false ],
			self::M,
			1000
		);
	}

	public function testFromResponseKeepsTheValidatedSubset(): void {
		self::assertSame(
			[ 'merchant_id' => self::M, 'name' => 'Site', 'network_mode' => 'test', 'site_url' => 'https://Site.test/', 'site_host' => 'site.test', 'site_verified' => false, 'fetched_at' => 1000 ],
			self::record()
		);
		self::assertNull( MerchantRecord::fromResponse( [ 'merchant_id' => 'mrch_other_one' ], self::M, 1 ) );
		self::assertNull( MerchantRecord::fromResponse( [ 'merchant_id' => self::M, 'network_mode' => 'x' ], self::M, 1 ) );
		self::assertFalse( self::record( [ 'site_verified' => 'true' ] )['site_verified'] );
		self::assertSame( 'live', MerchantRecord::fromResponse( [ 'merchant_id' => self::M ], self::M, 1 )['network_mode'] );
	}

	public function testHost(): void {
		self::assertSame( 'a.test', MerchantRecord::host( 'https://A.test:8443/x' ) );
		foreach ( [ 'ftp://a.test', 'https://u:p@a.test', 'a.test', 'https://', 'https://a..test' ] as $bad ) {
			self::assertNull( MerchantRecord::host( $bad ), $bad );
		}
	}

	public function testChargingMatrix(): void {
		$c = MerchantRecord::charging( self::M, self::record() );
		self::assertSame( [ true, 'site.test', 'test', 'test_merchant' ], array_values( $c ) );
		$c = MerchantRecord::charging( self::M, self::record( [ 'network_mode' => 'live' ] ) );
		self::assertSame( [ false, 'site.test', 'live', 'live_domain_unverified' ], array_values( $c ) );
		$c = MerchantRecord::charging( self::M, self::record( [ 'network_mode' => 'live', 'site_verified' => true ] ) );
		self::assertSame( [ true, 'site.test', 'live', 'live_domain_verified' ], array_values( $c ) );
		$c = MerchantRecord::charging( self::M, self::record( [ 'site_url' => null ] ) );
		self::assertSame( [ false, null, 'test', 'no_site_url' ], array_values( $c ) );
		self::assertSame( 'not_connected', MerchantRecord::charging( '', self::record() )['reason'] );
		self::assertSame( 'record_unavailable', MerchantRecord::charging( self::M, null )['reason'] );
		self::assertSame( 'record_unavailable', MerchantRecord::charging( 'mrch_someone_else', self::record() )['reason'] );
		self::assertFalse( MerchantRecord::charging( 'mrch_someone_else', self::record() )['approved'] );
	}

	public function testEligibility(): void {
		$ok = MerchantRecord::charging( self::M, self::record() );
		self::assertSame( [], Eligibility::problems( [ '/paid' ], 'SITE.test', true, $ok ) );
		self::assertSame( [ 'host_mismatch' ], Eligibility::problems( [ '/paid' ], 'www.site.test', true, $ok ) );
		self::assertSame( [ 'plain_permalinks' ], Eligibility::problems( [ '/paid' ], 'site.test', false, $ok ) );
		self::assertSame( [ 'no_paths' ], Eligibility::problems( [], 'site.test', true, $ok ) );
		self::assertSame( [ 'all_paths' ], Eligibility::problems( [ '/paid', '/*' ], 'site.test', true, $ok ) );
		self::assertSame( [ 'live_domain_unverified' ], Eligibility::problems( [ '/paid' ], 'site.test', true, MerchantRecord::charging( self::M, self::record( [ 'network_mode' => 'live' ] ) ) ) );
		self::assertSame( [ 'not_connected' ], Eligibility::problems( [ '/paid' ], 'site.test', true, MerchantRecord::charging( '', null ) ) );
	}

	public function testConnectCode(): void {
		self::assertSame( 'AIFP7K2MQX9D4HNCT0RB', ConnectCode::normalise( ' aifp-7k2m-qx9d-4hnc-t0rb ' ) );
		self::assertSame( '7K2MQX9D4HNCT0RB', ConnectCode::normalise( '7K2M QX9D 4HNC T0RB' ) );
		foreach ( [ '', 'AIFP-1234', 'AIFP-ILOU-ILOU-ILOU-ILOU', str_repeat( 'A', 40 ) ] as $bad ) {
			self::assertNull( ConnectCode::normalise( $bad ), $bad );
		}
	}

	public function testConnectResponse(): void {
		$secret = 'msec_' . str_repeat( 'a', 40 );
		self::assertSame( [ 'merchant_id' => self::M, 'secret' => $secret ], ConnectCode::parseResponse( 200, (string) json_encode( [ 'merchant_id' => self::M, 'secret' => $secret ] ) ) );
		foreach ( [
			[ 400, '', 'invalid_code' ],
			[ 429, '', 'rate_limited' ],
			[ 503, '', 'unavailable' ],
			[ null, '', 'unavailable' ],
			[ 200, 'not json', 'bad_response' ],
			[ 200, '{"merchant_id":"nope","secret":"' . $secret . '"}', 'bad_response' ],
			[ 200, '{"merchant_id":"' . self::M . '","secret":"short"}', 'bad_response' ],
		] as [ $status, $raw, $code ] ) {
			try {
				ConnectCode::parseResponse( $status, $raw );
				self::fail( 'accepted ' . $raw );
			} catch ( RuntimeException $e ) {
				self::assertSame( $code, $e->getMessage() );
			}
		}
	}
}
