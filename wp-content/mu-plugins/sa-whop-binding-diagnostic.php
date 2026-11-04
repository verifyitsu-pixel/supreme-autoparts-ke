<?php
/** Authenticated Whop profile comparison. Local reads only; consumed provider-read actions removed. No financial or settings writes. */
declare(strict_types=1);
if (!defined('ABSPATH')) exit;

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
        echo '<div class="wrap"><h1>Whop account binding</h1><p>Local read-only profile comparison. Secret values, customer records and checkout identifiers are not displayed. No payment or configuration is changed.</p><pre id="sa-whop-binding-result" style="background:white;padding:20px;white-space:pre-wrap">' . esc_html(wp_json_encode($r, JSON_PRETTY_PRINT)) . '</pre></div>';
    });
});
