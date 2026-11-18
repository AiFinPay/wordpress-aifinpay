<?php

declare(strict_types=1);

namespace AiFinPay\Core;

defined( 'ABSPATH' ) || exit;

/** "Tell agents what a page is": the optional `about` object of the 402, the same bytes as gate/src/about.ts. */
final class About {

	public const OFF      = 'off';
	public const TITLE    = 'title';
	public const SUMMARY  = 'title_summary';
	public const SETTINGS = [ self::OFF, self::TITLE, self::SUMMARY ];

	public const TITLE_MAX       = 200;
	public const DESCRIPTION_MAX = 300;

	private const TAG       = '/<[A-Za-z\/!?][^<>]*>/';
	private const ENTITY    = '/&(#[0-9]{1,7}|#[xX][0-9a-fA-F]{1,6}|[A-Za-z][A-Za-z0-9]{1,31});/';
	private const SURROGATE = '/\xED[\xA0-\xBF][\x80-\xBF]/';
	private const CONTROL   = '/[\x{0}-\x{8}\x{E}-\x{1F}\x{7F}-\x{84}\x{86}-\x{9F}]/u';
	private const SPACE     = '/[\t\n\x{B}\f\r \x{85}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+/u';

	private const NAMED = [
		'amp'    => '&',
		'lt'     => '<',
		'gt'     => '>',
		'quot'   => '"',
		'apos'   => "'",
		'nbsp'   => "\u{A0}",
		'ndash'  => "\u{2013}",
		'mdash'  => "\u{2014}",
		'hellip' => "\u{2026}",
		'lsquo'  => "\u{2018}",
		'rsquo'  => "\u{2019}",
		'sbquo'  => "\u{201A}",
		'ldquo'  => "\u{201C}",
		'rdquo'  => "\u{201D}",
		'bdquo'  => "\u{201E}",
		'laquo'  => "\u{AB}",
		'raquo'  => "\u{BB}",
		'middot' => "\u{B7}",
		'bull'   => "\u{2022}",
		'copy'   => "\u{A9}",
		'reg'    => "\u{AE}",
		'trade'  => "\u{2122}",
		'deg'    => "\u{B0}",
		'euro'   => "\u{20AC}",
		'pound'  => "\u{A3}",
		'yen'    => "\u{A5}",
		'cent'   => "\u{A2}",
		'sect'   => "\u{A7}",
		'para'   => "\u{B6}",
		'times'  => "\u{D7}",
		'divide' => "\u{F7}",
		'plusmn' => "\u{B1}",
	];

	/** What the setting lets the 402 say of $value ({title?, description?}), or null. */
	public static function build( string $setting, mixed $value ): ?array {
		if ( self::TITLE !== $setting && self::SUMMARY !== $setting ) {
			return null;
		}
		$about = self::from( $value );
		if ( self::TITLE === $setting && null !== $about ) {
			unset( $about['description'] );
		}
		return [] === $about ? null : $about;
	}

	/** Port of pageAbout(): the cleaned object, or null when there is nothing to say or the shape is wrong. */
	public static function from( mixed $value ): ?array {
		if ( ! is_array( $value ) || ( [] !== $value && array_is_list( $value ) ) ) {
			return null;
		}
		$title       = $value['title'] ?? null;
		$description = $value['description'] ?? null;
		if ( ( null !== $title && ! is_string( $title ) ) || ( null !== $description && ! is_string( $description ) ) ) {
			return null;
		}
		$about = [];
		$t     = null === $title ? '' : self::plain( $title, self::TITLE_MAX );
		$d     = null === $description ? '' : self::plain( $description, self::DESCRIPTION_MAX );
		if ( '' !== $t ) {
			$about['title'] = $t;
		}
		if ( '' !== $d ) {
			$about['description'] = $d;
		}
		return [] === $about ? null : $about;
	}

	/** Port of plainText(): tags stripped, entities decoded once, controls removed, whitespace collapsed, cut at $max code points. */
	public static function plain( string $value, int $max ): string {
		$text = (string) preg_replace( self::TAG, '', $value );
		$text = (string) preg_replace_callback( self::ENTITY, [ self::class, 'decode' ], $text );
		$text = (string) preg_replace( self::SURROGATE, '', $text );
		if ( 1 !== preg_match( '//u', $text ) ) {
			return '';
		}
		$text = (string) preg_replace( self::CONTROL, '', $text );
		$text = (string) preg_replace( self::SPACE, ' ', $text );
		$text = (string) preg_replace( '/^ | $/', '', $text );
		preg_match( '/^.{0,' . $max . '}/su', $text, $cut );
		$text = $cut[0];
		return str_ends_with( $text, ' ' ) ? substr( $text, 0, -1 ) : $text;
	}

	/** The body with `about` right after `resource`, as the gate SDK places it. */
	public static function insert( array $body, array $about ): array {
		$out = [];
		foreach ( $body as $key => $value ) {
			$out[ $key ] = $value;
			if ( 'resource' === $key ) {
				$out['about'] = $about;
			}
		}
		return $out + [ 'about' => $about ];
	}

	private static function decode( array $m ): string {
		$ref = $m[1];
		if ( '#' !== $ref[0] ) {
			return self::NAMED[ $ref ] ?? $m[0];
		}
		$code = 'x' === $ref[1] || 'X' === $ref[1] ? (int) hexdec( substr( $ref, 2 ) ) : (int) substr( $ref, 1 );
		if ( 0 === $code || $code > 0x10FFFF || ( $code >= 0xD800 && $code <= 0xDFFF ) ) {
			return $m[0];
		}
		return self::utf8( $code );
	}

	private static function utf8( int $code ): string {
		if ( $code < 0x80 ) {
			return chr( $code );
		}
		if ( $code < 0x800 ) {
			return chr( 0xC0 | ( $code >> 6 ) ) . chr( 0x80 | ( $code & 0x3F ) );
		}
		if ( $code < 0x10000 ) {
			return chr( 0xE0 | ( $code >> 12 ) ) . chr( 0x80 | ( ( $code >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $code & 0x3F ) );
		}
		return chr( 0xF0 | ( $code >> 18 ) ) . chr( 0x80 | ( ( $code >> 12 ) & 0x3F ) ) . chr( 0x80 | ( ( $code >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $code & 0x3F ) );
	}
}
