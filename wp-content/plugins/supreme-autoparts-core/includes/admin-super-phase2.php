<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Super Admin Phase 2 working surfaces: Orders, Customers, Payments, Shipping,
 * Products, Integrations, Settings, Content, Vendors (Phase 4 notice).
 * Phase 3 Inventory/Discounts/Marketing/Analytics/Reviews/Admins/Notifications
 * live in admin-super-phase3.php. Also dismisses Woo setup nag + dedupes menus.
 */

/** Dismiss WooCommerce "Step X of 5" / setup task list nags (idempotent). */
function sa_core_dismiss_woo_setup_nag(): void
{
    if (get_option('sa_woo_setup_dismissed_ver') === '2') {
        return;
    }

    update_option('woocommerce_task_list_hidden', 'yes');
    update_option('woocommerce_extended_task_list_hidden', 'yes');
    update_option('woocommerce_task_list_complete', 'yes');
    update_option('woocommerce_admin_dismissed_store_setup_task_list', 'yes');
    update_option('woocommerce_admin_dismissed_marketing_overview', 'yes');
    update_option('woocommerce_admin_introductory_notice_dismissed', 'yes');
    update_option('woocommerce_show_marketplace_suggestions', 'no');

    $profile = get_option('woocommerce_onboarding_profile', []);
    if (!is_array($profile)) {
        $profile = [];
    }
    $profile['completed'] = true;
    $profile['skipped'] = true;
    $profile['is_agree_marketing'] = false;
    update_option('woocommerce_onboarding_profile', $profile);

    // WC Admin remote inbox / setup checklist
    update_option('woocommerce_remote_inbox_notifications_stored_state', []);
    set_transient('wc_admin_setup_complete', true, YEAR_IN_SECONDS);

    update_option('sa_woo_setup_dismissed_ver', '2');
}

add_action('admin_init', 'sa_core_dismiss_woo_setup_nag', 5);
add_filter('woocommerce_enable_setup_wizard', '__return_false');
add_filter('woocommerce_prevent_automatic_wizard_redirect', '__return_true');
add_filter('woocommerce_admin_features', static function ($features) {
    if (!is_array($features)) {
        return $features;
    }
    // Keep analytics; hide onboarding task list UI when possible.
    return array_values(array_diff($features, ['onboarding', 'onboarding-tasks', 'setup']));
}, 20);

/**
 * Hide legacy duplicate tool menus once Super Admin Phase 2 pages own them.
 */
add_action('admin_menu', static function (): void {
    remove_submenu_page('supreme-autoparts', 'supreme-orders');
    remove_submenu_page('supreme-autoparts', 'supreme-products');
    remove_submenu_page('supreme-autoparts', 'supreme-customers');
}, 99);

/** @return array<string,int> */
function sa_core_super_order_status_counts(): array
{
    $out = [
        'pending'    => 0,
        'on-hold'    => 0,
        'processing' => 0,
        'completed'  => 0,
        'failed'     => 0,
        'cancelled'  => 0,
        'refunded'   => 0,
    ];
    if (!function_exists('wc_orders_count') && !function_exists('wc_get_orders')) {
        return $out;
    }
    foreach (array_keys($out) as $st) {
        if (function_exists('wc_orders_count')) {
            $out[$st] = (int) wc_orders_count($st);
        } else {
            $orders = wc_get_orders(['status' => $st, 'limit' => 1, 'paginate' => true, 'return' => 'ids']);
            $out[$st] = is_array($orders) && isset($orders['total']) ? (int) $orders['total'] : 0;
        }
    }
    return $out;
}

function sa_core_super_orders_url(string $status = ''): string
{
    $base = admin_url('admin.php?page=wc-orders');
    if (!function_exists('wc_get_page_screen_id')) {
        $base = admin_url('edit.php?post_type=shop_order');
    }
    if ($status !== '') {
        return add_query_arg('status', 'wc-' . ltrim($status, 'wc-'), $base);
    }
    return $base;
}

function sa_core_super_render_orders(): void
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $counts = sa_core_super_order_status_counts();
    $recent = function_exists('wc_get_orders') ? wc_get_orders([
        'limit'   => 25,
        'orderby' => 'date',
        'order'   => 'DESC',
        'status'  => array_keys(array_filter($counts, static fn ($n) => true)),
    ]) : [];

    echo '<div class="wrap sa-ultra sa-super">';
    sa_core_super_shell_header('Orders', 'Live WooCommerce orders — fulfil, copy invoice/pay links, jump to status queues.');
    echo '<div class="sa-actions" style="margin-bottom:16px;display:flex;flex-wrap:wrap;gap:8px;">';
    echo '<a class="button button-primary" href="' . esc_url(sa_core_super_orders_url()) . '">All Woo orders</a>';
    foreach (['processing' => 'Processing', 'on-hold' => 'On hold', 'pending' => 'Pending pay', 'completed' => 'Completed', 'failed' => 'Failed'] as $st => $lab) {
        $n = (int) ($counts[$st] ?? 0);
        echo '<a class="button" href="' . esc_url(sa_core_super_orders_url($st)) . '">' . esc_html($lab) . ' (' . esc_html((string) $n) . ')</a>';
    }
    echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=sa-super-payments')) . '">Payments</a>';
    echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=sa-super-shipping')) . '">Shipping</a>';
    echo '</div>';

    echo '<div class="sa-ultra__grid" style="margin-bottom:16px;">';
    foreach (['processing' => 'Processing', 'pending' => 'Awaiting payment', 'on-hold' => 'On hold', 'completed' => 'Completed'] as $st => $lab) {
        echo '<div class="sa-panel"><p class="sa-muted" style="margin:0;">' . esc_html($lab) . '</p>';
        echo '<p style="font-size:1.75rem;margin:4px 0 0;font-weight:700;">' . esc_html((string) ($counts[$st] ?? 0)) . '</p></div>';
    }
    echo '</div>';

    echo '<div class="sa-panel"><div class="sa-panel__head"><h2 class="sa-panel__title">Recent orders</h2></div>';
    echo '<div class="sa-table-wrap"><table class="sa-table"><thead><tr>';
    echo '<th>Order</th><th>Date</th><th>Customer</th><th>Status</th><th>Total</th><th>Invoice</th><th>Pay link</th>';
    echo '</tr></thead><tbody>';
    if (!$recent) {
        echo '<tr><td colspan="7" class="sa-muted">No orders yet.</td></tr>';
    } else {
        foreach ($recent as $order) {
            if (!$order instanceof WC_Order) {
                continue;
            }
            $oid = (int) $order->get_id();
            $inv = function_exists('sa_core_invoice_url') ? sa_core_invoice_url($oid) : '';
            $pay = $order->get_checkout_payment_url();
            $name = trim($order->get_formatted_billing_full_name());
            if ($name === '') {
                $name = $order->get_billing_email() ?: '—';
            }
            $cid = (int) $order->get_customer_id();
            $linked = (string) $order->get_meta('_sa_linked_guest_account');
            echo '<tr>';
            echo '<td><a href="' . esc_url($order->get_edit_order_url()) . '">#' . esc_html($order->get_order_number()) . '</a>';
            if ($linked !== '') {
                echo '<br><span class="sa-muted" style="font-size:11px;">guest→account:' . esc_html($linked) . '</span>';
            }
            echo '</td>';
            echo '<td>' . esc_html($order->get_date_created() ? $order->get_date_created()->date_i18n('Y-m-d H:i') : '') . '</td>';
            echo '<td>' . esc_html($name);
            if ($cid > 0) {
                echo '<br><a href="' . esc_url(get_edit_user_link($cid)) . '">#' . esc_html((string) $cid) . '</a>';
            }
            echo '</td>';
            echo '<td>' . esc_html(wc_get_order_status_name($order->get_status())) . '</td>';
            echo '<td>' . wp_kses_post($order->get_formatted_order_total()) . '</td>';
            echo '<td>';
            if ($inv !== '') {
                echo '<div class="sa-copy-row"><input type="text" readonly value="' . esc_attr($inv) . '" onclick="this.select()" /><button type="button" class="button" data-sa-copy>Copy</button></div>';
            } else {
                echo '—';
            }
            echo '</td><td>';
            echo '<div class="sa-copy-row"><input type="text" readonly value="' . esc_attr($pay) . '" onclick="this.select()" /><button type="button" class="button" data-sa-copy>Copy</button></div>';
            echo '</td></tr>';
        }
    }
    echo '</tbody></table></div></div></div>';
}

function sa_core_super_render_customers(): void
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    // Reuse note-saving from customers page.
    $notice = '';
    if (isset($_POST['sa_add_customer_note']) && check_admin_referer('sa_customer_notes')) {
        $user_id = absint($_POST['sa_customer_id'] ?? 0);
        $note = sanitize_textarea_field(wp_unslash((string) ($_POST['sa_note'] ?? '')));
        if ($user_id > 0 && $note !== '' && function_exists('sa_core_ultra_customer_notes')) {
            $notes = sa_core_ultra_customer_notes();
            array_unshift($notes, [
                'user_id' => $user_id,
                'note'    => $note,
                'author'  => wp_get_current_user()->user_login ?: 'admin',
                'at'      => time(),
            ]);
            update_option('sa_ultra_customer_notes', array_slice($notes, 0, 100), false);
            $notice = 'Note saved.';
        }
    }

    $q = isset($_GET['s']) ? sanitize_text_field(wp_unslash((string) $_GET['s'])) : ''; // phpcs:ignore
    $args = [
        'role'    => 'customer',
        'number'  => 40,
        'orderby' => 'registered',
        'order'   => 'DESC',
    ];
    if ($q !== '') {
        $args['search'] = '*' . $q . '*';
        $args['search_columns'] = ['user_login', 'user_email', 'display_name'];
    }
    $customers = get_users($args);
    $guest_origin = 0;
    foreach (get_users(['role' => 'customer', 'number' => -1, 'fields' => 'ID', 'meta_key' => '_sa_guest_checkout_account', 'meta_value' => '1']) as $_) {
        $guest_origin++;
    }

    echo '<div class="wrap sa-ultra sa-super">';
    sa_core_super_shell_header('Customers', 'Woo customers CRM — guest-checkout accounts, order counts, staff notes. One Customers menu (deduped).');
    if ($notice !== '') {
        echo '<div class="sa-inline-notice sa-inline-notice--ok">' . esc_html($notice) . '</div>';
    }

    echo '<div class="sa-actions" style="margin-bottom:16px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">';
    echo '<a class="button button-primary" href="' . esc_url(admin_url('users.php?role=customer')) . '">All customers (Users)</a>';
    echo '<a class="button" href="' . esc_url(admin_url('user-new.php')) . '">Add user</a>';
    echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=account')) . '">Account settings</a>';
    echo '<span class="sa-muted">Guest-origin accounts: <strong>' . esc_html((string) $guest_origin) . '</strong></span>';
    echo '</div>';

    echo '<form method="get" style="margin-bottom:16px;" class="sa-form-grid">';
    echo '<input type="hidden" name="page" value="sa-super-customers" />';
    echo '<label>Search customers <input type="search" name="s" value="' . esc_attr($q) . '" placeholder="email or name" /></label>';
    echo '<button class="button" type="submit">Search</button>';
    echo '</form>';

    echo '<div class="sa-ultra__grid">';
    echo '<div class="sa-panel"><div class="sa-panel__head"><h2 class="sa-panel__title">Customers</h2></div>';
    echo '<div class="sa-table-wrap"><table class="sa-table"><thead><tr>';
    echo '<th>ID</th><th>Name</th><th>Email</th><th>Orders</th><th>Origin</th><th>Registered</th>';
    echo '</tr></thead><tbody>';
    if (!$customers) {
        echo '<tr><td colspan="6" class="sa-muted">No customers match.</td></tr>';
    } else {
        foreach ($customers as $user) {
            $oid_count = function_exists('wc_get_customer_order_count') ? (int) wc_get_customer_order_count($user->ID) : 0;
            $guest = get_user_meta($user->ID, '_sa_guest_checkout_account', true) ? 'guest checkout' : 'register / admin';
            echo '<tr>';
            echo '<td><a href="' . esc_url(get_edit_user_link($user->ID)) . '">' . esc_html((string) $user->ID) . '</a></td>';
            echo '<td>' . esc_html($user->display_name) . '</td>';
            echo '<td><a href="mailto:' . esc_attr($user->user_email) . '">' . esc_html($user->user_email) . '</a></td>';
            echo '<td>' . esc_html((string) $oid_count) . '</td>';
            echo '<td>' . esc_html($guest) . '</td>';
            echo '<td>' . esc_html(mysql2date('Y-m-d', $user->user_registered)) . '</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table></div></div>';

    // Staff notes panel
    $notes = function_exists('sa_core_ultra_customer_notes') ? sa_core_ultra_customer_notes() : [];
    echo '<div class="sa-panel"><h2 class="sa-panel__title">Staff notes</h2>';
    echo '<form method="post" class="sa-form-grid" style="margin:12px 0 16px;">';
    wp_nonce_field('sa_customer_notes');
    echo '<label>Customer user ID <input type="text" name="sa_customer_id" inputmode="numeric" /></label>';
    echo '<label>Note <textarea name="sa_note" rows="3" placeholder="Internal only"></textarea></label>';
    echo '<button type="submit" name="sa_add_customer_note" class="button button-primary" value="1">Save note</button>';
    echo '</form><ul class="sa-note-list">';
    if (!$notes) {
        echo '<li class="sa-muted">No notes yet.</li>';
    } else {
        foreach (array_slice($notes, 0, 12) as $row) {
            $uid = (int) ($row['user_id'] ?? 0);
            $u = $uid ? get_userdata($uid) : false;
            $who = $u ? $u->display_name : ('#' . $uid);
            $when = !empty($row['at']) ? wp_date('Y-m-d H:i', (int) $row['at']) : '';
            echo '<li><div class="sa-note-list__meta">' . esc_html($who . ' · ' . (string) ($row['author'] ?? '') . ' · ' . $when) . ' EAT</div>';
            echo '<div>' . esc_html((string) ($row['note'] ?? '')) . '</div></li>';
        }
    }
    echo '</ul></div></div></div>';
}

function sa_core_super_render_payments(): void
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $whop = null;
    if (function_exists('WC') && WC()->payment_gateways()) {
        $gateways = WC()->payment_gateways()->payment_gateways();
        $whop = $gateways['whop'] ?? null;
    }
    $whop_on = $whop && method_exists($whop, 'is_available') ? ($whop->enabled === 'yes') : false;
    $webhook = class_exists('Whop_Webhook') ? Whop_Webhook::webhook_url() : home_url('/?wc-api=whop_webhook');

    $paid = function_exists('wc_get_orders') ? wc_get_orders([
        'limit'      => 15,
        'status'     => ['processing', 'completed'],
        'orderby'    => 'date',
        'order'      => 'DESC',
    ]) : [];

    echo '<div class="wrap sa-ultra sa-super">';
    sa_core_super_shell_header('Payments', 'Whop gateway status, webhook, and recent paid orders. Guest checkout stays enabled.');
    echo '<div class="sa-ultra__grid">';
    echo '<div class="sa-panel"><h2 class="sa-panel__title">Whop Checkout</h2>';
    echo '<p>Status: <strong>' . ($whop_on ? 'Enabled' : 'Disabled / missing') . '</strong></p>';
    echo '<p class="sa-muted">Title shown at checkout: <code>' . esc_html($whop ? (string) $whop->get_title() : '—') . '</code></p>';
    echo '<p>Webhook URL:<br><code style="word-break:break-all;">' . esc_html($webhook) . '</code></p>';
    echo '<p class="sa-muted">Dynamic plan titles are truncated to ≤30 chars (<code>SA Order #…</code>).</p>';
    echo '<p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=whop')) . '">Whop settings</a> ';
    echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout')) . '">All gateways</a></p>';
    echo '</div>';

    echo '<div class="sa-panel"><h2 class="sa-panel__title">Store currency</h2>';
    echo '<p>Checkout currency: <strong>' . esc_html(function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD') . '</strong></p>';
    echo '<p class="sa-muted">Guest checkout forced on. Returning guests use email OTP on My Account.</p>';
    echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=general')) . '">General</a> ';
    echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=account')) . '">Accounts</a></p>';
    echo '</div></div>';

    echo '<div class="sa-panel" style="margin-top:16px;"><h2 class="sa-panel__title">Recent paid / processing</h2>';
    echo '<div class="sa-table-wrap"><table class="sa-table"><thead><tr><th>Order</th><th>Method</th><th>Total</th><th>Status</th><th>When</th></tr></thead><tbody>';
    if (!$paid) {
        echo '<tr><td colspan="5" class="sa-muted">No paid orders yet.</td></tr>';
    } else {
        foreach ($paid as $order) {
            if (!$order instanceof WC_Order) {
                continue;
            }
            echo '<tr>';
            echo '<td><a href="' . esc_url($order->get_edit_order_url()) . '">#' . esc_html($order->get_order_number()) . '</a></td>';
            echo '<td>' . esc_html($order->get_payment_method_title() ?: $order->get_payment_method()) . '</td>';
            echo '<td>' . wp_kses_post($order->get_formatted_order_total()) . '</td>';
            echo '<td>' . esc_html(wc_get_order_status_name($order->get_status())) . '</td>';
            echo '<td>' . esc_html($order->get_date_created() ? $order->get_date_created()->date_i18n('Y-m-d H:i') : '') . '</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table></div></div></div>';
}

function sa_core_super_render_shipping(): void
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $notice = '';
    if (isset($_POST['sa_resync_shipping']) && check_admin_referer('sa_resync_shipping')) {
        if (function_exists('sa_core_ensure_shipping_zones')) {
            delete_option('sa_shipping_zones_ver');
            sa_core_ensure_shipping_zones();
            $notice = 'Shipping zones re-synced (Kenya + Rest of World rates).';
        } else {
            $notice = 'Shipping module not loaded.';
        }
    }

    $doc = get_option('sa_shipping_rates_doc', []);
    if (!is_array($doc)) {
        $doc = [];
    }

    echo '<div class="wrap sa-ultra sa-super">';
    sa_core_super_shell_header('Shipping', 'Kenya storefront rates (USD). Totals update when address/method changes at checkout.');
    if ($notice !== '') {
        echo '<div class="sa-inline-notice sa-inline-notice--ok">' . esc_html($notice) . '</div>';
    }

    echo '<div class="sa-actions" style="margin-bottom:16px;display:flex;flex-wrap:wrap;gap:8px;">';
    echo '<a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=shipping')) . '">Woo shipping zones</a>';
    echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=shipping&section=options')) . '">Shipping options</a>';
    echo '<form method="post" style="display:inline;">';
    wp_nonce_field('sa_resync_shipping');
    echo '<button type="submit" name="sa_resync_shipping" class="button" value="1">Re-sync SA Kenya rates</button>';
    echo '</form></div>';

    echo '<div class="sa-ultra__grid">';
    echo '<div class="sa-panel"><h2 class="sa-panel__title">Configured rates (USD)</h2><ul class="sa-note-list">';
    echo '<li><strong>Nairobi Delivery</strong> — $8.00 flat</li>';
    echo '<li><strong>Upcountry Kenya</strong> — $15.00 flat</li>';
    echo '<li><strong>Free shipping</strong> — $0 when subtotal ≥ $'
        . esc_html((string) ($doc['kenya']['free_min'] ?? (function_exists('sa_core_shipping_free_min') ? sa_core_shipping_free_min() : 99)))
        . '</li>';
    echo '<li><strong>Local pickup (Nairobi)</strong> — $0.00</li>';
    echo '<li><strong>International</strong> — $25.00 flat (Rest of World)</li>';
    echo '</ul>';
    if (!empty($doc['updated'])) {
        echo '<p class="sa-muted">Last sync: ' . esc_html((string) $doc['updated']) . ' · ver '
            . esc_html((string) get_option('sa_shipping_zones_ver', '—')) . '</p>';
    }
    echo '<p class="sa-muted">Docs: <code>docs/shipping-rates.md</code></p></div>';

    echo '<div class="sa-panel"><h2 class="sa-panel__title">Live Woo zones</h2>';
    if (!class_exists('WC_Shipping_Zones')) {
        echo '<p class="sa-muted">WooCommerce shipping not available.</p>';
    } else {
        echo '<div class="sa-table-wrap"><table class="sa-table"><thead><tr><th>Zone</th><th>Methods</th></tr></thead><tbody>';
        $zones = WC_Shipping_Zones::get_zones();
        $zones[0] = [
            'zone_id' => 0,
            'zone_name' => 'Rest of the world',
            'shipping_methods' => WC_Shipping_Zones::get_zone(0)->get_shipping_methods(true),
        ];
        foreach ($zones as $z) {
            $name = (string) ($z['zone_name'] ?? 'Zone');
            $methods = $z['shipping_methods'] ?? [];
            $labels = [];
            foreach ($methods as $m) {
                if (is_object($m)) {
                    $title = method_exists($m, 'get_title') ? $m->get_title() : ($m->title ?? $m->id);
                    $en = isset($m->enabled) ? $m->enabled : 'yes';
                    $labels[] = $title . ($en === 'yes' ? '' : ' (off)');
                }
            }
            echo '<tr><td><strong>' . esc_html($name) . '</strong></td><td>'
                . esc_html($labels ? implode(' · ', $labels) : '—') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div></div></div>';
}

function sa_core_super_render_products(): void
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $published = 0;
    $counts = wp_count_posts('product');
    if ($counts && isset($counts->publish)) {
        $published = (int) $counts->publish;
    }
    $low = function_exists('wc_get_products') ? wc_get_products([
        'limit' => 10,
        'status' => 'publish',
        'orderby' => 'date',
        'order' => 'DESC',
    ]) : [];

    echo '<div class="wrap sa-ultra sa-super">';
    sa_core_super_shell_header('Products', 'Catalog overview — edit in Woo, import via Supreme tools.');
    echo '<div class="sa-actions" style="margin-bottom:16px;display:flex;flex-wrap:wrap;gap:8px;">';
    echo '<a class="button button-primary" href="' . esc_url(admin_url('edit.php?post_type=product')) . '">All products (' . esc_html((string) $published) . ')</a>';
    echo '<a class="button" href="' . esc_url(admin_url('post-new.php?post_type=product')) . '">Add product</a>';
    echo '<a class="button" href="' . esc_url(admin_url('edit-tags.php?taxonomy=product_cat&post_type=product')) . '">Categories</a>';
    echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=supreme-import')) . '">Import tools</a>';
    echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=sa-super-inventory')) . '">Inventory</a>';
    echo '</div>';
    echo '<div class="sa-panel"><h2 class="sa-panel__title">Recently published</h2>';
    echo '<div class="sa-table-wrap"><table class="sa-table"><thead><tr><th>Product</th><th>SKU</th><th>Price</th><th>Stock</th></tr></thead><tbody>';
    if (!$low) {
        echo '<tr><td colspan="4" class="sa-muted">No products.</td></tr>';
    } else {
        foreach ($low as $p) {
            if (!$p instanceof WC_Product) {
                continue;
            }
            echo '<tr>';
            echo '<td><a href="' . esc_url(get_edit_post_link($p->get_id())) . '">' . esc_html($p->get_name()) . '</a></td>';
            echo '<td>' . esc_html($p->get_sku() ?: '—') . '</td>';
            echo '<td>' . wp_kses_post($p->get_price_html()) . '</td>';
            echo '<td>' . esc_html($p->get_stock_status()) . '</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table></div></div></div>';
}

function sa_core_super_render_integrations(): void
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $brevo = function_exists('sa_brevo_is_configured') && sa_brevo_is_configured();
    $whop_key = (getenv('WHOP_API_KEY') ?: '') !== '';
    $whop_co = (getenv('WHOP_COMPANY_ID') ?: '') !== '';

    echo '<div class="wrap sa-ultra sa-super">';
    sa_core_super_shell_header('Integrations', 'Whop payments + Brevo mail — env-backed secrets stay on Railway.');
    echo '<div class="sa-ultra__grid">';
    echo '<div class="sa-panel"><h2 class="sa-panel__title">Whop</h2>';
    echo '<p>API key: <strong>' . ($whop_key ? 'set (env)' : 'missing') . '</strong></p>';
    echo '<p>Company ID: <strong>' . ($whop_co ? 'set (env)' : 'missing') . '</strong></p>';
    echo '<p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=whop')) . '">Whop gateway</a></p></div>';
    echo '<div class="sa-panel"><h2 class="sa-panel__title">Brevo</h2>';
    echo '<p>Transactional API: <strong>' . ($brevo ? 'configured' : 'not configured') . '</strong></p>';
    echo '<p class="sa-muted">Used for order mail, password mail, and OTP login codes.</p>';
    echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=sa-brevo-settings')) . '">Brevo settings</a></p></div>';
    echo '</div></div>';
}

function sa_core_super_render_settings(): void
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    echo '<div class="wrap sa-ultra sa-super">';
    sa_core_super_shell_header('Settings', 'Store identity, accounts, emails — deep links into Woo + Supreme tools.');
    echo '<div class="sa-panel"><ul class="sa-note-list">';
    $links = [
        'General (address, currency)' => admin_url('admin.php?page=wc-settings&tab=general'),
        'Accounts & privacy (guest checkout)' => admin_url('admin.php?page=wc-settings&tab=account'),
        'Emails' => admin_url('admin.php?page=wc-settings&tab=email'),
        'Products' => admin_url('admin.php?page=wc-settings&tab=products'),
        'Tax' => admin_url('admin.php?page=wc-settings&tab=tax'),
        'Supreme Import tools' => admin_url('admin.php?page=supreme-import'),
        'Store policies' => admin_url('admin.php?page=supreme-policies'),
        'Brevo mail' => admin_url('admin.php?page=sa-brevo-settings'),
    ];
    foreach ($links as $lab => $url) {
        echo '<li><a href="' . esc_url($url) . '">' . esc_html($lab) . '</a></li>';
    }
    echo '</ul>';
    echo '<p class="sa-muted">Guest checkout: forced <strong>yes</strong>. Registration password: auto-generated + emailed. Returning guests: OTP login.</p>';
    echo '</div></div>';
}

function sa_core_super_render_content(): void
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $pages = get_posts(['post_type' => 'page', 'numberposts' => 30, 'orderby' => 'title', 'order' => 'ASC']);
    echo '<div class="wrap sa-ultra sa-super">';
    sa_core_super_shell_header('Content / Website', 'Pages &amp; policies for the storefront.');
    echo '<div class="sa-actions" style="margin-bottom:16px;">';
    echo '<a class="button button-primary" href="' . esc_url(admin_url('edit.php?post_type=page')) . '">All pages</a> ';
    echo '<a class="button" href="' . esc_url(admin_url('post-new.php?post_type=page')) . '">Add page</a> ';
    echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=supreme-policies')) . '">Policies</a> ';
    echo '<a class="button" href="' . esc_url(admin_url('customize.php')) . '">Customizer</a>';
    echo '</div>';
    echo '<div class="sa-panel"><div class="sa-table-wrap"><table class="sa-table"><thead><tr><th>Title</th><th>Slug</th><th>Status</th><th></th></tr></thead><tbody>';
    foreach ($pages as $p) {
        echo '<tr><td>' . esc_html($p->post_title) . '</td><td><code>' . esc_html($p->post_name) . '</code></td>';
        echo '<td>' . esc_html($p->post_status) . '</td>';
        echo '<td><a class="button" href="' . esc_url(get_edit_post_link($p->ID)) . '">Edit</a> ';
        echo '<a class="button" href="' . esc_url(get_permalink($p)) . '" target="_blank" rel="noopener">View</a></td></tr>';
    }
    echo '</tbody></table></div></div></div>';
}

function sa_core_super_render_vendors(): void
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    echo '<div class="wrap sa-ultra sa-super">';
    sa_core_super_shell_header('Vendors', 'Multi-vendor marketplace is Phase 4 — not enabled. Catalog is single-store Supreme Autoparts.');
    echo '<div class="sa-panel"><p class="sa-muted">No vendor accounts, commissions, or KYC in this build. Product vendors in the catalog are supplier brand names on imported parts, not marketplace sellers.</p>';
    echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=sa-super-products')) . '">Products</a></p></div></div>';
}
