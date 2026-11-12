<?php

declare(strict_types=1);

namespace AiFinPay\Core;

defined( 'ABSPATH' ) || exit;

/** Compact EdDSA JWT verification against a JWKS, as jose.jwtVerify in the gate SDK. */
final class Receipt {

	public const ISSUER          = 'https://api.aifinpay.io';
	public const CLOCK_TOLERANCE = 30;

	private static function decode( string $value ): string {
		if ( '' === $value || ! preg_match( '/^[A-Za-z0-9_-]+$/D', $value ) ) {
			throw new \RuntimeException( 'invalid_receipt' );
		}
		$decoded = base64_decode( strtr( $value, '-_', '+/' ), true );
		if ( false === $decoded || rtrim( strtr( base64_encode( $decoded ), '+/', '-_' ), '=' ) !== $value ) {
			throw new \RuntimeException( 'invalid_receipt' );
		}
		return $decoded;
	}

	/** @throws \RuntimeException 'unknown_key' when no key carries the token's kid; another message otherwise. */
	public static function signedClaims( string $token, array $jwks ): array {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) || strlen( $token ) > 8192 ) {
			throw new \RuntimeException( 'verification_unavailable' );
		}
		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) ) {
			throw new \RuntimeException( 'invalid_receipt' );
		}
		[ $head, $payload, $signature ] = $parts;
		$h                              = json_decode( self::decode( $head ), true, 8, JSON_THROW_ON_ERROR );
		$p                              = json_decode( self::decode( $payload ), true, 16, JSON_THROW_ON_ERROR );
		if ( ! is_array( $h ) || ! is_array( $p ) || 'EdDSA' !== ( $h['alg'] ?? '' )
			|| ( isset( $h['typ'] ) && 'JWT' !== $h['typ'] ) || ( isset( $h['kid'] ) && ! is_string( $h['kid'] ) )
			|| isset( $h['crit'] ) || isset( $h['jku'] ) || isset( $h['jwk'] ) || isset( $h['x5u'] ) ) {
			throw new \RuntimeException( 'invalid_receipt' );
		}
		// With no kid every key matches, so only a one-key set gets past the second match below.
		$kid = $h['kid'] ?? null;
		$key = null;
		foreach ( $jwks['keys'] ?? [] as $jwk ) {
			if ( null === $kid || ( $jwk['kid'] ?? null ) === $kid ) {
				if ( null !== $key || 'OKP' !== ( $jwk['kty'] ?? '' ) || 'Ed25519' !== ( $jwk['crv'] ?? '' )
					|| ( isset( $jwk['alg'] ) && 'EdDSA' !== $jwk['alg'] )
					|| ( isset( $jwk['use'] ) && 'sig' !== $jwk['use'] ) || isset( $jwk['d'] )
					|| ( isset( $jwk['key_ops'] ) && ! in_array( 'verify', $jwk['key_ops'], true ) ) ) {
					throw new \RuntimeException( 'invalid_key' );
				}
				$key = self::decode( $jwk['x'] ?? '' );
			}
		}
		if ( null === $key ) {
			throw new \RuntimeException( 'unknown_key' );
		}
		$sig = self::decode( $signature );
		if ( 32 !== strlen( $key ) || 64 !== strlen( $sig )
			|| ! sodium_crypto_sign_verify_detached( $sig, "$head.$payload", $key ) ) {
			throw new \RuntimeException( 'invalid_signature' );
		}
		return $p;
	}

	/**
	 * Signature, issuer, audience and time.
	 *
	 * @throws \RuntimeException 'expired', 'unknown_key' or 'invalid'.
	 */
	public static function verify( string $token, array $jwks, string $merchant, string $issuer, int $now ): array {
		try {
			$p = self::signedClaims( $token, $jwks );
		} catch ( \Throwable $e ) {
			throw new \RuntimeException( 'unknown_key' === $e->getMessage() ? 'unknown_key' : 'invalid' );
		}
		$aud    = $p['aud'] ?? null;
		$aud_ok = $aud === $merchant || ( is_array( $aud ) && in_array( $merchant, $aud, true ) );
		if ( ( $p['iss'] ?? null ) !== $issuer || ! $aud_ok
			|| ( isset( $p['iat'] ) && ! is_int( $p['iat'] ) )
			|| ( isset( $p['nbf'] ) && ( ! is_int( $p['nbf'] ) || $p['nbf'] > $now + self::CLOCK_TOLERANCE ) )
			|| ! is_int( $p['exp'] ?? null ) ) {
			throw new \RuntimeException( 'invalid' );
		}
		if ( $p['exp'] + self::CLOCK_TOLERANCE <= $now ) {
			throw new \RuntimeException( 'expired' );
		}
		return $p;
	}
}
