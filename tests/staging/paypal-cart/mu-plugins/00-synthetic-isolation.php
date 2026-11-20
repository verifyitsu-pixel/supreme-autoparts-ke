<?php
/** STAGING ONLY. Never copy to production mu-plugins. */
declare(strict_types=1);
defined('ABSPATH') || exit;
if (!defined('SA_SYNTHETIC_STAGING') || SA_SYNTHETIC_STAGING !== true || !defined('WP_ENVIRONMENT_TYPE') || WP_ENVIRONMENT_TYPE !== 'staging') {
    http_response_code(503);
    exit('Synthetic staging safety configuration required.');
}
// No copied customer database, gateway/plugin activation, mail or background jobs.
add_filter('pre_wp_mail', static fn() => false, PHP_INT_MAX);
add_filter('pre_http_request', static function ($prior, $args, $url) {
    // Empty-key preparation is strictly offline. No live or sandbox network.
    return new WP_Error('sa_staging_outbound_denied', 'Outbound requests are disabled until sandbox credentials and a reviewed allowlist are installed.');
}, PHP_INT_MAX, 3);
add_filter('pre_option_blog_public', static fn() => '0');
add_filter('pre_option_woocommerce_allow_tracking', static fn() => 'no');
add_filter('woocommerce_allow_marketplace_suggestions', '__return_false');
add_filter('woocommerce_admin_disabled', '__return_true');
add_filter('woocommerce_enqueue_styles', static fn($styles) => $styles);
add_action('send_headers', static function (): void {
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Cache-Control: no-store, private');
    // No Google analytics, pixels, external email links, SDK or remote media with empty keys.
    header("Content-Security-Policy: default-src 'self' data: blob:; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self'; frame-src 'none'; form-action 'self'; base-uri 'self'; frame-ancestors 'none'");
});
add_action('init', static function (): void {
    if (wp_doing_cron()) { http_response_code(403); exit('Scheduled jobs disabled in synthetic staging.'); }
    if (isset($_GET['wc-api'])) { http_response_code(403); exit('Processor callbacks disabled while keys are empty.'); }
    $ajax = isset($_GET['wc-ajax']) && is_string($_GET['wc-ajax']) ? $_GET['wc-ajax'] : '';
    if (str_starts_with($ajax, 'ppc') || str_starts_with($ajax, 'ppcp')) {
        wp_send_json_error(['message' => 'PayPal sandbox credentials are empty. No provider request was made.'], 503);
    }
}, -999);
// Never allow a UI toggle, inherited legacy option or accidental live credential.
foreach (['woocommerce-ppcp-data-common', 'woocommerce-ppcp-settings'] as $key) {
    add_filter('pre_option_' . $key, static fn() => ['use_sandbox' => true, 'sandbox_merchant' => true, 'sandbox_on' => true, 'client_id' => '', 'client_secret' => '', 'merchant_id' => '', 'merchant_email' => '', 'merchant_connected' => false]);
}
add_filter('pre_option_woocommerce_ppcp-gateway_settings', static fn() => ['enabled' => 'no', 'title' => 'PayPal sandbox']);
add_filter('woocommerce_available_payment_gateways', static function ($gateways) { return []; }, PHP_INT_MAX);
add_filter('woocommerce_order_button_html', static fn() => '<button type="button" class="button alt" disabled aria-disabled="true">Waiting for sandbox credentials</button>', PHP_INT_MAX);
add_action('woocommerce_after_checkout_validation', static function ($data, $errors): void {
    $errors->add('sa_sandbox_not_ready', 'PayPal sandbox credentials are empty. No payment or fulfilment is available.');
}, PHP_INT_MAX, 2);
add_action('wp_body_open', static function (): void {
    echo '<div role="note" style="padding:12px;background:#202830;color:white;text-align:center;font:600 14px/1.5 sans-serif">PRIVATE SYNTHETIC STAGING · Fictional products and orders only · No payment credentials</div>';
}, 0);
add_filter('woocommerce_locate_template', static function ($template, $name) {
    if ($name === 'checkout/payment.php') return dirname(__DIR__) . '/templates/checkout/payment.php';
    return $template;
}, PHP_INT_MAX, 2);
// Empty-key checks do not offer any usable payment gateway, including Pesapal.
// This modifies only the separate synthetic staging runtime, never the real store.

// Suppress remote font/SDK tags entirely in the empty-key phase, not only via CSP.
function sa_stage_local_asset(string $url): bool {
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) return true;
    $home = wp_parse_url(home_url('/'));
    $asset = wp_parse_url($url);
    return is_array($asset) && ($asset['host'] ?? '') === ($home['host'] ?? '') && (int) ($asset['port'] ?? 0) === (int) ($home['port'] ?? 0);
}
add_filter('script_loader_tag', static fn($tag,$handle,$src) => (!str_contains((string)$handle, 'ppcp') && sa_stage_local_asset((string)$src)) ? $tag : '', PHP_INT_MAX, 3);
add_filter('style_loader_tag', static fn($tag,$handle,$href) => sa_stage_local_asset((string)$href) ? $tag : '', PHP_INT_MAX, 3);
// Local rehearsal uses HTTP on loopback only. Published staging must use HTTPS.
add_filter('set_url_scheme', static function ($url) {
    $home = wp_parse_url(WP_HOME);
    if (($home['host'] ?? '') === '127.0.0.1' && str_starts_with($url, 'https://127.0.0.1:')) return 'http://' . substr($url, 8);
    return $url;
});

add_action('wp_enqueue_scripts', static function (): void {
    $scripts = wp_scripts();
    foreach ($scripts->queue as $handle) if (str_contains((string)$handle, 'ppcp')) wp_dequeue_script($handle);
}, PHP_INT_MAX);
// WooCommerce sets its own checkout frame header after send_headers. Reapply the
// stricter preparation CSP after that hook, without changing production headers.
add_action('template_redirect', static function (): void {
    header("Content-Security-Policy: default-src 'self' data: blob:; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self'; frame-src 'none'; form-action 'self'; base-uri 'self'; frame-ancestors 'none'");
}, PHP_INT_MAX);
add_action('wp_head', static function (): void {
    echo '<style>.sa-checkout-payment .payment_method_ppcp-gateway{display:block!important;padding:20px!important}.sa-checkout-payment .payment_method_ppcp-gateway input{display:inline-block!important;width:auto!important}.sa-checkout-payment .payment_method_ppcp-gateway label{display:inline!important}.sa-checkout-payment .payment_method_ppcp-gateway p{grid-column:1/-1!important;display:block!important;width:auto!important;max-width:none!important;margin:12px 0 0!important;white-space:normal!important;overflow-wrap:normal!important}</style>';
}, PHP_INT_MAX);
