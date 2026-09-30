<?php
/**
 * Plugin Name: Supreme Autoparts Security
 * Description: Baseline HTTP security headers, XML-RPC off, file-edit hardening helpers.
 * Version: 1.0.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Send baseline security headers on front-end and REST (skip admin UI chrome).
 */
add_action('send_headers', static function (): void {
    if (is_admin() && !(defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff', false);
    header('X-Frame-Options: SAMEORIGIN', false);
    header('Referrer-Policy: strict-origin-when-cross-origin', false);
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()', false);
    header('Cross-Origin-Opener-Policy: same-origin-allow-popups', false);

    // HSTS only when the request is HTTPS (Cloudflare / Railway TLS).
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443');
    if ($https) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains', false);
    }
}, 20);

/** Disable XML-RPC entirely (brute-force / pingback amplification vector). */
add_filter('xmlrpc_enabled', '__return_false');

add_action('init', static function (): void {
    remove_action('wp_head', 'rsd_link');
    remove_action('wp_head', 'wlwmanifest_link');
}, 1);

/** Block XML-RPC endpoint early. */
add_action('template_redirect', static function (): void {
    if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
        status_header(403);
        exit('XML-RPC disabled');
    }
}, 0);

/**
 * Hide author enumeration via ?author=N redirects for guests.
 */
add_action('template_redirect', static function (): void {
    if (is_admin() || is_user_logged_in()) {
        return;
    }
    if (isset($_GET['author']) || (bool) get_query_var('author')) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        wp_safe_redirect(home_url('/'), 301);
        exit;
    }
}, 1);

/**
 * Block public author / user enumeration via REST + legacy feeds.
 * Authenticated staff retain full access.
 */
add_filter('rest_endpoints', static function (array $endpoints): array {
    if (is_user_logged_in() && current_user_can('list_users')) {
        return $endpoints;
    }
    foreach (['/wp/v2/users', '/wp/v2/users/(?P<id>[\d]+)'] as $route) {
        if (isset($endpoints[$route])) {
            unset($endpoints[$route]);
        }
    }
    return $endpoints;
});

add_filter('wp_sitemaps_add_provider', static function ($provider, $name) {
    if ($name === 'users') {
        return false;
    }
    return $provider;
}, 10, 2);

add_filter('oembed_response_data', static function ($data) {
    if (is_array($data)) {
        unset($data['author_name'], $data['author_url']);
    }
    return $data;
}, 10, 1);

/**
 * Soft login rate-limit by IP after repeated failures (complements audit log).
 */
add_filter('authenticate', static function ($user, $username, $password) {
    unset($username, $password);
    if ($user instanceof WP_User) {
        return $user;
    }
    $ip = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
    $ip = preg_replace('/[^0-9a-fA-F:.]/', '', $ip) ?: '0.0.0.0';
    $key = 'sa_login_fail_' . md5($ip);
    $fails = (int) get_transient($key);
    if ($fails >= 10) {
        return new WP_Error(
            'sa_login_rate',
            __('Too many failed sign-in attempts from this network. Please wait 15 minutes and try again.', 'supreme-autoparts')
        );
    }
    return $user;
}, 30, 3);

add_action('wp_login_failed', static function () {
    $ip = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
    $ip = preg_replace('/[^0-9a-fA-F:.]/', '', $ip) ?: '0.0.0.0';
    $key = 'sa_login_fail_' . md5($ip);
    $fails = (int) get_transient($key);
    set_transient($key, $fails + 1, 15 * MINUTE_IN_SECONDS);
}, 5);

add_action('wp_login', static function () {
    $ip = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
    $ip = preg_replace('/[^0-9a-fA-F:.]/', '', $ip) ?: '0.0.0.0';
    delete_transient('sa_login_fail_' . md5($ip));
}, 5);
