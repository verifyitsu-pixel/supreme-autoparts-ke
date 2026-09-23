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

define('SA_THEME_VERSION', '1.4.25');
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
