<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Guest checkout → auto customer account + OTP login for returning guests.
 *
 * - Guest checkout stays enabled (no forced login before pay).
 * - On order create: attach to existing user by billing email, or create a
 *   customer account (random password never emailed — OTP is the return path).
 * - My Account: email a short-lived one-time login CODE (not password-first).
 * - Rate-limited + hashed codes; Brevo transactional send.
 */

const SA_OTP_TTL = 600;          // 10 minutes
const SA_OTP_SEND_COOLDOWN = 45; // seconds between sends
const SA_OTP_HOURLY_CAP = 5;     // sends per email per hour
const SA_OTP_IP_HOURLY_CAP = 12;
const SA_OTP_MAX_ATTEMPTS = 5;

/**
 * Link a Woo order to a WP customer by billing email (create if needed).
 */
function sa_core_link_guest_order_to_account($order): void
{
    if (!$order instanceof WC_Order) {
        return;
    }

    // Already linked.
    if ((int) $order->get_customer_id() > 0) {
        return;
    }

    $email = strtolower(trim((string) $order->get_billing_email()));
    if ($email === '' || !is_email($email)) {
        return;
    }

    // Never bind store/admin mailbox to a customer cart order.
    $store = function_exists('sa_core_store_email') ? strtolower(sa_core_store_email()) : 'calvin@supremeautoparts.co.ke';
    if ($email === $store) {
        return;
    }

    $user = get_user_by('email', $email);
    $created = false;

    if (!$user instanceof WP_User) {
        if (!function_exists('wc_create_new_customer')) {
            return;
        }

        // Explicit password so Woo marks password_generated=false → no password email.
        // Customer never sees this password; OTP is the return login path.
        $password = function_exists('sa_core_generate_customer_password')
            ? sa_core_generate_customer_password(16)
            : wp_generate_password(16, true, true);

        $GLOBALS['sa_core_skip_customer_password_email'] = true;
        $GLOBALS['sa_core_guest_checkout_creating'] = true;

        $user_id = wc_create_new_customer(
            $email,
            '',
            $password,
            [
                'first_name' => (string) $order->get_billing_first_name(),
                'last_name'  => (string) $order->get_billing_last_name(),
                'role'       => 'customer',
            ]
        );

        unset($GLOBALS['sa_core_guest_checkout_creating'], $GLOBALS['sa_core_skip_customer_password_email']);
        $password = '';

        if (is_wp_error($user_id) || (int) $user_id <= 0) {
            if (is_wp_error($user_id) && function_exists('wc_get_logger')) {
                wc_get_logger()->warning(
                    'Guest account create failed: ' . $user_id->get_error_message(),
                    ['source' => 'sa-guest-otp']
                );
            }
            return;
        }

        $user = get_user_by('id', (int) $user_id);
        if (!$user instanceof WP_User) {
            return;
        }
        $created = true;
        update_user_meta((int) $user->ID, '_sa_guest_checkout_account', '1');
        update_user_meta((int) $user->ID, '_sa_guest_checkout_created_at', gmdate('c'));
        delete_user_option((int) $user->ID, 'default_password_nag', true);
    }

    // Skip privileged accounts — never attach shop orders to admins via guest email match alone.
    if (user_can($user, 'manage_options') || user_can($user, 'manage_woocommerce')) {
        return;
    }

    $order->set_customer_id((int) $user->ID);
    if ($order->get_billing_first_name() === '' && $user->first_name) {
        $order->set_billing_first_name((string) $user->first_name);
    }
    if ($order->get_billing_last_name() === '' && $user->last_name) {
        $order->set_billing_last_name((string) $user->last_name);
    }
    $order->update_meta_data('_sa_linked_guest_account', $created ? 'created' : 'existing');
    $order->add_order_note(
        $created
            ? sprintf('Guest checkout: created customer account #%d for %s (OTP login; no password emailed).', (int) $user->ID, $email)
            : sprintf('Guest checkout: linked to existing customer #%d (%s).', (int) $user->ID, $email)
    );
    $order->save();

    // Soft notice once for brand-new guest accounts (no password, points to OTP).
    if ($created) {
        sa_core_email_guest_account_ready($user, $order);
    }
}

/**
 * After checkout creates the order (before Whop redirect) — link/create account.
 *
 * @param int $order_id
 * @param array<string,mixed> $posted_data
 * @param WC_Order|null $order
 */
function sa_core_on_checkout_order_processed_link_account($order_id, $posted_data = [], $order = null): void
{
    unset($posted_data);
    if (!$order instanceof WC_Order) {
        $order = wc_get_order((int) $order_id);
    }
    if ($order instanceof WC_Order) {
        sa_core_link_guest_order_to_account($order);
    }
}
add_action('woocommerce_checkout_order_processed', 'sa_core_on_checkout_order_processed_link_account', 25, 3);

/** Safety net if order stayed guest (e.g. alternate create path). */
add_action('woocommerce_payment_complete', static function ($order_id): void {
    $order = wc_get_order((int) $order_id);
    if ($order instanceof WC_Order) {
        sa_core_link_guest_order_to_account($order);
    }
}, 15);

/** Also on thank-you for delayed/webhook-paid flows. */
add_action('woocommerce_thankyou', static function ($order_id): void {
    $order = wc_get_order((int) $order_id);
    if ($order instanceof WC_Order) {
        sa_core_link_guest_order_to_account($order);
    }
}, 5);

/**
 * Suppress password-mail path when guest-checkout is creating the user.
 */
add_action('woocommerce_created_customer', static function (int $customer_id, $data = [], $password_generated = false): void {
    unset($data, $password_generated);
    if (empty($GLOBALS['sa_core_skip_customer_password_email']) && empty($GLOBALS['sa_core_guest_checkout_creating'])) {
        return;
    }
    if ($customer_id > 0) {
        set_transient('sa_core_pw_mailed_' . $customer_id, '1', 15 * MINUTE_IN_SECONDS);
    }
}, 1, 3);

/* -------------------------------------------------------------------------
 * OTP helpers
 * ---------------------------------------------------------------------- */

function sa_core_otp_email_key(string $email): string
{
    return 'sa_otp_' . hash('sha256', strtolower(trim($email)));
}

function sa_core_otp_client_ip(): string
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    return preg_replace('/[^0-9a-fA-F:\.]/', '', $ip) ?: '0';
}

/**
 * @return true|WP_Error
 */
function sa_core_otp_can_send(string $email)
{
    $email = strtolower(trim($email));
    if ($email === '' || !is_email($email)) {
        return new WP_Error('sa_otp_email', __('Enter a valid email address.', 'supreme-autoparts-core'));
    }

    $cool_key = 'sa_otp_cool_' . hash('sha256', $email);
    $last = (int) get_transient($cool_key);
    $now = time();
    if ($last > 0 && ($now - $last) < SA_OTP_SEND_COOLDOWN) {
        $wait = SA_OTP_SEND_COOLDOWN - ($now - $last);
        return new WP_Error(
            'sa_otp_cooldown',
            sprintf(
                /* translators: %d: seconds */
                __('Please wait %d seconds before requesting another code.', 'supreme-autoparts-core'),
                max(1, $wait)
            )
        );
    }

    $hour_key = 'sa_otp_hour_' . hash('sha256', $email);
    $count = (int) get_transient($hour_key);
    if ($count >= SA_OTP_HOURLY_CAP) {
        return new WP_Error(
            'sa_otp_cap',
            __('Too many login codes requested for this email. Try again later, or contact calvin@supremeautoparts.co.ke.', 'supreme-autoparts-core')
        );
    }

    $ip_key = 'sa_otp_ip_' . hash('sha256', sa_core_otp_client_ip());
    $ip_count = (int) get_transient($ip_key);
    if ($ip_count >= SA_OTP_IP_HOURLY_CAP) {
        return new WP_Error(
            'sa_otp_ip_cap',
            __('Too many login code requests from this network. Please try again later.', 'supreme-autoparts-core')
        );
    }

    // Must be an existing customer (created at prior checkout or register).
    $user = get_user_by('email', $email);
    if (!$user instanceof WP_User) {
        // Same generic message — do not reveal whether email exists.
        return true; // allow "send" to no-op success for enumeration safety
    }
    if (user_can($user, 'manage_options') || user_can($user, 'manage_woocommerce')) {
        return new WP_Error(
            'sa_otp_staff',
            __('Staff accounts must use password login.', 'supreme-autoparts-core')
        );
    }

    return true;
}

function sa_core_otp_mark_send(string $email): void
{
    $email = strtolower(trim($email));
    set_transient('sa_otp_cool_' . hash('sha256', $email), time(), SA_OTP_SEND_COOLDOWN + 5);

    $hour_key = 'sa_otp_hour_' . hash('sha256', $email);
    $count = (int) get_transient($hour_key);
    set_transient($hour_key, $count + 1, HOUR_IN_SECONDS);

    $ip_key = 'sa_otp_ip_' . hash('sha256', sa_core_otp_client_ip());
    $ip_count = (int) get_transient($ip_key);
    set_transient($ip_key, $ip_count + 1, HOUR_IN_SECONDS);
}

/**
 * Generate, store (hashed), and email a 6-digit OTP.
 *
 * @return true|WP_Error
 */
function sa_core_otp_send(string $email)
{
    $email = strtolower(trim($email));
    $can = sa_core_otp_can_send($email);
    if (is_wp_error($can)) {
        return $can;
    }

    $user = get_user_by('email', $email);
    // Enumeration-safe: pretend success if no user.
    if (!$user instanceof WP_User) {
        sa_core_otp_mark_send($email);
        return true;
    }

    $code = (string) random_int(100000, 999999);
    $payload = [
        'hash'       => wp_hash_password($code),
        'user_id'    => (int) $user->ID,
        'email'      => $email,
        'attempts'   => 0,
        'created'    => time(),
        'expires'    => time() + SA_OTP_TTL,
    ];
    set_transient(sa_core_otp_email_key($email), $payload, SA_OTP_TTL);

    $sent = sa_core_email_login_otp($user, $code);
    $code = ''; // scrub
    sa_core_otp_mark_send($email);

    if (!$sent) {
        delete_transient(sa_core_otp_email_key($email));
        return new WP_Error(
            'sa_otp_mail',
            __('We could not send the login code. Please try again in a moment, or contact calvin@supremeautoparts.co.ke.', 'supreme-autoparts-core')
        );
    }

    return true;
}

/**
 * Verify OTP and log the user in.
 *
 * @return true|WP_Error
 */
function sa_core_otp_verify_and_login(string $email, string $code)
{
    $email = strtolower(trim($email));
    $code  = preg_replace('/\s+/', '', trim($code)) ?? '';

    if ($email === '' || !is_email($email)) {
        return new WP_Error('sa_otp_email', __('Enter a valid email address.', 'supreme-autoparts-core'));
    }
    if (!preg_match('/^\d{6}$/', $code)) {
        return new WP_Error('sa_otp_format', __('Enter the 6-digit code from your email.', 'supreme-autoparts-core'));
    }

    $key = sa_core_otp_email_key($email);
    $payload = get_transient($key);
    if (!is_array($payload) || empty($payload['hash']) || empty($payload['user_id'])) {
        return new WP_Error(
            'sa_otp_missing',
            __('That code has expired or was not found. Request a new login code.', 'supreme-autoparts-core')
        );
    }

    if (!empty($payload['expires']) && time() > (int) $payload['expires']) {
        delete_transient($key);
        return new WP_Error('sa_otp_expired', __('That code has expired. Request a new login code.', 'supreme-autoparts-core'));
    }

    $attempts = (int) ($payload['attempts'] ?? 0);
    if ($attempts >= SA_OTP_MAX_ATTEMPTS) {
        delete_transient($key);
        return new WP_Error(
            'sa_otp_locked',
            __('Too many incorrect attempts. Request a new login code.', 'supreme-autoparts-core')
        );
    }

    if (!wp_check_password($code, (string) $payload['hash'])) {
        $payload['attempts'] = $attempts + 1;
        $ttl_left = max(30, (int) $payload['expires'] - time());
        set_transient($key, $payload, $ttl_left);
        return new WP_Error(
            'sa_otp_bad',
            __('That code is incorrect. Check the email and try again.', 'supreme-autoparts-core')
        );
    }

    delete_transient($key);
    $code = '';

    $user = get_user_by('id', (int) $payload['user_id']);
    if (!$user instanceof WP_User) {
        return new WP_Error('sa_otp_user', __('Account not found. Contact calvin@supremeautoparts.co.ke.', 'supreme-autoparts-core'));
    }
    if (user_can($user, 'manage_options') || user_can($user, 'manage_woocommerce')) {
        return new WP_Error('sa_otp_staff', __('Staff accounts must use password login.', 'supreme-autoparts-core'));
    }

    wp_set_current_user((int) $user->ID);
    // Persistent remember cookie (~120 days) — same path as password login.
    if (function_exists('sa_core_set_customer_auth_cookie')) {
        sa_core_set_customer_auth_cookie((int) $user->ID);
    } else {
        wp_set_auth_cookie((int) $user->ID, true, is_ssl());
    }
    do_action('wp_login', $user->user_login, $user);

    return true;
}

/**
 * Email OTP via Brevo (preferred) or wp_mail.
 */
function sa_core_email_login_otp(WP_User $user, string $code): bool
{
    $to = (string) $user->user_email;
    if (!is_email($to) || $code === '') {
        return false;
    }

    $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) ?: 'Supreme Autoparts';
    $name = trim((string) $user->first_name);
    if ($name === '') {
        $name = trim((string) $user->display_name) ?: 'there';
    }
    $mins = (int) ceil(SA_OTP_TTL / 60);
    $account_url = function_exists('wc_get_page_permalink')
        ? (string) wc_get_page_permalink('myaccount')
        : home_url('/my-account/');
    $store_email = function_exists('sa_core_store_email') ? sa_core_store_email() : 'calvin@supremeautoparts.co.ke';
    $wa_display = '+1 917 437 5121';
    if (function_exists('sa_enquire_contact')) {
        $c = sa_enquire_contact();
        if (!empty($c['phone_display'])) {
            $wa_display = (string) $c['phone_display'];
        }
    }

    // No square brackets in subject (Gmail/Apple show them as noisy).
    $subject = sprintf('Your login code: %s', $code);

    $lines = [
        'Hi ' . $name . ',',
        '',
        'Your one-time login code for ' . $site . ' is:',
        '',
        $code,
        '',
        'This code expires in ' . $mins . ' minutes. Enter it on the My Account page to view orders, invoices, and payment methods.',
        '',
        'Log in here: ' . $account_url,
        '',
        'If you did not request this, you can ignore this email. Never share the code.',
        '',
        '— ' . $site,
        $store_email . ' · WhatsApp ' . $wa_display,
    ];
    $body = implode("\n", $lines);

    $html = sa_core_otp_email_html([
        'name'         => $name,
        'site'         => $site,
        'code'         => $code,
        'mins'         => $mins,
        'account_url'  => $account_url,
        'store_email'  => $store_email,
        'wa_display'   => $wa_display,
    ]);

    if (class_exists('SA_Brevo_API') && function_exists('sa_brevo_is_configured') && sa_brevo_is_configured()) {
        $result = SA_Brevo_API::send_transactional([
            'to'          => [['email' => $to, 'name' => $name]],
            'subject'     => $subject,
            'textContent' => $body,
            'htmlContent' => $html,
            'tags'        => ['supreme-autoparts', 'woocommerce', 'login-otp'],
        ]);
        if (!empty($result['ok'])) {
            return true;
        }
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log('[sa-core] OTP email Brevo failed for user ' . (int) $user->ID . ': ' . (string) ($result['error'] ?? 'unknown'));
        return false;
    }

    return (bool) wp_mail(
        $to,
        $subject,
        $body,
        [
            'Content-Type: text/plain; charset=UTF-8',
            'From: Supreme Autoparts <' . $store_email . '>',
        ]
    );
}

/**
 * Branded HTML body for login OTP (logo header added by Brevo brand_html / send_transactional).
 *
 * @param array{name:string,site:string,code:string,mins:int,account_url:string,store_email:string,wa_display:string} $ctx
 */
function sa_core_otp_email_html(array $ctx): string
{
    $name = esc_html((string) ($ctx['name'] ?? 'there'));
    $site = esc_html((string) ($ctx['site'] ?? 'Supreme Autoparts'));
    $code = esc_html((string) ($ctx['code'] ?? ''));
    $mins = (int) ($ctx['mins'] ?? 10);
    $url  = esc_url((string) ($ctx['account_url'] ?? ''));
    $email = esc_html((string) ($ctx['store_email'] ?? 'calvin@supremeautoparts.co.ke'));
    $wa = esc_html((string) ($ctx['wa_display'] ?? '+1 917 437 5121'));

    return '<div class="sa-otp-mail" style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#18181b;max-width:560px;">'
        . '<p style="margin:0 0 12px;font-size:15px;line-height:1.5;">Hi ' . $name . ',</p>'
        . '<p style="margin:0 0 12px;font-size:15px;line-height:1.5;">Your one-time login code for <strong>' . $site . '</strong> is:</p>'
        . '<p style="margin:20px 0;text-align:center;">'
        . '<span style="display:inline-block;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:32px;letter-spacing:0.28em;font-weight:700;color:#0B0B0D;background:#f4f4f5;border:1px solid #e4e4e7;border-radius:10px;padding:14px 22px;">'
        . $code
        . '</span></p>'
        . '<p style="margin:0 0 16px;font-size:15px;line-height:1.5;">This code expires in <strong>' . $mins . ' minutes</strong>. Enter it on the My Account page to view orders, invoices, and payment methods.</p>'
        . '<p style="margin:0 0 20px;text-align:center;">'
        . '<a href="' . $url . '" style="display:inline-block;background:#16a34a;color:#ffffff;text-decoration:none;font-weight:600;font-size:15px;padding:12px 22px;border-radius:8px;">Open My Account</a>'
        . '</p>'
        . '<p style="margin:0 0 8px;font-size:13px;line-height:1.5;color:#52525b;">If the button does not work, use this link:<br />'
        . '<a href="' . $url . '" style="color:#16a34a;word-break:break-all;">' . esc_html((string) ($ctx['account_url'] ?? '')) . '</a></p>'
        . '<p style="margin:16px 0 0;font-size:13px;line-height:1.5;color:#71717a;">If you did not request this, you can ignore this email. Never share the code.</p>'
        . '<p style="margin:20px 0 0;padding-top:14px;border-top:1px solid #e4e4e7;font-size:13px;line-height:1.5;color:#52525b;">'
        . '— ' . $site . '<br />'
        . '<a href="mailto:' . esc_attr((string) ($ctx['store_email'] ?? '')) . '" style="color:#16a34a;">' . $email . '</a>'
        . ' · WhatsApp ' . $wa
        . '</p>'
        . '</div>';
}

/**
 * Soft welcome when a guest checkout creates an account (no password).
 */
function sa_core_email_guest_account_ready(WP_User $user, WC_Order $order): void
{
    $to = (string) $user->user_email;
    if (!is_email($to)) {
        return;
    }
    // One notice per account.
    if (get_user_meta((int) $user->ID, '_sa_guest_welcome_sent', true)) {
        return;
    }

    $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
    $name = trim((string) $order->get_billing_first_name()) ?: 'there';
    $account_url = function_exists('wc_get_page_permalink')
        ? (string) wc_get_page_permalink('myaccount')
        : home_url('/my-account/');
    $order_no = (string) $order->get_order_number();

    $subject = sprintf('Order #%s — your %s account is ready', $order_no, $site);
    $body = implode("\n", [
        'Hi ' . $name . ',',
        '',
        'Thanks for your order #' . $order_no . ' at ' . $site . '.',
        '',
        'We linked this order to an account using your email so you can track orders, invoices, and payment methods later.',
        '',
        'Next time you visit My Account, enter this email and choose “Email me a login code”. We will send a one-time code — no password to remember.',
        '',
        'My Account: ' . $account_url,
        '',
        '— Supreme Autoparts',
        'calvin@supremeautoparts.co.ke · WhatsApp +1 917 437 5121',
    ]);

    $ok = false;
    if (class_exists('SA_Brevo_API') && function_exists('sa_brevo_is_configured') && sa_brevo_is_configured()) {
        $html = '<div class="sa-guest-welcome-mail" style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#18181b;max-width:560px;">';
        foreach (preg_split("/\r\n|\r|\n/", $body) ?: [] as $line) {
            if ($line === '') {
                $html .= '<div style="height:8px;">&nbsp;</div>';
                continue;
            }
            $html .= '<p style="margin:0 0 8px;font-size:15px;line-height:1.5;color:#18181b;">'
                . esc_html($line) . '</p>';
        }
        $html .= '</div>';
        $result = SA_Brevo_API::send_transactional([
            'to'          => [['email' => $to, 'name' => $name]],
            'subject'     => $subject,
            'textContent' => $body,
            'htmlContent' => $html,
            'tags'        => ['supreme-autoparts', 'woocommerce', 'guest-account'],
        ]);
        $ok = !empty($result['ok']);
    } else {
        $ok = (bool) wp_mail($to, $subject, $body, [
            'Content-Type: text/plain; charset=UTF-8',
            'From: Supreme Autoparts <calvin@supremeautoparts.co.ke>',
        ]);
    }

    if ($ok) {
        update_user_meta((int) $user->ID, '_sa_guest_welcome_sent', gmdate('c'));
    }
}

/* -------------------------------------------------------------------------
 * Form handlers (My Account)
 * ---------------------------------------------------------------------- */

add_action('wp_loaded', static function (): void {
    if (is_admin() && !wp_doing_ajax()) {
        return;
    }

    // Request OTP
    if (isset($_POST['sa_otp_request'], $_POST['sa_otp_email'])) {
        $nonce = isset($_POST['sa_otp_nonce']) ? (string) wp_unslash($_POST['sa_otp_nonce']) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'sa_otp_login')) {
            if (function_exists('wc_add_notice')) {
                wc_add_notice(__('Security check failed. Please refresh and try again.', 'supreme-autoparts-core'), 'error');
            }
            return;
        }
        $email = sanitize_email(wp_unslash((string) $_POST['sa_otp_email']));
        $result = sa_core_otp_send($email);
        if (is_wp_error($result)) {
            if (function_exists('wc_add_notice')) {
                wc_add_notice($result->get_error_message(), 'error');
            }
            return;
        }
        if (function_exists('wc_add_notice')) {
            wc_add_notice(
                __('If that email has an account, we sent a 6-digit login code. It expires in 10 minutes — check inbox and spam.', 'supreme-autoparts-core'),
                'success'
            );
        }
        // Keep email in query for the verify step UI.
        $url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/');
        wp_safe_redirect(add_query_arg([
            'sa_otp'   => '1',
            'sa_email' => rawurlencode($email),
        ], $url));
        exit;
    }

    // Verify OTP
    if (isset($_POST['sa_otp_verify'], $_POST['sa_otp_email'], $_POST['sa_otp_code'])) {
        $nonce = isset($_POST['sa_otp_nonce']) ? (string) wp_unslash($_POST['sa_otp_nonce']) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'sa_otp_login')) {
            if (function_exists('wc_add_notice')) {
                wc_add_notice(__('Security check failed. Please refresh and try again.', 'supreme-autoparts-core'), 'error');
            }
            return;
        }
        $email = sanitize_email(wp_unslash((string) $_POST['sa_otp_email']));
        $code  = preg_replace('/\D+/', '', (string) wp_unslash($_POST['sa_otp_code'])) ?? '';
        $result = sa_core_otp_verify_and_login($email, $code);
        if (is_wp_error($result)) {
            if (function_exists('wc_add_notice')) {
                wc_add_notice($result->get_error_message(), 'error');
            }
            $url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/');
            wp_safe_redirect(add_query_arg([
                'sa_otp'   => '1',
                'sa_email' => rawurlencode($email),
            ], $url));
            exit;
        }
        $redirect = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/');
        $redirect = apply_filters('woocommerce_login_redirect', $redirect, wp_get_current_user());
        wp_safe_redirect($redirect);
        exit;
    }
}, 18);
