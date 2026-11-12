<?php
/**
 * Removes the secret, settings, tables and scheduled events.
 *
 * @package AiFinPay
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/autoload.php';

if ( is_multisite() ) {
	foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $aifinpay_site ) {
		switch_to_blog( $aifinpay_site );
		AiFinPay\WordPress\Maintenance::removeSiteData();
		restore_current_blog();
	}
} else {
	AiFinPay\WordPress\Maintenance::removeSiteData();
}
