<?php
/**
 * Modern cart page — line items, qty steppers, sticky summary, trust links.
 *
 * @package Supreme_Autoparts
 */

defined('ABSPATH') || exit;

do_action('woocommerce_before_cart');
?>
<div class="sa-cart-layout">
  <div class="sa-cart-layout__main">
    <form class="woocommerce-cart-form" action="<?php echo esc_url(wc_get_cart_url()); ?>" method="post">
      <?php do_action('woocommerce_before_cart_table'); ?>
      <table class="shop_table shop_table_responsive cart woocommerce-cart-form__contents sa-cart-table" cellspacing="0">
        <thead>
          <tr>
            <th class="product-remove"><span class="screen-reader-text"><?php esc_html_e('Remove item', 'supreme-autoparts'); ?></span></th>
            <th class="product-thumbnail"><span class="screen-reader-text"><?php esc_html_e('Thumbnail', 'supreme-autoparts'); ?></span></th>
            <th class="product-name"><?php esc_html_e('Product', 'supreme-autoparts'); ?></th>
            <th class="product-price"><?php esc_html_e('Price', 'supreme-autoparts'); ?></th>
            <th class="product-quantity"><?php esc_html_e('Quantity', 'supreme-autoparts'); ?></th>
            <th class="product-subtotal"><?php esc_html_e('Subtotal', 'supreme-autoparts'); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php do_action('woocommerce_before_cart_contents'); ?>
          <?php
          foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
              $_product   = apply_filters('woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key);
              $product_id = apply_filters('woocommerce_cart_item_product_id', $cart_item['product_id'], $cart_item, $cart_item_key);
              if ($_product && $_product->exists() && $cart_item['quantity'] > 0 && apply_filters('woocommerce_cart_item_visible', true, $cart_item, $cart_item_key)) {
                  $product_permalink = apply_filters('woocommerce_cart_item_permalink', $_product->is_visible() ? $_product->get_permalink($cart_item) : '', $cart_item, $cart_item_key);
                  ?>
                  <tr class="woocommerce-cart-form__cart-item <?php echo esc_attr(apply_filters('woocommerce_cart_item_class', 'cart_item', $cart_item, $cart_item_key)); ?>">
                    <td class="product-remove">
                      <?php
                      echo apply_filters( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                          'woocommerce_cart_item_remove_link',
                          sprintf(
                              '<a href="%s" class="remove" aria-label="%s" data-product_id="%s" data-product_sku="%s">&times;</a>',
                              esc_url(wc_get_cart_remove_url($cart_item_key)),
                              esc_html__('Remove this item', 'supreme-autoparts'),
                              esc_attr((string) $product_id),
                              esc_attr($_product->get_sku())
                          ),
                          $cart_item_key
                      );
                      ?>
                    </td>
                    <td class="product-thumbnail">
                      <?php
                      $thumbnail = apply_filters('woocommerce_cart_item_thumbnail', $_product->get_image('woocommerce_thumbnail', ['class' => 'attachment-woocommerce_thumbnail size-woocommerce_thumbnail', 'loading' => 'lazy', 'decoding' => 'async', 'sizes' => '64px']), $cart_item, $cart_item_key);
                      if (!$product_permalink) {
                          echo $thumbnail; // phpcs:ignore
                      } else {
                          printf('<a href="%s">%s</a>', esc_url($product_permalink), $thumbnail); // phpcs:ignore
                      }
                      ?>
                    </td>
                    <td class="product-name" data-title="<?php esc_attr_e('Product', 'supreme-autoparts'); ?>">
                      <?php
                      if (!$product_permalink) {
                          echo wp_kses_post(apply_filters('woocommerce_cart_item_name', $_product->get_name(), $cart_item, $cart_item_key) . '&nbsp;');
                      } else {
                          echo wp_kses_post(apply_filters('woocommerce_cart_item_name', sprintf('<a href="%s">%s</a>', esc_url($product_permalink), $_product->get_name()), $cart_item, $cart_item_key));
                      }
                      do_action('woocommerce_after_cart_item_name', $cart_item, $cart_item_key);
                      echo wc_get_formatted_cart_item_data($cart_item); // phpcs:ignore
                      ?>
                    </td>
                    <td class="product-price" data-title="<?php esc_attr_e('Price', 'supreme-autoparts'); ?>">
                      <?php echo apply_filters('woocommerce_cart_item_price', WC()->cart->get_product_price($_product), $cart_item, $cart_item_key); // phpcs:ignore ?>
                    </td>
                    <td class="product-quantity" data-title="<?php esc_attr_e('Quantity', 'supreme-autoparts'); ?>">
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
                        echo apply_filters('woocommerce_cart_item_quantity', $product_quantity, $cart_item_key, $cart_item); // phpcs:ignore
                        ?>
                        <button type="button" class="sa-qty__btn" data-sa-qty-plus aria-label="<?php esc_attr_e('Increase quantity', 'supreme-autoparts'); ?>">+</button>
                      </div>
                    </td>
                    <td class="product-subtotal" data-title="<?php esc_attr_e('Subtotal', 'supreme-autoparts'); ?>">
                      <?php echo apply_filters('woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal($_product, $cart_item['quantity']), $cart_item, $cart_item_key); // phpcs:ignore ?>
                    </td>
                  </tr>
                  <?php
              }
          }
          do_action('woocommerce_cart_contents');
          ?>
          <tr>
            <td colspan="6" class="actions">
              <button type="submit" class="button sa-btn sa-btn--outline" name="update_cart" value="<?php esc_attr_e('Update cart', 'supreme-autoparts'); ?>"><?php esc_html_e('Update cart', 'supreme-autoparts'); ?></button>
              <?php do_action('woocommerce_cart_actions'); ?>
              <?php wp_nonce_field('woocommerce-cart', 'woocommerce-cart-nonce'); ?>
            </td>
          </tr>
          <?php do_action('woocommerce_after_cart_contents'); ?>
        </tbody>
      </table>
      <?php do_action('woocommerce_after_cart_table'); ?>
    </form>
    <?php do_action('woocommerce_before_cart_collaterals'); ?>
  </div>

  <aside class="sa-cart-layout__summary">
    <div class="sa-cart-summary cart-collaterals">
      <?php do_action('woocommerce_cart_collaterals'); ?>
      <div class="sa-cart-trust">
        <a href="<?php echo esc_url(sa_page_url('shipping-policy')); ?>"><?php esc_html_e('Shipping policy', 'supreme-autoparts'); ?></a>
        <a href="<?php echo esc_url(sa_page_url('returns')); ?>"><?php esc_html_e('Returns', 'supreme-autoparts'); ?></a>
        <a href="<?php echo esc_url(sa_page_url('refund-policy')); ?>"><?php esc_html_e('Refunds', 'supreme-autoparts'); ?></a>
        <a href="<?php echo esc_url(sa_page_url('terms')); ?>"><?php esc_html_e('Terms', 'supreme-autoparts'); ?></a>
      </div>
    </div>
  </aside>
</div>
<?php do_action('woocommerce_after_cart'); ?>
