<?php
declare(strict_types=1);
if (!defined('ABSPATH')) {
    exit;
}
?><!DOCTYPE html>
<html <?php language_attributes(); ?> class="no-js">
<head>
  <meta charset="<?php bloginfo('charset'); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#ffffff">
  <?php $sa_iv = '?v=emblem2'; $sa_ia = SA_THEME_URI . '/assets/'; ?>
  <link rel="icon" href="<?php echo esc_url($sa_ia . 'favicon.ico' . $sa_iv); ?>" sizes="48x48">
  <link rel="icon" href="<?php echo esc_url($sa_ia . 'favicon-32.png' . $sa_iv); ?>" type="image/png" sizes="32x32">
  <link rel="icon" href="<?php echo esc_url($sa_ia . 'favicon-16.png' . $sa_iv); ?>" type="image/png" sizes="16x16">
  <link rel="icon" href="<?php echo esc_url($sa_ia . 'icon.png' . $sa_iv); ?>" type="image/png" sizes="192x192">
  <link rel="apple-touch-icon" sizes="180x180" href="<?php echo esc_url($sa_ia . 'apple-touch-icon.png' . $sa_iv); ?>">
  <link rel="manifest" href="<?php echo esc_url($sa_ia . 'site.webmanifest' . $sa_iv); ?>">
  <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<header class="sa-header" role="banner">
  <?php if ((!function_exists('sa_geo_is_kenya') || sa_geo_is_kenya())) : ?>
  <div class="sa-rsbar"><div class="sa-container sa-rsbar__in"><span><?php esc_html_e('Car trouble? We\'ll come to you.', 'supreme-autoparts'); ?></span><a href="<?php echo esc_url(home_url('/roadside/')); ?>"><?php esc_html_e('Get roadside help', 'supreme-autoparts'); ?></a><a class="sa-rsbar__tel" href="tel:+254714498451">+254 714 498 451</a></div></div>
  <?php else : ?>
  <div class="sa-rsbar sa-rsbar--intl"><div class="sa-container sa-rsbar__in"><span><?php (function_exists('sa_geo_country') && sa_geo_country() === 'US') ? esc_html_e('We ship across the US.', 'supreme-autoparts') : esc_html_e('We ship worldwide.', 'supreme-autoparts'); ?></span><a href="https://wa.me/19174375121" rel="noopener"><?php esc_html_e('Fitment help on WhatsApp', 'supreme-autoparts'); ?></a><a class="sa-rsbar__tel" href="tel:+19174375121">+1 917 437 5121</a></div></div>
  <?php endif; ?>
  <div class="sa-container sa-header__bar">
    <button type="button" class="sa-nav-toggle" data-sa-nav-toggle aria-expanded="false" aria-controls="sa-primary-nav">
      <?php echo sa_category_icon_svg('menu'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
      <span class="screen-reader-text"><?php esc_html_e('Menu', 'supreme-autoparts'); ?></span>
    </button>

    <a class="sa-logo" href="<?php echo esc_url(home_url('/')); ?>">
      <?php
      $sa_logo = function_exists('sa_theme_logo_url') ? sa_theme_logo_url(true) : (SA_THEME_URI . '/assets/logo-light.png');
      printf(
          '<img class="sa-logo__img" src="%s" alt="%s" width="390" height="60" loading="eager" fetchpriority="high" decoding="async" />',
          esc_url($sa_logo),
          esc_attr(get_bloginfo('name'))
      );
      ?>
    </a>

    <form class="sa-search" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>">
      <label class="screen-reader-text" for="sa-search-field"><?php esc_html_e('Search products', 'supreme-autoparts'); ?></label>
      <input type="search" id="sa-search-field" name="s" placeholder="<?php esc_attr_e('Part number, brand, or keyword…', 'supreme-autoparts'); ?>" value="<?php echo esc_attr(get_search_query()); ?>">
      <input type="hidden" name="post_type" value="product">
      <button type="submit" aria-label="<?php esc_attr_e('Search', 'supreme-autoparts'); ?>">
        <?php echo sa_category_icon_svg('search'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        <span class="sa-search__label"><?php esc_html_e('Search', 'supreme-autoparts'); ?></span>
      </button>
    </form>

    <div class="sa-header__actions">
      <a class="sa-hide-sm sa-header__link" href="<?php echo esc_url(home_url('/services/')); ?>"><?php esc_html_e('Car Service', 'supreme-autoparts'); ?></a>
      <?php if ((!function_exists('sa_geo_is_kenya') || sa_geo_is_kenya())) : ?>
      <a class="sa-hide-sm sa-header__link" href="<?php echo esc_url(home_url('/roadside/')); ?>"><?php esc_html_e('Roadside Help', 'supreme-autoparts'); ?></a>
      <?php endif; ?>
      <a class="sa-hide-sm sa-header__link" href="<?php echo esc_url(sa_page_url('contact')); ?>"><?php esc_html_e('Contact', 'supreme-autoparts'); ?></a>
      <?php if (function_exists('wc_get_page_permalink')) : ?>
        <a class="sa-header__icon-link" href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>" aria-label="<?php esc_attr_e('Account', 'supreme-autoparts'); ?>">
          <?php if (is_user_logged_in() && function_exists('sa_avatar_html')) : ?>
            <?php echo sa_avatar_html(get_current_user_id(), 26); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <span class="sa-hide-sm"><?php echo function_exists('sa_avatar_greeting_html') ? sa_avatar_greeting_html(get_current_user_id()) : esc_html__('Account', 'supreme-autoparts'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
          <?php else : ?>
            <?php echo sa_category_icon_svg('user'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <span class="sa-hide-sm"><?php esc_html_e('Account', 'supreme-autoparts'); ?></span>
          <?php endif; ?>
        </a>
        <?php if (is_user_logged_in()) : ?>
          <a class="sa-hide-sm sa-header__logout" href="<?php echo esc_url(function_exists('sa_core_logout_link_url') ? sa_core_logout_link_url() : wc_logout_url()); ?>"><?php esc_html_e('Log out', 'supreme-autoparts'); ?></a>
        <?php endif; ?>
        <button type="button" class="sa-header__cart" data-sa-cart-open aria-controls="sa-cart-drawer" aria-expanded="false">
          <?php echo sa_category_icon_svg('cart'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
          <span class="sa-hide-sm"><?php esc_html_e('Cart', 'supreme-autoparts'); ?></span>
          <span class="sa-cart-count" data-sa-cart-count><?php echo esc_html((string) (function_exists('WC') && WC()->cart ? WC()->cart->get_cart_contents_count() : 0)); ?></span>
        </button>
      <?php endif; ?>
      <button type="button" class="sa-header__menu" data-sa-side-open aria-label="<?php esc_attr_e('Open menu', 'supreme-autoparts'); ?>"><?php echo function_exists('sa_svc_icon') ? sa_svc_icon('menu') : ''; // phpcs:ignore ?><span><?php esc_html_e('Menu', 'supreme-autoparts'); ?></span></button>
    </div>
  </div>

  <div class="sa-header__search-mobile sa-container">
    <form class="sa-search sa-search--mobile" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>">
      <label class="screen-reader-text" for="sa-search-field-mobile"><?php esc_html_e('Search products', 'supreme-autoparts'); ?></label>
      <input type="search" id="sa-search-field-mobile" name="s" placeholder="<?php esc_attr_e('Part number, brand, or keyword…', 'supreme-autoparts'); ?>" value="<?php echo esc_attr(get_search_query()); ?>">
      <input type="hidden" name="post_type" value="product">
      <button type="submit" aria-label="<?php esc_attr_e('Search', 'supreme-autoparts'); ?>">
        <?php echo sa_category_icon_svg('search'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        <span class="sa-search__label"><?php esc_html_e('Search', 'supreme-autoparts'); ?></span>
      </button>
    </form>
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
        <li class="sa-nav__item">
          <a class="sa-nav__link" href="<?php echo esc_url(home_url('/categories/')); ?>"><?php esc_html_e('All categories', 'supreme-autoparts'); ?></a>
        </li>
        <li class="sa-nav__item">
          <a class="sa-nav__link sa-nav__link--service" href="<?php echo esc_url(function_exists('sa_svc_book_url') ? sa_svc_book_url() : home_url('/book-service/')); ?>"><?php esc_html_e('Book a Service', 'supreme-autoparts'); ?></a>
        </li>
        <?php if ((!function_exists('sa_geo_is_kenya') || sa_geo_is_kenya())) : ?>
        <li class="sa-nav__item">
          <a class="sa-nav__link sa-nav__link--service" href="<?php echo esc_url(home_url('/roadside/')); ?>"><?php esc_html_e('Roadside Rescue', 'supreme-autoparts'); ?></a>
        </li>
        <?php endif; ?>
        <?php if (function_exists('wc_get_page_permalink')) : ?>
          <li class="sa-nav__item">
            <a class="sa-nav__link" href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>"><?php esc_html_e('Shop', 'supreme-autoparts'); ?></a>
          </li>
        <?php endif; ?>
      </ul>
    </div>
  </nav>
</header>

