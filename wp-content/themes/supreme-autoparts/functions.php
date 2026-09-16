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

define('SA_THEME_VERSION', '1.4.7');
define('SA_THEME_DIR', get_template_directory());
define('SA_THEME_URI', get_template_directory_uri());

require_once SA_THEME_DIR . '/inc/setup.php';
require_once SA_THEME_DIR . '/inc/assets.php';
require_once SA_THEME_DIR . '/inc/image-performance.php';
require_once SA_THEME_DIR . '/inc/megamenu.php';
require_once SA_THEME_DIR . '/inc/woocommerce.php';
require_once SA_THEME_DIR . '/inc/helpers.php';
require_once SA_THEME_DIR . '/inc/enquire.php';

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
