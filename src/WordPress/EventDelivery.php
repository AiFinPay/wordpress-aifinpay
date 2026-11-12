<?php

declare(strict_types=1);

namespace AiFinPay\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Delivers queued events to POST /v1/merchants/{id}/gate-events.
 *
 * 200 delivered. 429, 5xx and network errors keep the events for the next run;
 * the ingest deduplicates by id, so a retry cannot double-count. 401/403 keeps
 * them too and says so on the status page. Any other refusal rejects the whole
 * batch, so it is split and the event refused alone is dropped: the same bytes
 * can never succeed.
 */
final class EventDelivery {

	public const BATCH        = 50;
	public const MAX_AGE      = 86000;
	public const LOCK         = 'aifinpay_delivery_lock';
	public const STATE        = 'aifinpay_delivery';
	public const CRON_OVERDUE = 600;

	/** A run from WP-Cron. */
	public static function run( int $batches = 5 ): void {
		if ( ! Settings::get()['report_events'] || null === Connection::get() || ! self::lock() ) {
			return;
		}
		try {
			EventQueue::purgeOlderThan( time() - self::MAX_AGE );
			for ( $i = 0; $i < $batches; $i++ ) {
				$events = EventQueue::take( self::BATCH );
				if ( ! $events || ! self::deliver( $events ) ) {
					break;
				}
			}
		} finally {
			delete_option( self::LOCK );
		}
	}

	/**
	 * WP-Cron does not run with DISABLE_WP_CRON or without traffic. A request
	 * that queued an event checks; a visitor is never made to wait for AiFinPay.
	 */
	public static function fallback( ?callable $finish = null, ?callable $spawn = null ): void {
		if ( ! EventQueue::$enqueued ) {
			return;
		}
		$oldest = EventQueue::oldest();
		if ( null === $oldest || time() - $oldest < self::CRON_OVERDUE ) {
			return;
		}
		$finish ??= self::finisher();
		if ( null === $finish ) {
			( $spawn ?? 'spawn_cron' )();
			return;
		}
		$finish();
		self::run( 1 );
	}

	private static function finisher(): ?callable {
		foreach ( [ 'fastcgi_finish_request', 'litespeed_finish_request' ] as $function ) {
			if ( function_exists( $function ) ) {
				return $function;
			}
		}
		return null;
	}

	/** @return bool Whether to go on with the next batch. */
	private static function deliver( array $events ): bool {
		$status = self::send( $events );
		if ( 200 === $status ) {
			EventQueue::delete( array_column( $events, 'id' ) );
			self::note( $status, count( $events ) );
			return true;
		}
		if ( self::retryable( $status ) ) {
			self::note( $status, 0 );
			return false;
		}
		$delivered = 0;
		foreach ( $events as $event ) {
			$one = 1 === count( $events ) ? $status : self::send( [ $event ] );
			if ( self::retryable( $one ) ) {
				self::note( $one, $delivered );
				return false;
			}
			$delivered += 200 === $one ? 1 : 0;
			EventQueue::delete( [ $event['id'] ] );
		}
		self::note( $status, $delivered );
		return true;
	}

	private static function retryable( ?int $status ): bool {
		return null === $status || 429 === $status || $status >= 500 || 401 === $status || 403 === $status;
	}

	private static function send( array $events ): ?int {
		$response = Api::request(
			'POST',
			'/v1/merchants/' . Connection::merchantId() . '/gate-events',
			[ 'AIFP-Merchant-Secret' => Connection::secret(), 'Content-Type' => 'application/json' ],
			(string) wp_json_encode( [ 'events' => $events ], JSON_UNESCAPED_SLASHES ),
			4.0
		);
		return $response['status'];
	}

	private static function note( ?int $status, int $delivered ): void {
		$state                = self::state();
		$state['last_status'] = $status;
		if ( $delivered > 0 ) {
			$state['last_delivered_at'] = time();
		}
		update_option( self::STATE, $state, false );
	}

	public static function state(): array {
		$state = get_option( self::STATE );
		return ( is_array( $state ) ? $state : [] ) + [ 'last_status' => null, 'last_delivered_at' => null ];
	}

	private static function lock(): bool {
		$now = time();
		if ( add_option( self::LOCK, $now + 60, '', false ) ) {
			return true;
		}
		if ( (int) get_option( self::LOCK ) >= $now ) {
			return false;
		}
		delete_option( self::LOCK );
		return add_option( self::LOCK, $now + 60, '', false );
	}
}
