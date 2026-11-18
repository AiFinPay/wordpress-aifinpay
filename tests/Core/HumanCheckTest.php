<?php

declare(strict_types=1);

use AiFinPay\Core\HumanCheck;
use AiFinPay\Core\ReaderProof;
use PHPUnit\Framework\TestCase;

/** POST /__aifp/human with an injected siteverify: nothing here touches the network. */
final class HumanCheckTest extends TestCase {

	private const UA  = 'Mozilla/5.0 (Macintosh) Chrome/131.0.0.0 Safari/537.36';
	private const NOW = 1800000000;

	private array $asked = [];

	private function answer( string $method, string $body, ?array $verdict, string $host = 'site.test' ): array {
		$this->asked = [];
		$verify      = function ( string $token, string $ip ) use ( $verdict ): ?array {
			$this->asked[] = [ $token, $ip ];
			return $verdict;
		};
		return HumanCheck::answer( $method, $body, self::UA, '203.0.113.9', $host, $verify, new ReaderProof( str_repeat( 'k', 32 ) ), self::NOW );
	}

	public function testAPassedCheckSetsTheSharedCookie(): void {
		$a = $this->answer( 'POST', '{"token":"tok"}', [ 'success' => true, 'hostname' => 'Site.Test' ] );
		self::assertSame( 204, $a['status'] );
		self::assertSame( [ [ 'tok', '203.0.113.9' ] ], $this->asked, 'the token and the client address go to siteverify' );
		self::assertSame( 'private, no-store, max-age=0', $a['headers']['Cache-Control'] );
		self::assertMatchesRegularExpression( '/^__aifp_human=(\d+\.[A-Za-z0-9_-]{43}); Path=\/; Max-Age=2592000; HttpOnly; Secure; SameSite=Lax$/D', $a['headers']['Set-Cookie'] );
		$value = explode( ';', substr( $a['headers']['Set-Cookie'], strlen( '__aifp_human=' ) ) )[0];
		self::assertTrue( ( new ReaderProof( str_repeat( 'k', 32 ) ) )->verify( '__aifp_human=' . $value, self::UA, self::NOW ) );
		self::assertCount( 2, $a['headers'], 'nothing but the cookie is set' );
	}

	public function testAnotherHostnameIsRefused(): void {
		foreach ( [ [ 'success' => true, 'hostname' => 'other.test' ], [ 'success' => true ], [ 'success' => true, 'hostname' => 'site.test.evil' ] ] as $verdict ) {
			$a = $this->answer( 'POST', '{"token":"tok"}', $verdict );
			self::assertSame( 403, $a['status'] );
			self::assertArrayNotHasKey( 'Set-Cookie', $a['headers'] );
		}
		self::assertSame( 403, $this->answer( 'POST', '{"token":"tok"}', [ 'success' => true, 'hostname' => '' ], '' )['status'], 'no site host, nothing matches' );
	}

	public function testAFailedOrMissingVerdictIsRefused(): void {
		foreach ( [ [ 'success' => false, 'hostname' => 'site.test' ], [ 'success' => 'true', 'hostname' => 'site.test' ], null ] as $verdict ) {
			self::assertSame( 403, $this->answer( 'POST', '{"token":"tok"}', $verdict )['status'] );
		}
		$throws = HumanCheck::answer( 'POST', '{"token":"t"}', self::UA, '', 'site.test', static fn () => throw new RuntimeException( 'down' ), new ReaderProof( str_repeat( 'k', 32 ) ), self::NOW );
		self::assertSame( 403, $throws['status'] );
	}

	public function testOnlyPost(): void {
		foreach ( [ 'GET', 'HEAD', 'PUT', 'OPTIONS' ] as $method ) {
			$a = $this->answer( $method, '{"token":"tok"}', [ 'success' => true, 'hostname' => 'site.test' ] );
			self::assertSame( [ 405, 'POST' ], [ $a['status'], $a['headers']['Allow'] ] );
		}
		self::assertSame( [], $this->asked );
	}

	public function testABadBodyIsRefusedBeforeSiteverify(): void {
		foreach ( [ '', 'token=tok', '{}', '{"token":""}', '{"token":7}', '["tok"]', '{"token":"' . str_repeat( 'a', 2049 ) . '"}' ] as $body ) {
			self::assertSame( 400, $this->answer( 'POST', $body, [ 'success' => true, 'hostname' => 'site.test' ] )['status'], $body );
			self::assertSame( [], $this->asked );
		}
	}
}
