<?php
declare(strict_types=1);
if (!defined('ABSPATH')) {
    exit;
}
?>
<?php
$sa_contact = function_exists('sa_enquire_contact') ? sa_enquire_contact() : [];
$sa_wa      = 'https://wa.me/' . (preg_replace('/\D+/', '', (string) ($sa_contact['whatsapp'] ?? '19174375121')) ?: '19174375121');
$sa_min     = (function_exists('is_cart') && is_cart()) || (function_exists('is_checkout') && is_checkout() && !is_order_received_page());
$sa_logo_f  = function_exists('sa_theme_logo_url') ? sa_theme_logo_url(true) : (SA_THEME_URI . '/assets/logo.png');
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
<footer class="sa-foot" role="contentinfo">
  <div class="sa-container">
    <div class="sa-foot__top">
      <a class="sa-foot__brand" href="<?php echo esc_url(home_url('/')); ?>">
        <img src="<?php echo esc_url($sa_logo_f); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>" width="150" height="45" loading="lazy" />
      </a>
      <nav class="sa-foot__links" aria-label="<?php esc_attr_e('Footer', 'supreme-autoparts'); ?>">
        <a href="<?php echo esc_url(function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/shop/')); ?>"><?php esc_html_e('Shop', 'supreme-autoparts'); ?></a>
        <a href="<?php echo esc_url(home_url('/services/')); ?>"><?php esc_html_e('Car Services', 'supreme-autoparts'); ?></a>
        <a href="<?php echo esc_url(home_url('/track-order/')); ?>"><?php esc_html_e('Track order', 'supreme-autoparts'); ?></a>
        <a href="<?php echo esc_url(function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/')); ?>"><?php esc_html_e('My Account', 'supreme-autoparts'); ?></a>
        <a href="<?php echo esc_url(sa_page_url('contact')); ?>"><?php esc_html_e('Contact', 'supreme-autoparts'); ?></a>
      </nav>
      <a class="sa-foot__wa" href="<?php echo esc_url($sa_wa); ?>" target="_blank" rel="noopener">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm5.3 14.1c-.2.6-1.3 1.2-1.8 1.2-.5.1-1 .2-3.3-.7-2.8-1.1-4.6-4-4.7-4.2-.1-.2-1.1-1.5-1.1-2.9s.7-2.1 1-2.4c.3-.3.6-.3.8-.3h.6c.2 0 .4 0 .6.5l.9 2.1c.1.2.1.4 0 .5l-.3.5-.4.5c-.1.1-.3.3-.1.6.2.3.8 1.3 1.6 2.1 1.1 1 2 1.3 2.3 1.4.3.1.5.1.6-.1l.9-1.1c.2-.3.4-.2.6-.1l2 1c.3.1.5.2.5.3.1.2.1.7-.1 1.3z"/></svg>
        <?php esc_html_e('WhatsApp us', 'supreme-autoparts'); ?>
      </a>
    </div>
    <div class="sa-foot__bottom">
      <span>&copy; <?php echo esc_html(gmdate('Y')); ?> Supreme Autoparts · <a href="mailto:calvin@supremeautoparts.co.ke">calvin@supremeautoparts.co.ke</a></span>
      <nav class="sa-foot__policies" aria-label="<?php esc_attr_e('Policies', 'supreme-autoparts'); ?>">
        <a href="<?php echo esc_url(sa_page_url('terms')); ?>"><?php esc_html_e('Terms', 'supreme-autoparts'); ?></a>
        <a href="<?php echo esc_url(sa_page_url('privacy-policy')); ?>"><?php esc_html_e('Privacy', 'supreme-autoparts'); ?></a>
        <a href="<?php echo esc_url(sa_page_url('shipping-policy')); ?>"><?php esc_html_e('Shipping', 'supreme-autoparts'); ?></a>
        <a href="<?php echo esc_url(sa_page_url('refund-policy')); ?>"><?php esc_html_e('Refunds', 'supreme-autoparts'); ?></a>
        <a href="<?php echo esc_url(home_url('/booking-terms/')); ?>"><?php esc_html_e('Booking terms', 'supreme-autoparts'); ?></a>
      </nav>
    </div>
  </div>
</footer>
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
