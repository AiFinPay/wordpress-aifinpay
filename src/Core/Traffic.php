<?php

declare(strict_types=1);

namespace AiFinPay\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Five-minute traffic summaries as AiFinPay takes them: what is counted, the
 * rows one request adds, and the body of a closed window. The bytes of the
 * shared vectors conformance/vectors/gate-traffic-v1.json.
 */
final class Traffic {

	public const WINDOW       = 300;
	public const MAX_ROWS     = 600;
	public const MAX_COUNT    = 1000000000;
	public const MAX_DISTINCT = 1000;
	public const CAPS         = [ 'sections' => 30, 'countries' => 20, 'networks' => 50 ];
	public const TABLES       = [ 'classes', 'operators', 'sections', 'countries', 'networks' ];
	public const OUTCOMES     = [ 'served', 'challenged', 'paid', 'blocked', 'error' ];
	public const EDGE_HEADER  = 'AIFP-Edge-Counted';

	public const LANGUAGE_PREFIXES = [
		'ar', 'bg', 'bn', 'ca', 'cs', 'da', 'de', 'el', 'en', 'es', 'et', 'fa', 'fi', 'fr', 'he', 'hi', 'hr', 'hu', 'id', 'it',
		'ja', 'ko', 'lt', 'lv', 'ms', 'nl', 'no', 'pl', 'pt', 'ro', 'ru', 'sk', 'sl', 'sr', 'sv', 'th', 'tr', 'uk', 'vi', 'zh',
	];

	public const ASSET_EXTENSIONS = [
		'avif', 'bmp', 'css', 'eot', 'gif', 'ico', 'jpeg', 'jpg', 'js', 'map', 'mjs',
		'mp3', 'mp4', 'otf', 'png', 'svg', 'ttf', 'wasm', 'webm', 'webp', 'woff', 'woff2',
	];

	private const UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
	private const LOWER = 'abcdefghijklmnopqrstuvwxyz';

	private static function lower( string $s ): string {
		return strtr( $s, self::UPPER, self::LOWER );
	}

	private static function pathOf( string $target ): string {
		return (string) preg_split( '/[?#]/', $target, 2 )[0];
	}

	/** The first segment of the request target, as the SPEC's section table. */
	public static function sectionOf( string $target ): string {
		$segments = array_values( array_map( [ self::class, 'lower' ], array_filter( explode( '/', self::pathOf( $target ) ), static fn ( string $s ): bool => '' !== $s ) ) );
		$language = '/^(?:' . implode( '|', self::LANGUAGE_PREFIXES ) . ')(?:[-_][a-z]{2,4})?$/D';
		$index    = $segments && 1 === preg_match( $language, $segments[0] ) ? 1 : 0;
		if ( ! isset( $segments[ $index ] ) ) {
			return '/';
		}
		$segment = $segments[ $index ];
		return 1 === preg_match( '/^[a-z0-9][a-z0-9_-]{0,31}$/D', $segment ) && preg_match_all( '/[0-9]/', $segment ) <= 4 ? '/' . $segment : '_other';
	}

	/** A page's images and scripts served free are not visitors. */
	public static function counted( string $target, string $outcome ): bool {
		if ( 'served' !== $outcome ) {
			return true;
		}
		$path = self::pathOf( $target );
		$last = self::lower( substr( $path, (int) strrpos( $path, '/' ) + ( str_contains( $path, '/' ) ? 1 : 0 ) ) );
		foreach ( self::ASSET_EXTENSIONS as $ext ) {
			if ( str_ends_with( $last, '.' . $ext ) ) {
				return false;
			}
		}
		return true;
	}

	/** @param list<string> $lines The field lines of AIFP-Edge-Counted: exactly one, 1 after trimming. */
	public static function edgeCounted( array $lines ): bool {
		return 1 === count( $lines ) && is_string( $lines[0] ?? null ) && '1' === trim( $lines[0], " \t" );
	}

	public static function countryOf( mixed $value ): string {
		return is_string( $value ) && 1 === preg_match( '/^[A-Za-z]{2}$/D', $value ) && 'XX' !== strtoupper( $value ) ? strtoupper( $value ) : 'ZZ';
	}

	public static function networkOf( mixed $value ): string {
		$n = null;
		if ( is_int( $value ) ) {
			$n = $value;
		} elseif ( is_string( $value ) && 1 === preg_match( '/^(?:AS|as)?(0|[1-9][0-9]{0,9})$/D', $value, $m ) ) {
			$n = (int) $m[1];
		}
		return null !== $n && $n >= 1 && $n <= 4294967295 ? 'AS' . $n : 'AS0';
	}

	/**
	 * The rows one request adds, or null when it is not counted. Country and
	 * network only when the site has a source for them; never network for a reader.
	 *
	 * @param array{target: string, ua_class: string, operator: string, outcome: string} $r
	 * @return ?list<array{0: string, 1: list<string>}> [table, cells]
	 */
	public static function rows( array $r, bool $country = false, mixed $countryValue = null, bool $network = false, mixed $networkValue = null ): ?array {
		if ( ! self::counted( $r['target'], $r['outcome'] ) ) {
			return null;
		}
		$agent = 'human' !== $r['ua_class'];
		$rows  = [ [ 'classes', [ $r['ua_class'], $r['outcome'] ] ] ];
		if ( $agent ) {
			$rows[] = [ 'operators', [ $r['operator'], $r['ua_class'], $r['outcome'] ] ];
			$rows[] = [ 'sections', [ self::sectionOf( $r['target'] ), $r['ua_class'] ] ];
		}
		if ( $country ) {
			$rows[] = [ 'countries', [ self::countryOf( $countryValue ), $r['ua_class'] ] ];
		}
		if ( $agent && $network ) {
			$rows[] = [ 'networks', [ self::networkOf( $networkValue ), $r['ua_class'] ] ];
		}
		return $rows;
	}

	/** The outcome of a gate decision, whatever the mode: shadow reports what it served past. */
	public static function outcome( array $decision ): string {
		$refusals = [ 402 => 'challenged', 403 => 'blocked', 503 => 'error' ];
		$shadow   = $decision['headers']['AIFP-Shadow-Status'] ?? null;
		if ( null !== $shadow ) {
			return $refusals[ (int) $shadow ] ?? 'blocked';
		}
		if ( 200 === $decision['status'] ) {
			return 'serve' === ( $decision['event']['kind'] ?? null ) ? 'paid' : 'served';
		}
		return $refusals[ $decision['status'] ] ?? 'blocked';
	}

	/**
	 * The request body of a closed window, or null when nothing was counted.
	 *
	 * @param array{id: string, from: string, to: string, mode: string, charge: string, sample: int} $window
	 * @param array<string, list<array>> $tables Rows of cells then a count; the same cells may repeat.
	 */
	public static function build( array $window, array $tables ): ?string {
		$rows = [];
		foreach ( self::TABLES as $name ) {
			$sums = [];
			foreach ( $tables[ $name ] ?? [] as $row ) {
				$n                       = (int) array_pop( $row );
				$key                     = implode( "\0", $row );
				$sums[ $key ]            = ( $sums[ $key ] ?? 0 ) + $n * $window['sample'];
			}
			if ( isset( self::CAPS[ $name ] ) ) {
				$sums = self::cap( $sums, self::CAPS[ $name ] );
			}
			$out = [];
			foreach ( $sums as $key => $n ) {
				$n = min( $n, self::MAX_COUNT );
				if ( $n > 0 ) {
					$out[] = [ ...explode( "\0", (string) $key ), $n ];
				}
			}
			usort( $out, [ self::class, 'byCells' ] );
			$rows[ $name ] = $out;
		}
		if ( ! $rows['classes'] ) {
			return null;
		}
		$total = array_sum( array_map( 'count', $rows ) );
		foreach ( [ 'networks', 'countries', 'sections', 'operators' ] as $name ) {
			if ( $total <= self::MAX_ROWS ) {
				break;
			}
			$total         -= count( $rows[ $name ] );
			$rows[ $name ]  = [];
		}
		$body = [
			'v'       => 1,
			'id'      => $window['id'],
			'from'    => $window['from'],
			'to'      => $window['to'],
			'mode'    => $window['mode'],
			'charge'  => $window['charge'],
			'sample'  => $window['sample'],
			'classes' => $rows['classes'],
		];
		foreach ( array_slice( self::TABLES, 1 ) as $name ) {
			if ( $rows[ $name ] ) {
				$body[ $name ] = $rows[ $name ];
			}
		}
		return Json::encode( $body );
	}

	/** Every value past the cap goes into _other, per class; ties by name. */
	private static function cap( array $sums, int $limit ): array {
		$totals = [];
		foreach ( $sums as $key => $n ) {
			$value = explode( "\0", (string) $key )[0];
			if ( '_other' !== $value ) {
				$totals[ $value ] = ( $totals[ $value ] ?? 0 ) + $n;
			}
		}
		uksort( $totals, static fn ( $a, $b ): int => $totals[ $b ] <=> $totals[ $a ] ?: strcmp( (string) $a, (string) $b ) );
		$kept = array_flip( array_map( 'strval', array_slice( array_keys( $totals ), 0, $limit ) ) );
		$out  = [];
		foreach ( $sums as $key => $n ) {
			$cells = explode( "\0", (string) $key );
			if ( '_other' !== $cells[0] && ! isset( $kept[ $cells[0] ] ) ) {
				$cells[0] = '_other';
			}
			$other         = implode( "\0", $cells );
			$out[ $other ] = ( $out[ $other ] ?? 0 ) + $n;
		}
		return $out;
	}

	private static function byCells( array $a, array $b ): int {
		$cells = count( $a ) - 1;
		for ( $i = 0; $i < $cells; $i++ ) {
			$c = strcmp( (string) $a[ $i ], (string) $b[ $i ] );
			if ( 0 !== $c ) {
				return $c < 0 ? -1 : 1;
			}
		}
		return 0;
	}
}
