<?php
/** Branded references for newly created orders only. Never migrate historical orders. */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }
add_action('woocommerce_new_order', static function ($order_id, $order = null): void {
    $order = $order ?: wc_get_order($order_id);
    if (!$order || $order->get_meta('_sa_order_reference') !== '') { return; }
    $order->update_meta_data('_sa_order_reference', 'Supreme-' . $order->get_id());
    $order->save_meta_data();
}, 1, 2);
add_filter('woocommerce_order_number', static function ($number, $order) {
    $reference = (string) $order->get_meta('_sa_order_reference');
    return preg_match('/^Supreme-[1-9][0-9]*$/D', $reference) ? $reference : $number;
}, 20, 2);

// Let customers use the printed reference in Woo's tracking form. Woo still
// verifies the billing email; numeric IDs and all account/order URLs stay intact.
add_filter('woocommerce_shortcode_order_tracking_order_id', static function ($value) {
    if (!is_string($value) || !preg_match('/^Supreme-([1-9][0-9]*)$/D', $value, $match)) { return $value; }
    $order = wc_get_order((int) $match[1]);
    return $order && (string) $order->get_meta('_sa_order_reference') === $value ? $order->get_id() : 0;
});
