<?php

declare(strict_types=1);

namespace AiFinPay\WordPress;

defined( 'ABSPATH' ) || exit;

use AiFinPay\Core\MerchantRecord;

/** The public merchant record: five minutes fresh; while unreachable the last good copy serves a day, retried every minute. */
final class RecordStore {

	public const TRANSIENT = 'aifinpay_record';
	public const LAST      = 'aifinpay_merchant_record';

	public const TTL         = 300;
	public const RETRY       = 60;
	public const STALE_LIMIT = 86400;

	public static function get( bool $refresh = false ): ?array {
		$merchant = Connection::merchantId();
		if ( '' === $merchant ) {
			return null;
		}
		$cached = $refresh ? false : get_transient( self::TRANSIENT );
		if ( is_array( $cached ) && array_key_exists( 'record', $cached ) && ( $cached['merchant_id'] ?? null ) === $merchant ) {
			return $cached['record'];
		}
		$now = time();
		try {
			$record = MerchantRecord::fromResponse( Api::publicJson( '/v1/merchants/' . $merchant ), $merchant, $now );
		} catch ( \Throwable ) {
			$record = null;
		}
		if ( null === $record ) {
			$last   = get_option( self::LAST );
			$record = is_array( $last ) && ( $last['merchant_id'] ?? null ) === $merchant && $now - (int) ( $last['fetched_at'] ?? 0 ) <= self::STALE_LIMIT ? $last : null;
			set_transient( self::TRANSIENT, [ 'merchant_id' => $merchant, 'record' => $record ], self::RETRY );
			return $record;
		}
		set_transient( self::TRANSIENT, [ 'merchant_id' => $merchant, 'record' => $record ], self::TTL );
		update_option( self::LAST, $record, false );
		return $record;
	}

	public static function forget(): void {
		delete_transient( self::TRANSIENT );
		delete_option( self::LAST );
	}
}
