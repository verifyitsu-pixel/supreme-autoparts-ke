<?php
/**
 * My Account login / register — stay signed in until Log out; password emailed on register.
 *
 * @package Supreme_Autoparts
 * @version 1.4.30
 */

defined('ABSPATH') || exit;

do_action('woocommerce_before_customer_login_form');
?>
<div class="sa-account-auth" id="customer_login">
  <div class="sa-account-auth__grid<?php echo ('yes' === get_option('woocommerce_enable_myaccount_registration')) ? ' sa-account-auth__grid--2' : ''; ?>">

    <section class="sa-account-auth__panel sa-account-auth__login">
      <header class="sa-account-panel__head">
        <h2><?php esc_html_e('Log in', 'supreme-autoparts'); ?></h2>
        <p class="sa-account-panel__lead"><?php esc_html_e('Access orders, invoices, and your saved cart on any device. You stay signed in on this browser until you tap Log out.', 'supreme-autoparts'); ?></p>
      </header>

      <form class="woocommerce-form woocommerce-form-login login sa-form" method="post" novalidate>
        <?php do_action('woocommerce_login_form_start'); ?>

        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
          <label for="username"><?php esc_html_e('Email or username', 'supreme-autoparts'); ?>&nbsp;<span class="required">*</span></label>
          <input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="username" id="username" autocomplete="username" value="<?php echo (!empty($_POST['username'])) ? esc_attr(wp_unslash($_POST['username'])) : ''; ?>" required /><?php // phpcs:ignore WordPress.Security.NonceVerification.Missing ?>
        </p>
        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
          <label for="password"><?php esc_html_e('Password', 'supreme-autoparts'); ?>&nbsp;<span class="required">*</span></label>
          <input class="woocommerce-Input woocommerce-Input--text input-text" type="password" name="password" id="password" autocomplete="current-password" required />
        </p>

        <?php do_action('woocommerce_login_form'); ?>

        <p class="sa-login-persist-note" role="note">
          <?php esc_html_e('You will stay signed in on this browser until you use Log out.', 'supreme-autoparts'); ?>
        </p>

        <p class="form-row sa-form__remember">
          <?php wp_nonce_field('woocommerce-login', 'woocommerce-login-nonce'); ?>
          <button type="submit" class="woocommerce-button button sa-btn woocommerce-form-login__submit<?php echo esc_attr(wc_wp_theme_get_element_class_name('button') ? ' ' . wc_wp_theme_get_element_class_name('button') : ''); ?>" name="login" value="<?php esc_attr_e('Log in', 'supreme-autoparts'); ?>"><?php esc_html_e('Log in', 'supreme-autoparts'); ?></button>
        </p>
        <p class="woocommerce-LostPassword lost_password">
          <a href="<?php echo esc_url(wp_lostpassword_url()); ?>"><?php esc_html_e('Email me a new password', 'supreme-autoparts'); ?></a>
        </p>

        <?php do_action('woocommerce_login_form_end'); ?>
      </form>
    </section>

    <?php if ('yes' === get_option('woocommerce_enable_myaccount_registration')) : ?>
      <section class="sa-account-auth__panel sa-account-auth__register">
        <header class="sa-account-panel__head">
          <h2><?php esc_html_e('Create account', 'supreme-autoparts'); ?></h2>
          <p class="sa-account-panel__lead"><?php esc_html_e('Save your cart and track orders across phones and laptops. No password to choose — we email one to you.', 'supreme-autoparts'); ?></p>
        </header>

        <form method="post" class="woocommerce-form woocommerce-form-register register sa-form" <?php do_action('woocommerce_register_form_tag'); ?> novalidate>
          <?php do_action('woocommerce_register_form_start'); ?>

          <?php if ('no' === get_option('woocommerce_registration_generate_username')) : ?>
            <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
              <label for="reg_username"><?php esc_html_e('Username', 'supreme-autoparts'); ?>&nbsp;<span class="required">*</span></label>
              <input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="username" id="reg_username" autocomplete="username" value="<?php echo (!empty($_POST['username'])) ? esc_attr(wp_unslash($_POST['username'])) : ''; ?>" required /><?php // phpcs:ignore ?>
            </p>
          <?php endif; ?>

          <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
            <label for="reg_email"><?php esc_html_e('Email address', 'supreme-autoparts'); ?>&nbsp;<span class="required">*</span></label>
            <input type="email" class="woocommerce-Input woocommerce-Input--text input-text" name="email" id="reg_email" autocomplete="email" value="<?php echo (!empty($_POST['email'])) ? esc_attr(wp_unslash($_POST['email'])) : ''; ?>" required /><?php // phpcs:ignore ?>
          </p>

          <p class="sa-register-password-note" role="note">
            <?php esc_html_e('We will email you a secure password that works right away. Check inbox and spam, then log in. After you log in, you stay signed in on this browser until you tap Log out. You can change the password anytime under Account details.', 'supreme-autoparts'); ?>
          </p>

          <?php do_action('woocommerce_register_form'); ?>

          <p class="woocommerce-form-row form-row">
            <?php wp_nonce_field('woocommerce-register', 'woocommerce-register-nonce'); ?>
            <button type="submit" class="woocommerce-Button woocommerce-button button sa-btn<?php echo esc_attr(wc_wp_theme_get_element_class_name('button') ? ' ' . wc_wp_theme_get_element_class_name('button') : ''); ?> woocommerce-form-register__submit" name="register" value="<?php esc_attr_e('Create account', 'supreme-autoparts'); ?>"><?php esc_html_e('Create account', 'supreme-autoparts'); ?></button>
          </p>

          <?php do_action('woocommerce_register_form_end'); ?>
        </form>
      </section>
    <?php endif; ?>

  </div>
</div>
<?php
do_action('woocommerce_after_customer_login_form');
