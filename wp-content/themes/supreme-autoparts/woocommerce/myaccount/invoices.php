<?php
/**
 * Customer invoices list.
 *
 * @package Supreme_Autoparts
 */

defined('ABSPATH') || exit;

$orders = [];
if (function_exists('wc_get_orders')) {
    $orders = wc_get_orders([
        'customer_id' => get_current_user_id(),
        'limit'       => 30,
        'orderby'     => 'date',
        'order'       => 'DESC',
        'status'      => ['wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed'],
        'return'      => 'objects',
    ]);
}
?>
<div class="sa-account-panel sa-invoices">
  <header class="sa-account-panel__head">
    <h2><?php esc_html_e('Invoices', 'supreme-autoparts'); ?></h2>
    <p class="sa-account-panel__lead"><?php esc_html_e('Open printable invoices and receipts for your recent orders.', 'supreme-autoparts'); ?></p>
  </header>

  <?php if (empty($orders)) : ?>
    <div class="sa-dash__empty">
      <p><?php esc_html_e('No invoices yet. Orders will appear here after checkout.', 'supreme-autoparts'); ?></p>
      <a class="sa-btn" href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>"><?php esc_html_e('Browse parts', 'supreme-autoparts'); ?></a>
    </div>
  <?php else : ?>
    <div class="sa-dash__table-wrap">
      <table class="sa-dash__table shop_table shop_table_responsive">
        <thead>
          <tr>
            <th><?php esc_html_e('Order', 'supreme-autoparts'); ?></th>
            <th><?php esc_html_e('Date', 'supreme-autoparts'); ?></th>
            <th><?php esc_html_e('Status', 'supreme-autoparts'); ?></th>
            <th><?php esc_html_e('Total', 'supreme-autoparts'); ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($orders as $order) :
              if (!$order instanceof WC_Order) {
                  continue;
              }
              $invoice_url = function_exists('sa_core_invoice_url')
                  ? sa_core_invoice_url((int) $order->get_id())
                  : $order->get_view_order_url();
              ?>
            <tr>
              <td data-title="<?php esc_attr_e('Order', 'supreme-autoparts'); ?>">
                <a href="<?php echo esc_url($order->get_view_order_url()); ?>">#<?php echo esc_html($order->get_order_number()); ?></a>
              </td>
              <td data-title="<?php esc_attr_e('Date', 'supreme-autoparts'); ?>"><?php echo esc_html(wc_format_datetime($order->get_date_created())); ?></td>
              <td data-title="<?php esc_attr_e('Status', 'supreme-autoparts'); ?>"><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></td>
              <td data-title="<?php esc_attr_e('Total', 'supreme-autoparts'); ?>"><?php echo wp_kses_post($order->get_formatted_order_total()); ?></td>
              <td>
                <a class="sa-btn sa-btn--outline sa-btn--sm" href="<?php echo esc_url($invoice_url); ?>" target="_blank" rel="noopener">
                  <?php esc_html_e('Invoice / Receipt', 'supreme-autoparts'); ?>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
