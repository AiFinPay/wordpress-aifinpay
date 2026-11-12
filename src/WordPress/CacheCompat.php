<?php

declare(strict_types=1);

namespace AiFinPay\WordPress;

defined( 'ABSPATH' ) || exit;

/** Keeps protected responses out of full-page caches and clears what was stored before the gate. */
final class CacheCompat {

	public const PURGE_ACTION = 'aifinpay_purge_caches';

	private static bool $forbidden = false;

	public static function forbid(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The constant page caches read.
		}
		do_action( 'litespeed_control_set_nocache', 'aifinpay' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own API.
		self::headers();
		if ( ! self::$forbidden ) {
			self::$forbidden = true;
			// Again as the headers go out, in case a later plugin set its own.
			header_register_callback( [ self::class, 'headers' ] );
		}
	}

	/** @internal */
	public static function headers(): void {
		header( 'Cache-Control: private, no-store, max-age=0' );
		// These outrank Cache-Control at the CDN they address.
		foreach ( [ 'CDN-Cache-Control', 'Surrogate-Control', 'Cloudflare-CDN-Cache-Control', 'Vercel-CDN-Cache-Control', 'Netlify-CDN-Cache-Control', 'Edge-Control', 'Akamai-Cache-Control' ] as $name ) {
			header( $name . ': no-store' );
		}
		header( 'X-Accel-Expires: 0' );
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		header( 'cf-edge-cache: no-cache' );
		header_remove( 'Expires' );
	}

	public static function purge(): void {
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
		do_action( 'litespeed_purge_all', 'aifinpay' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own API.
		do_action( 'aifinpay_purge_caches' );
	}

	/** The Cloudflare plugin subscribes to this list when it loads; hooked before plugins load in name order. */
	public static function cloudflareActions( array $actions ): array {
		$actions[] = self::PURGE_ACTION;
		return $actions;
	}

	/** @return string[] Names of the page caches this site runs. */
	public static function active(): array {
		$found = [];
		foreach ( [ 'WP_ROCKET_VERSION' => 'WP Rocket', 'W3TC' => 'W3 Total Cache', 'LSCWP_V' => 'LiteSpeed Cache', 'WPCACHEHOME' => 'WP Super Cache', 'CLOUDFLARE_PLUGIN_DIR' => 'Cloudflare' ] as $constant => $name ) {
			if ( defined( $constant ) ) {
				$found[] = $name;
			}
		}
		return $found;
	}
}
