<?php

declare(strict_types=1);

namespace AiFinPay\Core;

defined( 'ABSPATH' ) || exit;

/** The public merchant record (GET /v1/merchants/{id}): where charging is allowed and whether it is approved. */
final class MerchantRecord {

	public const RE_MERCHANT = '/^mrch_[a-z0-9_]{3,32}$/D';

	/** The validated subset this plugin uses; null for anything else. */
	public static function fromResponse( array $body, string $merchant, int $now ): ?array {
		if ( ( $body['merchant_id'] ?? null ) !== $merchant ) {
			return null;
		}
		$mode = $body['network_mode'] ?? 'live';
		if ( ! in_array( $mode, [ 'live', 'test' ], true ) ) {
			return null;
		}
		$url  = $body['site_url'] ?? null;
		$host = is_string( $url ) ? self::host( $url ) : null;
		$name = $body['name'] ?? null;
		return [
			'merchant_id'   => $merchant,
			'name'          => is_string( $name ) ? mb_substr( $name, 0, 120 ) : null,
			'network_mode'  => $mode,
			'site_url'      => null !== $host ? $url : null,
			'site_host'     => $host,
			'site_verified' => true === ( $body['site_verified'] ?? null ),
			'fetched_at'    => $now,
		];
	}

	/** Lower-case host of an http(s) URL without credentials; null otherwise. */
	public static function host( string $url ): ?string {
		if ( strlen( $url ) > 2048 || ! preg_match( '~^https?://~i', $url ) ) {
			return null;
		}
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Core stays free of WordPress calls.
		if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || ! isset( $parts['host'] ) ) {
			return null;
		}
		$host = rtrim( strtolower( $parts['host'] ), '.' );
		return preg_match( '/^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$/D', $host ) && ! str_contains( $host, '..' ) ? $host : null;
	}

	/**
	 * Host = the record's site_url. Approved = a test merchant, or a live one with a verified domain.
	 *
	 * @return array{approved: bool, host: ?string, network_mode: ?string, reason: string}
	 */
	public static function charging( string $merchant, ?array $record ): array {
		$own = null !== $record && '' !== $merchant && ( $record['merchant_id'] ?? null ) === $merchant;
		if ( ! $own ) {
			return [
				'approved'     => false,
				'host'         => null,
				'network_mode' => null,
				'reason'       => '' === $merchant ? 'not_connected' : 'record_unavailable',
			];
		}
		$host = is_string( $record['site_host'] ?? null ) ? $record['site_host'] : null;
		$mode = $record['network_mode'];
		if ( null === $host ) {
			$reason = 'no_site_url';
		} elseif ( 'test' === $mode ) {
			$reason = 'test_merchant';
		} else {
			$reason = true === $record['site_verified'] ? 'live_domain_verified' : 'live_domain_unverified';
		}
		return [
			'approved'     => in_array( $reason, [ 'test_merchant', 'live_domain_verified' ], true ),
			'host'         => $host,
			'network_mode' => $mode,
			'reason'       => $reason,
		];
	}
}
