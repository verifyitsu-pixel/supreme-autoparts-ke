<?php
/**
 * Checkout terms and conditions checkbox — required before place order.
 *
 * @package Supreme_Autoparts
 * @version 1.3.0
 */

defined('ABSPATH') || exit;

if (apply_filters('woocommerce_checkout_show_terms', true) && function_exists('wc_terms_and_conditions_checkbox_enabled') && wc_terms_and_conditions_checkbox_enabled()) :
    do_action('woocommerce_checkout_before_terms_and_conditions');
    ?>
  <div class="woocommerce-terms-and-conditions-wrapper sa-checkout-terms">
    <?php
    /**
     * Hook for terms page content (collapsed by Woo when configured).
     */
    do_action('woocommerce_checkout_terms_and_conditions');
    ?>

    <p class="form-row validate-required sa-checkout-terms__row">
      <label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
        <input type="checkbox"
          class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox"
          name="terms"
          <?php checked(apply_filters('woocommerce_terms_is_checked_default', isset($_POST['terms'])), true); // phpcs:ignore WordPress.Security.NonceVerification.Missing ?>
          id="terms"
          data-sa-terms-checkbox
          required
          aria-required="true" />
        <span class="woocommerce-terms-and-conditions-checkbox-text">
          <?php echo wp_kses_post(wc_terms_and_conditions_checkbox_text()); ?>
        </span>&nbsp;<abbr class="required" title="<?php esc_attr_e('required', 'supreme-autoparts'); ?>">*</abbr>
      </label>
      <input type="hidden" name="terms-field" value="1" />
    </p>
  </div>
    <?php
    do_action('woocommerce_checkout_after_terms_and_conditions');
endif;
