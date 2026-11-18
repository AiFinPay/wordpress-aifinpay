<?php

declare(strict_types=1);

namespace AiFinPay\WordPress;

defined( 'ABSPATH' ) || exit;

use AiFinPay\Core\ReaderProof;

/** The reader key and the Turnstile secret: beside the API secret in the database, not autoloaded, never rendered, logged or sent to a browser. */
final class ReaderKeys {

	public const OPTION = 'aifinpay_reader_keys';

	public static function readerKey(): ?string {
		$stored = self::stored()['reader_key'] ?? null;
		$key    = is_string( $stored ) ? base64_decode( $stored, true ) : false;
		return is_string( $key ) && strlen( $key ) >= ReaderProof::MIN_KEY ? $key : null;
	}

	public static function turnstileSecret(): string {
		$secret = self::stored()['turnstile_secret'] ?? '';
		return is_string( $secret ) && self::validSecret( $secret ) ? $secret : '';
	}

	public static function complete(): bool {
		return null !== self::readerKey() && '' !== self::turnstileSecret();
	}

	public static function proof(): ?ReaderProof {
		$key = self::readerKey();
		return null === $key ? null : new ReaderProof( $key );
	}

	public static function validSecret( string $secret ): bool {
		return 1 === preg_match( '/^[0-9A-Za-z_.-]{1,200}$/D', $secret );
	}

	/** Generated once, 32 random bytes; kept across saves so issued cookies stay valid. */
	public static function ensureReaderKey(): void {
		if ( null === self::readerKey() ) {
			self::write( [ 'reader_key' => base64_encode( random_bytes( 32 ) ) ] );
		}
	}

	public static function saveTurnstileSecret( string $secret ): void {
		self::write( [ 'turnstile_secret' => $secret ] );
	}

	private static function stored(): array {
		$stored = get_option( self::OPTION );
		return is_array( $stored ) ? $stored : [];
	}

	private static function write( array $patch ): void {
		update_option( self::OPTION, array_merge( self::stored(), $patch ), false );
	}
}
