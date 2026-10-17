<?php
/**
 * Retired payment gateways + order-received template fix.
 *
 * - TransactWorld is retired at code level: forced off at read time and
 *   removed from checkout, whatever the stored settings say.
 * - Whop stays hidden until SA_WHOP_CHECKOUT=1 is set (env). In test mode it is
 *   offered to shop admins only; SA_WHOP_CHECKOUT_PUBLIC=1 offers it to everyone.
 *   When visible, Whop and Pesapal are both offered, ordered by billing country:
 *   Kenya sees Pesapal first and preselected, everyone else sees Whop first.
 * - The Pesapal plugin registers a woocommerce_locate_template filter (priority 1)
 *   that replaces checkout/thankyou.php with its own bare template for EVERY order.
 *   Re-assert the theme's thankyou.php so customers get the full order details page.
 *
 * @package Supreme_Autoparts_Core
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

/* Force the retired gateways' enabled flag off without writing the database.
   Also blank the Whop gateway description: no processor branding at checkout. */
add_filter('option_woocommerce_whop_settings', static function ($settings) {
    if (is_array($settings)) {
        $settings['enabled'] = 'no';
        $settings['description'] = '';
    }
    return $settings;
}, 99);
add_filter('option_woocommerce_transactworld_settings', static function ($settings) {
    if (is_array($settings)) {
        $settings['enabled'] = 'no';
    }
    return $settings;
}, 99);

/* Whop checkout visibility: switch on + shop admin, unless the public flag is set. */
if (!function_exists('sa_whop_checkout_visible')) {
    function sa_whop_checkout_visible(): bool {
        if (getenv('SA_WHOP_CHECKOUT') !== '1') {
            return false;
        }
        if (getenv('SA_WHOP_CHECKOUT_PUBLIC') === '1') {
            return true;
        }
        return function_exists('current_user_can') && current_user_can('manage_woocommerce');
    }
}

/* Checkout gateway availability + order. TransactWorld is always removed;
   Whop only appears while sa_whop_checkout_visible() is true. */
add_filter('woocommerce_available_payment_gateways', static function ($gateways) {
    if (!is_array($gateways)) {
        return $gateways;
    }
    unset($gateways['transactworld']);
    if (!sa_whop_checkout_visible()) {
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

/* Clear labels while the Whop switch is on; unchanged while it is off.
   Priority 30 so these win over the geo-content label filter. Non-KE zones get a
   distinct neutral label for the mobile-money gateway so the two options never
   read the same; KE keeps the familiar mobile-money label. */
add_filter('woocommerce_gateway_title', static function ($title, $gateway_id) {
    if (!sa_whop_checkout_visible()) {
        return $title;
    }
    if ('whop' === $gateway_id) {
        return 'Card (Visa, Mastercard)';
    }
    if ('pesapal' === $gateway_id) {
        $ke = function_exists('sa_gc_zone') && sa_gc_zone() === 'KE';
        return $ke ? 'M-Pesa / Airtel Money' : 'Card / M-Pesa / Airtel Money';
    }
    return $title;
}, 30, 2);

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

/* No processor logos in the checkout gateway rows. */
add_filter('woocommerce_gateway_icon', static function ($icon, $gateway_id) {
    if ('pesapal' === $gateway_id || 'whop' === $gateway_id) {
        return '';
    }
    return $icon;
}, 30, 2);

/* The Whop gateway renders no inline fields at checkout; hide its empty box. */
add_action('wp_head', static function () {
    if (!function_exists('is_checkout') || !is_checkout()) {
        return;
    }
    echo '<style>.payment_box.payment_method_whop{display:none!important}</style>';
});

/* Preselection follows row order. On a full checkout page load the first
   gateway row for the visitor's zone becomes the selected one (KE: the
   mobile-money row, elsewhere: the card row). AJAX refreshes are skipped so a
   customer's own pick is never overridden mid-checkout. If the picked gateway
   is not available for the viewer, WooCommerce falls back to the first row. */
add_action('template_redirect', static function () {
    if (wp_doing_ajax() || isset($_GET['wc-ajax'])) {
        return;
    }
    if (!function_exists('is_checkout') || !is_checkout() || is_wc_endpoint_url()) {
        return;
    }
    if (!function_exists('WC') || !WC()->session) {
        return;
    }
    $ke = function_exists('sa_gc_zone') && sa_gc_zone() === 'KE';
    WC()->session->set('chosen_payment_method', $ke ? 'pesapal' : 'whop');
});
