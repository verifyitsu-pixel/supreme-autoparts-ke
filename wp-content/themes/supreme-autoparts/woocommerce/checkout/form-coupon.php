<?php
/**
 * Checkout coupon form — matches checkout visual system.
 *
 * @package Supreme_Autoparts
 * @version 1.3.0
 */

defined('ABSPATH') || exit;

if (!wc_coupons_enabled()) {
    return;
}
?>
<div class="woocommerce-form-coupon-toggle sa-checkout-coupon-toggle">
  <?php wc_print_notice(apply_filters('woocommerce_checkout_coupon_message', esc_html__('Have a coupon?', 'supreme-autoparts') . ' <a href="#" class="showcoupon">' . esc_html__('Click here to enter your code', 'supreme-autoparts') . '</a>'), 'notice'); ?>
</div>

<form class="checkout_coupon woocommerce-form-coupon sa-checkout-coupon" method="post" style="display:none">
  <p><?php esc_html_e('If you have a coupon code, apply it below.', 'supreme-autoparts'); ?></p>
  <p class="form-row form-row-first">
    <label for="coupon_code" class="screen-reader-text"><?php esc_html_e('Coupon:', 'supreme-autoparts'); ?></label>
    <input type="text" name="coupon_code" class="input-text" placeholder="<?php esc_attr_e('Coupon code', 'supreme-autoparts'); ?>" id="coupon_code" value="" />
  </p>
  <p class="form-row form-row-last">
    <button type="submit" class="button sa-btn sa-btn--outline<?php echo esc_attr(wc_wp_theme_get_element_class_name('button') ? ' ' . wc_wp_theme_get_element_class_name('button') : ''); ?>" name="apply_coupon" value="<?php esc_attr_e('Apply coupon', 'supreme-autoparts'); ?>"><?php esc_html_e('Apply coupon', 'supreme-autoparts'); ?></button>
  </p>
  <div class="clear"></div>
</form>
