<?php
/**
 * WELCOME30 — 30% off for first-time / new customers (worldwide).
 *
 * Seeds a real WooCommerce coupon, validates "email never ordered",
 * sitewide announce, cart/checkout notice + auto-apply when eligible.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const SA_WELCOME_COUPON_CODE = 'WELCOME30';
const SA_WELCOME_COUPON_VER  = '1';

/**
 * Canonical welcome coupon code (uppercase).
 */
function sa_core_welcome_coupon_code(): string
{
    $env = getenv('SA_WELCOME_COUPON');
    if (is_string($env) && $env !== '') {
        return strtoupper(wc_format_coupon_code($env));
    }
    return SA_WELCOME_COUPON_CODE;
}

/**
 * Seed / refresh WELCOME30 coupon (idempotent via version option).
 */
function sa_core_seed_welcome_coupon(): void
{
    if (!class_exists('WC_Coupon') || !function_exists('wc_get_coupon_id_by_code')) {
        return;
    }

    $code = sa_core_welcome_coupon_code();
    $id   = (int) wc_get_coupon_id_by_code($code);

    $coupon = $id > 0 ? new WC_Coupon($id) : new WC_Coupon();
    $coupon->set_code($code);
    $coupon->set_description('New customers only — 30% off first order (worldwide). Seeded by Supreme Autoparts Core.');
    $coupon->set_discount_type('percent');
    $coupon->set_amount(30);
    $coupon->set_individual_use(true);
    $coupon->set_usage_limit(0);           // unlimited total redemptions
    $coupon->set_usage_limit_per_user(1);  // one use per email/customer
    $coupon->set_limit_usage_to_x_items(0);
    $coupon->set_free_shipping(false);
    $coupon->set_exclude_sale_items(false);
    $coupon->set_minimum_amount('');
    $coupon->set_maximum_amount('');
    $coupon->set_email_restrictions([]); // enforced in PHP (new-customer check)
    $coupon->set_product_ids([]);
    $coupon->set_excluded_product_ids([]);
    $coupon->set_product_categories([]);
    $coupon->set_excluded_product_categories([]);
    // No expiry for launch promo (owner can set later in Super Admin → Discounts).
    $coupon->set_date_expires(null);

    $saved = $coupon->save();
    if ($saved) {
        update_post_meta((int) $saved, '_sa_welcome_coupon', '1');
        update_post_meta((int) $saved, '_sa_welcome_coupon_ver', SA_WELCOME_COUPON_VER);
        if (function_exists('sa_core_audit_log')) {
            sa_core_audit_log(
                'discount.welcome_coupon_seed',
                ['id' => (int) $saved, 'code' => $code, 'amount' => 30],
                'notice'
            );
        }
    }
}

add_action('init', static function (): void {
    if (get_option('sa_welcome_coupon_ver') === SA_WELCOME_COUPON_VER) {
        return;
    }
    if (!class_exists('WooCommerce')) {
        return;
    }
    sa_core_seed_welcome_coupon();
    update_option('sa_welcome_coupon_ver', SA_WELCOME_COUPON_VER);
}, 30);

/**
 * Resolve billing/customer email for coupon eligibility checks.
 */
function sa_core_welcome_customer_email(): string
{
    if (is_user_logged_in()) {
        $user = wp_get_current_user();
        if ($user && is_email($user->user_email)) {
            return strtolower($user->user_email);
        }
    }

    if (function_exists('WC') && WC()->customer) {
        $email = (string) WC()->customer->get_billing_email();
        if (is_email($email)) {
            return strtolower($email);
        }
    }

    if (function_exists('WC') && WC()->session) {
        $posted = WC()->session->get('customer');
        if (is_array($posted) && !empty($posted['email']) && is_email((string) $posted['email'])) {
            return strtolower((string) $posted['email']);
        }
    }

    // Checkout AJAX / POST.
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    if (!empty($_POST['billing_email']) && is_email(wp_unslash((string) $_POST['billing_email']))) {
        return strtolower(sanitize_email(wp_unslash((string) $_POST['billing_email'])));
    }

    return '';
}

/**
 * True when this email (or logged-in user) has never placed a paid/processable order.
 */
function sa_core_is_new_customer(?string $email = null): bool
{
    $email = $email !== null ? strtolower(trim($email)) : sa_core_welcome_customer_email();

    if (is_user_logged_in()) {
        $uid = get_current_user_id();
        $count = function_exists('wc_get_customer_order_count')
            ? (int) wc_get_customer_order_count($uid)
            : 0;
        if ($count > 0) {
            return false;
        }
        // Also check by account email in case orders were guest then account linked poorly.
        $user = get_userdata($uid);
        if ($user && is_email($user->user_email)) {
            $email = strtolower($user->user_email);
        }
    }

    if ($email === '' || !is_email($email)) {
        // Unknown email: treat as potentially new (auto-apply waits for email on checkout).
        // Manual apply without email is allowed; validity re-checked when email is known.
        return true;
    }

    if (!function_exists('wc_get_orders')) {
        return true;
    }

    $orders = wc_get_orders([
        'limit'    => 1,
        'return'   => 'ids',
        'status'   => ['wc-processing', 'wc-completed', 'wc-on-hold', 'wc-pending', 'wc-refunded'],
        'billing_email' => $email,
    ]);

    return empty($orders);
}

/**
 * Validate WELCOME30: new customers only once email is known.
 */
add_filter('woocommerce_coupon_is_valid', static function ($valid, $coupon, $discount = null) {
    unset($discount);
    if (!$valid || !($coupon instanceof WC_Coupon)) {
        return $valid;
    }
    if (strtoupper($coupon->get_code()) !== sa_core_welcome_coupon_code()) {
        return $valid;
    }

    $email = sa_core_welcome_customer_email();
    if ($email === '') {
        // Allow apply early; block at checkout if returning customer email appears.
        return true;
    }

    if (!sa_core_is_new_customer($email)) {
        throw new Exception(__('WELCOME30 is for new customers only (first order). This email already has an order with us.', 'supreme-autoparts-core'));
    }

    return true;
}, 20, 3);

/**
 * Extra checkout guard: remove coupon if returning customer email entered.
 */
add_action('woocommerce_checkout_process', static function (): void {
    if (!function_exists('WC') || !WC()->cart) {
        return;
    }
    $code = sa_core_welcome_coupon_code();
    $applied = WC()->cart->get_applied_coupons();
    if (!in_array(strtolower($code), array_map('strtolower', $applied), true)) {
        return;
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $email = !empty($_POST['billing_email'])
        ? strtolower(sanitize_email(wp_unslash((string) $_POST['billing_email'])))
        : sa_core_welcome_customer_email();

    if ($email !== '' && !sa_core_is_new_customer($email)) {
        WC()->cart->remove_coupon($code);
        wc_add_notice(
            __('WELCOME30 was removed — it is only for first-time customers.', 'supreme-autoparts-core'),
            'error'
        );
    }
});

/**
 * Capture ?coupon=WELCOME30 (or any code) into session and try apply.
 */
add_action('template_redirect', static function (): void {
    if (is_admin() || !function_exists('WC') || !WC()->cart) {
        return;
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $raw = isset($_GET['coupon']) ? sanitize_text_field(wp_unslash((string) $_GET['coupon'])) : '';
    if ($raw === '') {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!empty($_GET['welcome']) || !empty($_GET['welcome30'])) {
            $raw = sa_core_welcome_coupon_code();
        }
    }
    if ($raw === '') {
        return;
    }
    $code = wc_format_coupon_code($raw);
    if (WC()->session) {
        WC()->session->set('sa_pending_coupon', $code);
    }
    sa_core_try_apply_welcome_coupon($code);
}, 20);

/**
 * Attempt to apply welcome (or pending) coupon when cart has items and customer is new.
 */
function sa_core_try_apply_welcome_coupon(?string $prefer = null): void
{
    if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
        return;
    }
    if (!wc_coupons_enabled()) {
        return;
    }

    $code = $prefer ?: sa_core_welcome_coupon_code();
    if (WC()->session) {
        $pending = (string) WC()->session->get('sa_pending_coupon');
        if ($pending !== '') {
            $code = $pending;
        }
    }

    $code = wc_format_coupon_code($code);
    $applied = array_map('strtolower', WC()->cart->get_applied_coupons());
    if (in_array(strtolower($code), $applied, true)) {
        return;
    }
    // Respect individual_use: skip auto-apply if another coupon is already on.
    if (!empty($applied) && strtoupper($code) === sa_core_welcome_coupon_code()) {
        return;
    }

    $email = sa_core_welcome_customer_email();
    if (strtoupper($code) === sa_core_welcome_coupon_code() && $email !== '' && !sa_core_is_new_customer($email)) {
        return;
    }

    $result = WC()->cart->apply_coupon($code);
    if ($result && WC()->session) {
        WC()->session->set('sa_pending_coupon', null);
    }
}

add_action('woocommerce_cart_loaded_from_session', static function (): void {
    sa_core_try_apply_welcome_coupon();
}, 30);

add_action('woocommerce_add_to_cart', static function (): void {
    sa_core_try_apply_welcome_coupon();
}, 30);

add_action('woocommerce_before_cart', static function (): void {
    sa_core_try_apply_welcome_coupon();
}, 5);

add_action('woocommerce_before_checkout_form', static function (): void {
    sa_core_try_apply_welcome_coupon();
}, 5);

/**
 * Cart/checkout callout: show code clearly for new customers.
 */
function sa_core_welcome_offer_notice(): void
{
    if (!function_exists('WC') || !WC()->cart) {
        return;
    }
    $code = sa_core_welcome_coupon_code();
    $email = sa_core_welcome_customer_email();
    if ($email !== '' && !sa_core_is_new_customer($email)) {
        return;
    }

    $applied = array_map('strtolower', WC()->cart->get_applied_coupons());
    $is_on   = in_array(strtolower($code), $applied, true);

    if ($is_on) {
        echo '<div class="sa-welcome-offer sa-welcome-offer--on" role="status">';
        echo '<strong>' . esc_html__('New customer: 30% off applied', 'supreme-autoparts-core') . '</strong>';
        echo ' <span class="sa-welcome-offer__code"><code>' . esc_html($code) . '</code></span>';
        echo '</div>';
        return;
    }

    echo '<div class="sa-welcome-offer" role="status">';
    echo '<strong>' . esc_html__('New customers: 30% off', 'supreme-autoparts-core') . '</strong>';
    echo ' — ' . esc_html__('Use code', 'supreme-autoparts-core') . ' ';
    echo '<span class="sa-welcome-offer__code"><code>' . esc_html($code) . '</code></span>';
    echo ' ' . esc_html__('on your first order (worldwide).', 'supreme-autoparts-core');
    if (function_exists('wc_get_cart_url') && !is_cart() && !is_checkout()) {
        // no-op
    }
    echo '</div>';
}

add_action('woocommerce_before_cart', 'sa_core_welcome_offer_notice', 8);
add_action('woocommerce_before_checkout_form', 'sa_core_welcome_offer_notice', 8);

/**
 * Front-end styles for the cart/checkout offer chip (charcoal/amber).
 */
add_action('wp_head', static function (): void {
    if (!function_exists('is_cart')) {
        return;
    }
    if (!is_cart() && !is_checkout()) {
        return;
    }
    echo '<style id="sa-welcome-offer-css">
.sa-welcome-offer{margin:0 0 1rem;padding:.85rem 1rem;border-radius:10px;border:1px solid rgba(245,166,35,.35);background:linear-gradient(180deg,rgba(245,166,35,.12),rgba(11,11,13,.4));color:#F4F4F5;font-size:.9rem;line-height:1.4}
.sa-welcome-offer--on{border-color:rgba(245,166,35,.55);background:rgba(245,166,35,.16)}
.sa-welcome-offer__code code{font-weight:700;letter-spacing:.06em;color:#F5A623;background:rgba(11,11,13,.55);padding:.15rem .45rem;border-radius:6px;border:1px solid rgba(245,166,35,.35)}
</style>';
}, 40);
