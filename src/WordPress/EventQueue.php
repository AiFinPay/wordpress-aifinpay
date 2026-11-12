<?php

declare(strict_types=1);

namespace AiFinPay\WordPress;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- A queue on the plugin's own table.

/** Gate events waiting for delivery. */
final class EventQueue {

	public const CAP = 5000;

	public static bool $enqueued = false;

	public static function push( array $event, string $resource, int $now ): void {
		global $wpdb;
		$table = Schema::events();
		if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) >= self::CAP ) {
			return;
		}
		$id      = wp_generate_uuid4();
		$payload = [
			'id'       => $id,
			'kind'     => $event['kind'],
			'resource' => $resource,
			'at'       => gmdate( 'Y-m-d\TH:i:s\Z', $now ),
			'operator' => $event['operator'],
			'ua_class' => $event['ua_class'],
			'verified' => $event['verified'],
		];
		$wpdb->insert( $table, [ 'id' => $id, 'payload' => (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ), 'created' => $now ], [ '%s', '%s', '%d' ] );
		self::$enqueued = true;
	}

	/** @return array<int, array> Oldest first. */
	public static function take( int $limit ): array {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT payload FROM %i ORDER BY created ASC, id ASC LIMIT %d', Schema::events(), $limit ) );
		return array_values( array_filter( array_map( static fn ( string $p ) => json_decode( $p, true ), is_array( $rows ) ? $rows : [] ), 'is_array' ) );
	}

	public static function delete( array $ids ): void {
		global $wpdb;
		foreach ( $ids as $id ) {
			$wpdb->delete( Schema::events(), [ 'id' => $id ], [ '%s' ] );
		}
	}

	public static function count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::events() ) );
	}

	public static function oldest(): ?int {
		global $wpdb;
		$created = $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(created) FROM %i', Schema::events() ) );
		return null === $created ? null : (int) $created;
	}

	public static function purgeOlderThan( int $cutoff ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created < %d', Schema::events(), $cutoff ) );
	}

	public static function clear(): void {
		self::purgeOlderThan( PHP_INT_MAX );
	}
}
