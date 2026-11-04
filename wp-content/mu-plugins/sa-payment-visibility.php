<?php
/** Visible provider cards do not grant processing availability or change gateway maps. */
declare(strict_types=1);
defined('ABSPATH') || exit;
function sa_visible_whop_over_limit(): bool {
    if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay')) {
        $order = wc_get_order(absint(get_query_var('order-pay')));
        // Actual order currency only; never treat a KES amount as USD.
        return $order && $order->get_currency() === 'USD' && round((float)$order->get_total(),2) > 2500;
    }
    return function_exists('sa_whop_over_cap') && sa_whop_over_cap();
}
function sa_visible_missing_payment_cards(): void {
    if (!function_exists('WC') || !WC() || !WC()->payment_gateways()) return;
    $available=WC()->payment_gateways()->get_available_payment_gateways();
    // Restore the prior section: no extra missing-provider cards. Only the
    // owner's expressly requested over-limit Whop card remains visible.
    if (isset($available['whop']) || !sa_visible_whop_over_limit()) return;
    echo '<section class="sa-visible-providers" aria-label="Whop limit"><div aria-disabled="true" class="sa-provider-visible sa-whop-over-limit" style="border:1px solid #ddd;border-radius:8px;padding:18px;margin:12px 0;background:#eee;filter:grayscale(1);color:#666"><img src="'.esc_url(get_stylesheet_directory_uri().'/assets/images/providers/whop.svg').'" alt="Whop" style="display:block;width:100%;max-width:280px;height:80px;object-fit:contain;object-position:left center"><p>Above the USD 2,500 Whop limit.</p></div>';
    echo '</section>';
}
// Standard checkout calls directly inside its AJAX-replaced payment fragment.
add_action('woocommerce_pay_order_before_payment','sa_visible_missing_payment_cards',30);
