<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_enqueue_scripts', static function (): void {
    wp_enqueue_style(
        'sa-google-fonts',
        'https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,600..900&family=Inter:wght@400;500;600;700&display=swap',
        [],
        null
    );
    wp_enqueue_style('supreme-autoparts', get_stylesheet_uri(), ['sa-google-fonts'], SA_THEME_VERSION);
    wp_enqueue_style(
        'supreme-autoparts-main',
        SA_THEME_URI . '/assets/css/main.css',
        ['supreme-autoparts'],
        SA_THEME_VERSION
    );
    wp_enqueue_style(
        'supreme-autoparts-loader',
        SA_THEME_URI . '/assets/css/sa-loader.css',
        ['supreme-autoparts-main'],
        SA_THEME_VERSION
    );
    wp_enqueue_script(
        'supreme-autoparts',
        SA_THEME_URI . '/assets/js/theme.js',
        ['jquery'],
        SA_THEME_VERSION,
        true
    );
    wp_enqueue_script(
        'supreme-autoparts-loader',
        SA_THEME_URI . '/assets/js/sa-loader.js',
        ['jquery', 'supreme-autoparts'],
        SA_THEME_VERSION,
        true
    );
    wp_localize_script('supreme-autoparts', 'saTheme', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'cartUrl' => function_exists('wc_get_cart_url') ? wc_get_cart_url() : '',
        'checkoutQtyNonce' => wp_create_nonce('sa_checkout_qty'),
        'i18n'    => [
            'cart'               => __('Cart', 'supreme-autoparts'),
            'termsRequired'      => __('Please accept the store policies to place your order.', 'supreme-autoparts'),
            'loading'            => __('Loading…', 'supreme-autoparts'),
            'processing'         => __('Processing…', 'supreme-autoparts'),
            'processingPayment'  => __('Processing payment…', 'supreme-autoparts'),
            'qtyUpdateFailed'    => __('Could not update quantity. Please try again.', 'supreme-autoparts'),
        ],
    ]);

    if (function_exists('is_cart') && (is_cart() || is_checkout() || is_product())) {
        wp_enqueue_script('wc-cart-fragments');
    }

    $is_checkout = function_exists('is_checkout') && is_checkout();
    $is_order_received = function_exists('is_order_received_page') && is_order_received_page();
    if ($is_checkout || $is_order_received) {
        wp_enqueue_style(
            'supreme-autoparts-checkout',
            SA_THEME_URI . '/assets/css/checkout.css',
            ['supreme-autoparts-main'],
            SA_THEME_VERSION
        );
    }

    if (function_exists('is_account_page') && is_account_page()) {
        wp_enqueue_style(
            'supreme-autoparts-myaccount',
            SA_THEME_URI . '/assets/css/myaccount.css',
            ['supreme-autoparts-main'],
            SA_THEME_VERSION
        );
    }

});

add_filter('style_loader_src', static function ($src, $handle) {
    if ($handle !== 'sa-whop-open-pay' || !is_string($src) || $src === '') {
        return $src;
    }
    return add_query_arg('ver', SA_THEME_VERSION, remove_query_arg('ver', $src));
}, 20, 2);

add_action('wp_enqueue_scripts', static function (): void {
    wp_enqueue_style('supreme-autoparts-polish', SA_THEME_URI . '/assets/css/polish.css', [], SA_THEME_VERSION . '.p1');
}, 999);

add_action('wp_enqueue_scripts', static function (): void {
    if (!function_exists('is_shop') || !(is_shop() || is_product_taxonomy() || is_search())) {
        return;
    }
    wp_enqueue_style('supreme-autoparts-filters', SA_THEME_URI . '/assets/css/filters.css', ['supreme-autoparts-polish'], SA_THEME_VERSION);
    wp_enqueue_script('supreme-autoparts-filters', SA_THEME_URI . '/assets/js/filters.js', [], SA_THEME_VERSION, true);
}, 1000);

add_action('wp_enqueue_scripts', static function (): void {
    wp_enqueue_style('supreme-autoparts-cart-ui', SA_THEME_URI . '/assets/css/cart-ui.css', ['supreme-autoparts-polish'], SA_THEME_VERSION . '.cu10');
}, 1001);

/* Header search panel (optional filters) on every page. */
add_action('wp_enqueue_scripts', static function (): void {
    wp_enqueue_style('supreme-autoparts-search-panel', SA_THEME_URI . '/assets/css/search-panel.css', [], SA_THEME_VERSION . '.sp1');
    wp_enqueue_script('supreme-autoparts-search-panel', SA_THEME_URI . '/assets/js/search-panel.js', [], SA_THEME_VERSION . '.sp1', true);
    wp_localize_script('supreme-autoparts-search-panel', 'saSearchPanel', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'home'    => home_url('/'),
        'shop'    => function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/shop/'),
    ]);
}, 1001);

/* Editorial system (fonts, light header, homepage). */
add_action('wp_enqueue_scripts', static function (): void {
    wp_enqueue_style('supreme-autoparts-editorial', SA_THEME_URI . '/assets/css/editorial.css', [], SA_THEME_VERSION);
}, 1003);

/* Homepage cinematic hero: live suggestions, vehicle quick-select, scroll reveal. */
add_action('wp_enqueue_scripts', static function (): void {
    if (!is_front_page()) {
        return;
    }
    wp_enqueue_script('supreme-autoparts-hero', SA_THEME_URI . '/assets/js/hero.js', [], SA_THEME_VERSION, true);
    wp_localize_script('supreme-autoparts-hero', 'saHero', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'store'   => rest_url('wc/store/v1/products'),
    ]);
}, 1002);
