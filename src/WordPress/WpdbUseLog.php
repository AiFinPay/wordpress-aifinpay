<?php

declare(strict_types=1);

namespace AiFinPay\WordPress;

defined( 'ABSPATH' ) || exit;

use AiFinPay\Core\ReceiptUse;
use AiFinPay\Core\UseLog;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The plugin's own table, read and written per spend.

/** Lowest units left per receipt id, waiting for UseDelivery. Never the receipt token. */
final class WpdbUseLog implements UseLog {

	public function record( string $receiptId, int $remaining ): void {
		global $wpdb;
		if ( ! ReceiptUse::valid( $receiptId, $remaining ) ) {
			return;
		}
		$table = Schema::uses();
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$lowered = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET remaining = %d, attempts = 0 WHERE receipt_id = %s AND remaining > %d', $table, $remaining, $receiptId, $remaining ) );
			if ( false === $lowered ) {
				return;
			}
			if ( $lowered > 0 || (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE receipt_id = %s', $table, $receiptId ) ) > 0 ) {
				return;
			}
			if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) >= ReceiptUse::MAX_HELD ) {
				return;
			}
			if ( $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (receipt_id, remaining, attempts) VALUES (%s, %d, 0)', $table, $receiptId, $remaining ) ) > 0 ) {
				return;
			}
			// A concurrent first record created the row; lower it instead.
		}
	}

	/** @return array<string, int> */
	public static function held(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT receipt_id, remaining FROM %i', Schema::uses() ), ARRAY_A );
		$held = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$held[ (string) $row['receipt_id'] ] = (int) $row['remaining'];
		}
		return $held;
	}

	/** Forget what AiFinPay took, unless a lower remaining arrived meanwhile. */
	public static function sent( array $uses ): void {
		global $wpdb;
		foreach ( $uses as $use ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE receipt_id = %s AND remaining = %d', Schema::uses(), $use['receipt_id'], $use['remaining'] ) );
		}
	}

	/** One more failed run; what failed MAX_ATTEMPTS runs is dropped. */
	public static function failed(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET attempts = attempts + 1', Schema::uses() ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE attempts >= %d', Schema::uses(), ReceiptUse::MAX_ATTEMPTS ) );
	}

	public static function clear(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Schema::uses() ) );
	}
}
