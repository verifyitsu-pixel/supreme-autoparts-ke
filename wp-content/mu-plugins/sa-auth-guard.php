<?php
/**
 * Plugin Name: Store Authentication Guard
 * Description: Fixed-window authentication throttles and customer/staff separation.
 * Version: 1.0.0
 */
declare(strict_types=1);
defined('ABSPATH') || exit;

/** Never trust arbitrary client-supplied forwarding headers for a security key. */
function sa_security_network(): string {
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
}

/**
 * Atomic database-backed counter. Fixed windows do not extend on each failure.
 * Uses expiring, non-autoloaded transient rows, cleaned by WordPress.
 */
function sa_security_count(string $scope, string $identity, int $seconds, bool $increment = false, ?int $bucket = null): int {
    global $wpdb;
    $bucket = $bucket ?? (int) floor(time() / $seconds);
    $key = 'sa_guard_' . hash('sha256', $scope . '|' . strtolower(trim($identity)) . '|' . $bucket);
    if (!$increment) return (int) get_option('_transient_' . $key, 0);
    $name = '_transient_' . $key;
    add_option('_transient_timeout_' . $key, time() + $seconds, '', false);
    add_option($name, 0, '', false);
    $ok = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = CAST(option_value AS UNSIGNED) + 1 WHERE option_name = %s", $name));
    wp_cache_delete($name, 'options');
    if ($ok === false || $ok === 0) return PHP_INT_MAX;
    return (int) get_option($name, PHP_INT_MAX);
}

function sa_security_customer_user(WP_User $user): bool {
    foreach (['manage_options', 'manage_woocommerce', 'edit_posts', 'list_users'] as $cap) {
        if (user_can($user, $cap)) return false;
    }
    return (bool) array_intersect(['customer', 'subscriber'], (array) $user->roles);
}

function sa_security_login_identity(string $name): string {
    $user = get_user_by(strpos($name, '@') !== false ? 'email' : 'login', $name);
    return $user instanceof WP_User ? 'user:' . $user->ID : strtolower(trim($name));
}
function sa_security_password_locked(string $name): bool {
    return sa_security_count('password-network', sa_security_network(), 900) >= 200
        || ($name !== '' && sa_security_count('password-account', sa_security_login_identity($name), 900) >= 10);
}
function sa_security_password_gate($user, $name, $password) {
    if ($name === '' || $password === '') return $user;
    if (sa_security_password_locked((string) $name)) {
        return new WP_Error('sa_auth_rate', 'Too many sign-in attempts. Please wait up to 15 minutes and try again.');
    }
    return $user;
}
// Early gate avoids password hashing under load; late gate cannot be bypassed by a valid password.
add_filter('authenticate', 'sa_security_password_gate', 5, 3);
add_filter('authenticate', 'sa_security_password_gate', 99, 3);
add_action('wp_login_failed', static function ($name): void {
    sa_security_count('password-network', sa_security_network(), 900, true);
    if ((string) $name !== '') sa_security_count('password-account', sa_security_login_identity((string) $name), 900, true);
}, 5);

/** Return false once verification traffic exceeds a bounded network/account budget. */
function sa_security_otp_allowed(string $email, array $payload): bool {
    if (sa_security_count('otp-network', sa_security_network(), 600, true) > 60) return false;
    if (sa_security_count('otp-account', $email, 600, true) > 15) return false;
    // A fresh code gets at most five guesses, even for concurrent requests.
    $code_key = $email . '|' . (string) ($payload['hash'] ?? 'missing');
    return sa_security_count('otp-code', $code_key, 1200, true, (int) ($payload['created'] ?? 0)) <= 5;
}

/** Atomically consume a verified code before issuing any session. */
function sa_security_consume_otp(string $email, array $payload): bool {
    $key = 'sa_used_' . hash('sha256', $email . '|' . (string) ($payload['hash'] ?? ''));
    add_option('_transient_timeout_' . $key, time() + 1200, '', false);
    return add_option('_transient_' . $key, 1, '', false);
}
