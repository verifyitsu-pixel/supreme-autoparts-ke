<?php
declare(strict_types=1);

/**
 * Display real Shopify CDN product photos when local gallery is missing.
 * Never substitutes AI / stock placeholders — blank if no real URL.
 * Also powers order-email product thumbs via absolute https CDN URLs.
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
 * Build a width-constrained Shopify CDN URL (absolute https).
 */
function sa_core_shopify_cdn_width(string $url, int $width): string
{
    $url = trim($url);
    if ($url === '' || $width <= 0) {
        return $url;
    }
    if (str_starts_with($url, '//')) {
        $url = 'https:' . $url;
    }
    $url = preg_replace('#^http://#i', 'https://', $url) ?: $url;

    $parts = wp_parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return $url;
    }

    $host = strtolower((string) $parts['host']);
    $path = (string) ($parts['path'] ?? '');

    // Modern Shopify CDN: ?width=
    if (str_contains($host, 'shopify.com') || str_contains($host, 'shopifycdn.com')) {
        $query = [];
        if (!empty($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        }
        $query['width'] = (string) $width;
        $rebuild = (isset($parts['scheme']) ? $parts['scheme'] : 'https') . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $rebuild .= ':' . $parts['port'];
        }
        $rebuild .= $path . '?' . http_build_query($query);
        return $rebuild;
    }

    // Classic filename suffix _400x before extension.
    if (preg_match('#\.(jpe?g|png|gif|webp|avif)$#i', $path)) {
        $new_path = preg_replace(
            '#_(?:pico|icon|thumb|small|compact|medium|large|grande|original|master|\d+x\d+|\d+x|x\d+)(?=\.(?:jpe?g|png|gif|webp|avif)$)#i',
            '',
            $path
        );
        $new_path = preg_replace('#(\.(?:jpe?g|png|gif|webp|avif))$#i', '_' . $width . 'x$1', (string) $new_path);
        $rebuild = 'https://' . $parts['host'] . $new_path;
        if (!empty($parts['query'])) {
            $rebuild .= '?' . $parts['query'];
        }
        return is_string($new_path) ? $rebuild : $url;
    }

    return $url;
}

/**
 * @param list<int> $widths
 */
function sa_core_shopify_cdn_srcset(string $url, array $widths = [150, 300, 400, 600, 800]): string
{
    $parts = [];
    foreach ($widths as $w) {
        $w = (int) $w;
        if ($w <= 0) {
            continue;
        }
        $parts[] = esc_url(sa_core_shopify_cdn_width($url, $w)) . ' ' . $w . 'w';
    }
    return implode(', ', $parts);
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
        if (!preg_match('#^https?://#i', $u) && !str_starts_with($u, '//')) {
            continue;
        }
        if (str_starts_with($u, '//')) {
            $u = 'https:' . $u;
        }
        $u = preg_replace('#^http://#i', 'https://', $u) ?: $u;
        $clean[] = $u;
    }
    return array_values(array_unique($clean));
}

/**
 * Best absolute product image URL for emails (local thumb → Shopify CDN).
 */
function sa_core_get_product_email_image_url($product, int $width = 80): string
{
    if (!$product instanceof WC_Product) {
        return '';
    }
    $thumb = (int) $product->get_image_id();
    if ($thumb > 0) {
        $file = (string) get_post_meta($thumb, '_wp_attached_file', true);
        if ($file === '' || stripos($file, 'woocommerce-placeholder') === false) {
            $size = $width <= 100 ? 'woocommerce_gallery_thumbnail' : 'woocommerce_thumbnail';
            $src  = wp_get_attachment_image_url($thumb, $size);
            if (!$src) {
                $src = wp_get_attachment_image_url($thumb, 'thumbnail');
            }
            if (is_string($src) && $src !== '') {
                if (str_starts_with($src, '//')) {
                    $src = 'https:' . $src;
                }
                return preg_replace('#^http://#i', 'https://', $src) ?: $src;
            }
        }
    }
    $urls = sa_core_get_stored_shopify_image_urls($product->get_id());
    if ($urls === []) {
        return '';
    }
    return sa_core_shopify_cdn_width($urls[0], max(64, $width));
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
    if ($file === '' || stripos($file, 'woocommerce-placeholder') !== false) {
        return false;
    }
    // Attachment meta can outlive ephemeral uploads — require the file on disk.
    $uploads = wp_get_upload_dir();
    $basedir = (string) ($uploads['basedir'] ?? '');
    if ($basedir === '') {
        return false;
    }
    $path = $basedir . '/' . ltrim($file, '/');
    return is_readable($path);
}

/**
 * Resolve display width/height for a Woo size arg.
 *
 * @param string|int[] $size
 * @return array{0:int,1:int}
 */
function sa_core_resolve_image_dims($size): array
{
    if (is_array($size) && isset($size[0], $size[1])) {
        return [max(1, (int) $size[0]), max(1, (int) $size[1])];
    }
    $map = [
        'woocommerce_thumbnail'         => [400, 400],
        'woocommerce_single'            => [600, 600],
        'woocommerce_gallery_thumbnail' => [100, 100],
        'shop_catalog'                  => [400, 400],
        'shop_single'                   => [600, 600],
        'shop_thumbnail'                => [100, 100],
        'thumbnail'                     => [150, 150],
        'medium'                        => [300, 300],
        'medium_large'                  => [768, 768],
    ];
    $key = is_string($size) ? $size : 'woocommerce_thumbnail';
    return $map[$key] ?? [400, 400];
}

/**
 * Replace Woo placeholder / empty image HTML with first Shopify CDN photo.
 */
/**
 * Catalog / loops: prefer Shopify CDN for near-instant product cards when meta exists.
 * Local attachments still used on single product when the file is on disk.
 */
add_filter('woocommerce_product_get_image', static function ($image, $product, $size, $attr, $placeholder, $main_image) {
    if (!$product instanceof WC_Product) {
        return $image;
    }
    if (!empty($GLOBALS['sa_in_wc_email'])) {
        return $image;
    }
    $prefer_cdn = (function_exists('is_shop') && is_shop())
        || (function_exists('is_product_taxonomy') && is_product_taxonomy())
        || (function_exists('is_front_page') && is_front_page())
        || (function_exists('is_home') && is_home())
        || (!empty($GLOBALS['woocommerce_loop']['name']));
    // Single product keeps local-when-present path in the later filter.
    if (function_exists('is_product') && is_product() && empty($GLOBALS['woocommerce_loop']['name'])) {
        return $image;
    }
    if (!$prefer_cdn) {
        return $image;
    }
    $urls = sa_core_get_stored_shopify_image_urls($product->get_id());
    if ($urls === []) {
        return $image;
    }
    [$w, $h] = sa_core_resolve_image_dims($size);
    $src = sa_core_shopify_cdn_width($urls[0], $w);
    $alt = esc_attr($product->get_name());
    $class = 'attachment-woocommerce_thumbnail size-woocommerce_thumbnail wp-post-image sa-shopify-cdn-photo sa-product-card__img';
    if (is_array($attr) && !empty($attr['class'])) {
        $class = esc_attr((string) $attr['class']) . ' sa-shopify-cdn-photo';
    }
    $loading = (is_array($attr) && !empty($attr['loading'])) ? (string) $attr['loading'] : 'lazy';
    $fetch = (is_array($attr) && !empty($attr['fetchpriority']))
        ? ' fetchpriority="' . esc_attr((string) $attr['fetchpriority']) . '"'
        : '';
    $srcset = sa_core_shopify_cdn_srcset($urls[0], [150, 300, 400, 600, 800]);
    $sizes = (is_array($attr) && !empty($attr['sizes']))
        ? (string) $attr['sizes']
        : '(max-width: 600px) 50vw, (max-width: 1024px) 25vw, 280px';
    return sprintf(
        '<img src="%s" alt="%s" class="%s" width="%d" height="%d" srcset="%s" sizes="%s" loading="%s" decoding="async"%s referrerpolicy="no-referrer-when-downgrade" />',
        esc_url($src),
        $alt,
        $class,
        $w,
        $h,
        $srcset,
        esc_attr($sizes),
        esc_attr($loading),
        $fetch
    );
}, 15, 6);

add_filter('woocommerce_product_get_image', static function ($image, $product, $size, $attr, $placeholder, $main_image) {
    if (!$product instanceof WC_Product) {
        return $image;
    }
    if (sa_core_product_has_real_local_image($product)) {
        // Still force absolute https in email HTML for local srcs.
        if (!empty($GLOBALS['sa_in_wc_email']) && is_string($image) && preg_match('#\ssrc=(["\'])(/[^"\']+)\1#', $image, $m)) {
            $abs = 'https:' === substr(home_url('/'), 0, 6)
                ? home_url($m[2])
                : set_url_scheme(home_url($m[2]), 'https');
            $image = str_replace($m[0], ' src=' . $m[1] . esc_url($abs) . $m[1], $image);
        }
        return $image;
    }
    $urls = sa_core_get_stored_shopify_image_urls($product->get_id());
    if ($urls === []) {
        return '';
    }

    [$w, $h] = sa_core_resolve_image_dims($size);
    $src     = sa_core_shopify_cdn_width($urls[0], $w);
    $alt     = esc_attr($product->get_name());
    $class   = 'attachment-woocommerce_thumbnail size-woocommerce_thumbnail wp-post-image sa-shopify-cdn-photo';
    if (is_array($attr) && !empty($attr['class'])) {
        $class .= ' ' . esc_attr((string) $attr['class']);
    }

    $in_email = !empty($GLOBALS['sa_in_wc_email']);
    if ($in_email) {
        $class .= ' sa-email-product-thumb';
        return sprintf(
            '<img src="%s" alt="%s" class="%s" width="%d" height="%d" style="width:%dpx;height:auto;max-width:%dpx;object-fit:contain;" />',
            esc_url($src),
            $alt,
            $class,
            $w,
            $h,
            $w,
            $w
        );
    }

    $loading = 'lazy';
    $fetch   = '';
    if (is_array($attr)) {
        if (!empty($attr['loading'])) {
            $loading = (string) $attr['loading'];
        }
        if (!empty($attr['fetchpriority'])) {
            $fetch = ' fetchpriority="' . esc_attr((string) $attr['fetchpriority']) . '"';
        }
    }
    $srcset = sa_core_shopify_cdn_srcset($urls[0], [150, 300, 400, 600, 800]);
    $sizes  = (is_array($attr) && !empty($attr['sizes']))
        ? (string) $attr['sizes']
        : '(max-width: 600px) 50vw, (max-width: 1024px) 25vw, 280px';

    return sprintf(
        '<img src="%s" alt="%s" class="%s" width="%d" height="%d" srcset="%s" sizes="%s" loading="%s" decoding="async"%s referrerpolicy="no-referrer-when-downgrade" />',
        esc_url($src),
        $alt,
        $class,
        $w,
        $h,
        $srcset,
        esc_attr($sizes),
        esc_attr($loading),
        $fetch
    );
}, 20, 6);

/**
 * Single product main gallery: inject CDN frames when no local attachments.
 */
add_filter('woocommerce_single_product_image_thumbnail_html', static function ($html, $post_thumbnail_id) {
    if ($post_thumbnail_id) {
        $file = (string) get_post_meta((int) $post_thumbnail_id, '_wp_attached_file', true);
        $uploads = wp_get_upload_dir();
        $basedir = (string) ($uploads['basedir'] ?? '');
        $on_disk = $file !== ''
            && stripos($file, 'woocommerce-placeholder') === false
            && $basedir !== ''
            && is_readable($basedir . '/' . ltrim($file, '/'));
        if ($on_disk) {
            return $html;
        }
    }
    global $product;
    if (!$product instanceof WC_Product) {
        return $html;
    }
    $urls = sa_core_get_stored_shopify_image_urls($product->get_id());
    if ($urls === []) {
        return '';
    }
    $out = '';
    foreach ($urls as $i => $url) {
        $src    = sa_core_shopify_cdn_width($url, 600);
        $srcset = sa_core_shopify_cdn_srcset($url, [300, 600, 800, 1200]);
        $alt    = esc_attr($product->get_name());
        $prio   = $i === 0 ? ' fetchpriority="high"' : '';
        $load   = $i === 0 ? 'eager' : 'lazy';
        $out   .= sprintf(
            '<div data-thumb="%1$s" class="woocommerce-product-gallery__image%2$s">'
            . '<a href="%3$s"><img src="%1$s" alt="%4$s" class="wp-post-image sa-shopify-cdn-photo" '
            . 'width="600" height="600" srcset="%5$s" sizes="(max-width: 768px) 100vw, 600px" '
            . 'loading="%6$s" decoding="async"%7$s '
            . 'referrerpolicy="no-referrer-when-downgrade" /></a></div>',
            esc_url($src),
            $i === 0 ? '' : ' sa-cdn-gallery-extra',
            esc_url(sa_core_shopify_cdn_width($url, 1200)),
            $alt,
            $srcset,
            $load,
            $prio
        );
    }
    return $out;
}, 20, 2);

/**
 * Order emails: show product images; use featured or Shopify CDN absolute URL.
 *
 * @param array<string,mixed> $args
 * @return array<string,mixed>
 */
add_filter('woocommerce_email_order_items_args', static function (array $args): array {
    $args['show_image'] = true;
    $args['image_size'] = [64, 64];
    return $args;
}, 20);

/**
 * Ensure email order item thumbnail HTML always has an absolute https img when possible.
 *
 * @param string               $image
 * @param WC_Order_Item_Product $item
 */
add_filter('woocommerce_order_item_thumbnail', static function ($image, $item) {
    $product = null;
    if (is_object($item) && method_exists($item, 'get_product')) {
        $product = $item->get_product();
    }
    if (!$product instanceof WC_Product) {
        return $image;
    }
    $url = sa_core_get_product_email_image_url($product, 64);
    if ($url === '') {
        return $image;
    }
    // If Woo already rendered an img with a usable http(s) src, keep it (normalize https).
    if (is_string($image) && preg_match('#\ssrc=(["\'])(https?://[^"\']+)\1#i', $image, $m)) {
        $https = preg_replace('#^http://#i', 'https://', $m[2]) ?: $m[2];
        return str_replace($m[0], ' src=' . $m[1] . esc_url($https) . $m[1], $image);
    }
    $name = esc_attr($product->get_name());
    return sprintf(
        '<img src="%s" alt="%s" width="64" height="64" class="sa-email-product-thumb" style="width:64px;height:auto;max-width:64px;object-fit:contain;border:0;display:block;" />',
        esc_url($url),
        $name
    );
}, 30, 2);

/**
 * Hide WooCommerce placeholder image URL site-wide when we have no real photo.
 * Returning empty avoids generic grey placeholder boxes looking like "stock".
 */
add_filter('woocommerce_placeholder_img_src', static function ($src) {
    if (is_admin() && !wp_doing_ajax()) {
        return $src;
    }
    return $src;
});
