<?php
/**
 * Plugin Name: Whop Payments for WooCommerce
 * Description: WooCommerce payment gateway for Whop.com — checkout, webhooks, and My Account saved payment methods (setup checkout + sync).
 * Version: 1.2.0
 * Author: Supreme Autoparts
 * Text Domain: whop-payments
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('WHOP_PAYMENTS_VERSION', '1.2.0');
define('WHOP_PAYMENTS_FILE', __FILE__);
define('WHOP_PAYMENTS_DIR', plugin_dir_path(__FILE__));
define('WHOP_PAYMENTS_URL', plugin_dir_url(__FILE__));

add_action('plugins_loaded', static function (): void {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>'
                . esc_html__('Whop Payments requires WooCommerce to be installed and active.', 'whop-payments')
                . '</p></div>';
        });
        return;
    }

    require_once WHOP_PAYMENTS_DIR . 'includes/class-whop-api-client.php';
    require_once WHOP_PAYMENTS_DIR . 'includes/class-whop-webhook.php';
    require_once WHOP_PAYMENTS_DIR . 'includes/class-whop-payment-methods.php';
    require_once WHOP_PAYMENTS_DIR . 'includes/class-wc-gateway-whop.php';
    require_once WHOP_PAYMENTS_DIR . 'includes/class-whop-open-pay.php';

    Whop_Webhook::init();
    Whop_Payment_Methods::init();
    Whop_Open_Pay::init();

    add_filter('woocommerce_payment_gateways', static function (array $gateways): array {
        $gateways[] = 'WC_Gateway_Whop';
        return $gateways;
    });
}, 11);

register_activation_hook(__FILE__, static function (): void {
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, static function (): void {
    flush_rewrite_rules();
});

add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            WHOP_PAYMENTS_FILE,
            true
        );
    }
});
