<?php

declare(strict_types=1);

namespace AiFinPay\Core;

defined( 'ABSPATH' ) || exit;

/**
 * The sender table of the SPEC's traffic summary. Its own state, shared with
 * nothing: at most three closed windows held, sent oldest first with the same
 * id and bytes, and a pause after a refusal.
 */
final class TrafficSender {

	public const HOLD        = 3;
	public const STALE       = 3600;
	public const PAUSE_404   = 21600;
	public const PAUSE_OTHER = 86400;
	public const TIMEOUT     = 5.0;

	/** @return array{held: list<array{id: string, to: int, body: string}>, paused_until: ?int} */
	public static function emptyState(): array {
		return [ 'held' => [], 'paused_until' => null ];
	}

	/** A stored state, or an empty one when it is not readable. */
	public static function state( mixed $stored ): array {
		if ( ! is_array( $stored ) || ! is_array( $stored['held'] ?? null ) ) {
			return self::emptyState();
		}
		$held = array_values(
			array_filter(
				$stored['held'],
				static fn ( $w ): bool => is_array( $w ) && is_string( $w['id'] ?? null ) && is_int( $w['to'] ?? null ) && is_string( $w['body'] ?? null )
			)
		);
		return [ 'held' => array_slice( $held, -self::HOLD ), 'paused_until' => is_int( $stored['paused_until'] ?? null ) ? $stored['paused_until'] : null ];
	}

	/**
	 * One tick: hold what just closed (or drop it in a pause), keep three,
	 * drop what is over an hour old, then send oldest first until one is not accepted.
	 *
	 * @param list<array{id: string, to: int, body: string}> $closed Oldest first.
	 * @param callable(string $body): ?int                   $post   The status, null when nothing answered.
	 */
	public static function tick( array $state, int $at, array $closed, callable $post ): array {
		$state = self::state( $state );
		if ( null !== $state['paused_until'] && $at >= $state['paused_until'] ) {
			$state['paused_until'] = null;
		}
		foreach ( $closed as $window ) {
			if ( null === $state['paused_until'] ) {
				$state['held'][] = $window;
			}
		}
		$state['held'] = array_values( array_filter( array_slice( $state['held'], -self::HOLD ), static fn ( array $w ): bool => $w['to'] >= $at - self::STALE ) );
		while ( null === $state['paused_until'] && $state['held'] ) {
			$kind = self::kind( $post( $state['held'][0]['body'] ) );
			if ( 'done' === $kind ) {
				array_shift( $state['held'] );
				continue;
			}
			if ( 'retry' === $kind ) {
				break;
			}
			$state['held']         = [];
			$state['paused_until'] = $at + ( 'pause404' === $kind ? self::PAUSE_404 : self::PAUSE_OTHER );
		}
		return $state;
	}

	private static function kind( ?int $status ): string {
		if ( null === $status || 408 === $status || 429 === $status || $status >= 500 || $status < 200 ) {
			return 'retry';
		}
		if ( $status < 300 ) {
			return 'done';
		}
		return 404 === $status ? 'pause404' : 'pause';
	}
}
