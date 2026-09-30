<?php
/**
 * Checkout login form — compact chip toggle; stay signed in until Log out.
 *
 * @package Supreme_Autoparts
 * @version 1.4.40
 */

defined('ABSPATH') || exit;

if (is_user_logged_in() || 'no' === get_option('woocommerce_enable_checkout_login_reminder')) {
    return;
}
?>
<div class="woocommerce-form-login-toggle sa-checkout-login-toggle">
  <a href="#" class="showlogin sa-checkout-chip"><?php esc_html_e('Returning customer? Log in', 'supreme-autoparts'); ?></a>
</div>

<form class="woocommerce-form woocommerce-form-login login sa-checkout-login" method="post" style="display:none;" aria-label="<?php esc_attr_e('Log in', 'supreme-autoparts'); ?>">
  <p class="sa-checkout-login__lead">
    <?php esc_html_e('Log in to use your saved addresses and cart across devices. You stay signed in on this browser until you tap Log out.', 'supreme-autoparts'); ?>
  </p>

  <?php do_action('woocommerce_login_form_start'); ?>

  <p class="form-row form-row-first">
    <label for="username"><?php esc_html_e('Email or username', 'supreme-autoparts'); ?>&nbsp;<span class="required">*</span></label>
    <input type="text" class="input-text" name="username" id="username" autocomplete="username" required />
  </p>
  <p class="form-row form-row-last">
    <label for="password"><?php esc_html_e('Password', 'supreme-autoparts'); ?>&nbsp;<span class="required">*</span></label>
    <input class="input-text woocommerce-Input" type="password" name="password" id="password" autocomplete="current-password" required />
  </p>
  <div class="clear"></div>

  <?php do_action('woocommerce_login_form'); ?>

  <p class="sa-login-persist-note" role="note">
    <?php esc_html_e('You will stay signed in on this browser until you use Log out.', 'supreme-autoparts'); ?>
  </p>

  <p class="form-row">
    <?php wp_nonce_field('woocommerce-login', 'woocommerce-login-nonce'); ?>
    <input type="hidden" name="redirect" value="<?php echo esc_url(wc_get_checkout_url()); ?>" />
    <button type="submit" class="woocommerce-button button sa-btn woocommerce-form-login__submit<?php echo esc_attr(wc_wp_theme_get_element_class_name('button') ? ' ' . wc_wp_theme_get_element_class_name('button') : ''); ?>" name="login" value="<?php esc_attr_e('Log in', 'supreme-autoparts'); ?>"><?php esc_html_e('Log in', 'supreme-autoparts'); ?></button>
  </p>
  <p class="lost_password">
    <a href="<?php echo esc_url(wp_lostpassword_url()); ?>"><?php esc_html_e('Email me a new password', 'supreme-autoparts'); ?></a>
  </p>

  <?php do_action('woocommerce_login_form_end'); ?>

  <div class="clear"></div>
</form>
