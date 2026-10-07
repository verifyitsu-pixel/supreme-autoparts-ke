<?php
/**
 * Supreme Autoparts theme functions.
 *
 * @package Supreme_Autoparts
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('SA_THEME_VERSION', '1.4.62');
define('SA_THEME_DIR', get_template_directory());
define('SA_THEME_URI', get_template_directory_uri());

/** One-shot per theme version: pin Woo email header logo URL in options. */
add_action('init', static function (): void {
    $flag = 'sa_email_logo_pin_' . SA_THEME_VERSION;
    if (get_option($flag) === '1') {
        return;
    }
    $logo = '';
    if (defined('SA_THEME_DIR') && defined('SA_THEME_URI')) {
        foreach (['logo-light.jpg', 'logo-light.png'] as $f) {
            if (is_readable(SA_THEME_DIR . '/assets/' . $f)) {
                $logo = set_url_scheme(SA_THEME_URI . '/assets/' . $f, 'https');
                break;
            }
        }
    }
    if ($logo !== '') {
        update_option('woocommerce_email_header_image', $logo);
        update_option('sa_email_logo_url', $logo);
    }
    update_option($flag, '1', false);
}, 5);


add_filter('document_title_parts', static function (array $parts): array {
    if (is_admin() || wp_doing_ajax()) {
        return $parts;
    }
    $site = 'Supreme Autoparts';
    $title = null;
    if (is_front_page() || is_home()) {
        $title = $site;
    } elseif (function_exists('is_shop') && is_shop()) {
        $title = 'Shop | ' . $site;
    } elseif (function_exists('is_product') && is_product()) {
        $title = wp_strip_all_tags((string) get_the_title()) . ' | ' . $site;
    } elseif (function_exists('is_product_category') && is_product_category()) {
        $term = get_queried_object();
        $name = ($term instanceof WP_Term && $term->name !== '') ? $term->name : 'Shop';
        $title = $name . ' | ' . $site;
    } elseif (function_exists('is_product_tag') && is_product_tag()) {
        $term = get_queried_object();
        $name = ($term instanceof WP_Term && $term->name !== '') ? $term->name : 'Shop';
        $title = $name . ' | ' . $site;
    } elseif (function_exists('is_cart') && is_cart()) {
        $title = 'Cart | ' . $site;
    } elseif (function_exists('is_order_received_page') && is_order_received_page()) {
        $title = 'Order received | ' . $site;
    } elseif (function_exists('is_checkout') && is_checkout()) {
        $title = 'Checkout | ' . $site;
    } elseif (function_exists('is_account_page') && is_account_page()) {
        $title = 'Account | ' . $site;
    } elseif (is_search()) {
        $q = get_search_query();
        $title = ($q !== '' ? 'Search: ' . $q : 'Search') . ' | ' . $site;
    }
    if ($title === null) {
        return $parts;
    }
    $parts['title'] = $title;
    $parts['tagline'] = '';
    $parts['site'] = '';
    return $parts;
}, 40);

add_filter('woocommerce_page_title', static function ($title) {
    if (function_exists('is_shop') && is_shop()) {
        return __('Shop', 'supreme-autoparts');
    }
    return $title;
});

require_once SA_THEME_DIR . '/inc/setup.php';
require_once SA_THEME_DIR . '/inc/assets.php';
require_once SA_THEME_DIR . '/inc/image-performance.php';
require_once SA_THEME_DIR . '/inc/megamenu.php';
require_once SA_THEME_DIR . '/inc/woocommerce.php';
require_once SA_THEME_DIR . '/inc/helpers.php';
require_once SA_THEME_DIR . '/inc/enquire.php';
require_once SA_THEME_DIR . '/inc/contact-form.php';

add_action('after_switch_theme', static function (): void {
    flush_rewrite_rules();
});

add_action('init', static function (): void {
    if (get_option('sa_theme_flush_ver') === SA_THEME_VERSION) {
        return;
    }
    flush_rewrite_rules(false);
    update_option('sa_theme_flush_ver', SA_THEME_VERSION);
}, 99);
