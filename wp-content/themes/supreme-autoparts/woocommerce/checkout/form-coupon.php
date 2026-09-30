<?php
/**
 * Checkout coupon form — compact chip toggle (Shopify-style).
 *
 * @package Supreme_Autoparts
 * @version 1.4.40
 */

defined('ABSPATH') || exit;

if (!wc_coupons_enabled()) {
    return;
}
?>
<div class="woocommerce-form-coupon-toggle sa-checkout-coupon-toggle">
  <a href="#" class="showcoupon sa-checkout-chip"><?php esc_html_e('Have a coupon?', 'supreme-autoparts'); ?></a>
</div>

<form class="checkout_coupon woocommerce-form-coupon sa-checkout-coupon" method="post" style="display:none" aria-label="<?php esc_attr_e('Coupon', 'supreme-autoparts'); ?>">
  <p class="sa-checkout-coupon__lead"><?php esc_html_e('If you have a coupon code, apply it below.', 'supreme-autoparts'); ?></p>
  <div class="sa-checkout-coupon__row">
    <label for="coupon_code" class="screen-reader-text"><?php esc_html_e('Coupon:', 'supreme-autoparts'); ?></label>
    <input type="text" name="coupon_code" class="input-text" placeholder="<?php esc_attr_e('Coupon code', 'supreme-autoparts'); ?>" id="coupon_code" value="" autocomplete="off" />
    <button type="submit" class="button sa-btn sa-btn--outline<?php echo esc_attr(wc_wp_theme_get_element_class_name('button') ? ' ' . wc_wp_theme_get_element_class_name('button') : ''); ?>" name="apply_coupon" value="<?php esc_attr_e('Apply coupon', 'supreme-autoparts'); ?>"><?php esc_html_e('Apply', 'supreme-autoparts'); ?></button>
  </div>
</form>
