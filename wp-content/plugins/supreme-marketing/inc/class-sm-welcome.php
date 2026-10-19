<?php
/**
 * First-order welcome discount: a personal 20% code for first-time customers
 * who create an account or sign in (Google or code login) at checkout.
 *
 * Replaces the old shared WELCOME30. One use per customer, first order only,
 * 14-day expiry, restricted to the customer's own email, auto-applied at
 * checkout while signed in.
 *
 * Abuse protection (owner requirement, 2026-10-19):
 *  - normalized email matching (plus-tags stripped, Gmail dots ignored),
 *    disposable domains blocked;
 *  - one code per device cookie / IP / normalized email cluster;
 *  - signup rate limit per IP;
 *  - at checkout, shipping address and phone are matched against past
 *    welcome-code orders - repeats across different accounts are blocked;
 *  - every blocked attempt is logged and visible under Marketing > Welcome 20.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class SM_Welcome
{
    public const DISCOUNT = 20;
    public const EXPIRY_DAYS = 14;
    public const PREFIX = 'SUP20-';
    public const MAX_SIGNUPS_PER_IP_DAY = 5;

    public static function init(): void
    {
        add_action('init', [self::class, 'ensure_device_cookie'], 5);
        add_action('init', [self::class, 'backfill_order_norms'], 30);
        add_action('woocommerce_new_order', [self::class, 'record_order_norm'], 20);
        add_action('user_register', [self::class, 'on_register'], 20);
        add_action('wp_login', [self::class, 'on_login'], 20, 2);
        add_action('woocommerce_cart_loaded_from_session', [self::class, 'auto_apply'], 35);
        add_action('woocommerce_before_checkout_form', [self::class, 'auto_apply'], 6);
        add_action('woocommerce_checkout_process', [self::class, 'validate_at_checkout'], 5);
        add_action('woocommerce_order_status_processing', [self::class, 'mark_redeemed']);
        add_action('woocommerce_order_status_completed', [self::class, 'mark_redeemed']);
        add_action('woocommerce_order_status_on-hold', [self::class, 'mark_redeemed']);
    }

    /* ------------------------------------------------------------------ */
    /* Normalization + blocklists                                          */
    /* ------------------------------------------------------------------ */

    /** Canonical email for abuse matching: lowercase, tags stripped, Gmail dots ignored. */
    public static function norm_email(string $email): string
    {
        $email = strtolower(trim($email));
        if (!str_contains($email, '@')) {
            return $email;
        }
        [$local, $domain] = explode('@', $email, 2);
        $local = preg_replace('/\+.*$/', '', (string) $local);
        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $local = str_replace('.', '', (string) $local);
            $domain = 'gmail.com';
        }
        return $local . '@' . $domain;
    }

    public static function norm_phone(string $phone): string
    {
        $d = preg_replace('/\D+/', '', $phone);
        return strlen($d) >= 7 ? substr($d, -9) : '';
    }

    public static function norm_address(array $parts): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/', '', implode(' ', array_map('strval', $parts))));
    }

    public static function is_disposable_domain(string $email): bool
    {
        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
        static $blocked = [
            'mailinator.com', 'tempmail.com', 'temp-mail.org', '10minutemail.com', '10minutemail.net',
            'guerrillamail.com', 'guerrillamail.net', 'yopmail.com', 'trashmail.com', 'trashmail.net',
            'fakeinbox.com', 'sharklasers.com', 'getnada.com', 'dispostable.com', 'maildrop.cc',
            'mintemail.com', 'tempail.com', 'mohmal.com', 'emailondeck.com', 'throwawaymail.com',
            'tempinbox.com', 'burnermail.io', 'inboxkitten.com', 'mailnesia.com', 'anonaddy.com',
        ];
        return $domain !== '' && in_array($domain, $blocked, true);
    }

    private static function ip(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }

    public static function ensure_device_cookie(): void
    {
        if (is_admin() && !wp_doing_ajax()) {
            return;
        }
        if (empty($_COOKIE['sm_wid']) && !headers_sent()) {
            $id = wp_generate_password(24, false, false);
            setcookie('sm_wid', $id, time() + 365 * DAY_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
            $_COOKIE['sm_wid'] = $id;
        }
    }

    private static function device_id(): string
    {
        $c = (string) ($_COOKIE['sm_wid'] ?? '');
        return preg_match('/^[A-Za-z0-9]{16,64}$/', $c) ? $c : '';
    }

    private static function log_block(string $kind, string $detail, string $email = ''): void
    {
        global $wpdb;
        $wpdb->insert(SM_DB::table('abuse_log'), [
            'kind'       => $kind,
            'detail'     => substr($detail, 0, 250),
            'email'      => strtolower($email),
            'email_norm' => self::norm_email($email),
            'ip'         => self::ip(),
            'device_id'  => self::device_id(),
            'created_at' => sm_now(),
        ]);
        if (function_exists('sa_core_audit_log')) {
            sa_core_audit_log('marketing.welcome20_blocked', ['kind' => $kind, 'detail' => $detail, 'email' => $email], 'warning');
        }
    }

    /* ------------------------------------------------------------------ */
    /* Issuance                                                             */
    /* ------------------------------------------------------------------ */

    public static function on_register(int $user_id): void
    {
        self::maybe_issue($user_id, 'signup');
    }

    public static function on_login(string $login, $user): void
    {
        if ($user instanceof WP_User) {
            self::maybe_issue((int) $user->ID, 'signin');
        }
    }

    /** True when the user/email has never placed a processable order. */
    public static function has_zero_orders(int $user_id, string $email): bool
    {
        if (!function_exists('wc_get_orders')) {
            return false;
        }
        $statuses = ['wc-processing', 'wc-completed', 'wc-on-hold', 'wc-pending'];
        if ($user_id > 0 && (int) wc_get_customer_order_count($user_id) > 0) {
            return false;
        }
        if ($email !== '') {
            $orders = wc_get_orders(['limit' => 1, 'return' => 'ids', 'status' => $statuses, 'billing_email' => $email]);
            if (!empty($orders)) {
                return false;
            }
        }
        return true;
    }

    /** Issue a personal 20% code when eligible; log and skip when abuse checks trip. */
    public static function maybe_issue(int $user_id, string $trigger): ?string
    {
        if ($user_id <= 0 || !class_exists('WC_Coupon')) {
            return null;
        }
        $user = get_userdata($user_id);
        if (!$user || !is_email($user->user_email)) {
            return null;
        }
        // Customers only - never staff/admin accounts.
        if (!in_array('customer', (array) $user->roles, true) && !in_array('subscriber', (array) $user->roles, true)) {
            return null;
        }
        $email = strtolower($user->user_email);
        $norm = self::norm_email($email);
        $ip = self::ip();
        $device = self::device_id();

        // Already issued (by user, email or normalized email)?
        global $wpdb;
        $t = SM_DB::table('welcome_codes');
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT code FROM $t WHERE (user_id = %d OR email = %s OR email_norm = %s) AND status IN ('active','redeemed') LIMIT 1",
            $user_id, $email, $norm
        ));
        if ($existing) {
            return (string) $existing;
        }
        // First order only.
        if (!self::has_zero_orders($user_id, $email)) {
            return null; // normal returning customer, not abuse - no log
        }
        // Same mailbox under a plus/dot variant with past orders: block and log.
        if (self::norm_has_orders($norm)) {
            self::log_block('email_variant_repeat', 'Email alias of an address that has already ordered tried to claim the first-order code', $email);
            return null;
        }
        // Disposable inbox.
        if (self::is_disposable_domain($email)) {
            self::log_block('disposable_email', 'Signup from a disposable email domain, no welcome code', $email);
            return null;
        }
        // Device / IP cluster: one welcome code each.
        if ($device !== '') {
            $hit = $wpdb->get_var($wpdb->prepare("SELECT code FROM $t WHERE device_id = %s LIMIT 1", $device));
            if ($hit) {
                self::log_block('device_repeat', 'Same browser device already received a welcome code', $email);
                return null;
            }
        }
        if ($ip !== '') {
            $hit = $wpdb->get_var($wpdb->prepare(
                "SELECT code FROM $t WHERE ip = %s AND created_at >= DATE_SUB(%s, INTERVAL 30 DAY) LIMIT 1",
                $ip, sm_now()
            ));
            if ($hit) {
                self::log_block('ip_repeat', 'This IP already received a welcome code in the last 30 days', $email);
                return null;
            }
        }
        // Signup rate limit per IP.
        if ($ip !== '') {
            $log = (array) get_option('sm_signup_ip_log', []);
            $today = gmdate('Y-m-d');
            $log = array_filter($log, static fn ($v) => ($v['day'] ?? '') === $today);
            $log[$ip] = ['day' => $today, 'count' => (int) ($log[$ip]['count'] ?? 0) + 1];
            update_option('sm_signup_ip_log', $log, false);
            if ((int) $log[$ip]['count'] > self::MAX_SIGNUPS_PER_IP_DAY) {
                self::log_block('signup_rate', 'More than ' . self::MAX_SIGNUPS_PER_IP_DAY . ' signups from one IP in a day', $email);
                return null;
            }
        }

        // Issue the personal coupon.
        $code = self::PREFIX . strtoupper(wp_generate_password(6, false, false));
        $expires = time() + self::EXPIRY_DAYS * DAY_IN_SECONDS;
        $coupon = new WC_Coupon();
        $coupon->set_code($code);
        $coupon->set_description('Welcome 20: personal first-order discount for ' . $email . ' (trigger: ' . $trigger . ').');
        $coupon->set_discount_type('percent');
        $coupon->set_amount(self::DISCOUNT);
        $coupon->set_individual_use(true);
        $coupon->set_usage_limit(1);
        $coupon->set_usage_limit_per_user(1);
        $coupon->set_email_restrictions([$email]);
        $coupon->set_date_expires($expires);
        $fee = (int) get_option('sa_svc_fee_product', 0);
        if ($fee > 0) {
            $coupon->set_excluded_product_ids([$fee]); // never discount the booking-fee deposit
        }
        $coupon->save();
        update_post_meta((int) $coupon->get_id(), '_sm_welcome20', $email);

        $wpdb->insert($t, [
            'user_id'    => $user_id,
            'email'      => $email,
            'email_norm' => $norm,
            'code'       => $code,
            'status'     => 'active',
            'ip'         => $ip,
            'device_id'  => $device,
            'created_at' => sm_now(),
            'expires_at' => sm_dt($expires),
        ]);
        update_user_meta($user_id, '_sm_welcome20_code', $code);
        return $code;
    }


    /** True when the normalized mailbox already appears on a past order. */
    public static function norm_has_orders(string $norm): bool
    {
        if ($norm === '') {
            return false;
        }
        global $wpdb;
        $t = SM_DB::table('order_norms');
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT order_id FROM $t WHERE email_norm = %s LIMIT 1", $norm));
    }

    /** Record the normalized billing email of every new order. */
    public static function record_order_norm($order_id): void
    {
        if (!function_exists('wc_get_order')) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        $norm = self::norm_email((string) $order->get_billing_email());
        if ($norm === '' || !str_contains($norm, '@')) {
            return;
        }
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO " . SM_DB::table('order_norms') . " (email_norm, order_id, created_at) VALUES (%s, %d, %s)",
            $norm, (int) $order_id, sm_now()
        ));
    }

    /** One-time backfill of order email norms, 200 orders per request. */
    public static function backfill_order_norms(): void
    {
        if (get_option('sm_norm_backfill_done') === 'yes' || !function_exists('wc_get_orders')) {
            return;
        }
        $page = (int) get_option('sm_norm_backfill_page', 1);
        $ids = wc_get_orders(['limit' => 200, 'page' => $page, 'return' => 'ids', 'status' => ['wc-processing', 'wc-completed', 'wc-on-hold', 'wc-pending']]);
        foreach ($ids as $oid) {
            self::record_order_norm($oid);
        }
        if (count($ids) < 200) {
            update_option('sm_norm_backfill_done', 'yes', false);
            delete_option('sm_norm_backfill_page');
        } else {
            update_option('sm_norm_backfill_page', $page + 1, false);
        }
    }

    /** Active (unexpired, unredeemed) welcome code for an email. */
    public static function active_code_for_email(string $email): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT code, expires_at FROM ' . SM_DB::table('welcome_codes') . " WHERE (email = %s OR email_norm = %s) AND status = 'active' AND (expires_at IS NULL OR expires_at > %s) ORDER BY id DESC LIMIT 1",
            strtolower($email), self::norm_email($email), sm_now()
        ), ARRAY_A);
        if (!$row) {
            return null;
        }
        if (function_exists('wc_get_coupon_id_by_code') && (int) wc_get_coupon_id_by_code((string) $row['code']) <= 0) {
            return null;
        }
        return ['code' => (string) $row['code'], 'expires' => (string) ($row['expires_at'] ?? '')];
    }

    /* ------------------------------------------------------------------ */
    /* Auto-apply + redemption                                              */
    /* ------------------------------------------------------------------ */

    /** Apply the signed-in customer's personal code silently when the cart allows it. */
    public static function auto_apply(): void
    {
        if (!is_user_logged_in() || !function_exists('WC') || !WC()->cart || WC()->cart->is_empty() || !wc_coupons_enabled()) {
            return;
        }
        $user = wp_get_current_user();
        $active = self::active_code_for_email((string) $user->user_email);
        if (!$active) {
            return;
        }
        // Needs at least one eligible (non booking-fee) item.
        $fee = (int) get_option('sa_svc_fee_product', 0);
        $eligible = false;
        foreach (WC()->cart->get_cart() as $item) {
            $pid = (int) ($item['product_id'] ?? 0);
            $vid = (int) ($item['variation_id'] ?? 0);
            if ($fee <= 0 || ($pid !== $fee && $vid !== $fee)) {
                $eligible = true;
                break;
            }
        }
        if (!$eligible) {
            return;
        }
        $applied = WC()->cart->get_applied_coupons();
        if ($applied) {
            return; // personal code is individual_use; never push another coupon off
        }
        WC()->cart->apply_coupon($active['code']);
    }

    /**
     * Checkout guard: the personal code must belong to this customer's email,
     * and the shipping address/phone must not match a past welcome-code order.
     */
    public static function validate_at_checkout(): void
    {
        if (!function_exists('WC') || !WC()->cart) {
            return;
        }
        $applied = WC()->cart->get_applied_coupons();
        if (!$applied) {
            return;
        }
        global $wpdb;
        $t = SM_DB::table('welcome_codes');
        foreach ($applied as $code) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $t WHERE code = %s AND status = 'active' LIMIT 1",
                strtoupper((string) $code)
            ), ARRAY_A);
            if (!$row) {
                continue; // not one of ours
            }
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $email = !empty($_POST['billing_email']) ? strtolower(sanitize_email(wp_unslash((string) $_POST['billing_email']))) : '';
            if ($email !== '' && self::norm_email($email) !== (string) $row['email_norm']) {
                WC()->cart->remove_coupon($code);
                self::log_block('code_mismatch', 'Welcome code used with a different email at checkout', $email);
                wc_add_notice(__('That welcome code belongs to a different account. It was removed from this order.', 'supreme-marketing'), 'error');
                continue;
            }
            // Address/phone repeat check against past welcome orders.
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $phone = self::norm_phone((string) ($_POST['billing_phone'] ?? ''));
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $addr = self::norm_address([
                (string) ($_POST['shipping_address_1'] ?? ($_POST['billing_address_1'] ?? '')),
                (string) ($_POST['shipping_city'] ?? ($_POST['billing_city'] ?? '')),
                (string) ($_POST['shipping_postcode'] ?? ($_POST['billing_postcode'] ?? '')),
            ]);
            if ($phone === '' && $addr === '') {
                continue;
            }
            $orders = wc_get_orders([
                'limit'  => 50,
                'status' => ['wc-processing', 'wc-completed', 'wc-on-hold'],
                'return' => 'objects',
            ]);
            foreach ((array) $orders as $o) {
                $used_welcome = false;
                foreach ($o->get_coupon_codes() as $oc) {
                    if (str_starts_with(strtoupper($oc), self::PREFIX)) {
                        $used_welcome = true;
                        break;
                    }
                }
                if (!$used_welcome) {
                    continue;
                }
                $o_phone = self::norm_phone((string) $o->get_billing_phone());
                $o_addr = self::norm_address([
                    $o->has_shipping_address() ? $o->get_shipping_address_1() : $o->get_billing_address_1(),
                    $o->has_shipping_address() ? $o->get_shipping_city() : $o->get_billing_city(),
                    $o->has_shipping_address() ? $o->get_shipping_postcode() : $o->get_billing_postcode(),
                ]);
                if (($phone !== '' && $o_phone !== '' && $phone === $o_phone) || ($addr !== '' && $o_addr !== '' && $addr === $o_addr)) {
                    WC()->cart->remove_coupon($code);
                    self::log_block('address_phone_repeat', 'Welcome code blocked: phone or address already used a first-order code', $email);
                    wc_add_notice(__('The first-order discount is limited to one per customer and address. It was removed from this order.', 'supreme-marketing'), 'error');
                    break;
                }
            }
        }
    }

    /** Mark the code redeemed when the order is paid. */
    public static function mark_redeemed(int $order_id): void
    {
        if (!function_exists('wc_get_order')) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        global $wpdb;
        $t = SM_DB::table('welcome_codes');
        foreach ($order->get_coupon_codes() as $code) {
            if (!str_starts_with(strtoupper($code), self::PREFIX)) {
                continue;
            }
            $wpdb->update($t, [
                'status'      => 'redeemed',
                'order_id'    => $order_id,
                'redeemed_at' => sm_now(),
            ], ['code' => strtoupper($code), 'status' => 'active']);
        }
    }

    /** Retire the old shared WELCOME30 (owner decision 2026-10-19: personal 20% codes instead). */
    public static function retire_legacy_shared_coupon(): void
    {
        if (get_option('sm_welcome30_retired')) {
            return;
        }
        if (function_exists('wc_get_coupon_id_by_code')) {
            foreach (['WELCOME30', 'WELCOME20'] as $legacy) {
                $id = (int) wc_get_coupon_id_by_code($legacy);
                if ($id > 0) {
                    wp_trash_post($id);
                    if (function_exists('sa_core_audit_log')) {
                        sa_core_audit_log('marketing.welcome_coupon_retired', ['code' => $legacy], 'notice');
                    }
                }
            }
        }
        update_option('sm_welcome30_retired', 1, false);
    }

    /* ------------------------------------------------------------------ */
    /* Admin data                                                           */
    /* ------------------------------------------------------------------ */

    public static function stats(): array
    {
        global $wpdb;
        $t = SM_DB::table('welcome_codes');
        $a = SM_DB::table('abuse_log');
        return [
            'issued'   => (int) $wpdb->get_var("SELECT COUNT(*) FROM $t"),
            'active'   => (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE status = 'active'"),
            'redeemed' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE status = 'redeemed'"),
            'blocked'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM $a"),
        ];
    }

    public static function recent_codes(int $limit = 50): array
    {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . SM_DB::table('welcome_codes') . ' ORDER BY id DESC LIMIT %d',
            $limit
        ), ARRAY_A);
    }

    public static function recent_blocks(int $limit = 50): array
    {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . SM_DB::table('abuse_log') . ' ORDER BY id DESC LIMIT %d',
            $limit
        ), ARRAY_A);
    }
}
