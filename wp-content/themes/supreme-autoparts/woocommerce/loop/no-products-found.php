<?php
/**
 * Empty loop — filter-aware empty state + enquire.
 *
 * @package Supreme_Autoparts
 */

defined('ABSPATH') || exit;

$empty = get_template_directory() . '/template-parts/catalog-empty.php';
if (is_readable($empty)) {
    include $empty;
    return;
}

echo '<p class="woocommerce-info">' . esc_html__('No products were found matching your selection.', 'supreme-autoparts') . '</p>';
