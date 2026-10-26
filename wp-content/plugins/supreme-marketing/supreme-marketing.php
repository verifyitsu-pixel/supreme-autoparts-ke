<?php
/**
 * Plugin Name: Supreme Marketing
 * Description: In-house email marketing for Supreme Autoparts: subscribers (site signup = opt-in), automated flows (welcome, abandoned cart/checkout, browse abandonment, post-purchase, reorder, service reminders, win-back, restock), campaigns (newsletter, product and category adverts, offers with promo codes, cross-sell), segmentation, recommendations, personal first-order welcome discounts with abuse protection, approval gating and test mode. Nothing sends to customers until the owner enables the master switch.
 * Version: 1.0.0
 * Author: Supreme Autoparts
 * Text Domain: supreme-marketing
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('SUPM_VERSION', '1.0.0');
define('SUPM_DB_VERSION', '1.3.0');
define('SUPM_FILE', __FILE__);
define('SUPM_DIR', plugin_dir_path(__FILE__));
define('SUPM_URL', plugin_dir_url(__FILE__));

require_once SUPM_DIR . 'inc/helpers.php';
require_once SUPM_DIR . 'inc/class-sm-db.php';
require_once SUPM_DIR . 'inc/class-sm-subscribers.php';
require_once SUPM_DIR . 'inc/class-sm-templates.php';
require_once SUPM_DIR . 'inc/class-sm-segments.php';
require_once SUPM_DIR . 'inc/class-sm-recommend.php';
require_once SUPM_DIR . 'inc/class-sm-flows.php';
require_once SUPM_DIR . 'inc/class-sm-consent.php';
require_once SUPM_DIR . 'inc/class-sm-events.php';
require_once SUPM_DIR . 'inc/class-sm-queue.php';
require_once SUPM_DIR . 'inc/class-sm-sender.php';
require_once SUPM_DIR . 'inc/class-sm-campaigns.php';
require_once SUPM_DIR . 'inc/class-sm-welcome.php';
require_once SUPM_DIR . 'inc/class-sm-public.php';
require_once SUPM_DIR . 'inc/class-sm-admin.php';
require_once SUPM_DIR . 'inc/class-sm-selftest.php';
require_once SUPM_DIR . 'inc/class-sm-ai.php';

register_activation_hook(__FILE__, static function (): void {
    SM_DB::install();
    SM_Queue::schedule_events();
    SM_Subscribers::import_existing_customers();
    sm_seed_settings();
    SM_Welcome::retire_legacy_shared_coupon();
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, static function (): void {
    SM_Queue::unschedule_events();
    flush_rewrite_rules();
});

add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', SUPM_FILE, true);
    }
});

add_action('plugins_loaded', static function (): void {
    SM_DB::maybe_upgrade();
    SM_Consent::init();
    SM_Events::init();
    SM_Queue::init();
    SM_Sender::init();
    SM_Campaigns::init();
    SM_Welcome::init();
    SM_Public::init();
    if (is_admin()) {
        SM_Admin::init();
    }
}, 16);
