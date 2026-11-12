<?php

declare(strict_types=1);

namespace AiFinPay\Core;

defined( 'ABSPATH' ) || exit;

/** One HTTP request, as the gate needs to see it. */
final class Request {

	/** @param array<string, string> $headers Lower-case names. */
	public function __construct(
		public readonly string $method,
		public readonly string $host,
		public readonly string $path,
		private readonly array $headers = [],
		public readonly bool $loggedIn = false,
		public readonly bool $ambiguous = false
	) {}

	public function header( string $name ): ?string {
		return $this->headers[ strtolower( $name ) ] ?? null;
	}

	public static function normaliseHost( string $host ): string {
		$host = strtolower( trim( $host ) );
		$host = (string) preg_replace( '/:\d+$/', '', $host );
		return rtrim( $host, '.' );
	}
}
