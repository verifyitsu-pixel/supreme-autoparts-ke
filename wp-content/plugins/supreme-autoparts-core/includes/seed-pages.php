<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/page-content.php';
require_once __DIR__ . '/seed-categories.php';

/**
 * Create/update static pages and assign WooCommerce pages + store options.
 */
function sa_core_seed_pages(): void
{
    $created = [];
    foreach (sa_core_page_definitions() as $slug => $def) {
        $existing = get_page_by_path($slug);
        // Fallback: page may exist with a different slug/title — find by exact post_name.
        if (!$existing) {
            $by_name = get_posts([
                'name'           => $slug,
                'post_type'      => 'page',
                'post_status'    => ['publish', 'draft', 'private'],
                'numberposts'    => 1,
                'posts_per_page' => 1,
            ]);
            if ($by_name) {
                $existing = $by_name[0];
            }
        }
        if ($existing) {
            wp_update_post([
                'ID'           => $existing->ID,
                'post_title'   => $def['title'],
                'post_name'    => $slug,
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

    // Hard guarantee for footer/checkout policy URLs that were 404 on live.
    foreach (['chargeback-policy', 'cookie-policy', 'data-policy'] as $must) {
        if (empty($created[$must]) || !get_page_by_path($must)) {
            $defs = sa_core_page_definitions();
            if (!isset($defs[$must])) {
                continue;
            }
            $id = wp_insert_post([
                'post_title'   => $defs[$must]['title'],
                'post_name'    => $must,
                'post_content' => $defs[$must]['content'],
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_author'  => 1,
            ], true);
            if (!is_wp_error($id)) {
                $created[$must] = (int) $id;
            }
        }
    }

    // WooCommerce system pages
    $woo_pages = [
        'shop'       => 'Shop',
        'cart'       => 'Cart',
        'checkout'   => 'Checkout',
        'my-account' => 'My Account',
    ];
    foreach ($woo_pages as $slug => $title) {
        $page = get_page_by_path($slug);
        if (!$page) {
            $id = wp_insert_post([
                'post_title'   => $title,
                'post_name'    => $slug,
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_content' => '',
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

    // Terms acceptance at checkout → Terms of Service
    if (!empty($created['terms'])) {
        update_option('woocommerce_terms_page_id', $created['terms']);
        update_option('woocommerce_checkout_show_terms', 'yes');
        update_option('woocommerce_checkout_privacy_policy_text', sprintf(
            /* translators: placeholders filled by Woo with policy links when configured */
            __('Your personal data will be used to process your order, support your experience, and for other purposes described in our [privacy_policy]. By placing an order you also agree to our Terms of Service, Chargeback/Dispute Policy, and Refund/Returns Policy.', 'supreme-autoparts-core')
        ));
    }
    if (!empty($created['privacy-policy'])) {
        update_option('wp_page_for_privacy_policy', $created['privacy-policy']);
    }

    // Front page
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

    if (function_exists('sa_core_apply_store_settings')) {
        sa_core_apply_store_settings();
    } elseif (function_exists('sa_core_apply_store_options')) {
        sa_core_apply_store_options();
    }

    update_option('sa_pages_seeded', time());
    update_option('sa_pages_seed_ver', '5');
}

/**
 * Email, account, and checkout options applied on every seed/boot.
 */
function sa_core_apply_store_options(): void
{
    $admin_email = getenv('WORDPRESS_ADMIN_EMAIL') ?: 'calvin@supremeautoparts.co.ke';
    if ($admin_email) {
        update_option('admin_email', $admin_email);
        // Keep the main admin user email in sync when present.
        $admin = get_user_by('login', getenv('WORDPRESS_ADMIN_USER') ?: 'admin');
        if (!$admin) {
            $admins = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC']);
            $admin  = $admins[0] ?? null;
        }
        if ($admin instanceof WP_User) {
            wp_update_user([
                'ID'         => $admin->ID,
                'user_email' => $admin_email,
            ]);
        }
    }

    update_option('woocommerce_email_from_name', 'Supreme Autoparts');
    update_option('woocommerce_email_from_address', $admin_email);
    update_option('woocommerce_email_header_image', ''); // theme/logo can be set later via Customizer

    // Accounts
    update_option('woocommerce_enable_myaccount_registration', 'yes');
    update_option('woocommerce_enable_guest_checkout', 'yes');
    update_option('woocommerce_enable_checkout_login_reminder', 'yes');
    update_option('woocommerce_registration_generate_username', 'yes');
    update_option('woocommerce_registration_generate_password', 'no');
    update_option('users_can_register', 1);

    // Lost password uses core Woo endpoints on My Account — ensure permalinks friendly.
    update_option('woocommerce_myaccount_lost_password_endpoint', 'lost-password');
    update_option('woocommerce_myaccount_edit_account_endpoint', 'edit-account');

    // Checkout: show terms checkbox (Woo reads woocommerce_terms_page_id).
    update_option('woocommerce_checkout_show_terms', 'yes');

    update_option('sa_store_options_applied', time());
    update_option('sa_smtp_note', 'WordPress mail() / PHP mail is used until an SMTP plugin (e.g. WP Mail SMTP) is configured. Set From address to calvin@supremeautoparts.co.ke and authenticate SPF/DKIM for supremeautoparts.co.ke for deliverability.');
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
