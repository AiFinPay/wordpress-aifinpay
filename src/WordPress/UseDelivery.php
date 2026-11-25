<?php

declare(strict_types=1);

namespace AiFinPay\WordPress;

defined( 'ABSPATH' ) || exit;

use AiFinPay\Core\ReceiptUse;

/**
 * Sends receipt use to POST /v1/merchants/{id}/receipt-use on the event
 * schedule, whenever the site is connected: accounting, not the report_events
 * opt-in. 404 (an API without the route), another refusal or a redirect drops
 * what is held and the next spend records again; not reached, 408, 429 or 5xx
 * keeps it for up to five runs. Gate events are not touched.
 */
final class UseDelivery {

	/** A run from WP-Cron. */
	public static function run(): void {
		if ( null === Connection::get() ) {
			return;
		}
		foreach ( ReceiptUse::bodies( WpdbUseLog::held() ) as $body ) {
			$uses     = json_decode( $body, true )['uses'];
			$response = Api::request(
				'POST',
				'/v1/merchants/' . Connection::merchantId() . '/receipt-use',
				[ 'AIFP-Merchant-Secret' => Connection::secret(), 'Content-Type' => 'application/json' ],
				$body,
				4.0
			);
			$outcome = ReceiptUse::outcome( $response['status'], $response['body'], count( $uses ) );
			if ( ReceiptUse::SENT === $outcome ) {
				WpdbUseLog::sent( $uses );
				continue;
			}
			if ( ReceiptUse::KEEP === $outcome ) {
				WpdbUseLog::failed();
			} else {
				WpdbUseLog::clear();
			}
			return;
		}
	}
}
