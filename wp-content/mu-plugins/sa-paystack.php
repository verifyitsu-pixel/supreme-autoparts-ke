<?php
/** Supreme Paystack integration. KES is specific to this gateway; catalogue and other gateways stay USD. */
declare(strict_types=1);
defined('ABSPATH') || exit;

function sa_paystack_rate(): ?float {
    $r = class_exists('SA_FX_Rates') ? SA_FX_Rates::rate_for('KES') : null;
    return is_numeric($r) && is_finite((float)$r) && $r > 0 ? (float)$r : null;
}
function sa_paystack_amount(float $usd, float $rate): float {
    return round($usd * $rate, 2);
}
// Reuse securely saved plugin settings. Absent credentials means no broken checkout option.
add_filter('option_woocommerce_paystack_settings', static function ($s) {
    $s = is_array($s) ? $s : [];
    $s['testmode'] = $s['testmode'] ?? 'no';
    $test = $s['testmode'] === 'yes';
    $prefix = $test ? 'test_' : 'live_';
    $s['enabled'] = ($s['enabled'] ?? 'no') === 'yes' && !empty($s[$prefix.'public_key']) && !empty($s[$prefix.'secret_key']) ? 'yes' : 'no';
    $s['title'] = 'Card, M-Pesa & Airtel - powered by Paystack (KES)';
    $s['description'] = 'Pay securely in Kenyan shillings. Your exact KES total is shown before payment.';
    $s['payment_page'] = 'redirect';
    $s['saved_cards'] = 'no';
    $s['autocomplete_order'] = 'no';
    $s['custom_metadata'] = 'no';
    $s['split_payment'] = 'no';
    return $s;
}, 100);

add_filter('woocommerce_available_payment_gateways', static function ($gateways) {
    if (!is_array($gateways) || !isset($gateways['paystack'])) return $gateways;
    $allowed = sa_paystack_rate() !== null;
    if (!empty($gateways['paystack']->testmode) && !current_user_can('manage_woocommerce')) $allowed = false;
    // Preserve the current >USD2500 Pesapal-only rule, and never convert an existing USD order at payment time.
    if (function_exists('WC') && WC()->cart && (float)WC()->cart->get_total('edit') > 2500) $allowed = false;
    if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay')) {
        $order = wc_get_order(absint(get_query_var('order-pay')));
        $allowed = $order && $order->get_currency() === 'KES' && $order->get_payment_method() === 'paystack';
    }
    if (!$allowed) unset($gateways['paystack']);
    return $gateways;
}, 120);

// Quote the exact payable currency/amount while leaving other methods' checkout totals and defaults intact.
add_filter('woocommerce_gateway_description', static function ($description, $id) {
    if ($id !== 'paystack' || !function_exists('WC') || !WC()->cart) return $description;
    $rate = sa_paystack_rate();
    if (!$rate) return 'This payment option is temporarily unavailable because a KES exchange rate could not be loaded.';
    $usd = (float)WC()->cart->get_total('edit');
    $amount = sa_paystack_amount($usd, $rate);
    if (WC()->session) WC()->session->set('sa_paystack_quote', ['usd'=>$usd,'rate'=>$rate,'kes'=>$amount,'at'=>time()]);
    return 'You will be charged <strong>'.wc_price($amount, ['currency'=>'KES','decimals'=>2]).'</strong>. This order is charged and recorded in KES; your bank may convert it if your account uses another currency.';
}, 100, 2);

function sa_paystack_convert_order($order, float $rate): void {
    if ($order->get_currency() === 'KES' && $order->get_meta('_sa_paystack_fx_rate')) return;
    if ($order->get_currency() !== 'USD' || !is_finite($rate) || $rate <= 0) throw new Exception('Paystack currency conversion is unavailable. Please choose another payment method.');
    $original = (float)$order->get_total();
    foreach ($order->get_items(['line_item','fee','shipping']) as $item) {
        foreach (['subtotal','total'] as $field) {
            $get = 'get_'.$field; $set = 'set_'.$field;
            if (method_exists($item,$get) && method_exists($item,$set)) $item->$set(sa_paystack_amount((float)$item->$get(),$rate));
        }
        $taxes = $item->get_taxes();
        foreach ($taxes as $type=>$values) foreach ($values as $key=>$value) $taxes[$type][$key] = sa_paystack_amount((float)$value,$rate);
        $item->set_taxes($taxes);
    }
    foreach ($order->get_items('tax') as $item) {
        $item->set_tax_total(sa_paystack_amount((float)$item->get_tax_total(),$rate));
        $item->set_shipping_tax_total(sa_paystack_amount((float)$item->get_shipping_tax_total(),$rate));
    }
    foreach ($order->get_items('coupon') as $item) {
        $item->set_discount(sa_paystack_amount((float)$item->get_discount(),$rate));
        $item->set_discount_tax(sa_paystack_amount((float)$item->get_discount_tax(),$rate));
    }
    foreach (['discount_total','discount_tax','shipping_total','shipping_tax','cart_tax','total'] as $field) {
        $get='get_'.$field; $set='set_'.$field;
        $order->$set(sa_paystack_amount((float)$order->$get(),$rate));
    }
    $order->set_currency('KES');
    $order->update_meta_data('_sa_paystack_fx_rate', $rate);
    $order->update_meta_data('_sa_paystack_original_usd_total', $original);
    $order->update_meta_data('_sa_paystack_fx_at', gmdate('c'));
}
add_action('woocommerce_checkout_create_order', static function ($order,$data) {
    if (($data['payment_method'] ?? '') !== 'paystack') return;
    $quote = function_exists('WC') && WC()->session ? WC()->session->get('sa_paystack_quote') : null;
    $rate = is_array($quote) && ($quote['at'] ?? 0) > time()-1800 && abs((float)($quote['usd'] ?? -1)-(float)$order->get_total()) < 0.005 ? (float)$quote['rate'] : null;
    if (!$rate || (float)$order->get_total() > 2500) throw new Exception('Paystack is unavailable for this order. Please choose another payment method.');
    sa_paystack_convert_order($order,$rate);
}, 100, 2);

// The legacy USD notice must not contradict the new gateway's KES charge.
add_filter('gettext', static function ($translated,$text,$domain) {
    if ($domain === 'supreme-autoparts' && $text === "Charged in USD. M-Pesa / Airtel amounts are shown in KES at the payment provider's rate.") {
        return 'Orders are recorded in USD unless you select the KES payment option. The exact KES total is shown with that option before payment.';
    }
    return $translated;
}, 20, 3);

// Let the gateway currency explanation wrap inside the narrow order summary.
add_action('wp_head', static function () {
    if (function_exists('is_checkout') && is_checkout()) {
        echo '<style>.woocommerce-checkout-review-order-table tfoot tr.sa-usd-note td{white-space:normal!important;text-align:left!important;width:auto!important;overflow-wrap:anywhere;font-weight:400}</style>';
    }
}, 100);

// Enabled account channels, including hosted-checkout Apple Pay and bank transfer.
add_filter('woocommerce_gateway_icon', static function ($icon,$id) {
    if ($id !== 'paystack') return $icon;
    $html='<span class="sa-paystack-icons" aria-label="Visa, Mastercard, M-Pesa, Airtel Money, Apple Pay and bank transfer">';
    foreach (['visa'=>'Visa','mastercard'=>'Mastercard','mpesa'=>'M-Pesa','airtel'=>'Airtel Money'] as $file=>$label) {
        $html.='<img src="'.esc_url(plugins_url('woo-paystack/assets/images/supreme/'.$file.'.svg')).'" alt="'.esc_attr($label).'" style="display:inline-block;height:24px;width:auto;max-width:72px;margin:4px 4px 0 0;vertical-align:middle">';
    }
    foreach (['apple-pay'=>'Apple Pay','bank-transfer'=>'Bank transfer'] as $file=>$label) {
        $html.='<img src="'.esc_url(get_stylesheet_directory_uri().'/assets/images/payments/'.$file.'.svg').'" alt="'.esc_attr($label).'" style="display:inline-block;height:24px;width:auto;max-width:72px;margin:4px 4px 0 0;vertical-align:middle">';
    }
    return $html.'</span>';
},100,2);

// Explain the existing-order currency guard rather than presenting an unusable method.
add_action('woocommerce_pay_order_before_payment', static function (): void {
    $order = wc_get_order(absint(get_query_var('order-pay')));
    if (!$order || $order->get_currency() !== 'USD') return;
    echo '<p class="woocommerce-info">Paystack is available for new KES payments. This existing order is in USD, so Paystack is not offered here. Its currency will not be changed automatically.</p>';
});
