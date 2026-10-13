<?php
declare(strict_types=1);
if (!defined('ABSPATH')) {
    exit;
}

$sa_contact = function_exists('sa_enquire_contact') ? sa_enquire_contact() : [];
$sa_wa_digits = preg_replace('/\D+/', '', (string) ($sa_contact['whatsapp'] ?? '19174375121')) ?: '19174375121';
$sa_wa        = 'https://wa.me/' . $sa_wa_digits;
$sa_phone_disp = (string) ($sa_contact['phone_display'] ?? '+1 917 437 5121');
$sa_phone_tel  = (string) ($sa_contact['phone'] ?? '+19174375121');
$sa_min        = (function_exists('is_cart') && is_cart()) || (function_exists('is_checkout') && is_checkout() && !is_order_received_page());
$sa_logo_f     = function_exists('sa_theme_logo_url') ? sa_theme_logo_url(true) : (SA_THEME_URI . '/assets/logo-light.png');
$sa_shop       = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/shop/');
$sa_account    = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/');
$sa_chev       = '<svg class="sa-foot__chev" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';
?>
<?php if ($sa_min) : ?>
<footer class="sa-foot sa-foot--min" role="contentinfo">
  <div class="sa-container sa-foot__min">
    <span class="sa-foot__secure"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg><?php esc_html_e('Secure checkout', 'supreme-autoparts'); ?></span>
    <nav class="sa-foot__minlinks" aria-label="<?php esc_attr_e('Policies', 'supreme-autoparts'); ?>">
      <a href="<?php echo esc_url(sa_page_url('refund-policy')); ?>"><?php esc_html_e('Refunds', 'supreme-autoparts'); ?></a>
      <a href="<?php echo esc_url(sa_page_url('shipping-policy')); ?>"><?php esc_html_e('Shipping', 'supreme-autoparts'); ?></a>
      <a href="<?php echo esc_url(sa_page_url('privacy-policy')); ?>"><?php esc_html_e('Privacy', 'supreme-autoparts'); ?></a>
      <a href="<?php echo esc_url(sa_page_url('terms')); ?>"><?php esc_html_e('Terms', 'supreme-autoparts'); ?></a>
      <a href="<?php echo esc_url($sa_wa); ?>" target="_blank" rel="noopener"><?php esc_html_e('Help on WhatsApp', 'supreme-autoparts'); ?></a>
    </nav>
    <span class="sa-foot__copy">&copy; <?php echo esc_html(gmdate('Y')); ?> Supreme Autoparts</span>
  </div>
</footer>
<?php else : ?>
<footer class="sa-foot sa-foot--full" role="contentinfo">
  <div class="sa-foot__accent" aria-hidden="true"></div>
  <div class="sa-container">
    <div class="sa-foot__grid">
      <div class="sa-foot__brandcol">
        <a class="sa-foot__brand" href="<?php echo esc_url(home_url('/')); ?>">
          <img src="<?php echo esc_url($sa_logo_f); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>" width="168" height="50" loading="lazy" decoding="async" />
        </a>
        <p class="sa-foot__blurb">
          <?php esc_html_e('Performance parts & accessories for cars, trucks and SUVs, plus car servicing at our Njiru, Nairobi garage.', 'supreme-autoparts'); ?>
        </p>
        <p class="sa-foot__email">
          <a href="mailto:calvin@supremeautoparts.co.ke">calvin@supremeautoparts.co.ke</a>
        </p>
      </div>

      <details class="sa-foot__acc">
        <summary class="sa-foot__acc-sum">
          <span><?php esc_html_e('Help', 'supreme-autoparts'); ?></span>
          <?php echo $sa_chev; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </summary>
        <ul class="sa-foot__list">
          <li><a href="<?php echo esc_url(sa_page_url('about-us')); ?>"><?php esc_html_e('About Us', 'supreme-autoparts'); ?></a></li>
          <li><a href="<?php echo esc_url(sa_page_url('contact')); ?>"><?php esc_html_e('Contact', 'supreme-autoparts'); ?></a></li>
          <li><a href="<?php echo esc_url(function_exists('sa_enquire_page_url') ? sa_enquire_page_url() : home_url('/enquire/')); ?>"><?php esc_html_e('Can\'t find a part?', 'supreme-autoparts'); ?></a></li>
          <li><a href="<?php echo esc_url(sa_page_url('free-shipping')); ?>"><?php esc_html_e('Free Shipping', 'supreme-autoparts'); ?></a></li>
          <li><a href="<?php echo esc_url(sa_page_url('price-match')); ?>"><?php esc_html_e('Price Match', 'supreme-autoparts'); ?></a></li>
          <li><a href="<?php echo esc_url(sa_page_url('returns')); ?>"><?php esc_html_e('Returns', 'supreme-autoparts'); ?></a></li>
          <li><a href="<?php echo esc_url(sa_page_url('guides')); ?>"><?php esc_html_e('Guides', 'supreme-autoparts'); ?></a></li>
          <li><a href="<?php echo esc_url(sa_page_url('how-to-order')); ?>"><?php esc_html_e('How to order', 'supreme-autoparts'); ?></a></li>
          <li><a href="<?php echo esc_url(sa_page_url('shipping-policy')); ?>"><?php esc_html_e('Shipping & delivery', 'supreme-autoparts'); ?></a></li>
          <li><a href="<?php echo esc_url(sa_page_url('fitment-guide')); ?>"><?php esc_html_e('Fitment guide', 'supreme-autoparts'); ?></a></li>
          <li><a href="<?php echo esc_url($sa_account); ?>"><?php esc_html_e('My Account', 'supreme-autoparts'); ?></a></li>
          <li><a href="<?php echo esc_url(home_url('/services/')); ?>"><?php esc_html_e('Car Services', 'supreme-autoparts'); ?></a></li>
          <li><a href="<?php echo esc_url(home_url('/roadside/')); ?>"><?php esc_html_e('Roadside Rescue', 'supreme-autoparts'); ?></a></li>
          <li><a href="<?php echo esc_url(home_url('/roadside-terms/')); ?>"><?php esc_html_e('Roadside Terms', 'supreme-autoparts'); ?></a></li>
          <li><a href="<?php echo esc_url(home_url('/track-order/')); ?>"><?php esc_html_e('Track order', 'supreme-autoparts'); ?></a></li>
        </ul>
      </details>

      <details class="sa-foot__acc">
        <summary class="sa-foot__acc-sum">
          <span><?php esc_html_e('Policies', 'supreme-autoparts'); ?></span>
          <?php echo $sa_chev; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </summary>
        <ul class="sa-foot__list">
          <li><a href="<?php echo esc_url(sa_page_url('terms')); ?>"><?php esc_html_e('Terms of Service', 'supreme-autoparts'); ?></a></li>
          <li><a href="<?php echo esc_url(sa_page_url('privacy-policy')); ?>"><?php esc_html_e('Privacy Policy', 'supreme-autoparts'); ?></a></li>
          <li><a href="<?php echo esc_url(sa_page_url('shipping-policy')); ?>"><?php esc_html_e('Shipping Policy', 'supreme-autoparts'); ?></a></li>
          <li><a href="<?php echo esc_url(sa_page_url('refund-policy')); ?>"><?php esc_html_e('Refund Policy', 'supreme-autoparts'); ?></a></li>
          <li><a href="<?php echo esc_url(sa_page_url('chargeback-policy')); ?>"><?php esc_html_e('Chargeback & Disputes', 'supreme-autoparts'); ?></a></li>
          <li><a href="<?php echo esc_url(sa_page_url('cookie-policy')); ?>"><?php esc_html_e('Cookie Policy', 'supreme-autoparts'); ?></a></li>
          <li><a href="<?php echo esc_url(home_url('/booking-terms/')); ?>"><?php esc_html_e('Booking Terms', 'supreme-autoparts'); ?></a></li>
          <li><a href="<?php echo esc_url(home_url('/manage-booking/')); ?>"><?php esc_html_e('Manage booking', 'supreme-autoparts'); ?></a></li>
        </ul>
      </details>

      <details class="sa-foot__acc">
        <summary class="sa-foot__acc-sum">
          <span><?php esc_html_e('Shop', 'supreme-autoparts'); ?></span>
          <?php echo $sa_chev; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </summary>
        <ul class="sa-foot__list">
          <?php if (function_exists('sa_product_types')) : ?>
            <?php foreach (array_slice(sa_product_types(), 0, 6) as $type) : ?>
              <li>
                <a href="<?php echo esc_url(sa_term_link('product_cat', $type['slug'])); ?>">
                  <?php echo esc_html($type['title']); ?>
                </a>
              </li>
            <?php endforeach; ?>
          <?php endif; ?>
          <li><a class="sa-foot__shopall" href="<?php echo esc_url($sa_shop); ?>"><?php esc_html_e('Shop all parts', 'supreme-autoparts'); ?></a></li>
        </ul>
      </details>
    </div>

    <div class="sa-foot__contact">
      <a class="sa-foot__wa" href="<?php echo esc_url($sa_wa); ?>" target="_blank" rel="noopener">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm5.3 14.1c-.2.6-1.3 1.2-1.8 1.2-.5.1-1 .2-3.3-.7-2.8-1.1-4.6-4-4.7-4.2-.1-.2-1.1-1.5-1.1-2.9s.7-2.1 1-2.4c.3-.3.6-.3.8-.3h.6c.2 0 .4 0 .6.5l.9 2.1c.1.2.1.4 0 .5l-.3.5-.4.5c-.1.1-.3.3-.1.6.2.3.8 1.3 1.6 2.1 1.1 1 2 1.3 2.3 1.4.3.1.5.1.6-.1l.9-1.1c.2-.3.4-.2.6-.1l2 1c.3.1.5.2.5.3.1.2.1.7-.1 1.3z"/></svg>
        <?php esc_html_e('WhatsApp us', 'supreme-autoparts'); ?>
      </a>
      <a class="sa-foot__chip" href="mailto:calvin@supremeautoparts.co.ke">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>
        calvin@supremeautoparts.co.ke
      </a>
      <a class="sa-foot__chip" href="<?php echo esc_url('tel:' . preg_replace('/\s+/', '', $sa_phone_tel)); ?>">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M22 16.9v2a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h2a2 2 0 0 1 2 1.7c.1.9.3 1.8.6 2.6a2 2 0 0 1-.5 2.1L7.1 9.5a16 16 0 0 0 6 6l1.1-1.1a2 2 0 0 1 2.1-.5c.8.3 1.7.5 2.6.6A2 2 0 0 1 22 16.9z"/></svg>
        <?php echo esc_html($sa_phone_disp); ?>
      </a>
      <a class="sa-foot__chip sa-foot__chip--accent" href="<?php echo esc_url(sa_page_url('contact')); ?>">
        <?php esc_html_e('Contact page', 'supreme-autoparts'); ?>
      </a>
    </div>

    <div class="sa-foot__bottom">
      <span class="sa-foot__copy">&copy; <?php echo esc_html(gmdate('Y')); ?> Supreme Autoparts · supremeautoparts.co.ke</span>
      <nav class="sa-foot__policies" aria-label="<?php esc_attr_e('Policies', 'supreme-autoparts'); ?>">
        <a href="<?php echo esc_url(sa_page_url('terms')); ?>"><?php esc_html_e('Terms', 'supreme-autoparts'); ?></a>
        <a href="<?php echo esc_url(sa_page_url('privacy-policy')); ?>"><?php esc_html_e('Privacy', 'supreme-autoparts'); ?></a>
        <a href="<?php echo esc_url(sa_page_url('shipping-policy')); ?>"><?php esc_html_e('Shipping', 'supreme-autoparts'); ?></a>
        <a href="<?php echo esc_url(sa_page_url('refund-policy')); ?>"><?php esc_html_e('Refunds', 'supreme-autoparts'); ?></a>
        <a href="<?php echo esc_url(home_url('/booking-terms/')); ?>"><?php esc_html_e('Booking terms', 'supreme-autoparts'); ?></a>
      </nav>
      <div class="sa-foot__payments" role="group" aria-label="<?php echo esc_attr__('Accepted payment methods', 'supreme-autoparts'); ?>">
        <span class="sa-foot__payments-label"><?php esc_html_e('Secure checkout', 'supreme-autoparts'); ?></span>
        <ul class="sa-foot__pay-logos">
          <?php
          $sa_pay_logos = [
              ['file' => 'visa.svg',       'label' => 'Visa',              'w' => 40, 'h' => 26],
              ['file' => 'mastercard.svg', 'label' => 'Mastercard',        'w' => 40, 'h' => 26],
              ['file' => 'amex.svg',       'label' => 'American Express',  'w' => 40, 'h' => 26],
              ['file' => 'paypal.svg',     'label' => 'PayPal',            'w' => 40, 'h' => 26],
              ['file' => 'apple-pay.svg',  'label' => 'Apple Pay',         'w' => 40, 'h' => 26],
              ['file' => 'google-pay.svg', 'label' => 'Google Pay',        'w' => 52, 'h' => 26],
          ];
          $sa_pay_base = trailingslashit(SA_THEME_URI) . 'assets/images/payments/';
          foreach ($sa_pay_logos as $sa_pay) :
              ?>
            <li>
              <img
                src="<?php echo esc_url($sa_pay_base . $sa_pay['file']); ?>"
                alt="<?php echo esc_attr($sa_pay['label']); ?>"
                width="<?php echo (int) $sa_pay['w']; ?>"
                height="<?php echo (int) $sa_pay['h']; ?>"
                loading="lazy"
                decoding="async"
              />
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>
</footer>
<script>
(function(){
  var mq=window.matchMedia('(min-width:900px)');
  function sync(){
    document.querySelectorAll('.sa-foot--full .sa-foot__acc').forEach(function(d){
      if(mq.matches){ d.open=true; }
      else if(!d.dataset.userToggled){ d.open=false; }
    });
  }
  document.querySelectorAll('.sa-foot--full .sa-foot__acc').forEach(function(d){
    d.addEventListener('toggle',function(){ if(!mq.matches){ d.dataset.userToggled='1'; } });
  });
  sync();
  if(mq.addEventListener){ mq.addEventListener('change',sync); }
  else if(mq.addListener){ mq.addListener(sync); }
})();
</script>
<?php endif; ?>

<?php if (function_exists('WC')) : ?>
<aside id="sa-cart-drawer" class="sa-cart-drawer" data-sa-cart-drawer aria-hidden="true">
  <button type="button" class="sa-cart-drawer__backdrop" data-sa-cart-close aria-label="<?php esc_attr_e('Close cart', 'supreme-autoparts'); ?>"></button>
  <div class="sa-cart-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="sa-cart-drawer-title">
    <header class="sa-cart-drawer__head">
      <h2 id="sa-cart-drawer-title"><?php esc_html_e('Cart', 'supreme-autoparts'); ?></h2>
      <button type="button" class="sa-cart-drawer__close" data-sa-cart-close aria-label="<?php esc_attr_e('Close', 'supreme-autoparts'); ?>">
        <?php echo sa_category_icon_svg('close'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
      </button>
    </header>
    <div class="sa-cart-drawer__body widget_shopping_cart_content">
      <?php woocommerce_mini_cart(); ?>
    </div>
    <footer class="sa-cart-drawer__foot">
      <div class="sa-cart-drawer__trust">
        <a href="<?php echo esc_url(sa_page_url('shipping-policy')); ?>"><?php esc_html_e('Shipping', 'supreme-autoparts'); ?></a>
        <a href="<?php echo esc_url(sa_page_url('returns')); ?>"><?php esc_html_e('Returns', 'supreme-autoparts'); ?></a>
        <a href="<?php echo esc_url(sa_page_url('privacy-policy')); ?>"><?php esc_html_e('Privacy', 'supreme-autoparts'); ?></a>
      </div>
    </footer>
  </div>
</aside>
<?php endif; ?>

<?php wp_footer(); ?>
</body>
</html>
