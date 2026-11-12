<?php

declare(strict_types=1);

namespace AiFinPay\Core;

defined( 'ABSPATH' ) || exit;

/** Why this site may not switch to Enforce, as reason codes. Empty means it may. */
final class Eligibility {

	/** @param array{approved: bool, host: ?string, network_mode: ?string, reason: string} $charging */
	public static function problems( array $protected, string $site_host, bool $pretty_permalinks, array $charging ): array {
		$problems = [];
		if ( ! $charging['approved'] ) {
			$problems[] = $charging['reason'];
		} elseif ( Request::normaliseHost( $site_host ) !== $charging['host'] ) {
			$problems[] = 'host_mismatch';
		}
		if ( ! $pretty_permalinks ) {
			$problems[] = 'plain_permalinks';
		}
		if ( ! $protected ) {
			$problems[] = 'no_paths';
		}
		if ( in_array( Paths::ALL, $protected, true ) ) {
			$problems[] = 'all_paths';
		}
		return $problems;
	}
}
