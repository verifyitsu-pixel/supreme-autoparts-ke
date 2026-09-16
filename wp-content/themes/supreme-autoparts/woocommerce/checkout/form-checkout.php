<?php
/**
 * Checkout form — multi-section contact / shipping / payment + sticky summary.
 *
 * @package Supreme_Autoparts
 * @version 1.3.0
 */

defined('ABSPATH') || exit;

$checkout = WC()->checkout();

do_action('woocommerce_before_checkout_form', $checkout);

if (!$checkout->is_registration_enabled() && $checkout->is_registration_required() && !is_user_logged_in()) {
    echo esc_html(apply_filters('woocommerce_checkout_must_be_logged_in_message', __('You must be logged in to checkout.', 'supreme-autoparts')));
    return;
}

$shop_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/shop/');
?>
<div class="sa-checkout-page">
  <header class="sa-checkout-hero">
    <p class="sa-checkout-hero__eyebrow"><?php esc_html_e('Secure checkout', 'supreme-autoparts'); ?></p>
    <h1 class="sa-checkout-hero__title"><?php esc_html_e('Checkout', 'supreme-autoparts'); ?></h1>
    <p class="sa-checkout-hero__lead">
      <?php esc_html_e('Enter your details, then pay by card.', 'supreme-autoparts'); ?>
    </p>
    <ol class="sa-checkout-steps" aria-label="<?php esc_attr_e('Checkout steps', 'supreme-autoparts'); ?>">
      <li class="sa-checkout-steps__item is-active"><span>1</span> <?php esc_html_e('Contact', 'supreme-autoparts'); ?></li>
      <li class="sa-checkout-steps__item"><span>2</span> <?php esc_html_e('Shipping', 'supreme-autoparts'); ?></li>
      <li class="sa-checkout-steps__item"><span>3</span> <?php esc_html_e('Payment', 'supreme-autoparts'); ?></li>
    </ol>
  </header>

  <form name="checkout" method="post" class="checkout woocommerce-checkout sa-checkout" action="<?php echo esc_url(wc_get_checkout_url()); ?>" enctype="multipart/form-data" novalidate>
    <div class="sa-checkout-layout">
      <div class="sa-checkout-layout__main">
        <?php if ($checkout->get_checkout_fields()) : ?>
          <?php do_action('woocommerce_checkout_before_customer_details'); ?>

          <section class="sa-checkout-section sa-checkout-section--contact" id="sa-checkout-contact" aria-labelledby="sa-checkout-contact-title">
            <header class="sa-checkout-section__head">
              <span class="sa-checkout-section__num" aria-hidden="true">1</span>
              <div>
                <h2 id="sa-checkout-contact-title" class="sa-checkout-section__title"><?php esc_html_e('Contact &amp; billing', 'supreme-autoparts'); ?></h2>
                <p class="sa-checkout-section__sub"><?php esc_html_e('We use this email for order confirmation and tracking updates.', 'supreme-autoparts'); ?></p>
              </div>
            </header>
            <div class="sa-checkout-section__body" id="customer_details">
              <div class="sa-checkout-customer__billing">
                <?php do_action('woocommerce_checkout_billing'); ?>
              </div>
            </div>
          </section>

          <section class="sa-checkout-section sa-checkout-section--shipping" id="sa-checkout-shipping" aria-labelledby="sa-checkout-shipping-title">
            <header class="sa-checkout-section__head">
              <span class="sa-checkout-section__num" aria-hidden="true">2</span>
              <div>
                <h2 id="sa-checkout-shipping-title" class="sa-checkout-section__title"><?php esc_html_e('Shipping', 'supreme-autoparts'); ?></h2>
                <p class="sa-checkout-section__sub"><?php esc_html_e('Where should we deliver your parts in Kenya or internationally?', 'supreme-autoparts'); ?></p>
              </div>
            </header>
            <div class="sa-checkout-section__body sa-checkout-customer__shipping">
              <?php do_action('woocommerce_checkout_shipping'); ?>
            </div>
          </section>

          <?php do_action('woocommerce_checkout_after_customer_details'); ?>
        <?php endif; ?>

        <section class="sa-checkout-section sa-checkout-section--payment sa-checkout-section--payment-mobile" id="sa-checkout-payment-note" aria-labelledby="sa-checkout-payment-title">
          <header class="sa-checkout-section__head">
            <span class="sa-checkout-section__num" aria-hidden="true">3</span>
            <div>
              <h2 id="sa-checkout-payment-title" class="sa-checkout-section__title"><?php esc_html_e('Payment', 'supreme-autoparts'); ?></h2>
              <p class="sa-checkout-section__sub"><?php esc_html_e('Pay by card. Charged in USD.', 'supreme-autoparts'); ?></p>
            </div>
          </header>
        </section>
      </div>

      <aside class="sa-checkout-layout__summary" aria-label="<?php esc_attr_e('Order summary', 'supreme-autoparts'); ?>">
        <?php do_action('woocommerce_checkout_before_order_review_heading'); ?>
        <div class="sa-checkout-summary__head">
          <h3 id="order_review_heading"><?php esc_html_e('Your order', 'supreme-autoparts'); ?></h3>
          <?php if ($shop_url) : ?>
            <a class="sa-checkout-summary__edit" href="<?php echo esc_url(wc_get_cart_url()); ?>"><?php esc_html_e('Edit cart', 'supreme-autoparts'); ?></a>
          <?php endif; ?>
        </div>
        <?php do_action('woocommerce_checkout_before_order_review'); ?>
        <div id="order_review" class="woocommerce-checkout-review-order sa-checkout-summary">
          <?php do_action('woocommerce_checkout_order_review'); ?>
        </div>
        <?php do_action('woocommerce_checkout_after_order_review'); ?>
      </aside>
    </div>
  </form>
</div>
<?php
do_action('woocommerce_after_checkout_form', $checkout);
