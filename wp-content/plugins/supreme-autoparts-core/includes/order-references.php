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
