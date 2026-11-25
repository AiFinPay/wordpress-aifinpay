<?php

declare(strict_types=1);

namespace AiFinPay\Core;

defined( 'ABSPATH' ) || exit;

/**
 * What the plugin tells AiFinPay about itself on every request with the
 * merchant secret: User-Agent and AIFP-Gate as in the shared vectors
 * conformance/vectors/gate-identity-v1.json.
 */
final class GateIdentity {

	public const PKG      = 'wordpress';
	public const PLATFORM = 'wordpress';

	public const VALUE = '/^[a-z0-9._-]{1,32}$/D';

	/** "Who reads for free" as stored => the charge rule AiFinPay names. */
	public const CHARGES = [
		Unproven::NAMED    => 'agents',
		Unproven::PROVEN   => 'unproven',
		Unproven::EVERYONE => 'everyone',
	];

	/** @return array{User-Agent: string, AIFP-Gate: string} */
	public static function headers( string $pkg, mixed $ver, mixed $platform, string $mode, string $charge ): array {
		$version = is_string( $ver ) ? explode( '+', strtr( $ver, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz' ), 2 )[0] : '';
		$version = 1 === preg_match( self::VALUE, $version ) ? $version : 'unknown';
		$where   = is_string( $platform ) && 1 === preg_match( self::VALUE, $platform ) ? $platform : 'unknown';
		return [
			'User-Agent' => 'aifinpay-gate-' . $pkg . '/' . $version,
			'AIFP-Gate'  => 'pkg=' . $pkg . '; ver=' . $version . '; platform=' . $where . '; mode=' . $mode . '; charge=' . $charge,
		];
	}

	/** Off charges nothing, so it is reported as shadow. */
	public static function mode( string $setting ): string {
		return 'enforce' === $setting ? 'enforce' : 'shadow';
	}

	public static function charge( string $readers ): string {
		return self::CHARGES[ $readers ] ?? 'agents';
	}
}
