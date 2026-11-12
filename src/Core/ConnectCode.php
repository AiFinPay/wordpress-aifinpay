<?php

declare(strict_types=1);

namespace AiFinPay\Core;

defined( 'ABSPATH' ) || exit;

/** The one-time dashboard code redeemed at POST /v1/merchants/connect for merchant_id + a rotated secret. */
final class ConnectCode {

	public const RE_CODE = '/^(AIFP)?[0-9A-HJKMNP-TV-Z]{16}$/D';

	/** Upper-case, spaces and dashes off; null when it cannot be a code. */
	public static function normalise( string $input ): ?string {
		$raw = strtoupper( (string) preg_replace( '/[\s-]+/', '', $input ) );
		return preg_match( self::RE_CODE, $raw ) ? $raw : null;
	}

	public static function validSecret( mixed $secret ): bool {
		return is_string( $secret ) && strlen( $secret ) >= 32 && strlen( $secret ) <= 200 && ! preg_match( '/[\x00-\x20\x7f]/', $secret );
	}

	/**
	 * @return array{merchant_id: string, secret: string}
	 * @throws \RuntimeException invalid_code|rate_limited|unavailable|bad_response
	 */
	public static function parseResponse( ?int $status, string $raw ): array {
		if ( 400 === $status ) {
			throw new \RuntimeException( 'invalid_code' );
		}
		if ( 429 === $status ) {
			throw new \RuntimeException( 'rate_limited' );
		}
		if ( 200 !== $status ) {
			throw new \RuntimeException( 'unavailable' );
		}
		$body     = json_decode( $raw, true );
		$merchant = is_array( $body ) ? ( $body['merchant_id'] ?? null ) : null;
		$secret   = is_array( $body ) ? ( $body['secret'] ?? null ) : null;
		if ( ! is_string( $merchant ) || ! preg_match( MerchantRecord::RE_MERCHANT, $merchant ) || ! self::validSecret( $secret ) ) {
			throw new \RuntimeException( 'bad_response' );
		}
		return [ 'merchant_id' => $merchant, 'secret' => $secret ];
	}
}
