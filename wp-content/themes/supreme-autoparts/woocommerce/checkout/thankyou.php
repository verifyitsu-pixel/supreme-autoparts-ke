<?php
/**
 * Order received / thank you — confirmation, next steps, WhatsApp support.
 *
 * Whop return (?wc-api=whop_return) redirects here via get_checkout_order_received_url().
 *
 * @package Supreme_Autoparts
 * @version 1.3.0
 */

defined('ABSPATH') || exit;

$shop_url    = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/shop/');
$account_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/');
$contact     = function_exists('sa_enquire_contact') ? sa_enquire_contact() : ['whatsapp' => '19174375121', 'phone_display' => '+1 917 437 5121'];
$wa_url      = function_exists('sa_whatsapp_url') ? sa_whatsapp_url() : 'https://wa.me/supremeautopart';
$wa_display  = (string) ($contact['phone_display'] ?? '+1 917 437 5121');
$enquire_url = function_exists('sa_enquire_page_url') ? sa_enquire_page_url() : home_url('/enquire/');
?>
<div class="woocommerce-order sa-thankyou">
  <?php if ($order) : ?>
    <?php do_action('woocommerce_before_thankyou', $order->get_id()); ?>

    <?php if ($order->has_status('failed')) : ?>
      <div class="sa-thankyou__card sa-thankyou__card--failed">
        <p class="sa-thankyou__eyebrow"><?php esc_html_e('Payment issue', 'supreme-autoparts'); ?></p>
        <h1 class="sa-thankyou__title"><?php esc_html_e('Order not completed', 'supreme-autoparts'); ?></h1>
        <p class="sa-thankyou__lead">
          <?php esc_html_e('Unfortunately your order cannot be processed as the payment was declined or cancelled. Please try again or contact us for help.', 'supreme-autoparts'); ?>
        </p>
        <div class="sa-thankyou__actions">
          <a class="sa-btn" href="<?php echo esc_url($order->get_checkout_payment_url()); ?>">
            <?php esc_html_e('Try payment again', 'supreme-autoparts'); ?>
          </a>
          <a class="sa-btn sa-btn--outline" href="<?php echo esc_url($wa_url); ?>" target="_blank" rel="noopener noreferrer">
            <?php esc_html_e('WhatsApp support', 'supreme-autoparts'); ?>
          </a>
        </div>
      </div>
    <?php else : ?>
      <?php
      $paid     = $order->is_paid() || $order->has_status(['processing', 'completed', 'ready-pickup', 'shipped', 'out-for-delivery', 'delivered']);
      $is_pickup = false;
      foreach ($order->get_shipping_methods() as $sa_sm) {
          if (strpos((string) $sa_sm->get_method_id(), 'local_pickup') === 0) {
              $is_pickup = true;
          }
      }
      $pending  = $order->has_status(['pending', 'on-hold']);
      $eyebrow  = $paid ? __('Order confirmed', 'supreme-autoparts') : __('Order received', 'supreme-autoparts');
      $heading  = $paid
          ? __('Thank you — payment received', 'supreme-autoparts')
          : __('Thank you — we have your order', 'supreme-autoparts');
      $lead = $paid
          ? __('Your order is confirmed and a copy is on its way to your email. Check inbox and spam. We will prepare your parts and keep you updated by email.', 'supreme-autoparts')
          : __('If you just paid, confirmation can take a few seconds. Refresh this page if the status does not update.', 'supreme-autoparts');
      ?>
      <div class="sa-thankyou__card<?php echo $paid ? ' sa-thankyou__card--success' : ''; ?>">
        <div class="sa-thankyou__badge" aria-hidden="true">
          <?php if ($paid) : ?>
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 13l4 4L19 7"/></svg>
          <?php else : ?>
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
          <?php endif; ?>
        </div>
        <p class="sa-thankyou__eyebrow"><?php echo esc_html($eyebrow); ?></p>
        <h1 class="sa-thankyou__title"><?php echo esc_html($heading); ?></h1>
        <p class="sa-thankyou__lead"><?php echo esc_html($lead); ?></p>

        <dl class="sa-thankyou__meta">
          <div>
            <dt><?php esc_html_e('Order number', 'supreme-autoparts'); ?></dt>
            <dd><strong>#<?php echo esc_html($order->get_order_number()); ?></strong></dd>
          </div>
          <div>
            <dt><?php esc_html_e('Date', 'supreme-autoparts'); ?></dt>
            <dd><?php echo esc_html(wc_format_datetime($order->get_date_created())); ?></dd>
          </div>
          <div>
            <dt><?php esc_html_e('Email', 'supreme-autoparts'); ?></dt>
            <dd><?php echo esc_html($order->get_billing_email()); ?></dd>
          </div>
          <div>
            <dt><?php esc_html_e('Total', 'supreme-autoparts'); ?></dt>
            <dd><?php echo wp_kses_post($order->get_formatted_order_total()); ?></dd>
          </div>
          <?php if ($order->get_payment_method_title()) : ?>
            <div>
              <dt><?php esc_html_e('Payment', 'supreme-autoparts'); ?></dt>
              <dd><?php echo esc_html($order->get_payment_method_title()); ?></dd>
            </div>
          <?php endif; ?>
          <div>
            <dt><?php esc_html_e('Status', 'supreme-autoparts'); ?></dt>
            <dd><span class="sa-status sa-status--<?php echo esc_attr($order->get_status()); ?>"><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></span></dd>
          </div>
        </dl>

        <section class="sa-thankyou__next" aria-labelledby="sa-thankyou-next-title">
          <h2 id="sa-thankyou-next-title"><?php esc_html_e('What happens next', 'supreme-autoparts'); ?></h2>
          <ol class="sa-thankyou__steps">
            <li><?php esc_html_e('We verify payment and pick your parts.', 'supreme-autoparts'); ?></li>
            <?php if ($is_pickup) : ?>
              <li><?php echo $order->has_status('ready-pickup') ? esc_html__('Your order is ready. Show your pickup code at the store; it is in your email.', 'supreme-autoparts') : esc_html__('We email you a pickup code when your order is ready at the store.', 'supreme-autoparts'); ?></li>
            <?php else : ?>
              <li><?php esc_html_e('You receive packing and shipping updates by email.', 'supreme-autoparts'); ?></li>
            <?php endif; ?>
            <li><?php esc_html_e('Need fitment help?', 'supreme-autoparts'); ?> <a href="<?php echo esc_url($wa_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Message us on WhatsApp', 'supreme-autoparts'); ?></a> <?php esc_html_e('with your order number.', 'supreme-autoparts'); ?></li>
          </ol>
        </section>

      </div>

      <?php /* Rock 2026-10-17 13:14: this page is confirmation only. The full order
                 details, receipt and invoice live in the logged-in account section
                 (My Account > Orders), not on the thank-you page. */ ?>

      <div class="sa-thankyou__card sa-thankyou__card--actions">
        <?php /* Rock 2026-10-17: one clear next action. Receipt/invoice/order details are in
                 My Account > Orders once logged in; WhatsApp is the inline link above. */ ?>
        <div class="sa-thankyou__actions">
          <a class="sa-btn" href="<?php echo esc_url($shop_url); ?>">
            <?php esc_html_e('Continue shopping', 'supreme-autoparts'); ?>
          </a>
        </div>

        <?php if ($pending && !$paid) : ?>
          <p class="sa-thankyou__pending-note" data-sa-pending-order="<?php echo esc_attr((string) $order->get_id()); ?>">
            <span class="sa-spin-arc" aria-hidden="true"></span>
            <?php esc_html_e('Confirming your payment - this page checks again automatically every few seconds. If it stays pending, ', 'supreme-autoparts'); ?><a href="<?php echo esc_url($wa_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('WhatsApp us', 'supreme-autoparts'); ?></a><?php esc_html_e(' with your order number.', 'supreme-autoparts'); ?>
          </p>
        <?php endif; ?>
      </div>

      <?php
      /* WC core hooks woocommerce_order_details_table onto woocommerce_thankyou (priority 10).
         This page is confirmation only (Rock 2026-10-17): detach the core copy so no order
         details table renders here at all - it stays available in My Account > Orders. */
      remove_action('woocommerce_thankyou', 'woocommerce_order_details_table', 10);
      ?>
      <?php do_action('woocommerce_thankyou_' . $order->get_payment_method(), $order->get_id()); ?>
      <?php do_action('woocommerce_thankyou', $order->get_id()); ?>

      <?php /* Get the app (Rock 2026-10-17): compact block at the bottom of the page.
                 Android gets the direct APK download; iPhone gets Add to Home Screen
                 steps. spinners.js flags the platform so the right option leads. */ ?>
      <section class="sa-getapp" data-sa-get-app aria-labelledby="sa-getapp-title">
        <h2 id="sa-getapp-title" class="sa-getapp__title"><?php esc_html_e('Get the Supreme app', 'supreme-autoparts'); ?></h2>
        <div class="sa-getapp__row sa-getapp__row--android">
          <strong><?php esc_html_e('Android', 'supreme-autoparts'); ?></strong>
          <span><?php esc_html_e('Install the app for faster ordering and order tracking.', 'supreme-autoparts'); ?></span>
          <a class="sa-btn sa-getapp__btn" href="https://drive.usercontent.google.com/download?id=1ZkQD7498GshIKQLZg_wdrbizRm2iNohd&amp;export=download&amp;confirm=t" target="_blank" rel="noopener noreferrer">
            <?php esc_html_e('Download for Android', 'supreme-autoparts'); ?>
          </a>
        </div>
        <div class="sa-getapp__row sa-getapp__row--ios">
          <strong><?php esc_html_e('iPhone', 'supreme-autoparts'); ?></strong>
          <span><?php esc_html_e('In Safari tap Share, then "Add to Home Screen", then Add. It opens full screen like an app.', 'supreme-autoparts'); ?></span>
        </div>
      </section>
    <?php endif; ?>

  <?php else : ?>
    <div class="sa-thankyou__card">
      <p class="sa-thankyou__eyebrow"><?php esc_html_e('Order received', 'supreme-autoparts'); ?></p>
      <h1 class="sa-thankyou__title"><?php esc_html_e('Thank you', 'supreme-autoparts'); ?></h1>
      <p class="sa-thankyou__lead"><?php esc_html_e('Your order was submitted. If payment completed, a confirmation email is on its way — check inbox and spam.', 'supreme-autoparts'); ?></p>
      <div class="sa-thankyou__actions">
        <a class="sa-btn" href="<?php echo esc_url($shop_url); ?>"><?php esc_html_e('Continue shopping', 'supreme-autoparts'); ?></a>
        <a class="sa-btn sa-btn--outline" href="<?php echo esc_url($wa_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('WhatsApp support', 'supreme-autoparts'); ?></a>
        <a class="sa-btn sa-btn--outline" href="<?php echo esc_url($enquire_url); ?>"><?php esc_html_e('Enquire for a part', 'supreme-autoparts'); ?></a>
      </div>
    </div>
  <?php endif; ?>
</div>
