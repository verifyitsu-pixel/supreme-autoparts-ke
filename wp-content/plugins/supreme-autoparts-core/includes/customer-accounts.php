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

    // Explicit customer caps — read + Woo account features only.
    $allow = [
        'read'                   => true,
        'sa_view_invoices'       => true,
        // WooCommerce customer-facing (WP may not store these; harmless if present).
        'view_order'             => true,
        'pay_for_order'          => true,
        'order_again'            => true,
        'cancel_order'           => true,
    ];
    foreach ($allow as $cap => $grant) {
        if ($grant) {
            $role->add_cap($cap);
        } else {
            $role->remove_cap($cap);
        }
    }

    // Hard deny elevated caps if somehow present.
    foreach (['manage_woocommerce', 'edit_shop_orders', 'edit_products', 'shop_manager', 'manage_options', 'edit_others_posts', 'publish_posts'] as $deny) {
        $role->remove_cap($deny);
    }

    // Admins / shop managers also get invoice cap.
    foreach (['administrator', 'shop_manager'] as $r) {
        $admin = get_role($r);
        if ($admin) {
            $admin->add_cap('sa_view_invoices');
        }
    }

    update_option('sa_customer_caps_ver', '2');
}

add_action('init', static function (): void {
    if (get_option('sa_customer_caps_ver') === '2') {
        return;
    }
    sa_core_ensure_customer_capabilities();
}, 5);

/**
 * Force new Woo / WP registrations onto customer role (never shop_manager).
 */
add_filter('woocommerce_new_customer_data', static function (array $data): array {
    $data['role'] = 'customer';
    return $data;
});

add_action('user_register', static function (int $user_id): void {
    $user = new WP_User($user_id);
    if (!$user->exists()) {
        return;
    }
    // Do not demote admins created via WP-CLI / bootstrap.
    if (user_can($user_id, 'manage_options') || user_can($user_id, 'manage_woocommerce')) {
        return;
    }
    // Strip shop_manager if a registration path assigned it.
    if (in_array('shop_manager', (array) $user->roles, true)) {
        $user->remove_role('shop_manager');
    }
    if (!in_array('customer', (array) $user->roles, true) && !in_array('administrator', (array) $user->roles, true)) {
        $user->set_role('customer');
    }
}, 5);

/**
 * Ensure My Account endpoint pages/menus exist.
 *
 * @return array<string,string>
 */
function sa_core_my_account_endpoints(): array
{
    return [
        'orders'          => 'Orders',
        'downloads'       => 'Downloads',
        'edit-address'    => 'Addresses',
        'payment-methods' => 'Payment methods',
        'edit-account'    => 'Account details',
        'customer-logout' => 'Log out',
    ];
}

add_action('init', static function (): void {
    if (!class_exists('WooCommerce')) {
        return;
    }
    // Woo registers these by default when options are set; reinforce.
    foreach (array_keys(sa_core_my_account_endpoints()) as $endpoint) {
        if ($endpoint === 'customer-logout') {
            continue;
        }
        add_rewrite_endpoint($endpoint, EP_ROOT | EP_PAGES);
    }
}, 11);

add_filter('woocommerce_account_menu_items', static function (array $items): array {
    $desired = sa_core_my_account_endpoints();
    $ordered = [];
    foreach ($desired as $key => $label) {
        if (isset($items[$key])) {
            $ordered[$key] = $items[$key];
        } elseif ($key !== 'customer-logout') {
            $ordered[$key] = $label;
        }
    }
    // Preserve dashboard first if present.
    if (isset($items['dashboard'])) {
        $ordered = ['dashboard' => $items['dashboard']] + $ordered;
    }
    if (isset($items['customer-logout'])) {
        $ordered['customer-logout'] = $items['customer-logout'];
    }
    return $ordered;
}, 20);

/**
 * Show invoice link on customer order view when they have sa_view_invoices.
 */
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
