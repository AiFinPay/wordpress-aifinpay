<?php

declare(strict_types=1);

namespace AiFinPay\Core;

defined( 'ABSPATH' ) || exit;

/** Path patterns: an exact path or /prefix/*, matched as matchResource() in @aifinpay/gate. */
final class Paths {

	public const ALL = '/*';

	/**
	 * The page a spelling resolves to, as inspectPath in @aifinpay/gate reads it.
	 * Origins disagree on dot segments, so `ambiguous` marks those, a raw `#`, and a spelling
	 * still decoding after the last round. Anything else reads the same when read again.
	 *
	 * @return array{path: string, ambiguous: bool}
	 */
	public static function inspect( string $raw ): array {
		$target = explode( '?', $raw, 2 )[0];
		$path   = explode( '#', $target, 2 )[0] ?: '/';
		for ( $round = 0; $round < 3; $round++ ) {
			$next = self::decodeRound( $path );
			if ( $next === $path ) {
				break;
			}
			$path = $next;
		}
		$ambiguous = str_contains( $target, '#' ) || 1 === preg_match( '/[\x00-\x1f\x7f]/', $path ) || self::decodeRound( $path ) !== $path;
		$out       = [];
		foreach ( explode( '/', $path ) as $piece ) {
			$segment = explode( ';', $piece, 2 )[0];
			// Dropped with the parameter, an escaped mark would hide where a proxy that decodes ends the path.
			if ( 1 === preg_match( '/%(?:3f|23)/i', substr( $piece, strlen( $segment ) ) ) ) {
				$ambiguous = true;
			}
			if ( '' === $segment ) {
				continue;
			}
			if ( '.' === $segment || '..' === $segment ) {
				$ambiguous = true;
				if ( '..' === $segment ) {
					array_pop( $out );
				}
				continue;
			}
			$out[] = $segment;
		}
		$canonical = preg_replace_callback( '/[\x80-\xff]/', static fn ( array $m ): string => sprintf( '%%%02x', ord( $m[0] ) ), '/' . implode( '/', $out ) );
		return [ 'path' => strtolower( (string) $canonical ), 'ambiguous' => $ambiguous ];
	}

	/** `?` and `#` stay escaped: decoded, they would end the path the next time it is read. */
	private static function decodeRound( string $text ): string {
		$decoded = preg_replace_callback(
			'/%([0-9a-fA-F]{2})/',
			static function ( array $m ): string {
				$code = (int) hexdec( $m[1] );
				return $code < 0x80 && 0x3f !== $code && 0x23 !== $code ? chr( $code ) : $m[0];
			},
			$text
		);
		return str_replace( '\\', '/', (string) $decoded );
	}

	/** A proxy that decodes hands `/report%3Fx` on as `/report`, so a canonical path with an escaped mark may be either page. */
	public static function beforeMark( string $path ): ?string {
		return 1 === preg_match( '/%(?:3f|23)/i', $path, $found, PREG_OFFSET_CAPTURE ) ? self::inspect( substr( $path, 0, $found[0][1] ) )['path'] : null;
	}

	/** A receipt names a resource as the merchant spelled it: read it as a path is read, keeping a wildcard. */
	public static function spelled( string $resource ): string {
		if ( ! str_starts_with( $resource, '/' ) ) {
			return $resource;
		}
		$wild = str_ends_with( $resource, '/*' );
		$path = self::inspect( $wild ? substr( $resource, 0, -2 ) : $resource )['path'];
		return ! $wild ? $path : ( '/' === $path ? '/*' : $path . '/*' );
	}

	public static function validPattern( string $pattern ): bool {
		if ( self::ALL === $pattern ) {
			return true;
		}
		return strlen( $pattern ) <= 200
			&& preg_match( '~^/[a-z0-9_./-]+(?:/\*)?$~D', $pattern )
			&& ! str_contains( $pattern, '..' )
			&& ! str_contains( $pattern, '//' )
			&& ! str_ends_with( $pattern, '/' );
	}

	/** Lower-case, trailing slash off; invalid lines come back in `invalid`. */
	public static function parseLines( string $text ): array {
		$valid   = [];
		$invalid = [];
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) ?: [] as $line ) {
			$line = strtolower( trim( $line ) );
			if ( '' === $line ) {
				continue;
			}
			$pattern = self::ALL === $line || '/' === $line ? $line : rtrim( $line, '/' );
			if ( self::validPattern( $pattern ) ) {
				$valid[ $pattern ] = true;
			} else {
				$invalid[] = $line;
			}
		}
		return [ 'valid' => array_keys( $valid ), 'invalid' => $invalid ];
	}

	public static function covers( string $pattern, string $path ): bool {
		if ( ! str_ends_with( $pattern, '/*' ) ) {
			return $path === $pattern;
		}
		return $path === substr( $pattern, 0, -2 ) || str_starts_with( $path, substr( $pattern, 0, -1 ) );
	}

	/** An exact pattern covering the path, else the longest wildcard, or null. */
	public static function match( string $path, array $patterns ): ?string {
		$best = null;
		$rank = -1;
		foreach ( $patterns as $pattern ) {
			if ( ! is_string( $pattern ) || ! self::validPattern( $pattern ) || ! self::covers( $pattern, $path ) ) {
				continue;
			}
			$this_rank = str_ends_with( $pattern, '/*' ) ? strlen( $pattern ) : PHP_INT_MAX;
			if ( $this_rank > $rank ) {
				$best = $pattern;
				$rank = $this_rank;
			}
		}
		return $best;
	}

	/**
	 * The first spelling of this request that is protected and not excluded; failing
	 * that, the page before an escaped mark. `path` is what a receipt must cover.
	 *
	 * @param string[] $candidates The request path, then the canonical path of what it resolved to.
	 * @return array{rule: string, path: string}|null
	 */
	public static function resolve( array $candidates, array $protected, array $excluded ): ?array {
		return self::firstProtected( $candidates, $protected, $excluded )
			?? self::firstProtected( array_filter( array_map( [ self::class, 'beforeMark' ], $candidates ) ), $protected, $excluded );
	}

	private static function firstProtected( array $candidates, array $protected, array $excluded ): ?array {
		foreach ( $candidates as $path ) {
			if ( null !== self::match( $path, $excluded ) ) {
				return null;
			}
		}
		foreach ( $candidates as $path ) {
			$rule = self::match( $path, $protected );
			if ( null !== $rule ) {
				return [ 'rule' => $rule, 'path' => $path ];
			}
		}
		return null;
	}
}
