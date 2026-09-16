<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Order management shortcuts under Supreme Autoparts menu.
 */
add_action('admin_menu', static function (): void {
    add_submenu_page(
        'supreme-autoparts',
        'Order tools',
        'Order tools',
        'manage_woocommerce',
        'supreme-orders',
        'sa_core_render_orders_tools_page'
    );
}, 20);

function sa_core_render_orders_tools_page(): void
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $orders_url = admin_url('edit.php?post_type=shop_order');
    if (function_exists('wc_get_page_screen_id')) {
        $orders_url = admin_url('admin.php?page=wc-orders');
    }

    $recent = [];
    if (function_exists('wc_get_orders')) {
        $recent = wc_get_orders([
            'limit'   => 15,
            'orderby' => 'date',
            'order'   => 'DESC',
            'status'  => ['pending', 'on-hold', 'processing', 'completed', 'failed'],
        ]);
    }
    $email = function_exists('sa_core_store_email') ? sa_core_store_email() : 'calvin@supremeautoparts.co.ke';
    ?>
    <div class="wrap sa-ultra">
      <div class="sa-ultra__header">
        <div>
          <h1 class="sa-ultra__title">Order tools</h1>
          <p class="sa-ultra__sub">Shortcuts for WooCommerce orders, invoices, and customer payment links.</p>
        </div>
        <a class="sa-ultra__email" href="mailto:<?php echo esc_attr($email); ?>"><?php echo esc_html($email); ?></a>
      </div>

      <div class="sa-actions" style="margin-bottom:16px;">
        <a class="button button-primary" href="<?php echo esc_url($orders_url); ?>">All orders</a>
        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=wc-orders&status=wc-processing')); ?>">Processing</a>
        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=wc-orders&status=wc-on-hold')); ?>">On hold</a>
        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=wc-orders&status=wc-pending')); ?>">Pending payment</a>
        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=checkout')); ?>">Checkout gateways</a>
      </div>

      <div class="sa-panel">
        <div class="sa-panel__head">
          <h2 class="sa-panel__title">Invoice &amp; payment link copy</h2>
        </div>
        <p class="sa-muted" style="margin-top:0;">Select any field and use Copy. Same controls appear on each order edit screen.</p>
        <div class="sa-table-wrap">
          <table class="sa-table">
            <thead>
              <tr>
                <th>Order</th>
                <th>Customer</th>
                <th>Status</th>
                <th>Invoice URL</th>
                <th>Pay URL</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$recent) : ?>
              <tr><td colspan="5" class="sa-muted">No orders yet.</td></tr>
            <?php else : ?>
              <?php foreach ($recent as $order) : ?>
                <?php
                if (!$order instanceof WC_Order) {
                    continue;
                }
                $oid = (int) $order->get_id();
                $inv = function_exists('sa_core_invoice_url') ? sa_core_invoice_url($oid) : '';
                $pay = $order->get_checkout_payment_url();
                $inv_id = 'sa-ot-inv-' . $oid;
                $pay_id = 'sa-ot-pay-' . $oid;
                $name = trim($order->get_formatted_billing_full_name());
                if ($name === '') {
                    $name = $order->get_billing_email() ?: '—';
                }
                ?>
                <tr>
                  <td><a href="<?php echo esc_url($order->get_edit_order_url()); ?>">#<?php echo esc_html($order->get_order_number()); ?></a></td>
                  <td><?php echo esc_html($name); ?></td>
                  <td><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></td>
                  <td>
                    <div class="sa-copy-row">
                      <input id="<?php echo esc_attr($inv_id); ?>" type="text" readonly value="<?php echo esc_attr($inv); ?>" onclick="this.select()" />
                      <button type="button" class="button" data-sa-copy="#<?php echo esc_attr($inv_id); ?>">Copy</button>
                      <a class="button" href="<?php echo esc_url($inv); ?>" target="_blank" rel="noopener">Open</a>
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
      </div>
    </div>
    <?php
}
