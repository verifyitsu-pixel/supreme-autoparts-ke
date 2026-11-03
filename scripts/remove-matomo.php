<?php
/** Owner-approved Nov3 full Matomo removal, including residuals after vendor uninstall. */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) { exit; }
global $wpdb;
$prefix = $wpdb->prefix . 'matomo_';
$tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($prefix) . '%'));
foreach ($tables as $table) {
    if (!str_starts_with($table, $prefix) || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) { throw new RuntimeException('Unexpected Matomo table identifier'); }
    if ($wpdb->query('DROP TABLE IF EXISTS `' . $table . '`') === false) { throw new RuntimeException('Matomo table removal failed'); }
}
foreach ((array) _get_cron_array() as $timestamp => $jobs) {
    foreach ($jobs as $hook => $events) {
        if (str_starts_with($hook, 'matomo_') || $hook === 'sa_matomo_deep_provision') { wp_clear_scheduled_hook($hook); }
    }
}
$options = $wpdb->get_col('SELECT option_name FROM ' . $wpdb->options);
foreach ($options as $name) {
    if (str_starts_with($name, 'matomo-') || str_starts_with($name, 'matomo_') || str_starts_with($name, 'matomo_global-') || str_starts_with($name, '_transient_matomo_') || str_starts_with($name, '_transient_timeout_matomo_') || in_array($name, ['sa_matomo_deep_version', 'sa_matomo_deep_dims'], true)) { delete_option($name); }
}
foreach (['view','write','admin','superuser'] as $role) { remove_role('matomo_' . $role . '_role'); }
require_once ABSPATH . 'wp-admin/includes/file.php';
WP_Filesystem();
global $wp_filesystem;
foreach ([WP_CONTENT_DIR . '/cache/matomo', wp_upload_dir()['basedir'] . '/matomo'] as $path) {
    if (is_dir($path) && !$wp_filesystem->rmdir($path, true)) { throw new RuntimeException('Matomo directory removal failed'); }
}
echo 'Matomo residual cleanup complete: ' . count($tables) . " tables removed\n";
