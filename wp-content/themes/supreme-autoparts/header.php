<?php
declare(strict_types=1);
if (!defined('ABSPATH')) {
    exit;
}
?><!DOCTYPE html>
<html <?php language_attributes(); ?> class="no-js">
<head>
  <meta charset="<?php bloginfo('charset'); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#0B0B0D">
  <link rel="icon" href="<?php echo esc_url(SA_THEME_URI . '/assets/favicon.png'); ?>" type="image/png" sizes="any">
  <link rel="apple-touch-icon" href="<?php echo esc_url(SA_THEME_URI . '/assets/icon.png'); ?>">
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
      <?php echo sa_category_icon_svg('menu'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
      <span class="screen-reader-text"><?php esc_html_e('Menu', 'supreme-autoparts'); ?></span>
    </button>

    <a class="sa-logo" href="<?php echo esc_url(home_url('/')); ?>">
      <?php
      if (function_exists('has_custom_logo') && has_custom_logo()) {
          $custom_logo_id = (int) get_theme_mod('custom_logo');
          $logo_file      = (string) get_post_meta($custom_logo_id, '_wp_attached_file', true);
          $uploads        = wp_get_upload_dir();
          $basedir        = (string) ($uploads['basedir'] ?? '');
          $logo_on_disk   = $logo_file !== '' && $basedir !== '' && is_readable($basedir . '/' . ltrim($logo_file, '/'));
          $logo_html      = '';
          if ($logo_on_disk) {
              $logo_html = wp_get_attachment_image($custom_logo_id, 'sa-logo', false, [
                  'class'         => 'sa-logo__img',
                  'alt'           => get_bloginfo('name'),
                  'loading'       => 'eager',
                  'fetchpriority' => 'high',
                  'decoding'      => 'async',
                  'sizes'         => '(max-width: 767px) 140px, 200px',
              ]);
              if ($logo_html === '') {
                  $logo_html = wp_get_attachment_image($custom_logo_id, 'medium', false, [
                      'class'         => 'sa-logo__img',
                      'alt'           => get_bloginfo('name'),
                      'loading'       => 'eager',
                      'fetchpriority' => 'high',
                      'decoding'      => 'async',
                      'sizes'         => '(max-width: 767px) 140px, 200px',
                  ]);
              }
          }
          if ($logo_html !== '') {
              echo $logo_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
          } else {
              // Fall through to theme-baked asset when uploads missing after redeploy.
              $fallback_uri = function_exists('sa_theme_logo_url') ? sa_theme_logo_url(false) : (SA_THEME_URI . '/assets/logo.jpg');
              printf(
                  '<img class="sa-logo__img" src="%s" alt="%s" width="200" height="112" loading="eager" fetchpriority="high" decoding="async" sizes="(max-width: 767px) 140px, 200px" />',
                  esc_url($fallback_uri),
                  esc_attr(get_bloginfo('name'))
              );
          }
      } else {
          $fallback_uri = function_exists('sa_theme_logo_url') ? sa_theme_logo_url(false) : (SA_THEME_URI . '/assets/logo.png');
          $fallback_path = str_replace(SA_THEME_URI, SA_THEME_DIR, $fallback_uri);
          if (is_readable($fallback_path) || is_readable(SA_THEME_DIR . '/assets/logo.jpg') || is_readable(SA_THEME_DIR . '/assets/logo.png')) {
              printf(
                  '<img class="sa-logo__img" src="%s" alt="%s" width="200" height="112" loading="eager" fetchpriority="high" decoding="async" sizes="(max-width: 767px) 140px, 200px" />',
                  esc_url($fallback_uri),
                  esc_attr(get_bloginfo('name'))
              );
          } else {
              $icon = SA_THEME_URI . '/assets/icon.png';
              printf(
                  '<img class="sa-logo__img" src="%s" alt="%s" width="48" height="48" loading="eager" fetchpriority="high" decoding="async" />',
                  esc_url($icon),
                  esc_attr(get_bloginfo('name'))
              );
              echo '<span>' . esc_html(get_bloginfo('name')) . '</span>';
          }
      }
      ?>
      <span class="screen-reader-text"><?php bloginfo('name'); ?></span>
    </a>

    <form class="sa-search" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>">
      <label class="screen-reader-text" for="sa-search-field"><?php esc_html_e('Search products', 'supreme-autoparts'); ?></label>
      <input type="search" id="sa-search-field" name="s" placeholder="<?php esc_attr_e('Search car parts & accessories…', 'supreme-autoparts'); ?>" value="<?php echo esc_attr(get_search_query()); ?>">
      <input type="hidden" name="post_type" value="product">
      <button type="submit" aria-label="<?php esc_attr_e('Search', 'supreme-autoparts'); ?>">
        <?php echo sa_category_icon_svg('search'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
      </button>
    </form>

    <div class="sa-header__actions">
      <a class="sa-hide-sm sa-header__link" href="<?php echo esc_url(sa_page_url('contact')); ?>"><?php esc_html_e('Contact', 'supreme-autoparts'); ?></a>
      <?php if (function_exists('wc_get_page_permalink')) : ?>
        <a class="sa-header__icon-link" href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>" aria-label="<?php esc_attr_e('Account', 'supreme-autoparts'); ?>">
          <?php echo sa_category_icon_svg('user'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
          <span class="sa-hide-sm"><?php esc_html_e('Account', 'supreme-autoparts'); ?></span>
        </a>
        <button type="button" class="sa-header__cart" data-sa-cart-open aria-controls="sa-cart-drawer" aria-expanded="false">
          <?php echo sa_category_icon_svg('cart'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
          <span class="sa-hide-sm"><?php esc_html_e('Cart', 'supreme-autoparts'); ?></span>
          <span class="sa-cart-count" data-sa-cart-count><?php echo esc_html((string) (function_exists('WC') && WC()->cart ? WC()->cart->get_cart_contents_count() : 0)); ?></span>
        </button>
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
