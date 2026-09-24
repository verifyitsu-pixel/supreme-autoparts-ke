<?php
/**
 * Lost password form — we email a new working password (not a set-password link).
 *
 * @package Supreme_Autoparts
 * @version 1.4.29
 */

defined('ABSPATH') || exit;

do_action('woocommerce_before_lost_password_form');
?>

<div class="sa-account-auth sa-account-auth--lost">
  <section class="sa-account-auth__panel">
    <header class="sa-account-panel__head">
      <h2><?php esc_html_e('Email me a new password', 'supreme-autoparts'); ?></h2>
      <p class="sa-account-panel__lead">
        <?php esc_html_e('Enter the email on your account. We will set a new secure password and email it to you — it works right away. After you log in, you stay signed in on this browser until you tap Log out. You can change the password later under Account details.', 'supreme-autoparts'); ?>
      </p>
    </header>

    <form method="post" class="woocommerce-ResetPassword lost_reset_password sa-form">
      <p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first">
        <label for="user_login"><?php esc_html_e('Email or username', 'supreme-autoparts'); ?>&nbsp;<span class="required">*</span></label>
        <input class="woocommerce-Input woocommerce-Input--text input-text" type="text" name="user_login" id="user_login" autocomplete="username" required />
      </p>

      <div class="clear"></div>

      <?php do_action('woocommerce_lostpassword_form'); ?>

      <p class="woocommerce-form-row form-row">
        <input type="hidden" name="wc_reset_password" value="true" />
        <button type="submit" class="woocommerce-Button button sa-btn<?php echo esc_attr(wc_wp_theme_get_element_class_name('button') ? ' ' . wc_wp_theme_get_element_class_name('button') : ''); ?>" value="<?php esc_attr_e('Email me a new password', 'supreme-autoparts'); ?>"><?php esc_html_e('Email me a new password', 'supreme-autoparts'); ?></button>
      </p>

      <?php wp_nonce_field('lost_password', 'woocommerce-lost-password-nonce'); ?>
    </form>

    <p class="sa-lost-back">
      <a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>"><?php esc_html_e('Back to log in', 'supreme-autoparts'); ?></a>
    </p>
  </section>
</div>
<?php
do_action('woocommerce_after_lost_password_form');
