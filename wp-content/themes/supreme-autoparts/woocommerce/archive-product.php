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
if (function_exists('is_product_category') && is_product_category()) {
    $term = get_queried_object();
    if ($term && !is_wp_error($term) && !empty($term->slug) && function_exists('sa_category_image_url')) {
        $sa_cat_slug = (string) $term->slug;
        $sa_cat_img = sa_category_image_url($sa_cat_slug);
    }
}
?>
<header class="sa-archive-header<?php echo $sa_cat_img !== '' ? ' sa-archive-header--photo' : ''; ?>">
  <?php if ($sa_cat_img !== '') : ?>
    <div class="sa-archive-header__media" aria-hidden="true">
      <img src="<?php echo esc_url($sa_cat_img); ?>" alt="" class="sa-archive-header__img" loading="eager" decoding="async" width="800" height="450" />
    </div>
  <?php endif; ?>
  <div class="sa-archive-header__body">
    <?php if (apply_filters('woocommerce_show_page_title', true)) : ?>
      <h1 class="woocommerce-products-header__title page-title"><?php woocommerce_page_title(); ?></h1>
    <?php endif; ?>
    <?php do_action('woocommerce_archive_description'); ?>
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
