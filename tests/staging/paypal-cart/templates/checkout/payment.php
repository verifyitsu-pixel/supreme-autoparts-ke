<?php
defined('ABSPATH') || exit;
if (!defined('SA_SYNTHETIC_STAGING') || SA_SYNTHETIC_STAGING !== true) return;
if (!wp_doing_ajax()) do_action('woocommerce_review_order_before_payment');
?>
<div id="payment" class="woocommerce-checkout-payment sa-checkout-payment">
  <ul class="wc_payment_methods payment_methods methods">
    <li class="wc_payment_method payment_method_ppcp-gateway" aria-disabled="true">
      <input type="radio" disabled aria-disabled="true" id="sa_paypal_missing_keys" />
      <label for="sa_paypal_missing_keys"><strong>PayPal sandbox</strong></label>
      <p>Waiting for the sandbox client ID and secret. No PayPal request or payment can run.</p>
    </li>
  </ul>
  <p>Pesapal on the real store is unchanged. This isolated store cannot use its credentials, callbacks or orders.</p>
  <div class="form-row place-order sa-checkout-place">
    <?php wc_get_template('checkout/terms.php'); ?>
    <?php do_action('woocommerce_review_order_before_submit'); ?>
    <button type="button" class="button alt sa-btn" disabled aria-disabled="true">Waiting for sandbox credentials</button>
    <?php wp_nonce_field('woocommerce-process_checkout', 'woocommerce-process-checkout-nonce'); ?>
    <?php do_action('woocommerce_review_order_after_submit'); ?>
  </div>
</div>
<?php if (!wp_doing_ajax()) do_action('woocommerce_review_order_after_payment'); ?>
