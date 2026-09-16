<?php
/**
 * My Account — professional dashboard shell.
 *
 * @package Supreme_Autoparts
 */

defined('ABSPATH') || exit;
?>
<div class="sa-my-account">
  <aside class="sa-my-account__nav">
    <?php do_action('woocommerce_account_navigation'); ?>
  </aside>
  <div class="woocommerce-MyAccount-content sa-my-account__content">
    <?php do_action('woocommerce_account_content'); ?>
  </div>
</div>
