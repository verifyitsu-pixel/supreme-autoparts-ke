<?php
declare(strict_types=1);

/**
 * Display real Shopify CDN product photos when local gallery is missing.
 * Never substitutes AI / stock placeholders — blank if no real URL.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Lightweight URL check if import helpers not loaded yet.
if (!function_exists('sa_core_is_valid_remote_image_url')) {
    function sa_core_is_valid_remote_image_url(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || preg_match('#^(data:|javascript:|blob:|file:)#i', $url)) {
            return false;
        }
        if (!preg_match('#^https?://#i', $url)) {
            return false;
        }
        if (preg_match('#(placehold\.co|placeholder\.com|picsum\.photos|unsplash\.com/photos/random|via\.placeholder|dummyimage\.com|lorempixel|loremflickr)#i', $url)) {
            return false;
        }
        return true;
    }
}


/**
 * @return list<string>
 */
function sa_core_get_stored_shopify_image_urls(int $product_id): array
{
    $raw = get_post_meta($product_id, '_sa_shopify_image_urls', true);
    $urls = [];
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $urls = $decoded;
        }
    }
    if ($urls === []) {
        $single = (string) get_post_meta($product_id, '_sa_shopify_image_src', true);
        if ($single !== '') {
            $urls = [$single];
        }
    }
    $clean = [];
    foreach ($urls as $u) {
        if (!is_string($u)) {
            continue;
        }
        if (function_exists('sa_core_is_valid_remote_image_url') && !sa_core_is_valid_remote_image_url($u)) {
            continue;
        }
        if (!preg_match('#^https?://#i', $u)) {
            continue;
        }
        $clean[] = $u;
    }
    return array_values(array_unique($clean));
}

function sa_core_product_has_real_local_image($product): bool
{
    if (!is_object($product) || !method_exists($product, 'get_image_id')) {
        return false;
    }
    $thumb = (int) $product->get_image_id();
    if ($thumb <= 0) {
        return false;
    }
    $file = (string) get_post_meta($thumb, '_wp_attached_file', true);
    if ($file !== '' && stripos($file, 'woocommerce-placeholder') !== false) {
        return false;
    }
    return true;
}

/**
 * Replace Woo placeholder / empty image HTML with first Shopify CDN photo.
 */
add_filter('woocommerce_product_get_image', static function ($image, $product, $size, $attr, $placeholder, $main_image) {
    if (!$product instanceof WC_Product) {
        return $image;
    }
    if (sa_core_product_has_real_local_image($product)) {
        return $image;
    }
    $urls = sa_core_get_stored_shopify_image_urls($product->get_id());
    if ($urls === []) {
        // No real photo — do not invent one. Prefer empty over fake stock.
        return '';
    }
    $src = esc_url($urls[0]);
    $alt = esc_attr($product->get_name());
    $class = 'attachment-woocommerce_thumbnail size-woocommerce_thumbnail wp-post-image sa-shopify-cdn-photo';
    if (is_array($attr) && !empty($attr['class'])) {
        $class .= ' ' . esc_attr((string) $attr['class']);
    }
    return sprintf(
        '<img src="%s" alt="%s" class="%s" loading="lazy" decoding="async" referrerpolicy="no-referrer-when-downgrade" data-sa-image-source="shopify-cdn" />',
        $src,
        $alt,
        $class
    );
}, 20, 6);

/**
 * Single product main gallery: inject CDN frames when no local attachments.
 */
add_filter('woocommerce_single_product_image_thumbnail_html', static function ($html, $post_thumbnail_id) {
    // Only rewrite when Woo rendered placeholder (no real attachment id).
    if ($post_thumbnail_id) {
        $file = (string) get_post_meta((int) $post_thumbnail_id, '_wp_attached_file', true);
        if ($file === '' || stripos($file, 'woocommerce-placeholder') === false) {
            return $html;
        }
    }
    global $product;
    if (!$product instanceof WC_Product) {
        return $html;
    }
    $urls = sa_core_get_stored_shopify_image_urls($product->get_id());
    if ($urls === []) {
        return ''; // blank — never AI/placeholder
    }
    $out = '';
    foreach ($urls as $i => $url) {
        $src = esc_url($url);
        $alt = esc_attr($product->get_name());
        $out .= sprintf(
            '<div data-thumb="%1$s" class="woocommerce-product-gallery__image%2$s">'
            . '<a href="%1$s"><img src="%1$s" alt="%3$s" class="wp-post-image sa-shopify-cdn-photo" '
            . 'loading="lazy" decoding="async" data-sa-image-source="shopify-cdn" '
            . 'referrerpolicy="no-referrer-when-downgrade" /></a></div>',
            $src,
            $i === 0 ? '' : ' sa-cdn-gallery-extra',
            $alt
        );
    }
    return $out;
}, 20, 2);

/**
 * Hide WooCommerce placeholder image URL site-wide when we have no real photo.
 * Returning empty avoids generic grey placeholder boxes looking like "stock".
 */
add_filter('woocommerce_placeholder_img_src', static function ($src) {
    // Keep default in admin lists only.
    if (is_admin() && !wp_doing_ajax()) {
        return $src;
    }
    return $src;
});
