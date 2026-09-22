<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

add_filter('option_blogname', static function ($value) {
    if (empty($value) || stripos((string) $value, 'Supreme Mods') !== false) {
        return 'Supreme Autoparts';
    }
    return $value;
});

add_action('init', static function (): void {
    if (get_option('sa_branding_applied')) {
        return;
    }
    $name = get_option('blogname');
    if (!$name || stripos((string) $name, 'mods') !== false) {
        update_option('blogname', 'Supreme Autoparts');
    }
    $tagline = get_option('blogdescription');
    if (!$tagline || stripos((string) $tagline, 'mods') !== false) {
        update_option('blogdescription', 'Auto Parts & Accessories | Car, Truck, SUV, Jeep — supremeautoparts.co.ke');
    }
    if (!get_option('sa_free_shipping_threshold')) {
        update_option('sa_free_shipping_threshold', getenv('SUPREME_FREE_SHIPPING_THRESHOLD') ?: '99');
    }
    update_option('sa_branding_applied', 1);
});

/**
 * Migrate legacy free-shipping threshold: values > 1000 were stored as KES but
 * passed through wc_price as USD (producing absurd local display amounts).
 * New canonical unit is USD (checkout currency); default $99.
 */
add_action('init', static function (): void {
    if (get_option('sa_free_shipping_threshold_migrated_usd99')) {
        return;
    }
    $env = getenv('SUPREME_FREE_SHIPPING_THRESHOLD');
    if ($env !== false && $env !== '') {
        // Explicit env wins; just mark migrated so we do not fight deploys.
        update_option('sa_free_shipping_threshold_migrated_usd99', 1);
        return;
    }
    $current = get_option('sa_free_shipping_threshold', '');
    if ($current === '' || $current === false) {
        update_option('sa_free_shipping_threshold', '99');
    } elseif ((float) $current > 1000) {
        update_option('sa_free_shipping_threshold', '99');
    }
    update_option('sa_free_shipping_threshold_migrated_usd99', 1);
}, 5);

/** Remove any accidental Supreme Mods branding from titles. */
add_filter('document_title_parts', static function (array $parts): array {
    foreach ($parts as $k => $v) {
        $parts[$k] = str_ireplace(['Supreme Mods', 'Supreme-Mods.com', 'supreme-mods.com'], ['Supreme Autoparts', 'Supreme Autoparts', 'supremeautoparts.co.ke'], (string) $v);
    }
    return $parts;
});
