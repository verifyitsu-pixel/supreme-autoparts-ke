<?php
/**
 * My Account dashboard — greeting, recent orders, enquire CTA, quick links.
 *
 * @package Supreme_Autoparts
 */

defined('ABSPATH') || exit;

$current_user = wp_get_current_user();
$first        = trim((string) $current_user->first_name);
$greeting     = $first !== '' ? $first : $current_user->display_name;
$orders       = [];
if (function_exists('wc_get_orders')) {
    $orders = wc_get_orders([
        'customer_id' => get_current_user_id(),
        'limit'       => 5,
        'orderby'     => 'date',
        'order'       => 'DESC',
        'return'      => 'objects',
    ]);
}

$support_url = wc_get_account_endpoint_url('support');
$wa_url      = 'https://wa.me/254714498451';

$links = [
    [
        'href'  => wc_get_account_endpoint_url('orders'),
        'title' => __('Orders', 'supreme-autoparts'),
        'desc'  => __('Track and review purchases', 'supreme-autoparts'),
    ],
    [
        'href'  => wc_get_account_endpoint_url('invoices'),
        'title' => __('Invoices', 'supreme-autoparts'),
        'desc'  => __('Download receipts', 'supreme-autoparts'),
    ],
    [
        'href'  => wc_get_account_endpoint_url('edit-address'),
        'title' => __('Addresses', 'supreme-autoparts'),
        'desc'  => __('Billing and shipping', 'supreme-autoparts'),
    ],
    [
        'href'  => wc_get_account_endpoint_url('payment-methods'),
        'title' => __('Payment methods', 'supreme-autoparts'),
        'desc'  => __('Saved cards via Whop', 'supreme-autoparts'),
    ],
    [
        'href'  => wc_get_account_endpoint_url('edit-account'),
        'title' => __('Account details', 'supreme-autoparts'),
        'desc'  => __('Name, email, password', 'supreme-autoparts'),
    ],
    [
        'href'  => $support_url,
        'title' => __('Support / Enquire', 'supreme-autoparts'),
        'desc'  => __('Ask about a part or order', 'supreme-autoparts'),
    ],
];
?>
<div class="sa-dash">
  <header class="sa-dash__hero">
    <p class="sa-dash__eyebrow"><?php esc_html_e('My Account', 'supreme-autoparts'); ?></p>
    <h2 class="sa-dash__title">
      <?php
      printf(
          /* translators: %s: customer first name or display name */
          esc_html__('Welcome back, %s', 'supreme-autoparts'),
          esc_html($greeting)
      );
      ?>
    </h2>
    <p class="sa-dash__sub">
      <?php esc_html_e('Manage orders, payment methods, and account details for Supreme Autoparts.', 'supreme-autoparts'); ?>
    </p>
  </header>

  <section class="sa-dash__links" aria-label="<?php esc_attr_e('Quick links', 'supreme-autoparts'); ?>">
    <?php foreach ($links as $link) : ?>
      <a class="sa-dash__card" href="<?php echo esc_url($link['href']); ?>">
        <span class="sa-dash__card-title"><?php echo esc_html($link['title']); ?></span>
        <span class="sa-dash__card-desc"><?php echo esc_html($link['desc']); ?></span>
      </a>
    <?php endforeach; ?>
  </section>

  <section class="sa-dash__orders">
    <div class="sa-dash__section-head">
      <h3><?php esc_html_e('Recent orders', 'supreme-autoparts'); ?></h3>
      <a class="sa-dash__view-all" href="<?php echo esc_url(wc_get_account_endpoint_url('orders')); ?>">
        <?php esc_html_e('View all', 'supreme-autoparts'); ?>
      </a>
    </div>

    <?php if (empty($orders)) : ?>
      <div class="sa-dash__empty">
        <p><?php esc_html_e('No orders yet.', 'supreme-autoparts'); ?></p>
        <a class="sa-btn" href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>">
          <?php esc_html_e('Browse parts', 'supreme-autoparts'); ?>
        </a>
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
            <?php foreach ($orders as $order) : ?>
              <?php if (!$order instanceof WC_Order) { continue; } ?>
              <tr>
                <td data-title="<?php esc_attr_e('Order', 'supreme-autoparts'); ?>">
                  <a href="<?php echo esc_url($order->get_view_order_url()); ?>">
                    #<?php echo esc_html($order->get_order_number()); ?>
                  </a>
                </td>
                <td data-title="<?php esc_attr_e('Date', 'supreme-autoparts'); ?>">
                  <time datetime="<?php echo esc_attr($order->get_date_created() ? $order->get_date_created()->date('c') : ''); ?>">
                    <?php echo esc_html(wc_format_datetime($order->get_date_created())); ?>
                  </time>
                </td>
                <td data-title="<?php esc_attr_e('Status', 'supreme-autoparts'); ?>">
                  <span class="sa-status sa-status--<?php echo esc_attr($order->get_status()); ?>">
                    <?php echo esc_html(wc_get_order_status_name($order->get_status())); ?>
                  </span>
                </td>
                <td data-title="<?php esc_attr_e('Total', 'supreme-autoparts'); ?>">
                  <?php echo wp_kses_post($order->get_formatted_order_total()); ?>
                </td>
                <td>
                  <a class="sa-btn sa-btn--outline sa-btn--sm" href="<?php echo esc_url($order->get_view_order_url()); ?>">
                    <?php esc_html_e('View', 'supreme-autoparts'); ?>
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="sa-dash__enquire" aria-label="<?php esc_attr_e('Enquire', 'supreme-autoparts'); ?>">
    <div class="sa-dash__enquire-card">
      <div class="sa-dash__enquire-copy">
        <h3><?php esc_html_e('Need a part we do not list?', 'supreme-autoparts'); ?></h3>
        <p><?php esc_html_e('Tell us the part and your vehicle — we will check availability and pricing for Kenya delivery.', 'supreme-autoparts'); ?></p>
      </div>
      <div class="sa-dash__enquire-actions">
        <a class="sa-btn" href="<?php echo esc_url($support_url); ?>">
          <?php esc_html_e('Enquire now', 'supreme-autoparts'); ?>
        </a>
        <a class="sa-btn sa-btn--outline" href="<?php echo esc_url($wa_url); ?>" target="_blank" rel="noopener noreferrer">
          <?php esc_html_e('WhatsApp +254 714 498 451', 'supreme-autoparts'); ?>
        </a>
      </div>
    </div>
  </section>
</div>
