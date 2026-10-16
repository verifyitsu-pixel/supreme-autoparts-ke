<?php
/**
 * Guest pay: any order-pay link (/checkout/order-pay/ID/?pay_for_order=true&key=...) works without logging in,
 * as long as the order key in the URL matches the order. Covers emails, invoices, booking/roadside pay links,
 * the admin "Customer payment page" link and links shared from the Woo mobile app.
 *
 * @package Supreme_Autoparts_Core
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

/** Order id whose key in the current request is valid, else 0. */
function sa_gp_valid_key_order(int $order_id = 0): int
{
    static $cache = [];
    $key = isset($_GET['key']) ? wc_clean(wp_unslash((string) $_GET['key'])) : '';
    if ($key === '' || !function_exists('wc_get_order')) {
        return 0;
    }
    if (!$order_id) {
        global $wp;
        $order_id = absint($wp->query_vars['order-pay'] ?? 0);
    }
    if (!$order_id) {
        $order_id = absint(wc_get_order_id_by_order_key($key));
    }
    if (!$order_id) {
        return 0;
    }
    if (!isset($cache[$order_id . $key])) {
        $o = wc_get_order($order_id);
        $cache[$order_id . $key] = ($o && hash_equals((string) $o->get_order_key(), $key)) ? $order_id : 0;
    }
    return $cache[$order_id . $key];
}

add_filter('user_has_cap', static function (array $allcaps, array $caps, array $args) {
    if (($args[0] ?? '') === 'pay_for_order') {
        $oid = absint($args[2] ?? 0);
        if ($oid && sa_gp_valid_key_order($oid) === $oid) {
            $allcaps['pay_for_order'] = true;
        }
    }
    return $allcaps;
}, 20, 3);

/* Newer Woo may ask guests to confirm their email before paying/viewing; a valid key is proof enough. */
add_filter('woocommerce_order_email_verification_required', static function ($required, $order = null) {
    if ($order instanceof WC_Order && sa_gp_valid_key_order($order->get_id()) === $order->get_id()) {
        return false;
    }
    return $required;
}, 20, 2);

/* Never let a stray login wall or redirect hide the pay page when the key is valid. */
add_action('template_redirect', static function (): void {
    if (function_exists('is_checkout_pay_page') && is_checkout_pay_page() && sa_gp_valid_key_order()) {
        nocache_headers();
    }
}, 1);

/* ---------- Service wording on the pay page for booking-fee and roadside pay-link orders ---------- */

/** The order being paid on /checkout/order-pay/, when it pays for a service (booking fee or roadside). */
function sa_gp_service_order(): ?WC_Order
{
    // Reentrancy guard: loading an order translates strings, which re-enters the gettext filter below.
    static $busy = false;
    static $done = false;
    static $found = null;
    if ($busy || !did_action('wp')) {
        return null;
    }
    if ($done) {
        return $found;
    }
    $busy = true;
    try {
        if (function_exists('is_checkout_pay_page') && is_checkout_pay_page() && function_exists('wc_get_order')) {
            global $wp;
            $oid = absint($wp->query_vars['order-pay'] ?? 0);
            if (!$oid && isset($_GET['key'])) {
                $oid = absint(wc_get_order_id_by_order_key(wc_clean(wp_unslash((string) $_GET['key']))));
            }
            $o = $oid ? wc_get_order($oid) : null;
            if ($o instanceof WC_Order && ($o->get_meta('_sa_booking_fee_for') || $o->get_meta('_sa_rs_for'))) {
                $found = $o;
            }
        }
        $done = true;
    } finally {
        $busy = false;
    }
    return $found;
}

/* Rename the product-table headers and the guest warning only while paying for a service order. */
add_filter('gettext', static function (string $translated, string $text, string $domain): string {
    if ($domain !== 'woocommerce' || !sa_gp_service_order()) {
        return $translated;
    }
    if ($text === 'Product') {
        return 'Service';
    }
    if ($text === 'Totals') {
        return 'Amount';
    }
    if ($text === 'You are paying for a guest order. Please continue with payment only if you recognize this order.') {
        return 'You are paying for a service as a guest. Please continue with payment only if you recognize it.';
    }
    return $translated;
}, 20, 3);
