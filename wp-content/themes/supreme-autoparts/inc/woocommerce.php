<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

add_action('after_setup_theme', static function (): void {
    // WooCommerce gallery already declared in setup.php
}, 20);

// Remove default Woo wrappers; theme provides its own.
remove_action('woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10);
remove_action('woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10);

add_action('woocommerce_before_main_content', static function (): void {
    echo '<main id="primary" class="sa-main sa-woo"><div class="sa-container">';
}, 10);

add_action('woocommerce_after_main_content', static function (): void {
    echo '</div></main>';
}, 10);

add_filter('woocommerce_enqueue_styles', static function (array $styles): array {
    // Keep general Woo styles; theme overrides via style.css
    return $styles;
});

add_filter('loop_shop_per_page', static fn (): int => 24);
add_filter('loop_shop_columns', static fn (): int => 4);

add_filter('woocommerce_product_add_to_cart_text', static function (string $text): string {
    return __('Add to cart', 'supreme-autoparts');
});
