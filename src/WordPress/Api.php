<?php

declare(strict_types=1);

namespace AiFinPay\WordPress;

defined( 'ABSPATH' ) || exit;

/** The AiFinPay API, pinned origin, no redirects. */
final class Api {

	public const OFFICIAL = 'https://api.aifinpay.io';

	/** A test origin only from wp-config.php, only on loopback, only in a local environment. */
	public static function origin(): string {
		if ( defined( 'AIFINPAY_TEST_API_ORIGIN' ) && 'local' === wp_get_environment_type()
			&& preg_match( '~^http://127\.0\.0\.1:[1-9][0-9]{3,4}$~D', (string) constant( 'AIFINPAY_TEST_API_ORIGIN' ) ) ) {
			return (string) constant( 'AIFINPAY_TEST_API_ORIGIN' );
		}
		return self::OFFICIAL;
	}

	/** @return array{status: ?int, body: string} status null when AiFinPay was not reached. */
	public static function request( string $method, string $path, array $headers = [], ?string $body = null, float $timeout = 8.0 ): array {
		$args     = [
			'method'              => $method,
			'headers'             => $headers + [ 'Accept' => 'application/json' ],
			'timeout'             => $timeout,
			'redirection'         => 0,
			'sslverify'           => true,
			'limit_response_size' => 65536,
			'user-agent'          => 'AiFinPay-WordPress/' . AIFINPAY_VERSION,
		];
		if ( null !== $body ) {
			$args['body'] = $body;
		}
		$response = wp_remote_request( self::origin() . $path, $args );
		if ( is_wp_error( $response ) ) {
			return [ 'status' => null, 'body' => '' ];
		}
		return [ 'status' => (int) wp_remote_retrieve_response_code( $response ), 'body' => (string) wp_remote_retrieve_body( $response ) ];
	}

	/** @throws \RuntimeException When the answer is not a 200 with a JSON object. */
	public static function publicJson( string $path, float $timeout = 4.0 ): array {
		$response = self::request( 'GET', $path, [], null, $timeout );
		$data     = 200 === $response['status'] ? json_decode( $response['body'], true, 32 ) : null;
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'api_unavailable' );
		}
		return $data;
	}
}
