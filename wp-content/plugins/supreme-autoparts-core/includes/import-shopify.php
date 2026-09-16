<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Import products from a Shopify products.json-style file OR NDJSON stream.
 *
 * @param string               $path File path (.json with {products:[...]} or .ndjson)
 * @param array{limit?:int,offset?:int,skip_images?:bool} $opts
 * @return array{imported:int,updated:int,skipped:int,errors:int,messages:array<int,string>}
 */
function sa_core_import_shopify_products_file(string $path, array $opts = []): array
{
    $result = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'messages' => []];

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

    $limit = isset($opts['limit']) ? max(0, (int) $opts['limit']) : 0;
    $offset = isset($opts['offset']) ? max(0, (int) $opts['offset']) : 0;
    $skip_images = !empty($opts['skip_images']);

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'ndjson' || $ext === 'jsonl') {
        return sa_core_import_shopify_ndjson($path, $limit, $offset, $skip_images, $result);
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

    $products = $data['products'];
    if ($offset > 0) {
        $products = array_slice($products, $offset);
    }
    if ($limit > 0) {
        $products = array_slice($products, 0, $limit);
    }

    foreach ($products as $item) {
        if (!is_array($item)) {
            $result['skipped']++;
            continue;
        }
        sa_core_import_one_shopify_product($item, $result, $skip_images);
    }

    return $result;
}

/**
 * Stream NDJSON (one Shopify product object per line) with --limit/--offset.
 *
 * @param array{imported:int,updated:int,skipped:int,errors:int,messages:array<int,string>} $result
 * @return array{imported:int,updated:int,skipped:int,errors:int,messages:array<int,string>}
 */
function sa_core_import_shopify_ndjson(string $path, int $limit, int $offset, bool $skip_images, array $result): array
{
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        $result['errors']++;
        $result['messages'][] = "Unable to open NDJSON: {$path}";
        return $result;
    }

    $line_no = 0;
    $processed = 0;
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if ($line_no < $offset) {
            $line_no++;
            continue;
        }
        if ($limit > 0 && $processed >= $limit) {
            break;
        }
        $line_no++;
        $item = json_decode($line, true);
        if (!is_array($item)) {
            $result['errors']++;
            if (count($result['messages']) < 50) {
                $result['messages'][] = "Invalid JSON at line {$line_no}";
            }
            continue;
        }
        sa_core_import_one_shopify_product($item, $result, $skip_images);
        $processed++;
    }
    fclose($fh);
    $result['messages'][] = "NDJSON processed={$processed} offset={$offset} limit=" . ($limit ?: 'all');
    return $result;
}

/**
 * Idempotent upsert by Shopify handle / _sa_shopify_id / SKU.
 *
 * @param array{imported:int,updated:int,skipped:int,errors:int,messages:array<int,string>} $result
 */
function sa_core_import_one_shopify_product(array $item, array &$result, bool $skip_images = false): void
{
    try {
        $handle = sanitize_title((string) ($item['handle'] ?? ''));
        if ($handle === '') {
            $result['skipped']++;
            return;
        }

        $shopify_id = (string) ($item['id'] ?? '');
        $existing_id = 0;

        // 1) By stored Shopify id meta
        if ($shopify_id !== '') {
            $q = get_posts([
                'post_type'      => 'product',
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_key'       => '_sa_shopify_id',
                'meta_value'     => $shopify_id,
            ]);
            if ($q) {
                $existing_id = (int) $q[0];
            }
        }

        // 2) By handle / slug
        if (!$existing_id) {
            $by_path = get_page_by_path($handle, OBJECT, 'product');
            if ($by_path) {
                $existing_id = (int) $by_path->ID;
            }
        }

        // 3) By SKU (first variant)
        $variant = $item['variants'][0] ?? [];
        $sku = (string) ($variant['sku'] ?? '');
        if (!$existing_id && $sku !== '' && function_exists('wc_get_product_id_by_sku')) {
            $by_sku = wc_get_product_id_by_sku($sku);
            if ($by_sku) {
                $existing_id = (int) $by_sku;
            }
        }

        $is_update = $existing_id > 0;
        if ($is_update) {
            $product = wc_get_product($existing_id);
        } else {
            // Variable if >1 variant with options, else simple
            $variants = $item['variants'] ?? [];
            if (is_array($variants) && count($variants) > 1) {
                $product = new WC_Product_Variable();
            } else {
                $product = new WC_Product_Simple();
            }
        }
        if (!$product) {
            $result['errors']++;
            return;
        }

        $title = wp_strip_all_tags((string) ($item['title'] ?? $handle));
        $product->set_name($title);
        $product->set_slug($handle);
        $product->set_status('publish');
        $product->set_catalog_visibility('visible');
        $product->set_description((string) ($item['body_html'] ?? ''));
        $product->set_short_description(wp_trim_words(wp_strip_all_tags((string) ($item['body_html'] ?? '')), 40));

        $usd_to_kes = (float) (getenv('SUPREME_USD_TO_KES') ?: '130');
        $usd = (float) ($variant['price'] ?? 0);
        $compare = isset($variant['compare_at_price']) ? (float) $variant['compare_at_price'] : 0.0;

        if ($product instanceof WC_Product_Simple || $product->is_type('simple')) {
            if ($sku !== '') {
                try {
                    $product->set_sku($sku);
                } catch (Throwable $e) {
                    // SKU collision — keep existing
                }
            }
            if ($usd > 0) {
                $product->set_regular_price((string) round($usd * $usd_to_kes, 2));
            }
            if ($compare > $usd && $compare > 0) {
                $product->set_sale_price((string) round($usd * $usd_to_kes, 2));
                $product->set_regular_price((string) round($compare * $usd_to_kes, 2));
            }
            $product->set_manage_stock(false);
            $product->set_stock_status(!empty($variant['available']) ? 'instock' : 'outofstock');
        }

        $id = $product->save();
        if (!$id) {
            $result['errors']++;
            return;
        }

        // Categories from product_type / vendor
        $term_ids = [];
        $ptype = (string) ($item['product_type'] ?? '');
        if ($ptype !== '') {
            $tid = sa_core_ensure_product_cat($ptype, sanitize_title($ptype));
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
        // Tags → product_tag
        $tags_raw = $item['tags'] ?? '';
        if (is_string($tags_raw) && $tags_raw !== '') {
            $tag_names = array_filter(array_map('trim', explode(',', $tags_raw)));
            if ($tag_names) {
                wp_set_object_terms($id, $tag_names, 'product_tag', false);
            }
        }
        if ($term_ids) {
            wp_set_object_terms($id, array_map('intval', $term_ids), 'product_cat', false);
        }

        if (!$skip_images) {
            $images = $item['images'] ?? [];
            if (is_array($images) && !empty($images[0]['src'])) {
                sa_core_sideload_product_image((int) $id, (string) $images[0]['src']);
            }
        }

        update_post_meta($id, '_sa_shopify_id', $shopify_id);
        update_post_meta($id, '_sa_shopify_handle', $handle);
        if (!empty($item['updated_at'])) {
            update_post_meta($id, '_sa_shopify_updated_at', (string) $item['updated_at']);
        }

        if ($is_update) {
            $result['updated']++;
        } else {
            $result['imported']++;
        }
    } catch (Throwable $e) {
        $result['errors']++;
        if (count($result['messages']) < 50) {
            $result['messages'][] = $e->getMessage();
        }
    }
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
    if (has_post_thumbnail($product_id)) {
        return;
    }
    $att_id = media_sideload_image($url, $product_id, null, 'id');
    if (!is_wp_error($att_id) && $att_id) {
        set_post_thumbnail($product_id, (int) $att_id);
    }
}
