<?php
/**
 * Orders list — responsive table with invoice actions.
 *
 * @package Supreme_Autoparts
 * @version 9.5.0
 */

defined('ABSPATH') || exit;

do_action('woocommerce_before_account_orders', $has_orders);
?>
<div class="sa-account-panel sa-orders">
  <header class="sa-account-panel__head">
    <h2><?php esc_html_e('Orders', 'supreme-autoparts'); ?></h2>
    <p class="sa-account-panel__lead"><?php esc_html_e('Track purchases and open invoices or receipts.', 'supreme-autoparts'); ?></p>
  </header>

<?php if ($has_orders) : ?>
  <div class="sa-dash__table-wrap sa-orders__table-wrap">
    <table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders account-orders-table sa-dash__table">
      <thead>
        <tr>
          <?php foreach (wc_get_account_orders_columns() as $column_id => $column_name) : ?>
            <th scope="col" class="woocommerce-orders-table__header woocommerce-orders-table__header-<?php echo esc_attr($column_id); ?>">
              <span class="nobr"><?php echo esc_html($column_name); ?></span>
            </th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php
        foreach ($customer_orders->orders as $customer_order) {
            $order      = wc_get_order($customer_order);
            $item_count = $order->get_item_count() - $order->get_item_count_refunded();
            ?>
          <tr class="woocommerce-orders-table__row woocommerce-orders-table__row--status-<?php echo esc_attr($order->get_status()); ?> order">
            <?php foreach (wc_get_account_orders_columns() as $column_id => $column_name) :
                $is_order_number = 'order-number' === $column_id;
                ?>
              <?php if ($is_order_number) : ?>
                <th class="woocommerce-orders-table__cell woocommerce-orders-table__cell-<?php echo esc_attr($column_id); ?>" data-title="<?php echo esc_attr($column_name); ?>" scope="row">
              <?php else : ?>
                <td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-<?php echo esc_attr($column_id); ?>" data-title="<?php echo esc_attr($column_name); ?>">
              <?php endif; ?>

                <?php if (has_action('woocommerce_my_account_my_orders_column_' . $column_id)) : ?>
                  <?php do_action('woocommerce_my_account_my_orders_column_' . $column_id, $order); ?>

                <?php elseif ($is_order_number) : ?>
                  <a href="<?php echo esc_url($order->get_view_order_url()); ?>">
                    #<?php echo esc_html($order->get_order_number()); ?>
                  </a>

                <?php elseif ('order-date' === $column_id) : ?>
                  <time datetime="<?php echo esc_attr($order->get_date_created()->date('c')); ?>"><?php echo esc_html(wc_format_datetime($order->get_date_created())); ?></time>

                <?php elseif ('order-status' === $column_id) : ?>
                  <span class="sa-status sa-status--<?php echo esc_attr($order->get_status()); ?>">
                    <?php echo esc_html(wc_get_order_status_name($order->get_status())); ?>
                  </span>

                <?php elseif ('order-total' === $column_id) : ?>
                  <?php
                  echo wp_kses_post(sprintf(
                      _n('%1$s for %2$s item', '%1$s for %2$s items', $item_count, 'supreme-autoparts'),
                      $order->get_formatted_order_total(),
                      $item_count
                  ));
                  ?>

                <?php elseif ('order-actions' === $column_id) : ?>
                  <div class="sa-orders__actions">
                    <?php
                    $actions = wc_get_account_orders_actions($order);
                    if (!empty($actions)) {
                        foreach ($actions as $key => $action) {
                            $aria = !empty($action['aria-label'])
                                ? $action['aria-label']
                                : sprintf(__('%1$s order number %2$s', 'supreme-autoparts'), $action['name'], $order->get_order_number());
                            $btn_class = 'sa-btn sa-btn--sm';
                            if ($key === 'view') {
                                $btn_class .= ' sa-btn--outline';
                            } elseif ($key === 'sa_invoice') {
                                $btn_class .= ' sa-btn--outline sa-btn--invoice';
                            } elseif ($key === 'sa_email_invoice') {
                                $btn_class .= ' sa-btn--invoice';
                            } else {
                                $btn_class .= ' sa-btn--outline';
                            }
                            $target = ($key === 'sa_invoice') ? ' target="_blank" rel="noopener"' : '';
                            echo '<a href="' . esc_url($action['url']) . '" class="' . esc_attr($btn_class . ' ' . sanitize_html_class($key)) . '" aria-label="' . esc_attr($aria) . '"' . $target . '>' . esc_html($action['name']) . '</a>';
                        }
                    }
                    ?>
                  </div>
                <?php endif; ?>

              <?php if ($is_order_number) : ?>
                </th>
              <?php else : ?>
                </td>
              <?php endif; ?>
            <?php endforeach; ?>
          </tr>
            <?php
        }
        ?>
      </tbody>
    </table>
  </div>

  <?php do_action('woocommerce_before_account_orders_pagination'); ?>

  <?php if (1 < $customer_orders->max_num_pages) : ?>
    <div class="sa-orders__pagination woocommerce-pagination woocommerce-pagination--without-numbers woocommerce-Pagination">
      <?php if (1 !== $current_page) : ?>
        <a class="sa-btn sa-btn--outline sa-btn--sm" href="<?php echo esc_url(wc_get_endpoint_url('orders', $current_page - 1)); ?>">
          <?php esc_html_e('Previous', 'supreme-autoparts'); ?>
        </a>
      <?php endif; ?>
      <?php if ((int) $customer_orders->max_num_pages !== $current_page) : ?>
        <a class="sa-btn sa-btn--outline sa-btn--sm" href="<?php echo esc_url(wc_get_endpoint_url('orders', $current_page + 1)); ?>">
          <?php esc_html_e('Next', 'supreme-autoparts'); ?>
        </a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

<?php else : ?>
  <div class="sa-dash__empty">
    <p><?php esc_html_e('No orders yet.', 'supreme-autoparts'); ?></p>
    <a class="sa-btn" href="<?php echo esc_url(apply_filters('woocommerce_return_to_shop_redirect', wc_get_page_permalink('shop'))); ?>">
      <?php esc_html_e('Browse parts', 'supreme-autoparts'); ?>
    </a>
  </div>
<?php endif; ?>
</div>
<?php do_action('woocommerce_after_account_orders', $has_orders); ?>
