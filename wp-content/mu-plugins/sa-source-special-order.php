<?php
/** Explicit source-backed special-order labels for new targeted catalog additions. */
if (!defined('ABSPATH')) { exit; }
function sa_source_special_order_product($product): bool
{
    return $product instanceof WC_Product && $product->get_meta('_sa_source_special_order') === 'yes';
}
function sa_source_orderable_unknown_product($product): bool
{
    return $product instanceof WC_Product && $product->get_meta('_sa_source_orderable_unknown') === 'yes';
}
function sa_source_fulfillment_label($product): string
{
    if (sa_source_special_order_product($product)) {
        return 'Special order - availability and delivery timing to be confirmed';
    }
    return sa_source_orderable_unknown_product($product)
        ? 'Available to order - availability and delivery timing to be confirmed' : '';
}
add_filter('woocommerce_get_availability_text', static function ($text, $product) {
    return sa_source_fulfillment_label($product) !== '' && $product->is_on_backorder(1)
        ? sa_source_fulfillment_label($product) : $text;
}, 20, 2);
add_filter('woocommerce_cart_item_backorder_notification', static function ($text, $product_id) {
    $product = wc_get_product($product_id);
    return sa_source_fulfillment_label($product) !== ''
        ? '<p class="backorder_notification">' . esc_html(sa_source_fulfillment_label($product)) . '</p>' : $text;
}, 20, 2);

add_action('woocommerce_after_cart_item_name', static function ($cart_item, $key): void {
    $product = $cart_item['data'] ?? null;
    if (sa_source_fulfillment_label($product) !== '' && $product->is_on_backorder(1)) {
        echo '<p class="backorder_notification">' . esc_html(sa_source_fulfillment_label($product)) . '</p>';
    }
}, 20, 2);

add_filter('woocommerce_cart_item_name', static function ($name, $cart_item, $key) {
    $product = $cart_item['data'] ?? null;
    if (sa_source_fulfillment_label($product) !== '' && $product->is_on_backorder(1)) {
        $name .= '<span class="backorder_notification" style="display:block">' . esc_html(sa_source_fulfillment_label($product)) . '</span>';
    }
    return $name;
}, 20, 3);

add_action('wp_head', static function (): void {
    if (function_exists('is_checkout') && is_checkout()) {
        echo '<style id="sa-special-order-checkout-label">.sa-co-line__t:has(.backorder_notification){display:block;-webkit-line-clamp:unset;overflow:visible}</style>';
    }
}, 20);
