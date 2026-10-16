<?php
/**
 * Retired payment gateways + order-received template fix.
 *
 * - TransactWorld is retired at code level: forced off at read time and
 *   removed from checkout, whatever the stored settings say.
 * - Whop stays hidden until SA_WHOP_CHECKOUT=1 is set (env). With the switch on,
 *   Whop and Pesapal are both offered, ordered by billing country: Kenya sees
 *   Pesapal first and preselected, everyone else sees Whop first and preselected.
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

/* Checkout gateway availability + order. TransactWorld is always removed;
   Whop only appears while SA_WHOP_CHECKOUT=1. */
add_filter('woocommerce_available_payment_gateways', static function ($gateways) {
    if (!is_array($gateways)) {
        return $gateways;
    }
    unset($gateways['transactworld']);
    if (getenv('SA_WHOP_CHECKOUT') !== '1') {
        unset($gateways['whop']);
        return $gateways;
    }
    if (!isset($gateways['whop'], $gateways['pesapal'])) {
        return $gateways;
    }
    // Order by billing country (fallback: IP geolocation before billing is entered).
    $country = '';
    if (function_exists('WC') && WC()->customer) {
        $country = (string) WC()->customer->get_billing_country();
    }
    if ($country === '' && class_exists('WC_Geolocation')) {
        $country = (string) (WC_Geolocation::geolocate_ip()['country'] ?? '');
    }
    $first  = $country === 'KE' ? 'pesapal' : 'whop';
    $second = $country === 'KE' ? 'whop' : 'pesapal';
    $ordered = [$first => $gateways[$first], $second => $gateways[$second]];
    foreach ($gateways as $id => $gateway) {
        if ($id !== 'whop' && $id !== 'pesapal') {
            $ordered[$id] = $gateway;
        }
    }
    // Preselect the first option when the customer has not chosen yet.
    if (function_exists('WC') && WC()->session) {
        $chosen = (string) WC()->session->get('chosen_payment_method');
        if ($chosen === '') {
            WC()->session->set('chosen_payment_method', $first);
        }
    }
    return $ordered;
}, 99);

/* Clear labels while the Whop switch is on; unchanged while it is off. */
add_filter('woocommerce_gateway_title', static function ($title, $gateway_id) {
    if (getenv('SA_WHOP_CHECKOUT') !== '1') {
        return $title;
    }
    if ('whop' === $gateway_id) {
        return 'Card (Visa, Mastercard)';
    }
    if ('pesapal' === $gateway_id) {
        return 'M-Pesa / Airtel Money';
    }
    return $title;
}, 10, 2);

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
