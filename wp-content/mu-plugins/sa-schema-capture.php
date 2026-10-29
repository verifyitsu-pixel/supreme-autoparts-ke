<?php
/**
 * Plugin Name: SA Maintenance - Analytics Schema Capture (TEMPORARY, read-only)
 * Description: Read-only diagnostics for wp_sa_analytics_events after the 2026-10-29 truncate attempt was refused by MySQL. Captures live SHOW CREATE TABLE, information_schema stats, triggers, foreign keys, server flags, and the one-shot guard state. No mutations. Remove after review.
 */
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', function () {
    add_management_page('SA schema capture', 'SA schema capture', 'manage_options', 'sa-schema-capture', 'sa_schema_capture_page');
});

function sa_schema_capture_page() {
    if (!current_user_can('manage_options')) {
        wp_die('denied');
    }
    global $wpdb;
    echo '<div class="wrap"><h1>SA analytics schema capture (read-only)</h1>';
    echo '<p>One-shot guard state: <code>' . esc_html((string) get_option('sa_onshot_analytics_trunc_guard', '(unset)')) . '</code></p>';

    $cnt = $wpdb->get_var('SELECT COUNT(*) FROM `wp_sa_analytics_events`');
    echo '<p>Current rows: <strong>' . esc_html((string) $cnt) . '</strong></p>';

    $row = $wpdb->get_row('SHOW CREATE TABLE `wp_sa_analytics_events`', ARRAY_N);
    echo '<h2>SHOW CREATE TABLE</h2><pre style="white-space:pre-wrap">' . esc_html($row ? $row[1] : '(table missing)') . '</pre>';

    $t = $wpdb->get_row($wpdb->prepare(
        'SELECT ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, DATA_FREE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
        $wpdb->dbname, 'wp_sa_analytics_events'
    ), ARRAY_A);
    echo '<h2>information_schema.TABLES</h2><pre>' . esc_html($t ? wp_json_encode($t, JSON_PRETTY_PRINT) : '(missing)') . '</pre>';

    $trg = $wpdb->get_results($wpdb->prepare(
        'SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = %s',
        $wpdb->dbname
    ), ARRAY_A);
    echo '<h2>Triggers in schema</h2><pre>' . esc_html(wp_json_encode($trg ? $trg : [], JSON_PRETTY_PRINT)) . '</pre>';

    $fk = $wpdb->get_results($wpdb->prepare(
        'SELECT CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = %s AND (TABLE_NAME = %s OR REFERENCED_TABLE_NAME = %s)',
        $wpdb->dbname, 'wp_sa_analytics_events', 'wp_sa_analytics_events'
    ), ARRAY_A);
    echo '<h2>Foreign keys touching table</h2><pre>' . esc_html(wp_json_encode($fk ? $fk : [], JSON_PRETTY_PRINT)) . '</pre>';

    $ver = $wpdb->get_row('SELECT VERSION() AS v, @@innodb_file_per_table AS fpt', ARRAY_A);
    echo '<h2>Server</h2><pre>' . esc_html(wp_json_encode($ver, JSON_PRETTY_PRINT)) . '</pre>';

    $st = $wpdb->get_row('SHOW ENGINE INNODB STATUS', ARRAY_A);
    $txt = ($st && isset($st['Status'])) ? $st['Status'] : '';
    echo '<h2>INNODB STATUS (first 2500 chars)</h2><pre style="white-space:pre-wrap">' . esc_html(substr($txt, 0, 2500)) . '</pre>';
    echo '</div>';
}
