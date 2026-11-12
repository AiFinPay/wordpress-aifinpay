<?php

declare(strict_types=1);

namespace AiFinPay\Core;

defined( 'ABSPATH' ) || exit;

interface KeySet {

	/**
	 * The published JWKS.
	 *
	 * @throws \RuntimeException When it cannot be read.
	 */
	public function keys(): array;

	/** Re-read after an unknown key id, at most every 30 seconds; null when it may not be re-read yet. */
	public function refresh(): ?array;
}
