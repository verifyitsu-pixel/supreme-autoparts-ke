<?php
/**
 * Cart — line items on a white page. No invented sellers or sold counts.
 *
 * @package Supreme_Autoparts
 */

defined('ABSPATH') || exit;

do_action('woocommerce_before_cart');

$shop_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/shop/');
$checkout_url = function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : home_url('/checkout/');
$free_ship = function_exists('sa_theme_cart_has_free_shipping') && sa_theme_cart_has_free_shipping();
?>
<div class="sa-cart-page">
  <header class="sa-cart-hero">
    <h1 class="sa-cart-hero__title"><?php esc_html_e('Cart', 'supreme-autoparts'); ?></h1>
    <?php if ($shop_url) : ?>
      <a class="sa-cart-hero__shop" href="<?php echo esc_url($shop_url); ?>"><?php esc_html_e('Continue shopping', 'supreme-autoparts'); ?></a>
    <?php endif; ?>
  </header>

  <div class="sa-cart-layout">
    <div class="sa-cart-layout__main">
      <form id="woocommerce-cart" class="woocommerce-cart-form" action="<?php echo esc_url(wc_get_cart_url()); ?>" method="post">
        <?php do_action('woocommerce_before_cart_table'); ?>
        <ul class="sa-cart-lines">
          <?php do_action('woocommerce_before_cart_contents'); ?>
          <?php
          foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
              $_product   = apply_filters('woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key);
              $product_id = apply_filters('woocommerce_cart_item_product_id', $cart_item['product_id'], $cart_item, $cart_item_key);
              if (!$_product || !$_product->exists() || $cart_item['quantity'] <= 0 || !apply_filters('woocommerce_cart_item_visible', true, $cart_item, $cart_item_key)) {
                  continue;
              }
              $product_permalink = apply_filters('woocommerce_cart_item_permalink', $_product->is_visible() ? $_product->get_permalink($cart_item) : '', $cart_item, $cart_item_key);
              $thumbnail = apply_filters('woocommerce_cart_item_thumbnail', $_product->get_image('woocommerce_thumbnail', [
                  'class'    => 'sa-cart-line__img',
                  'loading'  => 'lazy',
                  'decoding' => 'async',
                  'alt'      => '',
              ]), $cart_item, $cart_item_key);
              if (!is_string($thumbnail) || strpos($thumbnail, '<img') === false) {
                  $thumbnail = '<span class="sa-cart-line__img sa-cart-line__img--empty" aria-hidden="true"></span>';
              }
              $on_sale = $_product->is_on_sale();
              $regular = (float) $_product->get_regular_price();
              $current = (float) $_product->get_price();
              $show_was = $on_sale && $regular > $current && $regular > 0;
              ?>
            <li class="sa-cart-line woocommerce-cart-form__cart-item <?php echo esc_attr(apply_filters('woocommerce_cart_item_class', 'cart_item', $cart_item, $cart_item_key)); ?>">
              <div class="sa-cart-line__photo">
                <?php
                if (!$product_permalink) {
                    echo $thumbnail; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                } else {
                    printf('<a href="%s" tabindex="-1">%s</a>', esc_url($product_permalink), $thumbnail); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                }
                ?>
              </div>
              <div class="sa-cart-line__body">
                <h2 class="sa-cart-line__title">
                  <?php
                  if (!$product_permalink) {
                      echo esc_html($_product->get_name());
                  } else {
                      printf('<a href="%s">%s</a>', esc_url($product_permalink), esc_html($_product->get_name()));
                  }
                  ?>
                </h2>
                <?php
                do_action('woocommerce_after_cart_item_name', $cart_item, $cart_item_key);
                echo wc_get_formatted_cart_item_data($cart_item); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                ?>
                <div class="sa-cart-line__qty">
                  <span class="sa-cart-line__qty-label"><?php esc_html_e('Qty', 'supreme-autoparts'); ?></span>
                  <div class="sa-qty" data-sa-qty>
                    <button type="button" class="sa-qty__btn" data-sa-qty-minus aria-label="<?php esc_attr_e('Decrease quantity', 'supreme-autoparts'); ?>">−</button>
                    <?php
                    if ($_product->is_sold_individually()) {
                        $min_quantity = 1;
                        $max_quantity = 1;
                    } else {
                        $min_quantity = 0;
                        $max_quantity = $_product->get_max_purchase_quantity();
                    }
                    $product_quantity = woocommerce_quantity_input(
                        [
                            'input_name'   => "cart[{$cart_item_key}][qty]",
                            'input_value'  => $cart_item['quantity'],
                            'max_value'    => $max_quantity,
                            'min_value'    => $min_quantity,
                            'product_name' => $_product->get_name(),
                        ],
                        $_product,
                        false
                    );
                    echo apply_filters('woocommerce_cart_item_quantity', $product_quantity, $cart_item_key, $cart_item); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    ?>
                    <button type="button" class="sa-qty__btn" data-sa-qty-plus aria-label="<?php esc_attr_e('Increase quantity', 'supreme-autoparts'); ?>">+</button>
                  </div>
                </div>
                <p class="sa-cart-line__price">
                  <span class="sa-cart-line__now"><?php echo apply_filters('woocommerce_cart_item_price', WC()->cart->get_product_price($_product), $cart_item, $cart_item_key); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                  <?php if ($show_was) : ?>
                    <del class="sa-cart-line__was"><?php echo wp_kses_post(wc_price($regular)); ?></del>
                  <?php endif; ?>
                </p>
                <?php if ($free_ship) : ?>
                  <p class="sa-cart-line__ship"><?php esc_html_e('Free shipping', 'supreme-autoparts'); ?></p>
                <?php endif; ?>
                <p class="sa-cart-line__links">
                  <?php if ($_product->is_purchasable() && $_product->is_in_stock()) : ?>
                    <a class="sa-cart-line__buy" href="<?php echo esc_url($checkout_url); ?>"><?php esc_html_e('Buy it now', 'supreme-autoparts'); ?></a>
                  <?php endif; ?>
                  <?php
                  echo apply_filters( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                      'woocommerce_cart_item_remove_link',
                      sprintf(
                          '<a href="%s" class="remove sa-cart-line__remove" aria-label="%s" data-product_id="%s" data-product_sku="%s">%s</a>',
                          esc_url(wc_get_cart_remove_url($cart_item_key)),
                          esc_attr__('Remove this item', 'supreme-autoparts'),
                          esc_attr((string) $product_id),
                          esc_attr($_product->get_sku()),
                          esc_html__('Remove', 'supreme-autoparts')
                      ),
                      $cart_item_key
                  );
                  ?>
                </p>
              </div>
            </li>
              <?php
          }
          do_action('woocommerce_cart_contents');
          ?>
        </ul>
        <p class="sa-cart-update-row">
          <button type="submit" class="button sa-btn sa-btn--outline sa-cart-update" name="update_cart" value="<?php esc_attr_e('Update cart', 'supreme-autoparts'); ?>"><?php esc_html_e('Update cart', 'supreme-autoparts'); ?></button>
          <?php do_action('woocommerce_cart_actions'); ?>
          <?php wp_nonce_field('woocommerce-cart', 'woocommerce-cart-nonce'); ?>
        </p>
        <?php do_action('woocommerce_after_cart_contents'); ?>
        <?php do_action('woocommerce_after_cart_table'); ?>
      </form>
      <?php do_action('woocommerce_before_cart_collaterals'); ?>
    </div>

    <aside class="sa-cart-layout__summary">
      <div class="sa-cart-summary cart-collaterals">
        <?php do_action('woocommerce_cart_collaterals'); ?>
        <p class="sa-cart-ship-note">
          <?php
          printf(
              /* translators: %s: formatted free-shipping threshold */
              esc_html__('Free US shipping on orders %s and up. Shipping is calculated at checkout when the order is under that.', 'supreme-autoparts'),
              esc_html(function_exists('sa_free_shipping_threshold') ? sa_free_shipping_threshold() : '$99')
          );
          ?>
        </p>
        <div class="sa-cart-trust">
          <a href="<?php echo esc_url(sa_page_url('shipping-policy')); ?>"><?php esc_html_e('Shipping', 'supreme-autoparts'); ?></a>
          <a href="<?php echo esc_url(sa_page_url('returns')); ?>"><?php esc_html_e('Returns', 'supreme-autoparts'); ?></a>
          <a href="<?php echo esc_url(sa_page_url('refund-policy')); ?>"><?php esc_html_e('Refunds', 'supreme-autoparts'); ?></a>
          <a href="<?php echo esc_url(sa_page_url('terms')); ?>"><?php esc_html_e('Terms', 'supreme-autoparts'); ?></a>
        </div>
      </div>
    </aside>
  </div>
</div>
<?php do_action('woocommerce_after_cart'); ?>
