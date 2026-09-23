<?php
/**
 * Lost password / email login code — OTP for customers.
 *
 * @package Supreme_Autoparts
 * @version 1.4.25
 */

defined('ABSPATH') || exit;

do_action('woocommerce_before_lost_password_form');

// If a code was just sent, show verify form here too.
if (function_exists('sa_core_otp_has_pending') && sa_core_otp_has_pending()) {
    if (function_exists('sa_core_render_otp_form')) {
        sa_core_render_otp_form();
    }
    do_action('woocommerce_after_lost_password_form');
    return;
}
?>

<div class="sa-account-auth sa-account-auth--lost">
  <section class="sa-account-auth__panel">
    <header class="sa-account-panel__head">
      <h2><?php esc_html_e('Email me a login code', 'supreme-autoparts'); ?></h2>
      <p class="sa-account-panel__lead">
        <?php esc_html_e('Enter your account email. We’ll email you a 6-digit code — enter it on the next screen to sign in. No password needed.', 'supreme-autoparts'); ?>
      </p>
    </header>

    <form method="post" class="woocommerce-ResetPassword lost_reset_password sa-form">
      <p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first">
        <label for="user_login"><?php esc_html_e('Username or email', 'supreme-autoparts'); ?>&nbsp;<span class="required">*</span></label>
        <input class="woocommerce-Input woocommerce-Input--text input-text" type="text" name="user_login" id="user_login" autocomplete="username" required />
      </p>

      <div class="clear"></div>

      <?php do_action('woocommerce_lostpassword_form'); ?>

      <p class="woocommerce-form-row form-row">
        <input type="hidden" name="wc_reset_password" value="true" />
        <button type="submit" class="woocommerce-Button button sa-btn<?php echo esc_attr(wc_wp_theme_get_element_class_name('button') ? ' ' . wc_wp_theme_get_element_class_name('button') : ''); ?>" value="<?php esc_attr_e('Email me a login code', 'supreme-autoparts'); ?>"><?php esc_html_e('Email me a login code', 'supreme-autoparts'); ?></button>
      </p>

      <?php wp_nonce_field('lost_password', 'woocommerce-lost-password-nonce'); ?>
    </form>

    <p class="sa-otp-back" style="margin-top:1rem;">
      <a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>"><?php esc_html_e('Back to log in', 'supreme-autoparts'); ?></a>
    </p>
  </section>
</div>
<?php
do_action('woocommerce_after_lost_password_form');
