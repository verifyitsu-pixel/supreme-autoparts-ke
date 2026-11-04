<?php
/**
 * Checkout payment section — Whop gateway + place order.
 *
 * @package Supreme_Autoparts
 * @version 1.3.0
 */

defined('ABSPATH') || exit;

if (!wp_doing_ajax()) {
    do_action('woocommerce_review_order_before_payment');
}
?>
<div id="payment" class="woocommerce-checkout-payment sa-checkout-payment">
  <?php if (WC()->cart && WC()->cart->needs_payment()) : ?>
    <ul class="wc_payment_methods payment_methods methods">
      <?php
      // Display order only. Do not change checked state or gateway availability.
      foreach (['paystack', 'pesapal', 'whop'] as $id) {
          if (isset($available_gateways[$id])) {
              wc_get_template('checkout/payment-method.php', ['gateway' => $available_gateways[$id]]);
          } elseif (function_exists('sa_visible_missing_payment_cards')) {
              sa_visible_missing_payment_cards($id);
          }
      }
      foreach (($available_gateways ?? []) as $id => $gateway) {
          if (!in_array($id, ['paystack', 'pesapal', 'whop'], true)) {
              wc_get_template('checkout/payment-method.php', ['gateway' => $gateway]);
          }
      }
      ?>
    </ul>
  <?php endif; ?>

  <div class="form-row place-order sa-checkout-place">
    <noscript>
      <?php
      printf(
          esc_html__('Since your browser does not support JavaScript, please click the %1$sUpdate totals%2$s button before placing your order.', 'supreme-autoparts'),
          '<em>',
          '</em>'
      );
      ?>
      <br/><button type="submit" class="button alt<?php echo esc_attr(wc_wp_theme_get_element_class_name('button') ? ' ' . wc_wp_theme_get_element_class_name('button') : ''); ?>" name="woocommerce_checkout_update_totals" value="<?php esc_attr_e('Update totals', 'supreme-autoparts'); ?>"><?php esc_html_e('Update totals', 'supreme-autoparts'); ?></button>
    </noscript>

    <?php wc_get_template('checkout/terms.php'); ?>

    <?php do_action('woocommerce_review_order_before_submit'); ?>

    <?php echo apply_filters('woocommerce_order_button_html', '<button type="submit" class="button alt sa-btn sa-checkout-place__btn' . esc_attr(wc_wp_theme_get_element_class_name('button') ? ' ' . wc_wp_theme_get_element_class_name('button') : '') . '" name="woocommerce_checkout_place_order" id="place_order" value="' . esc_attr($order_button_text) . '" data-value="' . esc_attr($order_button_text) . '" disabled aria-disabled="true">' . esc_html($order_button_text) . '</button>'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

    <?php do_action('woocommerce_review_order_after_submit'); ?>

    <?php wp_nonce_field('woocommerce-process_checkout', 'woocommerce-process-checkout-nonce'); ?>

    <p class="sa-checkout-place__hint" data-sa-terms-hint hidden>
      <?php esc_html_e('Accept the terms above to enable Pay.', 'supreme-autoparts'); ?>
    </p>
  </div>
</div>
<?php
if (!wp_doing_ajax()) {
    do_action('woocommerce_review_order_after_payment');
}
