<?php

declare(strict_types=1);

namespace AiFinPay\WordPress;

defined( 'ABSPATH' ) || exit;

use AiFinPay\Core\Pricing;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Counters on the plugin's own table.

/** The local report: agent requests per day and what was charged. */
final class TrafficLog {

	public const KEEP_DAYS = 35;

	public static function record( string $resource, string $operator, string $class, string $outcome, int $units ): void {
		global $wpdb;
		$table = Schema::traffic();
		$day   = gmdate( 'Y-m-d' );
		$key   = md5( implode( "\0", [ $day, $resource, $operator, $class, $outcome ] ) );
		if ( $wpdb->query( $wpdb->prepare( 'UPDATE %i SET requests = requests + 1, units = units + %d WHERE row_key = %s', $table, $units, $key ) ) ) {
			return;
		}
		$inserted = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (row_key, day, resource, operator, ua_class, outcome, requests, units) VALUES (%s, %s, %s, %s, %s, %s, 1, %d)', $table, $key, $day, $resource, $operator, $class, $outcome, $units ) );
		if ( 0 === $inserted ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET requests = requests + 1, units = units + %d WHERE row_key = %s', $table, $units, $key ) );
		}
	}

	/** @return array<int, array{operator: string, ua_class: string, observed: int, challenged: int, paid: int, charged: string}> */
	public static function report( int $days = 30 ): array {
		global $wpdb;
		$since = gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT operator, ua_class, outcome, SUM(requests) AS requests, SUM(units) AS units FROM %i WHERE day >= %s GROUP BY operator, ua_class, outcome', Schema::traffic(), $since ), ARRAY_A );
		$out   = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$id                 = $row['operator'] . '|' . $row['ua_class'];
			$out[ $id ]       ??= [ 'operator' => $row['operator'], 'ua_class' => $row['ua_class'], 'observed' => 0, 'challenged' => 0, 'paid' => 0, 'paid_units' => 0 ];
			if ( 'shadow' === $row['outcome'] ) {
				$out[ $id ]['observed'] += (int) $row['requests'];
			} elseif ( '402' === $row['outcome'] ) {
				$out[ $id ]['challenged'] += (int) $row['requests'];
			} elseif ( 'paid' === $row['outcome'] ) {
				$out[ $id ]['paid']       += (int) $row['requests'];
				$out[ $id ]['paid_units'] += (int) $row['units'];
			}
		}
		$out = array_map(
			static fn ( array $r ): array => [
				'operator'     => $r['operator'],
				'ua_class'     => $r['ua_class'],
				'observed'     => $r['observed'],
				'challenged'   => $r['challenged'],
				'paid'         => $r['paid'],
				'charged'      => Pricing::unitsToUsd( $r['paid_units'] ),
			],
			array_values( $out )
		);
		usort( $out, static fn ( array $a, array $b ): int => ( $b['observed'] + $b['challenged'] + $b['paid'] ) <=> ( $a['observed'] + $a['challenged'] + $a['paid'] ) );
		return $out;
	}

	public static function purgeOld( int $now ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE day < %s', Schema::traffic(), gmdate( 'Y-m-d', $now - self::KEEP_DAYS * DAY_IN_SECONDS ) ) );
	}
}
