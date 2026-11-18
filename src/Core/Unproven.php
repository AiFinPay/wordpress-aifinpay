<?php

declare(strict_types=1);

namespace AiFinPay\Core;

defined( 'ABSPATH' ) || exit;

/** "Unproven pays": who reads a protected path without a receipt, and how an unproven client is answered. */
final class Unproven {

	public const NAMED    = 'named';
	public const PROVEN   = 'proven';
	public const EVERYONE = 'everyone';
	public const SETTINGS = [ self::NAMED, self::PROVEN, self::EVERYONE ];

	public const FREE_KINDS = [ 'signed_in', 'reader', 'search_bot' ];

	/**
	 * agent | signed_in | reader | search_bot | unproven. Speaking the protocol is never free.
	 *
	 * @param ?ReaderProof                       $proof      Null when nobody can prove (everyone).
	 * @param callable(string $user_agent): ?string $search_bot The verified category, or null.
	 */
	public static function classify( Request $request, ?ReaderProof $proof, callable $search_bot, int $now ): string {
		if ( '' !== (string) $request->header( 'AIFP-Receipt' ) || '' !== (string) $request->header( 'AIFP-Agent-Id' ) ) {
			return 'agent';
		}
		if ( $request->loggedIn ) {
			return 'signed_in';
		}
		if ( null === $proof ) {
			return 'unproven';
		}
		$ua = (string) $request->header( 'User-Agent' );
		if ( $proof->verify( $request->header( 'Cookie' ), $ua, $now ) ) {
			return 'reader';
		}
		return 'search' === $search_bot( $ua ) ? 'search_bot' : 'unproven';
	}

	public static function acceptsHtml( ?string $accept ): bool {
		return 1 === preg_match( '~\btext/html\b~i', (string) $accept );
	}

	/** A 402 that may be the page itself with the paid part held back; anything else, a client speaking the protocol included, is the 402 JSON. */
	public static function holdable( Request $request, string $kind, bool $page, string $readers ): bool {
		return self::PROVEN === $readers && 'unproven' === $kind && $page && in_array( $request->method, [ 'GET', 'HEAD' ], true ) && self::acceptsHtml( $request->header( 'Accept' ) );
	}

	/** The free page with the check running hidden. */
	public static function quiet( Request $request, string $kind, string $readers ): bool {
		return self::PROVEN === $readers && 'unproven' === $kind && 'GET' === $request->method && self::acceptsHtml( $request->header( 'Accept' ) );
	}
}
