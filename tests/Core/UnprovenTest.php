<?php

declare(strict_types=1);

use AiFinPay\Core\PaidText;
use AiFinPay\Core\ReaderProof;
use AiFinPay\Core\Request;
use AiFinPay\Core\Unproven;
use PHPUnit\Framework\TestCase;

/** Who reads free under each setting, and when the 402 may be the page itself. */
final class UnprovenTest extends TestCase {

	private const CHROME = 'Mozilla/5.0 (Macintosh) Chrome/131.0.0.0 Safari/537.36';
	private const NOW    = 1800000000;

	private static function proof(): ReaderProof {
		return new ReaderProof( str_repeat( 'k', 32 ) );
	}

	private static function request( array $headers, string $method = 'GET', bool $logged_in = false ): Request {
		return new Request( $method, 'site.test', '/paid', array_change_key_case( $headers ) + [ 'user-agent' => self::CHROME ], $logged_in );
	}

	private static function classify( Request $r, bool $proven = true, ?string $bot = null ): string {
		return Unproven::classify( $r, $proven ? self::proof() : null, static fn () => $bot, self::NOW );
	}

	public function testTheTable(): void {
		$cookie = '__aifp_human=' . self::proof()->issue( self::CHROME, self::NOW );
		$cases  = [
			'no proof'                 => [ [], true, null, 'unproven' ],
			'reader cookie'            => [ [ 'Cookie' => $cookie ], true, null, 'reader' ],
			'cookie of another UA'     => [ [ 'Cookie' => $cookie, 'User-Agent' => 'curl/8' ], true, null, 'unproven' ],
			'expired cookie'           => [ [ 'Cookie' => '__aifp_human=' . self::proof()->issue( self::CHROME, self::NOW - ReaderProof::TTL ) ], true, null, 'unproven' ],
			'forged cookie'            => [ [ 'Cookie' => '__aifp_human=1900000000.' . str_repeat( 'A', 43 ) ], true, null, 'unproven' ],
			'verified search engine'   => [ [], true, 'search', 'search_bot' ],
			'other verified category'  => [ [], true, 'preview', 'unproven' ],
			'receipt beats cookie'     => [ [ 'Cookie' => $cookie, 'AIFP-Receipt' => 'x' ], true, null, 'agent' ],
			'agent id beats search'    => [ [ 'AIFP-Agent-Id' => '0xabc' ], true, 'search', 'agent' ],
			'everyone: cookie ignored' => [ [ 'Cookie' => $cookie ], false, null, 'unproven' ],
			'everyone: bot ignored'    => [ [], false, 'search', 'unproven' ],
		];
		foreach ( $cases as $name => [ $headers, $proven, $bot, $kind ] ) {
			self::assertSame( $kind, self::classify( self::request( $headers ), $proven, $bot ), $name );
		}
		self::assertSame( 'signed_in', self::classify( self::request( [], 'GET', true ) ) );
		self::assertSame( 'agent', self::classify( self::request( [ 'AIFP-Agent-Id' => '0xabc' ], 'GET', true ) ), 'a login does not exempt a paying agent' );
		foreach ( [ 'reader', 'search_bot', 'signed_in' ] as $free ) {
			self::assertContains( $free, Unproven::FREE_KINDS );
		}
		self::assertNotContains( 'agent', Unproven::FREE_KINDS );
		self::assertNotContains( 'unproven', Unproven::FREE_KINDS );
	}

	public function testTheBotCheckIsAskedOnlyWithoutACookie(): void {
		$asked = 0;
		$bot   = static function () use ( &$asked ): ?string {
			++$asked;
			return null;
		};
		Unproven::classify( self::request( [ 'Cookie' => '__aifp_human=' . self::proof()->issue( self::CHROME, self::NOW ) ] ), self::proof(), $bot, self::NOW );
		self::assertSame( 0, $asked );
		Unproven::classify( self::request( [] ), null, $bot, self::NOW );
		self::assertSame( 0, $asked, 'everyone: no lookup' );
	}

	public function testOnlyAnHtmlReadOfAPageMayBeHeld(): void {
		$html = [ 'Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8' ];
		self::assertTrue( Unproven::holdable( self::request( $html ), 'unproven', true, Unproven::PROVEN ) );
		self::assertTrue( Unproven::holdable( self::request( $html, 'HEAD' ), 'unproven', true, Unproven::PROVEN ) );
		self::assertFalse( Unproven::holdable( self::request( $html, 'POST' ), 'unproven', true, Unproven::PROVEN ) );
		self::assertFalse( Unproven::holdable( self::request( [ 'Accept' => 'application/json' ] ), 'unproven', true, Unproven::PROVEN ) );
		self::assertFalse( Unproven::holdable( self::request( [] ), 'unproven', true, Unproven::PROVEN ) );
		self::assertFalse( Unproven::holdable( self::request( $html ), 'unproven', false, Unproven::PROVEN ), 'feed or REST' );
		self::assertFalse( Unproven::holdable( self::request( $html ), 'unproven', true, Unproven::EVERYONE ), 'no check to pass' );
		self::assertFalse( Unproven::holdable( self::request( $html ), 'agent', true, Unproven::PROVEN ), 'an agent gets the JSON' );
		self::assertTrue( Unproven::quiet( self::request( $html ), 'unproven', Unproven::PROVEN ) );
		self::assertFalse( Unproven::quiet( self::request( $html ), 'reader', Unproven::PROVEN ) );
		self::assertFalse( Unproven::quiet( self::request( $html ), 'unproven', Unproven::EVERYONE ) );
	}

	public function testPaidTextIsFoundHoweverItIsPrinted(): void {
		$paid = '<!-- wp:paragraph --><p>The quarterly figures show that revenue from the northern region doubled while costs fell sharply.</p><!-- /wp:paragraph -->';
		self::assertFalse( PaidText::leaks( '<html><body><h1>Report</h1><div data-aifp-paid></div></body></html>', [ $paid ] ) );
		self::assertTrue( PaidText::leaks( '<p>The quarterly figures show that revenue from the northern <em>region</em> doubled</p>', [ $paid ] ), 'markup between the words' );
		self::assertTrue( PaidText::leaks( '<meta name="description" content="the quarterly figures show that revenue from the northern region&hellip;">', [ $paid ] ), 'a description' );
		self::assertTrue( PaidText::leaks( '<script type="application/ld+json">{"articleBody":"The quarterly figures show that revenue from the northern region doubled"}</script>', [ $paid ] ), 'JSON-LD' );
		self::assertTrue( PaidText::leaks( '{"x":"revenue from the n\u006frthern region doubled while costs fell sharply\u2026"}', [ $paid ] ), 'JSON escapes' );
		self::assertFalse( PaidText::leaks( 'revenue from the northern region', [ $paid ] ), 'fewer words than a run' );
		self::assertTrue( PaidText::leaks( '<p>PAID CONTENT MARKER</p>', [ 'PAID CONTENT MARKER' ] ), 'a short text whole' );
		self::assertFalse( PaidText::leaks( '<p>PAID CONTENT</p>', [ 'PAID CONTENT MARKER' ] ) );
		self::assertTrue( PaidText::leaks( '<p>Don’t stop – it’s here: café crème brûlée tonight, really now</p>', [ "Don't stop - it's here: café crème brûlée tonight, really now" ] ), 'typography' );
		self::assertFalse( PaidText::leaks( 'anything', [ '', '<p></p>' ] ) );
		self::assertTrue( PaidText::leaks( '<!-- The quarterly figures show that revenue from the northern region -->', [ $paid ] ), 'a comment is still sent' );
		self::assertTrue( PaidText::leaks( '<img alt="The quarterly figures show that revenue from the northern region">', [ $paid ] ), 'an attribute' );
	}
}
