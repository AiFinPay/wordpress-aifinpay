<?php

declare(strict_types=1);

namespace AiFinPay\WordPress;

defined( 'ABSPATH' ) || exit;

use AiFinPay\Core\AgentIdentity;
use AiFinPay\Core\GateIdentity;
use AiFinPay\Core\Request;
use AiFinPay\Core\Traffic;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Counters on the plugin's own table.

/**
 * The five-minute windows sent to AiFinPay as traffic summaries, counted in
 * the plugin's own table after the response. Not the local report (TrafficLog).
 *
 * A window is the rows sharing one window_from, mode and charge; a request
 * joins the earliest one opened less than five minutes ago, or opens one.
 */
final class TrafficCounter {

	public const CRON_OVERDUE = 600;

	/** Closed this long after its five minutes, so a request still writing into it is not cut off. */
	public const GRACE = 10;

	private static ?array $seen = null;

	/** What the gate decided for this request; written on shutdown, never here. */
	public static function note( Request $request, string $outcome, ?array $event, array $settings ): void {
		if ( null !== self::$seen || ! $settings['report_traffic'] || 'off' === $settings['mode'] ) {
			return;
		}
		$who        = null !== $event ? $event : AgentIdentity::classify( $request );
		self::$seen = [
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only its first segment is kept, and only when it matches the section pattern.
			'target'   => isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/',
			'ua_class' => (string) $who['ua_class'],
			'operator' => (string) $who['operator'],
			'outcome'  => $outcome,
			'edge'     => self::header( Traffic::EDGE_HEADER ),
			'country'  => '' !== $settings['country_header'] ? self::header( $settings['country_header'] ) : false,
			'network'  => '' !== $settings['network_header'] ? self::header( $settings['network_header'] ) : false,
			'mode'     => GateIdentity::mode( $settings['mode'] ),
			'charge'   => GateIdentity::charge( Settings::readers( $settings ) ),
		];
	}

	/** @return ?string The header as PHP received it, null when absent. */
	private static function header( string $name ): ?string {
		$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared with fixed patterns, never stored as sent.
		return isset( $_SERVER[ $key ] ) ? (string) wp_unslash( $_SERVER[ $key ] ) : null;
	}

	/** On shutdown, after the response has gone where the server allows it. A failure is swallowed. */
	public static function write( ?callable $finish = null, ?callable $spawn = null, ?int $now = null ): void {
		$seen       = self::$seen;
		self::$seen = null;
		if ( null === $seen ) {
			return;
		}
		try {
			if ( Traffic::edgeCounted( null === $seen['edge'] ? [] : [ $seen['edge'] ] ) || null === Connection::get() ) {
				return;
			}
			$finish ??= self::finisher();
			if ( null !== $finish ) {
				$finish();
			}
			$now  ??= time();
			$oldest = self::count( $seen, $now );
			// WP-Cron does not run with DISABLE_WP_CRON or without traffic.
			if ( null !== $oldest && $now - $oldest >= Traffic::WINDOW + self::CRON_OVERDUE ) {
				if ( null === $finish ) {
					( $spawn ?? 'spawn_cron' )();
				} else {
					TrafficDelivery::run( $now );
				}
			}
		} catch ( \Throwable ) {
			return;
		}
	}

	private static function finisher(): ?callable {
		foreach ( [ 'fastcgi_finish_request', 'litespeed_finish_request' ] as $function ) {
			if ( function_exists( $function ) ) {
				return $function;
			}
		}
		return null;
	}

	/**
	 * One small set of increments.
	 *
	 * @return ?int The oldest window still in the table.
	 */
	public static function count( array $seen, int $now ): ?int {
		global $wpdb;
		$table = Schema::trafficWindows();
		$found = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT MIN(CASE WHEN window_from > %d AND mode = %s AND charge = %s THEN window_from END) AS open_from, MIN(window_from) AS oldest FROM %i',
				$now - Traffic::WINDOW,
				$seen['mode'],
				$seen['charge'],
				$table
			),
			ARRAY_A
		);
		$oldest = isset( $found['oldest'] ) ? (int) $found['oldest'] : null;
		$from   = isset( $found['open_from'] ) ? (int) $found['open_from'] : $now;
		$rows   = Traffic::rows( $seen, false !== $seen['country'], $seen['country'], false !== $seen['network'], $seen['network'] );
		if ( null === $rows ) {
			return $oldest;
		}
		$rows   = self::bounded( $rows, $from, $seen );
		$values = [];
		foreach ( $rows as [ $name, $cells ] ) {
			array_push( $values, $from, $seen['mode'], $seen['charge'], $name, $cells[0], $cells[1], $cells[2] ?? '' );
		}
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- One placeholder group per row, all prepared.
				'INSERT INTO %i (window_from, mode, charge, tbl, k1, k2, k3, n) VALUES ' . implode( ', ', array_fill( 0, count( $rows ), '(%d, %s, %s, %s, %s, %s, %s, 1)' ) ) . ' ON DUPLICATE KEY UPDATE n = n + 1',
				$table,
				...$values
			)
		);
		return $oldest ?? $from;
	}

	/** A crawler with random paths or networks adds to _other once a window holds MAX_DISTINCT of them. */
	private static function bounded( array $rows, int $from, array $seen ): array {
		$values = [];
		foreach ( $rows as [ $name, $cells ] ) {
			if ( 'sections' === $name || 'networks' === $name ) {
				$values[ $name ] = $cells[0];
			}
		}
		if ( ! $values ) {
			return $rows;
		}
		global $wpdb;
		$held = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tbl, COUNT(DISTINCT k1) AS distinct_values, MAX(CASE WHEN (tbl = 'sections' AND k1 = %s) OR (tbl = 'networks' AND k1 = %s) THEN 1 ELSE 0 END) AS known FROM %i WHERE window_from = %d AND mode = %s AND charge = %s AND tbl IN ('sections', 'networks') GROUP BY tbl",
				$values['sections'] ?? '',
				$values['networks'] ?? '',
				Schema::trafficWindows(),
				$from,
				$seen['mode'],
				$seen['charge']
			),
			ARRAY_A
		);
		$full = [];
		foreach ( is_array( $held ) ? $held : [] as $row ) {
			$full[ (string) $row['tbl'] ] = (int) $row['distinct_values'] >= Traffic::MAX_DISTINCT && 0 === (int) $row['known'];
		}
		foreach ( $rows as $i => [ $name, $cells ] ) {
			if ( $full[ $name ] ?? false ) {
				$rows[ $i ][1][0] = '_other';
			}
		}
		return $rows;
	}

	/**
	 * Every window whose five minutes are over, as bodies to send, oldest first; their rows are deleted.
	 * With $all the open ones close too, ending now.
	 *
	 * @return list<array{id: string, to: int, body: string}>
	 */
	public static function close( int $now, bool $all = false ): array {
		global $wpdb;
		$table   = Schema::trafficWindows();
		$windows = $wpdb->get_results( $wpdb->prepare( 'SELECT DISTINCT window_from, mode, charge FROM %i WHERE window_from <= %d ORDER BY window_from ASC', $table, $all ? $now : $now - Traffic::WINDOW - self::GRACE ), ARRAY_A );
		$closed  = [];
		foreach ( is_array( $windows ) ? $windows : [] as $w ) {
			$from = (int) $w['window_from'];
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT tbl, k1, k2, k3, n FROM %i WHERE window_from = %d AND mode = %s AND charge = %s', $table, $from, $w['mode'], $w['charge'] ), ARRAY_A );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE window_from = %d AND mode = %s AND charge = %s', $table, $from, $w['mode'], $w['charge'] ) );
			$tables = [];
			foreach ( is_array( $rows ) ? $rows : [] as $row ) {
				$cells             = 'operators' === $row['tbl'] ? [ $row['k1'], $row['k2'], $row['k3'] ] : [ $row['k1'], $row['k2'] ];
				$tables[ $row['tbl'] ][] = [ ...array_map( 'strval', $cells ), (int) $row['n'] ];
			}
			$to   = $all ? max( $from + 1, min( $now, $from + Traffic::WINDOW ) ) : $from + Traffic::WINDOW;
			$body = Traffic::build(
				[
					'id'     => wp_generate_uuid4(),
					'from'   => gmdate( 'Y-m-d\TH:i:s\Z', $from ),
					'to'     => gmdate( 'Y-m-d\TH:i:s\Z', $to ),
					'mode'   => (string) $w['mode'],
					'charge' => (string) $w['charge'],
					'sample' => 1,
				],
				$tables
			);
			if ( null !== $body ) {
				$closed[] = [ 'id' => (string) json_decode( $body, true )['id'], 'to' => $to, 'body' => $body ];
			}
		}
		return $closed;
	}

	public static function clear(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Schema::trafficWindows() ) );
	}
}
