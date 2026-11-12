<?php

declare(strict_types=1);

// Core tests need nothing but PHP. With AIFINPAY_WP_ROOT set, the WordPress suite runs against that real site.
$aifinpay_wp_root = getenv( 'AIFINPAY_WP_ROOT' );
if ( is_string( $aifinpay_wp_root ) && '' !== $aifinpay_wp_root ) {
	$_SERVER['HTTP_HOST']   = '127.0.0.1';
	$_SERVER['REQUEST_URI'] = '/';
	define( 'WP_USE_THEMES', false );
	define( 'DISABLE_WP_CRON', true );
	require $aifinpay_wp_root . '/wp-load.php';
	define( 'AIFINPAY_VERSION', 'test' );
	require dirname( __DIR__ ) . '/vendor/autoload.php';
	AiFinPay\WordPress\Schema::install();
	return;
}
define( 'ABSPATH', sys_get_temp_dir() . '/' );
require dirname( __DIR__ ) . '/vendor/autoload.php';
