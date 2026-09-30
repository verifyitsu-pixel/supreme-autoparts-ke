<?php
/**
 * Product archive template override.
 *
 * @package Supreme_Autoparts
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

get_header('shop');

do_action('woocommerce_before_main_content');

$sa_cat_img = '';
$sa_cat_slug = '';
$sa_term = null;
$sa_is_cat = function_exists('is_product_category') && is_product_category();
$sa_is_shop = function_exists('is_shop') && is_shop();

if ($sa_is_cat) {
    $sa_term = get_queried_object();
    if ($sa_term && !is_wp_error($sa_term) && !empty($sa_term->slug) && function_exists('sa_category_image_url')) {
        $sa_cat_slug = (string) $sa_term->slug;
        $sa_cat_img = sa_category_image_url($sa_cat_slug);
    }
}

$sa_total = function_exists('wc_get_loop_prop') ? (int) wc_get_loop_prop('total') : 0;
if ($sa_total <= 0 && isset($GLOBALS['wp_query']) && $GLOBALS['wp_query'] instanceof WP_Query) {
    $sa_total = (int) $GLOBALS['wp_query']->found_posts;
}
?>
<header class="sa-archive-header<?php echo $sa_cat_img !== '' ? ' sa-archive-header--photo' : ''; ?><?php echo $sa_is_shop ? ' sa-archive-header--shop' : ''; ?>">
  <?php if ($sa_cat_img !== '') : ?>
    <div class="sa-archive-header__media" aria-hidden="true">
      <img src="<?php echo esc_url($sa_cat_img); ?>" alt="" class="sa-archive-header__img" loading="eager" decoding="async" width="800" height="450" />
    </div>
  <?php endif; ?>
  <div class="sa-archive-header__body">
    <p class="sa-archive-header__eyebrow">
      <?php if ($sa_is_shop) : ?>
        <?php esc_html_e('Catalog', 'supreme-autoparts'); ?>
      <?php else : ?>
        <a href="<?php echo esc_url(function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/shop/')); ?>"><?php esc_html_e('Shop', 'supreme-autoparts'); ?></a>
        <?php if ($sa_term && !empty($sa_term->name)) : ?>
          <span aria-hidden="true"> / </span>
          <span><?php echo esc_html($sa_term->name); ?></span>
        <?php endif; ?>
      <?php endif; ?>
    </p>
    <?php if (apply_filters('woocommerce_show_page_title', true)) : ?>
      <h1 class="woocommerce-products-header__title page-title"><?php woocommerce_page_title(); ?></h1>
    <?php endif; ?>
    <?php if ($sa_total > 0) : ?>
      <p class="sa-archive-header__count">
        <?php
        printf(
            /* translators: %s: product count */
            esc_html(_n('%s part', '%s parts', $sa_total, 'supreme-autoparts')),
            esc_html(number_format_i18n($sa_total))
        );
        ?>
      </p>
    <?php endif; ?>
    <div class="sa-archive-header__desc">
      <?php do_action('woocommerce_archive_description'); ?>
    </div>
    <?php if ($sa_is_shop) : ?>
      <p class="sa-archive-header__hint"><?php esc_html_e('Japan · US · UK · EV · motorcycle · USD checkout · shipping to Kenya', 'supreme-autoparts'); ?></p>
    <?php endif; ?>
  </div>
</header>
<?php
if (woocommerce_product_loop()) {
    do_action('woocommerce_before_shop_loop');
    woocommerce_product_loop_start();
    if (wc_get_loop_prop('total')) {
        while (have_posts()) {
            the_post();
            do_action('woocommerce_shop_loop');
            wc_get_template_part('content', 'product');
        }
    }
    woocommerce_product_loop_end();
    do_action('woocommerce_after_shop_loop');
} else {
    do_action('woocommerce_no_products_found');
}

do_action('woocommerce_after_main_content');
get_footer('shop');
