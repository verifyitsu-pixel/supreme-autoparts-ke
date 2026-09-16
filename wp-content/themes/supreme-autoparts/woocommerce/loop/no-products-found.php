<?php
/**
 * Empty loop — enquire UI instead of bare Woo message.
 *
 * @package Supreme_Autoparts
 */

defined('ABSPATH') || exit;

$context = 'empty-shop';
if (function_exists('is_product_category') && is_product_category()) {
    $context = 'empty-category';
} elseif (function_exists('is_product_tag') && is_product_tag()) {
    $context = 'empty-category';
}

if (function_exists('sa_render_enquire')) {
    sa_render_enquire(['context' => $context]);
    return;
}

echo '<p class="woocommerce-info">' . esc_html__('No products were found matching your selection.', 'supreme-autoparts') . '</p>';
