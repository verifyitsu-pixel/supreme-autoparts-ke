<?php
/** Temporary local database lock capability check. No provider or order access. */
if (!defined('ABSPATH')) exit;
add_action('admin_menu', static function (): void {
    add_management_page('Whop lock check', 'Whop lock check', 'manage_woocommerce', 'sa-whop-lock-check', static function (): void {
        if (!current_user_can('manage_woocommerce')) wp_die('Not permitted.');
        nocache_headers();
        echo '<div class="wrap"><h1>Whop database lock check</h1>';
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            check_admin_referer('sa_whop_lock_check'); global $wpdb;
            $name = 'sa_whop_fixture_' . wp_generate_uuid4();
            $acquired = false; $released = false;
            try { $acquired = (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name)) === '1'; }
            finally { if ($acquired) $released = (string) $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name)) === '1'; }
            echo '<pre id="sa-whop-lock-result">' . esc_html(wp_json_encode(['acquired' => $acquired, 'released' => $released, 'test_state_absent' => get_option('sa_whop_signed_product_test_v1', null) === null, 'test_lock_absent' => get_option('sa_whop_signed_product_test_v1_lock', null) === null, 'test_mu_absent' => !file_exists(WPMU_PLUGIN_DIR . '/sa-whop-signed-product-test.php')], JSON_PRETTY_PRINT)) . '</pre>';
        } else { echo '<form method="post">'; wp_nonce_field('sa_whop_lock_check'); echo '<button class="button">Check isolated database lock</button></form>'; }
        echo '</div>';
    });
});
