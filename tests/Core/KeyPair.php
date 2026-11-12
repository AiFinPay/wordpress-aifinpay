<?php

declare(strict_types=1);

namespace AiFinPay\Tests;

final class KeyPair {

	public string $secret;
	public array $jwks;

	public function __construct( public string $kid = 'k1' ) {
		$pair         = sodium_crypto_sign_keypair();
		$this->secret = sodium_crypto_sign_secretkey( $pair );
		$this->jwks   = [ 'keys' => [ [ 'kid' => $kid, 'kty' => 'OKP', 'crv' => 'Ed25519', 'x' => self::b64( sodium_crypto_sign_publickey( $pair ) ) ] ] ];
	}

	public static function b64( string $s ): string {
		return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
	}

	public function sign( array $claims, array $header = [] ): string {
		$h = self::b64( (string) json_encode( array_filter( $header + [ 'alg' => 'EdDSA', 'typ' => 'JWT', 'kid' => $this->kid ], static fn ( $v ) => null !== $v ) ) );
		$p = self::b64( (string) json_encode( array_filter( $claims, static fn ( $v ) => null !== $v ) ) );
		return "$h.$p." . self::b64( sodium_crypto_sign_detached( "$h.$p", $this->secret ) );
	}
}
