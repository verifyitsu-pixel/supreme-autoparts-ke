<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Baked representative Shopify CDN photos for top product_cat tiles.
 * Sourced from scraped catalog — never AI/stock placeholders.
 *
 * @return array<string, array{url:string,title?:string,product_type?:string}>
 */
function sa_core_category_thumbnail_map(): array
{
    static $map = null;
    if (is_array($map)) {
        return $map;
    }
    $path = SA_CORE_DIR . 'data/category-thumbnails.json';
    if (!is_readable($path)) {
        $map = [];
        return $map;
    }
    $raw = file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    $map = is_array($data) ? $data : [];
    return $map;
}

/**
 * Validate http(s) image URL; reject known placeholder hosts.
 */
function sa_core_is_real_category_image_url(string $url): bool
{
    $url = trim($url);
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return false;
    }
    if (preg_match('#(placehold\.co|placeholder\.com|picsum\.photos|via\.placeholder|dummyimage|lorempixel|loremflickr|unsplash\.com/photos/random)#i', $url)) {
        return false;
    }
    return true;
}

/**
 * Resolve image URL for a product_cat slug (term meta → baked map → first product CDN).
 */
function sa_core_get_category_image_url(string $slug, int $term_id = 0): string
{
    if ($term_id <= 0) {
        $term = get_term_by('slug', $slug, 'product_cat');
        $term_id = ($term && !is_wp_error($term)) ? (int) $term->term_id : 0;
    }

    // Prefer theme-bundled real catalog photos (downloaded from Shopify CDN scrape).
    $safe = sanitize_title($slug);
    if ($safe !== '' && function_exists('get_stylesheet_directory')) {
        $local = get_stylesheet_directory() . '/assets/images/categories/' . $safe . '.jpg';
        if (is_readable($local)) {
            return get_stylesheet_directory_uri() . '/assets/images/categories/' . $safe . '.jpg';
        }
    }

    if ($term_id > 0) {
        $stored = (string) get_term_meta($term_id, 'sa_image_url', true);
        if (sa_core_is_real_category_image_url($stored)) {
            return esc_url_raw($stored);
        }
        $thumb_id = (int) get_term_meta($term_id, 'thumbnail_id', true);
        if ($thumb_id > 0) {
            $file = (string) get_post_meta($thumb_id, '_wp_attached_file', true);
            if ($file !== '' && stripos($file, 'woocommerce-placeholder') === false) {
                $src = wp_get_attachment_image_url($thumb_id, 'woocommerce_thumbnail');
                if (is_string($src) && $src !== '') {
                    return $src;
                }
            }
        }
    }

    $map = sa_core_category_thumbnail_map();
    if (isset($map[$slug]['url']) && sa_core_is_real_category_image_url((string) $map[$slug]['url'])) {
        return esc_url_raw((string) $map[$slug]['url']);
    }

    if ($term_id > 0 && function_exists('wc_get_products')) {
        $products = wc_get_products([
            'limit'    => 8,
            'status'   => 'publish',
            'category' => [$slug],
            'orderby'  => 'date',
            'order'    => 'DESC',
            'return'   => 'objects',
        ]);
        foreach ($products as $product) {
            if (!$product instanceof WC_Product) {
                continue;
            }
            if (function_exists('sa_core_get_stored_shopify_image_urls')) {
                $urls = sa_core_get_stored_shopify_image_urls($product->get_id());
                if ($urls !== [] && sa_core_is_real_category_image_url($urls[0])) {
                    return esc_url_raw($urls[0]);
                }
            }
            $img = $product->get_image_id();
            if ($img) {
                $src = wp_get_attachment_image_url((int) $img, 'woocommerce_thumbnail');
                if (is_string($src) && $src !== '' && stripos($src, 'woocommerce-placeholder') === false) {
                    return $src;
                }
            }
        }
    }

    return '';
}

/**
 * Write sa_image_url term meta for IA product types from baked JSON.
 */
function sa_core_seed_category_thumbnails(): void
{
    if (!taxonomy_exists('product_cat')) {
        return;
    }
    $map = sa_core_category_thumbnail_map();
    $n = 0;
    foreach ($map as $slug => $row) {
        if (!is_array($row)) {
            continue;
        }
        $url = (string) ($row['url'] ?? '');
        if (!sa_core_is_real_category_image_url($url)) {
            continue;
        }
        $term = get_term_by('slug', (string) $slug, 'product_cat');
        if (!$term || is_wp_error($term)) {
            continue;
        }
        update_term_meta((int) $term->term_id, 'sa_image_url', esc_url_raw($url));
        if (!empty($row['title'])) {
            update_term_meta((int) $term->term_id, 'sa_image_title', sanitize_text_field((string) $row['title']));
        }
        $n++;
    }
    update_option('sa_category_thumbs_seeded', time());
    update_option('sa_category_thumbs_ver', '2');
    update_option('sa_category_thumbs_count', $n);
}

/**
 * Replace default Woo subcategory thumbnail with real catalog photos.
 */
add_action('init', static function (): void {
    remove_action('woocommerce_before_subcategory_title', 'woocommerce_subcategory_thumbnail', 10);
    add_action('woocommerce_before_subcategory_title', static function ($category): void {
        if (!is_object($category) || empty($category->term_id)) {
            return;
        }
        $url = sa_core_get_category_image_url((string) ($category->slug ?? ''), (int) $category->term_id);
        $name = (string) ($category->name ?? '');
        if ($url !== '') {
            echo '<img src="' . esc_url($url) . '" alt="' . esc_attr($name) . '" class="attachment-woocommerce_thumbnail size-woocommerce_thumbnail sa-cat-thumb" loading="lazy" decoding="async" referrerpolicy="no-referrer-when-downgrade" width="400" height="400" />';
            return;
        }
        // Fallback to Woo default only when we have no real photo.
        if (function_exists('woocommerce_subcategory_thumbnail')) {
            woocommerce_subcategory_thumbnail($category);
        }
    }, 10);
}, 20);

add_action('init', static function (): void {
    if (get_option('sa_category_thumbs_ver') === '2') {
        return;
    }
    sa_core_seed_category_thumbnails();
}, 30);
