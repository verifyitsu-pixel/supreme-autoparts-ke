<?php
/**
 * Plugin Name: Supreme Geo Currency Display
 * Description: Display prices in visitor local currency (CF-IPCountry / geo fallback) while WooCommerce + Whop checkout remain USD.
 * Version: 1.0.0
 * Author: Supreme Autoparts
 * Text Domain: sa-geo-currency
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('SA_GEO_CURRENCY_VERSION', '1.0.0');
define('SA_GEO_CURRENCY_FILE', __FILE__);
define('SA_GEO_CURRENCY_DIR', plugin_dir_path(__FILE__));
define('SA_GEO_CURRENCY_URL', plugin_dir_url(__FILE__));

require_once SA_GEO_CURRENCY_DIR . 'includes/class-sa-geo-detector.php';
require_once SA_GEO_CURRENCY_DIR . 'includes/class-sa-fx-rates.php';
require_once SA_GEO_CURRENCY_DIR . 'includes/class-sa-geo-display.php';

/**
 * Feature flag: SA_GEO_CURRENCY=1 enables adaptive display.
 */
function sa_geo_currency_enabled(): bool
{
    $env = getenv('SA_GEO_CURRENCY');
    if ($env === false || $env === '') {
        // Default on when plugin is active — env can force off with 0.
        return true;
    }
    return in_array(strtolower(trim((string) $env)), ['1', 'true', 'yes', 'on'], true);
}

/**
 * Store / order / Whop currency — always USD for this storefront.
 */
function sa_geo_checkout_currency(): string
{
    $env = getenv('SA_CHECKOUT_CURRENCY') ?: getenv('WOO_CURRENCY');
    if (is_string($env) && preg_match('/^[A-Za-z]{3}$/', trim($env))) {
        return strtoupper(trim($env));
    }
    return 'USD';
}

/**
 * Ensure Woo store currency stays checkout currency (USD). Idempotent.
 */
function sa_geo_enforce_store_currency(): void
{
    if (!function_exists('update_option')) {
        return;
    }
    $wanted = sa_geo_checkout_currency();
    if (get_option('woocommerce_currency') !== $wanted) {
        update_option('woocommerce_currency', $wanted);
    }
}

add_action('plugins_loaded', static function (): void {
    if (!class_exists('WooCommerce') && !function_exists('WC')) {
        return;
    }

    sa_geo_enforce_store_currency();

    // Never let display logic change order currency.
    add_filter('woocommerce_currency', static function (string $currency): string {
        // During order creation / payment / admin, force checkout currency.
        if (!sa_geo_currency_enabled()) {
            return sa_geo_checkout_currency();
        }
        // Keep store currency as USD always — display conversion is separate.
        return sa_geo_checkout_currency();
    }, 5);

    if (sa_geo_currency_enabled()) {
        SA_Geo_Display::init();
    }
}, 20);

add_action('init', static function (): void {
    sa_geo_enforce_store_currency();
}, 5);

// Soft bump so existing installs flip from KES → USD once.
add_action('init', static function (): void {
    if (get_option('sa_geo_currency_boot_ver') === '1') {
        return;
    }
    sa_geo_enforce_store_currency();
    update_option('sa_geo_currency_boot_ver', '1');
}, 25);

/**
 * Hard-guarantee Whop/Woo order currency is checkout currency (USD).
 */
add_action('woocommerce_checkout_create_order', static function ($order): void {
    if (!is_object($order) || !method_exists($order, 'set_currency')) {
        return;
    }
    $order->set_currency(sa_geo_checkout_currency());
}, 5);

