<?php

declare(strict_types=1);

namespace AiFinPay\WordPress;

defined( 'ABSPATH' ) || exit;

/** Activation, upgrades, scheduled work and removal. */
final class Maintenance {

	public const CRON_EVENTS = 'aifinpay_flush_events';
	public const CRON_DAILY  = 'aifinpay_housekeeping';
	public const SCHEDULE    = 'aifinpay_five_minutes';

	public const OPTIONS = [
		Settings::OPTION,
		Connection::OPTION,
		RecordStore::LAST,
		Schema::OPTION,
		EventDelivery::LOCK,
		EventDelivery::STATE,
		Resources::STATE,
	];

	public const TRANSIENTS = [ RecordStore::TRANSIENT, WpKeySet::TRANSIENT, WpKeySet::HOLD ];

	public static function activate(): void {
		Schema::install();
		add_option( Settings::OPTION, Settings::defaults(), '', true );
		self::schedule();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_EVENTS );
		wp_clear_scheduled_hook( self::CRON_DAILY );
	}

	/** Also covers updates, which run no activation hook, and sites of a network activation. */
	public static function maybeUpgrade(): void {
		if ( Schema::VERSION !== get_option( Schema::OPTION ) ) {
			self::activate();
		}
	}

	public static function addSchedule( array $schedules ): array {
		$schedules[ self::SCHEDULE ] = [ 'interval' => 5 * MINUTE_IN_SECONDS, 'display' => __( 'Every five minutes (AiFinPay)', 'aifinpay' ) ];
		return $schedules;
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_EVENTS ) ) {
			wp_schedule_event( time() + 60, self::SCHEDULE, self::CRON_EVENTS );
		}
		if ( ! wp_next_scheduled( self::CRON_DAILY ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::CRON_DAILY );
		}
	}

	public static function housekeeping(): void {
		$now = time();
		WpdbMeter::purgeExpired( $now );
		TrafficLog::purgeOld( $now );
		Resources::retryIfNeeded( Settings::get() );
	}

	/** Removes everything this plugin stored on the current site. */
	public static function removeSiteData(): void {
		self::deactivate();
		Schema::drop();
		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}
		foreach ( self::TRANSIENTS as $transient ) {
			delete_transient( $transient );
		}
		global $wpdb;
		// Per-user admin notices have no fixed name.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Removal of the plugin's own rows.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_aifinpay_' ) . '%', $wpdb->esc_like( '_transient_timeout_aifinpay_' ) . '%' ) );
	}
}
