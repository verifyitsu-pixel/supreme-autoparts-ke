<?php
/** Explicit source-backed special-order labels for new targeted catalog additions. */
if (!defined('ABSPATH')) { exit; }
function sa_source_special_order_product($product): bool
{
    return $product instanceof WC_Product && $product->get_meta('_sa_source_special_order') === 'yes';
}
add_filter('woocommerce_get_availability_text', static function ($text, $product) {
    return sa_source_special_order_product($product) && $product->is_on_backorder(1)
        ? 'Special order - available on backorder' : $text;
}, 20, 2);
add_filter('woocommerce_cart_item_backorder_notification', static function ($text, $product_id) {
    $product = wc_get_product($product_id);
    return sa_source_special_order_product($product)
        ? '<p class="backorder_notification">Special order - available on backorder</p>' : $text;
}, 20, 2);

add_action('woocommerce_after_cart_item_name', static function ($cart_item, $key): void {
    $product = $cart_item['data'] ?? null;
    if (sa_source_special_order_product($product) && $product->is_on_backorder(1)) {
        echo '<p class="backorder_notification">Special order - available on backorder.</p>';
    }
}, 20, 2);
