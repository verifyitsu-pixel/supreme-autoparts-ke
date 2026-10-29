<?php
/**
 * Plugin Name: SA Maintenance - DB Footprint Report (TEMPORARY, read-only)
 * Description: Read-only per-table size report (information_schema) to answer "what eats space" and quantify reclaimable space. No mutations. Remove after reading.
 */
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', function () {
    add_management_page('SA DB footprint', 'SA DB footprint', 'manage_options', 'sa-db-footprint', 'sa_db_footprint_page');
});

function sa_db_footprint_page() {
    if (!current_user_can('manage_options')) {
        wp_die('denied');
    }
    global $wpdb;
    $rows = $wpdb->get_results(
        'SELECT TABLE_NAME, ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, DATA_FREE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC',
        ARRAY_A
    );
    $tot = 0; $mat = 0; $sess = 0; $custom = 0;
    echo '<div class="wrap"><h1>SA DB footprint (read-only)</h1>';
    echo '<table border="1" cellpadding="4" cellspacing="0"><tr><th>Table</th><th>Engine</th><th>Rows(est)</th><th>Data MB</th><th>Index MB</th><th>Total MB</th><th>DataFree MB</th></tr>';
    foreach ($rows as $r) {
        $t = (int) $r['DATA_LENGTH'] + (int) $r['INDEX_LENGTH'];
        $tot += $t;
        if (strpos($r['TABLE_NAME'], 'wp_matomo_') === 0) { $mat += $t; }
        if ($r['TABLE_NAME'] === 'wp_woocommerce_sessions') { $sess = $t; }
        if ($r['TABLE_NAME'] === 'wp_sa_analytics_events') { $custom = $t; }
        echo '<tr><td>' . esc_html($r['TABLE_NAME']) . '</td><td>' . esc_html((string) $r['ENGINE']) . '</td><td>' . esc_html((string) $r['TABLE_ROWS']) . '</td><td>'
            . esc_html(number_format((int) $r['DATA_LENGTH'] / 1048576, 1)) . '</td><td>'
            . esc_html(number_format((int) $r['INDEX_LENGTH'] / 1048576, 1)) . '</td><td><strong>'
            . esc_html(number_format($t / 1048576, 1)) . '</strong></td><td>'
            . esc_html(number_format((int) $r['DATA_FREE'] / 1048576, 1)) . '</td></tr>';
    }
    echo '</table>';
    echo '<h2>Summary MB</h2><pre>'
        . 'ALL TABLES: ' . number_format($tot / 1048576, 1) . "\n"
        . 'wp_matomo_* subtotal: ' . number_format($mat / 1048576, 1) . "\n"
        . 'wp_woocommerce_sessions: ' . number_format($sess / 1048576, 1) . "\n"
        . 'wp_sa_analytics_events: ' . number_format($custom / 1048576, 1) . "\n"
        . '</pre></div>';
}
