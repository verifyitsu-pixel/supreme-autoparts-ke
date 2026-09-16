<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Policy URLs shown before Place order (terms tick reminder).
 *
 * Required customer-facing set: terms, privacy, chargeback, cookies, refund.
 * Extra store policies (returns, shipping, data) stay linked for clarity.
 *
 * @return array<string, string> label => url
 */
function sa_core_checkout_policy_links(): array
{
    $slugs = [
        'Terms of Service'      => 'terms',
        'Privacy Policy'        => 'privacy-policy',
        'Chargeback & Disputes' => 'chargeback-policy',
        'Cookie Policy'         => 'cookie-policy',
        'Refund Policy'         => 'refund-policy',
        'Returns'               => 'returns',
        'Shipping Policy'       => 'shipping-policy',
        'Data Policy'           => 'data-policy',
    ];
    $out = [];
    foreach ($slugs as $label => $slug) {
        $page = get_page_by_path($slug);
        $url = $page ? get_permalink($page) : home_url('/' . $slug . '/');
        $out[$label] = $url;
    }
    return $out;
}

/**
 * Compact policy list markup shared by checkout hooks.
 */
function sa_core_render_checkout_policy_notice(string $variant = 'full'): void
{
    $links = sa_core_checkout_policy_links();
    $class = $variant === 'compact'
        ? 'sa-checkout-policy-notice sa-checkout-policy-notice--compact'
        : 'sa-checkout-policy-notice woocommerce-info';

    echo '<div class="' . esc_attr($class) . '" role="note">';
    echo '<p><strong>' . esc_html__('Before you place your order', 'supreme-autoparts-core') . '</strong></p>';
    echo '<p>' . esc_html__('By placing an order you confirm you have read and agree to our store policies. Tick the terms box below to continue.', 'supreme-autoparts-core') . '</p>';
    echo '<ul class="sa-checkout-policy-notice__list">';
    foreach ($links as $label => $url) {
        printf(
            '<li><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></li>',
            esc_url($url),
            esc_html($label)
        );
    }
    echo '</ul>';
    if ($variant === 'full') {
        echo '<p>' . esc_html__('Questions or payment disputes:', 'supreme-autoparts-core') . ' ';
        echo '<a href="mailto:calvin@supremeautoparts.co.ke">calvin@supremeautoparts.co.ke</a>.</p>';
    }
    echo '</div>';
}

/**
 * Reminder of Terms + Privacy + Chargeback + Cookies + Refund before place order / terms checkbox.
 */
add_action('woocommerce_checkout_before_terms_and_conditions', static function (): void {
    sa_core_render_checkout_policy_notice('full');
}, 5);

/**
 * Fallback list just above Place order if the theme skips terms hooks.
 */
add_action('woocommerce_review_order_before_submit', static function (): void {
    if (did_action('woocommerce_checkout_before_terms_and_conditions')) {
        return;
    }
    sa_core_render_checkout_policy_notice('compact');
}, 5);

/**
 * Ensure terms checkbox is required even if theme overrides or terms page unset.
 */
add_action('woocommerce_checkout_process', static function (): void {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    if (empty($_POST['terms'])) {
        wc_add_notice(
            __('Please read and accept the Terms of Service to place your order.', 'supreme-autoparts-core'),
            'error'
        );
    }
}, 20);

/**
 * Force Woo to show terms checkbox when a terms page is configured.
 */
add_filter('woocommerce_checkout_show_terms', '__return_true');

/**
 * Keep guest checkout + checkout login reminder enabled (idempotent soft enforce).
 */
add_action('init', static function (): void {
    if (!class_exists('WooCommerce')) {
        return;
    }
    if (get_option('woocommerce_enable_guest_checkout') !== 'yes') {
        update_option('woocommerce_enable_guest_checkout', 'yes');
    }
    if (get_option('woocommerce_enable_checkout_login_reminder') !== 'yes') {
        update_option('woocommerce_enable_checkout_login_reminder', 'yes');
    }
    if (get_option('woocommerce_enable_signup_and_login_from_checkout') !== 'yes') {
        update_option('woocommerce_enable_signup_and_login_from_checkout', 'yes');
    }
}, 30);
