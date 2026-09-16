<?php
/**
 * My Addresses — billing + shipping cards.
 *
 * @package Supreme_Autoparts
 * @version 9.3.0
 */

defined('ABSPATH') || exit;

$customer_id = get_current_user_id();

if (!wc_ship_to_billing_address_only() && wc_shipping_enabled()) {
    $get_addresses = apply_filters(
        'woocommerce_my_account_get_addresses',
        [
            'billing'  => __('Billing address', 'supreme-autoparts'),
            'shipping' => __('Shipping address', 'supreme-autoparts'),
        ],
        $customer_id
    );
} else {
    $get_addresses = apply_filters(
        'woocommerce_my_account_get_addresses',
        [
            'billing' => __('Billing address', 'supreme-autoparts'),
        ],
        $customer_id
    );
}
?>
<div class="sa-account-panel sa-addresses">
  <header class="sa-account-panel__head">
    <h2><?php esc_html_e('Addresses', 'supreme-autoparts'); ?></h2>
    <p class="sa-account-panel__lead">
      <?php echo esc_html(apply_filters('woocommerce_my_account_my_address_description', __('These addresses are used at checkout by default.', 'supreme-autoparts'))); ?>
    </p>
  </header>

  <div class="sa-addresses__grid woocommerce-Addresses addresses<?php echo (!wc_ship_to_billing_address_only() && wc_shipping_enabled()) ? ' col2-set' : ''; ?>">
    <?php foreach ($get_addresses as $name => $address_title) :
        $address = wc_get_account_formatted_address($name);
        $edit_url = wc_get_endpoint_url('edit-address', $name);
        ?>
      <section class="sa-address-card woocommerce-Address">
        <header class="sa-address-card__head woocommerce-Address-title title">
          <h3><?php echo esc_html($address_title); ?></h3>
          <a class="sa-btn sa-btn--outline sa-btn--sm" href="<?php echo esc_url($edit_url); ?>">
            <?php
            echo $address
                ? esc_html__('Edit', 'supreme-autoparts')
                : esc_html__('Add', 'supreme-autoparts');
            ?>
          </a>
        </header>
        <address class="sa-address-card__body">
          <?php
          if ($address) {
              echo wp_kses_post($address);
          } else {
              echo '<span class="sa-address-card__empty">' . esc_html__('Not set yet.', 'supreme-autoparts') . '</span>';
          }
          do_action('woocommerce_my_account_after_my_address', $name);
          ?>
        </address>
      </section>
    <?php endforeach; ?>
  </div>
</div>
