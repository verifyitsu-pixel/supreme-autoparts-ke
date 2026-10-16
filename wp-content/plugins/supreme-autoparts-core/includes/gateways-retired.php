<?php
/**
 * Retired payment gateways + order-received template fix.
 *
 * - Whop and TransactWorld are retired at code level: forced off at read time and
 *   removed from checkout, whatever the stored settings say. Pesapal stays.
 * - The Pesapal plugin registers a woocommerce_locate_template filter (priority 1)
 *   that replaces checkout/thankyou.php with its own bare template for EVERY order.
 *   Re-assert the theme's thankyou.php so customers get the full order details page.
 *
 * @package Supreme_Autoparts_Core
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

/* Force the retired gateways' enabled flag off without writing the database. */
add_filter('option_woocommerce_whop_settings', static function ($settings) {
    if (is_array($settings)) {
        $settings['enabled'] = 'no';
    }
    return $settings;
}, 99);
add_filter('option_woocommerce_transactworld_settings', static function ($settings) {
    if (is_array($settings)) {
        $settings['enabled'] = 'no';
    }
    return $settings;
}, 99);

/* Never offer the retired gateways at checkout even if something re-enables them. */
add_filter('woocommerce_available_payment_gateways', static function ($gateways) {
    unset($gateways['whop'], $gateways['transactworld']);
    return $gateways;
}, 99);

/* Pesapal's plugin overrides checkout/thankyou.php for all orders; take it back for the theme. */
add_filter('woocommerce_locate_template', static function ($template, $template_name) {
    if ('checkout/thankyou.php' === $template_name) {
        $theme = get_stylesheet_directory() . '/woocommerce/checkout/thankyou.php';
        if (file_exists($theme)) {
            return $theme;
        }
    }
    return $template;
}, 999, 2);
