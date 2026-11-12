<?php

declare(strict_types=1);

namespace AiFinPay\Core;

defined( 'ABSPATH' ) || exit;

/** Transcribed from gate/src/pricing.ts. Strings and integers, never floats: these are money. */
final class Pricing {

	public const TIER_WEIGHTS    = [ 'standard' => 1, 'complex' => 4, 'premium' => 10 ];
	public const UNIT_PRICE_USD  = [ 'standard' => '0.0005', 'complex' => '0.002', 'premium' => '0.005' ];
	public const MIN_BATCH_UNITS = 20000;
	public const MIN_REQUESTS    = [ 'standard' => 40, 'complex' => 10, 'premium' => 4 ];
	public const BASE_UNIT_MICRO = 500;

	public static function isTier( mixed $tier ): bool {
		return is_string( $tier ) && isset( self::TIER_WEIGHTS[ $tier ] );
	}

	/** Billing units as dollars: one unit is $0.0005, whatever the tier. */
	public static function unitsToUsd( int $units ): string {
		$micro = $units * self::BASE_UNIT_MICRO;
		$whole = intdiv( $micro, 1000000 );
		$frac  = rtrim( str_pad( (string) ( $micro % 1000000 ), 6, '0', STR_PAD_LEFT ), '0' );
		return $whole . '.' . str_pad( $frac, 2, '0' );
	}
}
