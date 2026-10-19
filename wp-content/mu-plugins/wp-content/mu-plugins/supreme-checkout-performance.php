<?php
/**
 * Plugin Name: Supreme Checkout Performance
 * Description: Keep Blocks-only payment assets off the classic checkout.
 */
if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_enqueue_scripts', static function (): void {
    if (!function_exists('is_checkout') || !is_checkout()) {
        return;
    }
    // Pesapal's integration enqueues its Blocks bundle on every checkout. The
    // classic shortcode does not use it, or its React/WordPress data dependency
    // tree. Leave registration intact so a real Checkout Block still works.
    $post = get_post();
    if (!$post || !has_block('woocommerce/checkout', $post)) {
        wp_dequeue_script('pesapal-blocks-integration');
    }
    // TransactWorld is retired. Only keep its checkout handler if that gateway
    // is explicitly available again; don't load scripts for disabled methods.
    if (function_exists('WC') && WC()->payment_gateways()) {
        $available = WC()->payment_gateways()->get_available_payment_gateways();
        if (!isset($available['transactworld'])) {
            wp_dequeue_script('transactworld-checkout');
        }
    }
}, 100);

add_action('wp_enqueue_scripts', static function (): void {
    if (!function_exists('is_checkout') || !is_checkout()) {
        return;
    }
    // Selecting a method is local UI, not a request. The global loader used to
    // cover the page until its 45-second safety timeout when no totals request
    // followed. Keep real order submissions and in-flight totals protected.
    wp_add_inline_script('supreme-autoparts-loader', <<<'JS'
jQuery(function ($) {
    $(document.body).on('payment_method_selected.saCheckoutPerformance', function () {
        var form = document.querySelector('form.checkout');
        if (form && !form.classList.contains('processing') &&
            !form.querySelector('.blockUI') && window.saLoader) {
            window.saLoader.hide(true);
        }
    });
});
JS
    );
}, 110);
