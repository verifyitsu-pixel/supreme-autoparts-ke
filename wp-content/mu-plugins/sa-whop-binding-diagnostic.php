<?php
/** Authenticated Whop profile comparison. Local reads only; consumed provider-read actions removed. No financial or settings writes. */
declare(strict_types=1);
if (!defined('ABSPATH')) exit;

/** No hooks. Caller must enforce authenticated manage_woocommerce + POST + nonce. */
function sa_whop_legacy_config_read_once(): array {
    $settings = get_option('woocommerce_whop_settings', []);
    if (!is_array($settings) || ($settings['company_id'] ?? '') !== 'biz_Xeq4CXFxF2Hxk6' || empty($settings['api_key'])) return ['result' => 'saved_profile_unavailable', 'requests' => 0];
    $orders = wc_get_orders(['payment_method' => 'whop', 'status' => ['pending', 'on-hold', 'failed'], 'limit' => 100, 'page' => 1, 'paginate' => true, 'orderby' => 'ID', 'order' => 'DESC']);
    $out = ['result' => 'not_started', 'requests' => 0, 'validated_configs' => 0, 'known_account_counts' => ['supreme' => 0, 'legacy_ho7' => 0, 'other_account' => 0], 'order_plan_match' => 0, 'order_plan_mismatch' => 0, 'order_plan_missing' => 0, 'unsupported_checkout_orders' => 0];
    if ((int) $orders->total > count($orders->orders)) { $out['result'] = 'local_order_query_truncated_stopped'; return $out; }
    $ids = [];
    foreach ($orders->orders as $order) {
        $id = (string) $order->get_meta('_whop_checkout_id');
        if (!preg_match('/^ch_[A-Za-z0-9]+$/D', $id)) { ++$out['unsupported_checkout_orders']; continue; }
        $ids[$id][] = (string) $order->get_meta('_whop_plan_id');
    }
    $out['unique_supported_configs'] = count($ids);
    if (count($ids) > 10) { $out['result'] = 'more_than_ten_configs_stopped'; return $out; }
    foreach ($ids as $id => $stored_plans) {
        ++$out['requests'];
        $response = wp_remote_get('https://api.whop.com/api/v1/checkout_configurations/' . rawurlencode($id), [
            'timeout' => 15, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 16384,
            'headers' => ['Authorization' => 'Bearer ' . (string) $settings['api_key'], 'Accept' => 'application/json'],
        ]);
        if (is_wp_error($response)) { $out['result'] = 'transport_error_stopped'; break; }
        $code = (int) wp_remote_retrieve_response_code($response);
        $out['last_http_code'] = $code;
        $type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
        $raw = (string) wp_remote_retrieve_body($response);
        if ($code !== 200 || strpos($type, 'json') === false || strlen($raw) >= 16384) { $out['result'] = 'http_content_type_or_size_stopped'; unset($response, $raw); break; }
        $body = json_decode($raw); unset($raw, $response);
        $valid_biz = static function ($value): bool { return is_string($value) && (bool) preg_match('/^biz_[A-Za-z0-9]+$/D', $value); };
        if (!($body instanceof stdClass) || !isset($body->id) || $body->id !== $id) { $out['result'] = 'id_or_object_shape_stopped'; unset($body); break; }
        $has_company = property_exists($body, 'company_id'); $has_account = property_exists($body, 'account_id');
        if ((!$has_company && !$has_account) || ($has_company && !$valid_biz($body->company_id)) || ($has_account && !$valid_biz($body->account_id)) || ($has_company && $has_account && $body->company_id !== $body->account_id)) { $out['result'] = 'account_alias_shape_stopped'; unset($body); break; }
        $account = $has_account ? $body->account_id : $body->company_id;
        if (property_exists($body, 'mode') && (!is_string($body->mode) || !in_array($body->mode, ['payment', 'setup'], true))) { $out['result'] = 'mode_shape_stopped'; unset($body); break; }
        if (property_exists($body, 'currency') && $body->currency !== null && (!is_string($body->currency) || !preg_match('/^[a-z][a-z0-9_]{1,15}$/D', $body->currency))) { $out['result'] = 'currency_shape_stopped'; unset($body); break; }
        $plan = '';
        if (property_exists($body, 'plan') && $body->plan !== null) {
            if (!($body->plan instanceof stdClass) || !isset($body->plan->id) || !is_string($body->plan->id) || !preg_match('/^plan_[A-Za-z0-9]+$/D', $body->plan->id)) { $out['result'] = 'plan_shape_stopped'; unset($body); break; }
            $plan = $body->plan->id;
        }
        ++$out['validated_configs'];
        $label = $account === 'biz_Xeq4CXFxF2Hxk6' ? 'supreme' : ($account === 'biz_Ho7pNx0OKgAbaC' ? 'legacy_ho7' : 'other_account');
        ++$out['known_account_counts'][$label];
        foreach ($stored_plans as $stored) {
            if ($stored === '' || $plan === '') ++$out['order_plan_missing'];
            elseif (hash_equals($stored, $plan)) ++$out['order_plan_match'];
            else ++$out['order_plan_mismatch'];
        }
        unset($body, $account, $plan);
        $out['result'] = 'all_supported_configs_validated';
    }
    unset($settings, $ids, $stored_plans);
    return $out;
}

add_action('admin_menu', static function (): void {
    add_management_page('Whop account binding', 'Whop account binding', 'manage_woocommerce', 'sa-whop-binding-diagnostic', static function (): void {
        if (!current_user_can('manage_woocommerce')) wp_die('Access denied.');
        nocache_headers();
        $saved = get_option('woocommerce_whop_settings', []);
        $saved = is_array($saved) ? $saved : [];
        $gateways = function_exists('WC') && WC() ? WC()->payment_gateways()->payment_gateways() : [];
        $g = $gateways['whop'] ?? null;
        if (!$g || !method_exists($g, 'get_company_id')) { echo '<p>Whop gateway unavailable.</p>'; return; }
        $env = static function (string $key): string {
            $value = getenv($key);
            if ($value === false || $value === '') $value = $_ENV[$key] ?? $_SERVER[$key] ?? '';
            return is_string($value) ? trim($value) : '';
        };
        $company = static function ($value): string { return is_string($value) && preg_match('/^biz_[A-Za-z0-9]+$/D', $value) ? $value : 'unknown'; };
        $r = ['checked_at_utc' => gmdate('c'), 'saved_company' => $company($saved['company_id'] ?? ''), 'effective_company' => $company($g->get_company_id()), 'sandbox' => $g->is_sandbox_mode(), 'read_only' => true, 'external_requests' => false];
        foreach (['api_key' => ['WHOP_API_KEY', 'get_api_key'], 'webhook_secret' => ['WHOP_WEBHOOK_SECRET', 'get_webhook_secret'], 'company_id' => ['WHOP_COMPANY_ID', 'get_company_id']] as $key => [$variable, $getter]) {
            $s = (string) ($saved[$key] ?? ''); $e = $env($variable); $effective = (string) $g->$getter();
            $r['fields'][$key] = ['saved_present' => $s !== '', 'env_present' => $e !== '', 'effective_present' => $effective !== '', 'source' => $e !== '' ? 'environment' : 'woocommerce', 'saved_equals_effective' => hash_equals($s, $effective)];
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['sa_whop_config_read'])) {
            check_admin_referer('sa_whop_config_read');
            $r['legacy_config_read'] = sa_whop_legacy_config_read_once();
            $r['external_requests'] = $r['legacy_config_read']['requests'] > 0;
        }
        $orders = wc_get_orders(['payment_method' => 'whop', 'status' => ['pending', 'on-hold', 'failed'], 'limit' => 100, 'page' => 1, 'paginate' => true, 'orderby' => 'ID', 'order' => 'DESC']);
        $r['open_orders'] = ['total' => (int) $orders->total, 'sample_limit' => 100, 'sample_count' => count($orders->orders), 'truncated' => $orders->total > count($orders->orders), 'by_status' => [], 'checkout_present' => 0, 'checkout_absent' => 0, 'checkout_shape_counts' => ['supported_ch' => 0, 'legacy_chs' => 0, 'other' => 0], 'plan_present' => 0, 'attempt_meta_present' => 0, 'attempt_count' => 0, 'valid_account_fields' => 0, 'profile_fields_present' => 0, 'verified_profile_present' => 0, 'verified_payment_present' => 0, 'currency_counts' => [], 'persisted_account_binding' => 'not established; never inferred from current profile'];
        foreach ($orders->orders as $order) {
            $status = $order->get_status();
            $checkout = (string) $order->get_meta('_whop_checkout_id');
            if ($checkout !== '') {
                $shape = preg_match('/^ch_[A-Za-z0-9]+$/D', $checkout) ? 'supported_ch' : (preg_match('/^chs_[A-Za-z0-9]+$/D', $checkout) ? 'legacy_chs' : 'other');
                ++$r['open_orders']['checkout_shape_counts'][$shape];
            }
            $currency = $order->get_currency();
            $r['open_orders']['currency_counts'][$currency] = ($r['open_orders']['currency_counts'][$currency] ?? 0) + 1;
            foreach (['_whop_plan_id' => 'plan_present', '_sa_whop_verified_profile' => 'verified_profile_present', '_sa_whop_verified_payment' => 'verified_payment_present'] as $meta => $count) if ($order->get_meta($meta) !== '') ++$r['open_orders'][$count];
            $attempts = $order->get_meta('_sa_whop_account_attempts');
            if (is_array($attempts) && $attempts) {
                ++$r['open_orders']['attempt_meta_present'];
                foreach ($attempts as $attempt) {
                    if (!is_array($attempt)) continue;
                    ++$r['open_orders']['attempt_count'];
                    if (!empty($attempt['profile_id']) && is_string($attempt['profile_id'])) ++$r['open_orders']['profile_fields_present'];
                    if ($company($attempt['account_id'] ?? '') !== 'unknown') ++$r['open_orders']['valid_account_fields'];
                }
            }
            $r['open_orders']['by_status'][$status] = ($r['open_orders']['by_status'][$status] ?? 0) + 1;
            ++$r['open_orders'][(string) $order->get_meta('_whop_checkout_id') !== '' ? 'checkout_present' : 'checkout_absent'];
        }
        echo '<form method="post">'; wp_nonce_field('sa_whop_config_read');
        echo '<input type="hidden" name="sa_whop_config_read" value="1"><button type="submit" class="button">Read up to ten existing supported checkout bindings</button><p>Saved existing credential only. Stops on the first error. Does not change orders, callbacks or account settings.</p></form>';
        echo '<div class="wrap"><h1>Whop account binding</h1><p>Local read-only profile comparison. Secret values, customer records and checkout identifiers are not displayed. No payment or configuration is changed.</p><pre id="sa-whop-binding-result" style="background:white;padding:20px;white-space:pre-wrap">' . esc_html(wp_json_encode($r, JSON_PRETTY_PRINT)) . '</pre></div>';
    });
});
