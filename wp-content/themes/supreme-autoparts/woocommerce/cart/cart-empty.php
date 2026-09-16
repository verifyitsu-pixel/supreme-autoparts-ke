<?php
/**
 * Empty cart — shop link + enquire CTA to /enquire/.
 *
 * @package Supreme_Autoparts
 */

defined('ABSPATH') || exit;

// Prefer our CTA card over Woo's bare "empty cart" notice.
remove_action('woocommerce_cart_is_empty', 'wc_empty_cart_message', 10);
do_action('woocommerce_cart_is_empty');

$enquire_url = function_exists('sa_enquire_page_url') ? sa_enquire_page_url() : home_url('/enquire/');
$shop_url    = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/shop/');
?>
<div class="sa-cart-empty">
  <div class="sa-cart-empty__card">
    <p class="sa-cart-empty__eyebrow"><?php esc_html_e('Your cart is empty', 'supreme-autoparts'); ?></p>
    <h1 class="sa-cart-empty__title"><?php esc_html_e('Nothing to checkout yet', 'supreme-autoparts'); ?></h1>
    <p class="sa-cart-empty__lead">
      <?php esc_html_e('Browse the catalogue for parts in stock, or enquire if you need something we have not listed yet.', 'supreme-autoparts'); ?>
    </p>
    <div class="sa-cart-empty__actions">
      <?php if ($shop_url) : ?>
        <a class="button sa-btn wc-backward" href="<?php echo esc_url($shop_url); ?>">
          <?php esc_html_e('Continue shopping', 'supreme-autoparts'); ?>
        </a>
      <?php endif; ?>
      <a class="button sa-btn sa-btn--outline" href="<?php echo esc_url($enquire_url); ?>">
        <?php esc_html_e('Enquire for a part', 'supreme-autoparts'); ?>
      </a>
    </div>
  </div>
</div>
