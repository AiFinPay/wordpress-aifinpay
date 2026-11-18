<?php

declare(strict_types=1);

namespace AiFinPay\Core;

defined( 'ABSPATH' ) || exit;

/** The platform's DNS. */
final class SystemResolver implements Resolver {

	public function reverse( string $ip ): array {
		$packed = inet_pton( $ip );
		if ( false === $packed ) {
			throw new \RuntimeException( 'not_an_address' );
		}
		if ( 4 === strlen( $packed ) ) {
			$arpa = implode( '.', array_reverse( explode( '.', $ip ) ) ) . '.in-addr.arpa';
		} else {
			$arpa = implode( '.', array_reverse( str_split( bin2hex( $packed ) ) ) ) . '.ip6.arpa';
		}
		return array_values( array_filter( array_map( static fn ( array $r ): mixed => $r['target'] ?? null, self::records( $arpa, DNS_PTR ) ), 'is_string' ) );
	}

	public function forward( string $name, int $family ): array {
		$field = 4 === $family ? 'ip' : 'ipv6';
		return array_values( array_filter( array_map( static fn ( array $r ): mixed => $r[ $field ] ?? null, self::records( $name, 4 === $family ? DNS_A : DNS_AAAA ) ), 'is_string' ) );
	}

	private static function records( string $name, int $type ): array {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed lookup is an answer here, not a warning.
		$records = @dns_get_record( $name, $type );
		if ( ! is_array( $records ) ) {
			throw new \RuntimeException( 'dns_unavailable' );
		}
		return $records;
	}
}
