<?php
/**
 * My Account navigation — charcoal/amber, clear order.
 *
 * @package Supreme_Autoparts
 */

defined('ABSPATH') || exit;

do_action('woocommerce_before_account_navigation');
?>
<nav class="woocommerce-MyAccount-navigation sa-account-nav" aria-label="<?php esc_attr_e('Account pages', 'supreme-autoparts'); ?>">
  <ul>
    <?php foreach (wc_get_account_menu_items() as $endpoint => $label) : ?>
      <?php
      $classes = wc_get_account_menu_item_classes($endpoint);
      $class_str = is_array($classes) ? implode(' ', $classes) : (string) $classes;
      $current = str_contains($class_str, 'is-active');
      if ($endpoint === 'customer-logout') {
          $class_str .= ' sa-account-nav__logout';
      }
      ?>
      <li class="<?php echo esc_attr($class_str); ?>">
        <a href="<?php echo esc_url(wc_get_account_endpoint_url($endpoint)); ?>"<?php echo $current ? ' aria-current="page"' : ''; ?>>
          <?php echo esc_html($label); ?>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
</nav>
<?php
do_action('woocommerce_after_account_navigation');
