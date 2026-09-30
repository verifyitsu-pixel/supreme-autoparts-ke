<?php
/**
 * Review order table — sticky summary with qty steppers.
 *
 * @package Supreme_Autoparts
 * @version 1.4.43
 */

defined('ABSPATH') || exit;

$sa_currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD';
$sa_total_label = sprintf(
    /* translators: %s: currency code e.g. USD */
    __('Total (%s)', 'supreme-autoparts'),
    $sa_currency
);
?>
<table class="shop_table woocommerce-checkout-review-order-table sa-checkout-review-table">
  <thead>
    <tr>
      <th class="product-name"><?php esc_html_e('Product', 'supreme-autoparts'); ?></th>
      <th class="product-total"><?php esc_html_e('Subtotal', 'supreme-autoparts'); ?></th>
    </tr>
  </thead>
  <tbody>
    <?php
    do_action('woocommerce_review_order_before_cart_contents');

    foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
        $_product = apply_filters('woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key);
        if ($_product && $_product->exists() && $cart_item['quantity'] > 0 && apply_filters('woocommerce_checkout_cart_item_visible', true, $cart_item, $cart_item_key)) {
            $sold_individually = $_product->is_sold_individually();
            $min_qty = $sold_individually ? 1 : 0;
            $max_qty = $sold_individually ? 1 : $_product->get_max_purchase_quantity();
            ?>
            <tr class="<?php echo esc_attr(apply_filters('woocommerce_cart_item_class', 'cart_item', $cart_item, $cart_item_key)); ?>" data-sa-checkout-item="<?php echo esc_attr($cart_item_key); ?>">
              <td class="product-name">
                <div class="sa-checkout-line">
                  <?php
                  $GLOBALS['sa_rendering_cart_item_thumbnail'] = true;
                  $thumb_html = apply_filters(
                      'woocommerce_cart_item_thumbnail',
                      $_product->get_image(
                          'woocommerce_gallery_thumbnail',
                          [
                              'class'    => 'sa-checkout-line__thumb attachment-woocommerce_gallery_thumbnail',
                              'loading'  => 'lazy',
                              'decoding' => 'async',
                              'alt'      => '',
                          ]
                      ),
                      $cart_item,
                      $cart_item_key
                  );
                  unset($GLOBALS['sa_rendering_cart_item_thumbnail']);
                  echo $thumb_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                  ?>
                  <div class="sa-checkout-line__meta">
                    <?php echo wp_kses_post(apply_filters('woocommerce_cart_item_name', $_product->get_name(), $cart_item, $cart_item_key)); ?>
                    <?php echo wc_get_formatted_cart_item_data($cart_item); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php if (!$sold_individually) : ?>
                      <div class="sa-qty sa-qty--checkout" data-sa-qty data-sa-checkout-qty="<?php echo esc_attr($cart_item_key); ?>">
                        <button type="button" class="sa-qty__btn" data-sa-qty-minus aria-label="<?php esc_attr_e('Decrease quantity', 'supreme-autoparts'); ?>">−</button>
                        <label class="screen-reader-text" for="sa-co-qty-<?php echo esc_attr($cart_item_key); ?>"><?php esc_html_e('Quantity', 'supreme-autoparts'); ?></label>
                        <input
                          type="number"
                          id="sa-co-qty-<?php echo esc_attr($cart_item_key); ?>"
                          class="input-text qty text"
                          name="sa_checkout_qty[<?php echo esc_attr($cart_item_key); ?>]"
                          value="<?php echo esc_attr((string) $cart_item['quantity']); ?>"
                          min="<?php echo esc_attr((string) $min_qty); ?>"
                          <?php if ($max_qty > 0) : ?>max="<?php echo esc_attr((string) $max_qty); ?>"<?php endif; ?>
                          step="1"
                          inputmode="numeric"
                          data-sa-checkout-qty-input
                        />
                        <button type="button" class="sa-qty__btn" data-sa-qty-plus aria-label="<?php esc_attr_e('Increase quantity', 'supreme-autoparts'); ?>">+</button>
                      </div>
                    <?php else : ?>
                      <strong class="product-quantity">&times;&nbsp;<?php echo esc_html((string) $cart_item['quantity']); ?></strong>
                    <?php endif; ?>
                  </div>
                </div>
              </td>
              <td class="product-total">
                <?php echo apply_filters('woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal($_product, $cart_item['quantity']), $cart_item, $cart_item_key); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
              </td>
            </tr>
            <?php
        }
    }

    do_action('woocommerce_review_order_after_cart_contents');
    ?>
  </tbody>
  <tfoot>
    <tr class="cart-subtotal">
      <th><?php esc_html_e('Subtotal', 'supreme-autoparts'); ?></th>
      <td><?php wc_cart_totals_subtotal_html(); ?></td>
    </tr>

    <?php foreach (WC()->cart->get_coupons() as $code => $coupon) : ?>
      <tr class="cart-discount coupon-<?php echo esc_attr(sanitize_title($code)); ?>">
        <th><?php wc_cart_totals_coupon_label($coupon); ?></th>
        <td><?php wc_cart_totals_coupon_html($coupon); ?></td>
      </tr>
    <?php endforeach; ?>

    <?php if (WC()->cart->needs_shipping() && WC()->cart->show_shipping()) : ?>
      <?php do_action('woocommerce_review_order_before_shipping'); ?>
      <?php wc_cart_totals_shipping_html(); ?>
      <?php do_action('woocommerce_review_order_after_shipping'); ?>
    <?php endif; ?>

    <?php foreach (WC()->cart->get_fees() as $fee) : ?>
      <tr class="fee">
        <th><?php echo esc_html($fee->name); ?></th>
        <td><?php wc_cart_totals_fee_html($fee); ?></td>
      </tr>
    <?php endforeach; ?>

    <?php if (wc_tax_enabled() && !WC()->cart->display_prices_including_tax()) : ?>
      <?php if ('itemized' === get_option('woocommerce_tax_total_display')) : ?>
        <?php foreach (WC()->cart->get_tax_totals() as $code => $tax) : // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited ?>
          <tr class="tax-rate tax-rate-<?php echo esc_attr(sanitize_title($code)); ?>">
            <th><?php echo esc_html($tax->label); ?></th>
            <td><?php echo wp_kses_post($tax->formatted_amount); ?></td>
          </tr>
        <?php endforeach; ?>
      <?php else : ?>
        <tr class="tax-total">
          <th><?php echo esc_html(WC()->countries->tax_or_vat()); ?></th>
          <td><?php wc_cart_totals_taxes_total_html(); ?></td>
        </tr>
      <?php endif; ?>
    <?php endif; ?>

    <?php do_action('woocommerce_review_order_before_order_total'); ?>

    <tr class="order-total">
      <th><?php echo esc_html($sa_total_label); ?></th>
      <td><?php wc_cart_totals_order_total_html(); ?></td>
    </tr>

    <?php do_action('woocommerce_review_order_after_order_total'); ?>
  </tfoot>
</table>
