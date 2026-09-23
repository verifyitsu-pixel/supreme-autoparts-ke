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

add_filter('woocommerce_new_customer_data', static function (array $data): array {
    $data['role'] = 'customer';
    return $data;
});

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
 * Strong random password for customers (register + reset). Never log the value.
 */
function sa_core_generate_customer_password(): string
{
    return wp_generate_password(16, true, true);
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

/**
 * Email a working password to the customer via Brevo (wp_mail → sa-brevo-mail).
 * Does not BCC. Never logs the password.
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

    if ($reason === 'new') {
        $subject = sprintf('[%s] Your account password', $site);
        $intro   = 'Thanks for creating an account. We generated a secure password for you — it works right away.';
    } else {
        $subject = sprintf('[%s] Your new password', $site);
        $intro   = 'You requested a password reset. We generated a new secure password for you — it works right away.';
    }

    $lines = [
        'Hi ' . $login . ',',
        '',
        $intro,
        '',
        'Username (email): ' . $login,
        'Password: ' . $password,
        '',
        'Log in here: ' . $account_url,
        '',
        'You can change this password anytime after logging in under Account details.',
        '',
        'If you did not request this, contact us at calvin@supremeautoparts.co.ke.',
        '',
        '— Supreme Autoparts',
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

    $login = sanitize_user(wp_unslash((string) $_POST['user_login'])); // phpcs:ignore
    if ($login === '') {
        // Allow empty to fall through to a notice via a soft re-add.
        add_action('wp_loaded', ['WC_Form_Handler', 'process_lost_password'], 20);
        return;
    }

    $user = get_user_by('login', $login);
    if (!$user && is_email($login) && apply_filters('woocommerce_get_username_from_email', true)) {
        $user = get_user_by('email', $login);
    }

    if (!$user instanceof WP_User) {
        wc_add_notice(__('Invalid username or email.', 'supreme-autoparts-core'), 'error');
        return;
    }

    // Staff: fall back to Woo's reset-link email (do not email plaintext admin passwords).
    // Core handler already removed — call retrieve_password once (do not re-add the action).
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
        $msg = is_wp_error($allow) ? $allow->get_error_message() : __('Password reset is not allowed for this user', 'supreme-autoparts-core');
        wc_add_notice($msg, 'error');
        return;
    }

    $password = sa_core_generate_customer_password();
    wp_set_password($password, (int) $user->ID);

    // Refresh user object after password change (invalidates sessions).
    $user = get_user_by('id', (int) $user->ID);
    if (!$user instanceof WP_User) {
        wc_add_notice(__('Could not update password. Please try again.', 'supreme-autoparts-core'), 'error');
        return;
    }

    $sent = sa_core_email_customer_password($user, $password, 'reset');
    // Clear plaintext from memory as best-effort.
    $password = '';

    if (!$sent) {
        wc_add_notice(
            __('Your password was reset but the email could not be sent. Please contact support at calvin@supremeautoparts.co.ke.', 'supreme-autoparts-core'),
            'error'
        );
        return;
    }

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
    return __('A new secure password has been sent to the email address on file for your account. It may take a few minutes to arrive. Use that password to log in, then you can change it under Account details.', 'supreme-autoparts-core');
});

/**
 * Generated passwords are working logins (not temporary set-link passwords).
 * Clear Woo's "temporary password / emailed a link" nag after account creation.
 */
add_action('woocommerce_created_customer', static function (int $customer_id, $data = [], $password_generated = false): void {
    if ($password_generated) {
        delete_user_option($customer_id, 'default_password_nag', true);
    }
    unset($data);
}, 20, 3);

/**
 * Also rewrite Woo's default "reset link sent" copy if it still appears.
 */
add_filter('woocommerce_add_success', static function ($message) {
    if (!is_string($message)) {
        return $message;
    }
    if (stripos($message, 'password reset email') !== false || stripos($message, 'reset link') !== false) {
        return __('Check your email for a new password. It works right away — then you can change it under Account details.', 'supreme-autoparts-core');
    }
    return $message;
}, 20);

/**
 * Checkout / registration: when Woo generates a password, force length >= 16 with special chars
 * before the user is inserted, so the new-account email contains a strong working password.
 */
add_filter('woocommerce_new_customer_data', static function (array $data): array {
    $data['role'] = 'customer';
    if (get_option('woocommerce_registration_generate_password') === 'yes') {
        // Always replace with our strong password when auto-gen is on (register + checkout account creation).
        $data['user_pass'] = sa_core_generate_customer_password();
    }
    return $data;
}, 5);
