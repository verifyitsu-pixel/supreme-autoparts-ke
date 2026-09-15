<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Import products from a Shopify products.json-style file.
 *
 * @return array{imported:int,skipped:int,errors:int,messages:array<int,string>}
 */
function sa_core_import_shopify_products_file(string $path): array
{
    $result = ['imported' => 0, 'skipped' => 0, 'errors' => 0, 'messages' => []];

    if (!file_exists($path)) {
        $result['errors']++;
        $result['messages'][] = "File not found: {$path}";
        return $result;
    }
    if (!class_exists('WC_Product_Simple')) {
        $result['errors']++;
        $result['messages'][] = 'WooCommerce not active.';
        return $result;
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        $result['errors']++;
        $result['messages'][] = 'Unable to read file.';
        return $result;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['products']) || !is_array($data['products'])) {
        $result['errors']++;
        $result['messages'][] = 'Invalid JSON: expected { "products": [ ... ] }.';
        return $result;
    }

    // Rough USD→KES placeholder rate for sample display (owner should set real prices later).
    $usd_to_kes = (float) (getenv('SUPREME_USD_TO_KES') ?: '130');

    foreach ($data['products'] as $item) {
        try {
            $handle = sanitize_title((string) ($item['handle'] ?? ''));
            if ($handle === '') {
                $result['skipped']++;
                continue;
            }

            $existing = get_page_by_path($handle, OBJECT, 'product');
            if ($existing) {
                $product = wc_get_product($existing->ID);
            } else {
                $product = new WC_Product_Simple();
            }
            if (!$product) {
                $result['errors']++;
                continue;
            }

            $title = wp_strip_all_tags((string) ($item['title'] ?? $handle));
            $product->set_name($title);
            $product->set_slug($handle);
            $product->set_status('publish');
            $product->set_catalog_visibility('visible');
            $product->set_description((string) ($item['body_html'] ?? ''));
            $product->set_short_description(wp_trim_words(wp_strip_all_tags((string) ($item['body_html'] ?? '')), 40));

            $variant = $item['variants'][0] ?? [];
            $sku = (string) ($variant['sku'] ?? '');
            if ($sku !== '') {
                $product->set_sku($sku);
            }
            $usd = (float) ($variant['price'] ?? 0);
            if ($usd > 0) {
                $product->set_regular_price((string) round($usd * $usd_to_kes, 2));
            }
            $product->set_manage_stock(false);
            $product->set_stock_status(!empty($variant['available']) ? 'instock' : 'outofstock');

            $id = $product->save();
            if (!$id) {
                $result['errors']++;
                continue;
            }

            // Categories from product_type / vendor / tags
            $term_ids = [];
            $ptype = (string) ($item['product_type'] ?? '');
            if ($ptype !== '') {
                $slug = sanitize_title($ptype);
                $tid = sa_core_ensure_product_cat($ptype, $slug);
                if ($tid) {
                    $term_ids[] = $tid;
                }
            }
            $vendor = (string) ($item['vendor'] ?? '');
            if ($vendor !== '') {
                $tid = sa_core_ensure_product_cat($vendor, sanitize_title($vendor));
                if ($tid) {
                    $term_ids[] = $tid;
                }
            }
            if ($term_ids) {
                wp_set_object_terms($id, array_map('intval', $term_ids), 'product_cat', false);
            }

            // Sideload first image if present
            $images = $item['images'] ?? [];
            if (is_array($images) && !empty($images[0]['src'])) {
                sa_core_sideload_product_image((int) $id, (string) $images[0]['src']);
            }

            update_post_meta($id, '_sa_shopify_id', (string) ($item['id'] ?? ''));
            update_post_meta($id, '_sa_shopify_handle', $handle);
            $result['imported']++;
        } catch (Throwable $e) {
            $result['errors']++;
            $result['messages'][] = $e->getMessage();
        }
    }

    return $result;
}

function sa_core_ensure_product_cat(string $name, string $slug): int
{
    if (function_exists('sa_core_ensure_term')) {
        return sa_core_ensure_term($name, $slug);
    }
    $existing = get_term_by('slug', $slug, 'product_cat');
    if ($existing && !is_wp_error($existing)) {
        return (int) $existing->term_id;
    }
    $r = wp_insert_term($name, 'product_cat', ['slug' => $slug]);
    return is_wp_error($r) ? 0 : (int) $r['term_id'];
}

function sa_core_sideload_product_image(int $product_id, string $url): void
{
    if (!function_exists('media_sideload_image')) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }
    // Skip if already has thumbnail
    if (has_post_thumbnail($product_id)) {
        return;
    }
    $att_id = media_sideload_image($url, $product_id, null, 'id');
    if (!is_wp_error($att_id) && $att_id) {
        set_post_thumbnail($product_id, (int) $att_id);
    }
}
