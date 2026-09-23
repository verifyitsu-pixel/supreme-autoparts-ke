<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Customer registration, role caps, and My Account endpoints.
 * Never assigns shop_manager to customers.
 */

function sa_core_ensure_customer_capabilities(): void
{
    $role = get_role('customer');
    if (!$role) {
        add_role('customer', 'Customer', [
            'read' => true,
        ]);
        $role = get_role('customer');
    }
    if (!$role) {
        return;
    }

    $allow = [
        'read'             => true,
        'sa_view_invoices' => true,
        'view_order'       => true,
        'pay_for_order'    => true,
        'order_again'      => true,
        'cancel_order'     => true,
    ];
    foreach ($allow as $cap => $grant) {
        if ($grant) {
            $role->add_cap($cap);
        } else {
            $role->remove_cap($cap);
        }
    }

    foreach (['manage_woocommerce', 'edit_shop_orders', 'edit_products', 'shop_manager', 'manage_options', 'edit_others_posts', 'publish_posts'] as $deny) {
        $role->remove_cap($deny);
    }

    foreach (['administrator', 'shop_manager'] as $r) {
        $admin = get_role($r);
        if ($admin) {
            $admin->add_cap('sa_view_invoices');
        }
    }

    update_option('sa_customer_caps_ver', '4');
}

add_action('init', static function (): void {
    if (get_option('sa_customer_caps_ver') === '4') {
        return;
    }
    sa_core_ensure_customer_capabilities();
}, 5);

add_action('user_register', static function (int $user_id): void {
    $user = new WP_User($user_id);
    if (!$user->exists()) {
        return;
    }
    if (user_can($user_id, 'manage_options') || user_can($user_id, 'manage_woocommerce')) {
        return;
    }
    if (in_array('shop_manager', (array) $user->roles, true)) {
        $user->remove_role('shop_manager');
    }
    if (!in_array('customer', (array) $user->roles, true) && !in_array('administrator', (array) $user->roles, true)) {
        $user->set_role('customer');
    }
}, 5);

/**
 * @return array<string,string>
 */
function sa_core_my_account_endpoints(): array
{
    return [
        'orders'          => __('Orders', 'supreme-autoparts-core'),
        'invoices'        => __('Invoices', 'supreme-autoparts-core'),
        'edit-address'    => __('Addresses', 'supreme-autoparts-core'),
        'payment-methods' => __('Payment methods', 'supreme-autoparts-core'),
        'edit-account'    => __('Account details', 'supreme-autoparts-core'),
        'support'         => __('Support / Enquire', 'supreme-autoparts-core'),
        'customer-logout' => __('Log out', 'supreme-autoparts-core'),
    ];
}

add_action('init', static function (): void {
    if (!class_exists('WooCommerce')) {
        return;
    }
    foreach (array_keys(sa_core_my_account_endpoints()) as $endpoint) {
        if ($endpoint === 'customer-logout') {
            continue;
        }
        add_rewrite_endpoint($endpoint, EP_ROOT | EP_PAGES);
    }
    // Custom support endpoint query var.
    add_rewrite_endpoint('support', EP_ROOT | EP_PAGES);
    add_rewrite_endpoint('invoices', EP_ROOT | EP_PAGES);
}, 11);

add_filter('woocommerce_get_query_vars', static function (array $vars): array {
    $vars['support']  = 'support';
    $vars['invoices'] = 'invoices';
    return $vars;
});

add_filter('woocommerce_account_menu_items', static function (array $items): array {
    $desired = sa_core_my_account_endpoints();
    $ordered = [];
    if (isset($items['dashboard'])) {
        $ordered['dashboard'] = __('Dashboard', 'supreme-autoparts-core');
    }
    foreach ($desired as $key => $label) {
        if ($key === 'customer-logout') {
            continue;
        }
        $ordered[$key] = $label;
    }
    if (isset($items['downloads'])) {
        // Keep downloads if Woo enables them, after orders.
        $re = [];
        foreach ($ordered as $k => $v) {
            $re[$k] = $v;
            if ($k === 'orders') {
                $re['downloads'] = $items['downloads'];
            }
        }
        $ordered = $re;
    }
    $ordered['customer-logout'] = $desired['customer-logout'];
    return $ordered;
}, 20);


add_action('woocommerce_account_invoices_endpoint', static function (): void {
    $template = locate_template('woocommerce/myaccount/invoices.php');
    if ($template) {
        include $template;
        return;
    }
    echo '<div class="sa-account-panel"><h2>' . esc_html__('Invoices', 'supreme-autoparts-core') . '</h2></div>';
});

add_action('woocommerce_account_support_endpoint', static function (): void {
    $template = locate_template('woocommerce/myaccount/support.php');
    if ($template) {
        include $template;
        return;
    }
    $email = 'calvin@supremeautoparts.co.ke';
    echo '<div class="sa-account-panel"><h2>' . esc_html__('Support / Enquire', 'supreme-autoparts-core') . '</h2>';
    echo '<p>' . esc_html__('Need help with an order or fitment?', 'supreme-autoparts-core') . ' ';
    echo '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a></p></div>';
});

/**
 * Flush rewrite once after support endpoint added.
 */
add_action('init', static function (): void {
    if (get_option('sa_myaccount_endpoints_ver') === '5') {
        return;
    }
    flush_rewrite_rules(false);
    update_option('sa_myaccount_endpoints_ver', '5');
}, 99);

/**
 * After account details save: keep billing name in sync; Brevo handled by sa-brevo-mail.
 */
add_action('woocommerce_save_account_details', static function (int $user_id): void {
    $user = get_userdata($user_id);
    if (!$user) {
        return;
    }
    $first = (string) get_user_meta($user_id, 'first_name', true);
    $last  = (string) get_user_meta($user_id, 'last_name', true);
    if ($first !== '' && (string) get_user_meta($user_id, 'billing_first_name', true) === '') {
        update_user_meta($user_id, 'billing_first_name', $first);
    } elseif ($first !== '') {
        update_user_meta($user_id, 'billing_first_name', $first);
    }
    if ($last !== '') {
        update_user_meta($user_id, 'billing_last_name', $last);
    }
    // Upsert Brevo contact when API key present (name/email profile edits).
    if (class_exists('SA_Brevo_Sync') && function_exists('sa_brevo_is_configured') && sa_brevo_is_configured()) {
        SA_Brevo_Sync::sync_user($user_id);
    } elseif (class_exists('SA_Brevo_Sync') && (bool) get_user_meta($user_id, 'sa_brevo_optin', true)) {
        SA_Brevo_Sync::sync_user($user_id);
    }
}, 30);

add_action('woocommerce_order_details_after_order_table', static function ($order): void {
    if (!$order instanceof WC_Order) {
        return;
    }
    $user_id = get_current_user_id();
    $is_owner = $user_id && (int) $order->get_user_id() === $user_id;
    $is_staff = current_user_can('manage_woocommerce') || current_user_can('edit_shop_orders');
    if (!$is_owner && !$is_staff) {
        return;
    }
    // Avoid duplicate CTAs when theme view-order already shows docs header.
    if (is_wc_endpoint_url('view-order')) {
        return;
    }
    $oid = (int) $order->get_id();
    $url = sa_core_invoice_url($oid);
    $email_url = function_exists('sa_core_invoice_email_url') ? sa_core_invoice_email_url($oid) : '';
    $doc = function_exists('sa_core_invoice_doc_title') ? sa_core_invoice_doc_title($order) : __('Invoice', 'supreme-autoparts-core');
    echo '<div class="sa-order-docs" role="group" aria-label="' . esc_attr__('Order documents', 'supreme-autoparts-core') . '">';
    echo '<a class="sa-btn sa-btn--sm button" href="' . esc_url($url) . '" target="_blank" rel="noopener">'
        . esc_html(sprintf(/* translators: Invoice or Receipt */ __('View %s', 'supreme-autoparts-core'), $doc)) . '</a> ';
    echo '<a class="sa-btn sa-btn--outline sa-btn--sm button" href="' . esc_url(add_query_arg('download', '1', $url)) . '">'
        . esc_html__('Download', 'supreme-autoparts-core') . '</a>';
    if ($email_url) {
        echo ' <a class="sa-btn sa-btn--outline sa-btn--sm button" href="' . esc_url($email_url) . '">'
            . esc_html__('Email me', 'supreme-autoparts-core') . '</a>';
    }
    echo '</div>';
}, 20);

add_filter('woocommerce_my_account_my_orders_actions', static function (array $actions, $order): array {
    if (!$order instanceof WC_Order) {
        return $actions;
    }
    $user_id = get_current_user_id();
    $is_owner = $user_id && (int) $order->get_user_id() === $user_id;
    $is_staff = current_user_can('manage_woocommerce') || current_user_can('edit_shop_orders');
    $has_cap = current_user_can('sa_view_invoices');
    if ($is_owner || $is_staff || $has_cap) {
        $oid = (int) $order->get_id();
        $doc = function_exists('sa_core_invoice_doc_title') ? sa_core_invoice_doc_title($order) : __('Invoice', 'supreme-autoparts-core');
        $actions['sa_invoice'] = [
            'url'  => sa_core_invoice_url($oid),
            'name' => $doc,
        ];
        if (function_exists('sa_core_invoice_email_url')) {
            $actions['sa_email_invoice'] = [
                'url'  => sa_core_invoice_email_url($oid),
                'name' => __('Email', 'supreme-autoparts-core'),
            ];
        }
    }
    return $actions;
}, 20, 2);

/**
 * Keep registration auto-password / auto-username options pinned.
 * Redeploys and seed helpers must not flip customers back to choosing a password.
 */
function sa_core_force_registration_password_options(): void
{
    update_option('woocommerce_registration_generate_password', 'yes');
    update_option('woocommerce_registration_generate_username', 'yes');
    update_option('woocommerce_enable_myaccount_registration', 'yes');
}

add_action('init', static function (): void {
    if (get_option('sa_reg_password_force_ver') === '1') {
        // Still re-assert options lightly every boot — cheap and prevents drift.
        if (get_option('woocommerce_registration_generate_password') !== 'yes'
            || get_option('woocommerce_registration_generate_username') !== 'yes') {
            sa_core_force_registration_password_options();
        }
        return;
    }
    sa_core_force_registration_password_options();
    update_option('sa_reg_password_force_ver', '1');
}, 21);

/**
 * Email-safe random password for new customer accounts (never emailed).
 * Customers sign in with a one-time login code; they can set a password later.
 */
function sa_core_generate_customer_password(int $length = 18): string
{
    $length = max(16, min(32, $length));
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    $max = strlen($alphabet) - 1;
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, $max)];
    }
    return $password;
}

/**
 * True when this WP user is a store customer (not admin / shop manager).
 */
function sa_core_user_is_customer_for_password_mail(WP_User $user): bool
{
    if (user_can($user, 'manage_options') || user_can($user, 'manage_woocommerce')) {
        return false;
    }
    $roles = (array) $user->roles;
    if (in_array('administrator', $roles, true) || in_array('shop_manager', $roles, true)) {
        return false;
    }
    return true;
}

/** OTP lifetime (~12 minutes). */
function sa_core_otp_ttl(): int
{
    return (int) apply_filters('sa_core_otp_ttl', 12 * MINUTE_IN_SECONDS);
}

function sa_core_otp_max_attempts(): int
{
    return (int) apply_filters('sa_core_otp_max_attempts', 5);
}

function sa_core_otp_resend_cooldown(): int
{
    return (int) apply_filters('sa_core_otp_resend_cooldown', 60);
}

function sa_core_otp_hourly_cap(): int
{
    return (int) apply_filters('sa_core_otp_hourly_cap', 5);
}

function sa_core_otp_generate_code(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function sa_core_otp_hash(string $code, int $user_id): string
{
    $pepper = wp_salt('auth');
    return hash_hmac('sha256', $code . '|' . $user_id, $pepper);
}

function sa_core_otp_clear(int $user_id): void
{
    delete_user_meta($user_id, '_sa_otp_hash');
    delete_user_meta($user_id, '_sa_otp_expires');
    delete_user_meta($user_id, '_sa_otp_attempts');
    delete_user_meta($user_id, '_sa_otp_sent_at');
}

/**
 * Store a hashed OTP for the user. Never stores plaintext.
 */
function sa_core_otp_store(int $user_id, string $code): void
{
    update_user_meta($user_id, '_sa_otp_hash', sa_core_otp_hash($code, $user_id));
    update_user_meta($user_id, '_sa_otp_expires', (string) (time() + sa_core_otp_ttl()));
    update_user_meta($user_id, '_sa_otp_attempts', '0');
    update_user_meta($user_id, '_sa_otp_sent_at', (string) time());
}

/**
 * @return true|WP_Error
 */
function sa_core_otp_can_send(int $user_id)
{
    $sent_at = (int) get_user_meta($user_id, '_sa_otp_sent_at', true);
    $cooldown = sa_core_otp_resend_cooldown();
    if ($sent_at > 0 && (time() - $sent_at) < $cooldown) {
        $wait = $cooldown - (time() - $sent_at);
        return new WP_Error(
            'sa_otp_cooldown',
            sprintf(
                /* translators: %d: seconds */
                __('Please wait %d seconds before requesting another code.', 'supreme-autoparts-core'),
                max(1, $wait)
            )
        );
    }

    $hour_key = 'sa_otp_hour_' . $user_id;
    $count = (int) get_transient($hour_key);
    if ($count >= sa_core_otp_hourly_cap()) {
        return new WP_Error(
            'sa_otp_rate',
            __('Too many login codes requested. Please try again later.', 'supreme-autoparts-core')
        );
    }

    return true;
}

function sa_core_otp_bump_hourly(int $user_id): void
{
    $hour_key = 'sa_otp_hour_' . $user_id;
    $count = (int) get_transient($hour_key);
    set_transient($hour_key, $count + 1, HOUR_IN_SECONDS);
}

/**
 * @return true|WP_Error
 */
function sa_core_otp_verify(int $user_id, string $code)
{
    $code = preg_replace('/\D+/', '', $code) ?? '';
    if (strlen($code) !== 6) {
        return new WP_Error('sa_otp_format', __('Enter the 6-digit code from your email.', 'supreme-autoparts-core'));
    }

    $hash = (string) get_user_meta($user_id, '_sa_otp_hash', true);
    $expires = (int) get_user_meta($user_id, '_sa_otp_expires', true);
    $attempts = (int) get_user_meta($user_id, '_sa_otp_attempts', true);

    if ($hash === '' || $expires <= 0) {
        return new WP_Error('sa_otp_missing', __('No login code is pending. Request a new code.', 'supreme-autoparts-core'));
    }
    if ($attempts >= sa_core_otp_max_attempts()) {
        sa_core_otp_clear($user_id);
        return new WP_Error('sa_otp_locked', __('Too many incorrect attempts. Request a new code.', 'supreme-autoparts-core'));
    }
    if (time() > $expires) {
        sa_core_otp_clear($user_id);
        return new WP_Error('sa_otp_expired', __('That login code has expired. Request a new one.', 'supreme-autoparts-core'));
    }

    $expected = sa_core_otp_hash($code, $user_id);
    if (!hash_equals($hash, $expected)) {
        update_user_meta($user_id, '_sa_otp_attempts', (string) ($attempts + 1));
        $left = sa_core_otp_max_attempts() - ($attempts + 1);
        if ($left <= 0) {
            sa_core_otp_clear($user_id);
            return new WP_Error('sa_otp_locked', __('Too many incorrect attempts. Request a new code.', 'supreme-autoparts-core'));
        }
        return new WP_Error(
            'sa_otp_mismatch',
            sprintf(
                /* translators: %d: attempts remaining */
                __('Incorrect code. %d attempts remaining.', 'supreme-autoparts-core'),
                $left
            )
        );
    }

    sa_core_otp_clear($user_id);
    return true;
}

/**
 * Email a 6-digit login code via wp_mail / Brevo. Never logs the code. No BCC.
 */
function sa_core_email_login_code(WP_User $user, string $code): bool
{
    $to = (string) $user->user_email;
    if (!is_email($to)) {
        return false;
    }

    $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
    $mins = max(1, (int) round(sa_core_otp_ttl() / 60));
    $subject = sprintf('[%s] Your login code', $site);
    $name = trim((string) $user->display_name);
    if ($name === '') {
        $name = (string) $user->user_login;
    }

    $lines = [
        'Hi ' . $name . ',',
        '',
        'Your Supreme Autoparts login code is:',
        '',
        $code,
        '',
        'This code expires in about ' . $mins . ' minutes and can be used once.',
        'If you did not request this, you can ignore this email.',
        '',
        '— Supreme Autoparts',
        'calvin@supremeautoparts.co.ke',
    ];
    $body = implode("\n", $lines);

    $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.5;color:#0B0B0D;">'
        . '<p>Hi ' . esc_html($name) . ',</p>'
        . '<p>Your Supreme Autoparts login code is:</p>'
        . '<p style="font-size:28px;font-weight:700;letter-spacing:0.2em;font-family:ui-monospace,Menlo,Consolas,monospace;color:#0B0B0D;background:#F5F5F5;padding:14px 18px;border-radius:8px;display:inline-block;border:1px solid #E5E5E5;">'
        . esc_html($code)
        . '</p>'
        . '<p style="color:#444;">This code expires in about ' . (int) $mins . ' minutes and can be used once.</p>'
        . '<p style="color:#666;font-size:14px;">If you did not request this, you can ignore this email.</p>'
        . '<p style="margin-top:24px;color:#0B0B0D;">— Supreme Autoparts<br>'
        . '<a href="mailto:calvin@supremeautoparts.co.ke" style="color:#F5A623;">calvin@supremeautoparts.co.ke</a></p>'
        . '</div>';

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: Supreme Autoparts <calvin@supremeautoparts.co.ke>',
    ];

    // Prefer HTML; plain text is still in $body for clients that strip HTML via filters.
    unset($body);
    return (bool) wp_mail($to, $subject, $html, $headers);
}

/**
 * Issue a fresh OTP for a customer user (rate-limited). Does not log the code.
 *
 * @return true|WP_Error
 */
function sa_core_otp_issue_for_user(WP_User $user)
{
    if (!sa_core_user_is_customer_for_password_mail($user)) {
        return new WP_Error('sa_otp_staff', __('Staff accounts use the password reset link instead.', 'supreme-autoparts-core'));
    }

    $can = sa_core_otp_can_send((int) $user->ID);
    if (is_wp_error($can)) {
        return $can;
    }

    $code = sa_core_otp_generate_code();
    sa_core_otp_store((int) $user->ID, $code);
    $sent = sa_core_email_login_code($user, $code);
    $code = ''; // best-effort clear

    if (!$sent) {
        sa_core_otp_clear((int) $user->ID);
        return new WP_Error(
            'sa_otp_mail',
            __('We could not send the login code. Please try again or contact calvin@supremeautoparts.co.ke.', 'supreme-autoparts-core')
        );
    }

    sa_core_otp_bump_hourly((int) $user->ID);
    return true;
}

/**
 * Opaque browser challenge so we never put user id in the URL.
 *
 * @param array{user_id:int,purpose:string,redirect:string,email:string} $data
 */
function sa_core_otp_set_pending(array $data): void
{
    $token = bin2hex(random_bytes(16));
    $payload = [
        'user_id'  => (int) $data['user_id'],
        'purpose'  => (string) ($data['purpose'] ?? 'login'),
        'redirect' => (string) ($data['redirect'] ?? ''),
        'email'    => (string) ($data['email'] ?? ''),
    ];
    set_transient('sa_otp_chal_' . $token, $payload, sa_core_otp_ttl() + 120);

    $secure = is_ssl() || (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
    // Cookie holds only the opaque token.
    setcookie('sa_otp_chal', $token, [
        'expires'  => time() + sa_core_otp_ttl() + 120,
        'path'     => COOKIEPATH ?: '/',
        'domain'   => COOKIE_DOMAIN ?: '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE['sa_otp_chal'] = $token;
}

/**
 * @return array{user_id:int,purpose:string,redirect:string,email:string}|null
 */
function sa_core_otp_get_pending(): ?array
{
    $token = isset($_COOKIE['sa_otp_chal']) ? sanitize_text_field(wp_unslash((string) $_COOKIE['sa_otp_chal'])) : '';
    if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }
    $data = get_transient('sa_otp_chal_' . $token);
    if (!is_array($data) || empty($data['user_id'])) {
        return null;
    }
    return [
        'user_id'  => (int) $data['user_id'],
        'purpose'  => (string) ($data['purpose'] ?? 'login'),
        'redirect' => (string) ($data['redirect'] ?? ''),
        'email'    => (string) ($data['email'] ?? ''),
    ];
}

function sa_core_otp_clear_pending(): void
{
    $token = isset($_COOKIE['sa_otp_chal']) ? sanitize_text_field(wp_unslash((string) $_COOKIE['sa_otp_chal'])) : '';
    if ($token !== '' && preg_match('/^[a-f0-9]{32}$/', $token)) {
        delete_transient('sa_otp_chal_' . $token);
    }
    $secure = is_ssl() || (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
    setcookie('sa_otp_chal', '', [
        'expires'  => time() - YEAR_IN_SECONDS,
        'path'     => COOKIEPATH ?: '/',
        'domain'   => COOKIE_DOMAIN ?: '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE['sa_otp_chal']);
}

function sa_core_otp_has_pending(): bool
{
    return sa_core_otp_get_pending() !== null;
}

/**
 * Resolve login/email to a WP_User (customers only for OTP path).
 */
function sa_core_otp_find_user(string $login): ?WP_User
{
    $login = trim($login);
    if ($login === '') {
        return null;
    }
    $user = get_user_by('login', $login);
    if (!$user && is_email($login) && apply_filters('woocommerce_get_username_from_email', true)) {
        $user = get_user_by('email', sanitize_email($login));
    }
    return $user instanceof WP_User ? $user : null;
}

/**
 * Create a customer account for email if needed (random password, never emailed).
 *
 * @return WP_User|WP_Error
 */
function sa_core_otp_ensure_customer(string $email, string $first_name = '')
{
    $email = sanitize_email($email);
    if (!is_email($email)) {
        return new WP_Error('sa_otp_email', __('Please enter a valid email address.', 'supreme-autoparts-core'));
    }

    $existing = get_user_by('email', $email);
    if ($existing instanceof WP_User) {
        return $existing;
    }

    if (!function_exists('wc_create_new_customer')) {
        return new WP_Error('sa_otp_woo', __('Store registration is temporarily unavailable.', 'supreme-autoparts-core'));
    }

    // Flag so our created_customer hooks skip password emails.
    $GLOBALS['sa_core_otp_creating'] = true;
    $password = sa_core_generate_customer_password();
    $customer_id = wc_create_new_customer($email, '', $password);
    $password = '';
    $GLOBALS['sa_core_otp_creating'] = false;

    if (is_wp_error($customer_id)) {
        return $customer_id;
    }

    $customer_id = (int) $customer_id;
    if ($first_name !== '') {
        $first_name = sanitize_text_field($first_name);
        update_user_meta($customer_id, 'first_name', $first_name);
        update_user_meta($customer_id, 'billing_first_name', $first_name);
        wp_update_user([
            'ID'           => $customer_id,
            'display_name' => $first_name,
        ]);
    }
    delete_user_option($customer_id, 'default_password_nag', true);

    $user = get_user_by('id', $customer_id);
    return $user instanceof WP_User ? $user : new WP_Error('sa_otp_create', __('Could not create account. Please try again.', 'supreme-autoparts-core'));
}

function sa_core_otp_default_redirect(): string
{
    if (function_exists('wc_get_page_permalink')) {
        return (string) wc_get_page_permalink('myaccount');
    }
    return home_url('/');
}

function sa_core_otp_login_and_redirect(WP_User $user, string $redirect = ''): void
{
    wp_set_current_user((int) $user->ID);
    wp_set_auth_cookie((int) $user->ID, true, is_ssl());
    if (function_exists('wc_set_customer_auth_cookie')) {
        wc_set_customer_auth_cookie((int) $user->ID);
    }
    do_action('wp_login', $user->user_login, $user);

    sa_core_otp_clear_pending();

    if ($redirect === '') {
        $redirect = sa_core_otp_default_redirect();
    }
    $redirect = wp_validate_redirect($redirect, sa_core_otp_default_redirect());
    wp_safe_redirect($redirect);
    exit;
}

function sa_core_otp_generic_sent_notice(): void
{
    wc_add_notice(
        __('If an account exists for that email, we sent a 6-digit login code. Enter it below.', 'supreme-autoparts-core'),
        'success'
    );
}

function sa_core_otp_verify_url(): string
{
    // Always land on My Account so both register + lost-password share one verify UI.
    $base = function_exists('wc_get_page_permalink')
        ? (string) wc_get_page_permalink('myaccount')
        : home_url('/');
    return add_query_arg('sa_otp', '1', $base);
}

/**
 * Safe redirect target from POST (checkout / my-account).
 */
function sa_core_otp_redirect_from_request(): string
{
    $redirect = '';
    if (isset($_POST['redirect'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $redirect = esc_url_raw(wp_unslash((string) $_POST['redirect'])); // phpcs:ignore
    }
    if ($redirect === '' && isset($_REQUEST['redirect_to'])) { // phpcs:ignore
        $redirect = esc_url_raw(wp_unslash((string) $_REQUEST['redirect_to'])); // phpcs:ignore
    }
    return $redirect !== '' ? $redirect : sa_core_otp_default_redirect();
}

/**
 * Lost password / “email me a login code”: customers get OTP; staff keep Woo reset-link.
 */
add_action('wp_loaded', static function (): void {
    if (!isset($_POST['wc_reset_password'], $_POST['user_login'])) {
        return;
    }
    if (!class_exists('WooCommerce') || !class_exists('WC_Form_Handler')) {
        return;
    }

    $nonce_value = '';
    if (isset($_REQUEST['woocommerce-lost-password-nonce'])) {
        $nonce_value = (string) wp_unslash($_REQUEST['woocommerce-lost-password-nonce']); // phpcs:ignore
    } elseif (isset($_REQUEST['_wpnonce'])) {
        $nonce_value = (string) wp_unslash($_REQUEST['_wpnonce']); // phpcs:ignore
    }
    if ($nonce_value === '' || !wp_verify_nonce($nonce_value, 'lost_password')) {
        return;
    }

    remove_action('wp_loaded', ['WC_Form_Handler', 'process_lost_password'], 20);

    $login = trim((string) wp_unslash($_POST['user_login'])); // phpcs:ignore
    if ($login === '') {
        add_action('wp_loaded', ['WC_Form_Handler', 'process_lost_password'], 20);
        return;
    }

    $user = sa_core_otp_find_user($login);

    // Enumeration-safe: always show the same success path when we cannot send OTP.
    if (!$user instanceof WP_User) {
        sa_core_otp_generic_sent_notice();
        // No pending challenge — still redirect to OTP screen with a soft message.
        wp_safe_redirect(sa_core_otp_verify_url());
        exit;
    }

    if (!sa_core_user_is_customer_for_password_mail($user)) {
        // Staff: Woo reset-link email (never OTP plaintext for privileged users).
        $success = WC_Shortcode_My_Account::retrieve_password();
        if ($success) {
            wp_safe_redirect(add_query_arg('reset-link-sent', 'true', wc_get_account_endpoint_url('lost-password')));
            exit;
        }
        return;
    }

    $issued = sa_core_otp_issue_for_user($user);
    if (is_wp_error($issued)) {
        // Cooldown / rate — show real error; mail failure too.
        if (in_array($issued->get_error_code(), ['sa_otp_cooldown', 'sa_otp_rate', 'sa_otp_mail'], true)) {
            wc_add_notice($issued->get_error_message(), 'error');
            return;
        }
        sa_core_otp_generic_sent_notice();
        wp_safe_redirect(sa_core_otp_verify_url());
        exit;
    }

    sa_core_otp_set_pending([
        'user_id'  => (int) $user->ID,
        'purpose'  => 'login',
        'redirect' => sa_core_otp_redirect_from_request(),
        'email'    => (string) $user->user_email,
    ]);
    sa_core_otp_generic_sent_notice();
    wp_safe_redirect(sa_core_otp_verify_url());
    exit;
}, 19);

/**
 * Registration → OTP login (create customer if new; existing email treated as login-code).
 */
add_action('wp_loaded', static function (): void {
    if (!isset($_POST['register'], $_POST['email'])) {
        return;
    }
    // Only own the My Account / Woo register form (has Woo nonce).
    if (empty($_POST['woocommerce-register-nonce']) && empty($_POST['_wpnonce'])) {
        return;
    }
    if (!class_exists('WooCommerce') || !class_exists('WC_Form_Handler')) {
        return;
    }

    $nonce_value = '';
    if (isset($_REQUEST['woocommerce-register-nonce'])) {
        $nonce_value = (string) wp_unslash($_REQUEST['woocommerce-register-nonce']); // phpcs:ignore
    } elseif (isset($_REQUEST['_wpnonce'])) {
        $nonce_value = (string) wp_unslash($_REQUEST['_wpnonce']); // phpcs:ignore
    }
    if ($nonce_value === '' || !wp_verify_nonce($nonce_value, 'woocommerce-register')) {
        return;
    }

    remove_action('wp_loaded', ['WC_Form_Handler', 'process_registration'], 20);

    $email = sanitize_email(wp_unslash((string) $_POST['email'])); // phpcs:ignore
    $first = isset($_POST['first_name']) ? sanitize_text_field(wp_unslash((string) $_POST['first_name'])) : ''; // phpcs:ignore

    if (!is_email($email)) {
        wc_add_notice(__('Please enter a valid email address.', 'supreme-autoparts-core'), 'error');
        return;
    }

    // Privacy / terms if Woo requires them on register.
    if ('yes' === get_option('woocommerce_registration_privacy_policy_check', 'no') && empty($_POST['privacy_policy_reg'])) { // phpcs:ignore
        wc_add_notice(__('Please read and accept the privacy policy.', 'supreme-autoparts-core'), 'error');
        return;
    }

    $existing = get_user_by('email', $email);
    if ($existing instanceof WP_User && !sa_core_user_is_customer_for_password_mail($existing)) {
        // Staff email on register form — do not OTP; generic message (no leak).
        sa_core_otp_generic_sent_notice();
        wp_safe_redirect(sa_core_otp_verify_url());
        exit;
    }

    $user = sa_core_otp_ensure_customer($email, $first);
    if (is_wp_error($user)) {
        // Duplicate username etc. — still avoid leaking; show generic if email-related.
        $code = $user->get_error_code();
        if (in_array($code, ['registration-error-email-exists', 'existing_user_email'], true)) {
            $again = get_user_by('email', $email);
            if ($again instanceof WP_User && sa_core_user_is_customer_for_password_mail($again)) {
                $user = $again;
            } else {
                sa_core_otp_generic_sent_notice();
                wp_safe_redirect(sa_core_otp_verify_url());
                exit;
            }
        } else {
            wc_add_notice($user->get_error_message(), 'error');
            return;
        }
    }

    if (!$user instanceof WP_User || !sa_core_user_is_customer_for_password_mail($user)) {
        sa_core_otp_generic_sent_notice();
        wp_safe_redirect(sa_core_otp_verify_url());
        exit;
    }

    $issued = sa_core_otp_issue_for_user($user);
    if (is_wp_error($issued)) {
        if (in_array($issued->get_error_code(), ['sa_otp_cooldown', 'sa_otp_rate', 'sa_otp_mail'], true)) {
            wc_add_notice($issued->get_error_message(), 'error');
            return;
        }
        sa_core_otp_generic_sent_notice();
        wp_safe_redirect(sa_core_otp_verify_url());
        exit;
    }

    sa_core_otp_set_pending([
        'user_id'  => (int) $user->ID,
        'purpose'  => 'register',
        'redirect' => sa_core_otp_redirect_from_request(),
        'email'    => (string) $user->user_email,
    ]);
    sa_core_otp_generic_sent_notice();
    wp_safe_redirect(sa_core_otp_verify_url());
    exit;
}, 19);

/**
 * Verify OTP + optional resend.
 */
add_action('wp_loaded', static function (): void {
    if (!isset($_POST['sa_otp_action'])) {
        return;
    }
    $action = sanitize_key((string) wp_unslash($_POST['sa_otp_action'])); // phpcs:ignore
    if (!in_array($action, ['verify', 'resend'], true)) {
        return;
    }

    $nonce = isset($_POST['sa_otp_nonce']) ? (string) wp_unslash($_POST['sa_otp_nonce']) : ''; // phpcs:ignore
    if ($nonce === '' || !wp_verify_nonce($nonce, 'sa_otp_verify')) {
        wc_add_notice(__('Something went wrong. Please try again.', 'supreme-autoparts-core'), 'error');
        return;
    }

    $pending = sa_core_otp_get_pending();
    if ($pending === null) {
        wc_add_notice(__('Your login code session expired. Request a new code.', 'supreme-autoparts-core'), 'error');
        return;
    }

    $user = get_user_by('id', (int) $pending['user_id']);
    if (!$user instanceof WP_User || !sa_core_user_is_customer_for_password_mail($user)) {
        sa_core_otp_clear_pending();
        wc_add_notice(__('Your login code session expired. Request a new code.', 'supreme-autoparts-core'), 'error');
        return;
    }

    if ($action === 'resend') {
        $issued = sa_core_otp_issue_for_user($user);
        if (is_wp_error($issued)) {
            wc_add_notice($issued->get_error_message(), 'error');
            return;
        }
        // Refresh challenge TTL.
        sa_core_otp_set_pending($pending);
        wc_add_notice(__('If an account exists, we sent a new login code.', 'supreme-autoparts-core'), 'success');
        wp_safe_redirect(sa_core_otp_verify_url());
        exit;
    }

    $code = isset($_POST['sa_otp_code']) ? (string) wp_unslash($_POST['sa_otp_code']) : ''; // phpcs:ignore
    $ok = sa_core_otp_verify((int) $user->ID, $code);
    if (is_wp_error($ok)) {
        wc_add_notice($ok->get_error_message(), 'error');
        return;
    }

    sa_core_otp_login_and_redirect($user, (string) $pending['redirect']);
}, 18);

/**
 * Skip Woo password-in-welcome when we are creating via OTP register.
 */
add_action('woocommerce_created_customer', static function (int $customer_id, $data = [], $password_generated = false): void {
    if (empty($GLOBALS['sa_core_otp_creating']) || $customer_id <= 0) {
        return;
    }
    delete_user_option($customer_id, 'default_password_nag', true);
    set_transient('sa_core_otp_welcome_' . $customer_id, '1', 15 * MINUTE_IN_SECONDS);
    unset($data, $password_generated);
}, 5, 3);

add_action('woocommerce_created_customer_notification', static function ($customer_id, $new_customer_data = [], $password_generated = false): void {
    $customer_id = (int) $customer_id;
    if ($customer_id <= 0 || !get_transient('sa_core_otp_welcome_' . $customer_id)) {
        return;
    }
    if (!function_exists('WC') || !WC()->mailer()) {
        return;
    }
    $mailer = WC()->mailer();
    remove_action('woocommerce_created_customer_notification', [$mailer, 'customer_new_account'], 10);
    $emails = $mailer->get_emails();
    if (isset($emails['WC_Email_Customer_New_Account']) && is_object($emails['WC_Email_Customer_New_Account'])) {
        // Welcome only — login code already emailed separately.
        $emails['WC_Email_Customer_New_Account']->trigger($customer_id, '', false);
    }
    delete_transient('sa_core_otp_welcome_' . $customer_id);
    unset($new_customer_data, $password_generated);
}, 1, 3);

/** Confirmation copy if Woo reset-link-sent still appears (staff path). */
add_filter('woocommerce_lost_password_confirmation_message', static function (string $message): string {
    // Customers use OTP; staff still get reset-link wording from Woo when that path is used.
    if (sa_core_otp_has_pending()) {
        return __('We emailed a 6-digit login code. Enter it on the next screen to sign in.', 'supreme-autoparts-core');
    }
    return $message;
});

add_filter('woocommerce_add_success', static function ($message) {
    if (!is_string($message)) {
        return $message;
    }
    if (stripos($message, 'password reset email') !== false || stripos($message, 'reset link') !== false) {
        return __('If an account exists, check your email for a login code or reset link.', 'supreme-autoparts-core');
    }
    if (stripos($message, 'new password') !== false && stripos($message, 'sent') !== false) {
        return __('If an account exists, we emailed a 6-digit login code.', 'supreme-autoparts-core');
    }
    return $message;
}, 20);

/**
 * Checkout / registration data: keep role=customer; random password is never emailed.
 */
add_filter('woocommerce_new_customer_data', static function (array $data): array {
    $data['role'] = 'customer';
    if (get_option('woocommerce_registration_generate_password') === 'yes') {
        $data['user_pass'] = sa_core_generate_customer_password();
    }
    return $data;
}, 5);

/**
 * Render OTP verify form (used by theme templates).
 */
function sa_core_render_otp_form(): void
{
    $pending = sa_core_otp_get_pending();
    $email_hint = '';
    if ($pending && $pending['email'] !== '') {
        $email = $pending['email'];
        $at = strpos($email, '@');
        if ($at !== false && $at > 1) {
            $email_hint = substr($email, 0, 1) . str_repeat('•', min(6, $at - 1)) . substr($email, $at);
        }
    }
    $mins = max(1, (int) round(sa_core_otp_ttl() / 60));
    ?>
    <div class="sa-account-auth sa-account-auth--otp" id="sa_otp_login">
      <section class="sa-account-auth__panel">
        <header class="sa-account-panel__head">
          <h2><?php esc_html_e('Enter login code', 'supreme-autoparts-core'); ?></h2>
          <p class="sa-account-panel__lead">
            <?php
            if ($email_hint !== '') {
                printf(
                    /* translators: 1: masked email 2: minutes */
                    esc_html__('We sent a 6-digit code to %1$s. It expires in about %2$d minutes.', 'supreme-autoparts-core'),
                    esc_html($email_hint),
                    $mins
                );
            } else {
                printf(
                    /* translators: %d: minutes */
                    esc_html__('Enter the 6-digit code we emailed you. It expires in about %d minutes.', 'supreme-autoparts-core'),
                    $mins
                );
            }
            ?>
          </p>
        </header>

        <form method="post" class="woocommerce-form sa-form sa-otp-form" novalidate>
          <?php wp_nonce_field('sa_otp_verify', 'sa_otp_nonce'); ?>
          <input type="hidden" name="sa_otp_action" value="verify" />

          <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
            <label for="sa_otp_code"><?php esc_html_e('Login code', 'supreme-autoparts-core'); ?>&nbsp;<span class="required">*</span></label>
            <input
              type="text"
              inputmode="numeric"
              pattern="[0-9]*"
              maxlength="6"
              autocomplete="one-time-code"
              class="woocommerce-Input woocommerce-Input--text input-text sa-otp-input"
              name="sa_otp_code"
              id="sa_otp_code"
              required
            />
          </p>

          <p class="woocommerce-form-row form-row">
            <button type="submit" class="woocommerce-Button button sa-btn<?php echo esc_attr(function_exists('wc_wp_theme_get_element_class_name') && wc_wp_theme_get_element_class_name('button') ? ' ' . wc_wp_theme_get_element_class_name('button') : ''); ?>">
              <?php esc_html_e('Verify and log in', 'supreme-autoparts-core'); ?>
            </button>
          </p>
        </form>

        <form method="post" class="sa-form sa-otp-resend" style="margin-top:0.75rem;">
          <?php wp_nonce_field('sa_otp_verify', 'sa_otp_nonce'); ?>
          <input type="hidden" name="sa_otp_action" value="resend" />
          <button type="submit" class="button sa-btn sa-btn--outline<?php echo esc_attr(function_exists('wc_wp_theme_get_element_class_name') && wc_wp_theme_get_element_class_name('button') ? ' ' . wc_wp_theme_get_element_class_name('button') : ''); ?>">
            <?php esc_html_e('Resend code', 'supreme-autoparts-core'); ?>
          </button>
          <a class="sa-otp-back" href="<?php echo esc_url(function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/')); ?>" style="margin-left:0.75rem;">
            <?php esc_html_e('Back to log in', 'supreme-autoparts-core'); ?>
          </a>
        </form>
      </section>
    </div>
    <?php
}

/**
 * On My Account when ?sa_otp=1 (or pending cookie), show OTP form instead of login/register.
 */
add_action('woocommerce_before_customer_login_form', static function (): void {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $want = !empty($_GET['sa_otp']) || sa_core_otp_has_pending();
    if (!$want || is_user_logged_in()) {
        return;
    }
    if (!sa_core_otp_has_pending()) {
        // Arrived with ?sa_otp=1 but no cookie — ask them to request again.
        echo '<div class="sa-account-auth sa-account-auth--otp"><section class="sa-account-auth__panel">';
        echo '<p>' . esc_html__('Request a login code with your email first.', 'supreme-autoparts-core') . '</p>';
        echo '<p><a class="sa-btn" href="' . esc_url(wp_lostpassword_url()) . '">' . esc_html__('Email me a login code', 'supreme-autoparts-core') . '</a></p>';
        echo '</section></div>';
        // Hide default login/register markup by buffering? Simpler: set a flag templates check.
        $GLOBALS['sa_core_otp_form_shown'] = true;
        return;
    }
    sa_core_render_otp_form();
    $GLOBALS['sa_core_otp_form_shown'] = true;
}, 5);

function sa_core_otp_form_shown(): bool
{
    return !empty($GLOBALS['sa_core_otp_form_shown']);
}
