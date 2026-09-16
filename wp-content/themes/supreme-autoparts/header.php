<?php
declare(strict_types=1);
if (!defined('ABSPATH')) {
    exit;
}
?><!DOCTYPE html>
<html <?php language_attributes(); ?> class="no-js">
<head>
  <meta charset="<?php bloginfo('charset'); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#000000">
  <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<div class="sa-announce">
  <?php
  printf(
      /* translators: %s: formatted free-shipping threshold */
      esc_html__('Free shipping on qualifying orders over %s*', 'supreme-autoparts'),
      esc_html(sa_free_shipping_threshold())
  );
  ?>
  &nbsp;—&nbsp;
  <a href="<?php echo esc_url(sa_page_url('free-shipping')); ?>"><?php esc_html_e('Details', 'supreme-autoparts'); ?></a>
</div>

<header class="sa-header" role="banner">
  <div class="sa-container sa-header__bar">
    <button type="button" class="sa-nav-toggle" data-sa-nav-toggle aria-expanded="false" aria-controls="sa-primary-nav">
      <?php esc_html_e('Menu', 'supreme-autoparts'); ?>
    </button>

    <a class="sa-logo" href="<?php echo esc_url(home_url('/')); ?>">
      <?php
      // Logo slot: Customizer → Site Identity → Logo (custom_logo theme_mod).
      // Parent agent / ops can upload the brand mark; until then show text mark.
      if (function_exists('has_custom_logo') && has_custom_logo()) {
          // Strip default link — we already wrap in .sa-logo
          $logo_html = get_custom_logo();
          echo preg_replace('#</?a\b[^>]*>#i', '', $logo_html); // phpcs:ignore WordPress.Security.EscapeOutput
      } else {
          ?>
      <span class="sa-logo__mark" aria-hidden="true">S</span>
      <span class="sa-logo__text"><?php bloginfo('name'); ?></span>
          <?php
      }
      ?>
    </a>

    <form class="sa-search" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>">
      <label class="screen-reader-text" for="sa-search-field"><?php esc_html_e('Search products', 'supreme-autoparts'); ?></label>
      <input type="search" id="sa-search-field" name="s" placeholder="<?php esc_attr_e('Search car parts & accessories…', 'supreme-autoparts'); ?>" value="<?php echo esc_attr(get_search_query()); ?>">
      <input type="hidden" name="post_type" value="product">
      <button type="submit" aria-label="<?php esc_attr_e('Search', 'supreme-autoparts'); ?>">🔍</button>
    </form>

    <div class="sa-header__actions">
      <a class="sa-hide-sm" href="<?php echo esc_url(sa_page_url('contact')); ?>"><?php esc_html_e('Contact', 'supreme-autoparts'); ?></a>
      <?php if (function_exists('wc_get_page_permalink')) : ?>
        <a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>"><?php esc_html_e('Account', 'supreme-autoparts'); ?></a>
        <a href="<?php echo esc_url(wc_get_cart_url()); ?>">
          <?php esc_html_e('Cart', 'supreme-autoparts'); ?>
          <span class="sa-cart-count"><?php echo esc_html((string) (function_exists('WC') && WC()->cart ? WC()->cart->get_cart_contents_count() : 0)); ?></span>
        </a>
      <?php endif; ?>
    </div>
  </div>

  <nav id="sa-primary-nav" class="sa-nav" data-sa-nav aria-label="<?php esc_attr_e('Primary', 'supreme-autoparts'); ?>">
    <div class="sa-container">
      <ul class="sa-nav__list">
        <?php foreach (sa_megamenu_tree() as $key => $menu) : ?>
          <li class="sa-nav__item" data-sa-mega-parent>
            <a class="sa-nav__link" href="<?php echo esc_url(sa_term_link('product_cat', $menu['slug'])); ?>">
              <?php echo esc_html($menu['label']); ?>
            </a>
            <div class="sa-mega sa-mega--full" role="region" aria-label="<?php echo esc_attr($menu['label']); ?>">
              <div class="sa-mega__grid">
                <?php foreach ($menu['columns'] as $col) :
                    $child_slug = sa_slugify($col);
                    ?>
                  <div class="sa-mega__col">
                    <h4>
                      <a href="<?php echo esc_url(sa_term_link('product_cat', $child_slug)); ?>">
                        <?php echo esc_html($col); ?>
                      </a>
                    </h4>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </li>
        <?php endforeach; ?>
        <li class="sa-nav__item">
          <a class="sa-nav__link" href="<?php echo esc_url(sa_term_link('product_cat', 'tires')); ?>"><?php esc_html_e('Tires', 'supreme-autoparts'); ?></a>
        </li>
        <li class="sa-nav__item">
          <a class="sa-nav__link" href="<?php echo esc_url(sa_term_link('product_cat', 'wheels')); ?>"><?php esc_html_e('Wheels', 'supreme-autoparts'); ?></a>
        </li>
        <?php if (function_exists('wc_get_page_permalink')) : ?>
          <li class="sa-nav__item">
            <a class="sa-nav__link" href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>"><?php esc_html_e('Shop All', 'supreme-autoparts'); ?></a>
          </li>
        <?php endif; ?>
      </ul>
    </div>
  </nav>
</header>
