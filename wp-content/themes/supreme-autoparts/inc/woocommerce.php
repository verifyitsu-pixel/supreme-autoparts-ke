<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

remove_action('woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10);
remove_action('woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10);

add_action('woocommerce_before_main_content', static function (): void {
    echo '<main id="primary" class="sa-main sa-woo"><div class="sa-container">';
}, 10);

add_action('woocommerce_after_main_content', static function (): void {
    echo '</div></main>';
}, 10);

add_filter('woocommerce_enqueue_styles', static function (array $styles): array {
    return $styles;
});

add_filter('loop_shop_per_page', static fn (): int => 24);
add_filter('loop_shop_columns', static fn (): int => 4);

add_filter('woocommerce_product_add_to_cart_text', static function (string $text): string {
    return __('Add to cart', 'supreme-autoparts');
});

add_filter('woocommerce_add_to_cart_fragments', static function (array $fragments): array {
    $count = (function_exists('WC') && WC()->cart) ? (int) WC()->cart->get_cart_contents_count() : 0;
    $fragments['span.sa-cart-count'] = '<span class="sa-cart-count" data-sa-cart-count>' . esc_html((string) $count) . '</span>';
    ob_start();
    woocommerce_mini_cart();
    $mini = ob_get_clean();
    $fragments['div.widget_shopping_cart_content'] = '<div class="widget_shopping_cart_content">' . $mini . '</div>';
    return $fragments;
});

// Quantity steppers markup wrapper.
add_action('wp_footer', static function (): void {
    if (!function_exists('is_woocommerce')) {
        return;
    }
}, 5);

add_filter('woocommerce_sale_flash', static function (string $html): string {
    return '<span class="onsale sa-badge sa-badge--sale">' . esc_html__('Sale', 'supreme-autoparts') . '</span>';
});


add_filter('woocommerce_loop_add_to_cart_args', static function (array $args): array {
    $class = isset($args['class']) ? (string) $args['class'] : 'button';
    if (strpos($class, 'sa-btn') === false) {
        $class .= ' sa-btn sa-btn--block sa-product-card__atc';
    }
    $args['class'] = trim(preg_replace('/\s+/', ' ', $class));
    return $args;
});

// Keep KES visible in price HTML (Woo currency code / symbol).
add_filter('woocommerce_currency_symbol', static function (string $symbol, string $currency): string {
    if (strtoupper($currency) === 'KES' && $symbol !== '' && stripos($symbol, 'KES') === false) {
        return 'KES';
    }
    return $symbol;
}, 10, 2);

// Custom product cards — suppress default loop title/price/thumb/button (rendered in content-product.php).
add_action('init', static function (): void {
    remove_action('woocommerce_before_shop_loop_item_title', 'woocommerce_show_product_loop_sale_flash', 10);
    remove_action('woocommerce_before_shop_loop_item_title', 'woocommerce_template_loop_product_thumbnail', 10);
    remove_action('woocommerce_shop_loop_item_title', 'woocommerce_template_loop_product_title', 10);
    remove_action('woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_price', 10);
    remove_action('woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_rating', 5);
    remove_action('woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10);
    remove_action('woocommerce_before_shop_loop_item', 'woocommerce_template_loop_product_link_open', 10);
    remove_action('woocommerce_after_shop_loop_item', 'woocommerce_template_loop_product_link_close', 5);
});
