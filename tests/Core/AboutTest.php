<?php

declare(strict_types=1);

use AiFinPay\Core\About;
use AiFinPay\Core\Json;
use AiFinPay\Core\ReceiptGate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The `about` object of the 402: the shared vectors of AiFinPay/sdk conformance, and what the setting allows. */
final class AboutTest extends TestCase {

	/** PHP strings are bytes: a lone UTF-16 surrogate arrives as its three WTF-8 bytes, which json_decode refuses as an escape. */
	public static function vectors(): array {
		$raw  = (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/about-v1.json' );
		$raw  = (string) preg_replace_callback(
			'/\\\\u[dD][89a-fA-F][0-9a-fA-F]{2}(?:\\\\u[dD][c-fC-F][0-9a-fA-F]{2})?/',
			static fn ( array $m ): string => 12 === strlen( $m[0] ) && preg_match( '/^\\\\u[dD][89abAB]/', $m[0] ) ? $m[0] : '@@SURROGATE-' . strtoupper( substr( $m[0], 2, 4 ) ) . '@@',
			$raw
		);
		$data = json_decode( $raw, true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( [ 200, 300 ], [ $data['title_max'], $data['description_max'] ] );
		$wtf8 = static function ( mixed $value ) use ( &$wtf8 ): mixed {
			if ( is_array( $value ) ) {
				return array_map( $wtf8, $value );
			}
			return is_string( $value ) ? (string) preg_replace_callback( '/@@SURROGATE-([0-9A-F]{4})@@/', static fn ( array $m ): string => chr( 0xE0 | ( hexdec( $m[1] ) >> 12 ) ) . chr( 0x80 | ( ( hexdec( $m[1] ) >> 6 ) & 0x3F ) ) . chr( 0x80 | ( hexdec( $m[1] ) & 0x3F ) ), $value ) : $value;
		};
		$out = [];
		foreach ( $data['vectors'] as $v ) {
			$out[ $v['name'] ] = [ $wtf8( $v['value'] ), $v['about'] ];
		}
		return $out;
	}

	#[DataProvider( 'vectors' )]
	public function testSharedVector( mixed $value, mixed $about ): void {
		self::assertSame( Json::encode( $about ), Json::encode( About::from( $value ) ) );
	}

	public function testAllVectorsAreRun(): void {
		self::assertCount( 42, self::vectors() );
	}

	public function testTheSettingDecidesWhatIsSaid(): void {
		$value = [ 'title' => 'Herons', 'description' => 'Where they nest.' ];
		self::assertSame( $value, About::build( About::SUMMARY, $value ) );
		self::assertSame( [ 'title' => 'Herons' ], About::build( About::TITLE, $value ) );
		self::assertNull( About::build( About::TITLE, [ 'description' => 'Where they nest.' ] ) );
		self::assertNull( About::build( About::OFF, $value ) );
		self::assertNull( About::build( 'TITLE', $value ), 'an unknown value says nothing' );
		self::assertNull( About::build( About::SUMMARY, [ 'title' => 'Herons', 'description' => [ 'x' ] ] ), 'a wrong shape drops the whole object' );
	}

	public function testItGoesRightAfterResourceAndChangesNothingElse(): void {
		$body = ReceiptGate::challenge( 'mrch_x', '/paid', 'standard', 'https://api.aifinpay.io' );
		$with = About::insert( $body, [ 'title' => 'T' ] );
		$keys = array_keys( $with );
		self::assertSame( [ 'resource', 'about', 'tier' ], array_slice( $keys, array_search( 'resource', $keys, true ), 3 ) );
		unset( $with['about'] );
		self::assertSame( Json::encode( $body ), Json::encode( $with ) );
	}
}
