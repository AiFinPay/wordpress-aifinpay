<?php

declare(strict_types=1);

use AiFinPay\Core\GateIdentity;
use AiFinPay\Core\Unproven;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Driven by conformance/vectors/gate-identity-v1.json of AiFinPay/sdk (copy in tests/fixtures). */
final class GateIdentityTest extends TestCase {

	private static function vectors(): array {
		return json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/gate-identity-v1.json' ), true, 32, JSON_THROW_ON_ERROR );
	}

	public static function formats(): array {
		$out = [];
		foreach ( self::vectors()['format'] as $v ) {
			$out[ $v['name'] ] = [ $v['input'], $v['user_agent'], $v['header'] ];
		}
		return $out;
	}

	#[DataProvider( 'formats' )]
	public function testEveryFormatVector( array $input, string $user_agent, string $header ): void {
		self::assertSame(
			[ 'User-Agent' => $user_agent, 'AIFP-Gate' => $header ],
			GateIdentity::headers( $input['pkg'], $input['ver'], $input['platform'], $input['mode'], $input['charge'] )
		);
	}

	public function testThisPackageIsInTheList(): void {
		self::assertContains( GateIdentity::PKG, self::vectors()['pkgs'] );
		self::assertMatchesRegularExpression( GateIdentity::VALUE, GateIdentity::PLATFORM );
	}

	public function testTheVersionInTheHeaderIsThePluginsOwn(): void {
		$root   = dirname( __DIR__, 2 );
		$plugin = (string) file_get_contents( $root . '/aifinpay.php' );
		self::assertSame( 1, preg_match( '/^ \* Version:\s+(\S+)$/m', $plugin, $header ) );
		self::assertSame( 1, preg_match( "/^define\\( 'AIFINPAY_VERSION', '([^']+)' \\);$/m", $plugin, $constant ) );
		self::assertSame( 1, preg_match( '/^Stable tag: (\S+)$/m', (string) file_get_contents( $root . '/readme.txt' ), $stable ) );
		self::assertSame( $header[1], $constant[1] );
		self::assertSame( $header[1], $stable[1] );
		$sent = GateIdentity::headers( GateIdentity::PKG, $constant[1], GateIdentity::PLATFORM, 'shadow', 'agents' );
		self::assertSame( 'aifinpay-gate-wordpress/' . $header[1], $sent['User-Agent'] );
		self::assertSame( 'pkg=wordpress; ver=' . $header[1] . '; platform=wordpress; mode=shadow; charge=agents', $sent['AIFP-Gate'] );
	}

	public function testModeAndWhoReadsForFreeAsTheApiNamesThem(): void {
		self::assertSame( 'enforce', GateIdentity::mode( 'enforce' ) );
		self::assertSame( 'shadow', GateIdentity::mode( 'shadow' ) );
		self::assertSame( 'shadow', GateIdentity::mode( 'off' ) );
		self::assertSame( 'agents', GateIdentity::charge( Unproven::NAMED ) );
		self::assertSame( 'unproven', GateIdentity::charge( Unproven::PROVEN ) );
		self::assertSame( 'everyone', GateIdentity::charge( Unproven::EVERYONE ) );
		foreach ( GateIdentity::CHARGES as $charge ) {
			self::assertContains( $charge, self::vectors()['charges'] );
		}
	}
}
