<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Simple HTML invoice endpoint: ?sa_invoice=ORDER_ID[&key=nonce]
 * Access: order owner with sa_view_invoices, or manage_woocommerce / edit_shop_orders.
 */

function sa_core_invoice_url(int $order_id): string
{
    return add_query_arg(
        [
            'sa_invoice' => $order_id,
            'key'        => wp_create_nonce('sa_invoice_' . $order_id),
        ],
        home_url('/')
    );
}

function sa_core_user_can_view_invoice(int $order_id, int $user_id = 0): bool
{
    $user_id = $user_id ?: get_current_user_id();
    if (!$user_id || !function_exists('wc_get_order')) {
        return false;
    }
    if (user_can($user_id, 'manage_woocommerce') || user_can($user_id, 'edit_shop_orders')) {
        return true;
    }
    $order = wc_get_order($order_id);
    if (!$order) {
        return false;
    }
    if ((int) $order->get_user_id() !== $user_id) {
        return false;
    }
    return user_can($user_id, 'sa_view_invoices') || user_can($user_id, 'read');
}

add_action('init', static function (): void {
    if (empty($_GET['sa_invoice'])) {
        return;
    }
    $order_id = absint($_GET['sa_invoice']);
    if ($order_id <= 0 || !function_exists('wc_get_order')) {
        return;
    }

    if (!is_user_logged_in()) {
        auth_redirect();
        exit;
    }

    if (!sa_core_user_can_view_invoice($order_id)) {
        wp_die(esc_html__('You do not have permission to view this invoice.', 'supreme-autoparts-core'), 403);
    }

    // Optional nonce: required for customers; admins may open without key (order screen copy).
    $nonce = isset($_GET['key']) ? sanitize_text_field(wp_unslash((string) $_GET['key'])) : '';
    $is_staff = current_user_can('manage_woocommerce') || current_user_can('edit_shop_orders');
    if (!$is_staff) {
        if ($nonce === '' || !wp_verify_nonce($nonce, 'sa_invoice_' . $order_id)) {
            wp_die(esc_html__('Invalid or expired invoice link. Open the invoice again from My Account → Orders.', 'supreme-autoparts-core'), 403);
        }
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_die('Order not found.', 404);
    }

    sa_core_render_invoice_html($order);
    exit;
}, 1);

/**
 * @param WC_Order $order
 */
function sa_core_render_invoice_html($order): void
{
    $store = 'Supreme Autoparts';
    $email = function_exists('sa_core_store_email') ? sa_core_store_email() : 'calvin@supremeautoparts.co.ke';
    $currency = $order->get_currency();
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Invoice #<?php echo esc_html($order->get_order_number()); ?> — <?php echo esc_html($store); ?></title>
  <style>
    *{box-sizing:border-box}
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;margin:0;padding:1.25rem;color:#111;background:#fff;line-height:1.45}
    .wrap{max-width:820px;margin:0 auto}
    h1{font-size:clamp(1.2rem,4vw,1.5rem);margin:0 0 .25rem}
    .muted{color:#555;font-size:.9rem}
    table{width:100%;border-collapse:collapse;margin:1.25rem 0;font-size:.95rem}
    th,td{border-bottom:1px solid #ddd;padding:.55rem .4rem;text-align:left;vertical-align:top}
    th{background:#f6f6f6}
    .right{text-align:right}
    .totals{max-width:320px;margin-left:auto}
    .totals td{border:0}
    .totals tr td:last-child{text-align:right;font-weight:600}
    .box{border:1px solid #e2e2e2;padding:1rem;border-radius:8px;margin:1rem 0}
    .no-print{display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem}
    .no-print button,.no-print a{appearance:none;border:1px solid #ccc;background:#0B0B0D;color:#fff;border-radius:6px;padding:.55rem .9rem;font:inherit;cursor:pointer;text-decoration:none}
    .no-print a{background:#F5A623;color:#0B0B0D;border-color:#F5A623;font-weight:600}
    @media (max-width:520px){
      body{padding:.85rem}
      table,thead,tbody,th,td,tr{display:block;width:100%}
      thead{display:none}
      tr{border-bottom:1px solid #eee;padding:.5rem 0;margin:0 0 .35rem}
      td{border:0;padding:.2rem 0;display:flex;justify-content:space-between;gap:1rem}
      td:nth-child(2)::before{content:"Qty ";color:#666;font-size:.75rem;text-transform:uppercase}
      td:nth-child(3)::before{content:"Total ";color:#666;font-size:.75rem;text-transform:uppercase}
      .totals{max-width:none;margin:1rem 0 0}
      .totals tr{display:flex;justify-content:space-between;border:0;padding:.25rem 0}
      .totals td{display:block;padding:0}
    }
    @media print{.no-print{display:none} body{padding:0}}
  </style>
</head>
<body>
  <div class="wrap">
  <p class="no-print">
    <button type="button" onclick="window.print()">Print / Save PDF</button>
    <a href="javascript:history.back()">Back</a>
  </p>
  <h1><?php echo esc_html($store); ?> — Invoice / Receipt</h1>
  <p class="muted"><?php echo esc_html($email); ?> · Nairobi, Kenya</p>
  <div class="box">
    <p><strong>Invoice #<?php echo esc_html($order->get_order_number()); ?></strong></p>
    <p>Date: <?php echo esc_html(wc_format_datetime($order->get_date_created())); ?></p>
    <p>Status: <?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></p>
    <p>Payment: <?php echo esc_html($order->get_payment_method_title() ?: '—'); ?></p>
  </div>
  <div class="box">
    <strong>Bill to</strong>
    <p><?php echo wp_kses_post($order->get_formatted_billing_address() ?: esc_html($order->get_billing_email())); ?></p>
    <p><?php echo esc_html($order->get_billing_email()); ?>
      <?php if ($order->get_billing_phone()) : ?> · <?php echo esc_html($order->get_billing_phone()); ?><?php endif; ?>
    </p>
  </div>
  <table>
    <thead>
      <tr><th>Item</th><th>Qty</th><th class="right">Total</th></tr>
    </thead>
    <tbody>
    <?php foreach ($order->get_items() as $item) : ?>
      <tr>
        <td><?php echo esc_html($item->get_name()); ?></td>
        <td><?php echo esc_html((string) $item->get_quantity()); ?></td>
        <td class="right"><?php echo wp_kses_post($order->get_formatted_line_subtotal($item)); ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <table class="totals">
    <tr><td>Subtotal</td><td><?php echo wp_kses_post($order->get_subtotal_to_display()); ?></td></tr>
    <?php if ((float) $order->get_shipping_total() > 0) : ?>
      <tr><td>Shipping</td><td><?php echo wp_kses_post(wc_price((float) $order->get_shipping_total(), ['currency' => $currency])); ?></td></tr>
    <?php endif; ?>
    <?php foreach ($order->get_tax_totals() as $code => $tax) : ?>
      <tr><td><?php echo esc_html($tax->label); ?></td><td><?php echo wp_kses_post($tax->formatted_amount); ?></td></tr>
    <?php endforeach; ?>
    <tr><td>Total</td><td><?php echo wp_kses_post($order->get_formatted_order_total()); ?></td></tr>
  </table>
  <?php if ($order->get_customer_note()) : ?>
    <div class="box"><strong>Note</strong><p><?php echo esc_html($order->get_customer_note()); ?></p></div>
  <?php endif; ?>
  <p class="muted">Thank you for shopping with Supreme Autoparts.</p>
  </div>
</body>
</html>
    <?php
}

/**
 * Admin: copy invoice / payment links on order edit screen.
 */
add_action('woocommerce_admin_order_data_after_order_details', static function ($order): void {
    if (!$order instanceof WC_Order || !current_user_can('manage_woocommerce')) {
        return;
    }
    $oid = (int) $order->get_id();
    $invoice = sa_core_invoice_url($oid);
    $pay = $order->get_checkout_payment_url();
    $view = $order->get_view_order_url();
    $inv_id = 'sa-order-inv-' . $oid;
    $pay_id = 'sa-order-pay-' . $oid;
    $view_id = 'sa-order-view-' . $oid;
    echo '<div class="order_data_column sa-invoice-box">';
    echo '<h3>' . esc_html__('Supreme invoice & payment links', 'supreme-autoparts-core') . '</h3>';
    echo '<div class="sa-copy-row"><label>Invoice</label><input id="' . esc_attr($inv_id) . '" type="text" class="widefat" readonly value="' . esc_attr($invoice) . '" onclick="this.select()" /><button type="button" class="button" data-sa-copy="#' . esc_attr($inv_id) . '">Copy</button></div>';
    echo '<div class="sa-copy-row"><label>Pay</label><input id="' . esc_attr($pay_id) . '" type="text" class="widefat" readonly value="' . esc_attr($pay) . '" onclick="this.select()" /><button type="button" class="button" data-sa-copy="#' . esc_attr($pay_id) . '">Copy</button></div>';
    echo '<div class="sa-copy-row"><label>Customer</label><input id="' . esc_attr($view_id) . '" type="text" class="widefat" readonly value="' . esc_attr($view) . '" onclick="this.select()" /><button type="button" class="button" data-sa-copy="#' . esc_attr($view_id) . '">Copy</button></div>';
    echo '<p style="margin:8px 0 0;"><a class="button button-primary" href="' . esc_url($invoice) . '" target="_blank" rel="noopener">Open invoice</a> ';
    echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=supreme-orders')) . '">Order tools</a></p>';
    echo '</div>';
});

add_filter('woocommerce_order_actions', static function (array $actions): array {
    $actions['sa_open_invoice'] = __('Open Supreme invoice', 'supreme-autoparts-core');
    return $actions;
});

add_action('woocommerce_order_action_sa_open_invoice', static function ($order): void {
    if (!$order instanceof WC_Order) {
        return;
    }
    $url = sa_core_invoice_url((int) $order->get_id());
    wp_safe_redirect($url);
    exit;
});
