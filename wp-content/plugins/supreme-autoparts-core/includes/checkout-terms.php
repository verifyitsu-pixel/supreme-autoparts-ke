<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Policy URLs for checkout reminders.
 *
 * @return array<string, string> label => url
 */
function sa_core_checkout_policy_links(): array
{
    $slugs = [
        'Terms of Service'      => 'terms',
        'Privacy Policy'        => 'privacy-policy',
        'Chargeback & Disputes' => 'chargeback-policy',
        'Returns'               => 'returns',
        'Refund Policy'         => 'refund-policy',
        'Shipping Policy'       => 'shipping-policy',
        'Cookie Policy'         => 'cookie-policy',
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
 * Reminder of Terms + Privacy + Chargeback + Returns before place order / terms checkbox.
 */
add_action('woocommerce_checkout_before_terms_and_conditions', static function (): void {
    $links = sa_core_checkout_policy_links();
    echo '<div class="sa-checkout-policy-notice woocommerce-info" role="note">';
    echo '<p><strong>' . esc_html__('Before you place your order', 'supreme-autoparts-core') . '</strong></p>';
    echo '<p>' . esc_html__('By placing an order you confirm you have read and agree to our store policies:', 'supreme-autoparts-core') . '</p>';
    echo '<ul class="sa-checkout-policy-notice__list">';
    foreach ($links as $label => $url) {
        printf(
            '<li><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></li>',
            esc_url($url),
            esc_html($label)
        );
    }
    echo '</ul>';
    echo '<p>' . esc_html__('Questions or payment disputes:', 'supreme-autoparts-core') . ' ';
    echo '<a href="mailto:calvin@supremeautoparts.co.ke">calvin@supremeautoparts.co.ke</a>.</p>';
    echo '</div>';
}, 5);

/**
 * Also show a compact list just above Place order if the theme skips terms hooks.
 */
add_action('woocommerce_review_order_before_submit', static function (): void {
    if (did_action('woocommerce_checkout_before_terms_and_conditions')) {
        return;
    }
    $links = sa_core_checkout_policy_links();
    echo '<div class="sa-checkout-policies" style="margin:1rem 0;padding:1rem;border:1px solid #333;border-radius:8px;background:#111;font-size:.9rem;">';
    echo '<p style="margin:0 0 .5rem;"><strong>' . esc_html__('Before you place your order', 'supreme-autoparts-core') . '</strong></p>';
    echo '<ul style="margin:0;padding-left:1.2rem;columns:2;gap:1rem;">';
    foreach ($links as $label => $url) {
        printf(
            '<li style="margin:.2rem 0;"><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></li>',
            esc_url($url),
            esc_html($label)
        );
    }
    echo '</ul></div>';
}, 5);

/**
 * Ensure terms checkbox is required even if theme overrides.
 */
add_action('woocommerce_checkout_process', static function (): void {
    $terms_id = (int) get_option('woocommerce_terms_page_id');
    if ($terms_id <= 0) {
        return;
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    if (empty($_POST['terms'])) {
        wc_add_notice(
            __('Please read and accept the Terms of Service to place your order.', 'supreme-autoparts-core'),
            'error'
        );
    }
}, 20);

/**
 * Force Woo to show terms checkbox when terms page is configured.
 */
add_filter('woocommerce_checkout_show_terms', '__return_true');
