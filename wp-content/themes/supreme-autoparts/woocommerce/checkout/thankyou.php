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
$wa_url      = 'https://wa.me/254714498451';
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
      $paid     = $order->is_paid() || $order->has_status(['processing', 'completed']);
      $pending  = $order->has_status(['pending', 'on-hold']);
      $eyebrow  = $paid ? __('Order confirmed', 'supreme-autoparts') : __('Order received', 'supreme-autoparts');
      $heading  = $paid
          ? __('Thank you — payment received', 'supreme-autoparts')
          : __('Thank you — we have your order', 'supreme-autoparts');
      $lead = $paid
          ? __('A confirmation email is on its way. We will prepare your parts and update you on shipping.', 'supreme-autoparts')
          : __('If you just paid on Whop, confirmation can take a few seconds. Refresh this page if the status does not update.', 'supreme-autoparts');
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
            <li><?php esc_html_e('You receive packing and shipping updates by email.', 'supreme-autoparts'); ?></li>
            <li><?php esc_html_e('Need fitment help? Message us on WhatsApp with your order number.', 'supreme-autoparts'); ?></li>
          </ol>
        </section>

        <?php
        $invoice_url = '';
        if (function_exists('sa_core_invoice_url')) {
            $invoice_url = sa_core_invoice_url((int) $order->get_id(), true);
        }
        $doc_label = function_exists('sa_core_invoice_doc_title')
            ? sa_core_invoice_doc_title($order)
            : ($paid ? __('Receipt', 'supreme-autoparts') : __('Invoice', 'supreme-autoparts'));
        ?>
        <div class="sa-thankyou__actions">
          <?php if ($invoice_url) : ?>
            <a class="sa-btn" href="<?php echo esc_url($invoice_url); ?>" target="_blank" rel="noopener">
              <?php echo esc_html(sprintf(/* translators: Invoice or Receipt */ __('View %s', 'supreme-autoparts'), $doc_label)); ?>
            </a>
          <?php endif; ?>
          <a class="sa-btn<?php echo $invoice_url ? ' sa-btn--outline' : ''; ?>" href="<?php echo esc_url($shop_url); ?>">
            <?php esc_html_e('Continue shopping', 'supreme-autoparts'); ?>
          </a>
          <?php if (is_user_logged_in()) : ?>
            <a class="sa-btn sa-btn--outline" href="<?php echo esc_url($order->get_view_order_url()); ?>">
              <?php esc_html_e('View order', 'supreme-autoparts'); ?>
            </a>
          <?php elseif ($account_url) : ?>
            <a class="sa-btn sa-btn--outline" href="<?php echo esc_url($account_url); ?>">
              <?php esc_html_e('Create / log in to account', 'supreme-autoparts'); ?>
            </a>
          <?php endif; ?>
          <a class="sa-btn sa-btn--outline" href="<?php echo esc_url($wa_url); ?>" target="_blank" rel="noopener noreferrer">
            <?php esc_html_e('WhatsApp +254 714 498 451', 'supreme-autoparts'); ?>
          </a>
        </div>

        <?php if ($pending && !$paid) : ?>
          <p class="sa-thankyou__pending-note">
            <?php esc_html_e('Still seeing “pending”? Wait a moment after Whop, then refresh. If it stays pending, WhatsApp us with your order number.', 'supreme-autoparts'); ?>
          </p>
        <?php endif; ?>
      </div>

      <?php do_action('woocommerce_thankyou_' . $order->get_payment_method(), $order->get_id()); ?>
      <?php do_action('woocommerce_thankyou', $order->get_id()); ?>
    <?php endif; ?>

  <?php else : ?>
    <div class="sa-thankyou__card">
      <p class="sa-thankyou__eyebrow"><?php esc_html_e('Order received', 'supreme-autoparts'); ?></p>
      <h1 class="sa-thankyou__title"><?php esc_html_e('Thank you', 'supreme-autoparts'); ?></h1>
      <p class="sa-thankyou__lead"><?php esc_html_e('Your order has been received. Check your email for confirmation.', 'supreme-autoparts'); ?></p>
      <div class="sa-thankyou__actions">
        <a class="sa-btn" href="<?php echo esc_url($shop_url); ?>"><?php esc_html_e('Continue shopping', 'supreme-autoparts'); ?></a>
        <a class="sa-btn sa-btn--outline" href="<?php echo esc_url($wa_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('WhatsApp support', 'supreme-autoparts'); ?></a>
        <a class="sa-btn sa-btn--outline" href="<?php echo esc_url($enquire_url); ?>"><?php esc_html_e('Enquire for a part', 'supreme-autoparts'); ?></a>
      </div>
    </div>
  <?php endif; ?>
</div>
