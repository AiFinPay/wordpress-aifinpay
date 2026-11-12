<?php

declare(strict_types=1);

namespace AiFinPay\WordPress;

defined( 'ABSPATH' ) || exit;

use AiFinPay\Core\KeySet;

/** The published receipt-signing keys, cached like createRemoteJWKSet. */
final class WpKeySet implements KeySet {

	public const TRANSIENT = 'aifinpay_jwks';
	public const HOLD      = 'aifinpay_jwks_hold';
	public const TTL       = 600;
	public const COOLDOWN  = 30;

	public function keys(): array {
		$cached = get_transient( self::TRANSIENT );
		return is_array( $cached ) && is_array( $cached['keys'] ?? null ) ? $cached : $this->fetch();
	}

	public function refresh(): ?array {
		if ( false !== get_transient( self::HOLD ) ) {
			return null;
		}
		set_transient( self::HOLD, 1, self::COOLDOWN );
		return $this->fetch();
	}

	private function fetch(): array {
		$jwks = Api::publicJson( '/.well-known/jwks.json' );
		if ( ! is_array( $jwks['keys'] ?? null ) || count( $jwks['keys'] ) > 16 ) {
			throw new \RuntimeException( 'invalid_key_set' );
		}
		set_transient( self::TRANSIENT, $jwks, self::TTL );
		return $jwks;
	}
}
