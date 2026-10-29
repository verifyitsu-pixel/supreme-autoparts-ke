<?php
/**
 * Plugin Name: SA Maintenance - One-Shot Analytics Truncate (TEMPORARY - remove after confirmed execution)
 * Description: One-time, admin-only, POST+nonce-guarded TRUNCATE of wp_sa_analytics_events (user-approved deletion of the 12-day custom analytics event history, 2026-10-29). Remove this file immediately after confirmed execution.
 */
if (!defined('ABSPATH')) {
    exit;
}

final class SA_One_Shot_Analytics_Truncate
{
    const TABLE  = 'wp_sa_analytics_events'; // exact hardcoded target - never from request input
    const GUARD  = 'sa_onshot_analytics_trunc_guard';
    const ACTION = 'sa_onshot_analytics_trunc';
    const SLUG   = 'sa-oneshot-analytics-truncate';

    public static function init()
    {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_' . self::ACTION, [__CLASS__, 'handle']);
    }

    public static function menu()
    {
        add_management_page('SA one-shot truncate', 'SA one-shot truncate', 'manage_options', self::SLUG, [__CLASS__, 'page']);
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
        echo '<div class="wrap"><h1>SA one-shot analytics truncate</h1>';
        echo '<p>Target table: <code>' . esc_html(self::TABLE) . '</code> (exists: ' . esc_html($exists === self::TABLE ? 'yes' : 'NO') . ')</p>';
        echo '<p>Current rows: <strong>' . esc_html((string) $count) . '</strong></p>';
        echo '<p>Guard state: <code>' . esc_html($guard === '' ? '(not run)' : $guard) . '</code></p>';
        if ($guard === '' && $exists === self::TABLE) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field(self::ACTION);
            echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
            submit_button('TRUNCATE ' . self::TABLE . ' (one shot, irreversible)');
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
        // One-shot guard: claim the slot BEFORE mutating so any refresh/retry/concurrent POST refuses.
        if (!add_option(self::GUARD, 'claimed ' . gmdate('c'), '', 'no')) {
            wp_die('locked: ' . esc_html((string) get_option(self::GUARD)));
        }
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::TABLE));
        if ($exists !== self::TABLE) {
            update_option(self::GUARD, 'aborted: target table missing ' . gmdate('c'), 'no');
            wp_die('aborted: target table missing');
        }
        $pre  = (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . self::TABLE . '`');
        $res  = $wpdb->query('TRUNCATE TABLE `' . self::TABLE . '`');
        $post = (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . self::TABLE . '`');
        $report = 'executed ' . gmdate('c') . ' | pre=' . $pre . ' | truncate_result=' . var_export($res, true) . ' | post=' . $post;
        update_option(self::GUARD, $report, 'no');
        wp_die(esc_html($report));
    }
}
SA_One_Shot_Analytics_Truncate::init();
