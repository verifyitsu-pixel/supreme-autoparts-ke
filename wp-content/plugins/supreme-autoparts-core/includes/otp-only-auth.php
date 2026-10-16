<?php
/**
 * OTP-only customer auth + token-secured order page after payment.
 *
 * - Customers sign in ONLY with the emailed 6-digit code (guest-account-otp.php).
 * - No customer passwords: password login, lost-password, registration and
 *   password emails are disabled for customer/subscriber accounts. Staff
 *   (manage_woocommerce / manage_options / edit_posts) keep password login for wp-admin.
 * - After payment the customer lands on /checkout/order-received/{id}/?key=wc_order_xxx
 *   (the Woo order key is the unguessable token) with the full order details and no login.
 *
 * @package Supreme_Autoparts_Core
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

/** True for accounts that must use the emailed code (not staff). */
function sa_core_otp_only_user(WP_User $user): bool
{
    if (user_can($user, 'manage_options') || user_can($user, 'manage_woocommerce') || user_can($user, 'edit_posts')) {
        return false;
    }
    return (bool) array_intersect(['customer', 'subscriber'], (array) $user->roles);
}

/* Password login is closed for customers (wp-login.php, wc login form, REST app passwords excluded). */
add_filter('authenticate', static function ($user) {
    if ($user instanceof WP_User && sa_core_otp_only_user($user)) {
        return new WP_Error(
            'sa_otp_only',
            __('Customers sign in with an emailed login code. Go to My Account and choose "Email me a login code".', 'supreme-autoparts-core')
        );
    }
    return $user;
}, 99);

/* No customer registration form (it emailed a password). Guest checkout still creates the account. */
add_filter('pre_option_woocommerce_enable_myaccount_registration', static fn () => 'no');
add_filter('pre_option_woocommerce_enable_checkout_login_reminder', static fn () => 'no');

/* Lost-password is gone: send people to the code login. */
add_filter('lostpassword_url', static function () {
    return function_exists('wc_get_page_permalink') ? (string) wc_get_page_permalink('myaccount') : home_url('/my-account/');
}, 99);
add_filter('woocommerce_lostpassword_url', static function () {
    return function_exists('wc_get_page_permalink') ? (string) wc_get_page_permalink('myaccount') : home_url('/my-account/');
}, 99);

add_action('wp_loaded', static function (): void {
    // Run before customer-accounts.php (10) and WC_Form_Handler (20): drop password-issue posts.
    if (isset($_POST['wc_reset_password'])) { // phpcs:ignore WordPress.Security.NonceVerification
        unset($_POST['wc_reset_password'], $_REQUEST['wc_reset_password']);
        if (function_exists('wc_add_notice')) {
            wc_add_notice(__('Passwords are no longer used. Enter your email and we will send a login code.', 'supreme-autoparts-core'), 'notice');
        }
    }
}, 1);

add_action('template_redirect', static function (): void {
    if (function_exists('is_wc_endpoint_url') && (is_wc_endpoint_url('lost-password'))) {
        wp_safe_redirect(wc_get_page_permalink('myaccount'));
        exit;
    }
}, 1);

/* Never email a customer a password-change notice they cannot use; staff mail is untouched.
   (wp_insert_user passes $user as an array; handle WP_User too.) */
add_filter('send_password_change_email', static function ($send, $user = null) {
    if (is_array($user) && !empty($user['ID'])) {
        $user = get_userdata((int) $user['ID']);
    }
    if ($user instanceof WP_User && sa_core_otp_only_user($user)) {
        return false;
    }
    return $send;
}, 99, 2);
add_filter('woocommerce_email_enabled_customer_new_account', '__return_false');
add_filter('woocommerce_email_enabled_customer_reset_password', '__return_false');

/* Order page token: a valid ?key= is proof enough, no login or email check. */
add_filter('woocommerce_order_received_verify_known_shoppers', static function ($verify) {
    return (function_exists('sa_gp_valid_key_order') && sa_gp_valid_key_order() > 0) ? false : $verify;
}, 20);

/* Order page must never be cached or indexed (URL contains the token). */
add_action('template_redirect', static function (): void {
    if (function_exists('is_order_received_page') && is_order_received_page()) {
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
        header('Referrer-Policy: no-referrer', true);
    }
}, 1);
