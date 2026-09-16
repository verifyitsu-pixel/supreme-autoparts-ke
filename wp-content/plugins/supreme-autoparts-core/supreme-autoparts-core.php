<?php
/**
 * Plugin Name: Supreme Autoparts Core
 * Description: Branding defaults, category seed, static pages, invoices, admin dashboard, and Shopify JSON import helpers for Supreme Autoparts.
 * Version: 1.2.7
 * Author: Supreme Autoparts
 * Text Domain: supreme-autoparts-core
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('SA_CORE_VERSION', '1.2.7');
define('SA_CORE_FILE', __FILE__);
define('SA_CORE_DIR', plugin_dir_path(__FILE__));
define('SA_CORE_URL', plugin_dir_url(__FILE__));

require_once SA_CORE_DIR . 'includes/branding.php';
require_once SA_CORE_DIR . 'includes/seed-categories.php';
require_once SA_CORE_DIR . 'includes/category-thumbnails.php';
require_once SA_CORE_DIR . 'includes/seed-pages.php';
require_once SA_CORE_DIR . 'includes/cli.php';
require_once SA_CORE_DIR . 'includes/admin-import.php';
require_once SA_CORE_DIR . 'includes/product-images.php';
require_once SA_CORE_DIR . 'includes/checkout-terms.php';
require_once SA_CORE_DIR . 'includes/store-settings.php';
require_once SA_CORE_DIR . 'includes/customer-accounts.php';
require_once SA_CORE_DIR . 'includes/invoices.php';
require_once SA_CORE_DIR . 'includes/admin-ultra.php';
require_once SA_CORE_DIR . 'includes/admin-dashboard.php';
require_once SA_CORE_DIR . 'includes/admin-orders.php';
require_once SA_CORE_DIR . 'includes/admin-products.php';
require_once SA_CORE_DIR . 'includes/admin-customers.php';
require_once SA_CORE_DIR . 'includes/admin-leads.php';
// import-shopify.php is loaded by CLI/admin/boot import and by product-images helpers when needed.

register_activation_hook(__FILE__, static function (): void {
    require_once SA_CORE_DIR . 'includes/seed-categories.php';
    require_once SA_CORE_DIR . 'includes/category-thumbnails.php';
    require_once SA_CORE_DIR . 'includes/seed-pages.php';
    require_once SA_CORE_DIR . 'includes/customer-accounts.php';
    if (function_exists('sa_core_seed_categories')) {
        sa_core_seed_categories();
    }
    if (function_exists('sa_core_seed_category_thumbnails')) {
        sa_core_seed_category_thumbnails();
    }
    if (function_exists('sa_core_seed_pages')) {
        sa_core_seed_pages();
    }
    if (function_exists('sa_core_ensure_customer_capabilities')) {
        sa_core_ensure_customer_capabilities();
    }
    if (function_exists('sa_core_apply_store_settings')) {
        sa_core_apply_store_settings();
    }
    update_option('sa_core_activated', time());
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, static function (): void {
    flush_rewrite_rules();
});

add_action('plugins_loaded', static function (): void {
    // Ensure WooCommerce is present before shop helpers.
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-warning"><p>Supreme Autoparts Core works best with WooCommerce active.</p></div>';
        });
    }
});


/**
 * Force page seed when sa_pages_seed_ver bumps (creates missing policy pages on deploy).
 */
add_action('init', static function (): void {
    if (get_option('sa_pages_seed_ver') === '7') {
        return;
    }
    if (!function_exists('sa_core_seed_pages')) {
        return;
    }
    sa_core_seed_pages();
    if (function_exists('sa_core_apply_store_settings')) {
        sa_core_apply_store_settings();
    }
    flush_rewrite_rules(false);
}, 25);

/**
 * On core version bump: re-seed product_cat hierarchy + flush product/product_cat rewrites.
 */
add_action('init', static function (): void {
    if (get_option('sa_core_flush_ver') === SA_CORE_VERSION) {
        return;
    }
    if (function_exists('sa_core_seed_categories') && taxonomy_exists('product_cat')) {
        sa_core_seed_categories();
    }
    flush_rewrite_rules(false);
    update_option('sa_core_flush_ver', SA_CORE_VERSION);
}, 30);

/**
 * Empty-catalog recovery: clear stuck sa_boot_import_* and (re)import when
 * published product count is 0. SUPREME_FORCE_IMPORT=1 clears flags and reimports.
 */
add_action('init', static function (): void {
    if (!class_exists('WooCommerce') || !taxonomy_exists('product_cat')) {
        return;
    }
    $force = (string) (getenv('SUPREME_FORCE_IMPORT') ?: '') === '1';
    $counts = wp_count_posts('product');
    $published = isset($counts->publish) ? (int) $counts->publish : 0;

    // Stale lock: entrypoint may die mid-import leaving sa_boot_import_running=1.
    $running_at = (int) get_option('sa_boot_import_running_at', 0);
    $running = (string) get_option('sa_boot_import_running', '') === '1';
    $running_stale = $running && ($running_at <= 0 || (time() - $running_at) > 600);

    if ($force || $published === 0 || $running_stale) {
        delete_option('sa_boot_import_done');
        delete_option('sa_boot_import_batch50');
        if ($force || $published === 0 || $running_stale) {
            delete_option('sa_boot_import_running');
            delete_option('sa_boot_import_running_at');
        }
    }

    if ($published > 0 && !$force) {
        return;
    }

    // Still marked running and not stale — another worker is importing.
    if ((string) get_option('sa_boot_import_running', '') === '1') {
        return;
    }

    // Short lock so we can retry soon if import yields 0 products.
    if (!$force && get_transient('sa_empty_catalog_import_lock')) {
        return;
    }
    set_transient('sa_empty_catalog_import_lock', 1, 5 * MINUTE_IN_SECONDS);

    if (!wp_next_scheduled('sa_core_empty_catalog_import')) {
        wp_schedule_single_event(time() + 5, 'sa_core_empty_catalog_import');
    }
    // Kick cron on this request so recovery does not wait for a later visitor.
    if (function_exists('spawn_cron')) {
        spawn_cron(time());
    }
}, 40);

add_action('sa_core_empty_catalog_import', static function (): void {
    if (!class_exists('WooCommerce')) {
        return;
    }
    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }
    if (function_exists('wp_raise_memory_limit')) {
        wp_raise_memory_limit('admin');
    }

    $counts = wp_count_posts('product');
    $published = isset($counts->publish) ? (int) $counts->publish : 0;
    $force = (string) (getenv('SUPREME_FORCE_IMPORT') ?: '') === '1';
    if ($published > 0 && !$force) {
        delete_option('sa_boot_import_running');
        delete_option('sa_boot_import_running_at');
        return;
    }
    if ((string) get_option('sa_boot_import_running', '') === '1') {
        $running_at = (int) get_option('sa_boot_import_running_at', 0);
        if ($running_at > 0 && (time() - $running_at) <= 600) {
            return;
        }
    }

    update_option('sa_boot_import_running', 1);
    update_option('sa_boot_import_running_at', time());

    if (function_exists('sa_core_seed_categories')) {
        sa_core_seed_categories();
    }

    // Prefer the smaller batch for web/cron recovery (fast products on shop/brakes),
    // unless SUPREME_IMPORT_FILE or LIMIT explicitly targets the 400 batch.
    $env_file = getenv('SUPREME_IMPORT_FILE') ?: '';
    $candidates = [
        $env_file,
        SA_CORE_DIR . 'data/scrape/chunks/batch-with-images-50.ndjson',
        '/usr/src/supreme-data/scrape/chunks/batch-with-images-50.ndjson',
        SA_CORE_DIR . 'data/scrape/chunks/batch-with-images-400.ndjson',
        '/usr/src/supreme-data/scrape/chunks/batch-with-images-400.ndjson',
    ];
    // If operator asked for 400 via limit/file, put 400 first.
    $limit_env = (int) (getenv('SUPREME_IMPORT_LIMIT') ?: 0);
    if ($limit_env >= 200 || ($env_file !== '' && str_contains($env_file, '400'))) {
        $candidates = [
            $env_file,
            SA_CORE_DIR . 'data/scrape/chunks/batch-with-images-400.ndjson',
            '/usr/src/supreme-data/scrape/chunks/batch-with-images-400.ndjson',
            SA_CORE_DIR . 'data/scrape/chunks/batch-with-images-50.ndjson',
            '/usr/src/supreme-data/scrape/chunks/batch-with-images-50.ndjson',
        ];
    }

    $file = '';
    foreach ($candidates as $c) {
        if (is_string($c) && $c !== '' && is_readable($c)) {
            $file = $c;
            break;
        }
    }
    if ($file === '') {
        delete_option('sa_boot_import_running');
        delete_option('sa_boot_import_running_at');
        delete_transient('sa_empty_catalog_import_lock');
        return;
    }

    $limit = $limit_env;
    if ($limit <= 0) {
        $limit = str_contains($file, 'batch-with-images-400') ? 400 : 50;
    }
    $skip_images = (string) (getenv('SUPREME_IMPORT_SKIP_IMAGES') ?: '1') === '1';

    require_once SA_CORE_DIR . 'includes/import-shopify.php';
    $result = sa_core_import_shopify_products_file($file, [
        'limit'          => $limit,
        'skip_images'    => $skip_images,
        'require_images' => true,
    ]);

    flush_rewrite_rules(false);

    $imported = (int) ($result['imported'] ?? 0) + (int) ($result['updated'] ?? 0);
    $counts_after = wp_count_posts('product');
    $published_after = isset($counts_after->publish) ? (int) $counts_after->publish : 0;

    if ($imported > 0 || $published_after > 0) {
        update_option('sa_boot_import_done', 1);
        update_option('sa_boot_import_batch50', 1);
    } else {
        // Leave done unset so next boot / cron retry can run.
        delete_option('sa_boot_import_done');
        delete_option('sa_boot_import_batch50');
        delete_transient('sa_empty_catalog_import_lock');
    }

    delete_option('sa_boot_import_running');
    delete_option('sa_boot_import_running_at');

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[supreme] empty-catalog import file=' . $file . ' imported=' . $imported . ' published=' . $published_after);
    }
});

add_action('after_switch_theme', static function (): void {
    flush_rewrite_rules();
});
