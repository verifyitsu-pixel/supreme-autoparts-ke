<?php
/**
 * Super Admin Phase 3: deep Inventory, Discounts/coupons, Marketing (Brevo),
 * Analytics (full Woo aggregates), Reviews, Admins & Roles basics, Notifications.
 * Real Woo/Brevo data only — no fake campaign builders or sample-of-100 revenue.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Whether Woo HPOS custom orders table is in use. */
function sa_core_super_hpos_enabled(): bool
{
    if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
        && method_exists('\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled')
    ) {
        return (bool) \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }
    return false;
}

/**
 * Full revenue + order count for paid-ish statuses in a date window.
 * Uses HPOS SQL when available; otherwise postmeta aggregates (not a 100-order sample).
 *
 * @param string $from_gmt Y-m-d H:i:s UTC inclusive, or '' for all-time
 * @param string $to_gmt   Y-m-d H:i:s UTC exclusive, or '' for open end
 * @return array{revenue:float,orders:int,aov:float}
 */
function sa_core_super_revenue_stats(string $from_gmt = '', string $to_gmt = ''): array
{
    global $wpdb;
    $statuses = ["'wc-processing'", "'wc-completed'", "'wc-on-hold'"];
    $in = implode(',', $statuses);
    $revenue = 0.0;
    $orders = 0;

    if (sa_core_super_hpos_enabled()) {
        $table = $wpdb->prefix . 'wc_orders';
        $sql = "SELECT COALESCE(SUM(total_amount),0) AS rev, COUNT(*) AS n FROM {$table}
            WHERE type = 'shop_order' AND status IN ({$in})";
        $args = [];
        if ($from_gmt !== '') {
            $sql .= ' AND date_created_gmt >= %s';
            $args[] = $from_gmt;
        }
        if ($to_gmt !== '') {
            $sql .= ' AND date_created_gmt < %s';
            $args[] = $to_gmt;
        }
        $row = $args
            ? $wpdb->get_row($wpdb->prepare($sql, ...$args))
            : $wpdb->get_row($sql);
        if ($row) {
            $revenue = (float) $row->rev;
            $orders = (int) $row->n;
        }
    } else {
        $meta = $wpdb->postmeta;
        $posts = $wpdb->posts;
        $sql = "SELECT COALESCE(SUM(CAST(pm.meta_value AS DECIMAL(20,4))),0) AS rev, COUNT(DISTINCT p.ID) AS n
            FROM {$posts} p
            INNER JOIN {$meta} pm ON pm.post_id = p.ID AND pm.meta_key = '_order_total'
            WHERE p.post_type = 'shop_order' AND p.post_status IN ({$in})";
        $args = [];
        if ($from_gmt !== '') {
            $sql .= ' AND p.post_date_gmt >= %s';
            $args[] = $from_gmt;
        }
        if ($to_gmt !== '') {
            $sql .= ' AND p.post_date_gmt < %s';
            $args[] = $to_gmt;
        }
        $row = $args
            ? $wpdb->get_row($wpdb->prepare($sql, ...$args))
            : $wpdb->get_row($sql);
        if ($row) {
            $revenue = (float) $row->rev;
            $orders = (int) $row->n;
        }
    }

    return [
        'revenue' => $revenue,
        'orders'  => $orders,
        'aov'     => $orders > 0 ? ($revenue / $orders) : 0.0,
    ];
}

/**
 * Stock status counts across published products (simple + parent; variations counted separately when managing stock).
 *
 * @return array{instock:int,outofstock:int,onbackorder:int,managing:int,low:int,published:int}
 */
function sa_core_super_stock_counts(): array
{
    global $wpdb;
    $out = [
        'instock'     => 0,
        'outofstock'  => 0,
        'onbackorder' => 0,
        'managing'    => 0,
        'low'         => 0,
        'published'   => 0,
    ];
    $counts = wp_count_posts('product');
    if ($counts && isset($counts->publish)) {
        $out['published'] = (int) $counts->publish;
    }

    $rows = $wpdb->get_results(
        "SELECT pm.meta_value AS status, COUNT(*) AS n
         FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE p.post_type IN ('product','product_variation')
           AND p.post_status IN ('publish','private')
           AND pm.meta_key = '_stock_status'
         GROUP BY pm.meta_value"
    );
    if (is_array($rows)) {
        foreach ($rows as $r) {
            $st = (string) $r->status;
            if (isset($out[$st])) {
                $out[$st] = (int) $r->n;
            }
        }
    }

    $out['managing'] = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE p.post_type IN ('product','product_variation')
           AND p.post_status IN ('publish','private')
           AND pm.meta_key = '_manage_stock' AND pm.meta_value = 'yes'"
    );

    $threshold = (int) get_option('woocommerce_notify_low_stock_amount', 2);
    if ($threshold < 1) {
        $threshold = 2;
    }
    $out['low'] = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} stock
         INNER JOIN {$wpdb->posts} p ON p.ID = stock.post_id
         INNER JOIN {$wpdb->postmeta} manage ON manage.post_id = p.ID AND manage.meta_key = '_manage_stock' AND manage.meta_value = 'yes'
         INNER JOIN {$wpdb->postmeta} status ON status.post_id = p.ID AND status.meta_key = '_stock_status' AND status.meta_value = 'instock'
         WHERE p.post_type IN ('product','product_variation')
           AND p.post_status IN ('publish','private')
           AND stock.meta_key = '_stock'
           AND CAST(stock.meta_value AS SIGNED) > 0
           AND CAST(stock.meta_value AS SIGNED) <= %d",
        $threshold
    ));

    return $out;
}

/**
 * @param array{status?:string,q?:string,limit?:int} $args
 * @return list<WC_Product>
 */
function sa_core_super_inventory_products(array $args = []): array
{
    if (!function_exists('wc_get_products')) {
        return [];
    }
    $status = sanitize_key((string) ($args['status'] ?? ''));
    $q = sanitize_text_field((string) ($args['q'] ?? ''));
    $limit = max(1, min(100, (int) ($args['limit'] ?? 50)));
    $query = [
        'limit'   => $limit,
        'status'  => 'publish',
        'orderby' => 'modified',
        'order'   => 'DESC',
        'return'  => 'objects',
    ];
    if (in_array($status, ['instock', 'outofstock', 'onbackorder'], true)) {
        $query['stock_status'] = $status;
    }
    if ($status === 'low') {
        $query['manage_stock'] = true;
        $query['stock_status'] = 'instock';
        // Fetch more then filter by threshold.
        $query['limit'] = min(200, $limit * 4);
    }
    if ($q !== '') {
        $query['s'] = $q;
    }
    $products = wc_get_products($query);
    if (!is_array($products)) {
        return [];
    }
    if ($status === 'low') {
        $threshold = (int) get_option('woocommerce_notify_low_stock_amount', 2);
        if ($threshold < 1) {
            $threshold = 2;
        }
        $products = array_values(array_filter($products, static function ($p) use ($threshold): bool {
            if (!$p instanceof WC_Product || !$p->managing_stock()) {
                return false;
            }
            $qty = $p->get_stock_quantity();
            return $qty !== null && $qty > 0 && $qty <= $threshold;
        }));
        $products = array_slice($products, 0, $limit);
    }
    return $products;
}

function sa_core_super_render_inventory(): void
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $notice = '';
    $notice_type = 'ok';

    // Quick stock qty update (managed stock only).
    if (isset($_POST['sa_inv_update']) && check_admin_referer('sa_super_inventory')) {
        $pid = absint($_POST['product_id'] ?? 0);
        $qty_raw = wp_unslash((string) ($_POST['stock_qty'] ?? ''));
        $stock_status = sanitize_key((string) ($_POST['stock_status'] ?? ''));
        $product = $pid && function_exists('wc_get_product') ? wc_get_product($pid) : false;
        if (!$product instanceof WC_Product) {
            $notice = 'Product not found.';
            $notice_type = 'warn';
        } else {
            if ($qty_raw !== '' && is_numeric($qty_raw)) {
                $product->set_manage_stock(true);
                $product->set_stock_quantity((float) $qty_raw);
                if ((float) $qty_raw <= 0 && $stock_status === '') {
                    $product->set_stock_status('outofstock');
                } elseif ($stock_status === '') {
                    $product->set_stock_status('instock');
                }
            }
            if (in_array($stock_status, ['instock', 'outofstock', 'onbackorder'], true)) {
                $product->set_stock_status($stock_status);
            }
            $product->save();
            if (function_exists('sa_core_audit_log')) {
                sa_core_audit_log('inventory.stock_update', [
                    'product_id' => $pid,
                    'qty'        => $qty_raw,
                    'status'     => $stock_status ?: $product->get_stock_status(),
                ], 'notice');
            }
            $notice = 'Stock updated for #' . $pid . ' — ' . $product->get_name();
        }
    }

    $filter = isset($_GET['stock']) ? sanitize_key((string) $_GET['stock']) : ''; // phpcs:ignore
    $q = isset($_GET['s']) ? sanitize_text_field(wp_unslash((string) $_GET['s'])) : ''; // phpcs:ignore
    $counts = sa_core_super_stock_counts();
    $threshold = (int) get_option('woocommerce_notify_low_stock_amount', 2);
    $products = sa_core_super_inventory_products(['status' => $filter, 'q' => $q, 'limit' => 50]);

    echo '<div class="wrap sa-ultra sa-super">';
    sa_core_super_shell_header(
        'Inventory',
        'Live stock across the catalog. Single warehouse (storefront). Quick-update qty/status below — no fake multi-warehouse.'
    );
    if ($notice !== '') {
        echo '<div class="sa-inline-notice sa-inline-notice--' . esc_attr($notice_type) . '">' . esc_html($notice) . '</div>';
    }

    echo '<div class="sa-ultra__kpis">';
    $kpis = [
        ['Published', (string) $counts['published'], ''],
        ['In stock', (string) $counts['instock'], 'instock'],
        ['Out of stock', (string) $counts['outofstock'], 'outofstock'],
        ['On backorder', (string) $counts['onbackorder'], 'onbackorder'],
        ['Low stock (≤' . $threshold . ')', (string) $counts['low'], 'low'],
        ['Managing stock', (string) $counts['managing'], ''],
    ];
    foreach ($kpis as [$lab, $val, $link]) {
        echo '<div class="sa-kpi">';
        echo '<span class="sa-kpi__label">' . esc_html($lab) . '</span>';
        if ($link !== '') {
            echo '<a class="sa-kpi__value" href="' . esc_url(admin_url('admin.php?page=sa-super-inventory&stock=' . $link)) . '" style="text-decoration:none;color:inherit;font-size:1.5rem;font-weight:700;">' . esc_html($val) . '</a>';
        } else {
            echo '<span class="sa-kpi__value" style="font-size:1.5rem;font-weight:700;">' . esc_html($val) . '</span>';
        }
        echo '</div>';
    }
    echo '</div>';

    echo '<div class="sa-actions" style="margin-bottom:16px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">';
    echo '<a class="button button-primary" href="' . esc_url(admin_url('edit.php?post_type=product')) . '">Woo products</a>';
    echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=products&section=inventory')) . '">Woo inventory settings</a>';
    echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=sa-super-products')) . '">Products overview</a>';
    echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=sa-super-inventory')) . '">Clear filter</a>';
    echo '<span class="sa-muted">Low-stock threshold: <strong>' . esc_html((string) $threshold) . '</strong> (Woo setting)</span>';
    echo '</div>';

    echo '<form method="get" class="sa-form-grid" style="margin-bottom:16px;">';
    echo '<input type="hidden" name="page" value="sa-super-inventory" />';
    if ($filter !== '') {
        echo '<input type="hidden" name="stock" value="' . esc_attr($filter) . '" />';
    }
    echo '<label>Search SKU / name <input type="search" name="s" value="' . esc_attr($q) . '" placeholder="brake pad, SKU…" /></label>';
    echo '<label>Stock filter <select name="stock"><option value="">All (recent)</option>';
    foreach (['instock' => 'In stock', 'outofstock' => 'Out of stock', 'onbackorder' => 'On backorder', 'low' => 'Low stock'] as $k => $lab) {
        echo '<option value="' . esc_attr($k) . '"' . selected($filter, $k, false) . '>' . esc_html($lab) . '</option>';
    }
    echo '</select></label>';
    echo '<button class="button" type="submit">Filter</button>';
    echo '</form>';

    echo '<div class="sa-panel"><div class="sa-panel__head"><h2 class="sa-panel__title">Stock list';
    if ($filter !== '') {
        echo ' — ' . esc_html($filter);
    }
    echo '</h2></div>';
    echo '<div class="sa-table-wrap"><table class="sa-table"><thead><tr>';
    echo '<th>Product</th><th>SKU</th><th>Status</th><th>Qty</th><th>Manage</th><th>Quick update</th>';
    echo '</tr></thead><tbody>';
    if (!$products) {
        echo '<tr><td colspan="6" class="sa-muted">No products match this filter.</td></tr>';
    } else {
        foreach ($products as $p) {
            if (!$p instanceof WC_Product) {
                continue;
            }
            $pid = $p->get_id();
            $st = $p->get_stock_status();
            $badge = $st === 'instock' ? 'ok' : ($st === 'outofstock' ? 'bad' : 'warn');
            $qty = $p->managing_stock() ? (string) $p->get_stock_quantity() : '';
            echo '<tr>';
            echo '<td><a href="' . esc_url(get_edit_post_link($pid)) . '">' . esc_html($p->get_name()) . '</a>';
            if ($p->is_type('variation')) {
                echo '<br><span class="sa-muted" style="font-size:11px;">variation</span>';
            }
            echo '</td>';
            echo '<td><code>' . esc_html($p->get_sku() ?: '—') . '</code></td>';
            echo '<td><span class="sa-badge sa-badge--' . esc_attr($badge) . '">' . esc_html($st) . '</span></td>';
            echo '<td>' . esc_html($p->managing_stock() ? ($qty !== '' ? $qty : '0') : '—') . '</td>';
            echo '<td>' . ($p->managing_stock() ? 'yes' : 'no') . '</td>';
            echo '<td><form method="post" class="sa-inv-quick" style="display:flex;flex-wrap:wrap;gap:4px;align-items:center;">';
            wp_nonce_field('sa_super_inventory');
            echo '<input type="hidden" name="product_id" value="' . esc_attr((string) $pid) . '" />';
            echo '<input type="number" name="stock_qty" step="1" min="0" style="width:72px;" placeholder="qty" value="' . esc_attr($qty) . '" />';
            echo '<select name="stock_status" style="max-width:120px;">';
            echo '<option value="">status…</option>';
            foreach (['instock' => 'In stock', 'outofstock' => 'Out', 'onbackorder' => 'Backorder'] as $sk => $sl) {
                echo '<option value="' . esc_attr($sk) . '"' . selected($st, $sk, false) . '>' . esc_html($sl) . '</option>';
            }
            echo '</select>';
            echo '<button type="submit" name="sa_inv_update" class="button button-small" value="1">Save</button>';
            echo '</form></td></tr>';
        }
    }
    echo '</tbody></table></div>';
    echo '<p class="sa-muted" style="margin-top:12px;">Warehouses / multi-location stock = Phase 4 (single-store catalog today). Changes write straight to Woo product stock meta.</p>';
    echo '</div></div>';
}

function sa_core_super_render_discounts(): void
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $notice = '';
    $notice_type = 'ok';

    if (isset($_POST['sa_create_coupon']) && check_admin_referer('sa_super_discounts')) {
        $code = wc_format_coupon_code(sanitize_text_field(wp_unslash((string) ($_POST['coupon_code'] ?? ''))));
        $amount = wc_format_decimal(wp_unslash((string) ($_POST['coupon_amount'] ?? '0')));
        $dtype = sanitize_key((string) ($_POST['discount_type'] ?? 'percent'));
        $usage_limit = absint($_POST['usage_limit'] ?? 0);
        $expiry = sanitize_text_field(wp_unslash((string) ($_POST['expiry'] ?? '')));
        $individual = !empty($_POST['individual_use']) ? 'yes' : 'no';
        $free_ship = !empty($_POST['free_shipping']) ? 'yes' : 'no';

        if ($code === '' || !in_array($dtype, ['percent', 'fixed_cart', 'fixed_product'], true)) {
            $notice = 'Enter a coupon code and valid discount type.';
            $notice_type = 'warn';
        } elseif (function_exists('wc_get_coupon_id_by_code') && wc_get_coupon_id_by_code($code)) {
            $notice = 'That coupon code already exists.';
            $notice_type = 'warn';
        } elseif (!class_exists('WC_Coupon')) {
            $notice = 'WooCommerce coupons unavailable.';
            $notice_type = 'warn';
        } else {
            $coupon = new WC_Coupon();
            $coupon->set_code($code);
            $coupon->set_discount_type($dtype);
            $coupon->set_amount($amount);
            if ($usage_limit > 0) {
                $coupon->set_usage_limit($usage_limit);
            }
            if ($expiry !== '') {
                $coupon->set_date_expires(strtotime($expiry . ' 23:59:59'));
            }
            $coupon->set_individual_use($individual === 'yes');
            $coupon->set_free_shipping($free_ship === 'yes');
            $cid = $coupon->save();
            if ($cid) {
                if (function_exists('sa_core_audit_log')) {
                    sa_core_audit_log('discount.coupon_create', ['id' => $cid, 'code' => $code, 'amount' => $amount, 'type' => $dtype], 'notice');
                }
                $notice = 'Coupon created: ' . $code;
            } else {
                $notice = 'Could not save coupon.';
                $notice_type = 'warn';
            }
        }
    }

    $coupons = get_posts([
        'post_type'      => 'shop_coupon',
        'numberposts'    => 50,
        'post_status'    => ['publish', 'draft', 'pending', 'private'],
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);

    echo '<div class="wrap sa-ultra sa-super">';
    sa_core_super_shell_header('Discounts', 'WooCommerce coupons — create here or edit in Woo. Real discount engine (cart/product/percent).');
    if ($notice !== '') {
        echo '<div class="sa-inline-notice sa-inline-notice--' . esc_attr($notice_type) . '">' . esc_html($notice) . '</div>';
    }

    echo '<div class="sa-actions" style="margin-bottom:16px;display:flex;flex-wrap:wrap;gap:8px;">';
    echo '<a class="button button-primary" href="' . esc_url(admin_url('edit.php?post_type=shop_coupon')) . '">All Woo coupons</a>';
    echo '<a class="button" href="' . esc_url(admin_url('post-new.php?post_type=shop_coupon')) . '">Advanced coupon editor</a>';
    echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=sa-super-marketing')) . '">Marketing</a>';
    echo '</div>';

    echo '<div class="sa-ultra__grid">';
    echo '<div class="sa-panel"><h2 class="sa-panel__title">Create coupon</h2>';
    echo '<form method="post" class="sa-form-grid" style="margin-top:12px;">';
    wp_nonce_field('sa_super_discounts');
    echo '<label>Code <input type="text" name="coupon_code" required placeholder="SAVE10" style="text-transform:uppercase;" /></label>';
    echo '<label>Type <select name="discount_type">';
    echo '<option value="percent">Percentage discount</option>';
    echo '<option value="fixed_cart">Fixed cart discount</option>';
    echo '<option value="fixed_product">Fixed product discount</option>';
    echo '</select></label>';
    echo '<label>Amount <input type="number" name="coupon_amount" step="0.01" min="0" required placeholder="10" /></label>';
    echo '<label>Usage limit (0 = unlimited) <input type="number" name="usage_limit" min="0" value="0" /></label>';
    echo '<label>Expiry date <input type="date" name="expiry" /></label>';
    echo '<label style="flex-direction:row;align-items:center;gap:8px;"><input type="checkbox" name="individual_use" value="1" /> Individual use only</label>';
    echo '<label style="flex-direction:row;align-items:center;gap:8px;"><input type="checkbox" name="free_shipping" value="1" /> Allow free shipping</label>';
    echo '<button type="submit" name="sa_create_coupon" class="button button-primary" value="1">Create coupon</button>';
    echo '</form></div>';

    echo '<div class="sa-panel"><h2 class="sa-panel__title">How discounts work</h2>';
    echo '<ul class="sa-note-list">';
    echo '<li>Customers enter codes at cart/checkout (classic shortcode checkout).</li>';
    echo '<li>Percent / fixed cart / fixed product are native Woo types.</li>';
    echo '<li>Free shipping flag works with SA Kenya free-shipping min rules.</li>';
    echo '<li>Marketing campaigns that email codes live under Marketing → Brevo.</li>';
    echo '</ul></div></div>';

    echo '<div class="sa-panel" style="margin-top:16px;"><h2 class="sa-panel__title">Coupons</h2>';
    echo '<div class="sa-table-wrap"><table class="sa-table"><thead><tr>';
    echo '<th>Code</th><th>Type</th><th>Amount</th><th>Used</th><th>Limit</th><th>Expires</th><th>Status</th><th></th>';
    echo '</tr></thead><tbody>';
    if (!$coupons) {
        echo '<tr><td colspan="8" class="sa-muted">No coupons yet — create one above.</td></tr>';
    } else {
        foreach ($coupons as $c) {
            $coupon = new WC_Coupon($c->ID);
            $used = (int) $coupon->get_usage_count();
            $limit = (int) $coupon->get_usage_limit();
            $exp = $coupon->get_date_expires();
            $exp_s = $exp ? $exp->date_i18n('Y-m-d') : '—';
            echo '<tr>';
            echo '<td><code>' . esc_html($coupon->get_code()) . '</code></td>';
            echo '<td>' . esc_html($coupon->get_discount_type()) . '</td>';
            echo '<td>' . esc_html((string) $coupon->get_amount()) . '</td>';
            echo '<td>' . esc_html((string) $used) . '</td>';
            echo '<td>' . esc_html($limit > 0 ? (string) $limit : '∞') . '</td>';
            echo '<td>' . esc_html($exp_s) . '</td>';
            echo '<td>' . esc_html($c->post_status) . '</td>';
            echo '<td><a class="button" href="' . esc_url(get_edit_post_link($c->ID)) . '">Edit</a></td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table></div></div></div>';
}

function sa_core_super_render_marketing(): void
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $brevo_ok = function_exists('sa_brevo_is_configured') && sa_brevo_is_configured();
    $list_id = function_exists('sa_brevo_list_id') ? sa_brevo_list_id() : 0;
    $lists = [];
    $campaigns = [];
    $brevo_err = '';

    if ($brevo_ok && class_exists('SA_Brevo_API')) {
        $lr = SA_Brevo_API::get_lists(50);
        if (!empty($lr['ok']) && isset($lr['body']['lists']) && is_array($lr['body']['lists'])) {
            $lists = $lr['body']['lists'];
        } elseif (!empty($lr['error'])) {
            $brevo_err = (string) $lr['error'];
        }
        if (method_exists('SA_Brevo_API', 'get_email_campaigns')) {
            $cr = SA_Brevo_API::get_email_campaigns(20);
            if (!empty($cr['ok']) && isset($cr['body']['campaigns']) && is_array($cr['body']['campaigns'])) {
                $campaigns = $cr['body']['campaigns'];
            }
        }
    }

    $leads = function_exists('sa_core_ultra_leads') ? sa_core_ultra_leads() : [];
    $wa = '19174375121';
    if (function_exists('sa_enquire_contact')) {
        $digits = preg_replace('/\D+/', '', (string) (sa_enquire_contact()['whatsapp'] ?? ''));
        if (is_string($digits) && $digits !== '') {
            $wa = $digits;
        }
    }

    echo '<div class="wrap sa-ultra sa-super">';
    sa_core_super_shell_header(
        'Marketing',
        'Brevo contact lists + campaign snapshot (API), enquire leads, WhatsApp +1 917 437 5121. No fake in-WP campaign builder.'
    );

    echo '<div class="sa-actions" style="margin-bottom:16px;display:flex;flex-wrap:wrap;gap:8px;">';
    echo '<a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=sa-brevo-settings')) . '">Brevo settings</a>';
    echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=supreme-leads')) . '">Enquire leads</a>';
    echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=sa-super-discounts')) . '">Coupons</a>';
    echo '<a class="button" href="https://wa.me/' . esc_attr($wa) . '" target="_blank" rel="noopener">WhatsApp</a>';
    echo '<a class="button" href="https://app.brevo.com/" target="_blank" rel="noopener">Brevo dashboard</a>';
    echo '</div>';

    echo '<div class="sa-ultra__grid">';
    echo '<div class="sa-panel"><h2 class="sa-panel__title">Brevo connection</h2>';
    echo '<p>API: <strong>' . ($brevo_ok ? 'configured' : 'not configured') . '</strong></p>';
    echo '<p>Default list ID: <code>' . esc_html($list_id > 0 ? (string) $list_id : '—') . '</code></p>';
    echo '<p class="sa-muted">Sender: ' . esc_html(function_exists('sa_brevo_sender_name') ? sa_brevo_sender_name() : 'Supreme Autoparts')
        . ' &lt;' . esc_html(function_exists('sa_brevo_sender_email') ? sa_brevo_sender_email() : '') . '&gt;</p>';
    if ($brevo_err !== '') {
        echo '<p class="sa-muted">Lists fetch: ' . esc_html($brevo_err) . '</p>';
    }
    echo '</div>';

    echo '<div class="sa-panel"><h2 class="sa-panel__title">Channels</h2><ul class="sa-note-list">';
    echo '<li>Transactional: orders, passwords, OTP login (Brevo API)</li>';
    echo '<li>Contact sync: customers → Brevo list when configured</li>';
    echo '<li>Enquire: WhatsApp / email / SMS → log in Enquire leads</li>';
    echo '<li>Logged enquire leads: <strong>' . esc_html((string) count($leads)) . '</strong></li>';
    echo '</ul></div></div>';

    echo '<div class="sa-ultra__grid" style="margin-top:16px;">';
    echo '<div class="sa-panel"><h2 class="sa-panel__title">Brevo lists</h2>';
    if (!$brevo_ok) {
        echo '<p class="sa-muted">Set BREVO_API_KEY on Railway to load lists here.</p>';
    } elseif (!$lists) {
        echo '<p class="sa-muted">No lists returned (empty account or API error).</p>';
    } else {
        echo '<div class="sa-table-wrap"><table class="sa-table"><thead><tr><th>ID</th><th>Name</th><th>Subscribers</th><th>Folder</th></tr></thead><tbody>';
        foreach ($lists as $L) {
            if (!is_array($L)) {
                continue;
            }
            $id = (int) ($L['id'] ?? 0);
            $name = (string) ($L['name'] ?? '');
            $subs = (int) ($L['uniqueSubscribers'] ?? $L['totalSubscribers'] ?? 0);
            $folder = (string) ($L['folderId'] ?? '—');
            $hl = ($list_id > 0 && $id === $list_id) ? ' style="background:#fff8e8;"' : '';
            echo '<tr' . $hl . '><td><code>' . esc_html((string) $id) . '</code></td>';
            echo '<td>' . esc_html($name) . ($id === $list_id ? ' <span class="sa-badge sa-badge--ok">default</span>' : '') . '</td>';
            echo '<td>' . esc_html((string) $subs) . '</td>';
            echo '<td>' . esc_html($folder) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';

    echo '<div class="sa-panel"><h2 class="sa-panel__title">Email campaigns (Brevo)</h2>';
    if (!$brevo_ok) {
        echo '<p class="sa-muted">Configure Brevo to preview campaigns.</p>';
    } elseif (!$campaigns) {
        echo '<p class="sa-muted">No campaigns in Brevo yet — create/send in the Brevo dashboard. This panel only lists them (honest; we do not fake a builder).</p>';
    } else {
        echo '<div class="sa-table-wrap"><table class="sa-table"><thead><tr><th>Name</th><th>Status</th><th>Subject</th><th>Sent</th></tr></thead><tbody>';
        foreach ($campaigns as $camp) {
            if (!is_array($camp)) {
                continue;
            }
            echo '<tr><td>' . esc_html((string) ($camp['name'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($camp['status'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($camp['subject'] ?? '—')) . '</td>';
            $stats = is_array($camp['statistics'] ?? null) ? $camp['statistics'] : [];
            $sent = $stats['globalStats']['sent'] ?? $stats['sent'] ?? '—';
            echo '<td>' . esc_html(is_scalar($sent) ? (string) $sent : '—') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div></div>';

    echo '<div class="sa-panel" style="margin-top:16px;"><h2 class="sa-panel__title">Recent enquire leads</h2>';
    echo '<ul class="sa-note-list">';
    if (!$leads) {
        echo '<li class="sa-muted">None logged — <a href="' . esc_url(admin_url('admin.php?page=supreme-leads')) . '">open Enquire leads</a>.</li>';
    } else {
        foreach (array_slice($leads, 0, 8) as $row) {
            echo '<li><div class="sa-note-list__meta">' . esc_html((string) ($row['channel'] ?? ''))
                . ' · ' . esc_html(wp_date('Y-m-d H:i', (int) ($row['at'] ?? 0))) . ' EAT</div>';
            echo '<div><strong>' . esc_html((string) ($row['product'] ?: '—')) . '</strong>';
            if (!empty($row['contact'])) {
                echo ' · ' . esc_html((string) $row['contact']);
            }
            echo '</div></li>';
        }
    }
    echo '</ul></div></div>';
}

function sa_core_super_render_analytics(): void
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $range = isset($_GET['range']) ? sanitize_key((string) $_GET['range']) : '30d'; // phpcs:ignore
    if (!in_array($range, ['7d', '30d', '90d', 'all'], true)) {
        $range = '30d';
    }

    $tz = wp_timezone();
    $now = new DateTimeImmutable('now', $tz);
    $from_local = null;
    if ($range === '7d') {
        $from_local = $now->modify('-7 days')->setTime(0, 0, 0);
    } elseif ($range === '30d') {
        $from_local = $now->modify('-30 days')->setTime(0, 0, 0);
    } elseif ($range === '90d') {
        $from_local = $now->modify('-90 days')->setTime(0, 0, 0);
    }
    $from_gmt = $from_local ? $from_local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') : '';
    $to_gmt = '';

    $stats = sa_core_super_revenue_stats($from_gmt, $to_gmt);
    $all_time = sa_core_super_revenue_stats('', '');
    $counts = sa_core_super_order_status_counts();
    $stock = sa_core_super_stock_counts();

    // Customers (role count — not capped at 100).
    $customer_q = new WP_User_Query(['role' => 'customer', 'fields' => 'ID', 'number' => 1, 'count_total' => true]);
    $customer_n = (int) $customer_q->get_total();

    // Top products by order line items in range (best-effort via recent paid orders, capped reasonably for PHP).
    $top = [];
    if (function_exists('wc_get_orders')) {
        $paid_args = [
            'status'  => ['processing', 'completed', 'on-hold'],
            'limit'   => 500,
            'orderby' => 'date',
            'order'   => 'DESC',
            'return'  => 'objects',
        ];
        if ($from_gmt !== '') {
            $paid_args['date_created'] = '>=' . $from_gmt;
        }
        $paid = wc_get_orders($paid_args);
        if (is_array($paid)) {
            foreach ($paid as $order) {
                if (!$order instanceof WC_Order) {
                    continue;
                }
                foreach ($order->get_items() as $item) {
                    if (!$item instanceof WC_Order_Item_Product) {
                        continue;
                    }
                    $pid = (int) $item->get_product_id();
                    if ($pid <= 0) {
                        continue;
                    }
                    if (!isset($top[$pid])) {
                        $top[$pid] = ['name' => $item->get_name(), 'qty' => 0, 'revenue' => 0.0];
                    }
                    $top[$pid]['qty'] += (int) $item->get_quantity();
                    $top[$pid]['revenue'] += (float) $item->get_total();
                }
            }
        }
        uasort($top, static fn ($a, $b) => $b['qty'] <=> $a['qty']);
        $top = array_slice($top, 0, 10, true);
    }

    $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD';
    $range_labels = ['7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days', 'all' => 'All time'];

    echo '<div class="wrap sa-ultra sa-super">';
    sa_core_super_shell_header(
        'Analytics',
        'Full Woo aggregates (HPOS/SQL) — not a sample of 100. Range: ' . ($range_labels[$range] ?? $range) . '.'
    );

    echo '<div class="sa-actions" style="margin-bottom:16px;display:flex;flex-wrap:wrap;gap:8px;">';
    foreach ($range_labels as $k => $lab) {
        $cls = $k === $range ? 'button button-primary' : 'button';
        echo '<a class="' . esc_attr($cls) . '" href="' . esc_url(admin_url('admin.php?page=sa-super-analytics&range=' . $k)) . '">' . esc_html($lab) . '</a>';
    }
    echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=wc-admin&path=/analytics/overview')) . '">Woo Analytics</a>';
    echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=sa-super-orders')) . '">Orders</a>';
    echo '</div>';

    echo '<div class="sa-ultra__kpis">';
    $cards = [
        ['Revenue (' . $range . ')', $currency . ' ' . number_format($stats['revenue'], 2)],
        ['Orders (' . $range . ')', (string) $stats['orders']],
        ['AOV (' . $range . ')', $currency . ' ' . number_format($stats['aov'], 2)],
        ['All-time revenue', $currency . ' ' . number_format($all_time['revenue'], 2)],
        ['Customers', (string) $customer_n],
        ['Products published', (string) $stock['published']],
    ];
    foreach ($cards as [$lab, $val]) {
        echo '<div class="sa-kpi"><span class="sa-kpi__label">' . esc_html($lab) . '</span>';
        echo '<span class="sa-kpi__value" style="font-size:1.35rem;font-weight:700;">' . esc_html($val) . '</span></div>';
    }
    echo '</div>';

    echo '<div class="sa-ultra__grid">';
    echo '<div class="sa-panel"><h2 class="sa-panel__title">Order status (all-time counts)</h2>';
    echo '<div class="sa-table-wrap"><table class="sa-table"><thead><tr><th>Status</th><th>Count</th></tr></thead><tbody>';
    foreach ($counts as $st => $n) {
        echo '<tr><td>' . esc_html(function_exists('wc_get_order_status_name') ? wc_get_order_status_name($st) : $st) . '</td>';
        echo '<td><a href="' . esc_url(sa_core_super_orders_url($st)) . '">' . esc_html((string) $n) . '</a></td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<p class="sa-muted" style="margin-top:8px;">Storage: ' . (sa_core_super_hpos_enabled() ? 'HPOS (wc_orders)' : 'legacy posts') . '</p>';
    echo '</div>';

    echo '<div class="sa-panel"><h2 class="sa-panel__title">Top products by qty (' . esc_html($range) . ')</h2>';
    echo '<div class="sa-table-wrap"><table class="sa-table"><thead><tr><th>Product</th><th>Qty</th><th>Line total</th></tr></thead><tbody>';
    if (!$top) {
        echo '<tr><td colspan="3" class="sa-muted">No paid order line items in this range.</td></tr>';
    } else {
        foreach ($top as $pid => $row) {
            echo '<tr><td><a href="' . esc_url(get_edit_post_link((int) $pid)) . '">' . esc_html((string) $row['name']) . '</a></td>';
            echo '<td>' . esc_html((string) $row['qty']) . '</td>';
            echo '<td>' . esc_html($currency . ' ' . number_format((float) $row['revenue'], 2)) . '</td></tr>';
        }
    }
    echo '</tbody></table></div>';
    echo '<p class="sa-muted">Top products scan up to 500 recent paid orders in-range (qty sort). Revenue KPIs above are full SQL totals.</p>';
    echo '</div></div>';

    echo '<div class="sa-panel" style="margin-top:16px;"><h2 class="sa-panel__title">Catalog pulse</h2>';
    echo '<p>In stock <strong>' . esc_html((string) $stock['instock']) . '</strong> · Out <strong>'
        . esc_html((string) $stock['outofstock']) . '</strong> · Low <strong>'
        . esc_html((string) $stock['low']) . '</strong> · '
        . '<a href="' . esc_url(admin_url('admin.php?page=sa-super-inventory')) . '">Inventory</a></p>';
    echo '</div></div>';
}

function sa_core_super_render_reviews(): void
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $comments = get_comments([
        'post_type' => 'product',
        'status'    => 'all',
        'number'    => 40,
        'orderby'   => 'comment_date_gmt',
        'order'     => 'DESC',
    ]);

    $approved = (int) get_comments(['post_type' => 'product', 'status' => 'approve', 'count' => true]);
    $pending = (int) get_comments(['post_type' => 'product', 'status' => 'hold', 'count' => true]);

    echo '<div class="wrap sa-ultra sa-super">';
    sa_core_super_shell_header('Reviews', 'Product reviews are Woo/WP comments on products — moderate for real.');
    echo '<div class="sa-actions" style="margin-bottom:16px;display:flex;flex-wrap:wrap;gap:8px;">';
    echo '<a class="button button-primary" href="' . esc_url(admin_url('edit-comments.php')) . '">All comments</a>';
    echo '<a class="button" href="' . esc_url(admin_url('edit-comments.php?comment_status=moderated')) . '">Pending</a>';
    echo '<span class="sa-muted">Approved: <strong>' . esc_html((string) $approved) . '</strong> · Pending: <strong>'
        . esc_html((string) $pending) . '</strong></span>';
    echo '</div>';

    echo '<div class="sa-panel"><div class="sa-table-wrap"><table class="sa-table"><thead><tr>';
    echo '<th>When</th><th>Product</th><th>Author</th><th>Rating</th><th>Status</th><th>Excerpt</th><th></th>';
    echo '</tr></thead><tbody>';
    if (!$comments) {
        echo '<tr><td colspan="7" class="sa-muted">No product reviews yet.</td></tr>';
    } else {
        foreach ($comments as $c) {
            $rating = get_comment_meta((int) $c->comment_ID, 'rating', true);
            $excerpt = wp_trim_words(wp_strip_all_tags((string) $c->comment_content), 16);
            echo '<tr>';
            echo '<td>' . esc_html(mysql2date('Y-m-d H:i', $c->comment_date)) . '</td>';
            echo '<td><a href="' . esc_url(get_edit_post_link((int) $c->comment_post_ID)) . '">'
                . esc_html(get_the_title((int) $c->comment_post_ID) ?: ('#' . $c->comment_post_ID)) . '</a></td>';
            echo '<td>' . esc_html($c->comment_author) . '</td>';
            echo '<td>' . esc_html($rating !== '' && $rating !== false ? ((string) $rating . '/5') : '—') . '</td>';
            echo '<td>' . esc_html((string) $c->comment_approved) . '</td>';
            echo '<td class="sa-muted">' . esc_html($excerpt) . '</td>';
            echo '<td><a class="button" href="' . esc_url(admin_url('comment.php?action=editcomment&c=' . (int) $c->comment_ID)) . '">Moderate</a></td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table></div></div></div>';
}

function sa_core_super_render_admins(): void
{
    if (!current_user_can('manage_options')) {
        echo '<div class="wrap"><p>Administrator capability required.</p></div>';
        return;
    }

    $notice = '';
    // Soft role education only — no dangerous role mutation UI beyond WP Users.
    $admins = get_users(['role__in' => ['administrator', 'shop_manager', 'editor'], 'number' => 80, 'orderby' => 'login']);

    $role_blurb = [
        'administrator' => 'Full WP + Woo + Super Admin (including Admins & Roles).',
        'shop_manager'  => 'WooCommerce + Super Admin ops pages (not users.php manage_options).',
        'editor'        => 'Content only — no Woo manage_woocommerce by default.',
        'customer'      => 'Storefront account only — never grant shop_manager.',
    ];

    echo '<div class="wrap sa-ultra sa-super">';
    sa_core_super_shell_header('Admins & Roles', 'Staff accounts and capability basics. Create/edit users in WP Users — customers stay customers.');
    if ($notice !== '') {
        echo '<div class="sa-inline-notice sa-inline-notice--ok">' . esc_html($notice) . '</div>';
    }

    echo '<div class="sa-actions" style="margin-bottom:16px;display:flex;flex-wrap:wrap;gap:8px;">';
    echo '<a class="button button-primary" href="' . esc_url(admin_url('users.php')) . '">All users</a>';
    echo '<a class="button" href="' . esc_url(admin_url('user-new.php')) . '">Add user</a>';
    echo '<a class="button" href="' . esc_url(admin_url('users.php?role=shop_manager')) . '">Shop managers</a>';
    echo '<a class="button" href="' . esc_url(admin_url('users.php?role=customer')) . '">Customers</a>';
    echo '</div>';

    echo '<div class="sa-ultra__grid">';
    echo '<div class="sa-panel"><h2 class="sa-panel__title">Role guide</h2><ul class="sa-note-list">';
    foreach ($role_blurb as $role => $desc) {
        echo '<li><strong>' . esc_html($role) . '</strong> — ' . esc_html($desc) . '</li>';
    }
    echo '</ul>';
    echo '<p class="sa-muted">Custom capability matrix / SSO = later. Phase 3 ships role visibility + safe deep links only.</p></div>';

    echo '<div class="sa-panel"><h2 class="sa-panel__title">Key caps (this site)</h2><ul class="sa-note-list">';
    echo '<li><code>manage_woocommerce</code> — Super Admin ops (orders, inventory, …)</li>';
    echo '<li><code>manage_options</code> — Admins & Roles page + WP settings</li>';
    echo '<li><code>edit_products</code> / <code>publish_products</code> — catalog</li>';
    echo '<li>Guest checkout stays on; OTP login for returning guests</li>';
    echo '</ul></div></div>';

    echo '<div class="sa-panel" style="margin-top:16px;"><h2 class="sa-panel__title">Staff</h2>';
    echo '<div class="sa-table-wrap"><table class="sa-table"><thead><tr><th>Login</th><th>Name</th><th>Email</th><th>Roles</th><th>Registered</th><th></th></tr></thead><tbody>';
    if (!$admins) {
        echo '<tr><td colspan="6" class="sa-muted">No staff users found.</td></tr>';
    } else {
        foreach ($admins as $u) {
            echo '<tr>';
            echo '<td><a href="' . esc_url(get_edit_user_link($u->ID)) . '">' . esc_html($u->user_login) . '</a></td>';
            echo '<td>' . esc_html($u->display_name) . '</td>';
            echo '<td>' . esc_html($u->user_email) . '</td>';
            echo '<td>' . esc_html(implode(', ', $u->roles)) . '</td>';
            echo '<td>' . esc_html(mysql2date('Y-m-d', $u->user_registered)) . '</td>';
            echo '<td><a class="button" href="' . esc_url(get_edit_user_link($u->ID)) . '">Edit</a></td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table></div></div></div>';
}

function sa_core_super_render_notifications(): void
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $brevo_ok = function_exists('sa_brevo_is_configured') && sa_brevo_is_configured();
    $store = function_exists('sa_core_store_email') ? sa_core_store_email() : 'calvin@supremeautoparts.co.ke';

    $mail_map = [];
    if (function_exists('WC') && WC()->mailer()) {
        foreach (WC()->mailer()->get_emails() as $email) {
            if (!is_object($email)) {
                continue;
            }
            $mail_map[] = [
                'id'      => (string) ($email->id ?? ''),
                'title'   => (string) ($email->get_title()),
                'enabled' => method_exists($email, 'is_enabled') ? (bool) $email->is_enabled() : (($email->enabled ?? '') === 'yes'),
                'recipient' => method_exists($email, 'get_recipient') ? (string) $email->get_recipient() : '',
            ];
        }
    }

    echo '<div class="wrap sa-ultra sa-super">';
    sa_core_super_shell_header(
        'Notifications',
        'Transactional email is live via Brevo. In-app / push notifications are not built — honest Phase 3 scope.'
    );

    echo '<div class="sa-actions" style="margin-bottom:16px;display:flex;flex-wrap:wrap;gap:8px;">';
    echo '<a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=email')) . '">Woo emails</a>';
    echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=sa-brevo-settings')) . '">Brevo</a>';
    echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=sa-super-marketing')) . '">Marketing</a>';
    echo '</div>';

    echo '<div class="sa-ultra__grid">';
    echo '<div class="sa-panel"><h2 class="sa-panel__title">Routing</h2><ul class="sa-note-list">';
    echo '<li>Brevo API: <strong>' . ($brevo_ok ? 'on' : 'off') . '</strong></li>';
    echo '<li>Store owner: <code>' . esc_html($store) . '</code></li>';
    echo '<li>Customer OTP + order mail → customer only (no admin BCC)</li>';
    echo '<li>New order admin mail → Woo “New order” recipient</li>';
    echo '<li>Subjects use prefix <code>Supreme Autoparts ·</code> (no square brackets)</li>';
    echo '</ul></div>';

    echo '<div class="sa-panel"><h2 class="sa-panel__title">Not in this build</h2>';
    echo '<p class="sa-muted">SMS blast, browser push, Slack alerts, and in-admin toast feeds are Phase 4+ / external. Use Brevo + email + WhatsApp enquire for outreach.</p></div>';
    echo '</div>';

    echo '<div class="sa-panel" style="margin-top:16px;"><h2 class="sa-panel__title">Woo email templates</h2>';
    echo '<div class="sa-table-wrap"><table class="sa-table"><thead><tr><th>Email</th><th>ID</th><th>Enabled</th><th>Recipient</th></tr></thead><tbody>';
    if (!$mail_map) {
        echo '<tr><td colspan="4" class="sa-muted">Woo mailer not available.</td></tr>';
    } else {
        foreach ($mail_map as $row) {
            echo '<tr><td>' . esc_html($row['title']) . '</td>';
            echo '<td><code>' . esc_html($row['id']) . '</code></td>';
            echo '<td>' . ($row['enabled'] ? '<span class="sa-badge sa-badge--ok">on</span>' : '<span class="sa-badge sa-badge--warn">off</span>') . '</td>';
            echo '<td class="sa-muted">' . esc_html($row['recipient'] !== '' ? $row['recipient'] : '—') . '</td></tr>';
        }
    }
    echo '</tbody></table></div></div></div>';
}
