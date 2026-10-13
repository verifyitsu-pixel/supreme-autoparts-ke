<?php
/**
 * Reliable customer logout.
 *
 * - Logout link always carries a fresh nonce (wc_logout_url()).
 * - A logged-in visit to /my-account/customer-logout/ logs out even when the
 *   nonce is stale (cached page / old tab) instead of WooCommerce's
 *   "Are you sure?" confirm loop that looked like "logout does nothing".
 * - Clears auth cookies + Woo session, sends no-cache + Clear-Site-Data so the
 *   browser does not show a cached logged-in page afterwards.
 * - Redirects to My Account (login) with a "You've been logged out" notice.
 * - Visible Log out link in header, side drawer and My Account (mobile too).
 *
 * @package Supreme_Autoparts_Core
 */

defined('ABSPATH') || exit;

function sa_core_logout_done_url(): string
{
    $acct = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/');
    return add_query_arg('sa_logged_out', '1', $acct);
}

function sa_core_logout_link_url(): string
{
    return function_exists('wc_logout_url') ? wc_logout_url() : wp_logout_url(home_url('/'));
}

/** Full logout: session, cookies, Woo session, no-cache. */
function sa_core_do_customer_logout(): void
{
    if (function_exists('WC') && WC()->session && method_exists(WC()->session, 'destroy_session')) {
        WC()->session->destroy_session();
    }
    wp_logout(); // wp_destroy_current_session + wp_clear_auth_cookie + wp_set_current_user(0).
    wp_clear_auth_cookie();
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    nocache_headers();
    if (!headers_sent()) {
        header('Clear-Site-Data: "cache"');
    }
    wp_safe_redirect(sa_core_logout_done_url());
    exit;
}

/** Handle /my-account/customer-logout/ before WooCommerce's confirm step. */
add_action('template_redirect', static function (): void {
    if (!function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('customer-logout')) {
        return;
    }
    if (is_user_logged_in()) {
        sa_core_do_customer_logout();
    }
    wp_safe_redirect(sa_core_logout_done_url());
    exit;
}, 1);

/** wp-login.php?action=logout and any other logout path land on the same page. */
add_filter('logout_redirect', static function ($redirect_to, $requested, $user) {
    if ($user instanceof WP_User && (user_can($user, 'manage_woocommerce') || user_can($user, 'manage_options'))) {
        return $redirect_to;
    }
    return sa_core_logout_done_url();
}, 99, 3);
add_filter('woocommerce_logout_default_redirect_url', static fn() => sa_core_logout_done_url(), 99);

/** Notice + never cache the post-logout page. */
add_action('template_redirect', static function (): void {
    if (empty($_GET['sa_logged_out'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return;
    }
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    nocache_headers();
    if (!is_user_logged_in() && function_exists('wc_add_notice') && function_exists('WC') && WC()->session) {
        if (!wc_has_notice(__("You've been logged out.", 'supreme-autoparts-core'), 'success')) {
            wc_add_notice(__("You've been logged out.", 'supreme-autoparts-core'), 'success');
        }
    }
}, 2);

/** Logged-in HTML is per-user: never let browsers/proxies reuse it. */
add_action('send_headers', static function (): void {
    if (is_user_logged_in() && !is_admin()) {
        nocache_headers();
        header('Vary: Cookie', false);
    }
});

/** My Account: obvious full-width Log out button (the nav strip scrolls off-screen on phones). */
add_action('woocommerce_after_account_navigation', static function (): void {
    if (!is_user_logged_in()) {
        return;
    }
    echo '<a class="sa-logout-btn" href="' . esc_url(sa_core_logout_link_url()) . '">' . esc_html__('Log out', 'supreme-autoparts-core') . '</a>';
});

add_action('wp_head', static function (): void {
    echo '<style id="sa-logout-css">'
        . '.sa-logout-btn{display:block;margin:.6rem 0 0;padding:.7rem 1rem;text-align:center;border:1px solid #d4d4d8;border-radius:10px;color:#b91c1c;font-weight:600;text-decoration:none;background:#fff}'
        . '.sa-logout-btn:hover{background:#fef2f2}'
        . '.sa-header__logout{font-size:.85rem;font-weight:600;color:inherit;text-decoration:none;opacity:.85;white-space:nowrap}'
        . '.sa-header__logout:hover{opacity:1;text-decoration:underline}'
        . '@media (min-width:801px){.sa-logout-btn{display:none}}'
        . '.sa-side__link--logout{color:#b91c1c}'
        . '</style>';
}, 50);
