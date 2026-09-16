<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_enqueue_scripts', static function (): void {
    wp_enqueue_style(
        'sa-google-fonts',
        'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
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
    wp_enqueue_script(
        'supreme-autoparts',
        SA_THEME_URI . '/assets/js/theme.js',
        ['jquery'],
        SA_THEME_VERSION,
        true
    );
    wp_localize_script('supreme-autoparts', 'saTheme', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'cartUrl' => function_exists('wc_get_cart_url') ? wc_get_cart_url() : '',
        'i18n'    => [
            'cart' => __('Cart', 'supreme-autoparts'),
        ],
    ]);

    if (function_exists('is_cart') && (is_cart() || is_checkout() || is_product())) {
        wp_enqueue_script('wc-cart-fragments');
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
