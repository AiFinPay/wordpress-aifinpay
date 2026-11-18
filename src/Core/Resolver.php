<?php

declare(strict_types=1);

namespace AiFinPay\Core;

defined( 'ABSPATH' ) || exit;

/** The DNS lookups a search-bot check needs; both throw \RuntimeException when the answer cannot be had. */
interface Resolver {

	/** @return string[] PTR names of the address. */
	public function reverse( string $ip ): array;

	/** @return string[] A records when $family is 4, AAAA when 6. */
	public function forward( string $name, int $family ): array;
}
