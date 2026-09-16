<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Supreme Autoparts "Ultra" admin dashboard under wp-admin.
 */
add_action('admin_menu', static function (): void {
    add_menu_page(
        'Supreme Autoparts',
        'Supreme Autoparts',
        'manage_woocommerce',
        'supreme-autoparts',
        'sa_core_render_ultra_dashboard',
        'dashicons-car',
        56
    );

    add_submenu_page(
        'supreme-autoparts',
        'Dashboard',
        'Dashboard',
        'manage_woocommerce',
        'supreme-autoparts',
        'sa_core_render_ultra_dashboard'
    );

    add_submenu_page(
        'supreme-autoparts',
        'Import tools',
        'Import tools',
        'manage_woocommerce',
        'supreme-import',
        'sa_core_render_import_page'
    );

    add_submenu_page(
        'supreme-autoparts',
        'Policies',
        'Policies',
        'manage_woocommerce',
        'supreme-policies',
        'sa_core_render_policies_page'
    );
}, 9);

function sa_core_render_ultra_dashboard(): void
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $products = function_exists('wc_get_products')
        ? (int) (wp_count_posts('product')->publish ?? 0)
        : 0;
    $orders_processing = 0;
    $orders_completed = 0;
    $customers = 0;
    if (function_exists('wc_orders_count')) {
        $orders_processing = (int) wc_orders_count('processing');
        $orders_completed = (int) wc_orders_count('completed');
    } elseif (function_exists('wc_get_orders')) {
        $proc = wc_get_orders(['status' => 'processing', 'limit' => 1, 'paginate' => true, 'return' => 'ids']);
        $comp = wc_get_orders(['status' => 'completed', 'limit' => 1, 'paginate' => true, 'return' => 'ids']);
        $orders_processing = is_object($proc) ? (int) ($proc->total ?? 0) : 0;
        $orders_completed = is_object($comp) ? (int) ($comp->total ?? 0) : 0;
    }
    $cust_q = count_users();
    $customers = (int) ($cust_q['avail_roles']['customer'] ?? 0);

    $brevo_ok = function_exists('sa_brevo_is_configured') && sa_brevo_is_configured();
    $brevo_page = admin_url('admin.php?page=sa-brevo-settings');
    $whop_page = admin_url('admin.php?page=wc-settings&tab=checkout&section=whop');

    $recent = [];
    if (function_exists('wc_get_orders')) {
        $recent = wc_get_orders(['limit' => 8, 'orderby' => 'date', 'order' => 'DESC']);
    }
    ?>
    <div class="wrap">
      <h1>Supreme Autoparts — Ultra Dashboard</h1>
      <p>Quick ops for catalog, customers, Whop payments, Brevo mail, and invoices.</p>

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin:1.25rem 0;">
        <?php
        $kpis = [
            ['Products', $products],
            ['Customers', $customers],
            ['Processing', $orders_processing],
            ['Completed', $orders_completed],
            ['Brevo', $brevo_ok ? 'Connected' : 'Set API key'],
        ];
        foreach ($kpis as [$label, $val]) :
            ?>
          <div style="background:#fff;border:1px solid #c3c4c7;border-radius:8px;padding:1rem;">
            <div style="font-size:.8rem;color:#646970;text-transform:uppercase;letter-spacing:.04em;"><?php echo esc_html($label); ?></div>
            <div style="font-size:1.6rem;font-weight:700;margin-top:.25rem;"><?php echo esc_html((string) $val); ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <h2>Quick links</h2>
      <p style="display:flex;flex-wrap:wrap;gap:8px;">
        <a class="button button-primary" href="<?php echo esc_url(admin_url('edit.php?post_type=shop_order')); ?>">Orders</a>
        <a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=product')); ?>">Products</a>
        <a class="button" href="<?php echo esc_url(admin_url('users.php?role=customer')); ?>">Customers</a>
        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=supreme-import')); ?>">Import tools</a>
        <a class="button" href="<?php echo esc_url($brevo_page); ?>">Brevo settings</a>
        <a class="button" href="<?php echo esc_url($whop_page); ?>">Whop payments</a>
        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=supreme-policies')); ?>">Policies</a>
        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=account')); ?>">Account settings</a>
      </p>

      <h2>Invoices</h2>
      <p>Open any order → <strong>Supreme invoice &amp; payment links</strong> box (copy URL), or use order action <em>Open Supreme invoice</em>. Customers with <code>sa_view_invoices</code> see an Invoice button on My Account → Orders.</p>
      <p>HTML invoice URL pattern: <code><?php echo esc_html(home_url('/?sa_invoice=ORDER_ID')); ?></code></p>

      <h2>Recent orders</h2>
      <table class="widefat striped">
        <thead>
          <tr><th>#</th><th>Date</th><th>Status</th><th>Total</th><th>Invoice</th></tr>
        </thead>
        <tbody>
        <?php if (!$recent) : ?>
          <tr><td colspan="5">No orders yet.</td></tr>
        <?php else : ?>
          <?php foreach ($recent as $order) : ?>
            <tr>
              <td><a href="<?php echo esc_url($order->get_edit_order_url()); ?>">#<?php echo esc_html($order->get_order_number()); ?></a></td>
              <td><?php echo esc_html($order->get_date_created() ? $order->get_date_created()->date_i18n('Y-m-d H:i') : ''); ?></td>
              <td><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></td>
              <td><?php echo wp_kses_post($order->get_formatted_order_total()); ?></td>
              <td><a href="<?php echo esc_url(sa_core_invoice_url((int) $order->get_id())); ?>" target="_blank" rel="noopener">Invoice</a></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php
}

function sa_core_render_policies_page(): void
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $slugs = ['terms', 'privacy-policy', 'shipping-policy', 'refund-policy', 'privacy'];
    echo '<div class="wrap"><h1>Store policies</h1><ul>';
    foreach ($slugs as $slug) {
        $page = get_page_by_path($slug);
        if ($page) {
            echo '<li><a href="' . esc_url(get_edit_post_link($page->ID)) . '">' . esc_html($page->post_title) . '</a> — <a href="' . esc_url(get_permalink($page)) . '" target="_blank" rel="noopener">View</a></li>';
        }
    }
    // Also list by title search for shipping/refund/terms variants.
    $q = get_posts(['post_type' => 'page', 'numberposts' => 20, 's' => 'policy']);
    foreach ($q as $p) {
        echo '<li><a href="' . esc_url(get_edit_post_link($p->ID)) . '">' . esc_html($p->post_title) . '</a></li>';
    }
    echo '</ul><p><a class="button" href="' . esc_url(admin_url('admin.php?page=supreme-import')) . '">Re-seed pages via Import tools</a></p></div>';
}

/**
 * Remove duplicate Tools → Supreme Import menu if Ultra already registered it.
 * Keep Tools page working via same callback slug.
 */
add_action('admin_menu', static function (): void {
    // admin-import.php also adds Tools page; both share slug supreme-import — WP merges fine.
}, 99);
