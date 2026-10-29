<?php
/**
 * Plugin Name: SA Maintenance - One-shot analytics DROP+recreate (TEMPORARY)
 * Description: One-shot DROP + recreate of wp_sa_analytics_events using the live-captured DDL stored in wp_options. Guarded; runs once on page view. REMOVE AFTER USE.
 */
if (!defined('ABSPATH')) {
    exit;
}

define('SA_ONESHOT_DROPREC_GUARD', 'sa_onshot_analytics_droprec_guard');

add_action('admin_menu', function () {
    add_management_page('SA oneshot analytics droprecreate', 'SA oneshot analytics droprecreate', 'manage_options', 'sa-oneshot-analytics-droprecreate', 'sa_oneshot_analytics_droprecreate_page');
});

function sa_oneshot_analytics_droprecreate_page() {
    if (!current_user_can('manage_options')) {
        wp_die('denied');
    }
    $guard = get_option(SA_ONESHOT_DROPREC_GUARD);
    if ($guard) {
        echo '<div class="wrap"><h1>SA analytics drop+recreate</h1><p><strong>Already executed; guard locked.</strong></p><pre>' . esc_html($guard) . '</pre></div>';
        return;
    }

    global $wpdb;
    $table = 'wp_sa_analytics_events';
    $log = array();
    $log[] = 'executed ' . gmdate('c');

    // 1. Fetch the live-captured DDL saved during diagnosis.
    $ddl = get_option('sa_onshot_analytics_trunc2_guard_ddl');
    if (!$ddl || stripos($ddl, 'CREATE TABLE') === false || strpos($ddl, $table) === false) {
        $log[] = 'ABORT: preserved DDL missing or invalid in option sa_onshot_analytics_trunc2_guard_ddl';
        update_option(SA_ONESHOT_DROPREC_GUARD, implode(' | ', $log), false);
        wp_die('<pre>' . esc_html(implode("\n", $log)) . '</pre>');
    }
    $log[] = 'ddl_source=wp_options(len=' . strlen($ddl) . ')';

    // 2. Pre state.
    $pre = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `' . $table . '`'));
    $log[] = 'pre=' . var_export($pre, true);
    if ($wpdb->last_error) {
        $log[] = 'pre_error=' . $wpdb->last_error;
    }

    // 3. DROP (unlink frees OS space; data loss is the approved action).
    $drop = $wpdb->query('DROP TABLE IF EXISTS `' . $table . '`');
    $drop_err = $wpdb->last_error;
    $log[] = 'drop_result=' . var_export($drop, true);
    $log[] = 'drop_last_error=' . ($drop_err ? $drop_err : '(none)');
    if ($drop_err) {
        $log[] = 'ABORT after failed DROP';
        update_option(SA_ONESHOT_DROPREC_GUARD, implode(' | ', $log), false);
        wp_die('<pre>' . esc_html(implode("\n", $log)) . '</pre>');
    }
    $gone = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    $log[] = 'table_exists_after_drop=' . ($gone ? 'true' : 'false');

    // 4. Recreate from preserved DDL (verbatim SHOW CREATE output).
    $create = $wpdb->query($ddl);
    $create_err = $wpdb->last_error;
    $log[] = 'create_result=' . var_export($create, true);
    $log[] = 'create_last_error=' . ($create_err ? $create_err : '(none)');
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    $log[] = 'table_exists_after_create=' . ($exists ? 'true' : 'false');

    // 5. Verify: empty table + write health probe.
    if ($exists && !$create_err) {
        $post = $wpdb->get_var('SELECT COUNT(*) FROM `' . $table . '`');
        $log[] = 'post_count=' . var_export($post, true);
        $probe = $wpdb->insert($table, array(
            'ts'       => current_time('mysql', true),
            'host'     => 'www.supremeautoparts.co.ke',
            'uri'      => '/__sa_write_probe__',
            'referrer' => '',
            'ua'       => 'sa-droprecreate-verify',
            'ip_hash'  => hash('sha256', 'probe'),
            'is_bot'   => 1,
            'company'  => '',
            'crawler'  => '',
            'category' => '',
        ));
        $log[] = 'probe_insert=' . ($probe ? 'ok(id=' . $wpdb->insert_id . ')' : 'FAILED: ' . $wpdb->last_error);
        if ($probe) {
            $wpdb->delete($table, array('uri' => '/__sa_write_probe__'));
            $log[] = 'probe_row_removed=1';
        }
        $size = $wpdb->get_row("SELECT DATA_LENGTH, INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $table . "'", ARRAY_A);
        if ($size) {
            $log[] = 'size_after_mb=' . number_format(((int) $size['DATA_LENGTH'] + (int) $size['INDEX_LENGTH']) / 1048576, 2);
        }
    } else {
        $log[] = 'VERIFY-SKIPPED: table missing after recreate attempt';
    }

    $result = implode(' | ', $log);
    update_option(SA_ONESHOT_DROPREC_GUARD, $result, false);
    wp_die('<pre>' . esc_html($result) . '</pre>');
}
