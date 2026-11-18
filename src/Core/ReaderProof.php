<?php

declare(strict_types=1);

namespace AiFinPay\Core;

defined( 'ABSPATH' ) || exit;

/** The reader cookie `<exp>.<sig>` shared with @aifinpay/gate and the edge Worker; conformance/vectors/reader-proof-v1.json. */
final class ReaderProof {

	public const COOKIE      = '__aifp_human';
	public const TTL         = 2592000;
	public const MIN_KEY     = 32;
	private const SAFE_MAX   = 9007199254740991;
	private const VALUE_FORM = '/^(0|[1-9][0-9]{0,15})\.([A-Za-z0-9_-]{43})$/D';

	public function __construct( private string $key, private int $ttl = self::TTL ) {
		if ( strlen( $key ) < self::MIN_KEY ) {
			throw new \InvalidArgumentException( 'reader key must be at least 32 bytes' );
		}
	}

	public function issue( string $user_agent, int $now ): string {
		$exp = (string) ( $now + $this->ttl );
		return $exp . '.' . $this->sign( $exp, $user_agent );
	}

	/** The first `__aifp_human` in the header, unexpired and signed for this User-Agent. */
	public function verify( ?string $cookie_header, string $user_agent, int $now ): bool {
		$prefix = self::COOKIE . '=';
		foreach ( preg_split( '/;[ \t]*/', (string) $cookie_header ) as $pair ) {
			if ( ! str_starts_with( $pair, $prefix ) ) {
				continue;
			}
			if ( 1 !== preg_match( self::VALUE_FORM, substr( $pair, strlen( $prefix ) ), $m ) ) {
				return false;
			}
			$exp = (int) $m[1];
			if ( $exp > self::SAFE_MAX || $exp <= $now ) {
				return false;
			}
			return hash_equals( $this->sign( $m[1], $user_agent ), $m[2] );
		}
		return false;
	}

	public function setCookie( string $value ): string {
		return self::COOKIE . '=' . $value . '; Path=/; Max-Age=' . $this->ttl . '; HttpOnly; Secure; SameSite=Lax';
	}

	private function sign( string $exp, string $user_agent ): string {
		return rtrim( strtr( base64_encode( hash_hmac( 'sha256', $exp . "\n" . $user_agent, $this->key, true ) ), '+/', '-_' ), '=' );
	}
}
