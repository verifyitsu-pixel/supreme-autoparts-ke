<?php
/**
 * Single product template override.
 *
 * @package Supreme_Autoparts
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

get_header('shop');

while (have_posts()) {
    the_post();
    do_action('woocommerce_before_main_content');
    echo '<div class="sa-single">';
    wc_get_template_part('content', 'single-product');
    echo '</div>';
    do_action('woocommerce_after_main_content');
}

get_footer('shop');
