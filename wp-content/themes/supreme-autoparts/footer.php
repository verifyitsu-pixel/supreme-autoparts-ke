<?php
declare(strict_types=1);
if (!defined('ABSPATH')) {
    exit;
}
?>
<footer class="sa-footer" role="contentinfo">
  <div class="sa-container">
    <div class="sa-footer__grid">
      <div>
        <h3><?php esc_html_e('Supreme Autoparts', 'supreme-autoparts'); ?></h3>
        <p class="sa-footer__blurb">
          <?php esc_html_e('Performance parts & accessories for cars, trucks, and SUVs. Serving Kenya and beyond.', 'supreme-autoparts'); ?>
        </p>
        <p class="sa-footer__email">
          <a href="mailto:calvin@supremeautoparts.co.ke">calvin@supremeautoparts.co.ke</a>
        </p>
      </div>
      <div>
        <h3><?php esc_html_e('Help', 'supreme-autoparts'); ?></h3>
        <ul>
          <li><a href="<?php echo esc_url(sa_page_url('about-us')); ?>"><?php esc_html_e('About Us', 'supreme-autoparts'); ?></a></li>
          <li><a href="<?php echo esc_url(sa_page_url('contact')); ?>"><?php esc_html_e('Contact', 'supreme-autoparts'); ?></a></li>
          <li><a href="<?php echo esc_url(sa_page_url('free-shipping')); ?>"><?php esc_html_e('Free Shipping', 'supreme-autoparts'); ?></a></li>
          <li><a href="<?php echo esc_url(sa_page_url('price-match')); ?>"><?php esc_html_e('Price Match', 'supreme-autoparts'); ?></a></li>
          <li><a href="<?php echo esc_url(sa_page_url('returns')); ?>"><?php esc_html_e('Returns', 'supreme-autoparts'); ?></a></li>
          <?php if (function_exists('wc_get_page_permalink')) : ?>
            <li><a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>"><?php esc_html_e('My Account', 'supreme-autoparts'); ?></a></li>
          <?php endif; ?>
        </ul>
      </div>
      <div>
        <h3><?php esc_html_e('Policies', 'supreme-autoparts'); ?></h3>
        <ul>
          <li><a href="<?php echo esc_url(sa_page_url('terms')); ?>"><?php esc_html_e('Terms of Service', 'supreme-autoparts'); ?></a></li>
          <li><a href="<?php echo esc_url(sa_page_url('privacy-policy')); ?>"><?php esc_html_e('Privacy Policy', 'supreme-autoparts'); ?></a></li>
          <li><a href="<?php echo esc_url(sa_page_url('shipping-policy')); ?>"><?php esc_html_e('Shipping Policy', 'supreme-autoparts'); ?></a></li>
          <li><a href="<?php echo esc_url(sa_page_url('refund-policy')); ?>"><?php esc_html_e('Refund Policy', 'supreme-autoparts'); ?></a></li>
          <li><a href="<?php echo esc_url(sa_page_url('chargeback-policy')); ?>"><?php esc_html_e('Chargeback & Disputes', 'supreme-autoparts'); ?></a></li>
          <li><a href="<?php echo esc_url(sa_page_url('cookie-policy')); ?>"><?php esc_html_e('Cookie Policy', 'supreme-autoparts'); ?></a></li>
        </ul>
      </div>
      <div>
        <h3><?php esc_html_e('Shop', 'supreme-autoparts'); ?></h3>
        <ul>
          <?php foreach (array_slice(sa_product_types(), 0, 6) as $type) : ?>
            <li>
              <a href="<?php echo esc_url(sa_term_link('product_cat', $type['slug'])); ?>">
                <?php echo esc_html($type['title']); ?>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
    <div class="sa-footer__bottom">
      <span>&copy; <?php echo esc_html(gmdate('Y')); ?> Supreme Autoparts · supremeautoparts.co.ke</span>
      <span><?php esc_html_e('Prices in KES unless noted. *Free shipping terms apply.', 'supreme-autoparts'); ?></span>
    </div>
  </div>
</footer>

<?php if (function_exists('WC')) : ?>
<aside id="sa-cart-drawer" class="sa-cart-drawer" data-sa-cart-drawer aria-hidden="true">
  <button type="button" class="sa-cart-drawer__backdrop" data-sa-cart-close aria-label="<?php esc_attr_e('Close cart', 'supreme-autoparts'); ?>"></button>
  <div class="sa-cart-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="sa-cart-drawer-title">
    <header class="sa-cart-drawer__head">
      <h2 id="sa-cart-drawer-title"><?php esc_html_e('Your cart', 'supreme-autoparts'); ?></h2>
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
      <?php if (function_exists('wc_get_cart_url')) : ?>
        <a class="sa-btn sa-btn--outline sa-btn--block" href="<?php echo esc_url(wc_get_cart_url()); ?>"><?php esc_html_e('View full cart', 'supreme-autoparts'); ?></a>
      <?php endif; ?>
    </footer>
  </div>
</aside>
<?php endif; ?>

<?php wp_footer(); ?>
</body>
</html>
