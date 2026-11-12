<?php

declare(strict_types=1);

use AiFinPay\Core\Receipt;
use AiFinPay\Core\ReceiptGate;
use AiFinPay\Tests\KeyPair;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/KeyPair.php';

final class ReceiptTest extends TestCase {

	private const MERCHANT = 'mrch_45b1fb9572bdf4f3';

	private static function claims( array $patch = [] ): array {
		return $patch + [ 'iss' => Receipt::ISSUER, 'aud' => self::MERCHANT, 'sub' => 'agent', 'iat' => 1000, 'exp' => 2000, 'receipt_id' => 'rcpt_1234567890abcdef' ];
	}

	public function testValidReceiptVerifies(): void {
		$k = new KeyPair();
		self::assertSame( 'rcpt_1234567890abcdef', Receipt::verify( $k->sign( self::claims() ), $k->jwks, self::MERCHANT, Receipt::ISSUER, 1500 )['receipt_id'] );
	}

	public function testExpiryHasTheGateClockTolerance(): void {
		$k     = new KeyPair();
		$token = $k->sign( self::claims() );
		self::assertSame( 2000, Receipt::verify( $token, $k->jwks, self::MERCHANT, Receipt::ISSUER, 2029 )['exp'] );
		$this->expectExceptionMessage( 'expired' );
		Receipt::verify( $token, $k->jwks, self::MERCHANT, Receipt::ISSUER, 2030 );
	}

	public static function refused(): array {
		return [
			'another merchant'     => [ [ 'aud' => 'mrch_another00000000' ] ],
			'another issuer'       => [ [ 'iss' => 'https://evil.test' ] ],
			'iat not a number'     => [ [ 'iat' => 'soon' ] ],
			'not yet valid'        => [ [ 'nbf' => 1600 ] ],
			'no expiry'            => [ [ 'exp' => null ] ],
		];
	}

	#[DataProvider( 'refused' )]
	public function testForeignOrMalformedReceiptIsInvalid( array $patch ): void {
		$k = new KeyPair();
		$this->expectExceptionMessage( 'invalid' );
		Receipt::verify( $k->sign( self::claims( $patch ) ), $k->jwks, self::MERCHANT, Receipt::ISSUER, 1500 );
	}

	public function testAFutureIatAloneDoesNotRefuse(): void {
		$k = new KeyPair();
		self::assertSame( 1600, Receipt::verify( $k->sign( self::claims( [ 'iat' => 1600 ] ) ), $k->jwks, self::MERCHANT, Receipt::ISSUER, 1500 )['iat'] );
	}

	public function testNoTypAndNoKidVerifyAgainstAOneKeySet(): void {
		$k = new KeyPair();
		foreach ( [ [ 'typ' => null ], [ 'kid' => null ], [ 'typ' => null, 'kid' => null ] ] as $header ) {
			self::assertSame( 'agent', Receipt::verify( $k->sign( self::claims(), $header ), $k->jwks, self::MERCHANT, Receipt::ISSUER, 1500 )['sub'] );
		}
	}

	public function testNoKidIsRefusedWhenTheSetHasSeveralKeys(): void {
		$k     = new KeyPair();
		$other = new KeyPair( 'k2' );
		foreach ( [ [ ...$k->jwks['keys'], ...$other->jwks['keys'] ], [ ...$other->jwks['keys'], ...$k->jwks['keys'] ] ] as $keys ) {
			try {
				Receipt::verify( $k->sign( self::claims(), [ 'kid' => null ] ), [ 'keys' => $keys ], self::MERCHANT, Receipt::ISSUER, 1500 );
				self::fail( 'accepted' );
			} catch ( RuntimeException $e ) {
				self::assertSame( 'invalid', $e->getMessage() );
			}
		}
	}

	public function testNoKidStillNeedsTheRightSignature(): void {
		$k     = new KeyPair();
		$other = new KeyPair();
		$this->expectExceptionMessage( 'invalid' );
		Receipt::verify( $k->sign( self::claims(), [ 'kid' => null ] ), $other->jwks, self::MERCHANT, Receipt::ISSUER, 1500 );
	}

	public function testAnotherTypOrANonStringKidIsInvalid(): void {
		$k = new KeyPair();
		foreach ( [ [ 'typ' => 'at+jwt' ], [ 'kid' => 7 ] ] as $header ) {
			try {
				Receipt::verify( $k->sign( self::claims(), $header ), $k->jwks, self::MERCHANT, Receipt::ISSUER, 1500 );
				self::fail( 'accepted ' . json_encode( $header ) );
			} catch ( RuntimeException $e ) {
				self::assertSame( 'invalid', $e->getMessage() );
			}
		}
	}

	public function testForgedSignatureIsInvalid(): void {
		$k     = new KeyPair();
		$other = new KeyPair();
		$this->expectExceptionMessage( 'invalid' );
		Receipt::verify( $k->sign( self::claims() ), $other->jwks, self::MERCHANT, Receipt::ISSUER, 1500 );
	}

	public function testAlgNoneIsInvalid(): void {
		$k = new KeyPair();
		$h = KeyPair::b64( '{"alg":"none","typ":"JWT","kid":"k1"}' );
		$p = KeyPair::b64( (string) json_encode( self::claims() ) );
		$this->expectExceptionMessage( 'invalid' );
		Receipt::verify( "$h.$p.", $k->jwks, self::MERCHANT, Receipt::ISSUER, 1500 );
	}

	public function testAKeyNotInTheSetIsAskedForAgain(): void {
		$k     = new KeyPair( 'rotated' );
		$other = new KeyPair( 'old' );
		$this->expectExceptionMessage( 'unknown_key' );
		Receipt::verify( $k->sign( self::claims() ), $other->jwks, self::MERCHANT, Receipt::ISSUER, 1500 );
	}

	public function testPayerBinding(): void {
		self::assertTrue( ReceiptGate::samePayer( '0x2714Ca06600dB9C5167eafa37216716A8ADc83fE', '0x2714ca06600db9c5167eafa37216716a8adc83fe' ) );
		self::assertFalse( ReceiptGate::samePayer( '3qJSQCKX653L7G9qGaFmrQEQ9eWcMA97iUJXToUYA7d2', '3qjsqckx653l7g9qgafmrqeq9ewcma97iujxtouya7d2' ) );
		self::assertFalse( ReceiptGate::samePayer( '0x2714Ca06600dB9C5167eafa37216716A8ADc83fE', null ) );
	}
}
