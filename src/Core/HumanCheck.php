<?php

declare(strict_types=1);

namespace AiFinPay\Core;

defined( 'ABSPATH' ) || exit;

/** POST /__aifp/human {token}: Turnstile siteverify for this host, then the reader cookie. Nothing else is set. */
final class HumanCheck {

	public const PATH          = '/__aifp/human';
	public const SITEVERIFY    = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
	private const NO_STORE     = 'private, no-store, max-age=0';
	private const TOKEN_MAX    = 2048;

	/**
	 * @param callable(string $token, string $ip): ?array $siteverify The decoded siteverify answer, null when there is none.
	 * @return array{status: int, headers: array<string, string>}
	 */
	public static function answer( string $method, string $body, string $user_agent, string $ip, string $site_host, callable $siteverify, ReaderProof $proof, int $now ): array {
		if ( 'POST' !== $method ) {
			return self::plain( 405, [ 'Allow' => 'POST' ] );
		}
		$data  = json_decode( $body, true, 4 );
		$token = is_array( $data ) ? ( $data['token'] ?? null ) : null;
		if ( ! is_string( $token ) || '' === $token || strlen( $token ) > self::TOKEN_MAX ) {
			return self::plain( 400 );
		}
		try {
			$verdict = $siteverify( $token, $ip );
		} catch ( \Throwable ) {
			$verdict = null;
		}
		if ( ! is_array( $verdict ) || true !== ( $verdict['success'] ?? null ) || ! is_string( $verdict['hostname'] ?? null )
			|| Request::normaliseHost( $verdict['hostname'] ) !== Request::normaliseHost( $site_host ) || '' === $site_host ) {
			return self::plain( 403 );
		}
		return self::plain( 204, [ 'Set-Cookie' => $proof->setCookie( $proof->issue( $user_agent, $now ) ) ] );
	}

	private static function plain( int $status, array $headers = [] ): array {
		return [ 'status' => $status, 'headers' => [ 'Cache-Control' => self::NO_STORE ] + $headers ];
	}
}
