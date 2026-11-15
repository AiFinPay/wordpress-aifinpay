<?php

declare(strict_types=1);

namespace AiFinPay\WordPress;

defined( 'ABSPATH' ) || exit;

use AiFinPay\Core\Paths;
use AiFinPay\Core\Pricing;

/** Plugin settings. Approval and charging host are deliberately not among them. */
final class Settings {

	public const OPTION = 'aifinpay_settings';

	public static function defaults(): array {
		return [
			'mode'                => 'shadow',
			'tier'                => 'standard',
			'protected_paths'     => [ Paths::ALL ],
			'path_tiers'          => [],
			'excluded_paths'      => [],
			'charge_alternates'   => true,
			'report_events'       => false,
			'discovery_documents' => true,
			'discovery_link'      => true,
		];
	}

	public static function get(): array {
		$stored = get_option( self::OPTION, [] );
		$s      = ( is_array( $stored ) ? $stored : [] ) + self::defaults();
		// Only the exact word turns charging on; anything else observes.
		$s['mode'] = in_array( $s['mode'], [ 'off', 'shadow', 'enforce' ], true ) ? $s['mode'] : 'shadow';
		$s['tier'] = Pricing::isTier( $s['tier'] ) ? $s['tier'] : 'standard';
		foreach ( [ 'protected_paths', 'excluded_paths' ] as $key ) {
			$s[ $key ] = is_array( $s[ $key ] ) ? array_values( array_filter( $s[ $key ], static fn ( $p ): bool => is_string( $p ) && Paths::validPattern( $p ) ) ) : [];
		}
		// A price of its own only for a path still listed, and only a known one.
		$s['path_tiers'] = is_array( $s['path_tiers'] ) ? array_filter( $s['path_tiers'], static fn ( $t, $p ): bool => in_array( $p, $s['protected_paths'], true ) && Pricing::isTier( $t ), ARRAY_FILTER_USE_BOTH ) : [];
		foreach ( [ 'charge_alternates', 'report_events', 'discovery_documents', 'discovery_link' ] as $key ) {
			$s[ $key ] = (bool) $s[ $key ];
		}
		return $s;
	}

	public static function save( array $settings ): void {
		update_option( self::OPTION, array_intersect_key( $settings, self::defaults() ), true );
	}

	/** The path of home_url(), '' for a site at the domain root. */
	public static function homePath(): string {
		$path = Paths::inspect( (string) wp_parse_url( home_url(), PHP_URL_PATH ) )['path'];
		return '/' === $path ? '' : $path;
	}

	public static function restPath(): string {
		return self::homePath() . '/' . strtolower( rest_get_url_prefix() );
	}

	/** The same content at other URLs: the REST content routes and the main feed. */
	public static function alternates(): array {
		return [ self::restPath() . '/wp/v2/*', self::homePath() . '/feed/*' ];
	}

	public static function patterns( array $settings ): array {
		if ( 'enforce' === $settings['mode'] && $settings['charge_alternates'] ) {
			return array_values( array_unique( [ ...$settings['protected_paths'], ...self::alternates() ] ) );
		}
		return $settings['protected_paths'];
	}

	/** Every gated pattern => its tier: the one written beside it, else the site-wide one. */
	public static function tiers( array $settings ): array {
		$tiers = [];
		foreach ( $settings['protected_paths'] as $pattern ) {
			$tiers[ $pattern ] = $settings['path_tiers'][ $pattern ] ?? $settings['tier'];
		}
		foreach ( self::patterns( $settings ) as $pattern ) {
			$tiers[ $pattern ] ??= self::alternatesTier( $settings );
		}
		return $tiers;
	}

	/** The REST content routes and the feed carry the posts of every page, so they cost what the dearest page path costs. */
	public static function alternatesTier( array $settings ): string {
		$dearest = null;
		foreach ( $settings['protected_paths'] as $pattern ) {
			$tier = $settings['path_tiers'][ $pattern ] ?? $settings['tier'];
			if ( ! str_starts_with( $pattern, self::restPath() . '/' ) && ( null === $dearest || Pricing::TIER_WEIGHTS[ $tier ] > Pricing::TIER_WEIGHTS[ $dearest ] ) ) {
				$dearest = $tier;
			}
		}
		return $dearest ?? $settings['tier'];
	}

	/** The Protected paths box as the owner wrote it: a path, then its own price if it has one. */
	public static function protectedLines( array $settings ): array {
		return array_map( static fn ( string $p ): string => isset( $settings['path_tiers'][ $p ] ) ? $p . ' ' . $settings['path_tiers'][ $p ] : $p, $settings['protected_paths'] );
	}
}
