<?php
/** Visible provider cards do not grant processing availability or change gateway maps. */
declare(strict_types=1);
defined('ABSPATH') || exit;
function sa_visible_missing_payment_cards(): void {
    if (!function_exists('WC') || !WC() || !WC()->payment_gateways()) return;
    $available=WC()->payment_gateways()->get_available_payment_gateways();
    $brands=['whop'=>['Whop','whop.svg','Card and supported wallets. Whop starts with USD; supported local currencies are shown in its checkout.'], 'pesapal'=>['Pesapal','pesapal.png','Card, M-Pesa and Airtel. Supported methods and currency are shown before payment.'], 'paystack'=>['Paystack','paystack.png','Card, M-Pesa and Airtel in KES on the current live integration.']];
    $missing=array_diff_key($brands,$available);
    if (!$missing) return;
    echo '<section class="sa-visible-providers" aria-label="Other payment providers" style="margin:20px 0"><h3>Payment providers</h3>';
    foreach($missing as $id=>$b) {
        echo '<div class="sa-provider-visible" style="border:1px solid #ddd;border-radius:8px;padding:18px;margin:12px 0;background:#fff"><img src="'.esc_url(get_stylesheet_directory_uri().'/assets/images/providers/'.$b[1]).'" alt="'.esc_attr($b[0]).'" style="display:block;width:100%;max-width:280px;height:80px;object-fit:contain;object-position:left center"><p>'.esc_html($b[2]).'</p><p>This provider is not available for this payment in its current currency or checkout state. Use an available payment method above.</p></div>';
    }
    echo '</section>';
}
add_action('woocommerce_review_order_before_payment','sa_visible_missing_payment_cards',30);
add_action('woocommerce_pay_order_before_payment','sa_visible_missing_payment_cards',30);
