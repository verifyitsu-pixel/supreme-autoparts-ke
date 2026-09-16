<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ultra admin: KPI helpers, store health, dashboard render, asset enqueue.
 */

add_action('admin_enqueue_scripts', static function (string $hook): void {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $pages = [
        'toplevel_page_supreme-autoparts',
        'supreme-autoparts_page_supreme-orders',
        'supreme-autoparts_page_supreme-products',
        'supreme-autoparts_page_supreme-customers',
        'supreme-autoparts_page_supreme-leads',
        'supreme-autoparts_page_supreme-policies',
        'supreme-autoparts_page_supreme-import',
    ];
    $on_ultra = in_array($hook, $pages, true);
    $on_order = $hook === 'woocommerce_page_wc-orders'
        || $hook === 'post.php'
        || $hook === 'edit.php';

    if (!$on_ultra && !$on_order) {
        return;
    }

    wp_enqueue_style(
        'sa-admin-ultra',
        SA_CORE_URL . 'assets/css/admin-ultra.css',
        [],
        SA_CORE_VERSION
    );
    wp_enqueue_script(
        'sa-admin-ultra',
        SA_CORE_URL . 'assets/js/admin-ultra.js',
        [],
        SA_CORE_VERSION,
        true
    );
});

/**
 * @return array{
 *   orders_today:int,
 *   revenue_today:float,
 *   revenue_label:string,
 *   products:int,
 *   low_stock:int,
 *   customers:int,
 *   processing:int,
 *   completed:int
 * }
 */
function sa_core_ultra_kpis(): array
{
    $tz = wp_timezone();
    $start = (new DateTimeImmutable('today', $tz))->setTime(0, 0, 0);
    $start_gmt = $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

    $orders_today = 0;
    $revenue = 0.0;
    $processing = 0;
    $completed = 0;

    if (function_exists('wc_get_orders')) {
        $today = wc_get_orders([
            'limit'        => 200,
            'status'       => ['pending', 'on-hold', 'processing', 'completed'],
            'date_created' => '>=' . $start_gmt,
            'return'       => 'objects',
        ]);
        if (is_array($today)) {
            $orders_today = count($today);
            foreach ($today as $order) {
                if ($order instanceof WC_Order) {
                    // Revenue stub: sum totals for paid-ish statuses.
                    $st = $order->get_status();
                    if (in_array($st, ['processing', 'completed', 'on-hold'], true)) {
                        $revenue += (float) $order->get_total();
                    }
                }
            }
        }
        if (function_exists('wc_orders_count')) {
            $processing = (int) wc_orders_count('processing');
            $completed = (int) wc_orders_count('completed');
        }
    }

    $products = 0;
    $counts = wp_count_posts('product');
    if (is_object($counts) && isset($counts->publish)) {
        $products = (int) $counts->publish;
    }

    $low_stock = 0;
    if (function_exists('wc_get_low_stock_amount') && function_exists('wc_get_products')) {
        // Lightweight stub: count published products marked low / out of stock via meta query.
        $low_threshold = (int) get_option('woocommerce_notify_low_stock_amount', 2);
        $q = new WP_Query([
            'post_type'              => 'product',
            'post_status'            => 'publish',
            'posts_per_page'         => 1,
            'fields'                 => 'ids',
            'no_found_rows'          => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'meta_query'             => [
                'relation' => 'AND',
                [
                    'key'     => '_manage_stock',
                    'value'   => 'yes',
                    'compare' => '=',
                ],
                [
                    'key'     => '_stock',
                    'value'   => $low_threshold,
                    'compare' => '<=',
                    'type'    => 'NUMERIC',
                ],
            ],
        ]);
        $low_stock = (int) $q->found_posts;
        wp_reset_postdata();
    }

    $customers = 0;
    $cust_q = count_users();
    $customers = (int) ($cust_q['avail_roles']['customer'] ?? 0);

    $currency = function_exists('get_woocommerce_currency_symbol')
        ? get_woocommerce_currency_symbol()
        : '$';

    return [
        'orders_today'   => $orders_today,
        'revenue_today'  => $revenue,
        'revenue_label'  => $currency . number_format_i18n($revenue, 2),
        'products'       => $products,
        'low_stock'      => $low_stock,
        'customers'      => $customers,
        'processing'     => $processing,
        'completed'      => $completed,
    ];
}

/**
 * @return array<int, array{label:string,ok:bool,detail:string,badge:string}>
 */
function sa_core_ultra_store_health(): array
{
    $home = (string) (getenv('WP_HOME') ?: get_option('home') ?: '');
    $brevo = function_exists('sa_brevo_is_configured') && sa_brevo_is_configured();

    $whop_enabled = false;
    $whop_detail = 'Gateway not loaded';
    $settings = get_option('woocommerce_whop_settings', []);
    if (is_array($settings) && $settings !== []) {
        $whop_enabled = (($settings['enabled'] ?? 'no') === 'yes');
        $whop_detail = $whop_enabled ? 'Whop gateway enabled' : 'Whop gateway disabled';
    }
    if (function_exists('WC') && WC() && isset(WC()->payment_gateways)) {
        $gateways = WC()->payment_gateways();
        if ($gateways) {
            $all = $gateways->payment_gateways();
            if (isset($all['whop']) && is_object($all['whop'])) {
                $whop_enabled = $all['whop']->enabled === 'yes';
                $whop_detail = $whop_enabled ? 'Whop gateway enabled' : 'Whop gateway disabled';
            } elseif ($whop_detail === 'Gateway not loaded') {
                $whop_detail = 'Whop gateway missing';
            }
        }
    }

    $products = 0;
    $counts = wp_count_posts('product');
    if (is_object($counts) && isset($counts->publish)) {
        $products = (int) $counts->publish;
    }

    return [
        [
            'label'  => 'WP_HOME',
            'ok'     => $home !== '' && str_contains($home, 'supremeautoparts'),
            'detail' => $home !== '' ? $home : 'Not set',
            'badge'  => $home !== '' ? 'ok' : 'warn',
        ],
        [
            'label'  => 'Brevo',
            'ok'     => $brevo,
            'detail' => $brevo ? 'API key configured' : 'Set BREVO_API_KEY / plugin settings',
            'badge'  => $brevo ? 'ok' : 'warn',
        ],
        [
            'label'  => 'Whop gateway',
            'ok'     => $whop_enabled,
            'detail' => $whop_detail,
            'badge'  => $whop_enabled ? 'ok' : 'warn',
        ],
        [
            'label'  => 'Products',
            'ok'     => $products > 0,
            'detail' => number_format_i18n($products) . ' published',
            'badge'  => $products > 0 ? 'ok' : 'bad',
        ],
    ];
}

/**
 * Primary Ultra dashboard (Supreme Autoparts menu root).
 */
function sa_core_ultra_render_dashboard(): void
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $kpis = sa_core_ultra_kpis();
    $health = sa_core_ultra_store_health();
    $email = function_exists('sa_core_store_email') ? sa_core_store_email() : 'calvin@supremeautoparts.co.ke';

    $recent = [];
    if (function_exists('wc_get_orders')) {
        $recent = wc_get_orders(['limit' => 8, 'orderby' => 'date', 'order' => 'DESC']);
    }

    $links = [
        ['Orders', admin_url('edit.php?post_type=shop_order'), true],
        ['Products', admin_url('edit.php?post_type=product'), false],
        ['Customers', admin_url('admin.php?page=supreme-customers'), false],
        ['Import', admin_url('admin.php?page=supreme-import'), false],
        ['Brevo', admin_url('admin.php?page=sa-brevo-settings'), false],
        ['Whop', admin_url('admin.php?page=wc-settings&tab=checkout&section=whop'), false],
        ['Policies', admin_url('admin.php?page=supreme-policies'), false],
        ['Enquire leads', admin_url('admin.php?page=supreme-leads'), false],
    ];
    // HPOS-aware orders URL.
    if (function_exists('wc_get_page_screen_id')) {
        $links[0][1] = admin_url('admin.php?page=wc-orders');
    }
    ?>
    <div class="wrap sa-ultra">
      <div class="sa-ultra__header">
        <div>
          <h1 class="sa-ultra__title">Supreme Autoparts — Ultra</h1>
          <p class="sa-ultra__sub">Ops console for orders, catalog, customers, payments, mail, and part enquiries.</p>
        </div>
        <a class="sa-ultra__email" href="mailto:<?php echo esc_attr($email); ?>"><?php echo esc_html($email); ?></a>
      </div>

      <div class="sa-ultra__kpis">
        <div class="sa-kpi">
          <span class="sa-kpi__label">Orders today</span>
          <p class="sa-kpi__value"><?php echo esc_html((string) $kpis['orders_today']); ?></p>
          <p class="sa-kpi__hint">Africa/Nairobi midnight → now</p>
        </div>
        <div class="sa-kpi">
          <span class="sa-kpi__label">Revenue (stub)</span>
          <p class="sa-kpi__value"><?php echo esc_html($kpis['revenue_label']); ?></p>
          <p class="sa-kpi__hint">Processing / completed / on-hold today</p>
        </div>
        <div class="sa-kpi">
          <span class="sa-kpi__label">Products</span>
          <p class="sa-kpi__value"><?php echo esc_html(number_format_i18n($kpis['products'])); ?></p>
          <p class="sa-kpi__hint"><?php echo esc_html(number_format_i18n($kpis['customers'])); ?> customers</p>
        </div>
        <div class="sa-kpi<?php echo $kpis['low_stock'] > 0 ? ' sa-kpi--warn' : ''; ?>">
          <span class="sa-kpi__label">Low stock</span>
          <p class="sa-kpi__value"><?php echo esc_html((string) $kpis['low_stock']); ?></p>
          <p class="sa-kpi__hint">Managed stock ≤ notify threshold</p>
        </div>
      </div>

      <div class="sa-ultra__grid">
        <div class="sa-panel">
          <div class="sa-panel__head">
            <h2 class="sa-panel__title">Quick actions</h2>
          </div>
          <div class="sa-actions">
            <?php foreach ($links as [$label, $url, $primary]) : ?>
              <a class="button<?php echo $primary ? ' button-primary' : ''; ?>" href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a>
            <?php endforeach; ?>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=supreme-orders')); ?>">Order tools</a>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=supreme-products')); ?>">Product tools</a>
          </div>
        </div>

        <div class="sa-panel">
          <div class="sa-panel__head">
            <h2 class="sa-panel__title">Store health</h2>
          </div>
          <ul class="sa-health">
            <?php foreach ($health as $row) : ?>
              <li>
                <div>
                  <span class="sa-health__label"><?php echo esc_html($row['label']); ?></span>
                  <span class="sa-health__meta"><?php echo esc_html($row['detail']); ?></span>
                </div>
                <span class="sa-badge sa-badge--<?php echo esc_attr($row['badge']); ?>">
                  <?php echo esc_html($row['ok'] ? 'OK' : 'Check'); ?>
                </span>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>

      <div class="sa-panel" style="margin-bottom:18px;">
        <div class="sa-panel__head">
          <h2 class="sa-panel__title">Recent orders</h2>
          <a class="sa-panel__link" href="<?php echo esc_url($links[0][1]); ?>">View all</a>
        </div>
        <div class="sa-table-wrap">
          <table class="sa-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Date</th>
                <th>Status</th>
                <th>Total</th>
                <th>Invoice</th>
                <th>Pay link</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$recent) : ?>
              <tr><td colspan="6" class="sa-muted">No orders yet.</td></tr>
            <?php else : ?>
              <?php foreach ($recent as $i => $order) : ?>
                <?php
                if (!$order instanceof WC_Order) {
                    continue;
                }
                $oid = (int) $order->get_id();
                $inv = function_exists('sa_core_invoice_url') ? sa_core_invoice_url($oid) : '';
                $pay = $order->get_checkout_payment_url();
                $inv_id = 'sa-inv-' . $oid;
                $pay_id = 'sa-pay-' . $oid;
                ?>
                <tr>
                  <td><a href="<?php echo esc_url($order->get_edit_order_url()); ?>">#<?php echo esc_html($order->get_order_number()); ?></a></td>
                  <td><?php echo esc_html($order->get_date_created() ? $order->get_date_created()->date_i18n('Y-m-d H:i') : ''); ?></td>
                  <td><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></td>
                  <td><?php echo wp_kses_post($order->get_formatted_order_total()); ?></td>
                  <td>
                    <div class="sa-copy-row">
                      <input id="<?php echo esc_attr($inv_id); ?>" type="text" readonly value="<?php echo esc_attr($inv); ?>" onclick="this.select()" />
                      <button type="button" class="button" data-sa-copy="#<?php echo esc_attr($inv_id); ?>">Copy</button>
                    </div>
                  </td>
                  <td>
                    <div class="sa-copy-row">
                      <input id="<?php echo esc_attr($pay_id); ?>" type="text" readonly value="<?php echo esc_attr($pay); ?>" onclick="this.select()" />
                      <button type="button" class="button" data-sa-copy="#<?php echo esc_attr($pay_id); ?>">Copy</button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
        <p class="sa-muted" style="margin:12px 0 0;">
          Invoice pattern: <code><?php echo esc_html(home_url('/?sa_invoice=ORDER_ID')); ?></code>
          · Processing: <?php echo esc_html((string) $kpis['processing']); ?>
          · Completed: <?php echo esc_html((string) $kpis['completed']); ?>
        </p>
      </div>
    </div>
    <?php
}

/** Back-compat alias used by menu registration. */
function sa_core_render_ultra_dashboard(): void
{
    sa_core_ultra_render_dashboard();
}
