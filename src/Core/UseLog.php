<?php

declare(strict_types=1);

namespace AiFinPay\Core;

defined( 'ABSPATH' ) || exit;

interface UseLog {

	/** Keep the lowest remaining for this receipt id; called after every spend. */
	public function record( string $receiptId, int $remaining ): void;
}
