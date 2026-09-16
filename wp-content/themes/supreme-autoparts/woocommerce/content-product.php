<?php
/**
 * Product card in loops — real images, geo display price, clear Add to cart.
 *
 * @package Supreme_Autoparts
 */

defined('ABSPATH') || exit;

global $product;

if (empty($product) || !$product->is_visible()) {
    return;
}

$permalink = $product->get_permalink();
$name      = $product->get_name();
?>
<li <?php wc_product_class('sa-product-card', $product); ?>>
  <div class="sa-product-card__media">
    <a href="<?php echo esc_url($permalink); ?>" class="sa-product-card__thumb" aria-label="<?php echo esc_attr($name); ?>">
      <?php echo $product->get_image('woocommerce_thumbnail', ['class' => 'sa-product-card__img', 'loading' => 'lazy', 'decoding' => 'async']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    </a>
    <?php if ($product->is_on_sale()) : ?>
      <span class="sa-badge sa-badge--sale"><?php esc_html_e('Sale', 'supreme-autoparts'); ?></span>
    <?php endif; ?>
  </div>
  <div class="sa-product-card__body">
    <h2 class="woocommerce-loop-product__title sa-product-card__title">
      <a href="<?php echo esc_url($permalink); ?>"><?php echo esc_html($name); ?></a>
    </h2>
    <div class="sa-product-card__price price" aria-label="<?php esc_attr_e('Product price', 'supreme-autoparts'); ?>">
      <?php echo $product->get_price_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    </div>
    <div class="sa-product-card__actions">
      <?php woocommerce_template_loop_add_to_cart(); ?>
    </div>
  </div>
</li>
