<?php
/**
 * Plugin Name: SA Maintenance - Instrumented One-Shot Analytics Truncate v2 (TEMPORARY - remove after confirmed execution)
 * Description: Retry of the user-approved wp_sa_analytics_events history deletion with full error capture (last_error + mysqli errno). Same guards as v1: manage_options + POST + nonce + claim-before-mutate one-shot + exact hardcoded table. Remove this file immediately after confirmed execution.
 */
if (!defined('ABSPATH')) {
    exit;
}

final class SA_One_Shot_Analytics_Truncate2
{
    const TABLE    = 'wp_sa_analytics_events'; // exact hardcoded target - never from request input
    const GUARD    = 'sa_onshot_analytics_trunc2_guard';
    const GUARD_V1 = 'sa_onshot_analytics_trunc_guard';
    const ACTION   = 'sa_onshot_analytics_trunc2';
    const SLUG     = 'sa-oneshot-analytics-truncate2';

    public static function init()
    {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_' . self::ACTION, [__CLASS__, 'handle']);
    }

    public static function menu()
    {
        add_management_page('SA one-shot truncate v2', 'SA one-shot truncate v2', 'manage_options', self::SLUG, [__CLASS__, 'page']);
    }

    public static function page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('denied');
        }
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::TABLE));
        $count  = ($exists === self::TABLE) ? (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . self::TABLE . '`') : -1;
        $guard  = get_option(self::GUARD, '');
        echo '<div class="wrap"><h1>SA one-shot analytics truncate v2 (instrumented)</h1>';
        echo '<p>v1 guard (preserved): <code>' . esc_html((string) get_option(self::GUARD_V1, '(unset)')) . '</code></p>';
        echo '<p>Target table: <code>' . esc_html(self::TABLE) . '</code> (exists: ' . esc_html($exists === self::TABLE ? 'yes' : 'NO') . ')</p>';
        echo '<p>Current rows: <strong>' . esc_html((string) $count) . '</strong></p>';
        echo '<p>v2 guard state: <code>' . esc_html($guard === '' ? '(not run)' : $guard) . '</code></p>';
        if ($guard === '' && $exists === self::TABLE) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field(self::ACTION);
            echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
            submit_button('TRUNCATE ' . self::TABLE . ' (one shot, instrumented, irreversible)');
            echo '</form>';
        } else {
            echo '<p><em>Execution locked.</em></p>';
        }
        echo '</div>';
    }

    public static function handle()
    {
        if (!current_user_can('manage_options') || !check_admin_referer(self::ACTION, '_wpnonce', false)) {
            wp_die('denied');
        }
        if (!add_option(self::GUARD, 'claimed ' . gmdate('c'), '', 'no')) {
            wp_die('locked: ' . esc_html((string) get_option(self::GUARD)));
        }
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::TABLE));
        if ($exists !== self::TABLE) {
            update_option(self::GUARD, 'aborted: target table missing ' . gmdate('c'), 'no');
            wp_die('aborted: target table missing');
        }
        $pre = (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . self::TABLE . '`');
        $res = $wpdb->query('TRUNCATE TABLE `' . self::TABLE . '`');
        $err = (string) $wpdb->last_error;
        $errno = null;
        if (isset($wpdb->dbh) && $wpdb->dbh instanceof mysqli) {
            $errno = $wpdb->dbh->errno;
        }
        $existsAfter = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::TABLE));
        $post = ($existsAfter === self::TABLE) ? (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . self::TABLE . '`') : -1;
        $ddl = '';
        if ($existsAfter === self::TABLE) {
            $row = $wpdb->get_row('SHOW CREATE TABLE `' . self::TABLE . '`', ARRAY_N);
            $ddl = $row ? $row[1] : '';
        }
        $report = 'executed ' . gmdate('c')
            . ' | pre=' . $pre
            . ' | truncate_result=' . var_export($res, true)
            . ' | post=' . $post
            . ' | table_exists_after=' . var_export($existsAfter === self::TABLE, true)
            . ' | errno=' . var_export($errno, true)
            . ' | last_error=' . substr($err, 0, 600);
        update_option(self::GUARD, $report, 'no');
        if ($ddl !== '') {
            update_option(self::GUARD . '_ddl', $ddl, 'no');
        }
        wp_die(esc_html($report));
    }
}
SA_One_Shot_Analytics_Truncate2::init();
