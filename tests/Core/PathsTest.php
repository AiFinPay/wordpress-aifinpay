<?php

declare(strict_types=1);

use AiFinPay\Core\Paths;
use AiFinPay\Core\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PathsTest extends TestCase {

	public static function spellings(): array {
		return [
			[ '/docs/what%3F', '/docs/what%3f', false ],
			[ '/docs/what%253F', '/docs/what%3f', false ],
			[ '/wiki/C%23', '/wiki/c%23', false ],
			[ '/report;a%3Fb/c', '/report/c', true ],
			[ '/report%3Ba%3Fb/c', '/report/c', true ],
			[ '/report%3Fx', '/report%3fx', false ],
			[ '/report%23x', '/report%23x', false ],
			[ "/caf\u{e9}/X", '/caf%c3%a9/x', false ],
			[ '/caf%C3%A9/X', '/caf%c3%a9/x', false ],
			[ '/z#/..;/paid/a', '/z', true ],
			[ '/paid/%2525252e%2525252e/x', '/paid/%2e%2e/x', true ],
			[ '/paid/a', '/paid/a', false ],
			[ '/PAID/A', '/paid/a', false ],
			[ '/paid/a/', '/paid/a', false ],
			[ '//paid//a', '/paid/a', false ],
			[ '/paid/a?x=/b', '/paid/a', false ],
			[ '/%70aid/a', '/paid/a', false ],
			[ '/%2570aid/a', '/paid/a', false ],
			[ '/paid%2fa', '/paid/a', false ],
			[ '/paid\\a', '/paid/a', false ],
			[ '/paid;x=1/a', '/paid/a', false ],
			[ '/;x/paid/a', '/paid/a', false ],
			[ '/paid/./a', '/paid/a', true ],
			[ '/x/../paid/a', '/paid/a', true ],
			[ '/paid/..;/../a', '/a', true ],
			[ '/x%3F/../paid/a', '/paid/a', true ],
			[ '/a%00b', "/a\x00b", true ],
			[ '/news/caf%C3%A9', '/news/caf%c3%a9', false ],
			[ '/.well-known/acme-challenge/x', '/.well-known/acme-challenge/x', false ],
			[ '/', '/', false ],
			[ '', '/', false ],
		];
	}

	#[DataProvider( 'spellings' )]
	public function testAPathReadsAsTheGateReadsIt( string $raw, string $path, bool $ambiguous ): void {
		self::assertSame( [ 'path' => $path, 'ambiguous' => $ambiguous ], Paths::inspect( $raw ) );
	}

	public function testAPathReadAgainReadsTheSame(): void {
		$pieces = [ 'paid', 'A', '%2e', '%252e', '%2e%2e', '..', '.', ';x=1', '%3F', '%253F', '%23', '%25', '%2525', '%2f', '%252f', '\\', "caf\u{e9}", '%C3%A9', '%', '%4', '%41', '%2541', '%3B', '%5C', '' ];
		foreach ( $pieces as $a ) {
			foreach ( $pieces as $b ) {
				foreach ( $pieces as $c ) {
					$once = Paths::inspect( "/$a/$b$c/x" );
					if ( ! $once['ambiguous'] ) {
						self::assertSame( $once, Paths::inspect( $once['path'] ), "/$a/$b$c/x" );
					}
				}
			}
		}
	}

	public function testAnEscapedMarkIsUnderTheRuleOfThePageBeforeIt(): void {
		$protected = [ '/report', '/paid/*' ];
		foreach ( [ '/report%3Fx', '/REPORT%23x', '/report%253Fx' ] as $raw ) {
			self::assertSame( [ 'rule' => '/report', 'path' => '/report' ], Paths::resolve( [ Paths::inspect( $raw )['path'] ], $protected, [] ), $raw );
		}
		self::assertSame( [ 'rule' => '/paid/*', 'path' => '/paid' ], Paths::resolve( [ '/paid%3fx' ], $protected, [] ) );
		self::assertNull( Paths::resolve( [ '/free%3f/paid' ], $protected, [] ) );
		self::assertNull( Paths::beforeMark( '/report' ) );
	}

	public function testAPageUnderARuleKeepsItsWholeName(): void {
		self::assertSame( [ 'rule' => '/paid/*', 'path' => '/paid/what%3fx' ], Paths::resolve( [ '/paid/what%3fx' ], [ '/paid/*' ], [] ) );
	}

	public function testExclusionsApplyToThePageBeforeTheMark(): void {
		self::assertNull( Paths::resolve( [ '/report%3fx' ], [ '/report' ], [ '/report' ] ) );
		self::assertSame( '/paid/*', Paths::resolve( [ '/paid/a%3fx' ], [ '/paid/*' ], [ '/paid/a' ] )['rule'], 'the excluded page is another page' );
	}

	public function testSpelledKeepsAWildcard(): void {
		foreach ( [ '/Paid/*' => '/paid/*', '/*' => '/*', '/report/' => '/report', '/my%20report' => '/my report', '/' => '/', '*' => '*' ] as $raw => $read ) {
			self::assertSame( $read, Paths::spelled( $raw ), $raw );
		}
	}

	public function testAnExactPatternBeatsAWildcardWhateverTheOrder(): void {
		self::assertSame( '/paid/a', Paths::match( '/paid/a', [ '/paid/some-very-long-name/*', '/paid/*', '/paid/a' ] ) );
		self::assertSame( '/paid/a', Paths::match( '/paid/a', [ '/paid/a', '/paid/*' ] ) );
		self::assertSame( '/paid/a/*', Paths::match( '/paid/a', [ '/paid/*', '/paid/a/*' ] ), 'then the longest wildcard' );
		self::assertSame( '/x', Paths::match( '/x', [ '/*', '/x' ] ) );
	}

	public function testHost(): void {
		self::assertSame( 'site.test', Request::normaliseHost( 'SITE.test:8080' ) );
		self::assertSame( 'site.test', Request::normaliseHost( 'site.test.' ) );
	}

	public function testMatching(): void {
		self::assertSame( '/paid', Paths::match( '/paid', [ '/paid' ] ) );
		self::assertNull( Paths::match( '/paid/a', [ '/paid' ] ) );
		self::assertSame( '/paid/*', Paths::match( '/paid', [ '/paid/*' ] ) );
		self::assertSame( '/paid/*', Paths::match( '/paid/a/b', [ '/paid/*' ] ) );
		self::assertNull( Paths::match( '/paidx', [ '/paid/*' ] ) );
		self::assertSame( '/*', Paths::match( '/', [ '/*' ] ) );
		self::assertSame( '/paid/ab', Paths::match( '/paid/ab', [ '/*', '/paid/*', '/paid/ab' ] ) );
		self::assertNull( Paths::match( '/paid', [ '/bad//', 7 ] ) );
	}

	public function testValidPatterns(): void {
		foreach ( [ '/*', '/a', '/a/b', '/a/*', '/a.b_c-d', '/wp-json/wp/v2/*' ] as $ok ) {
			self::assertTrue( Paths::validPattern( $ok ), $ok );
		}
		foreach ( [ '', '/', 'a', '/a//b', '/a/../b', '/a/', '/A', '/a*', '/a/*/b', '/a b', '/a?b', "/a\n", '/' . str_repeat( 'a', 200 ) ] as $bad ) {
			self::assertFalse( Paths::validPattern( $bad ), $bad );
		}
	}

	public function testParseLines(): void {
		self::assertSame(
			[ 'valid' => [ '/pricing', '/docs/*', '/*' ], 'invalid' => [ 'not a path' ] ],
			Paths::parseLines( " /Pricing/ \r\n\n/docs/*\nnot a path\n/pricing\n/*" )
		);
	}

	public function testAProtectedLineMayCarryItsOwnPrice(): void {
		self::assertSame(
			[
				'valid'     => [ '/wp-json/aifinpay-demo/v1/license-record', '/wp-json/aifinpay-demo/v1/*', '/reports/*', '/*' ],
				'tiers'     => [ '/wp-json/aifinpay-demo/v1/license-record' => 'premium', '/reports/*' => 'complex' ],
				'invalid'   => [],
				'conflicts' => [],
			],
			Paths::parsePriced( " /wp-json/aifinpay-demo/v1/license-record   PREMIUM \r\n\n/wp-json/aifinpay-demo/v1/*\n/Reports/*\tcomplex\n/*\n/reports/* complex" )
		);
		self::assertSame( Paths::parseLines( " /Pricing/ \r\n\n/docs/*\n/pricing\n/*" )['valid'], Paths::parsePriced( " /Pricing/ \r\n\n/docs/*\n/pricing\n/*" )['valid'], 'lines without a price read as before' );
	}

	public function testAPriceThatCannotBeReadIsRefused(): void {
		$parsed = Paths::parsePriced( "/a gold\n/a premium extra\npremium\n/a/ /b\n/a//b premium\n/ok standard\n/a Premium-ish" );
		self::assertSame( [ '/a gold', '/a premium extra', 'premium', '/a/ /b', '/a//b premium', '/a premium-ish' ], $parsed['invalid'] );
		self::assertSame( [ '/ok' ], $parsed['valid'] );
		self::assertSame( [ '/a premium' ], Paths::parseLines( '/a premium' )['invalid'], 'an excluded path has no price' );
	}

	public function testAPathGivenTwoPricesIsAConflict(): void {
		self::assertSame( [ '/a' ], Paths::parsePriced( "/a premium\n/a complex" )['conflicts'] );
		self::assertSame( [ '/a' ], Paths::parsePriced( "/a\n/a/ premium\n/a complex" )['conflicts'], 'no price is a price too' );
		self::assertSame( [], Paths::parsePriced( "/a premium\n/A/ premium" )['conflicts'] );
	}

	public function testTheDearestSpellingOfOnePageSetsThePrice(): void {
		$protected = [ '/*', '/guide' ];
		$tiers     = [ '/*' => 'standard', '/guide' => 'premium' ];
		foreach ( [ [ '/2026/11/guide', '/guide' ], [ '/guide', '/2026/11/guide' ] ] as $candidates ) {
			self::assertSame( [ 'rule' => '/guide', 'path' => '/guide' ], Paths::resolve( $candidates, $protected, [], $tiers ), $candidates[0] );
		}
		self::assertSame( [ 'rule' => '/*', 'path' => '/2026/11/guide' ], Paths::resolve( [ '/2026/11/guide', '/guide' ], $protected, [], [ '/*' => 'premium', '/guide' => 'premium' ] ), 'a tie keeps the first' );
		self::assertSame( [ 'rule' => '/*', 'path' => '/2026/11/guide' ], Paths::resolve( [ '/2026/11/guide', '/guide' ], $protected, [] ), 'no prices: as before' );
	}

	public function testThePageBeforeAnEscapedMarkSetsThePriceWhenItIsDearer(): void {
		$protected = [ '/report', '/*' ];
		self::assertSame( [ 'rule' => '/report', 'path' => '/report' ], Paths::resolve( [ '/report%3fx' ], $protected, [], [ '/report' => 'premium', '/*' => 'standard' ] ) );
		self::assertSame( [ 'rule' => '/*', 'path' => '/report%3fx' ], Paths::resolve( [ '/report%3fx' ], $protected, [], [ '/report' => 'standard', '/*' => 'premium' ] ) );
		self::assertSame( [ 'rule' => '/*', 'path' => '/report%3fx' ], Paths::resolve( [ '/report%3fx' ], $protected, [], [ '/report' => 'complex', '/*' => 'complex' ] ), 'a tie keeps the page as asked' );
		self::assertSame( [ 'rule' => '/*', 'path' => '/report%3fx' ], Paths::resolve( [ '/report%3fx' ], $protected, [ '/report' ], [ '/report' => 'premium', '/*' => 'standard' ] ), 'an excluded page before the mark is free, so the page keeps its own price' );
	}

	public function testResolveSeesTheCanonicalPathAndExclusionsWin(): void {
		self::assertSame( [ 'rule' => '/paid', 'path' => '/paid' ], Paths::resolve( [ '/index.php/paid', '/paid' ], [ '/paid' ], [] ) );
		self::assertNull( Paths::resolve( [ '/x', '/paid' ], [ '/paid' ], [ '/x' ] ) );
		self::assertNull( Paths::resolve( [ '/paid/free' ], [ '/paid/*' ], [ '/paid/free' ] ) );
		self::assertNull( Paths::resolve( [ '/other' ], [ '/paid' ], [] ) );
	}
}
