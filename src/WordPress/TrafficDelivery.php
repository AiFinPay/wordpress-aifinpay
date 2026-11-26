<?php

declare(strict_types=1);

namespace AiFinPay\WordPress;

defined( 'ABSPATH' ) || exit;

use AiFinPay\Core\TrafficSender;

/**
 * Sends closed traffic windows to POST /v1/merchants/{id}/gate-traffic on the
 * event schedule, with its own state: nothing here touches gate events,
 * receipt use, pricing or a page.
 */
final class TrafficDelivery {

	public const STATE = 'aifinpay_traffic_sender';
	public const LOCK  = 'aifinpay_traffic_lock';

	/** A run from WP-Cron; $flush also closes the window still open. */
	public static function run( ?int $now = null, bool $flush = false ): void {
		try {
			if ( ! Settings::get()['report_traffic'] || null === Connection::get() ) {
				self::forget();
				return;
			}
			if ( ! self::lock() ) {
				return;
			}
		} catch ( \Throwable ) {
			return;
		}
		try {
			$now  ??= time();
			$state  = TrafficSender::tick( TrafficSender::state( get_option( self::STATE ) ), $now, TrafficCounter::close( $now, $flush ), [ self::class, 'post' ] );
			update_option( self::STATE, $state, false );
		} catch ( \Throwable ) {
			return;
		} finally {
			delete_option( self::LOCK );
		}
	}

	/** @return ?int The status, null when AiFinPay did not answer. */
	public static function post( string $body ): ?int {
		return Api::request(
			'POST',
			'/v1/merchants/' . Connection::merchantId() . '/gate-traffic',
			[ 'AIFP-Merchant-Secret' => Connection::secret(), 'Content-Type' => 'application/json' ],
			$body,
			TrafficSender::TIMEOUT
		)['status'];
	}

	public static function state(): array {
		return TrafficSender::state( get_option( self::STATE ) );
	}

	/** Turned off or disconnected: nothing counted or held stays behind. */
	public static function forget(): void {
		TrafficCounter::clear();
		delete_option( self::STATE );
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
