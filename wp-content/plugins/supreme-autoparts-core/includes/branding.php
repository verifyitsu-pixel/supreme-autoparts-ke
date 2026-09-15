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
        update_option('sa_free_shipping_threshold', getenv('SUPREME_FREE_SHIPPING_THRESHOLD') ?: '15000');
    }
    update_option('sa_branding_applied', 1);
});

/** Remove any accidental Supreme Mods branding from titles. */
add_filter('document_title_parts', static function (array $parts): array {
    foreach ($parts as $k => $v) {
        $parts[$k] = str_ireplace(['Supreme Mods', 'Supreme-Mods.com', 'supreme-mods.com'], ['Supreme Autoparts', 'Supreme Autoparts', 'supremeautoparts.co.ke'], (string) $v);
    }
    return $parts;
});
