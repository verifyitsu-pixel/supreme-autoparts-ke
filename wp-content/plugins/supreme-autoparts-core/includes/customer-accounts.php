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
 * Email-safe random password for customers (register + reset).
 * Alphanumeric only — no & < > " ' that HTML emails mangle via esc_html (& → &amp;).
 * Avoids ambiguous 0/O/1/l/I. Never log the value.
 */
function sa_core_generate_customer_password(int $length = 18): string
{
    $length = max(16, min(32, $length));
    // No 0 O 1 l I — copy/paste and OCR-friendly.
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

/** Seconds between customer password-issue emails for the same account. */
function sa_core_password_issue_cooldown(): int
{
    return (int) apply_filters('sa_core_password_issue_cooldown', 60);
}

/** Max password-issue emails per customer account per hour. */
function sa_core_password_issue_hourly_cap(): int
{
    return (int) apply_filters('sa_core_password_issue_hourly_cap', 5);
}

/**
 * Rate-limit lost-password / issue-password for customers.
 *
 * @return true|WP_Error
 */
function sa_core_password_issue_can_send(int $user_id)
{
    $sent_at = (int) get_user_meta($user_id, '_sa_pw_issued_at', true);
    $cooldown = sa_core_password_issue_cooldown();
    if ($sent_at > 0 && (time() - $sent_at) < $cooldown) {
        $wait = $cooldown - (time() - $sent_at);
        return new WP_Error(
            'sa_pw_cooldown',
            sprintf(
                /* translators: %d: seconds */
                __('Please wait %d seconds before requesting another password email. Check your inbox and spam folder first.', 'supreme-autoparts-core'),
                max(1, $wait)
            )
        );
    }

    $hour_key = 'sa_pw_hour_' . $user_id;
    $count = (int) get_transient($hour_key);
    if ($count >= sa_core_password_issue_hourly_cap()) {
        return new WP_Error(
            'sa_pw_rate',
            __('Too many password emails requested for this account. Please try again later, or contact calvin@supremeautoparts.co.ke for help.', 'supreme-autoparts-core')
        );
    }

    return true;
}

function sa_core_password_issue_mark_sent(int $user_id): void
{
    update_user_meta($user_id, '_sa_pw_issued_at', (string) time());
    $hour_key = 'sa_pw_hour_' . $user_id;
    $count = (int) get_transient($hour_key);
    set_transient($hour_key, $count + 1, HOUR_IN_SECONDS);
}

/**
 * Email a working password to the customer via Brevo (wp_mail → sa-brevo-mail).
 * Plain text so the password is never HTML-escaped. Does not BCC. Never logs the password.
 */
function sa_core_email_customer_password(WP_User $user, string $password, string $reason = 'reset'): bool
{
    $to = (string) $user->user_email;
    if (!is_email($to)) {
        return false;
    }

    $login = (string) $user->user_login;
    $site  = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
    $account_url = function_exists('wc_get_page_permalink')
        ? (string) wc_get_page_permalink('myaccount')
        : (string) wp_login_url();

    $name = trim((string) $user->display_name);
    if ($name === '') {
        $name = $login;
    }

    $email_login = (string) $user->user_email;
    $login_hint = $email_login !== '' && is_email($email_login)
        ? $email_login
        : $login;

    if ($reason === 'new') {
        $subject = sprintf('[%s] Your account password', $site);
        $intro   = 'Thanks for creating an account on ' . $site . '. We set a secure password for you — it works right away.';
    } else {
        $subject = sprintf('[%s] Your new password', $site);
        $intro   = 'You asked us to issue a new password for your ' . $site . ' account. We set a secure password for you — it works right away.';
    }

    $lines = [
        'Hi ' . $name . ',',
        '',
        $intro,
        '',
        'Log in with:',
        'Email: ' . $login_hint,
        'Password: ' . $password,
        '',
        'Log in here: ' . $account_url,
        '',
        'After you log in, you can change this password anytime under Account details.',
        '',
        'If you did not request this, contact us right away at calvin@supremeautoparts.co.ke.',
        '',
        '— Supreme Autoparts',
        'calvin@supremeautoparts.co.ke',
    ];
    $body = implode("\n", $lines);

    $headers = [
        'Content-Type: text/plain; charset=UTF-8',
        'From: Supreme Autoparts <calvin@supremeautoparts.co.ke>',
    ];

    // wp_mail is routed through sa-brevo-mail when BREVO_API_KEY is set.
    return (bool) wp_mail($to, $subject, $body, $headers);
}

/**
 * Replace Woo lost-password flow: set a new random password and email it.
 * Admin / shop_manager keep the default Woo reset-link behaviour.
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

    // Remove core handler so we fully own the customer path.
    remove_action('wp_loaded', ['WC_Form_Handler', 'process_lost_password'], 20);

    $login = trim(sanitize_text_field(wp_unslash((string) $_POST['user_login']))); // phpcs:ignore
    if ($login === '') {
        wc_add_notice(
            __('Enter the email address (or username) for your account so we can email you a new password.', 'supreme-autoparts-core'),
            'error'
        );
        return;
    }

    $user = null;
    if (is_email($login) && apply_filters('woocommerce_get_username_from_email', true)) {
        $user = get_user_by('email', $login);
    }
    if (!$user) {
        $user = get_user_by('login', sanitize_user($login, true));
    }

    if (!$user instanceof WP_User) {
        wc_add_notice(
            __('We could not find an account with that email or username. Check the spelling, or create an account on the Log in page. Need help? calvin@supremeautoparts.co.ke', 'supreme-autoparts-core'),
            'error'
        );
        return;
    }

    // Staff: fall back to Woo's reset-link email (do not email plaintext admin passwords).
    if (!sa_core_user_is_customer_for_password_mail($user)) {
        $success = WC_Shortcode_My_Account::retrieve_password();
        if ($success) {
            wp_safe_redirect(add_query_arg('reset-link-sent', 'true', wc_get_account_endpoint_url('lost-password')));
            exit;
        }
        return;
    }

    $allow = apply_filters('allow_password_reset', true, $user->ID);
    if (!$allow || is_wp_error($allow)) {
        $msg = is_wp_error($allow)
            ? $allow->get_error_message()
            : __('Password reset is not allowed for this account. Contact calvin@supremeautoparts.co.ke for help.', 'supreme-autoparts-core');
        wc_add_notice($msg, 'error');
        return;
    }

    $can = sa_core_password_issue_can_send((int) $user->ID);
    if (is_wp_error($can)) {
        wc_add_notice($can->get_error_message(), 'error');
        return;
    }

    $password = sa_core_generate_customer_password();
    wp_set_password($password, (int) $user->ID);

    // Refresh user object after password change (invalidates sessions).
    $user = get_user_by('id', (int) $user->ID);
    if (!$user instanceof WP_User) {
        wc_add_notice(
            __('We could not update your password. Please try again, or contact calvin@supremeautoparts.co.ke.', 'supreme-autoparts-core'),
            'error'
        );
        return;
    }

    $sent = sa_core_email_customer_password($user, $password, 'reset');
    // Clear plaintext from memory as best-effort.
    $password = '';

    if (!$sent) {
        wc_add_notice(
            __('Your password was updated, but we could not send the email. Contact calvin@supremeautoparts.co.ke and we will help you log in.', 'supreme-autoparts-core'),
            'error'
        );
        return;
    }

    sa_core_password_issue_mark_sent((int) $user->ID);

    wp_safe_redirect(add_query_arg('reset-link-sent', 'true', wc_get_account_endpoint_url('lost-password')));
    exit;
}, 19);

/**
 * Friendly notice after we emailed a new password.
 */
add_action('template_redirect', static function (): void {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if (empty($_GET['password-sent'])) {
        return;
    }
    // Alias → Woo confirmation screen (hides the form).
    wp_safe_redirect(add_query_arg('reset-link-sent', 'true', wc_get_account_endpoint_url('lost-password')));
    exit;
}, 5);

/** Confirmation copy when reset-link-sent (our email-a-password flow). */
add_filter('woocommerce_lost_password_confirmation_message', static function (): string {
    return __('A new secure password has been emailed to the address on your account. It may take a few minutes — check inbox and spam. Use that password on the Log in page. After you log in, you stay signed in on this browser until you tap Log out. You can change the password under Account details.', 'supreme-autoparts-core');
});

/**
 * Register path: re-set an email-safe password and email it via our plain Brevo mailer.
 * Woo 9+/11 fires customer_new_account via deferred hook
 * woocommerce_created_customer_notification (not the Email::trigger on created_customer),
 * and that notification still carries the *pre-insert* password — which would no longer
 * match after wp_set_password. Mark the user so the notification wrapper strips it.
 * Staff reset-link flow is untouched (lost-password handler above).
 */
add_action('woocommerce_created_customer', static function (int $customer_id, $data = [], $password_generated = false): void {
    if (!$password_generated || $customer_id <= 0) {
        return;
    }

    delete_user_option($customer_id, 'default_password_nag', true);

    $user = get_user_by('id', $customer_id);
    if (!$user instanceof WP_User || !sa_core_user_is_customer_for_password_mail($user)) {
        return;
    }

    $password = sa_core_generate_customer_password();
    wp_set_password($password, $customer_id);

    $user = get_user_by('id', $customer_id);
    if (!$user instanceof WP_User) {
        return;
    }

    $sent = sa_core_email_customer_password($user, $password, 'new');
    $password = '';

    // Flag for notification wrapper (sync or deferred) — strip Woo password body.
    if ($sent) {
        set_transient('sa_core_pw_mailed_' . $customer_id, '1', 15 * MINUTE_IN_SECONDS);
        sa_core_password_issue_mark_sent($customer_id);
        // Store a one-shot success notice for the next page load (register often redirects).
        if (function_exists('wc_add_notice')) {
            wc_add_notice(
                __('Account created. We emailed you a secure password — it works right away. Check your inbox (and spam), then log in. You can change the password under Account details after you sign in.', 'supreme-autoparts-core'),
                'success'
            );
        }
    } else {
        if (function_exists('wc_add_notice')) {
            wc_add_notice(
                __('Your account was created, but we could not email your password. Use “Email me a new password” on the Lost password page, or contact calvin@supremeautoparts.co.ke.', 'supreme-autoparts-core'),
                'error'
            );
        }
    }

    unset($data);
}, 5, 3);

/**
 * Woo queues woocommerce_created_customer → *_notification with the original user_pass.
 * If we already emailed a freshly re-set password, force welcome-without-password.
 */
add_action('woocommerce_created_customer_notification', static function ($customer_id, $new_customer_data = [], $password_generated = false): void {
    $customer_id = (int) $customer_id;
    if ($customer_id <= 0 || !get_transient('sa_core_pw_mailed_' . $customer_id)) {
        return;
    }

    if (!function_exists('WC') || !WC()->mailer()) {
        return;
    }

    $mailer = WC()->mailer();
    remove_action('woocommerce_created_customer_notification', [$mailer, 'customer_new_account'], 10);

    $emails = $mailer->get_emails();
    if (isset($emails['WC_Email_Customer_New_Account']) && is_object($emails['WC_Email_Customer_New_Account'])) {
        // Welcome only — password already sent as plain text by sa_core_email_customer_password.
        $emails['WC_Email_Customer_New_Account']->trigger($customer_id, '', false);
    }

    delete_transient('sa_core_pw_mailed_' . $customer_id);
    unset($new_customer_data, $password_generated);
}, 1, 3);

/**
 * Rewrite Woo success notices that still talk about reset links / generic password emails.
 */
add_filter('woocommerce_add_success', static function ($message) {
    if (!is_string($message)) {
        return $message;
    }
    $lower = strtolower($message);
    if (str_contains($lower, 'password reset email')
        || str_contains($lower, 'reset link')
        || str_contains($lower, 'password reset')
        || (str_contains($lower, 'check your email') && str_contains($lower, 'password'))
    ) {
        return __('Check your email for a new password. It works right away — then you can change it under Account details after you log in.', 'supreme-autoparts-core');
    }
    if (str_contains($lower, 'login details have been sent')
        || str_contains($lower, 'account was created successfully')
        || (str_contains($lower, 'registered successfully') && str_contains($lower, 'email'))
    ) {
        return __('Account created. We emailed you a secure password — it works right away. Check your inbox (and spam), then log in. You can change the password under Account details after you sign in.', 'supreme-autoparts-core');
    }
    return $message;
}, 20);

/**
 * Rewrite Woo / WP login + lost-password error notices into clear, actionable copy.
 */
add_filter('woocommerce_add_error', static function ($message) {
    if (!is_string($message) || $message === '') {
        return $message;
    }
    $lower = strtolower(wp_strip_all_tags($message));
    $lost = function_exists('wp_lostpassword_url') ? wp_lostpassword_url() : '/my-account/lost-password/';

    if (str_contains($lower, 'lost your password')
        || str_contains($lower, 'incorrect username')
        || str_contains($lower, 'incorrect password')
        || str_contains($lower, 'the password you entered')
        || str_contains($lower, 'unknown email')
        || str_contains($lower, 'unknown username')
        || str_contains($lower, 'invalid username')
        || str_contains($lower, 'a user could not be found')
    ) {
        return sprintf(
            /* translators: %s: lost-password URL */
            __('That email/username or password did not work. Try again, or <a href="%s">email me a new password</a>. Need help? calvin@supremeautoparts.co.ke', 'supreme-autoparts-core'),
            esc_url($lost)
        );
    }

    if (str_contains($lower, 'enter a username') || str_contains($lower, 'username is required') || str_contains($lower, 'email address is required')) {
        return __('Enter your email address (or username) and password to log in.', 'supreme-autoparts-core');
    }

    if (str_contains($lower, 'valid email') || str_contains($lower, 'provide a valid email')) {
        return __('Please enter a valid email address. Need help? calvin@supremeautoparts.co.ke', 'supreme-autoparts-core');
    }

    if (str_contains($lower, 'account is already registered') || str_contains($lower, 'already registered')) {
        return sprintf(
            /* translators: %s: lost-password URL */
            __('An account with that email already exists. Log in, or <a href="%s">email me a new password</a> if you cannot sign in.', 'supreme-autoparts-core'),
            esc_url($lost)
        );
    }

    return $message;
}, 20);

/**
 * WP-login style errors (rare on storefront, but keep consistent).
 */
add_filter('woocommerce_process_login_errors', static function ($validation_error, $username = '', $password = '') {
    unset($username, $password);
    return $validation_error;
}, 10, 3);

/**
 * Checkout / registration: when Woo auto-generates a password, seed an email-safe one
 * before wp_insert_user. woocommerce_created_customer then re-sets + emails the final copy.
 */
add_filter('woocommerce_new_customer_data', static function (array $data): array {
    $data['role'] = 'customer';
    if (get_option('woocommerce_registration_generate_password') === 'yes') {
        $data['user_pass'] = sa_core_generate_customer_password();
    }
    return $data;
}, 5);
