<?php
/**
 * View Order — status, docs, and order details.
 *
 * @package Supreme_Autoparts
 * @version 3.1.0
 */

defined('ABSPATH') || exit;

$notes = $order->get_customer_order_notes();
$oid = (int) $order->get_id();
$invoice_url = function_exists('sa_core_invoice_url') ? sa_core_invoice_url($oid) : '';
$email_url = function_exists('sa_core_invoice_email_url') ? sa_core_invoice_email_url($oid) : '';
$doc = function_exists('sa_core_invoice_doc_title') ? sa_core_invoice_doc_title($order) : __('Invoice', 'supreme-autoparts');
$can_invoice = $invoice_url && (
    current_user_can('sa_view_invoices')
    || current_user_can('manage_woocommerce')
    || current_user_can('edit_shop_orders')
    || (int) $order->get_user_id() === get_current_user_id()
);
?>
<div class="sa-account-panel sa-view-order">
  <header class="sa-account-panel__head sa-view-order__head">
    <div>
      <p class="sa-dash__eyebrow"><?php esc_html_e('Order details', 'supreme-autoparts'); ?></p>
      <h2>
        <?php
        printf(
            /* translators: %s: order number */
            esc_html__('Order #%s', 'supreme-autoparts'),
            esc_html($order->get_order_number())
        );
        ?>
      </h2>
      <p class="sa-account-panel__lead sa-view-order__meta">
        <?php
        printf(
            /* translators: 1: date 2: status */
            esc_html__('Placed on %1$s · Status: %2$s', 'supreme-autoparts'),
            esc_html(wc_format_datetime($order->get_date_created())),
            esc_html(wc_get_order_status_name($order->get_status()))
        );
        ?>
      </p>
    </div>
    <?php if ($can_invoice) : ?>
      <div class="sa-view-order__docs" role="group" aria-label="<?php esc_attr_e('Order documents', 'supreme-autoparts'); ?>">
        <a class="sa-btn sa-btn--sm" href="<?php echo esc_url($invoice_url); ?>" target="_blank" rel="noopener">
          <?php echo esc_html(sprintf(/* translators: Invoice or Receipt */ __('View %s', 'supreme-autoparts'), $doc)); ?>
        </a>
        <a class="sa-btn sa-btn--outline sa-btn--sm" href="<?php echo esc_url(add_query_arg('download', '1', $invoice_url)); ?>">
          <?php esc_html_e('Download', 'supreme-autoparts'); ?>
        </a>
        <?php if ($email_url) : ?>
          <a class="sa-btn sa-btn--outline sa-btn--sm" href="<?php echo esc_url($email_url); ?>">
            <?php esc_html_e('Email me', 'supreme-autoparts'); ?>
          </a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </header>

  <?php if ($notes) : ?>
    <section class="sa-view-order__updates" aria-label="<?php esc_attr_e('Order updates', 'supreme-autoparts'); ?>">
      <h3><?php esc_html_e('Order updates', 'supreme-autoparts'); ?></h3>
      <ol class="woocommerce-OrderUpdates commentlist notes sa-view-order__notes">
        <?php foreach ($notes as $note) : ?>
          <li class="woocommerce-OrderUpdate comment note">
            <div class="woocommerce-OrderUpdate-inner comment_container">
              <div class="woocommerce-OrderUpdate-text comment-text">
                <p class="woocommerce-OrderUpdate-meta meta">
                  <?php echo esc_html(date_i18n(__('l jS \o\f F Y, h:ia', 'supreme-autoparts'), strtotime($note->comment_date))); ?>
                </p>
                <div class="woocommerce-OrderUpdate-description description">
                  <?php echo wp_kses_post(wpautop(wptexturize($note->comment_content))); ?>
                </div>
              </div>
            </div>
          </li>
        <?php endforeach; ?>
      </ol>
    </section>
  <?php endif; ?>

  <div class="sa-view-order__details">
    <?php do_action('woocommerce_view_order', $order_id); ?>
  </div>
</div>
