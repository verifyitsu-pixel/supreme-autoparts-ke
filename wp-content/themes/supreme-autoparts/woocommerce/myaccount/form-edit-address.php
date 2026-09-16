<?php
/**
 * Edit address form — billing / shipping.
 *
 * @package Supreme_Autoparts
 * @version 9.3.0
 */

defined('ABSPATH') || exit;

$page_title = ('billing' === $load_address)
    ? esc_html__('Billing address', 'supreme-autoparts')
    : esc_html__('Shipping address', 'supreme-autoparts');

do_action('woocommerce_before_edit_account_address_form');
?>

<?php if (!$load_address) : ?>
  <?php wc_get_template('myaccount/my-address.php'); ?>
<?php else : ?>
  <div class="sa-account-panel sa-edit-address">
    <header class="sa-account-panel__head">
      <h2><?php echo esc_html(apply_filters('woocommerce_my_account_edit_address_title', $page_title, $load_address)); ?></h2>
      <p class="sa-account-panel__lead">
        <?php esc_html_e('Keep your details current for faster checkout and accurate invoices.', 'supreme-autoparts'); ?>
      </p>
      <p class="sa-edit-address__back">
        <a href="<?php echo esc_url(wc_get_account_endpoint_url('edit-address')); ?>">
          &larr; <?php esc_html_e('Back to addresses', 'supreme-autoparts'); ?>
        </a>
      </p>
    </header>

    <form method="post" class="sa-form sa-edit-address__form" novalidate>
      <div class="woocommerce-address-fields">
        <?php do_action("woocommerce_before_edit_address_form_{$load_address}"); ?>

        <div class="woocommerce-address-fields__field-wrapper sa-edit-address__fields">
          <?php
          foreach ($address as $key => $field) {
              woocommerce_form_field($key, $field, wc_get_post_data_by_key($key, $field['value']));
          }
          ?>
        </div>

        <?php do_action("woocommerce_after_edit_address_form_{$load_address}"); ?>

        <p class="sa-form__submit">
          <button type="submit" class="button sa-btn" name="save_address" value="<?php esc_attr_e('Save address', 'supreme-autoparts'); ?>">
            <?php esc_html_e('Save address', 'supreme-autoparts'); ?>
          </button>
          <?php wp_nonce_field('woocommerce-edit_address', 'woocommerce-edit-address-nonce'); ?>
          <input type="hidden" name="action" value="edit_address" />
        </p>
      </div>
    </form>
  </div>
<?php endif; ?>

<?php do_action('woocommerce_after_edit_account_address_form'); ?>
