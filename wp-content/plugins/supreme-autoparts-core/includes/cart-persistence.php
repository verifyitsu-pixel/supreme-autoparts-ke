<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cart + session persistence and always-on Remember me for customers.
 *
 * - Logged-in: WooCommerce persistent cart (cross-device when same account).
 * - Guest: longer WC session cookie so the cart survives browser restarts.
 * - Customers: always persistent auth cookies (~90 days) until explicit Log out.
 * - Staff (admin / shop_manager): shorter cookies.
 * - On login / register: merge guest session cart into the user cart.
 * - Log out still clears cookies fully (wp_logout / wp_clear_auth_cookie untouched).
 */

/** Customer auth cookie lifetime (stay signed in until Log out). */
function sa_core_customer_auth_days(): int
{
    return (int) apply_filters('sa_core_customer_auth_days', 90);
}

/** Staff auth cookie when Remember me is checked. */
function sa_core_staff_auth_days(): int
{
    return (int) apply_filters('sa_core_staff_auth_days', 14);
}

function sa_core_user_is_store_customer(?WP_User $user): bool
{
    if (!$user instanceof WP_User || !$user->exists()) {
        return false;
    }
    if (user_can($user, 'manage_options') || user_can($user, 'manage_woocommerce')) {
        return false;
    }
    $roles = (array) $user->roles;
    if (in_array('administrator', $roles, true) || in_array('shop_manager', $roles, true)) {
        return false;
    }
    return true;
}

/**
 * Soft-enforce Woo options related to accounts + cart continuity.
 */
function sa_core_apply_cart_persistence_settings(): void
{
    if (!class_exists('WooCommerce')) {
        return;
    }

    update_option('woocommerce_enable_guest_checkout', 'yes');
    update_option('woocommerce_enable_checkout_login_reminder', 'yes');
    update_option('woocommerce_enable_signup_and_login_from_checkout', 'yes');
    update_option('woocommerce_enable_myaccount_registration', 'yes');
    update_option('woocommerce_enable_persistent_cart', 'yes');

    update_option('sa_cart_persistence_ver', '2');
}

add_action('init', static function (): void {
    if (get_option('sa_cart_persistence_ver') === '2') {
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
 * Auth cookie lifetime:
 * - Customers: always ~90 days (persistent until Log out), whether or not Remember was checked.
 * - Staff: 14 days when remembered, otherwise WP default (~2 days).
 *
 * wp_set_password on lost-password invalidates prior sessions once (expected).
 * The next login with the emailed password gets a fresh 90-day cookie again.
 */
add_filter('auth_cookie_expiration', static function (int $length, int $user_id, bool $remember): int {
    $user = get_userdata($user_id);
    if (sa_core_user_is_store_customer($user instanceof WP_User ? $user : null)) {
        return sa_core_customer_auth_days() * DAY_IN_SECONDS;
    }
    if ($remember) {
        return sa_core_staff_auth_days() * DAY_IN_SECONDS;
    }
    return $length;
}, 20, 3);

/**
 * Storefront Woo login: always treat Remember me as on.
 * Staff still get shorter TTL via auth_cookie_expiration.
 */
add_action('wp_loaded', static function (): void {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    if (empty($_POST['login']) && empty($_POST['woocommerce-login-nonce'])) {
        return;
    }
    $_POST['rememberme'] = 'forever'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
}, 5);

/**
 * Hidden remember fields so missing/unchecked checkboxes still persist.
 */
add_action('woocommerce_login_form_end', static function (): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    echo '<input type="hidden" name="sa_remember_intent" value="1" />';
    echo '<input type="hidden" name="rememberme" value="forever" />';
}, 99);

add_filter('woocommerce_process_login_errors', static function ($validation, $username, $password) {
    unset($username, $password);
    $_POST['rememberme'] = 'forever'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
    return $validation;
}, 5, 3);

/** Force remember flag into wp_signon credentials. */
add_filter('woocommerce_login_credentials', static function (array $creds): array {
    $creds['remember'] = true;
    return $creds;
}, 20);

/**
 * After successful login: persist cart so multi-device sync has fresh data.
 */
add_action('wp_login', static function (string $user_login, $user): void {
    unset($user_login);
    if (!$user instanceof WP_User || !class_exists('WooCommerce') || !function_exists('WC')) {
        return;
    }
    if (!WC()->cart || !WC()->session) {
        return;
    }
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
 * When a guest registers, merge session cart into user meta.
 * We do not auto-login after register (password is emailed); first login restores cart.
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
 * If Woo auth-cookies a customer (rare post-register path), force remember=true.
 * Logout remains wp_logout → wp_clear_auth_cookie (untouched).
 */
add_action('woocommerce_set_customer_auth_cookie', static function (int $customer_id): void {
    $user = get_userdata($customer_id);
    if (!sa_core_user_is_store_customer($user instanceof WP_User ? $user : null)) {
        return;
    }
    // Woo may already have set cookies; re-issue once with remember for 90-day TTL.
    if (!empty($GLOBALS['sa_core_customer_auth_remembered'])) {
        return;
    }
    $GLOBALS['sa_core_customer_auth_remembered'] = true;
    wp_set_auth_cookie($customer_id, true);
}, 5);

/**
 * After successful login: ensure customers get a remember=true auth cookie (90-day).
 * Covers any path that signed in without remember. Does not touch logout.
 */
add_action('wp_login', static function (string $user_login, $user): void {
    unset($user_login);
    if (!$user instanceof WP_User || !sa_core_user_is_store_customer($user)) {
        return;
    }
    if (!empty($GLOBALS['sa_core_customer_auth_remembered'])) {
        return;
    }
    $GLOBALS['sa_core_customer_auth_remembered'] = true;
    wp_set_auth_cookie((int) $user->ID, true);
}, 5, 2);

add_filter('body_class', static function (array $classes): array {
    $classes[] = 'sa-persist-cart';
    $classes[] = 'sa-persist-auth';
    return $classes;
});
