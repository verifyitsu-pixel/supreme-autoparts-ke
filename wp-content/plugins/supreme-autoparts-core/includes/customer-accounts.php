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

    update_option('sa_customer_caps_ver', '3');
}

add_action('init', static function (): void {
    if (get_option('sa_customer_caps_ver') === '3') {
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
        'support'         => __('Support', 'supreme-autoparts-core'),
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
    echo '<div class="sa-account-panel"><h2>' . esc_html__('Support', 'supreme-autoparts-core') . '</h2>';
    echo '<p>' . esc_html__('Need help with an order or fitment?', 'supreme-autoparts-core') . ' ';
    echo '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a></p></div>';
});

/**
 * Flush rewrite once after support endpoint added.
 */
add_action('init', static function (): void {
    if (get_option('sa_myaccount_endpoints_ver') === '3') {
        return;
    }
    flush_rewrite_rules(false);
    update_option('sa_myaccount_endpoints_ver', '3');
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
    if (!is_user_logged_in()) {
        return;
    }
    $user_id = get_current_user_id();
    if ((int) $order->get_user_id() !== $user_id && !current_user_can('manage_woocommerce')) {
        return;
    }
    if (!current_user_can('sa_view_invoices') && !current_user_can('manage_woocommerce')) {
        return;
    }
    $url = sa_core_invoice_url((int) $order->get_id());
    echo '<p class="sa-invoice-link"><a class="button" href="' . esc_url($url) . '" target="_blank" rel="noopener">'
        . esc_html__('View invoice', 'supreme-autoparts-core')
        . '</a></p>';
}, 20);

add_action('woocommerce_my_account_my_orders_actions', static function (array $actions, $order): array {
    if ($order instanceof WC_Order && (current_user_can('sa_view_invoices') || current_user_can('manage_woocommerce'))) {
        $actions['sa_invoice'] = [
            'url'  => sa_core_invoice_url((int) $order->get_id()),
            'name' => __('Invoice', 'supreme-autoparts-core'),
        ];
    }
    return $actions;
}, 20, 2);
