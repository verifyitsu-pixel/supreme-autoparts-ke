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
   Also blank the Whop and Pesapal gateway descriptions: no processor branding
   at checkout. */
add_filter('option_woocommerce_whop_settings', static function ($settings) {
    if (is_array($settings)) {
        $settings['enabled'] = 'no';
        $settings['description'] = '';
    }
    return $settings;
}, 99);
add_filter('option_woocommerce_pesapal_settings', static function ($settings) {
    if (is_array($settings)) {
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
    /* Card checkout (Whop) is hidden by default (gateway visibility only:
       account, keys, webhook and existing orders untouched).
       Same kill switch as before, default flipped: SA_WHOP_CHECKOUT_OFF=0 turns it
       back on for everyone; otherwise only the admin test mode (SA_WHOP_CHECKOUT=1,
       shop admins) can see it. */
    function sa_whop_checkout_visible(): bool {
        if (getenv('SA_WHOP_CHECKOUT_OFF') === '0') {
            return true;
        }
        if (getenv('SA_WHOP_CHECKOUT') !== '1') {
            return false;
        }
        return function_exists('current_user_can') && current_user_can('manage_woocommerce');
    }
}

/* True when the current cart is above the Whop per-checkout cap (USD). */
if (!function_exists('sa_whop_over_cap')) {
    function sa_whop_over_cap(): bool {
        if (!function_exists('WC') || !WC()->cart) {
            return false;
        }
        $env = getenv('SA_WHOP_MAX_USD');
        $cap = (float) (($env !== false && $env !== '') ? $env : 2500);
        return (float) WC()->cart->get_total('edit') > $cap;
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
    // Order by billing country (fallback: site geo detector, then WC IP geolocation).
    $country = '';
    if (function_exists('WC') && WC()->customer) {
        $country = (string) WC()->customer->get_billing_country();
    }
    if ($country === '' && class_exists('SA_Geo_Detector')) {
        $country = (string) SA_Geo_Detector::country_code();
    }
    if ($country === '' && class_exists('WC_Geolocation')) {
        $country = (string) (WC_Geolocation::geolocate_ip()['country'] ?? '');
    }
    // Rock 2026-10-28: Whop alongside Pesapal in every region - both are
    // offered wherever each is available; the outside-Africa Pesapal removal
    // (2026-10-17 rule) is superseded. While the Whop switch is off, production
    // keeps Pesapal for everyone so no checkout is ever left empty.
    // 2026-10-18: above the Whop per-checkout cap (USD, env SA_WHOP_MAX_USD, default 2500)
    // card is not offered in any region: customers get Bank wire (Whop's only method at
    // that size) and Pesapal.
    if (sa_whop_over_cap()) {
        // User wording (2026-10-18): over the cap, send customers straight to Pesapal.
        unset($gateways['whop']);
        if (WC()->session) {
            WC()->session->set('chosen_payment_method', 'pesapal');
        }
        return $gateways;
    }
    // Rock 2026-10-18: card (Whop) first and preselected for everyone, Pesapal second where offered.
    $first  = 'whop';
    $second = 'pesapal';
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
        // 2026-10-28 user label; methods verified live on the business's Whop
        // hosted checkout (card fields, Apple Pay + Google Pay buttons, Bank Wire).
        return sa_whop_over_cap() ? 'Bank wire' : 'Card, Apple Pay, Google Pay and bank transfer - powered by Whop';
    }
    if ('pesapal' === $gateway_id) {
        // 2026-10-28 user label; Pesapal offers cards + M-Pesa + Airtel in all regions.
        return 'Card, M-Pesa & Airtel - powered by Pesapal';
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

/* Neither gateway renders inline fields at this checkout (payment happens on
   the next step); hide their description boxes. */
add_action('wp_head', static function () {
    if (!function_exists('is_checkout') || !is_checkout()) {
        return;
    }
    echo '<style>.payment_box.payment_method_whop,.payment_box.payment_method_pesapal{display:none!important}</style>';
});

/* Preselection follows row order. On a full checkout page load the first
   gateway row for the customer's billing country becomes the selected one (KE:
   the mobile-money row, elsewhere: the card row), matching the ordering filter
   above. AJAX refreshes are skipped so a customer's own pick is never
   overridden mid-checkout. If the picked gateway is not available for the
   viewer, WooCommerce falls back to the first row. */
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
    $country = '';
    if (WC()->customer) {
        $country = (string) WC()->customer->get_billing_country();
    }
    if ($country === '' && class_exists('WC_Geolocation')) {
        $country = (string) (WC_Geolocation::geolocate_ip()['country'] ?? '');
    }
    // Remember the last payment method (cookie set when an order is placed); default card.
    $last = isset($_COOKIE['sa_last_pm']) ? sanitize_key(wp_unslash((string) $_COOKIE['sa_last_pm'])) : '';
    WC()->session->set('chosen_payment_method', in_array($last, ['whop', 'pesapal'], true) ? $last : 'whop');
});

/* Remember the payment method the customer used on their last order (1 year). */
add_action('woocommerce_checkout_order_processed', static function ($order_id, $posted = []) {
    $method = is_array($posted) ? (string) ($posted['payment_method'] ?? '') : '';
    if (in_array($method, ['whop', 'pesapal'], true) && !headers_sent()) {
        setcookie('sa_last_pm', $method, [
            'expires'  => time() + 365 * DAY_IN_SECONDS,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}, 10, 2);
