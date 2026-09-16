<?php
declare(strict_types=1);

/**
 * Image performance: LCP preload, fetchpriority, lazy-below-fold, sensible sizes.
 *
 * @package Supreme_Autoparts
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register compact logo size (header ~200×58 CSS → 2× retina).
 */
add_action('after_setup_theme', static function (): void {
    add_image_size('sa-logo', 400, 225, false);
}, 20);

/**
 * Absolute https URL helper for email clients / preload.
 */
function sa_absolute_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (str_starts_with($url, '//')) {
        $url = 'https:' . $url;
    }
    if (preg_match('#^https?://#i', $url)) {
        return set_url_scheme($url, 'https');
    }
    return set_url_scheme(home_url($url), 'https');
}

/**
 * Theme logo URL (prefer optimized JPEG, then PNG).
 */
function sa_theme_logo_url(bool $light = false): string
{
    $base = SA_THEME_DIR . '/assets/';
    $uri  = SA_THEME_URI . '/assets/';
    if ($light) {
        foreach (['logo-light.jpg', 'logo-light.png'] as $f) {
            if (is_readable($base . $f)) {
                return $uri . $f;
            }
        }
        return $uri . 'logo-light.png';
    }
    foreach (['logo.jpg', 'logo.png'] as $f) {
        if (is_readable($base . $f)) {
            return $uri . $f;
        }
    }
    return $uri . 'logo.png';
}

/**
 * Whether current request is rendering a WooCommerce email.
 */
function sa_is_wc_email_context(): bool
{
    return !empty($GLOBALS['sa_in_wc_email']);
}

add_action('woocommerce_email_header', static function (): void {
    $GLOBALS['sa_in_wc_email'] = true;
}, 1);

add_action('woocommerce_email_footer', static function (): void {
    $GLOBALS['sa_in_wc_email'] = false;
}, 999);

/**
 * Loop index for above-the-fold product cards (first row eager).
 */
function sa_loop_product_index(): int
{
    if (!empty($GLOBALS['sa_loop_product_index'])) {
        return (int) $GLOBALS['sa_loop_product_index'];
    }
    return 0;
}

function sa_loop_product_bump(): int
{
    $n = sa_loop_product_index() + 1;
    $GLOBALS['sa_loop_product_index'] = $n;
    return $n;
}

add_action('woocommerce_before_shop_loop', static function (): void {
    $GLOBALS['sa_loop_product_index'] = 0;
}, 1);

add_action('woocommerce_after_shop_loop', static function (): void {
    $GLOBALS['sa_loop_product_index'] = 0;
}, 99);

/**
 * Attrs for product card / cart / account thumbs.
 *
 * @param array<string,string> $attr
 * @return array<string,string>
 */
function sa_product_image_attrs(array $attr = [], bool $is_lcp = false): array
{
    $attr['decoding'] = $attr['decoding'] ?? 'async';
    if ($is_lcp) {
        $attr['loading']       = 'eager';
        $attr['fetchpriority'] = 'high';
    } else {
        $attr['loading'] = $attr['loading'] ?? 'lazy';
        unset($attr['fetchpriority']);
    }
    if (empty($attr['sizes']) && empty($attr['width'])) {
        // Shop cards render ~ in 4-col grid; keep modest.
        $attr['sizes'] = '(max-width: 600px) 50vw, (max-width: 1024px) 25vw, 280px';
    }
    return $attr;
}

/**
 * Preload header logo + LCP product image where sensible.
 */
add_action('wp_head', static function (): void {
    if (is_admin()) {
        return;
    }

    $logo = '';
    if (function_exists('has_custom_logo') && has_custom_logo()) {
        $id = (int) get_theme_mod('custom_logo');
        if ($id > 0) {
            $file = (string) get_post_meta($id, '_wp_attached_file', true);
            $uploads = wp_get_upload_dir();
            $basedir = (string) ($uploads['basedir'] ?? '');
            $on_disk = $file !== '' && $basedir !== '' && is_readable($basedir . '/' . ltrim($file, '/'));
            if ($on_disk) {
                $logo = (string) (wp_get_attachment_image_url($id, 'sa-logo') ?: wp_get_attachment_image_url($id, 'medium') ?: wp_get_attachment_image_url($id, 'full'));
            }
        }
    }
    // Theme-baked JPEG always survives redeploys (no uploads volume dependency).
    if ($logo === '') {
        $logo = sa_theme_logo_url(false);
    }
    $logo = sa_absolute_url($logo);
    if ($logo !== '') {
        $type_attr = str_ends_with(strtolower(parse_url($logo, PHP_URL_PATH) ?: ''), '.webp')
            ? ' type="image/webp"'
            : '';
        printf(
            '<link rel="preload" as="image" href="%s"%s fetchpriority="high" />' . "\n",
            esc_url($logo),
            $type_attr
        );
    }

    $lcp = '';
    if (function_exists('is_product') && is_product()) {
        global $product;
        if (!$product instanceof WC_Product) {
            $product = wc_get_product(get_the_ID());
        }
        if ($product instanceof WC_Product) {
            $img_id = (int) $product->get_image_id();
            if ($img_id > 0) {
                $lcp = (string) wp_get_attachment_image_url($img_id, 'woocommerce_single');
            }
            if ($lcp === '' && function_exists('sa_core_get_stored_shopify_image_urls')) {
                $urls = sa_core_get_stored_shopify_image_urls($product->get_id());
                if ($urls !== []) {
                    $lcp = function_exists('sa_core_shopify_cdn_width')
                        ? sa_core_shopify_cdn_width($urls[0], 600)
                        : $urls[0];
                }
            }
        }
    } elseif (function_exists('is_shop') && (is_shop() || is_product_taxonomy())) {
        // First product in current query — cheap peek without extra SQL when main query ready.
        global $wp_query;
        if ($wp_query instanceof WP_Query && !empty($wp_query->posts[0])) {
            $first = $wp_query->posts[0];
            $pid   = is_object($first) ? (int) $first->ID : 0;
            if ($pid > 0 && function_exists('wc_get_product')) {
                $p = wc_get_product($pid);
                if ($p instanceof WC_Product) {
                    $img_id = (int) $p->get_image_id();
                    if ($img_id > 0) {
                        $lcp = (string) wp_get_attachment_image_url($img_id, 'woocommerce_thumbnail');
                    } elseif (function_exists('sa_core_get_stored_shopify_image_urls')) {
                        $urls = sa_core_get_stored_shopify_image_urls($pid);
                        if ($urls !== []) {
                            $lcp = function_exists('sa_core_shopify_cdn_width')
                                ? sa_core_shopify_cdn_width($urls[0], 400)
                                : $urls[0];
                        }
                    }
                }
            }
        }
    }

    $lcp = sa_absolute_url($lcp);
    if ($lcp !== '' && $lcp !== $logo) {
        $type_attr = str_ends_with(strtolower(parse_url($lcp, PHP_URL_PATH) ?: ''), '.webp')
            ? ' type="image/webp"'
            : '';
        $srcset = '';
        // Prefer responsive preload when attachment known on PDP.
        if (function_exists('is_product') && is_product()) {
            global $product;
            $p = ($product instanceof WC_Product) ? $product : (function_exists('wc_get_product') ? wc_get_product(get_the_ID()) : null);
            if ($p instanceof WC_Product) {
                $img_id = (int) $p->get_image_id();
                if ($img_id > 0) {
                    $srcset = (string) (wp_get_attachment_image_srcset($img_id, 'woocommerce_single') ?: '');
                }
            }
        }
        if ($srcset !== '') {
            printf(
                '<link rel="preload" as="image" href="%s" imagesrcset="%s" imagesizes="(max-width: 768px) 100vw, 600px"%s fetchpriority="high" />' . "\n",
                esc_url($lcp),
                esc_attr($srcset),
                $type_attr
            );
        } else {
            printf(
                '<link rel="preload" as="image" href="%s"%s fetchpriority="high" />' . "\n",
                esc_url($lcp),
                $type_attr
            );
        }
    }
}, 2);

/**
 * Tighten attachment image attrs for logos and Woo product sizes.
 *
 * @param array<string,string> $attr
 * @param WP_Post              $attachment
 * @param string|int[]         $size
 * @return array<string,string>
 */
add_filter('wp_get_attachment_image_attributes', static function (array $attr, $attachment, $size): array {
    $class = isset($attr['class']) ? (string) $attr['class'] : '';

    // Header / custom logo — never full-bleed sizes=100vw.
    if (str_contains($class, 'sa-logo__img') || str_contains($class, 'custom-logo')) {
        $attr['loading']       = 'eager';
        $attr['fetchpriority'] = 'high';
        $attr['decoding']      = 'async';
        $attr['sizes']         = '(max-width: 767px) 140px, 200px';
        return $attr;
    }

    // Product thumbs in loops / cards.
    if (str_contains($class, 'sa-product-card__img') || str_contains($class, 'attachment-woocommerce_thumbnail')) {
        if (sa_is_wc_email_context()) {
            $attr['loading'] = 'eager';
            unset($attr['fetchpriority']);
            return $attr;
        }
        // Default lazy; content-product may override first row via get_image attrs.
        if (!isset($attr['loading'])) {
            $attr['loading'] = 'lazy';
        }
        if (!isset($attr['decoding'])) {
            $attr['decoding'] = 'async';
        }
        if (empty($attr['sizes']) || str_contains((string) $attr['sizes'], '100vw')) {
            $attr['sizes'] = '(max-width: 600px) 50vw, (max-width: 1024px) 25vw, 280px';
        }
    }

    // Single product main image — LCP.
    if (str_contains($class, 'wp-post-image') && function_exists('is_product') && is_product() && !sa_is_wc_email_context()) {
        if (!str_contains($class, 'sa-product-card__img')) {
            $attr['loading']       = 'eager';
            $attr['fetchpriority'] = 'high';
            $attr['decoding']      = 'async';
        }
    }

    return $attr;
}, 20, 3);

/**
 * Prefer JPEG/WebP in srcset candidates; never strip image/webp from accept.
 * Ensure uploads allow common product image types.
 *
 * @param array<string,string> $mimes
 * @return array<string,string>
 */
add_filter('upload_mimes', static function (array $mimes): array {
    $mimes['webp'] = 'image/webp';
    $mimes['jpg|jpeg|jpe'] = 'image/jpeg';
    $mimes['png'] = 'image/png';
    return $mimes;
});

/**
 * Cart / mini-cart: force thumbnail size (never full).
 *
 * @param string               $image
 * @param array<string,mixed>  $cart_item
 * @param string               $cart_item_key
 */
add_filter('woocommerce_cart_item_thumbnail', static function ($image, $cart_item, $cart_item_key) {
    $product = $cart_item['data'] ?? null;
    if (!$product instanceof WC_Product) {
        return $image;
    }
    return $product->get_image('woocommerce_thumbnail', sa_product_image_attrs([
        'class' => 'attachment-woocommerce_thumbnail size-woocommerce_thumbnail',
    ], false));
}, 20, 3);

/**
 * Account order view thumbnails stay compact.
 *
 * @param string     $image
 * @param WC_Product $product
 */
add_filter('woocommerce_order_item_thumbnail', static function ($image, $item = null) {
    // Ensure absolute https for any relative src left in markup.
    if (is_string($image) && $image !== '' && preg_match('#\ssrc=(["\'])(/[^"\']+)\1#', $image, $m)) {
        $abs = sa_absolute_url($m[2]);
        $image = str_replace($m[0], ' src=' . $m[1] . esc_url($abs) . $m[1], $image);
    }
    return $image;
}, 20, 2);


/**
 * Order emails: always show product thumbnails (defense in depth; core plugin also sets this).
 *
 * @param array<string,mixed> $args
 * @return array<string,mixed>
 */
add_filter('woocommerce_email_order_items_args', static function (array $args): array {
    $args['show_image'] = true;
    if (empty($args['image_size'])) {
        $args['image_size'] = [64, 64];
    }
    return $args;
}, 5);


/**
 * Treat WebP as a displayable image in media library / srcset generation.
 *
 * @param bool   $result
 * @param string $path
 */
add_filter('file_is_displayable_image', static function ($result, $path) {
    if ($result) {
        return $result;
    }
    $ext = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));
    return $ext === 'webp' ? true : $result;
}, 10, 2);
