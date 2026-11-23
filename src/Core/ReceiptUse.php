<?php

declare(strict_types=1);

namespace AiFinPay\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Receipt use as AiFinPay takes it: POST /v1/merchants/{id}/receipt-use,
 * {"uses": [{"receipt_id", "remaining"}]}, at most 100 per call, ordered by
 * receipt id, the lowest remaining per receipt. The bytes of the shared
 * vectors conformance/vectors/receipt-use-v1.json.
 */
final class ReceiptUse {

	public const BATCH        = 100;
	public const MAX_HELD     = 10000;
	public const MAX_ATTEMPTS = 5;

	public const SENT = 'sent';
	public const KEEP = 'keep';
	public const DROP = 'drop';

	/** A receipt id as the backend issues it (never the token) and a whole number of units left. */
	public static function valid( mixed $receiptId, mixed $remaining ): bool {
		return is_string( $receiptId ) && 1 === preg_match( '/^rcpt_[0-9a-f]{16}$/D', $receiptId )
			&& is_int( $remaining ) && $remaining >= 0;
	}

	/**
	 * @param array<string, int> $held Lowest remaining per receipt id.
	 * @return list<string>
	 */
	public static function bodies( array $held ): array {
		ksort( $held, SORT_STRING );
		$uses = [];
		foreach ( $held as $id => $remaining ) {
			$uses[] = [ 'receipt_id' => (string) $id, 'remaining' => (int) $remaining ];
		}
		return array_map(
			static fn ( array $chunk ): string => (string) json_encode( [ 'uses' => $chunk ], JSON_UNESCAPED_SLASHES ),
			array_chunk( $uses, self::BATCH )
		);
	}

	/**
	 * What one answer means for what was sent. 200 with matching counts: sent.
	 * Not reached, 408, 429, 5xx or an unreadable 2xx: keep for the next run.
	 * 404 (an API without the route), another 4xx or a redirect: drop what is held.
	 */
	public static function outcome( ?int $status, string $answer, int $count ): string {
		if ( null === $status || 408 === $status || 429 === $status || $status >= 500 ) {
			return self::KEEP;
		}
		if ( $status < 200 || $status >= 300 ) {
			return self::DROP;
		}
		$ack = json_decode( $answer, true );
		return is_array( $ack ) && is_int( $ack['applied'] ?? null ) && is_int( $ack['ignored'] ?? null )
			&& $ack['applied'] >= 0 && $ack['ignored'] >= 0 && $ack['applied'] + $ack['ignored'] === $count
			? self::SENT : self::KEEP;
	}
}
