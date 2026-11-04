<?php
/** Temporary verifier-only nonfinancial Whop sample. Never handles payment or member events. */
declare(strict_types=1);
if (!defined('ABSPATH')) exit;
const SA_WHOP_SIGNED_TEST_STATE = 'sa_whop_signed_product_test_v1';
function sa_whop_signed_test_account(stdClass $event): ?string {
    // Current account_id or older company_id at envelope top level only.
    $ids = [];
    foreach (['account_id', 'company_id'] as $field) {
        if (!property_exists($event, $field)) continue;
        if (!is_string($event->$field) || !preg_match('/^biz_[A-Za-z0-9]+$/D', $event->$field)) return null;
        $ids[] = $event->$field;
    }
    return $ids && count(array_unique($ids)) === 1 ? $ids[0] : null;
}
add_action('woocommerce_api_whop_webhook', static function (): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || (string) ($_GET['wc-api'] ?? '') !== 'whop_webhook') return;
    $stream = fopen('php://input', 'rb');
    $raw = $stream ? stream_get_contents($stream, 262145) : false;
    if ($stream) fclose($stream);
    if (!is_string($raw) || strlen($raw) > 262144) return;
    $event = json_decode($raw);
    // Return before any lock for every payment/setup/member/unrelated event.
    if (!($event instanceof stdClass) || ($event->type ?? null) !== 'product.updated') return;
    $account = sa_whop_signed_test_account($event);
    if ($account !== 'biz_Xeq4CXFxF2Hxk6' || !class_exists('Whop_Api_Client')) return;
    $settings = get_option('woocommerce_whop_settings', []);
    if (!is_array($settings) || ($settings['company_id'] ?? '') !== 'biz_Xeq4CXFxF2Hxk6' || empty($settings['webhook_secret']) || !is_string($settings['webhook_secret'])) return;
    $lock = SA_WHOP_SIGNED_TEST_STATE . '_lock';
    if (!add_option($lock, time(), '', false)) return;
    $handled = false; $verified = false;
    try {
        $state = get_option(SA_WHOP_SIGNED_TEST_STATE, []);
        if (!is_array($state) || empty($state['expires']) || time() > $state['expires'] || !empty($state['consumed'])) return;
        $header = static function (string $name): string {
            $v = $_SERVER['HTTP_' . $name] ?? $_SERVER[$name] ?? '';
            return is_string($v) ? $v : '';
        };
        $verified = Whop_Api_Client::verify_webhook_signature($raw, $header('WEBHOOK_ID'), $header('WEBHOOK_TIMESTAMP'), $header('WEBHOOK_SIGNATURE'), $settings['webhook_secret']);
        // An invalid sample never overwrites an existing valid proof.
        $state['result'] = ['received' => true, 'signature_matches_saved' => $verified, 'account_matches_Xeq' => true, 'event_type' => 'product.updated', 'checked_at_utc' => gmdate('c')];
        if ($verified) $state['consumed'] = true;
        update_option(SA_WHOP_SIGNED_TEST_STATE, $state, false);
        $handled = true;
    } finally {
        delete_option($lock);
        unset($settings, $raw, $event);
    }
    if ($handled) {
        nocache_headers(); status_header($verified ? 200 : 401);
        echo $verified ? 'verified_nonfinancial_test' : 'unverified_nonfinancial_test';
        exit;
    }
}, -100);
add_action('admin_post_sa_whop_arm_signed_test', static function (): void {
    if (!current_user_can('manage_woocommerce') || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') wp_die('Not permitted.');
    check_admin_referer('sa_whop_arm_signed_test');
    if (!add_option(SA_WHOP_SIGNED_TEST_STATE, ['expires' => time() + 300, 'consumed' => false, 'result' => null], '', false)) wp_die('Test already armed or handled. No repeat authorized.');
    wp_safe_redirect(admin_url('tools.php?page=sa-whop-signed-test')); exit;
});
add_action('admin_menu', static function (): void {
    add_management_page('Whop signed nonfinancial test', 'Whop signed test', 'manage_woocommerce', 'sa-whop-signed-test', static function (): void {
        if (!current_user_can('manage_woocommerce')) wp_die('Not permitted.');
        nocache_headers();
        $state = get_option(SA_WHOP_SIGNED_TEST_STATE, []);
        echo '<div class="wrap"><h1>Whop signed nonfinancial test</h1><p>One product.updated sample only. Existing payment and member callbacks are unchanged.</p>';
        if (!$state) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="sa_whop_arm_signed_test">'; wp_nonce_field('sa_whop_arm_signed_test');
            echo '<button class="button">Arm one five-minute verification window</button></form>';
        } else {
            $safe = ['armed' => empty($state['consumed']) && time() <= $state['expires'], 'consumed' => !empty($state['consumed']), 'expires_at_utc' => gmdate('c', (int) $state['expires']), 'result' => $state['result'] ?? null];
            echo '<pre id="sa-whop-signed-test-result">' . esc_html(wp_json_encode($safe, JSON_PRETTY_PRINT)) . '</pre>';
        }
        echo '</div>';
    });
});
