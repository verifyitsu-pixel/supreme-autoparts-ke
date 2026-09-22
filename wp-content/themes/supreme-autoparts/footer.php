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
        <a class="sa-footer__brand" href="<?php echo esc_url(home_url('/')); ?>">
          <img class="sa-footer__logo" src="<?php echo esc_url(function_exists('sa_theme_logo_url') ? sa_theme_logo_url(false) : (SA_THEME_URI . '/assets/logo.png')); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>" width="160" height="48" loading="lazy" />
        </a>
        <h3 class="screen-reader-text"><?php esc_html_e('Supreme Autoparts', 'supreme-autoparts'); ?></h3>
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
          <li><a href="<?php echo esc_url(sa_enquire_page_url()); ?>"><?php esc_html_e('Can\'t find a part?', 'supreme-autoparts'); ?></a></li>
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

    <section id="sa-footer-contact" class="sa-footer-contact" aria-labelledby="sa-footer-contact-title">
      <div class="sa-footer-contact__head">
        <h3 id="sa-footer-contact-title"><?php esc_html_e('Contact us', 'supreme-autoparts'); ?></h3>
        <p class="sa-footer-contact__lead"><?php esc_html_e('Send a message — we usually reply within one business day.', 'supreme-autoparts'); ?></p>
      </div>
      <?php echo sa_footer_contact_notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
      <form class="sa-footer-contact__form sa-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" novalidate>
        <input type="hidden" name="action" value="sa_footer_contact" />
        <input type="hidden" name="sa_contact_redirect" value="<?php echo esc_url(is_singular() ? (string) get_permalink() : home_url('/')); ?>" />
        <?php wp_nonce_field('sa_footer_contact', 'sa_footer_contact_nonce'); ?>
        <p class="sa-footer-contact__hp" aria-hidden="true">
          <label for="sa-contact-company"><?php esc_html_e('Company', 'supreme-autoparts'); ?></label>
          <input type="text" id="sa-contact-company" name="sa_company" value="" tabindex="-1" autocomplete="off" />
        </p>
        <div class="sa-footer-contact__grid">
          <p class="sa-footer-contact__field">
            <label for="sa-contact-name"><?php esc_html_e('Name', 'supreme-autoparts'); ?> <span aria-hidden="true">*</span></label>
            <input type="text" id="sa-contact-name" name="sa_name" class="input-text" required maxlength="120" autocomplete="name" />
          </p>
          <p class="sa-footer-contact__field">
            <label for="sa-contact-email"><?php esc_html_e('Email', 'supreme-autoparts'); ?> <span aria-hidden="true">*</span></label>
            <input type="email" id="sa-contact-email" name="sa_email" class="input-text" required maxlength="190" autocomplete="email" />
          </p>
          <p class="sa-footer-contact__field">
            <label for="sa-contact-phone"><?php esc_html_e('Phone', 'supreme-autoparts'); ?> <span class="sa-footer-contact__opt"><?php esc_html_e('(optional)', 'supreme-autoparts'); ?></span></label>
            <input type="tel" id="sa-contact-phone" name="sa_phone" class="input-text" maxlength="40" autocomplete="tel" />
          </p>
          <p class="sa-footer-contact__field sa-footer-contact__field--full">
            <label for="sa-contact-message"><?php esc_html_e('Message', 'supreme-autoparts'); ?> <span aria-hidden="true">*</span></label>
            <textarea id="sa-contact-message" name="sa_message" class="input-text" rows="3" required maxlength="5000" placeholder="<?php esc_attr_e('How can we help?', 'supreme-autoparts'); ?>"></textarea>
          </p>
        </div>
        <p class="sa-footer-contact__actions">
          <button type="submit" class="sa-btn sa-btn--sm"><?php esc_html_e('Send message', 'supreme-autoparts'); ?></button>
        </p>
      </form>
    </section>

    <div class="sa-footer__bottom">
      <span>&copy; <?php echo esc_html(gmdate('Y')); ?> Supreme Autoparts · supremeautoparts.co.ke</span>
      <span><?php esc_html_e('Prices shown in local currency where available; charged in USD at checkout. *Free shipping terms apply.', 'supreme-autoparts'); ?></span>
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
