<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/page-content.php';
require_once __DIR__ . '/seed-categories.php';

/**
 * Create/update static pages and assign WooCommerce pages.
 */
function sa_core_seed_pages(): void
{
    $created = [];
    foreach (sa_core_page_definitions() as $slug => $def) {
        $existing = get_page_by_path($slug);
        if ($existing) {
            wp_update_post([
                'ID'           => $existing->ID,
                'post_title'   => $def['title'],
                'post_content' => $def['content'],
                'post_status'  => 'publish',
            ]);
            $created[$slug] = (int) $existing->ID;
        } else {
            $id = wp_insert_post([
                'post_title'   => $def['title'],
                'post_name'    => $slug,
                'post_content' => $def['content'],
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_author'  => 1,
            ], true);
            if (!is_wp_error($id)) {
                $created[$slug] = (int) $id;
            }
        }
    }

    // WooCommerce system pages
    $woo_pages = [
        'shop'      => 'Shop',
        'cart'      => 'Cart',
        'checkout'  => 'Checkout',
        'my-account'=> 'My Account',
    ];
    foreach ($woo_pages as $slug => $title) {
        $page = get_page_by_path($slug);
        if (!$page) {
            $id = wp_insert_post([
                'post_title'  => $title,
                'post_name'   => $slug,
                'post_status' => 'publish',
                'post_type'   => 'page',
                'post_content'=> '',
            ]);
            if (!is_wp_error($id)) {
                $created[$slug] = (int) $id;
            }
        } else {
            $created[$slug] = (int) $page->ID;
        }
    }

    if (!empty($created['shop'])) {
        update_option('woocommerce_shop_page_id', $created['shop']);
    }
    if (!empty($created['cart'])) {
        update_option('woocommerce_cart_page_id', $created['cart']);
    }
    if (!empty($created['checkout'])) {
        update_option('woocommerce_checkout_page_id', $created['checkout']);
    }
    if (!empty($created['my-account'])) {
        update_option('woocommerce_myaccount_page_id', $created['my-account']);
    }

    // Front page: use a dedicated Home page that theme front-page.php overrides,
    // or simply show latest posts — theme uses front-page.php when is_front_page.
    $home = get_page_by_path('home');
    if (!$home) {
        $home_id = wp_insert_post([
            'post_title'   => 'Home',
            'post_name'    => 'home',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => '',
        ]);
    } else {
        $home_id = $home->ID;
    }
    if (!is_wp_error($home_id) && $home_id) {
        update_option('show_on_front', 'page');
        update_option('page_on_front', (int) $home_id);
    }

    if (function_exists('sa_core_seed_categories')) {
        sa_core_seed_categories();
    }

    update_option('sa_pages_seeded', time());
}

// Allow `wp eval-file .../seed-pages.php` to run seeding when loaded in WP context.
if (defined('ABSPATH') && (defined('WP_CLI') || (isset($GLOBALS['argv'][0]) && str_contains((string) $GLOBALS['argv'][0], 'wp')))) {
    // WP-CLI eval-file will call functions explicitly; no auto-run.
}

// When loaded via `wp eval-file .../seed-pages.php`, run once.
if (defined('WP_CLI') && WP_CLI && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    sa_core_seed_pages();
    WP_CLI::success('sa_core_seed_pages completed.');
}
