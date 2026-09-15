<?php
/**
 * My Account page override.
 *
 * @package Supreme_Autoparts
 * @version 3.5.0
 */

defined('ABSPATH') || exit;
?>
<div class="sa-my-account">
  <?php
  do_action('woocommerce_account_navigation');
  ?>
  <div class="woocommerce-MyAccount-content">
    <?php do_action('woocommerce_account_content'); ?>
  </div>
</div>
