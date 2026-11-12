<?php

declare(strict_types=1);

namespace AiFinPay\Core;

defined( 'ABSPATH' ) || exit;

interface Meter {

	/**
	 * Spend $weight units of one receipt.
	 *
	 * @return int|null Units used after this call, or null when the batch cannot cover it (nothing is taken then).
	 * @throws \RuntimeException When the store fails.
	 */
	public function spend( string $merchant, string $receiptId, int $weight, int $limit, int $expires ): ?int;
}
