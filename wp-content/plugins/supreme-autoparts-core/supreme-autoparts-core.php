<?php
/**
 * Plugin Name: Supreme Autoparts Core
 * Description: Branding defaults, category seed, static pages, and Shopify JSON import helpers for Supreme Autoparts.
 * Version: 1.0.0
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

define('SA_CORE_VERSION', '1.0.0');
define('SA_CORE_FILE', __FILE__);
define('SA_CORE_DIR', plugin_dir_path(__FILE__));
define('SA_CORE_URL', plugin_dir_url(__FILE__));

require_once SA_CORE_DIR . 'includes/branding.php';
require_once SA_CORE_DIR . 'includes/seed-categories.php';
require_once SA_CORE_DIR . 'includes/seed-pages.php';
require_once SA_CORE_DIR . 'includes/cli.php';
require_once SA_CORE_DIR . 'includes/admin-import.php';
require_once SA_CORE_DIR . 'includes/product-images.php';
require_once SA_CORE_DIR . 'includes/checkout-terms.php';
require_once SA_CORE_DIR . 'includes/store-settings.php';
// import-shopify.php is loaded by CLI/admin/boot import and by product-images helpers when needed.

register_activation_hook(__FILE__, static function (): void {
    require_once SA_CORE_DIR . 'includes/seed-categories.php';
    require_once SA_CORE_DIR . 'includes/seed-pages.php';
    if (function_exists('sa_core_seed_categories')) {
        sa_core_seed_categories();
    }
    if (function_exists('sa_core_seed_pages')) {
        sa_core_seed_pages();
    }
    update_option('sa_core_activated', time());
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
