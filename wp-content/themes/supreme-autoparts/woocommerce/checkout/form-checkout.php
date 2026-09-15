<?php
/**
 * Checkout form override — structure matches WooCommerce core with theme classes.
 *
 * @package Supreme_Autoparts
 * @version 3.5.0
 */

defined('ABSPATH') || exit;

do_action('woocommerce_before_checkout_form', WC()->checkout());

if (!WC()->checkout()->is_registration_enabled() && WC()->checkout()->is_registration_required() && !is_user_logged_in()) {
    echo esc_html(apply_filters('woocommerce_checkout_must_be_logged_in_message', __('You must be logged in to checkout.', 'supreme-autoparts')));
    return;
}
?>
<form name="checkout" method="post" class="checkout woocommerce-checkout" action="<?php echo esc_url(wc_get_checkout_url()); ?>" enctype="multipart/form-data">
  <?php if (WC()->checkout()->get_checkout_fields()) : ?>
    <?php do_action('woocommerce_checkout_before_customer_details'); ?>
    <div class="col2-set" id="customer_details">
      <div class="col-1"><?php do_action('woocommerce_checkout_billing'); ?></div>
      <div class="col-2"><?php do_action('woocommerce_checkout_shipping'); ?></div>
    </div>
    <?php do_action('woocommerce_checkout_after_customer_details'); ?>
  <?php endif; ?>
  <?php do_action('woocommerce_checkout_before_order_review_heading'); ?>
  <h3 id="order_review_heading"><?php esc_html_e('Your order', 'supreme-autoparts'); ?></h3>
  <?php do_action('woocommerce_checkout_before_order_review'); ?>
  <div id="order_review" class="woocommerce-checkout-review-order">
    <?php do_action('woocommerce_checkout_order_review'); ?>
  </div>
  <?php do_action('woocommerce_checkout_after_order_review'); ?>
</form>
<?php
do_action('woocommerce_after_checkout_form', WC()->checkout());
