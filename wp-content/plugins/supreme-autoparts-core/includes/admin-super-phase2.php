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

// Orders/Customers/Payments upgraded in admin-super-ops.php

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
            $notice = 'Shipping zones re-synced (US + Kenya + Rest of World rates).';
        } else {
            $notice = 'Shipping module not loaded.';
        }
    }

    $doc = get_option('sa_shipping_rates_doc', []);
    if (!is_array($doc)) {
        $doc = [];
    }

    echo '<div class="wrap sa-ultra sa-super">';
    sa_core_super_shell_header('Shipping', 'US-primary storefront rates (USD). Totals update when address/method changes at checkout.');
    if ($notice !== '') {
        echo '<div class="sa-inline-notice sa-inline-notice--ok">' . esc_html($notice) . '</div>';
    }

    echo '<div class="sa-actions" style="margin-bottom:16px;display:flex;flex-wrap:wrap;gap:8px;">';
    echo '<a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=shipping')) . '">Woo shipping zones</a>';
    echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=shipping&section=options')) . '">Shipping options</a>';
    echo '<form method="post" style="display:inline;">';
    wp_nonce_field('sa_resync_shipping');
    echo '<button type="submit" name="sa_resync_shipping" class="button" value="1">Re-sync SA shipping rates</button>';
    echo '</form></div>';

    echo '<div class="sa-ultra__grid">';
    echo '<div class="sa-panel"><h2 class="sa-panel__title">Configured rates (USD)</h2><ul class="sa-note-list">';
    $free = esc_html((string) ($doc['united_states']['free_min'] ?? $doc['kenya']['free_min'] ?? (function_exists('sa_core_shipping_free_min') ? sa_core_shipping_free_min() : 99)));
    echo '<li><strong>Free shipping</strong> — $0 when subtotal ≥ $' . $free . '</li>';
    echo '<li><strong>Standard Shipping (Continental US)</strong> — $8.00 flat</li>';
    echo '<li><strong>Priority Shipping (US)</strong> — $15.00 flat</li>';
    echo '<li><strong>Nairobi Delivery</strong> — $8.00 flat (KE)</li>';
    echo '<li><strong>Upcountry Kenya</strong> — $15.00 flat (KE)</li>';
    echo '<li><strong>Local pickup (Nairobi)</strong> — $0.00 (KE)</li>';
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
