<?php
/** Supreme Paystack integration. Orders stay in their own currency (USD catalogue); this gateway charges the exact customer-visible KES quote. */
declare(strict_types=1);
defined('ABSPATH') || exit;

// Must match SA_Paystack_Charge_Quote::DISPLAY_META_KEY in woo-paystack (mu-plugins load before plugins).
const SA_PAYSTACK_DISPLAY_META_KEY = '_sa_paystack_display_quote';

function sa_paystack_rate(): ?float {
    $r = class_exists('SA_FX_Rates') ? SA_FX_Rates::rate_for('KES') : null;
    return is_numeric($r) && is_finite((float)$r) && $r > 0 ? (float)$r : null;
}
function sa_paystack_amount(float $usd, float $rate): float {
    return round($usd * $rate, 2);
}

/** Quote a USD amount in KES for customer-visible display; null when no truthful quote exists. */
function sa_paystack_display_quote(float $usd): ?array {
    $rate = sa_paystack_rate();
    if (!$rate || !is_finite($usd) || $usd <= 0) return null;
    return ['usd'=>$usd, 'rate'=>$rate, 'kes'=>sa_paystack_amount($usd,$rate), 'at'=>time()];
}

/** Lock the customer-visible quote on the order; the gateway charges exactly this figure. */
function sa_paystack_lock_display_quote($order, array $quote): void {
    $order->update_meta_data(SA_PAYSTACK_DISPLAY_META_KEY, [
        'order_currency'  => $order->get_currency(),
        'order_total'     => (float)$order->get_total(),
        'charge_currency' => 'KES',
        'charge_amount'   => (float)$quote['kes'],
        'rate'            => (float)$quote['rate'],
        'quoted_at'       => (int)$quote['at'],
    ]);
}

// Reuse securely saved plugin settings. Absent credentials means no broken checkout option.
add_filter('option_woocommerce_paystack_settings', static function ($s) {
    $s = is_array($s) ? $s : [];
    $s['testmode'] = $s['testmode'] ?? 'no';
    $test = $s['testmode'] === 'yes';
    $prefix = $test ? 'test_' : 'live_';
    $s['enabled'] = ($s['enabled'] ?? 'no') === 'yes' && !empty($s[$prefix.'public_key']) && !empty($s[$prefix.'secret_key']) ? 'yes' : 'no';
    $s['title'] = 'Card, M-Pesa & Airtel - powered by Paystack (KES)';
    $s['description'] = 'Pay securely by card, M-Pesa or Airtel. The exact KES charge and rate are shown before payment; your order is recorded in US dollars.';
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
    // Preserve the standing user rule: orders above USD 2500 go to Pesapal only.
    if (function_exists('WC') && WC()->cart && (float)WC()->cart->get_total('edit') > 2500) $allowed = false;
    // On the pay-for-order page, offer Paystack only when it is the order's chosen method. USD orders are quoted in KES at payment time; their totals are never rewritten.
    if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay')) {
        $order = wc_get_order(absint(get_query_var('order-pay')));
        $allowed = $order && $order->get_payment_method() === 'paystack';
    }
    if (!$allowed) unset($gateways['paystack']);
    return $gateways;
}, 120);

// Quote the exact payable amount before commitment, leaving other methods' checkout totals and defaults intact.
add_filter('woocommerce_gateway_description', static function ($description, $id) {
    if ($id !== 'paystack' || !function_exists('WC') || !WC()->cart) return $description;
    // On the pay-for-order page the cart is empty; quote the order being paid instead.
    $on_order_pay = function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay');
    $usd = (float)WC()->cart->get_total('edit');
    if ($on_order_pay) {
        $order = wc_get_order(absint(get_query_var('order-pay')));
        if ($order) $usd = (float)$order->get_total();
    }
    $quote = sa_paystack_display_quote($usd);
    if (!$quote) return 'This payment option is temporarily unavailable because a KES exchange rate could not be loaded.';
    if (!$on_order_pay && WC()->session) WC()->session->set('sa_paystack_quote', $quote);
    return 'You will be charged <strong>'.wc_price($quote['kes'], ['currency'=>'KES','decimals'=>2]).'</strong> at 1 USD = KES '.esc_html((string)$quote['rate']).'. Your order stays in US dollars; only the payment itself is charged in Kenyan shillings.';
}, 100, 2);

// Lock the customer-visible checkout quote onto the order without rewriting its currency or totals.
add_action('woocommerce_checkout_create_order', static function ($order,$data) {
    if (($data['payment_method'] ?? '') !== 'paystack') return;
    if ((float)$order->get_total() > 2500) throw new Exception('Paystack is unavailable for this order. Please choose another payment method.');
    if ($order->get_currency() === 'KES') return; // Same-currency orders charge their own figures; no quote needed.
    $quote = function_exists('WC') && WC()->session ? WC()->session->get('sa_paystack_quote') : null;
    $valid = is_array($quote)
        && ($quote['at'] ?? 0) > time()-1800
        && abs((float)($quote['usd'] ?? -1)-(float)$order->get_total()) < 0.005
        && (float)($quote['rate'] ?? 0) > 0
        && (float)($quote['kes'] ?? 0) > 0;
    if (!$valid) throw new Exception('Paystack could not confirm the exact KES charge for this order. Please review the payment options and try again.');
    sa_paystack_lock_display_quote($order, $quote);
}, 100, 2);

// The legacy USD notice must not contradict the gateway's quoted KES charge.
add_filter('gettext', static function ($translated,$text,$domain) {
    if ($domain === 'supreme-autoparts' && $text === "Charged in USD. M-Pesa / Airtel amounts are shown in KES at the payment provider's rate.") {
        return 'Orders are recorded in US dollars. Card, M-Pesa and Airtel payments through Paystack are charged in Kenyan shillings at the exact rate shown before payment.';
    }
    return $translated;
}, 20, 3);

// Let the gateway currency explanation wrap inside the narrow order summary.
add_action('wp_head', static function () {
    if (function_exists('is_checkout') && is_checkout()) {
        echo '<style>.woocommerce-checkout-review-order-table tfoot tr.sa-usd-note td{white-space:normal!important;text-align:left!important;width:auto!important;overflow-wrap:anywhere;font-weight:400}</style>';
    }
}, 100);

// These brands match this account's enabled Card and Mobile Money channels.
add_filter('woocommerce_gateway_icon', static function ($icon,$id) {
    if ($id !== 'paystack') return $icon;
    $html='<span class="sa-paystack-icons" aria-label="Visa, Mastercard, M-Pesa and Airtel Money">';
    foreach (['visa'=>'Visa','mastercard'=>'Mastercard','mpesa'=>'M-Pesa','airtel'=>'Airtel Money'] as $file=>$label) {
        $html.='<img src="'.esc_url(plugins_url('woo-paystack/assets/images/supreme/'.$file.'.svg')).'" alt="'.esc_attr($label).'" style="display:inline-block;height:24px;width:auto;max-width:72px;margin:4px 4px 0 0;vertical-align:middle">';
    }
    return $html.'</span>';
},100,2);

// Show and lock the exact KES charge when an existing USD order is paid through its pay-for-order page.
add_action('woocommerce_pay_order_before_payment', static function (): void {
    $order = wc_get_order(absint(get_query_var('order-pay')));
    if (!$order || $order->get_payment_method() !== 'paystack' || $order->get_currency() === 'KES') return;
    $quote = sa_paystack_display_quote((float)$order->get_total());
    if (!$quote) {
        echo '<p class="woocommerce-info">Paystack is temporarily unavailable because a KES exchange rate could not be loaded. Please choose another payment method.</p>';
        return;
    }
    sa_paystack_lock_display_quote($order, $quote);
    $order->save();
    echo '<p class="woocommerce-info">This order is in US dollars. You will be charged <strong>'.wc_price($quote['kes'], ['currency'=>'KES','decimals'=>2]).'</strong> at 1 USD = KES '.esc_html((string)$quote['rate']).'.</p>';
});
