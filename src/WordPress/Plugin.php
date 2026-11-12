<?php

declare(strict_types=1);

namespace AiFinPay\WordPress;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public static function boot( string $file ): void {
		add_filter( 'cloudflare_purge_everything_actions', [ CacheCompat::class, 'cloudflareActions' ] );
		add_filter( 'cron_schedules', [ Maintenance::class, 'addSchedule' ] ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- Delivery of a small batch.
		add_action( 'plugins_loaded', [ Maintenance::class, 'maybeUpgrade' ] );
		add_action( 'init', [ Maintenance::class, 'schedule' ] );
		add_action( Maintenance::CRON_EVENTS, [ EventDelivery::class, 'run' ], 10, 0 );
		add_action( Maintenance::CRON_DAILY, [ Maintenance::class, 'housekeeping' ] );

		add_action( 'parse_request', [ FrontGate::class, 'onParseRequest' ], 1, 0 );
		add_filter( 'wp_headers', [ FrontGate::class, 'filterHeaders' ] );
		// After redirect_canonical and wp_old_slug_redirect at 10: a request that ends in a 301 meters nothing.
		add_action( 'template_redirect', [ FrontGate::class, 'onTemplateRedirect' ], 20, 0 );
		add_action( 'rest_api_init', [ FrontGate::class, 'onRestInit' ], 0, 0 );
		add_action( 'shutdown', [ EventDelivery::class, 'fallback' ], 100, 0 );

		if ( is_admin() ) {
			Admin::register( $file );
		}
	}
}
