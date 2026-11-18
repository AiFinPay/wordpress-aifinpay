<?php

declare(strict_types=1);

use AiFinPay\Core\ReaderProof;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The reader cookie, byte for byte the shared vectors: tests/fixtures/reader-proof-v1.json. */
final class ReaderProofTest extends TestCase {

	private static function fixture(): array {
		return json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/reader-proof-v1.json' ), true, 16, JSON_THROW_ON_ERROR );
	}

	public function testTheFormatIsTheSharedOne(): void {
		$f = self::fixture();
		self::assertSame( 1, $f['schema'] );
		self::assertSame( $f['cookie'], ReaderProof::COOKIE );
		self::assertSame( $f['ttl_seconds'], ReaderProof::TTL );
		$proof = new ReaderProof( $f['issue'][0]['key'] );
		self::assertSame( str_replace( '<value>', 'v', $f['set_cookie'] ), $proof->setCookie( 'v' ) );
	}

	public static function issued(): iterable {
		foreach ( self::fixture()['issue'] as $case ) {
			yield $case['name'] => [ $case ];
		}
	}

	#[DataProvider( 'issued' )]
	public function testIssueGivesTheVectorAndItVerifies( array $case ): void {
		$proof = new ReaderProof( $case['key'] );
		self::assertSame( $case['cookie'], $proof->issue( $case['user_agent'], $case['now'] ) );
		self::assertTrue( $proof->verify( ReaderProof::COOKIE . '=' . $case['cookie'], $case['user_agent'], $case['now'] ) );
	}

	public static function verified(): iterable {
		foreach ( self::fixture()['verify'] as $case ) {
			yield $case['name'] => [ $case ];
		}
	}

	#[DataProvider( 'verified' )]
	public function testVerifyAnswersAsTheVector( array $case ): void {
		self::assertSame( $case['valid'], ( new ReaderProof( $case['key'] ) )->verify( $case['cookie_header'], $case['user_agent'], $case['now'] ) );
	}

	public function testEveryVectorIsRun(): void {
		$f = self::fixture();
		self::assertCount( 3, $f['issue'] );
		self::assertGreaterThanOrEqual( 20, count( $f['verify'] ) );
		self::assertContains( true, array_column( $f['verify'], 'valid' ) );
		self::assertContains( false, array_column( $f['verify'], 'valid' ) );
	}

	public function testAnyCookieIsNotEnough(): void {
		$proof = new ReaderProof( str_repeat( 'k', 32 ) );
		$ua    = 'Mozilla/5.0 Chrome/131';
		$good  = $proof->issue( $ua, 1000 );
		self::assertTrue( $proof->verify( '__aifp_human=' . $good, $ua, 1000 ) );
		self::assertFalse( $proof->verify( '__aifp_human=' . $good, 'Mozilla/5.0 Chrome/132', 1000 ), 'another User-Agent' );
		self::assertFalse( $proof->verify( '__aifp_human=' . $good, $ua, 1000 + ReaderProof::TTL ), 'expired' );
		self::assertFalse( ( new ReaderProof( str_repeat( 'x', 32 ) ) )->verify( '__aifp_human=' . $good, $ua, 1000 ), 'another key' );
		self::assertFalse( $proof->verify( '__aifp_human=1.' . str_repeat( 'A', 43 ), $ua, 0 ), 'forged' );
	}

	public function testAShortKeyIsRefused(): void {
		$this->expectException( InvalidArgumentException::class );
		new ReaderProof( str_repeat( 'k', 31 ) );
	}
}
