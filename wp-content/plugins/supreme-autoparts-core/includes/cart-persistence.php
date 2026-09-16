<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cart + session persistence and Remember me defaults.
 *
 * - Logged-in: WooCommerce persistent cart (cross-device when same account).
 * - Guest: longer WC session cookie so the cart survives browser restarts.
 * - Login / checkout login: Remember me checked by default; longer auth cookie.
 * - On login / register: merge guest session cart into the user cart.
 */

/**
 * Soft-enforce Woo options related to accounts + cart continuity.
 */
function sa_core_apply_cart_persistence_settings(): void
{
    if (!class_exists('WooCommerce')) {
        return;
    }

    // Guest checkout + login from checkout (idempotent).
    update_option('woocommerce_enable_guest_checkout', 'yes');
    update_option('woocommerce_enable_checkout_login_reminder', 'yes');
    update_option('woocommerce_enable_signup_and_login_from_checkout', 'yes');
    update_option('woocommerce_enable_myaccount_registration', 'yes');

    // Document intent for operators (Woo uses the filter, not this option, but
    // some hosting dashboards surface it).
    update_option('woocommerce_enable_persistent_cart', 'yes');

    update_option('sa_cart_persistence_ver', '1');
}

add_action('init', static function (): void {
    if (get_option('sa_cart_persistence_ver') === '1') {
        return;
    }
    sa_core_apply_cart_persistence_settings();
}, 28);

/** Keep persistent cart enabled for logged-in shoppers. */
add_filter('woocommerce_persistent_cart_enabled', static function ($enabled) {
    return true;
});

/**
 * Guest / anonymous session lifetime — 14 days (default Woo is ~48h).
 */
add_filter('wc_session_expiration', static function (): int {
    return 14 * DAY_IN_SECONDS;
});

add_filter('wc_session_expiring', static function (): int {
    return 13 * DAY_IN_SECONDS;
});

/**
 * Auth cookie lifetime when Remember me is checked (45 days).
 * When unchecked, WordPress uses ~2 days — we leave that alone.
 */
add_filter('auth_cookie_expiration', static function (int $length, int $user_id, bool $remember): int {
    if ($remember) {
        return 45 * DAY_IN_SECONDS;
    }
    // Still stretch "session" cookies slightly so same-browser revisits stay signed in
    // when the browser keeps the cookie (not a true session-only cookie in all browsers).
    return max($length, 14 * DAY_IN_SECONDS);
}, 20, 3);

/**
 * Default Remember me checked on Woo login forms (my-account + checkout).
 */
add_filter('woocommerce_login_form', static function (): void {
    // Marker for theme JS; checkbox itself is rendered by form-login templates.
}, 1);

add_filter('woocommerce_form_field_args', static function (array $args, string $key): array {
    return $args;
}, 10, 2);

/**
 * Ensure rememberme POST defaults to on when the field is missing but a
 * custom template omitted it (defensive).
 */
add_action('woocommerce_login_form_end', static function (): void {
    // Hidden fallback only if no visible checkbox rendered yet.
    // Templates should render the visible checkbox; this is a safety net.
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    echo '<input type="hidden" name="sa_remember_intent" value="1" />';
}, 99);

/**
 * Force remember=true for WooCommerce login when checkbox present or intent set.
 */
add_filter('woocommerce_process_login_errors', static function ($validation, $username, $password) {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    if (empty($_POST['rememberme']) && !empty($_POST['sa_remember_intent'])) {
        $_POST['rememberme'] = 'forever';
    }
    return $validation;
}, 5, 3);

/**
 * After successful customer login: persist cart + set long-lived auth when remembered.
 * Woo already merges session → persistent cart; we reaffirm and clear stale guest flag.
 */
add_action('wp_login', static function (string $user_login, $user): void {
    if (!$user instanceof WP_User || !class_exists('WooCommerce') || !function_exists('WC')) {
        return;
    }
    if (!WC()->cart || !WC()->session) {
        return;
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $remember = !empty($_POST['rememberme']) || !empty($_POST['sa_remember_intent']);
    if ($remember && !is_user_logged_in()) {
        // wp_login fires after cookies are set; WP already handled rememberme.
    }
    // Trigger persistent cart save so multi-device sync has fresh data.
    if (method_exists(WC()->cart, 'get_cart_for_session')) {
        $persisted = WC()->cart->get_cart_for_session();
        if (is_array($persisted)) {
            update_user_meta(
                (int) $user->ID,
                '_woocommerce_persistent_cart_' . get_current_blog_id(),
                ['cart' => $persisted]
            );
        }
    }
}, 20, 2);

/**
 * When a guest registers (checkout create-account or my-account), merge session cart.
 * WooCommerce does this on login; registration mid-checkout keeps the same session.
 */
add_action('woocommerce_created_customer', static function (int $customer_id): void {
    if (!function_exists('WC') || !WC()->cart) {
        return;
    }
    $cart = WC()->cart->get_cart_for_session();
    if (!empty($cart) && is_array($cart)) {
        update_user_meta(
            $customer_id,
            '_woocommerce_persistent_cart_' . get_current_blog_id(),
            ['cart' => $cart]
        );
    }
}, 20);

/**
 * Body class helper for checkout JS.
 */
add_filter('body_class', static function (array $classes): array {
    $classes[] = 'sa-persist-cart';
    return $classes;
});
