<?php

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class_name ): void {
		if ( ! str_starts_with( $class_name, 'AiFinPay\\' ) ) {
			return;
		}
		$file = __DIR__ . '/' . str_replace( '\\', '/', substr( $class_name, strlen( 'AiFinPay\\' ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
