<?php
/**
 * Mini-cart (slide-out drawer + widget) — Supreme Autoparts layout.
 * One row per item: thumb · title + compact meta · qty × price · remove.
 *
 * @package supreme-autoparts
 */
declare(strict_types=1);

defined('ABSPATH') || exit;

do_action('woocommerce_before_mini_cart');

$sa_fee_pid = (int) get_option('sa_svc_fee_product', 0);
$sa_logo    = defined('SA_THEME_URI') ? SA_THEME_URI . '/assets/emblem.png?v=emblem2' : '';
?>
<?php if (WC()->cart && !WC()->cart->is_empty()) : ?>
  <ul class="woocommerce-mini-cart cart_list product_list_widget sa-mc">
    <?php
    do_action('woocommerce_before_mini_cart_contents');
    foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
        $_product   = apply_filters('woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key);
        $product_id = apply_filters('woocommerce_cart_item_product_id', $cart_item['product_id'], $cart_item, $cart_item_key);
        if (!$_product || !$_product->exists() || $cart_item['quantity'] <= 0 || !apply_filters('woocommerce_widget_cart_item_visible', true, $cart_item, $cart_item_key)) {
            continue;
        }
        $is_fee    = !empty($cart_item['sa_booking']) || ($sa_fee_pid && (int) $product_id === $sa_fee_pid);
        $name      = apply_filters('woocommerce_cart_item_name', $_product->get_name(), $cart_item, $cart_item_key);
        $permalink = $is_fee ? '' : apply_filters('woocommerce_cart_item_permalink', $_product->is_visible() ? $_product->get_permalink($cart_item) : '', $cart_item, $cart_item_key);
        $line      = apply_filters('woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal($_product, $cart_item['quantity']), $cart_item, $cart_item_key);
        $unit      = apply_filters('woocommerce_cart_item_price', WC()->cart->get_product_price($_product), $cart_item, $cart_item_key);
        ?>
        <li class="woocommerce-mini-cart-item sa-mc__item<?php echo $is_fee ? ' sa-mc__item--fee' : ''; ?> <?php echo esc_attr(apply_filters('woocommerce_mini_cart_item_class', 'mini_cart_item', $cart_item, $cart_item_key)); ?>">
          <div class="sa-mc__thumb">
            <?php if ($is_fee) : ?>
              <span class="sa-mc__icon" aria-hidden="true">
                <?php if ($sa_logo) : ?><img src="<?php echo esc_url($sa_logo); ?>" alt="" width="56" height="56" loading="lazy" /><?php else : ?>
                <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M9 15l2 2 4-4"/></svg>
                <?php endif; ?>
              </span>
            <?php elseif ($permalink) : ?>
              <a href="<?php echo esc_url($permalink); ?>" tabindex="-1"><?php echo $_product->get_image('woocommerce_gallery_thumbnail'); // phpcs:ignore ?></a>
            <?php else : ?>
              <?php echo $_product->get_image('woocommerce_gallery_thumbnail'); // phpcs:ignore ?>
            <?php endif; ?>
          </div>
          <div class="sa-mc__info">
            <p class="sa-mc__title">
              <?php if ($permalink) : ?><a href="<?php echo esc_url($permalink); ?>"><?php echo wp_kses_post($name); ?></a><?php else : ?><?php echo wp_kses_post($name); ?><?php endif; ?>
            </p>
            <?php if ($is_fee) : ?>
              <p class="sa-mc__meta">
                <?php if (!empty($cart_item['sa_booking_ref'])) : ?><span class="sa-mc__chip"><?php echo esc_html('Booking ' . $cart_item['sa_booking_ref']); ?></span><?php endif; ?>
                <span class="sa-mc__note"><?php esc_html_e('Credited to your service bill', 'supreme-autoparts'); ?></span>
              </p>
            <?php else : ?>
              <?php $sku = $_product->get_sku(); if ($sku) : ?><p class="sa-mc__meta"><span class="sa-mc__note"><?php echo esc_html('Part # ' . $sku); ?></span></p><?php endif; ?>
            <?php endif; ?>
            <p class="sa-mc__price">
              <span class="sa-mc__qty"><?php echo esc_html((int) $cart_item['quantity'] . ' × '); ?><?php echo wp_kses_post($unit); ?></span>
              <strong class="sa-mc__line"><?php echo wp_kses_post($line); ?></strong>
            </p>
          </div>
          <?php
          echo apply_filters( // phpcs:ignore
              'woocommerce_cart_item_remove_link',
              sprintf(
                  '<a href="%s" class="remove remove_from_cart_button sa-mc__remove" aria-label="%s" data-product_id="%s" data-cart_item_key="%s" data-product_sku="%s"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></a>',
                  esc_url(wc_get_cart_remove_url($cart_item_key)),
                  /* translators: %s product name */
                  esc_attr(sprintf(__('Remove %s from cart', 'supreme-autoparts'), wp_strip_all_tags($name))),
                  esc_attr((string) $product_id),
                  esc_attr($cart_item_key),
                  esc_attr($_product->get_sku())
              ),
              $cart_item_key
          );
          ?>
        </li>
        <?php
    }
    do_action('woocommerce_mini_cart_contents');
    ?>
  </ul>

  <div class="sa-mc__summary">
    <p class="woocommerce-mini-cart__total total sa-mc__total">
      <span><?php esc_html_e('Subtotal', 'supreme-autoparts'); ?></span>
      <strong><?php echo WC()->cart->get_cart_subtotal(); // phpcs:ignore ?></strong>
    </p>
    <p class="sa-mc__hint"><?php esc_html_e('Shipping and any taxes are calculated at checkout.', 'supreme-autoparts'); ?></p>
    <p class="woocommerce-mini-cart__buttons buttons sa-mc__buttons">
      <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="button checkout wc-forward sa-mc__btn sa-mc__btn--primary"><?php esc_html_e('Checkout', 'supreme-autoparts'); ?></a>
      <a href="<?php echo esc_url(wc_get_cart_url()); ?>" class="button wc-forward sa-mc__btn sa-mc__btn--ghost"><?php esc_html_e('View cart', 'supreme-autoparts'); ?></a>
    </p>
  </div>
<?php else : ?>
  <div class="sa-mc__empty">
    <p class="woocommerce-mini-cart__empty-message"><?php esc_html_e('Your cart is empty.', 'supreme-autoparts'); ?></p>
    <a class="sa-mc__btn sa-mc__btn--primary" href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>"><?php esc_html_e('Shop parts', 'supreme-autoparts'); ?></a>
  </div>
<?php endif; ?>

<?php do_action('woocommerce_after_mini_cart'); ?>
