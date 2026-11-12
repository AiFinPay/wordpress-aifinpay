<?php
/**
 * Plugin Name:       AiFinPay
 * Plugin URI:        https://aifinpay.io
 * Description:       See which AI agents read your site and what they would pay, then charge them per request. Readers stay free.
 * Version:           0.2.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Aifinpay OÜ
 * Author URI:        https://aifinpay.io
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       aifinpay
 *
 * @package AiFinPay
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'AIFINPAY_VERSION', '0.2.0' );

require_once __DIR__ . '/src/autoload.php';

register_activation_hook( __FILE__, [ AiFinPay\WordPress\Maintenance::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ AiFinPay\WordPress\Maintenance::class, 'deactivate' ] );

AiFinPay\WordPress\Plugin::boot( __FILE__ );
