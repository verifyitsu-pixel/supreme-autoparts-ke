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
    <div class="sa-cart-empty__icon" aria-hidden="true">
      <svg width="40" height="40" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M7 6h14l-1.4 7H8.2L7 6Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
        <path d="M7 6 6.2 3H3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
        <circle cx="9.5" cy="19" r="1.4" fill="currentColor"/>
        <circle cx="17.5" cy="19" r="1.4" fill="currentColor"/>
      </svg>
    </div>
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
