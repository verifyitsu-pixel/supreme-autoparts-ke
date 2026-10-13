<?php
/**
 * Supreme Autoparts: sync Pesapal / TransactWorld (TransactPay) / PayPal gateway
 * settings from Railway env vars. Each gateway is auto-enabled only when its
 * required keys are present, and forced off otherwise (no broken options at checkout).
 */
if (!defined('ABSPATH')) { exit; }

function sa_gw_env(string $k): string {
    $v = getenv($k);
    return is_string($v) ? trim($v) : '';
}

function sa_gw_sync(): void {
    if (!class_exists('WooCommerce')) return;
    $sig = md5(implode('|', array_map('sa_gw_env', [
        'PESAPAL_CONSUMER_KEY','PESAPAL_CONSUMER_SECRET','PESAPAL_ENV','PESAPAL_IPN_ID',
        'TRANSACTPAY_MERCHANT_ID','TRANSACTPAY_WORKING_KEY','TRANSACTPAY_PARTNER_NAME','TRANSACTPAY_PARTNER_ID',
        'TRANSACTPAY_ENV','TRANSACTPAY_LIVE_URL','TRANSACTPAY_TEST_URL','TRANSACTPAY_QUERY_URL','TRANSACTPAY_IP',
        'PAYPAL_CLIENT_ID','PAYPAL_CLIENT_SECRET',
    ])) . '|v2');
    if (get_option('sa_gw_env_sig') === $sig) return;

    /* ---- Pesapal (official plugin, option woocommerce_pesapal_settings) ---- */
    $p = (array) get_option('woocommerce_pesapal_settings', []);
    $pk = sa_gw_env('PESAPAL_CONSUMER_KEY'); $ps = sa_gw_env('PESAPAL_CONSUMER_SECRET');
    $sandbox = strtolower(sa_gw_env('PESAPAL_ENV')) === 'sandbox';
    $p['testmode'] = $sandbox ? 'yes' : 'no';
    if ($pk && $ps) {
        if ($sandbox) { $p['testconsumerkey'] = $pk; $p['testsecretkey'] = $ps; }
        else { $p['consumerkey'] = $pk; $p['secretkey'] = $ps; }
        $ipn = sa_gw_env('PESAPAL_IPN_ID') ?: sa_gw_pesapal_register_ipn($pk, $ps, $sandbox);
        if ($ipn) $p['testnotification_id'] = $ipn; // plugin uses this field for live + demo
        $p['enabled'] = $ipn ? 'yes' : 'no';
        if (empty($p['title'])) $p['title'] = 'Pesapal (M-Pesa, Airtel Money, Visa, Mastercard)';
    } else {
        $p['enabled'] = 'no';
    }
    update_option('woocommerce_pesapal_settings', $p);

    /* ---- TransactWorld / TransactPay (official plugin, id transactworld) ---- */
    $t = (array) get_option('woocommerce_transactworld_settings', []);
    $map = ['merchant_id'=>'TRANSACTPAY_MERCHANT_ID','working_key'=>'TRANSACTPAY_WORKING_KEY','totype'=>'TRANSACTPAY_PARTNER_NAME',
            'partenerid'=>'TRANSACTPAY_PARTNER_ID','liveurl'=>'TRANSACTPAY_LIVE_URL','testurl'=>'TRANSACTPAY_TEST_URL',
            'queryurl'=>'TRANSACTPAY_QUERY_URL','ipaddr'=>'TRANSACTPAY_IP'];
    foreach ($map as $opt => $env) { if (sa_gw_env($env) !== '') $t[$opt] = sa_gw_env($env); }
    $live = strtolower(sa_gw_env('TRANSACTPAY_ENV')) === 'live';
    $t['livemode'] = $live ? 'Y' : 'N';
    $url_ok = $live ? !empty($t['liveurl']) : !empty($t['testurl']);
    $t['enabled'] = (!empty($t['merchant_id']) && !empty($t['working_key']) && $url_ok) ? 'yes' : 'no';
    update_option('woocommerce_transactworld_settings', $t);

    update_option('sa_gw_env_sig', $sig);
}

function sa_gw_pesapal_register_ipn(string $key, string $secret, bool $sandbox): string {
    $cached = get_option('sa_pesapal_ipn_' . ($sandbox ? 'demo' : 'live'));
    if ($cached) return (string) $cached;
    $base = $sandbox ? 'https://cybqa.pesapal.com/pesapalv3/api' : 'https://pay.pesapal.com/v3/api';
    $h = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
    $r = wp_remote_post($base . '/Auth/RequestToken', ['headers'=>$h,'timeout'=>20,'body'=>wp_json_encode(['consumer_key'=>$key,'consumer_secret'=>$secret])]);
    $tok = json_decode((string) wp_remote_retrieve_body($r), true)['token'] ?? '';
    if (!$tok) { error_log('[supreme] Pesapal token failed'); return ''; }
    $h['Authorization'] = 'Bearer ' . $tok;
    $url = add_query_arg('wc-api', 'WC_Pesapal_Gateway', home_url('/'));
    $r = wp_remote_post($base . '/URLSetup/RegisterIPN', ['headers'=>$h,'timeout'=>20,'body'=>wp_json_encode(['url'=>$url,'ipn_notification_type'=>'GET'])]);
    $id = json_decode((string) wp_remote_retrieve_body($r), true)['ipn_id'] ?? '';
    if ($id) update_option('sa_pesapal_ipn_' . ($sandbox ? 'demo' : 'live'), $id);
    else error_log('[supreme] Pesapal RegisterIPN failed');
    return (string) $id;
}

add_action('woocommerce_init', 'sa_gw_sync', 20);

/* PayPal Payments: credentials come from the plugin's own Connect/onboarding flow
 * (WooCommerce > Settings > Payments > PayPal); it shows nothing at checkout until connected. */
