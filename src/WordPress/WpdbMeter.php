<?php

declare(strict_types=1);

namespace AiFinPay\WordPress;

defined( 'ABSPATH' ) || exit;

use AiFinPay\Core\Meter;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Metering must hit the database: a cache would refill a batch.

final class WpdbMeter implements Meter {

	public function spend( string $merchant, string $receiptId, int $weight, int $limit, int $expires ): ?int {
		global $wpdb;
		$table = Schema::units();
		$hash  = hash( 'sha256', $merchant . "\0" . $receiptId );
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			// One conditional UPDATE: of two concurrent calls only the one that
			// still fits under the limit changes the row, so a batch is never overspent.
			$updated = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET used = used + %d WHERE receipt_hash = %s AND used + %d <= %d', $table, $weight, $hash, $weight, $limit ) );
			if ( false === $updated ) {
				throw new \RuntimeException( 'meter_unavailable' );
			}
			if ( $updated > 0 ) {
				return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT used FROM %i WHERE receipt_hash = %s', $table, $hash ) );
			}
			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE receipt_hash = %s', $table, $hash ) );
			if ( null === $exists ) {
				throw new \RuntimeException( 'meter_unavailable' );
			}
			if ( (int) $exists > 0 || $weight > $limit ) {
				return null;
			}
			$inserted = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (receipt_hash, used, expires) VALUES (%s, %d, %d)', $table, $hash, $weight, $expires ) );
			if ( false === $inserted ) {
				throw new \RuntimeException( 'meter_unavailable' );
			}
			if ( $inserted > 0 ) {
				return $weight;
			}
			// A concurrent first spend created the row; meter against it.
		}
		return null;
	}

	public static function purgeExpired( int $now ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires < %d', Schema::units(), $now ) );
	}
}
