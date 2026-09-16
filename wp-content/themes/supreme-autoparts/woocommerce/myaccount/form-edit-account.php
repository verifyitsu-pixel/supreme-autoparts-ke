<?php
/**
 * Edit account form — polished account details.
 *
 * @package Supreme_Autoparts
 * @version 9.7.0
 */

defined('ABSPATH') || exit;

do_action('woocommerce_before_edit_account_form');
?>
<div class="sa-account-panel sa-edit-account">
  <header class="sa-account-panel__head">
    <h2><?php esc_html_e('Account details', 'supreme-autoparts'); ?></h2>
    <p class="sa-account-panel__lead"><?php esc_html_e('Update your name, email, and password. Changes sync to your store profile.', 'supreme-autoparts'); ?></p>
  </header>

  <form class="woocommerce-EditAccountForm edit-account sa-form" action="" method="post" <?php do_action('woocommerce_edit_account_form_tag'); ?>>
    <?php do_action('woocommerce_edit_account_form_start'); ?>

    <div class="sa-form__row sa-form__row--2">
      <p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first">
        <label for="account_first_name"><?php esc_html_e('First name', 'supreme-autoparts'); ?>&nbsp;<span class="required">*</span></label>
        <input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="account_first_name" id="account_first_name" autocomplete="given-name" value="<?php echo esc_attr($user->first_name); ?>" required />
      </p>
      <p class="woocommerce-form-row woocommerce-form-row--last form-row form-row-last">
        <label for="account_last_name"><?php esc_html_e('Last name', 'supreme-autoparts'); ?>&nbsp;<span class="required">*</span></label>
        <input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="account_last_name" id="account_last_name" autocomplete="family-name" value="<?php echo esc_attr($user->last_name); ?>" required />
      </p>
    </div>

    <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
      <label for="account_display_name"><?php esc_html_e('Display name', 'supreme-autoparts'); ?>&nbsp;<span class="required">*</span></label>
      <input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="account_display_name" id="account_display_name" value="<?php echo esc_attr($user->display_name); ?>" required />
      <span class="sa-form__hint"><?php esc_html_e('This is how your name appears on the site.', 'supreme-autoparts'); ?></span>
    </p>

    <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
      <label for="account_email"><?php esc_html_e('Email address', 'supreme-autoparts'); ?>&nbsp;<span class="required">*</span></label>
      <input type="email" class="woocommerce-Input woocommerce-Input--email input-text" name="account_email" id="account_email" autocomplete="email" value="<?php echo esc_attr($user->user_email); ?>" required />
    </p>

    <fieldset class="sa-form__fieldset">
      <legend><?php esc_html_e('Password change', 'supreme-autoparts'); ?></legend>
      <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
        <label for="password_current"><?php esc_html_e('Current password (leave blank to keep)', 'supreme-autoparts'); ?></label>
        <input type="password" class="woocommerce-Input woocommerce-Input--password input-text" name="password_current" id="password_current" autocomplete="current-password" />
      </p>
      <div class="sa-form__row sa-form__row--2">
        <p class="woocommerce-form-row form-row">
          <label for="password_1"><?php esc_html_e('New password', 'supreme-autoparts'); ?></label>
          <input type="password" class="woocommerce-Input woocommerce-Input--password input-text" name="password_1" id="password_1" autocomplete="new-password" />
        </p>
        <p class="woocommerce-form-row form-row">
          <label for="password_2"><?php esc_html_e('Confirm new password', 'supreme-autoparts'); ?></label>
          <input type="password" class="woocommerce-Input woocommerce-Input--password input-text" name="password_2" id="password_2" autocomplete="new-password" />
        </p>
      </div>
    </fieldset>

    <?php do_action('woocommerce_edit_account_form'); ?>

    <p class="sa-form__submit">
      <?php wp_nonce_field('save_account_details', 'save-account-details-nonce'); ?>
      <button type="submit" class="woocommerce-Button button sa-btn" name="save_account_details" value="<?php esc_attr_e('Save changes', 'supreme-autoparts'); ?>"><?php esc_html_e('Save changes', 'supreme-autoparts'); ?></button>
      <input type="hidden" name="action" value="save_account_details" />
    </p>

    <?php do_action('woocommerce_edit_account_form_end'); ?>
  </form>
</div>
<?php
do_action('woocommerce_after_edit_account_form');
