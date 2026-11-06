<?php
// Aggregate order status counter. One option row per (gateway,status). Atomic SQL increment, no read-modify-write.
// Counts only: no order id, customer data or free text is stored.
if (!defined('ABSPATH')) {
    exit;
}

add_action('woocommerce_order_status_changed', static function ($order_id, $from, $to, $order): void {
    global $wpdb;
    static $allowed_status = ['failed' => 1, 'processing' => 1, 'completed' => 1];
    if (!isset($allowed_status[$to]) || !$order || !isset($wpdb)) {
        return;
    }
    $gw = sanitize_key((string) $order->get_payment_method());
    $known = (function_exists('WC') && WC() && WC()->payment_gateways())
        ? array_keys((array) WC()->payment_gateways()->payment_gateways()) : [];
    if ($gw === '' || !in_array($gw, $known, true)) {
        $gw = 'other';
    }
    $name = 'obs_osc_' . $gw . '_' . $to;
    if (strlen($name) > 120) {
        $name = 'obs_osc_other_' . $to;
    }
    // A counter failure must never break or print into checkout.
    $prev = $wpdb->suppress_errors(true);
    try {
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no')
             ON DUPLICATE KEY UPDATE option_value = option_value + 1",
            $name
        ));
    } catch (\Throwable $e) {
        // ignore
    }
    $wpdb->suppress_errors($prev);
}, 10, 4);
