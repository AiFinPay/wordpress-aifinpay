<?php

declare(strict_types=1);

namespace AiFinPay\WordPress;

defined( 'ABSPATH' ) || exit;

use AiFinPay\Core\ConnectCode;
use AiFinPay\Core\MerchantRecord;

/** The merchant ID and secret from a redeemed connect code. Server-side only: never rendered, logged or sent to a browser. */
final class Connection {

	public const OPTION = 'aifinpay_connection';

	public static function get(): ?array {
		$c = get_option( self::OPTION );
		return is_array( $c ) && is_string( $c['merchant_id'] ?? null ) && preg_match( MerchantRecord::RE_MERCHANT, $c['merchant_id'] )
			&& ConnectCode::validSecret( $c['secret'] ?? null ) ? $c : null;
	}

	public static function merchantId(): string {
		return self::get()['merchant_id'] ?? '';
	}

	public static function secret(): string {
		return self::get()['secret'] ?? '';
	}

	public static function save( string $merchant, string $secret ): void {
		update_option( self::OPTION, [ 'merchant_id' => $merchant, 'secret' => $secret, 'connected_at' => time() ], false );
		RecordStore::forget();
	}

	public static function delete(): void {
		delete_option( self::OPTION );
		RecordStore::forget();
	}

	/**
	 * @return array{merchant_id: string, secret: string}
	 * @throws \RuntimeException invalid_code|rate_limited|unavailable|bad_response
	 */
	public static function redeem( string $input ): array {
		$code = ConnectCode::normalise( $input );
		if ( null === $code ) {
			throw new \RuntimeException( 'invalid_code' );
		}
		$response = Api::request( 'POST', '/v1/merchants/connect', [ 'Content-Type' => 'application/json' ], (string) wp_json_encode( [ 'code' => $code ] ) );
		return ConnectCode::parseResponse( $response['status'], $response['body'] );
	}
}
