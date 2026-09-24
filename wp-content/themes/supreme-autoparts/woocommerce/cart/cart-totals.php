<?php
/**
 * Cart totals — marketplace order summary (coupon accordion, FREE shipping, CTA).
 *
 * @package Supreme_Autoparts
 */

defined('ABSPATH') || exit;

$shipping_total = WC()->cart ? (float) WC()->cart->get_shipping_total() : 0.0;
$needs_shipping = WC()->cart && WC()->cart->needs_shipping() && WC()->cart->show_shipping();
?>
<div class="cart_totals <?php echo (WC()->customer->has_calculated_shipping()) ? 'calculated_shipping' : ''; ?>">
  <?php do_action('woocommerce_before_cart_totals'); ?>

  <h2 class="sa-cart-summary__title"><?php esc_html_e('Order summary', 'supreme-autoparts'); ?></h2>

  <?php if (wc_coupons_enabled()) : ?>
    <details class="sa-cart-coupon">
      <summary class="sa-cart-coupon__summary"><?php esc_html_e('Have a coupon?', 'supreme-autoparts'); ?></summary>
      <div class="sa-cart-coupon__body coupon">
        <label for="coupon_code" class="screen-reader-text"><?php esc_html_e('Coupon:', 'supreme-autoparts'); ?></label>
        <input type="text" name="coupon_code" class="input-text" id="coupon_code" value="" placeholder="<?php esc_attr_e('Coupon code', 'supreme-autoparts'); ?>" form="woocommerce-cart" />
        <button type="submit" class="button sa-btn sa-btn--outline" name="apply_coupon" value="<?php esc_attr_e('Apply coupon', 'supreme-autoparts'); ?>" form="woocommerce-cart"><?php esc_html_e('Apply', 'supreme-autoparts'); ?></button>
        <?php do_action('woocommerce_cart_coupon'); ?>
      </div>
    </details>
  <?php endif; ?>

  <table cellspacing="0" class="shop_table shop_table_responsive sa-cart-totals-table">
    <tr class="cart-subtotal">
      <th><?php esc_html_e('Subtotal', 'supreme-autoparts'); ?></th>
      <td data-title="<?php esc_attr_e('Subtotal', 'supreme-autoparts'); ?>"><?php wc_cart_totals_subtotal_html(); ?></td>
    </tr>

    <?php foreach (WC()->cart->get_coupons() as $code => $coupon) : ?>
      <tr class="cart-discount coupon-<?php echo esc_attr(sanitize_title($code)); ?>">
        <th><?php wc_cart_totals_coupon_label($coupon); ?></th>
        <td data-title="<?php echo esc_attr(wc_cart_totals_coupon_label($coupon, false)); ?>"><?php wc_cart_totals_coupon_html($coupon); ?></td>
      </tr>
    <?php endforeach; ?>

    <?php if ($needs_shipping) : ?>
      <?php do_action('woocommerce_cart_totals_before_shipping'); ?>
      <?php wc_cart_totals_shipping_html(); ?>
      <?php do_action('woocommerce_cart_totals_after_shipping'); ?>
    <?php elseif (WC()->cart->needs_shipping()) : ?>
      <tr class="sa-cart-shipping-hint">
        <th><?php esc_html_e('Shipping', 'supreme-autoparts'); ?></th>
        <td data-title="<?php esc_attr_e('Shipping', 'supreme-autoparts'); ?>">
          <?php
          if ($shipping_total <= 0) {
              echo '<span class="sa-cart-free-ship">' . esc_html__('FREE', 'supreme-autoparts') . '</span>';
          } else {
              esc_html_e('Calculated at checkout', 'supreme-autoparts');
          }
          ?>
        </td>
      </tr>
    <?php else : ?>
      <tr class="sa-cart-shipping-hint">
        <th><?php esc_html_e('Shipping', 'supreme-autoparts'); ?></th>
        <td><span class="sa-cart-free-ship"><?php esc_html_e('FREE', 'supreme-autoparts'); ?></span></td>
      </tr>
    <?php endif; ?>

    <?php foreach (WC()->cart->get_fees() as $fee) : ?>
      <tr class="fee">
        <th><?php echo esc_html($fee->name); ?></th>
        <td data-title="<?php echo esc_attr($fee->name); ?>"><?php wc_cart_totals_fee_html($fee); ?></td>
      </tr>
    <?php endforeach; ?>

    <?php
    if (wc_tax_enabled() && !WC()->cart->display_prices_including_tax()) {
        $taxable_address = WC()->customer->get_taxable_address();
        $estimated_text  = '';
        if (WC()->customer->is_customer_outside_base() && !WC()->customer->has_calculated_shipping()) {
            /* translators: %s location. */
            $estimated_text = sprintf(' <small>' . esc_html__('(estimated for %s)', 'supreme-autoparts') . '</small>', WC()->countries->estimated_for_prefix($taxable_address[0]) . WC()->countries->countries[$taxable_address[0]]);
        }
        if ('itemized' === get_option('woocommerce_tax_total_display')) {
            foreach (WC()->cart->get_tax_totals() as $code => $tax) {
                ?>
                <tr class="tax-rate tax-rate-<?php echo esc_attr(sanitize_title($code)); ?>">
                  <th><?php echo esc_html($tax->label) . $estimated_text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></th>
                  <td data-title="<?php echo esc_attr($tax->label); ?>"><?php echo wp_kses_post($tax->formatted_amount); ?></td>
                </tr>
                <?php
            }
        } else {
            ?>
            <tr class="tax-total">
              <th><?php echo esc_html(WC()->countries->tax_or_vat()) . $estimated_text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></th>
              <td data-title="<?php echo esc_attr(WC()->countries->tax_or_vat()); ?>"><?php wc_cart_totals_taxes_total_html(); ?></td>
            </tr>
            <?php
        }
    }
    ?>

    <?php do_action('woocommerce_cart_totals_before_order_total'); ?>

    <tr class="order-total sa-cart-estimated-total">
      <th><?php esc_html_e('Estimated total', 'supreme-autoparts'); ?></th>
      <td data-title="<?php esc_attr_e('Estimated total', 'supreme-autoparts'); ?>"><?php wc_cart_totals_order_total_html(); ?></td>
    </tr>

    <?php do_action('woocommerce_cart_totals_after_order_total'); ?>
  </table>

  <div class="wc-proceed-to-checkout sa-cart-proceed">
    <?php do_action('woocommerce_proceed_to_checkout'); ?>
  </div>

  <?php do_action('woocommerce_after_cart_totals'); ?>
</div>
