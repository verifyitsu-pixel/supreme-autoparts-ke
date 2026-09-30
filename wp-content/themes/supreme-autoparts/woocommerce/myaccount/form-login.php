<?php
/**
 * My Account login / register — OTP for returning guests; password still available.
 *
 * @package Supreme_Autoparts
 * @version 1.4.45
 */

defined('ABSPATH') || exit;

do_action('woocommerce_before_customer_login_form');

$sa_otp_step = isset($_GET['sa_otp']) && (string) $_GET['sa_otp'] === '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$sa_otp_email = '';
if (!empty($_GET['sa_email'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $sa_otp_email = sanitize_email(wp_unslash((string) $_GET['sa_email'])); // phpcs:ignore
}
if ($sa_otp_email === '' && !empty($_POST['sa_otp_email'])) { // phpcs:ignore
    $sa_otp_email = sanitize_email(wp_unslash((string) $_POST['sa_otp_email'])); // phpcs:ignore
}
?>
<div class="sa-account-auth" id="customer_login">
  <div class="sa-account-auth__grid<?php echo ('yes' === get_option('woocommerce_enable_myaccount_registration')) ? ' sa-account-auth__grid--2' : ''; ?>">

    <section class="sa-account-auth__panel sa-account-auth__login">
      <header class="sa-account-panel__head">
        <h2><?php esc_html_e('Log in', 'supreme-autoparts'); ?></h2>
        <p class="sa-account-panel__lead"><?php esc_html_e('Ordered as a guest? Enter your email and we will send a one-time login code. Or use your password if you already have one.', 'supreme-autoparts'); ?></p>
      </header>

      <div class="sa-otp-block" data-sa-otp>
        <h3 class="sa-otp-block__title"><?php esc_html_e('Email me a login code', 'supreme-autoparts'); ?></h3>
        <p class="sa-otp-block__hint"><?php esc_html_e('Best for returning guests — 6-digit code, expires in 10 minutes. No password needed.', 'supreme-autoparts'); ?></p>
        <p class="sa-login-persist-note" role="note">
          <?php esc_html_e('You will stay signed in on this browser for months until you use Log out.', 'supreme-autoparts'); ?>
        </p>

        <?php if (!$sa_otp_step) : ?>
          <form class="woocommerce-form sa-form sa-otp-form" method="post" novalidate>
            <p class="woocommerce-form-row form-row form-row-wide">
              <label for="sa_otp_email"><?php esc_html_e('Email address', 'supreme-autoparts'); ?>&nbsp;<span class="required">*</span></label>
              <input type="email" class="woocommerce-Input input-text" name="sa_otp_email" id="sa_otp_email" autocomplete="email" value="<?php echo esc_attr($sa_otp_email); ?>" required />
            </p>
            <?php wp_nonce_field('sa_otp_login', 'sa_otp_nonce'); ?>
            <p class="form-row">
              <button type="submit" class="woocommerce-button button sa-btn" name="sa_otp_request" value="1"><?php esc_html_e('Email me a login code', 'supreme-autoparts'); ?></button>
            </p>
          </form>
        <?php else : ?>
          <form class="woocommerce-form sa-form sa-otp-form sa-otp-form--verify" method="post" novalidate>
            <p class="woocommerce-form-row form-row form-row-wide">
              <label for="sa_otp_email_v"><?php esc_html_e('Email address', 'supreme-autoparts'); ?></label>
              <input type="email" class="woocommerce-Input input-text" name="sa_otp_email" id="sa_otp_email_v" autocomplete="email" value="<?php echo esc_attr($sa_otp_email); ?>" required />
            </p>
            <p class="woocommerce-form-row form-row form-row-wide">
              <label for="sa_otp_code"><?php esc_html_e('6-digit code', 'supreme-autoparts'); ?>&nbsp;<span class="required">*</span></label>
              <input type="text" class="woocommerce-Input input-text sa-otp-code" name="sa_otp_code" id="sa_otp_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required />
            </p>
            <?php wp_nonce_field('sa_otp_login', 'sa_otp_nonce'); ?>
            <p class="form-row sa-otp-actions">
              <button type="submit" class="woocommerce-button button sa-btn" name="sa_otp_verify" value="1"><?php esc_html_e('Verify &amp; log in', 'supreme-autoparts'); ?></button>
              <button type="submit" class="woocommerce-button button sa-btn sa-btn--outline" name="sa_otp_request" value="1"><?php esc_html_e('Resend code', 'supreme-autoparts'); ?></button>
            </p>
            <p class="sa-otp-back">
              <a href="<?php echo esc_url(function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/')); ?>"><?php esc_html_e('Start over', 'supreme-autoparts'); ?></a>
            </p>
          </form>
        <?php endif; ?>
      </div>

      <details class="sa-password-login">
        <summary><?php esc_html_e('Or log in with password', 'supreme-autoparts'); ?></summary>
        <form class="woocommerce-form woocommerce-form-login login sa-form" method="post" novalidate>
          <?php do_action('woocommerce_login_form_start'); ?>

          <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
            <label for="username"><?php esc_html_e('Email or username', 'supreme-autoparts'); ?>&nbsp;<span class="required">*</span></label>
            <input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="username" id="username" autocomplete="username" value="<?php echo (!empty($_POST['username'])) ? esc_attr(wp_unslash($_POST['username'])) : ''; ?>" /><?php // phpcs:ignore WordPress.Security.NonceVerification.Missing ?>
          </p>
          <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
            <label for="password"><?php esc_html_e('Password', 'supreme-autoparts'); ?>&nbsp;<span class="required">*</span></label>
            <input class="woocommerce-Input woocommerce-Input--text input-text" type="password" name="password" id="password" autocomplete="current-password" />
          </p>

          <?php do_action('woocommerce_login_form'); ?>

          <p class="sa-login-persist-note" role="note">
            <?php esc_html_e('You will stay signed in on this browser for months until you use Log out.', 'supreme-autoparts'); ?>
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
      </details>
    </section>

    <?php if ('yes' === get_option('woocommerce_enable_myaccount_registration')) : ?>
      <section class="sa-account-auth__panel sa-account-auth__register">
        <header class="sa-account-panel__head">
          <h2><?php esc_html_e('Create account', 'supreme-autoparts'); ?></h2>
          <p class="sa-account-panel__lead"><?php esc_html_e('Save your cart and track orders across devices. No password to choose — we email one. Guest checkout also creates an account after you order (login code next time).', 'supreme-autoparts'); ?></p>
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
            <?php esc_html_e('We will email you a secure password that works right away. Prefer checkout as a guest? We will still link your order and you can sign in later with a login code.', 'supreme-autoparts'); ?>
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
