<?php

declare(strict_types=1);

namespace AiFinPay\Core;

defined( 'ABSPATH' ) || exit;

/** Forward-confirmed reverse DNS for a crawler naming a search operator, as verifySearchBot() in @aifinpay/gate. */
final class SearchBot {

	public const TTL_VERIFIED = 86400;
	public const TTL_REFUSED  = 3600;
	public const TTL_ERROR    = 60;

	/** Operator => product tokens that claim it, PTR suffixes with their leading dot, category. */
	public const TABLE = [
		'google'    => [ [ 'googlebot', 'googlebot-image', 'googlebot-news', 'googlebot-video' ], [ '.googlebot.com' ], 'search' ],
		'microsoft' => [ [ 'bingbot' ], [ '.search.msn.com' ], 'search' ],
		'apple'     => [ [ 'applebot' ], [ '.applebot.apple.com' ], 'search' ],
		'yandex'    => [ [ 'yandexbot', 'yandeximages', 'yandexmobilebot' ], [ '.yandex.ru', '.yandex.net', '.yandex.com' ], 'search' ],
		'baidu'     => [ [ 'baiduspider', 'baiduspider-image', 'baiduspider-render' ], [ '.baidu.com', '.baidu.jp' ], 'search' ],
	];

	/** The one operator the User-Agent claims by a whole product token; none when it carries an AI-agent marker or claims two. */
	public static function claimed( string $user_agent ): ?string {
		$ua = strtolower( $user_agent );
		foreach ( array_keys( AgentIdentity::UA_TABLE ) as $marker ) {
			if ( str_contains( $ua, $marker ) ) {
				return null;
			}
		}
		$tokens  = array_flip( preg_split( '/[^a-z0-9-]+/', $ua ) );
		$claimed = array_keys( array_filter( self::TABLE, static fn ( array $e ): bool => [] !== array_intersect_key( array_flip( $e[0] ), $tokens ) ) );
		return 1 === count( $claimed ) ? $claimed[0] : null;
	}

	/** Canonical text of an address, IPv4-mapped IPv6 as IPv4; null when not one. */
	public static function normaliseIp( string $ip ): ?string {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return null;
		}
		$packed = (string) inet_pton( $ip );
		if ( 16 === strlen( $packed ) && str_starts_with( $packed, str_repeat( "\0", 10 ) . "\xff\xff" ) ) {
			$packed = substr( $packed, 12 );
		}
		return (string) inet_ntop( $packed );
	}

	/**
	 * Null when nothing is claimed or the address is not one (no lookup, nothing to cache).
	 *
	 * @return array{category: ?string, ttl: int}|null
	 */
	public static function verify( string $user_agent, string $ip, Resolver $dns ): ?array {
		$address  = self::normaliseIp( $ip );
		$operator = self::claimed( $user_agent );
		if ( null === $address || null === $operator ) {
			return null;
		}
		try {
			$ok = self::forwardConfirmed( $dns, $address, self::TABLE[ $operator ][1] );
		} catch ( \Throwable ) {
			return [ 'category' => null, 'ttl' => self::TTL_ERROR ];
		}
		return $ok
			? [ 'category' => self::TABLE[ $operator ][2], 'ttl' => self::TTL_VERIFIED ]
			: [ 'category' => null, 'ttl' => self::TTL_REFUSED ];
	}

	private static function forwardConfirmed( Resolver $dns, string $ip, array $suffixes ): bool {
		$family = str_contains( $ip, ':' ) ? 6 : 4;
		foreach ( array_slice( $dns->reverse( $ip ), 0, 8 ) as $raw ) {
			if ( ! is_string( $raw ) || strlen( $raw ) > 254 ) {
				continue;
			}
			$name = rtrim( strtolower( $raw ), '.' );
			if ( 1 !== preg_match( '/^[a-z0-9-]+(\.[a-z0-9-]+)+$/D', $name ) ) {
				continue;
			}
			if ( [] === array_filter( $suffixes, static fn ( string $s ): bool => str_ends_with( $name, $s ) ) ) {
				continue;
			}
			foreach ( array_slice( $dns->forward( $name, $family ), 0, 32 ) as $address ) {
				if ( is_string( $address ) && self::normaliseIp( $address ) === $ip ) {
					return true;
				}
			}
		}
		return false;
	}
}
