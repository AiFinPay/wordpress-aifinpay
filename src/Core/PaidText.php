<?php

declare(strict_types=1);

namespace AiFinPay\Core;

defined( 'ABSPATH' ) || exit;

/** Whether a response still carries paid text: any run of words from it, markup, entities and JSON escapes aside. */
final class PaidText {

	public const RUN = 8;

	/** @param string[] $texts Paid texts as stored: HTML, blocks, shortcodes. */
	public static function leaks( string $output, array $texts, int $run = self::RUN ): bool {
		$out    = self::words( $output, false );
		$joined = ' ' . implode( ' ', $out ) . ' ';
		$runs   = [];
		for ( $i = 0, $last = count( $out ) - $run; $i <= $last; $i++ ) {
			$runs[ implode( ' ', array_slice( $out, $i, $run ) ) ] = true;
		}
		foreach ( $texts as $text ) {
			$words = self::words( (string) $text, true );
			if ( [] === $words ) {
				continue;
			}
			if ( count( $words ) < $run ) {
				if ( str_contains( $joined, ' ' . implode( ' ', $words ) . ' ' ) ) {
					return true;
				}
				continue;
			}
			for ( $i = 0, $last = count( $words ) - $run; $i <= $last; $i++ ) {
				if ( isset( $runs[ implode( ' ', array_slice( $words, $i, $run ) ) ] ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Comments in stored text (block markup) are not shown; in a response they are still sent.
	 *
	 * @return string[]
	 */
	public static function words( string $text, bool $stored ): array {
		$text = (string) preg_replace_callback( '/\\\\u([0-9a-fA-F]{4})/', static fn ( array $m ): string => html_entity_decode( '&#x' . $m[1] . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $text );
		$text = $stored ? (string) preg_replace( '/<!--.*?-->/s', ' ', $text ) : str_replace( [ '<!--', '-->' ], ' ', $text );
		// A tag keeps its attribute values: a description or alt text can carry the text too.
		$text = (string) preg_replace_callback(
			'/<[^>]*>/',
			static fn ( array $m ): string => preg_match_all( '/"([^"]*)"|\'([^\']*)\'/', $m[0], $v ) ? ' ' . implode( ' ', array_map( static fn ( string $a, string $b ): string => $a . $b, $v[1], $v[2] ) ) . ' ' : ' ',
			$text
		);
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = strtolower( $text );
		// Bytes that are not UTF-8 still split on ASCII punctuation.
		if ( false === preg_match_all( '/[\p{L}\p{N}]+/u', $text, $m ) ) {
			preg_match_all( '/[a-z0-9\x80-\xff]+/', $text, $m );
		}
		return $m[0];
	}
}
