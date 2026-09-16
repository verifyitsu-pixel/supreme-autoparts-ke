<?php
/**
 * Product card in loops — sharp grid, real images, KES price.
 *
 * @package Supreme_Autoparts
 */

defined('ABSPATH') || exit;

global $product;

if (empty($product) || !$product->is_visible()) {
    return;
}
?>
<li <?php wc_product_class('sa-product-card', $product); ?>>
  <div class="sa-product-card__media">
    <a href="<?php echo esc_url($product->get_permalink()); ?>" class="sa-product-card__thumb" aria-label="<?php echo esc_attr($product->get_name()); ?>">
      <?php echo $product->get_image('woocommerce_thumbnail', ['class' => 'sa-product-card__img']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    </a>
    <?php if ($product->is_on_sale()) : ?>
      <span class="sa-badge sa-badge--sale"><?php esc_html_e('Sale', 'supreme-autoparts'); ?></span>
    <?php endif; ?>
  </div>
  <div class="sa-product-card__body">
    <h2 class="woocommerce-loop-product__title sa-product-card__title">
      <a href="<?php echo esc_url($product->get_permalink()); ?>"><?php echo esc_html($product->get_name()); ?></a>
    </h2>
    <div class="sa-product-card__price price"><?php echo $product->get_price_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
    <div class="sa-product-card__actions">
      <?php woocommerce_template_loop_add_to_cart(); ?>
    </div>
  </div>
</li>
