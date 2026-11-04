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
$sa_providers = ['whop' => ['Whop', 'whop.svg'], 'pesapal' => ['Pesapal', 'pesapal.png'], 'paystack' => ['Paystack', 'paystack.png']];
$sa_provider = $sa_providers[$gateway->id] ?? null;
$sa_title = $gateway->get_title();
if ($sa_provider) {
    $sa_title = preg_replace('/\s*[-–]\s*powered by\s+(Whop|Pesapal|Paystack)/i', '', $sa_title);
}
?>
<li class="wc_payment_method payment_method_<?php echo esc_attr($gateway->id); ?>">
  <input id="payment_method_<?php echo esc_attr($gateway->id); ?>" type="radio" class="input-radio" name="payment_method" value="<?php echo esc_attr($gateway->id); ?>" <?php checked($gateway->chosen, true); ?> data-order_button_text="<?php echo esc_attr($gateway->order_button_text); ?>" />

  <label for="payment_method_<?php echo esc_attr($gateway->id); ?>">
    <?php if ($sa_provider) : ?>
      <span class="sa-provider-brand"><img src="<?php echo esc_url(get_stylesheet_directory_uri() . '/assets/images/providers/' . $sa_provider[1]); ?>" alt="<?php echo esc_attr($sa_provider[0]); ?>" /></span>
    <?php endif; ?>
    <span class="sa-payment-method-title"><?php echo wp_kses_post($sa_title); ?></span>
    <span class="sa-payment-method-icons"><?php echo $gateway->get_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - gateway-generated brand logo markup (as in Woo core) ?></span>
  </label>
  <?php if ($gateway->has_fields() || $gateway->get_description()) : ?>
    <div class="payment_box payment_method_<?php echo esc_attr($gateway->id); ?>" <?php if (!$gateway->chosen) : ?>style="display:none;"<?php endif; ?>>
      <?php $gateway->payment_fields(); ?>
    </div>
  <?php endif; ?>
</li>
