<?php
/** Consumed one-test cleanup only. No webhook interceptor or arming route remains. */
if (!defined('ABSPATH')) exit;
add_action('admin_menu', static function (): void {
    add_management_page('Whop test cleanup', 'Whop test cleanup', 'manage_woocommerce', 'sa-whop-signed-test', static function (): void {
        if (!current_user_can('manage_woocommerce')) wp_die('Not permitted.');
        nocache_headers();
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            check_admin_referer('sa_whop_test_cleanup');
            delete_option('sa_whop_signed_product_test_v1');
            delete_option('sa_whop_signed_product_test_v1_lock');
        }
        $state_absent = get_option('sa_whop_signed_product_test_v1', null) === null;
        $lock_absent = get_option('sa_whop_signed_product_test_v1_lock', null) === null;
        echo '<div class="wrap"><h1>Whop test cleanup</h1><pre id="sa-whop-cleanup-result">' . esc_html(wp_json_encode(['state_absent' => $state_absent, 'lock_absent' => $lock_absent, 'interceptor_removed' => true, 'arming_removed' => true], JSON_PRETTY_PRINT)) . '</pre>';
        if (!$state_absent || !$lock_absent) {
            echo '<form method="post">'; wp_nonce_field('sa_whop_test_cleanup');
            echo '<button class="button">Remove consumed test state and lock</button></form>';
        }
        echo '</div>';
    });
});
