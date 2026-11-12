<?php

declare(strict_types=1);

namespace AiFinPay\WordPress;

defined( 'ABSPATH' ) || exit;

/** The plugin's tables. No IP, no User-Agent, no raw path, no receipt token. */
final class Schema {

	public const VERSION = '1';
	public const OPTION  = 'aifinpay_db_version';

	public static function units(): string {
		global $wpdb;
		return $wpdb->prefix . 'aifinpay_receipt_units';
	}

	public static function events(): string {
		global $wpdb;
		return $wpdb->prefix . 'aifinpay_gate_events';
	}

	public static function traffic(): string {
		global $wpdb;
		return $wpdb->prefix . 'aifinpay_traffic';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			'CREATE TABLE ' . self::units() . " (
receipt_hash char(64) NOT NULL,
used bigint(20) NOT NULL DEFAULT 0,
expires bigint(20) NOT NULL,
PRIMARY KEY  (receipt_hash),
KEY expires (expires)
) $charset;"
		);
		dbDelta(
			'CREATE TABLE ' . self::events() . " (
id char(36) NOT NULL,
payload text NOT NULL,
created bigint(20) NOT NULL,
PRIMARY KEY  (id),
KEY created (created)
) $charset;"
		);
		dbDelta(
			'CREATE TABLE ' . self::traffic() . " (
row_key char(32) NOT NULL,
day date NOT NULL,
resource varchar(200) NOT NULL,
operator varchar(32) NOT NULL,
ua_class varchar(32) NOT NULL,
outcome varchar(16) NOT NULL,
requests bigint(20) NOT NULL DEFAULT 0,
units bigint(20) NOT NULL DEFAULT 0,
PRIMARY KEY  (row_key),
KEY day (day)
) $charset;"
		);
		update_option( self::OPTION, self::VERSION, true );
	}

	public static function drop(): void {
		global $wpdb;
		foreach ( [ self::units(), self::events(), self::traffic() ] as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall removes the plugin's own tables.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
		delete_option( self::OPTION );
	}
}
