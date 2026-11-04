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

/** Selected route for a USD order: native USD, or explicitly configured visible KES conversion. */
function sa_paystack_usd_quote(float $usd): ?array {
    if (!is_finite($usd) || $usd <= 0) return null;
    if (SA_Paystack_Charge_Quote::native_usd_enabled()) return ['usd'=>$usd,'rate'=>1.0,'kes'=>$usd,'charge_currency'=>'USD','at'=>time()];
    return sa_paystack_display_quote($usd);
}

/** Lock the customer-visible quote on the order; the gateway charges exactly this figure. */
function sa_paystack_lock_display_quote($order, array $quote): void {
    $order->update_meta_data(SA_PAYSTACK_DISPLAY_META_KEY, [
        'order_currency'  => $order->get_currency(),
        'order_total'     => (float)$order->get_total(),
        'charge_currency' => $quote['charge_currency'] ?? 'KES',
        'charge_amount'   => (float)$quote['kes'],
        'rate'            => (float)$quote['rate'],
        'quoted_at'       => (int)$quote['at'],
    ]);
}

/** Order total expressed in USD for the standing >2500 cap; null when unsupported or unknown. */
function sa_paystack_order_total_usd($order): ?float {
    $total = (float)$order->get_total();
    if (!is_finite($total) || $total <= 0) return null;
    $currency = strtoupper((string)$order->get_currency());
    if ($currency === 'USD') return $total;
    if ($currency === 'KES') { $r = sa_paystack_rate(); return $r ? $total / $r : null; }
    return null;
}

/**
 * The single customer-visible KES quote for a USD order's pay-for-order page.
 * The first display wins within its freshness window: notice and description
 * reuse the locked figure instead of fetching a new rate, so two tabs or a
 * rate move can never show or charge two different figures. A new quote is
 * built, displayed and locked only when none fresh matches the current order.
 */
function sa_paystack_order_pay_quote($order): ?array {
    if (!$order || strtoupper((string)$order->get_currency()) !== 'USD') return null;
    static $request_quotes = [];
    $request_key = $order->get_id().':'.$order->get_currency().':'.$order->get_total();
    if (isset($request_quotes[$request_key])) return $request_quotes[$request_key];
    $locked = $order->get_meta(SA_PAYSTACK_DISPLAY_META_KEY);
    if (is_array($locked)
        && ($locked['charge_currency'] ?? 'KES') === (SA_Paystack_Charge_Quote::native_usd_enabled() ? 'USD' : 'KES')
        && strtoupper((string)($locked['order_currency'] ?? '')) === 'USD'
        && abs((float)($locked['order_total'] ?? -1) - (float)$order->get_total()) < 0.005
        && (int)($locked['quoted_at'] ?? 0) >= time() - 1800
        && (float)($locked['rate'] ?? 0) > 0
        && (float)($locked['charge_amount'] ?? 0) > 0) {
        return $request_quotes[$request_key] = ['usd'=>(float)$locked['order_total'], 'rate'=>(float)$locked['rate'], 'kes'=>(float)$locked['charge_amount'], 'at'=>(int)$locked['quoted_at'], 'charge_currency'=>$locked['charge_currency'] ?? 'KES'];
    }
    $quote = sa_paystack_usd_quote((float)$order->get_total());
    if ($quote) {
        sa_paystack_lock_display_quote($order, $quote);
        $order->save();
    }
    if ($quote) $request_quotes[$request_key] = $quote;
    return $quote;
}

// Reuse securely saved plugin settings. Absent credentials means no broken checkout option.
add_filter('option_woocommerce_paystack_settings', static function ($s) {
    $s = is_array($s) ? $s : [];
    $s['testmode'] = $s['testmode'] ?? 'no';
    $test = $s['testmode'] === 'yes';
    $prefix = $test ? 'test_' : 'live_';
    $s['enabled'] = ($s['enabled'] ?? 'no') === 'yes' && !empty($s[$prefix.'public_key']) && !empty($s[$prefix.'secret_key']) ? 'yes' : 'no';
    $s['title'] = 'Paystack';
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
    $allowed = SA_Paystack_Charge_Quote::native_usd_enabled() || sa_paystack_rate() !== null;
    if (!in_array(strtoupper((string)get_woocommerce_currency()), ['USD','KES'], true) && !(function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay'))) $allowed = false;
    if (!empty($gateways['paystack']->testmode) && !current_user_can('manage_woocommerce')) $allowed = false;
    // Preserve the standing user rule: orders above USD 2500 go to Pesapal only.
    if (function_exists('WC') && WC()->cart && (float)WC()->cart->get_total('edit') > 2500) $allowed = false;
    // On the pay-for-order page keep every guard above and add the order's own:
    // paystack only when it is the order's chosen method, the currency is supported
    // (USD converts to KES, KES charges as-is), and the order is within the same cap.
    if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay')) {
        $order = wc_get_order(absint(get_query_var('order-pay')));
        if (!$order || $order->get_payment_method() !== 'paystack') {
            $allowed = false;
        } else {
            $currency = strtoupper((string)$order->get_currency());
            if ($currency !== 'USD' && $currency !== 'KES') $allowed = false;
            $total_usd = sa_paystack_order_total_usd($order);
            if ($total_usd === null || $total_usd > 2500) $allowed = false;
        }
    }
    if (!$allowed) unset($gateways['paystack']);
    return $gateways;
}, 120);

// Quote the exact payable amount before commitment, leaving other methods' checkout totals and defaults intact.
add_filter('woocommerce_gateway_description', static function ($description, $id) {
    if ($id !== 'paystack' || !function_exists('WC') || !WC()->cart) return $description;
    // On the pay-for-order page the cart is empty; quote the order being paid instead.
    $order = (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay'))
        ? wc_get_order(absint(get_query_var('order-pay'))) : null;
    $currency = $order ? strtoupper((string)$order->get_currency()) : strtoupper((string)get_woocommerce_currency());
    // KES orders charge their own figures: no conversion, no USD wording.
    if ($currency === 'KES') return 'Pay securely by card, M-Pesa or Airtel. This order and its payment are in Kenyan shillings; no currency conversion applies.';
    // Only USD converts to KES; anything else fails closed rather than misquoting.
    if ($currency !== 'USD') return 'This payment option is unavailable for this order\'s currency.';
    if ($order) {
        // Reuse the exact locked customer-visible quote the notice displays.
        $quote = sa_paystack_order_pay_quote($order);
    } else {
        $quote = sa_paystack_usd_quote((float)WC()->cart->get_total('edit'));
        if ($quote && WC()->session) WC()->session->set('sa_paystack_quote', $quote);
    }
    if (!$quote) return 'This payment option is temporarily unavailable because a KES exchange rate could not be loaded.';
    $scope = $order ? 'order:'.$order->get_id() : SA_Paystack_Charge_Quote::checkout_scope();
    $token = SA_Paystack_Charge_Quote::token($quote, $scope);
    $GLOBALS['sa_paystack_rendered_token'] = $token;
    if (($quote['charge_currency'] ?? 'KES') === 'USD') return 'You will be charged <strong>'.wc_price($quote['usd'], ['currency'=>'USD','decimals'=>2]).'</strong> in US dollars by card. No store currency conversion applies. Your card issuer may apply its own exchange rate or fees.';
    return 'You will be charged <strong>'.wc_price($quote['kes'], ['currency'=>'KES','decimals'=>2]).'</strong> at 1 USD = KES '.esc_html((string)$quote['rate']).'. Your order stays in US dollars; only the payment itself is charged in Kenyan shillings.';
}, 100, 2);

// Lock the customer-visible checkout quote onto the order without rewriting its currency or totals.
add_action('woocommerce_checkout_create_order', static function ($order,$data) {
    if (($data['payment_method'] ?? '') !== 'paystack') return;
    $total_usd = sa_paystack_order_total_usd($order);
    if ($total_usd === null || $total_usd > 2500) throw new Exception('Paystack is unavailable for this order. Please choose another payment method.');
    if ($order->get_currency() === 'KES') return; // Same-currency orders charge their own figures; no quote needed.
    if (strtoupper((string)$order->get_currency()) !== 'USD') throw new Exception('Paystack does not support this order currency.');
    $quote = SA_Paystack_Charge_Quote::submitted_quote($order, true);
    if (!$quote) throw new Exception('Please review the exact Paystack charge and try again.');
    $order->update_meta_data('_sa_paystack_checkout_token_hash', hash('sha256', $_POST['sa_paystack_quote_token']));
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
    if (!$order || $order->get_payment_method() !== 'paystack') return;
    $currency = strtoupper((string)$order->get_currency());
    if ($currency === 'KES') return; // Same-currency order: charged as-is, nothing to quote.
    if ($currency !== 'USD') {
        echo '<p class="woocommerce-info">Paystack is unavailable for this order\'s currency. Please choose another payment method.</p>';
        return;
    }
    $quote = sa_paystack_order_pay_quote($order);
    if (!$quote) {
        echo '<p class="woocommerce-info">Paystack is temporarily unavailable because a KES exchange rate could not be loaded. Please choose another payment method.</p>';
        return;
    }
    if (($quote['charge_currency'] ?? 'KES') === 'USD') { echo '<p class="woocommerce-info">This order and its card payment are in US dollars. Exact charge: '.wc_price($quote['usd'], ['currency'=>'USD','decimals'=>2]).'. No store currency conversion applies.</p>'; return; }
    echo '<p class="woocommerce-info">This order is in US dollars. You will be charged <strong>'.wc_price($quote['kes'], ['currency'=>'KES','decimals'=>2]).'</strong> at 1 USD = KES '.esc_html((string)$quote['rate']).'.</p>';
});

// Woo sanitizes gateway descriptions and removes inputs. Render the signed quote
// inside the actual form immediately before its submit button instead.
function sa_paystack_render_quote_token(): void {
    $token = $GLOBALS['sa_paystack_rendered_token'] ?? '';
    if ($token) echo '<input type="hidden" name="sa_paystack_quote_token" value="'.esc_attr($token).'">';
}
add_action('woocommerce_review_order_before_submit', 'sa_paystack_render_quote_token');
add_action('woocommerce_pay_order_before_submit', 'sa_paystack_render_quote_token');

function sa_paystack_native_usd_context(): bool {
    $order = (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay')) ? wc_get_order(absint(get_query_var('order-pay'))) : null;
    $currency = $order ? $order->get_currency() : get_woocommerce_currency();
    return strtoupper((string)$currency) === 'USD' && SA_Paystack_Charge_Quote::native_usd_enabled();
}
add_filter('woocommerce_gateway_title', static function($title,$id){
    if ($id !== 'paystack') return $title;
    return sa_paystack_native_usd_context() ? 'Card - Paystack (USD)' : 'Card, M-Pesa & Airtel - Paystack (KES)';
},100,2);
add_filter('woocommerce_gateway_icon', static function($icon,$id){
    if ($id !== 'paystack' || !sa_paystack_native_usd_context()) return $icon;
    return '<span aria-label="Visa and Mastercard"><img src="'.esc_url(plugins_url('woo-paystack/assets/images/supreme/visa.svg')).'" alt="Visa" style="height:24px;width:auto"><img src="'.esc_url(plugins_url('woo-paystack/assets/images/supreme/mastercard.svg')).'" alt="Mastercard" style="height:24px;width:auto"></span>';
},110,2);
add_filter('wc_paystack_payment_channels', static function($channels,$id,$order){
    $q = SA_Paystack_Charge_Quote::submitted_quote($order);
    return $q && ($q['charge_currency'] ?? 'KES') === 'USD' ? ['card'] : $channels;
},100,3);
