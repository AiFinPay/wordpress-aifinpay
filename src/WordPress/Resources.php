<?php

declare(strict_types=1);

namespace AiFinPay\WordPress;

defined( 'ABSPATH' ) || exit;

/** Registers the protected patterns with AiFinPay: the gate-events ingest refuses any other. */
final class Resources {

	public const STATE = 'aifinpay_registration';

	public static function sync( array $settings ): void {
		if ( null === Connection::get() || 'off' === $settings['mode'] || ( ! $settings['report_events'] && 'enforce' !== $settings['mode'] ) ) {
			return;
		}
		$patterns = Settings::patterns( $settings );
		$failed   = [];
		foreach ( $patterns as $pattern ) {
			$response = Api::request(
				'POST',
				'/v1/merchants/' . Connection::merchantId() . '/resources',
				[ 'AIFP-Merchant-Secret' => Connection::secret(), 'Content-Type' => 'application/json' ],
				(string) wp_json_encode(
					[
						'route_pattern' => $pattern,
						'type'          => str_starts_with( $pattern, Settings::restPath() . '/' ) ? 'api' : 'page',
						'tier'          => $settings['tier'],
						'upsert'        => true,
					],
					JSON_UNESCAPED_SLASHES
				),
				4.0
			);
			if ( ! in_array( $response['status'], [ 200, 201 ], true ) ) {
				$failed[] = $pattern;
			}
		}
		update_option( self::STATE, [ 'patterns' => $patterns, 'tier' => $settings['tier'], 'failed' => $failed, 'at' => time() ], false );
	}

	/** After a failed registration, or settings changed outside the form. */
	public static function retryIfNeeded( array $settings ): void {
		$state = get_option( self::STATE );
		if ( ! is_array( $state ) || $state['failed'] || $state['patterns'] !== Settings::patterns( $settings ) || $state['tier'] !== $settings['tier'] ) {
			self::sync( $settings );
		}
	}

	public static function failed(): array {
		$state = get_option( self::STATE );
		return is_array( $state ) && is_array( $state['failed'] ?? null ) ? $state['failed'] : [];
	}
}
