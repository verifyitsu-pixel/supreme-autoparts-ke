<?php
/**
 * Checkout form — multi-section contact / shipping / payment + sticky summary.
 *
 * @package Supreme_Autoparts
 * @version 1.4.43
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
    <h1 class="sa-checkout-hero__title"><?php esc_html_e('Checkout', 'supreme-autoparts'); ?></h1>
    <p class="sa-checkout-hero__lead">
      <?php esc_html_e('Enter your details, then continue to payment.', 'supreme-autoparts'); ?>
    </p>
    <ol class="sa-checkout-steps" aria-label="<?php esc_attr_e('Checkout steps', 'supreme-autoparts'); ?>" data-sa-checkout-steps>
      <li class="sa-checkout-steps__item is-active" data-sa-step="contact"><span>1</span> <?php esc_html_e('Contact', 'supreme-autoparts'); ?></li>
      <li class="sa-checkout-steps__item" data-sa-step="shipping"><span>2</span> <?php esc_html_e('Shipping', 'supreme-autoparts'); ?></li>
      <li class="sa-checkout-steps__item" data-sa-step="payment"><span>3</span> <?php esc_html_e('Payment', 'supreme-autoparts'); ?></li>
    </ol>
  </header>

  <form name="checkout" method="post" class="checkout woocommerce-checkout sa-checkout" action="<?php echo esc_url(wc_get_checkout_url()); ?>" enctype="multipart/form-data" novalidate>
    <div class="sa-checkout-layout">
      <div class="sa-checkout-layout__main">
        <?php if ($checkout->get_checkout_fields()) : ?>
          <?php do_action('woocommerce_checkout_before_customer_details'); ?>

          <section class="sa-checkout-section sa-checkout-section--contact" id="sa-checkout-contact" data-sa-checkout-panel="contact" aria-labelledby="sa-checkout-contact-title">
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

          <section class="sa-checkout-section sa-checkout-section--shipping" id="sa-checkout-shipping" data-sa-checkout-panel="shipping" aria-labelledby="sa-checkout-shipping-title">
            <header class="sa-checkout-section__head">
              <span class="sa-checkout-section__num" aria-hidden="true">2</span>
              <div>
                <h2 id="sa-checkout-shipping-title" class="sa-checkout-section__title"><?php esc_html_e('Shipping', 'supreme-autoparts'); ?></h2>
                <p class="sa-checkout-section__sub"><?php esc_html_e('Enter your delivery address — continental US, Alaska/Hawaii, or international.', 'supreme-autoparts'); ?></p>
              </div>
            </header>
            <div class="sa-checkout-section__body sa-checkout-customer__shipping">
              <?php do_action('woocommerce_checkout_shipping'); ?>
            </div>
          </section>

          <?php do_action('woocommerce_checkout_after_customer_details'); ?>
        <?php endif; ?>

        <section class="sa-checkout-section sa-checkout-section--payment sa-checkout-section--payment-mobile" id="sa-checkout-payment-note" data-sa-checkout-panel="payment" aria-labelledby="sa-checkout-payment-title">
          <header class="sa-checkout-section__head">
            <span class="sa-checkout-section__num" aria-hidden="true">3</span>
            <div>
              <h2 id="sa-checkout-payment-title" class="sa-checkout-section__title"><?php esc_html_e('Payment', 'supreme-autoparts'); ?></h2>
              <p class="sa-checkout-section__sub"><?php esc_html_e('Review your order, accept policies, then pay by card.', 'supreme-autoparts'); ?></p>
            </div>
          </header>
        </section>
      </div>

      <aside class="sa-checkout-layout__summary" aria-label="<?php esc_attr_e('Order summary', 'supreme-autoparts'); ?>" data-sa-checkout-panel="payment">
        <?php do_action('woocommerce_checkout_before_order_review_heading'); ?>
        <div class="sa-checkout-summary__head">
          <h3 id="order_review_heading"><?php esc_html_e('Your order', 'supreme-autoparts'); ?></h3>
          <a class="sa-checkout-summary__edit" href="<?php echo esc_url(wc_get_cart_url()); ?>"><?php esc_html_e('Edit cart', 'supreme-autoparts'); ?></a>
        </div>
        <?php do_action('woocommerce_checkout_before_order_review'); ?>
        <div id="order_review" class="woocommerce-checkout-review-order sa-checkout-summary">
          <?php do_action('woocommerce_checkout_order_review'); ?>
        </div>
        <?php do_action('woocommerce_checkout_after_order_review'); ?>
      </aside>
    </div>

    <div class="sa-checkout-sticky-pay" data-sa-checkout-sticky hidden>
      <div class="sa-checkout-sticky-pay__total">
        <small><?php
          printf(
              /* translators: %s: currency code */
              esc_html__('Total (%s)', 'supreme-autoparts'),
              esc_html(function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD')
          );
        ?></small>
        <strong data-sa-checkout-sticky-total><?php echo wp_kses_post(WC()->cart ? WC()->cart->get_total() : ''); ?></strong>
      </div>
      <button type="button" class="button alt sa-btn sa-checkout-sticky-pay__btn" data-sa-checkout-sticky-pay>
        <?php esc_html_e('Place order', 'supreme-autoparts'); ?>
      </button>
    </div>
  </form>
</div>
<?php
do_action('woocommerce_after_checkout_form', $checkout);
