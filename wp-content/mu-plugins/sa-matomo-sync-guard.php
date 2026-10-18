<?php
/**
 * Plugin Name: SA Matomo Sync Guard
 * Description: Wraps Matomo's user-sync hooks so a Matomo bootstrap failure (e.g. its cache-directory permission check throwing in Filechecks) can never fatal a profile or user save. The sync failure is logged instead.
 * Version: 1.0.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

add_action('plugins_loaded', function () {
    global $wp_filter;

    $guarded_hooks = ['profile_update', 'user_register', 'delete_user', 'granted_super_admin', 'revoked_super_admin'];

    foreach ($guarded_hooks as $hook) {
        if (empty($wp_filter[$hook]) || !($wp_filter[$hook] instanceof WP_Hook)) {
            continue;
        }
        foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $entry) {
                $cb = $entry['function'];
                if (is_array($cb) && isset($cb[0]) && is_object($cb[0]) && $cb[0] instanceof \WpMatomo\User\Sync) {
                    remove_action($hook, $cb, $priority);
                    $accepted_args = (int) $entry['accepted_args'];
                    add_action($hook, function () use ($cb) {
                        try {
                            call_user_func_array($cb, func_get_args());
                        } catch (\Throwable $e) {
                            error_log('sa-matomo-sync-guard: Matomo user sync failed and was suppressed: ' . $e->getMessage());
                        }
                    }, $priority, $accepted_args);
                }
            }
        }
    }
}, 999);
