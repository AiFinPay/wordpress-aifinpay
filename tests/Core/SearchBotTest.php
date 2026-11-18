<?php

declare(strict_types=1);

use AiFinPay\Core\Resolver;
use AiFinPay\Core\SearchBot;
use PHPUnit\Framework\TestCase;

/** Forward-confirmed reverse DNS with an injected resolver: nothing here touches the network. */
final class SearchBotTest extends TestCase {

	private const GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

	/** @param array<string, string[]|\Throwable> $ptr @param array<string, string[]|\Throwable> $forward */
	private static function dns( array $ptr, array $forward ): Resolver {
		return new class( $ptr, $forward ) implements Resolver {
			public array $asked = [];
			public function __construct( private array $ptr, private array $forward ) {}
			public function reverse( string $ip ): array {
				$this->asked[] = 'PTR ' . $ip;
				$a = $this->ptr[ $ip ] ?? [];
				if ( $a instanceof \Throwable ) {
					throw $a;
				}
				return $a;
			}
			public function forward( string $name, int $family ): array {
				$this->asked[] = ( 4 === $family ? 'A ' : 'AAAA ' ) . $name;
				$a = $this->forward[ $name ] ?? [];
				if ( $a instanceof \Throwable ) {
					throw $a;
				}
				return $a;
			}
		};
	}

	public function testAGoodGooglebotIsASearchEngineForADay(): void {
		$dns = self::dns( [ '66.249.66.1' => [ 'crawl-66-249-66-1.googlebot.com.' ] ], [ 'crawl-66-249-66-1.googlebot.com' => [ '66.249.66.1' ] ] );
		self::assertSame( [ 'category' => 'search', 'ttl' => SearchBot::TTL_VERIFIED ], SearchBot::verify( self::GOOGLEBOT, '66.249.66.1', $dns ) );
		self::assertSame( [ 'PTR 66.249.66.1', 'A crawl-66-249-66-1.googlebot.com' ], $dns->asked );
	}

	public function testEveryOperatorOfTheTable(): void {
		$cases = [
			'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)' => 'msnbot-1.search.msn.com',
			'Mozilla/5.0 (Macintosh) Version/17 Safari/605.1.15 (Applebot/0.1)'     => 'x.applebot.apple.com',
			'Mozilla/5.0 (compatible; YandexBot/3.0)'                               => 'a.spider.yandex.com',
			'Mozilla/5.0 (compatible; Baiduspider/2.0)'                             => 'b.crawl.baidu.jp',
		];
		foreach ( $cases as $ua => $name ) {
			$dns = self::dns( [ '192.0.2.7' => [ $name ] ], [ $name => [ '192.0.2.7' ] ] );
			self::assertSame( 'search', SearchBot::verify( $ua, '192.0.2.7', $dns )['category'], $ua );
		}
	}

	public function testAWrongSuffixIsRefusedForAnHour(): void {
		$dns = self::dns( [ '192.0.2.1' => [ 'host.example.com' ] ], [ 'host.example.com' => [ '192.0.2.1' ] ] );
		self::assertSame( [ 'category' => null, 'ttl' => SearchBot::TTL_REFUSED ], SearchBot::verify( self::GOOGLEBOT, '192.0.2.1', $dns ) );
		self::assertSame( [ 'PTR 192.0.2.1' ], $dns->asked, 'no forward lookup for a name of another operator' );
	}

	public function testALookAlikeSuffixIsRefused(): void {
		foreach ( [ 'crawl.evilgooglebot.com', 'googlebot.com', 'crawl.googlebot.com.evil.test', 'crawl.search.msn.com' ] as $name ) {
			$dns = self::dns( [ '192.0.2.1' => [ $name ] ], [ $name => [ '192.0.2.1' ] ] );
			self::assertNull( SearchBot::verify( self::GOOGLEBOT, '192.0.2.1', $dns )['category'], $name );
		}
	}

	public function testTheNameMustResolveBackToTheSameAddress(): void {
		$dns = self::dns( [ '192.0.2.1' => [ 'crawl.googlebot.com' ] ], [ 'crawl.googlebot.com' => [ '66.249.66.1' ] ] );
		self::assertSame( [ 'category' => null, 'ttl' => SearchBot::TTL_REFUSED ], SearchBot::verify( self::GOOGLEBOT, '192.0.2.1', $dns ) );
	}

	public function testAResolverErrorIsNotVerifiedAndRetriedInAMinute(): void {
		foreach ( [
			self::dns( [ '66.249.66.1' => new RuntimeException( 'SERVFAIL' ) ], [] ),
			self::dns( [ '66.249.66.1' => [ 'crawl.googlebot.com' ] ], [ 'crawl.googlebot.com' => new RuntimeException( 'timeout' ) ] ),
		] as $dns ) {
			self::assertSame( [ 'category' => null, 'ttl' => SearchBot::TTL_ERROR ], SearchBot::verify( self::GOOGLEBOT, '66.249.66.1', $dns ) );
		}
	}

	public function testIpv6AndMappedAddresses(): void {
		$dns = self::dns( [ '2001:4860:4801:10::1' => [ 'crawl-2001-4860-4801-10--1.googlebot.com' ] ], [ 'crawl-2001-4860-4801-10--1.googlebot.com' => [ '2001:4860:4801:0010:0000:0000:0000:0001' ] ] );
		self::assertSame( 'search', SearchBot::verify( self::GOOGLEBOT, '2001:4860:4801:10:0::1', $dns )['category'] );
		self::assertContains( 'AAAA crawl-2001-4860-4801-10--1.googlebot.com', $dns->asked );
		$v4 = self::dns( [ '66.249.66.1' => [ 'crawl.googlebot.com' ] ], [ 'crawl.googlebot.com' => [ '66.249.66.1' ] ] );
		self::assertSame( 'search', SearchBot::verify( self::GOOGLEBOT, '::ffff:66.249.66.1', $v4 )['category'] );
		self::assertNull( SearchBot::normaliseIp( 'not-an-ip' ) );
		self::assertNull( SearchBot::verify( self::GOOGLEBOT, 'fe80::1%eth0', $v4 ) );
	}

	public function testAnAiMarkerOrTwoOperatorsAreNeverVerified(): void {
		$dns = self::dns( [ '66.249.66.1' => [ 'crawl.googlebot.com' ] ], [ 'crawl.googlebot.com' => [ '66.249.66.1' ] ] );
		foreach ( [
			'Mozilla/5.0 (compatible; Googlebot/2.1) GPTBot/1.2',
			'Mozilla/5.0 (compatible; Googlebot/2.1) Google-Extended',
			'Googlebot/2.1 bingbot/2.0',
			'Mozilla/5.0 NotGooglebot/1.0',
			'Mozilla/5.0 Chrome/131',
			'',
		] as $ua ) {
			self::assertNull( SearchBot::claimed( $ua ), $ua );
			self::assertNull( SearchBot::verify( $ua, '66.249.66.1', $dns ), $ua );
		}
		self::assertSame( [], $dns->asked, 'no lookup without a single claim' );
		self::assertSame( 'google', SearchBot::claimed( 'Googlebot-Image/1.0' ) );
	}
}
