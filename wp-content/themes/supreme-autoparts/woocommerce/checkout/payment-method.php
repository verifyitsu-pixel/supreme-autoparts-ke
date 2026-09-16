<?php
/**
 * Single payment method — Whop-friendly markup.
 *
 * @package Supreme_Autoparts
 * @version 1.3.0
 */

defined('ABSPATH') || exit;

if (!isset($gateway) || !is_object($gateway)) {
    return;
}
?>
<li class="wc_payment_method payment_method_<?php echo esc_attr($gateway->id); ?>">
  <input id="payment_method_<?php echo esc_attr($gateway->id); ?>" type="radio" class="input-radio" name="payment_method" value="<?php echo esc_attr($gateway->id); ?>" <?php checked($gateway->chosen, true); ?> data-order_button_text="<?php echo esc_attr($gateway->order_button_text); ?>" />

  <label for="payment_method_<?php echo esc_attr($gateway->id); ?>">
    <?php echo wp_kses_post($gateway->get_title()); ?> <?php echo wp_kses_post($gateway->get_icon()); ?>
  </label>
  <?php if ($gateway->has_fields() || $gateway->get_description()) : ?>
    <div class="payment_box payment_method_<?php echo esc_attr($gateway->id); ?>" <?php if (!$gateway->chosen) : ?>style="display:none;"<?php endif; ?>>
      <?php $gateway->payment_fields(); ?>
    </div>
  <?php endif; ?>
</li>
