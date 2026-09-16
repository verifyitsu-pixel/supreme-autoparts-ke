<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Import products from a Shopify products.json-style file OR NDJSON stream.
 *
 * Images: prefer real Shopify CDN URLs from the scrape; enforce cross-product
 * uniqueness (no shared photo URL/attachment). If none unique, fetch a real
 * matching product photo from the web (manufacturer/retail). Never AI/placeholder.
 *
 * @param string $path File path (.json with {products:[...]} or .ndjson)
 * @param array{limit?:int,offset?:int,skip_images?:bool,require_images?:bool,category?:string,dry_run?:bool,mapping?:string,mapping_file?:string} $opts
 * @return array{imported:int,updated:int,skipped:int,errors:int,filtered:int,messages:array<int,string>,dry_run?:bool,category?:string}
 */
function sa_core_import_shopify_products_file(string $path, array $opts = []): array
{
    $result = [
        'imported' => 0,
        'updated'  => 0,
        'skipped'  => 0,
        'errors'   => 0,
        'filtered' => 0,
        'web_fallback' => 0,
        'messages' => [],
    ];

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
    $require_images = !empty($opts['require_images']);
    $category_raw = strtolower(trim((string) ($opts['category'] ?? '')));
    if (in_array($category_raw, ['', 'all', '*', 'any'], true)) {
        $category = '';
    } else {
        $category = sanitize_title($category_raw);
    }
    $dry_run = !empty($opts['dry_run']);
    $mapping_file = (string) ($opts['mapping'] ?? $opts['mapping_file'] ?? '');
    if ($mapping_file !== '') {
        sa_core_set_product_type_parent_map_file($mapping_file);
    }
    $result['dry_run'] = $dry_run;
    $result['category'] = $category;

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'ndjson' || $ext === 'jsonl') {
        return sa_core_import_shopify_ndjson(
            $path,
            $limit,
            $offset,
            $skip_images,
            $require_images,
            $result,
            $category,
            $dry_run
        );
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

    $processed = 0;
    foreach ($products as $item) {
        if (!is_array($item)) {
            $result['skipped']++;
            continue;
        }
        if ($category !== '' && !sa_core_product_matches_import_category($item, $category)) {
            $result['filtered']++;
            continue;
        }
        if ($limit > 0 && $processed >= $limit) {
            break;
        }
        sa_core_import_one_shopify_product($item, $result, $skip_images, $dry_run, $require_images);
        $processed++;
    }

    $result['messages'][] = 'JSON processed=' . $processed
        . ' category=' . ($category !== '' ? $category : 'all')
        . ' dry_run=' . ($dry_run ? '1' : '0')
        . ' filtered=' . $result['filtered'];

    return $result;
}

/**
 * Stream NDJSON (one Shopify product object per line) with --limit/--offset.
 *
 * @param array{imported:int,updated:int,skipped:int,errors:int,messages:array<int,string>} $result
 * @return array{imported:int,updated:int,skipped:int,errors:int,messages:array<int,string>}
 */
function sa_core_import_shopify_ndjson(
    string $path,
    int $limit,
    int $offset,
    bool $skip_images,
    bool $require_images,
    array $result,
    string $category = '',
    bool $dry_run = false
): array {
    if (!isset($result['filtered'])) {
        $result['filtered'] = 0;
    }
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        $result['errors']++;
        $result['messages'][] = "Unable to open NDJSON: {$path}";
        return $result;
    }

    $line_no = 0;
    $seen_lines = 0;
    $processed = 0;
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if ($seen_lines < $offset) {
            $seen_lines++;
            $line_no++;
            continue;
        }
        $line_no++;
        $seen_lines++;
        $item = json_decode($line, true);
        if (!is_array($item)) {
            $result['errors']++;
            if (count($result['messages']) < 50) {
                $result['messages'][] = "Invalid JSON at line {$line_no}";
            }
            continue;
        }
        // Category filter does not consume --limit (limit = max matching products).
        if ($category !== '' && !sa_core_product_matches_import_category($item, $category)) {
            $result['filtered']++;
            continue;
        }
        if ($limit > 0 && $processed >= $limit) {
            break;
        }
        sa_core_import_one_shopify_product($item, $result, $skip_images, $dry_run, $require_images);
        $processed++;
    }
    fclose($fh);
    $result['messages'][] = "NDJSON processed={$processed} offset={$offset} limit=" . ($limit ?: 'all')
        . ' require_images=' . ($require_images ? '1' : '0')
        . ' category=' . ($category !== '' ? $category : 'all')
        . ' dry_run=' . ($dry_run ? '1' : '0')
        . ' filtered=' . (int) $result['filtered'];
    return $result;
}

/**
 * @param array<string,mixed> $item
 */
function sa_core_shopify_item_has_images(array $item): bool
{
    return sa_core_collect_shopify_image_urls($item) !== [];
}

/**
 * Reject non-http(s) URLs, data URIs, blanks, and obvious fake/placeholder schemes.
 */
if (!function_exists('sa_core_is_valid_remote_image_url')) {
function sa_core_is_valid_remote_image_url(string $url): bool
{
    $url = trim($url);
    if ($url === '') {
        return false;
    }
    if (preg_match('#^(data:|javascript:|blob:|file:)#i', $url)) {
        return false;
    }
    if (!preg_match('#^https?://#i', $url)) {
        return false;
    }
    // Never accept common AI / stock placeholder hosts.
    if (preg_match('#(placehold\.co|placeholder\.com|picsum\.photos|unsplash\.com/photos/random|via\.placeholder|dummyimage\.com|lorempixel|loremflickr)#i', $url)) {
        return false;
    }
    return true;
}
}


/**
 * Prefer full-size Shopify CDN URLs by stripping size suffixes before the extension.
 * Examples: _100x100, _grande, _large, _compact, _pico, _200x
 */
function sa_core_normalize_shopify_image_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    // Strip Shopify resized filename suffixes: name_100x100.jpg → name.jpg
    $normalized = preg_replace(
        '#_(?:pico|icon|thumb|small|compact|medium|large|grande|original|master|\d+x\d+|\d+x|x\d+)(?=\.(?:jpe?g|png|gif|webp|avif)(?:\?|$))#i',
        '',
        $url
    );
    return is_string($normalized) && $normalized !== '' ? $normalized : $url;
}

/**
 * Collect unique full-size http(s) image URLs from a Shopify product payload.
 * Order preserved: featured first, then gallery.
 *
 * @param array<string,mixed> $item
 * @return list<string>
 */
function sa_core_collect_shopify_image_urls(array $item): array
{
    $out = [];
    $seen = [];

    $images = $item['images'] ?? [];
    if (!is_array($images)) {
        return [];
    }

    foreach ($images as $img) {
        if (!is_array($img)) {
            continue;
        }
        $src = isset($img['src']) ? (string) $img['src'] : '';
        if (!sa_core_is_valid_remote_image_url($src)) {
            continue;
        }
        $src = sa_core_normalize_shopify_image_url($src);
        if (!sa_core_is_valid_remote_image_url($src)) {
            continue;
        }
        $key = strtok($src, '?') ?: $src;
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $src;
    }

    return $out;
}

/**
 * Idempotent upsert by Shopify handle / _sa_shopify_id / SKU.
 *
 * @param array{imported:int,updated:int,skipped:int,errors:int,messages:array<int,string>} $result
 */
function sa_core_import_one_shopify_product(array $item, array &$result, bool $skip_images = false, bool $dry_run = false, bool $require_images = false): void
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
        if ($dry_run) {
            if ($is_update) {
                $result['updated']++;
            } else {
                $result['imported']++;
            }
            if (count($result['messages']) < 20) {
                $ptype = (string) ($item['product_type'] ?? '');
                $parent = sa_core_map_product_type_parent_slug($ptype);
                $result['messages'][] = sprintf(
                    '[dry-run] %s %s type=%s parent=%s',
                    $is_update ? 'update' : 'import',
                    $handle,
                    $ptype !== '' ? $ptype : '-',
                    $parent !== '' ? $parent : '-'
                );
            }
            return;
        }
        if ($is_update) {
            $product = wc_get_product($existing_id);
        } else {
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
        // Preserve real body_html; leave empty when Shopify had no description (no AI filler).
        $body_html = (string) ($item['body_html'] ?? '');
        $product->set_description($body_html);
        if (trim(wp_strip_all_tags($body_html)) === '') {
            $product->set_short_description('');
        } else {
            $product->set_short_description(wp_trim_words(wp_strip_all_tags($body_html), 40));
        }

        // Shopify scrape amounts are USD. Store as USD when checkout currency is USD;
        // only multiply by SUPREME_USD_TO_KES when WOO/SA currency is KES (legacy).
        $usd = (float) ($variant['price'] ?? 0);
        $compare = isset($variant['compare_at_price']) ? (float) $variant['compare_at_price'] : 0.0;
        $store_regular = sa_core_shopify_usd_to_store_amount($usd);
        $store_compare = $compare > 0 ? sa_core_shopify_usd_to_store_amount($compare) : 0.0;

        if ($product instanceof WC_Product_Simple || $product->is_type('simple')) {
            if ($sku !== '') {
                try {
                    $product->set_sku($sku);
                } catch (Throwable $e) {
                    // SKU collision — keep existing
                }
            }
            if ($store_regular > 0) {
                $product->set_regular_price((string) $store_regular);
            }
            if ($store_compare > $store_regular && $store_compare > 0) {
                $product->set_sale_price((string) $store_regular);
                $product->set_regular_price((string) $store_compare);
            }
            $product->set_manage_stock(false);
            $product->set_stock_status(!empty($variant['available']) ? 'instock' : 'outofstock');
        }

        $id = $product->save();
        if (!$id) {
            $result['errors']++;
            return;
        }

        // Categories: leaf product_type under IA parent + parent itself so
        // /product-category/brakes/ lists products (include_children + direct assign).
        $term_ids = [];
        $ptype = (string) ($item['product_type'] ?? '');
        if ($ptype !== '') {
            $mapped = sa_core_assign_product_type_categories($ptype);
            foreach ($mapped as $tid) {
                if ($tid) {
                    $term_ids[] = $tid;
                }
            }
        }
        // Collections: array of {title,handle} or handles/titles as strings.
        $collections = $item['collections'] ?? ($item['collection'] ?? null);
        if (is_array($collections)) {
            foreach ($collections as $col) {
                $cname = '';
                $cslug = '';
                if (is_array($col)) {
                    $cname = trim((string) ($col['title'] ?? $col['name'] ?? ''));
                    $cslug = sanitize_title((string) ($col['handle'] ?? $cname));
                } elseif (is_string($col) && trim($col) !== '') {
                    $cname = trim($col);
                    $cslug = sanitize_title($cname);
                }
                if ($cname === '' || $cslug === '') {
                    continue;
                }
                $tid = sa_core_ensure_product_cat($cname, $cslug);
                if ($tid) {
                    $term_ids[] = $tid;
                }
            }
        }
        $vendor = (string) ($item['vendor'] ?? '');
        if ($vendor !== '') {
            // Keep vendors under Brands parent when possible (not top-level orphans).
            $brands_parent = 0;
            if (function_exists('sa_core_ensure_term')) {
                $brands_parent = sa_core_ensure_term('Brands', 'brands');
            }
            $tid = sa_core_ensure_product_cat($vendor, sanitize_title($vendor), $brands_parent > 0 ? $brands_parent : 0);
            if ($tid) {
                $term_ids[] = $tid;
            }
        }
        $tags_raw = $item['tags'] ?? '';
        if (is_string($tags_raw) && $tags_raw !== '') {
            $tag_names = array_filter(array_map('trim', explode(',', $tags_raw)));
            if ($tag_names) {
                wp_set_object_terms($id, $tag_names, 'product_tag', false);
            }
        } elseif (is_array($tags_raw) && $tags_raw) {
            $tag_names = array_values(array_filter(array_map(static function ($t) {
                return is_string($t) ? trim($t) : '';
            }, $tags_raw)));
            if ($tag_names) {
                wp_set_object_terms($id, $tag_names, 'product_tag', false);
            }
        }
        if ($term_ids) {
            wp_set_object_terms($id, array_values(array_unique(array_map('intval', $term_ids))), 'product_cat', false);
        }

        // Resolve unique images: Shopify CDN first, then web fallback. Never share URLs.
        $image_urls = sa_core_resolve_unique_product_images($item, (int) $id);
        $used_web = !empty($GLOBALS['sa_core_last_image_used_web_fallback']);
        $reject_no_image = false;
        if ($image_urls) {
            update_post_meta($id, '_sa_shopify_image_urls', wp_json_encode($image_urls));
            update_post_meta($id, '_sa_shopify_image_src', $image_urls[0]);
            if ($used_web) {
                update_post_meta($id, '_sa_web_fallback_image_url', $image_urls[0]);
                update_post_meta($id, '_sa_image_source', 'web_fallback');
                $result['web_fallback'] = (int) ($result['web_fallback'] ?? 0) + 1;
            } else {
                update_post_meta($id, '_sa_image_source', 'shopify_cdn');
            }
            sa_core_claim_image_urls_for_product((int) $id, $image_urls);
        } else {
            delete_post_meta($id, '_sa_shopify_image_urls');
            delete_post_meta($id, '_sa_shopify_image_src');
            if ($require_images) {
                $reject_no_image = true;
                wp_update_post(['ID' => $id, 'post_status' => 'draft']);
                if (count($result['messages']) < 50) {
                    $result['messages'][] = 'No unique image for ' . $handle . ' — left draft';
                }
            }
        }

        if (!$skip_images && $image_urls) {
            $needs_gallery = sa_core_product_needs_image_backfill((int) $id);
            if ($needs_gallery) {
                sa_core_sideload_product_gallery((int) $id, $image_urls);
            }
        }

        update_post_meta($id, '_sa_shopify_id', $shopify_id);
        update_post_meta($id, '_sa_shopify_handle', $handle);
        update_post_meta($id, '_sa_price_currency', sa_core_import_store_currency());
        if ($usd > 0) {
            update_post_meta($id, '_sa_shopify_price_usd', (string) round($usd, 2));
        }
        if (!empty($item['updated_at'])) {
            update_post_meta($id, '_sa_shopify_updated_at', (string) $item['updated_at']);
        }

        if (!empty($reject_no_image)) {
            $result['skipped']++;
        } elseif ($is_update) {
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

/**
 * Optional override path for product_type → parent category mapping JSON.
 */
function sa_core_set_product_type_parent_map_file(string $path): void
{
    $GLOBALS['sa_core_ptype_parent_map_file'] = $path;
    unset($GLOBALS['sa_core_ptype_parent_map_cache']);
}

/**
 * Default + optional file mapping: exact product_type → parent slug, plus keyword rules.
 *
 * File shape (JSON):
 * {
 *   "exact": { "Brake Pads": "brakes", "Shocks and Struts": "suspension" },
 *   "keywords": { "brakes": ["brake", "rotor"], "suspension": ["shock", "strut"] }
 * }
 * Flat { "Brake Pads": "brakes" } is also accepted (treated as exact).
 *
 * @return array{exact:array<string,string>,keywords:array<string,list<string>>}
 */
function sa_core_product_type_parent_map(): array
{
    if (isset($GLOBALS['sa_core_ptype_parent_map_cache']) && is_array($GLOBALS['sa_core_ptype_parent_map_cache'])) {
        return $GLOBALS['sa_core_ptype_parent_map_cache'];
    }

    $defaults_keywords = [
        'brakes'     => ['brake', 'rotor', 'caliper'],
        'air-intake' => ['air filter', 'air intake', 'cold air', 'cabin air'],
        'suspension' => ['shock', 'strut', 'coilover', 'sway', 'spring', 'control arm', 'lift', 'leveling', 'camber', 'bushing', 'torsion', 'panhard', 'traction'],
        'exhaust'    => ['exhaust', 'catback', 'cat-back', 'axle back', 'axle-back', 'muffler', 'header', 'manifold', 'downpipe', 'wrap'],
        'lighting'   => ['light', 'bulb', 'fog', 'led', 'headlight', 'tail light', 'signal'],
        'drivetrain' => ['clutch', 'axle', 'differential', 'driveshaft', 'transmission', 'torque converter', 'transfer case'],
        'tires'      => ['tire', 'tyre'],
        'wheels'     => ['wheel', 'rim'],
        'exterior'   => ['tonneau', 'bed', 'skid', 'body', 'grille', 'bumper', 'winch', 'wiper', 'fender', 'spoiler', 'hood', 'mirror', 'mud flap', 'running board', 'roof rack', 'chrome', 'armor', 'topper', 'truck cap', 'cover'],
        'interior'   => ['steering wheel', 'seat', 'floor mat', 'gauge', 'pedal', 'dash', 'organizer', 'cargo liner', 'shift knob', 'sun shade'],
        'engine'     => ['filter', 'intake', 'oil', 'spark', 'intercooler', 'fuel', 'push rod', 'blow off', 'bearing', 'bolt', 'thermal', 'cooling', 'ignition', 'turbo', 'supercharg'],
    ];

    $exact = [];
    $keywords = $defaults_keywords;

    $candidates = [];
    if (!empty($GLOBALS['sa_core_ptype_parent_map_file']) && is_string($GLOBALS['sa_core_ptype_parent_map_file'])) {
        $candidates[] = $GLOBALS['sa_core_ptype_parent_map_file'];
    }
    if (defined('SA_CORE_DIR')) {
        $candidates[] = SA_CORE_DIR . 'data/product-type-parent-map.json';
    }
    $candidates[] = trailingslashit(ABSPATH) . 'data/product-type-parent-map.json';

    foreach ($candidates as $file) {
        if ($file === '' || !is_readable($file)) {
            continue;
        }
        $raw = file_get_contents($file);
        if ($raw === false) {
            continue;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            continue;
        }
        if (isset($data['exact']) && is_array($data['exact'])) {
            foreach ($data['exact'] as $k => $v) {
                if (is_string($k) && is_string($v) && $v !== '') {
                    $exact[strtolower(trim($k))] = sanitize_title($v);
                }
            }
        }
        if (isset($data['keywords']) && is_array($data['keywords'])) {
            foreach ($data['keywords'] as $parent => $kws) {
                if (!is_string($parent) || !is_array($kws)) {
                    continue;
                }
                $keywords[sanitize_title($parent)] = array_values(array_filter(array_map(
                    static fn($x) => is_string($x) ? strtolower(trim($x)) : '',
                    $kws
                )));
            }
        }
        // Flat map: "Brake Pads": "brakes"
        $reserved = ['exact' => true, 'keywords' => true];
        foreach ($data as $k => $v) {
            if (isset($reserved[$k]) || !is_string($k) || !is_string($v) || $v === '') {
                continue;
            }
            $exact[strtolower(trim($k))] = sanitize_title($v);
        }
        break;
    }

    $map = ['exact' => $exact, 'keywords' => $keywords];
    $GLOBALS['sa_core_ptype_parent_map_cache'] = $map;
    return $map;
}

/**
 * True when product belongs to the requested IA parent (or exact leaf slug).
 */
function sa_core_product_matches_import_category(array $item, string $category): bool
{
    $category = sanitize_title($category);
    if ($category === '') {
        return true;
    }
    $ptype = trim((string) ($item['product_type'] ?? ''));
    if ($ptype !== '') {
        if (sanitize_title($ptype) === $category) {
            return true;
        }
        $parent = sa_core_map_product_type_parent_slug($ptype);
        if ($parent === $category) {
            return true;
        }
    }
    // Title fallback for sparse product_type.
    $title = (string) ($item['title'] ?? '');
    if ($title !== '' && sa_core_map_product_type_parent_slug($title) === $category) {
        return true;
    }
    $collections = $item['collections'] ?? null;
    if (is_array($collections)) {
        foreach ($collections as $col) {
            $handle = '';
            $cname = '';
            if (is_array($col)) {
                $handle = sanitize_title((string) ($col['handle'] ?? ''));
                $cname = (string) ($col['title'] ?? $col['name'] ?? '');
            } elseif (is_string($col)) {
                $handle = sanitize_title($col);
                $cname = $col;
            }
            if ($handle === $category || sa_core_map_product_type_parent_slug($cname) === $category) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Map Shopify product_type string → IA parent slug (brakes, engine, …).
 */
function sa_core_map_product_type_parent_slug(string $ptype): string
{
    $p = strtolower(trim($ptype));
    if ($p === '') {
        return '';
    }
    $map = sa_core_product_type_parent_map();
    if (isset($map['exact'][$p]) && $map['exact'][$p] !== '') {
        return $map['exact'][$p];
    }
    // Also try sanitized slug as exact key.
    $slug = sanitize_title($ptype);
    if ($slug !== '' && isset($map['exact'][$slug]) && $map['exact'][$slug] !== '') {
        return $map['exact'][$slug];
    }
    foreach ($map['keywords'] as $parent => $kws) {
        foreach ($kws as $kw) {
            if ($kw !== '' && str_contains($p, $kw)) {
                return (string) $parent;
            }
        }
    }
    return '';
}

/**
 * Ensure leaf + parent product_cat terms and return term IDs to assign.
 *
 * @return array<int,int>
 */
function sa_core_assign_product_type_categories(string $ptype): array
{
    $ptype = trim($ptype);
    if ($ptype === '') {
        return [];
    }
    $leaf_slug = sanitize_title($ptype);
    $parent_slug = sa_core_map_product_type_parent_slug($ptype);
    $ids = [];

    $parent_id = 0;
    if ($parent_slug !== '') {
        $parent_labels = [
            'air-intake' => 'Air Intake',
            'brakes' => 'Brakes',
            'drivetrain' => 'Drivetrain',
            'engine' => 'Engine',
            'exhaust' => 'Exhaust',
            'exterior' => 'Exterior',
            'interior' => 'Interior',
            'lighting' => 'Lighting',
            'suspension' => 'Suspension',
            'tires' => 'Tires',
            'wheels' => 'Wheels',
        ];
        $label = $parent_labels[$parent_slug] ?? ucwords(str_replace('-', ' ', $parent_slug));
        $parent_id = sa_core_ensure_product_cat($label, $parent_slug, 0);
        if ($parent_id) {
            $ids[] = $parent_id;
        }
    }

    $leaf_id = sa_core_ensure_product_cat($ptype, $leaf_slug, $parent_id);
    if ($leaf_id) {
        $ids[] = $leaf_id;
    }

    // Also attach common megamenu child slug when product_type is a variant
    // e.g. "Brake Pads - Performance" → also "brake-pads".
    $aliases = [
        'brake-pads' => ['brake pad'],
        'brake-rotors' => ['brake rotor', 'rotor'],
        'brake-calipers' => ['caliper'],
        'brake-kits' => ['brake kit'],
        'big-brake-kits' => ['big brake'],
        'shocks-struts' => ['shock', 'strut'],
        'coilovers' => ['coilover'],
        'sway-bars' => ['sway bar'],
        'control-arms' => ['control arm'],
        'lift-kits' => ['lift kit'],
        'leveling-kits' => ['leveling'],
        'cat-back-exhaust' => ['catback', 'cat-back', 'cat back'],
        'axle-back-exhaust' => ['axle back', 'axle-back'],
        'headers-manifolds' => ['header', 'manifold'],
        'downpipes' => ['downpipe'],
        'muffler' => ['muffler'],
        'air-intakes' => ['cold air', 'air intake'],
        'fog-lights' => ['fog light'],
        'headlights' => ['headlight'],
        'tail-lights' => ['tail light'],
        'tonneau-covers' => ['tonneau'],
        'wheels' => ['wheel', 'rim'],
        'tires' => ['tire', 'tyre'],
        'oil-filters' => ['oil filter'],
        'spark-plugs' => ['spark plug'],
    ];
    $pl = strtolower($ptype);
    foreach ($aliases as $child_slug => $needles) {
        foreach ($needles as $n) {
            if (str_contains($pl, $n)) {
                $child_name = ucwords(str_replace('-', ' ', $child_slug));
                $cid = sa_core_ensure_product_cat($child_name, $child_slug, $parent_id);
                if ($cid) {
                    $ids[] = $cid;
                }
                break;
            }
        }
    }

    return array_values(array_unique(array_map('intval', $ids)));
}

function sa_core_ensure_product_cat(string $name, string $slug, int $parent = 0): int
{
    if (function_exists('sa_core_ensure_term')) {
        return sa_core_ensure_term($name, $slug, $parent);
    }
    $existing = get_term_by('slug', $slug, 'product_cat');
    if ($existing && !is_wp_error($existing)) {
        $tid = (int) $existing->term_id;
        if ($parent > 0 && (int) $existing->parent !== $parent) {
            wp_update_term($tid, 'product_cat', ['parent' => $parent]);
        }
        return $tid;
    }
    $args = ['slug' => $slug];
    if ($parent > 0) {
        $args['parent'] = $parent;
    }
    $r = wp_insert_term($name, 'product_cat', $args);
    return is_wp_error($r) ? 0 : (int) $r['term_id'];
}

/**
 * True when product has no featured image, or featured is Woo placeholder, or gallery empty
 * while we expect to backfill from scrape CDN URLs.
 */
function sa_core_product_needs_image_backfill(int $product_id): bool
{
    if (!has_post_thumbnail($product_id)) {
        return true;
    }
    $thumb_id = (int) get_post_thumbnail_id($product_id);
    if ($thumb_id <= 0) {
        return true;
    }
    // WooCommerce default placeholder attachment is not a real catalog photo.
    $file = (string) get_post_meta($thumb_id, '_wp_attached_file', true);
    if ($file !== '' && stripos($file, 'woocommerce-placeholder') !== false) {
        return true;
    }
    $product = wc_get_product($product_id);
    if ($product) {
        $gallery = $product->get_gallery_image_ids();
        // Has thumbnail but no gallery — still allow backfill of remaining images.
        if (empty($gallery)) {
            return true;
        }
    }
    return false;
}

/**
 * Sideload ALL valid URLs: first = featured thumbnail, rest = product gallery.
 * Skips invalid / non-http URLs. Does not invent placeholders when list is empty.
 *
 * @param list<string> $urls
 */
function sa_core_sideload_product_gallery(int $product_id, array $urls): void
{
    if (!function_exists('media_sideload_image')) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    $attachment_ids = [];
    foreach ($urls as $url) {
        if (!sa_core_is_valid_remote_image_url($url)) {
            continue;
        }
        $url = sa_core_normalize_shopify_image_url($url);
        if (!sa_core_is_valid_remote_image_url($url)) {
            continue;
        }
        // Never attach a URL already owned by a different product.
        if (sa_core_is_image_url_claimed_by_other($url, $product_id)) {
            continue;
        }
        // Avoid re-downloading the same CDN URL onto this product.
        $existing = sa_core_find_attachment_by_source_url($product_id, $url);
        if ($existing) {
            $attachment_ids[] = $existing;
            continue;
        }
        // Also refuse to reuse another product's attachment GUID/source.
        $stolen = sa_core_find_attachment_claimed_globally($url, $product_id);
        if ($stolen) {
            continue;
        }
        $att_id = media_sideload_image($url, $product_id, null, 'id');
        if (is_wp_error($att_id) || !$att_id) {
            continue;
        }
        $att_id = (int) $att_id;
        update_post_meta($att_id, '_sa_source_image_url', $url);
        $attachment_ids[] = $att_id;
    }

    if ($attachment_ids === []) {
        // No real photos — leave product without fake imagery.
        return;
    }

    $featured = array_shift($attachment_ids);
    set_post_thumbnail($product_id, $featured);

    $product = wc_get_product($product_id);
    if ($product) {
        $product->set_gallery_image_ids($attachment_ids);
        $product->save();
    } else {
        update_post_meta($product_id, '_product_image_gallery', implode(',', array_map('strval', $attachment_ids)));
    }
}

/**
 * @deprecated Use sa_core_sideload_product_gallery(); kept for callers expecting single-image API.
 */
function sa_core_sideload_product_image(int $product_id, string $url): void
{
    if (!sa_core_is_valid_remote_image_url($url)) {
        return;
    }
    sa_core_sideload_product_gallery($product_id, [sa_core_normalize_shopify_image_url($url)]);
}

function sa_core_find_attachment_by_source_url(int $product_id, string $url): int
{
    $key = strtok($url, '?') ?: $url;
    $children = get_children([
        'post_parent' => $product_id,
        'post_type'   => 'attachment',
        'numberposts' => 50,
        'fields'      => 'ids',
    ]);
    if (!$children) {
        return 0;
    }
    foreach ($children as $att_id) {
        $stored = (string) get_post_meta((int) $att_id, '_sa_source_image_url', true);
        if ($stored === '') {
            continue;
        }
        $stored_key = strtok($stored, '?') ?: $stored;
        if ($stored_key === $key) {
            return (int) $att_id;
        }
    }
    return 0;
}

/**
 * Canonical key for image URL uniqueness (strip query + Shopify size suffix).
 */
function sa_core_image_url_identity_key(string $url): string
{
    $url = sa_core_normalize_shopify_image_url(trim($url));
    $key = strtok($url, '?') ?: $url;
    return strtolower($key);
}

/**
 * @return array<string,int> url_key => product_id
 */
function &sa_core_image_claim_registry(): array
{
    if (!isset($GLOBALS['sa_core_image_claim_registry']) || !is_array($GLOBALS['sa_core_image_claim_registry'])) {
        $GLOBALS['sa_core_image_claim_registry'] = [];
        $GLOBALS['sa_core_image_claim_registry_bootstrapped'] = false;
    }
    return $GLOBALS['sa_core_image_claim_registry'];
}

/**
 * Load existing claims from product meta + attachment source URLs (once per request).
 */
function sa_core_bootstrap_image_claim_registry(): void
{
    if (!empty($GLOBALS['sa_core_image_claim_registry_bootstrapped'])) {
        return;
    }
    $reg = &sa_core_image_claim_registry();
    $ids = get_posts([
        'post_type'      => 'product',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ]);
    foreach ($ids as $pid) {
        $pid = (int) $pid;
        $single = (string) get_post_meta($pid, '_sa_shopify_image_src', true);
        if ($single !== '') {
            $reg[sa_core_image_url_identity_key($single)] = $pid;
        }
        $raw = get_post_meta($pid, '_sa_shopify_image_urls', true);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $u) {
                    if (is_string($u) && $u !== '') {
                        $reg[sa_core_image_url_identity_key($u)] = $pid;
                    }
                }
            }
        }
        $web = (string) get_post_meta($pid, '_sa_web_fallback_image_url', true);
        if ($web !== '') {
            $reg[sa_core_image_url_identity_key($web)] = $pid;
        }
    }
    // Attachment-level source URLs / GUIDs.
    global $wpdb;
    if (isset($wpdb) && $wpdb instanceof wpdb) {
        $rows = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_sa_source_image_url' AND meta_value <> '' LIMIT 50000",
            ARRAY_A
        );
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $att_id = (int) ($row['post_id'] ?? 0);
                $url = (string) ($row['meta_value'] ?? '');
                if ($att_id <= 0 || $url === '') {
                    continue;
                }
                $parent = (int) wp_get_post_parent_id($att_id);
                if ($parent <= 0) {
                    continue;
                }
                $reg[sa_core_image_url_identity_key($url)] = $parent;
            }
        }
    }
    $GLOBALS['sa_core_image_claim_registry_bootstrapped'] = true;
}

function sa_core_is_image_url_claimed_by_other(string $url, int $product_id): bool
{
    sa_core_bootstrap_image_claim_registry();
    $reg = &sa_core_image_claim_registry();
    $key = sa_core_image_url_identity_key($url);
    if ($key === '') {
        return true;
    }
    if (!isset($reg[$key])) {
        return false;
    }
    return (int) $reg[$key] !== (int) $product_id;
}

/**
 * @param list<string> $urls
 */
function sa_core_claim_image_urls_for_product(int $product_id, array $urls): void
{
    sa_core_bootstrap_image_claim_registry();
    $reg = &sa_core_image_claim_registry();
    foreach ($urls as $u) {
        if (!is_string($u) || $u === '') {
            continue;
        }
        $reg[sa_core_image_url_identity_key($u)] = $product_id;
    }
}

/**
 * @param list<string> $urls
 * @return list<string>
 */
function sa_core_filter_urls_unique_for_product(array $urls, int $product_id): array
{
    $out = [];
    $seen = [];
    foreach ($urls as $u) {
        if (!is_string($u) || !sa_core_is_valid_remote_image_url($u)) {
            continue;
        }
        $u = sa_core_normalize_shopify_image_url($u);
        $key = sa_core_image_url_identity_key($u);
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        if (sa_core_is_image_url_claimed_by_other($u, $product_id)) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $u;
    }
    return $out;
}

function sa_core_find_attachment_claimed_globally(string $url, int $exclude_product_id): int
{
    $key = sa_core_image_url_identity_key($url);
    if ($key === '') {
        return 0;
    }
    global $wpdb;
    if (!isset($wpdb) || !($wpdb instanceof wpdb)) {
        return 0;
    }
    // Match source meta without query string variance via LIKE on path basename when possible.
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_sa_source_image_url' AND meta_value LIKE %s LIMIT 20",
            '%' . $wpdb->esc_like(basename(strtok($url, '?') ?: $url)) . '%'
        ),
        ARRAY_A
    );
    if (!is_array($rows)) {
        return 0;
    }
    foreach ($rows as $row) {
        $att_id = (int) ($row['post_id'] ?? 0);
        $stored = (string) ($row['meta_value'] ?? '');
        if ($att_id <= 0 || sa_core_image_url_identity_key($stored) !== $key) {
            continue;
        }
        $parent = (int) wp_get_post_parent_id($att_id);
        if ($parent > 0 && $parent !== $exclude_product_id) {
            return $att_id;
        }
    }
    return 0;
}

/**
 * Shopify CDN first (unique), else web fallback (unique). Sets
 * $GLOBALS['sa_core_last_image_used_web_fallback'].
 *
 * @param array<string,mixed> $item
 * @return list<string>
 */
function sa_core_resolve_unique_product_images(array $item, int $product_id): array
{
    $GLOBALS['sa_core_last_image_used_web_fallback'] = false;
    $shopify = sa_core_filter_urls_unique_for_product(sa_core_collect_shopify_image_urls($item), $product_id);
    if ($shopify !== []) {
        return $shopify;
    }
    $fb = sa_core_fetch_web_fallback_image_url($item, $product_id);
    if ($fb !== '' && sa_core_is_valid_remote_image_url($fb) && !sa_core_is_image_url_claimed_by_other($fb, $product_id)) {
        $GLOBALS['sa_core_last_image_used_web_fallback'] = true;
        return [$fb];
    }
    return [];
}

/**
 * Fetch a real matching product image from the public web when Shopify CDN is missing
 * or entirely claimed. Prefers manufacturer/retail CDNs; rejects placeholders.
 *
 * @param array<string,mixed> $item
 */
function sa_core_fetch_web_fallback_image_url(array $item, int $product_id): string
{
    $vendor = trim((string) ($item['vendor'] ?? ''));
    $title = trim(wp_strip_all_tags((string) ($item['title'] ?? '')));
    $variant = $item['variants'][0] ?? [];
    $sku = trim((string) ($variant['sku'] ?? ''));
    $parts = array_filter([$vendor, $sku !== '' ? $sku : null, $title]);
    if ($parts === []) {
        return '';
    }
    $query = implode(' ', $parts) . ' product photo';
    $candidates = sa_core_web_search_image_candidates($query, 8);
    foreach ($candidates as $url) {
        if (!sa_core_is_valid_remote_image_url($url)) {
            continue;
        }
        if (sa_core_is_image_url_claimed_by_other($url, $product_id)) {
            continue;
        }
        // Prefer image-looking URLs from retail/CDN hosts.
        if (!preg_match('#\.(jpe?g|png|webp|gif)(\?|$)#i', $url) && !preg_match('#/(cdn|images|img|media|product)#i', $url)) {
            continue;
        }
        return sa_core_normalize_shopify_image_url($url);
    }
    return '';
}

/**
 * @return list<string>
 */
function sa_core_web_search_image_candidates(string $query, int $limit = 8): array
{
    $limit = max(1, min(15, $limit));
    $urls = [];
    // 1) DuckDuckGo HTML results → follow top links for og:image
    $ddg = 'https://html.duckduckgo.com/html/?q=' . rawurlencode($query);
    $html = sa_core_http_get_body($ddg, 12);
    $page_links = [];
    if ($html !== '') {
        if (preg_match_all('#class="result__a"[^>]*href="([^"]+)"#i', $html, $m)) {
            foreach ($m[1] as $href) {
                $href = html_entity_decode($href, ENT_QUOTES);
                // DDG redirect URLs: //duckduckgo.com/l/?uddg=<encoded>
                if (preg_match('#uddg=([^&]+)#', $href, $mm)) {
                    $href = rawurldecode($mm[1]);
                }
                if (preg_match('#^https?://#i', $href)) {
                    $page_links[] = $href;
                }
                if (count($page_links) >= 5) {
                    break;
                }
            }
        }
    }
    foreach ($page_links as $page) {
        // Skip social noise
        if (preg_match('#(facebook\.com|twitter\.com|x\.com|youtube\.com|instagram\.com|pinterest\.com)#i', $page)) {
            continue;
        }
        $body = sa_core_http_get_body($page, 10);
        if ($body === '') {
            continue;
        }
        $og = sa_core_extract_og_image($body);
        if ($og !== '') {
            $urls[] = $og;
        }
        if (count($urls) >= $limit) {
            break;
        }
    }
    // 2) DuckDuckGo image-like direct links in HTML ( occasional )
    if ($html !== '' && count($urls) < $limit) {
        if (preg_match_all('#https?://[^"\s<>]+\.(?:jpe?g|png|webp)#i', $html, $im)) {
            foreach ($im[0] as $u) {
                if (preg_match('#(placehold|placeholder|picsum|dummyimage|lorempixel)#i', $u)) {
                    continue;
                }
                $urls[] = $u;
                if (count($urls) >= $limit) {
                    break;
                }
            }
        }
    }
    return array_values(array_unique($urls));
}

function sa_core_extract_og_image(string $html): string
{
    if (preg_match('#<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']#i', $html, $m)) {
        return trim(html_entity_decode($m[1], ENT_QUOTES));
    }
    if (preg_match('#<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']#i', $html, $m)) {
        return trim(html_entity_decode($m[1], ENT_QUOTES));
    }
    if (preg_match('#<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']#i', $html, $m)) {
        return trim(html_entity_decode($m[1], ENT_QUOTES));
    }
    return '';
}

function sa_core_http_get_body(string $url, int $timeout = 12): string
{
    $args = [
        'timeout'     => $timeout,
        'redirection' => 3,
        'user-agent'  => 'SupremeAutopartsBot/1.0 (+https://www.supremeautoparts.co.ke)',
        'headers'     => ['Accept' => 'text/html,application/xhtml+xml'],
    ];
    $res = wp_remote_get($url, $args);
    if (is_wp_error($res)) {
        return '';
    }
    $code = (int) wp_remote_retrieve_response_code($res);
    if ($code < 200 || $code >= 400) {
        return '';
    }
    $body = (string) wp_remote_retrieve_body($res);
    return $body;
}

/**
 * Audit + fix published products: sideload unique CDN/web images; draft if none.
 *
 * @param array{limit?:int,dry_run?:bool,allow_web?:bool} $opts
 * @return array{examined:int,fixed:int,drafted:int,shared_fixed:int,web_fallback:int,ok:int,messages:list<string>}
 */
function sa_core_audit_fix_product_images(array $opts = []): array
{
    $limit = isset($opts['limit']) ? max(1, (int) $opts['limit']) : 500;
    $dry = !empty($opts['dry_run']);
    $allow_web = !array_key_exists('allow_web', $opts) || !empty($opts['allow_web']);
    $out = [
        'examined' => 0,
        'fixed' => 0,
        'drafted' => 0,
        'shared_fixed' => 0,
        'web_fallback' => 0,
        'ok' => 0,
        'messages' => [],
    ];
    sa_core_bootstrap_image_claim_registry();
    $ids = get_posts([
        'post_type' => 'product',
        'post_status' => 'publish',
        'posts_per_page' => $limit,
        'fields' => 'ids',
        'orderby' => 'ID',
        'order' => 'DESC',
    ]);
    foreach ($ids as $id) {
        $id = (int) $id;
        $out['examined']++;
        $thumb = (int) get_post_thumbnail_id($id);
        $urls = [];
        if (function_exists('sa_core_get_stored_shopify_image_urls')) {
            $urls = sa_core_get_stored_shopify_image_urls($id);
        }
        $urls = sa_core_filter_urls_unique_for_product($urls, $id);
        $needs = !$thumb || sa_core_product_needs_image_backfill($id);
        // Detect shared featured attachment across products
        if ($thumb) {
            $src_meta = (string) get_post_meta($thumb, '_sa_source_image_url', true);
            $guid = (string) get_the_guid($thumb);
            foreach ([$src_meta, $guid] as $u) {
                if ($u && sa_core_is_image_url_claimed_by_other($u, $id)) {
                    $needs = true;
                    $out['shared_fixed']++;
                    break;
                }
            }
        }
        if (!$needs && $thumb) {
            $out['ok']++;
            continue;
        }
        if ($urls === [] && $allow_web) {
            $title = get_the_title($id);
            $sku = get_post_meta($id, '_sku', true);
            $item = [
                'title' => $title,
                'vendor' => '',
                'variants' => [['sku' => (string) $sku]],
            ];
            $fb = sa_core_fetch_web_fallback_image_url($item, $id);
            if ($fb !== '') {
                $urls = [$fb];
                $out['web_fallback']++;
            }
        }
        if ($urls === []) {
            if (!$dry) {
                wp_update_post(['ID' => $id, 'post_status' => 'draft']);
            }
            $out['drafted']++;
            if (count($out['messages']) < 40) {
                $out['messages'][] = "draft #$id (no unique image)";
            }
            continue;
        }
        if (!$dry) {
            update_post_meta($id, '_sa_shopify_image_urls', wp_json_encode($urls));
            update_post_meta($id, '_sa_shopify_image_src', $urls[0]);
            sa_core_claim_image_urls_for_product($id, $urls);
            sa_core_sideload_product_gallery($id, array_slice($urls, 0, 3));
        }
        if ($dry || get_post_thumbnail_id($id)) {
            $out['fixed']++;
        } else {
            if (!$dry) {
                wp_update_post(['ID' => $id, 'post_status' => 'draft']);
            }
            $out['drafted']++;
        }
    }
    return $out;
}





/**
 * Checkout / catalog store currency for imports (WOO_CURRENCY / SA_CHECKOUT_CURRENCY).
 */
function sa_core_import_store_currency(): string
{
    $env = getenv('SA_CHECKOUT_CURRENCY') ?: getenv('WOO_CURRENCY') ?: 'USD';
    if (is_string($env) && preg_match('/^[A-Za-z]{3}$/', trim($env))) {
        return strtoupper(trim($env));
    }
    return 'USD';
}

/**
 * Convert Shopify USD variant amount into store currency units.
 * USD (default): store as-is. KES (legacy): multiply by SUPREME_USD_TO_KES (~130).
 */
function sa_core_shopify_usd_to_store_amount(float $usd): float
{
    if (!is_finite($usd) || $usd <= 0) {
        return 0.0;
    }
    $currency = sa_core_import_store_currency();
    if ($currency === 'KES') {
        $rate = (float) (getenv('SUPREME_USD_TO_KES') ?: '130');
        if (!is_finite($rate) || $rate <= 0) {
            $rate = 130.0;
        }
        return round($usd * $rate, 2);
    }
    return round($usd, 2);
}

/**
 * Candidate NDJSON paths for price repair (baked chunks + live scrape).
 *
 * @return list<string>
 */
function sa_core_price_repair_ndjson_candidates(): array
{
    $paths = [
        SA_CORE_DIR . 'data/scrape/chunks/batch-with-images-400.ndjson',
        SA_CORE_DIR . 'data/scrape/chunks/batch-with-images-50.ndjson',
        SA_CORE_DIR . 'data/scrape/chunks/batch-with-images-1000.ndjson',
        SA_CORE_DIR . 'data/scrape/chunks/batch-with-images-2000.ndjson',
        trailingslashit(ABSPATH) . 'data/scrape/chunks/batch-with-images-400.ndjson',
        trailingslashit(ABSPATH) . 'data/scrape/chunks/batch-with-images-50.ndjson',
        trailingslashit(ABSPATH) . 'data/scrape/chunks/batch-with-images-1000.ndjson',
        trailingslashit(ABSPATH) . 'data/scrape/products.ndjson',
        '/usr/src/supreme-data/scrape/chunks/batch-with-images-400.ndjson',
        '/usr/src/supreme-data/scrape/chunks/batch-with-images-50.ndjson',
        '/usr/src/supreme-data/scrape/products.ndjson',
    ];
    $env = getenv('SUPREME_IMPORT_FILE');
    if (is_string($env) && $env !== '') {
        array_unshift($paths, $env);
    }
    return array_values(array_unique($paths));
}

/**
 * Build shopify_id / handle → {price, compare_at_price} from NDJSON files.
 *
 * @param list<string>|null $files
 * @return array{by_id: array<string, array{price:float,compare:float}>, by_handle: array<string, array{price:float,compare:float}>, files: list<string>}
 */
function sa_core_load_shopify_usd_price_map(?array $files = null): array
{
    $by_id = [];
    $by_handle = [];
    $used = [];
    $candidates = $files ?? sa_core_price_repair_ndjson_candidates();
    foreach ($candidates as $path) {
        if (!is_string($path) || $path === '' || !is_readable($path)) {
            continue;
        }
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            continue;
        }
        $used[] = $path;
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $item = json_decode($line, true);
            if (!is_array($item)) {
                continue;
            }
            $variants = $item['variants'] ?? null;
            if (!is_array($variants) || !$variants) {
                continue;
            }
            $variant = $variants[0];
            if (!is_array($variant)) {
                continue;
            }
            $price = isset($variant['price']) ? (float) $variant['price'] : 0.0;
            if (!is_finite($price) || $price <= 0) {
                continue;
            }
            $compare = isset($variant['compare_at_price']) && $variant['compare_at_price'] !== null && $variant['compare_at_price'] !== ''
                ? (float) $variant['compare_at_price']
                : 0.0;
            if (!is_finite($compare) || $compare < 0) {
                $compare = 0.0;
            }
            $row = ['price' => round($price, 2), 'compare' => round($compare, 2)];
            $sid = isset($item['id']) ? (string) $item['id'] : '';
            $handle = isset($item['handle']) ? (string) $item['handle'] : '';
            if ($sid !== '') {
                $by_id[$sid] = $row;
            }
            if ($handle !== '') {
                $by_handle[$handle] = $row;
            }
        }
        fclose($fh);
    }
    return ['by_id' => $by_id, 'by_handle' => $by_handle, 'files' => $used];
}

/**
 * True when a Woo price looks like a Shopify USD amount multiplied by $rate.
 */
function sa_core_price_looks_usd_times_rate(float $stored, float $rate): bool
{
    if (!is_finite($stored) || !is_finite($rate) || $stored <= 0 || $rate <= 1) {
        return false;
    }
    $usd = round($stored / $rate, 2);
    if ($usd < 1.0) {
        return false;
    }
    $rebuilt = round($usd * $rate, 2);
    return abs($rebuilt - round($stored, 2)) < 0.05;
}

/**
 * One-shot repair: products imported as USD*SUPREME_USD_TO_KES while store currency is USD.
 * Prefers NDJSON / _sa_shopify_price_usd; falls back to divide-by-rate when prices look inflated.
 *
 * @param array{limit?:int,dry_run?:bool,rate?:float,force?:bool} $opts
 * @return array{repaired:int,skipped:int,examined:int,dry_run:bool,rate:float,ndjson_files:list<string>,samples:list<array<string,mixed>>,messages:list<string>}
 */
function sa_core_repair_inflated_usd_prices(array $opts = []): array
{
    $limit = isset($opts['limit']) ? max(1, (int) $opts['limit']) : 5000;
    $dry = !empty($opts['dry_run']);
    $force = !empty($opts['force']);
    $rate = isset($opts['rate']) ? (float) $opts['rate'] : (float) (getenv('SUPREME_USD_TO_KES') ?: '130');
    if (!is_finite($rate) || $rate <= 1) {
        $rate = 130.0;
    }

    $out = [
        'repaired' => 0,
        'skipped' => 0,
        'examined' => 0,
        'dry_run' => $dry,
        'rate' => $rate,
        'ndjson_files' => [],
        'samples' => [],
        'messages' => [],
    ];

    if (!class_exists('WooCommerce') || !function_exists('wc_get_product')) {
        $out['messages'][] = 'WooCommerce inactive';
        return $out;
    }

    $map = sa_core_load_shopify_usd_price_map();
    $out['ndjson_files'] = $map['files'];
    $by_id = $map['by_id'];
    $by_handle = $map['by_handle'];

    $q = new WP_Query([
        'post_type' => 'product',
        'post_status' => ['publish', 'draft', 'pending', 'private'],
        'posts_per_page' => $limit,
        'fields' => 'ids',
        'orderby' => 'ID',
        'order' => 'ASC',
        'meta_query' => [
            [
                'key' => '_sa_shopify_id',
                'compare' => 'EXISTS',
            ],
        ],
    ]);

    foreach ($q->posts as $pid) {
        $pid = (int) $pid;
        $out['examined']++;
        $product = wc_get_product($pid);
        if (!$product) {
            $out['skipped']++;
            continue;
        }

        $shopify_id = (string) get_post_meta($pid, '_sa_shopify_id', true);
        $handle = (string) get_post_meta($pid, '_sa_shopify_handle', true);
        $meta_usd = (float) get_post_meta($pid, '_sa_shopify_price_usd', true);
        $currency_meta = strtoupper((string) get_post_meta($pid, '_sa_price_currency', true));

        $row = null;
        if ($shopify_id !== '' && isset($by_id[$shopify_id])) {
            $row = $by_id[$shopify_id];
        } elseif ($handle !== '' && isset($by_handle[$handle])) {
            $row = $by_handle[$handle];
        }

        $target_usd = 0.0;
        $target_compare = 0.0;
        $source = '';

        if (is_array($row) && ($row['price'] ?? 0) > 0) {
            $target_usd = (float) $row['price'];
            $target_compare = (float) ($row['compare'] ?? 0);
            $source = 'ndjson';
        } elseif (is_finite($meta_usd) && $meta_usd > 0) {
            $target_usd = round($meta_usd, 2);
            $source = 'meta';
        }

        $regular = (float) $product->get_regular_price();
        $sale = (float) $product->get_sale_price();
        if (!is_finite($regular)) {
            $regular = 0.0;
        }
        if (!is_finite($sale)) {
            $sale = 0.0;
        }

        // Already correct USD (matches target or meta).
        if (!$force && $target_usd > 0 && abs($regular - $target_usd) < 0.02) {
            if ($currency_meta !== 'USD' && !$dry) {
                update_post_meta($pid, '_sa_price_currency', 'USD');
                update_post_meta($pid, '_sa_shopify_price_usd', (string) $target_usd);
            }
            $out['skipped']++;
            continue;
        }

        if ($target_usd <= 0) {
            // Heuristic: stored amount looks like USD * rate.
            if (!sa_core_price_looks_usd_times_rate($regular, $rate)) {
                $out['skipped']++;
                continue;
            }
            if (!$force && $currency_meta === 'USD' && $meta_usd > 0 && abs($regular - $meta_usd) < 0.02) {
                $out['skipped']++;
                continue;
            }
            // Woo on-sale: sale = Shopify price, regular = compare_at (both previously * rate).
            if ($sale > 0 && sa_core_price_looks_usd_times_rate($sale, $rate) && $sale < $regular) {
                $target_usd = round($sale / $rate, 2);
                $target_compare = round($regular / $rate, 2);
            } else {
                $target_usd = round($regular / $rate, 2);
                $target_compare = 0.0;
            }
            $source = 'divide';
        } else {
            // Authoritative USD from NDJSON/meta — rewrite when inflated or forced.
            $looks_inflated = sa_core_price_looks_usd_times_rate($regular, $rate)
                || ($meta_usd > 0 && abs($regular - round($meta_usd * $rate, 2)) < 0.05)
                || ($currency_meta === 'KES')
                || ($currency_meta === '' && $regular > $target_usd * ($rate * 0.9));
            if (!$force && !$looks_inflated) {
                $out['skipped']++;
                continue;
            }
            if ($target_compare <= $target_usd) {
                $target_compare = 0.0;
            }
        }

        if ($target_usd <= 0 || !is_finite($target_usd)) {
            $out['skipped']++;
            continue;
        }

        $new_regular = $target_usd;
        $new_sale = '';
        if ($target_compare > $target_usd && $target_compare > 0) {
            $new_regular = $target_compare;
            $new_sale = (string) $target_usd;
        }

        $before_regular = $regular;
        $before_sale = $sale;

        if (!$dry) {
            if ($new_sale !== '') {
                $product->set_regular_price((string) round((float) $new_regular, 2));
                $product->set_sale_price((string) round((float) $new_sale, 2));
            } else {
                $product->set_regular_price((string) round((float) $new_regular, 2));
                $product->set_sale_price('');
            }
            $product->save();
            update_post_meta($pid, '_sa_price_currency', 'USD');
            update_post_meta($pid, '_sa_shopify_price_usd', (string) round($target_usd, 2));
            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($pid);
            }
        }

        $out['repaired']++;
        if (count($out['samples']) < 15) {
            $out['samples'][] = [
                'id' => $pid,
                'handle' => $handle,
                'source' => $source,
                'before_regular' => $before_regular,
                'before_sale' => $before_sale > 0 ? $before_sale : null,
                'after_regular' => (float) $new_regular,
                'after_sale' => $new_sale !== '' ? (float) $new_sale : null,
                'usd' => $target_usd,
            ];
        }
    }

    if (!$dry && function_exists('update_option')) {
        update_option('sa_price_usd_repair_v1', '1');
        if ($out['repaired'] > 0) {
            update_option('sa_price_usd_repair_v1_stats', [
                'at' => time(),
                'repaired' => $out['repaired'],
                'examined' => $out['examined'],
                'samples' => array_slice($out['samples'], 0, 5),
            ]);
        }
    }

    return $out;
}


/**
 * Re-assign IA parent categories for all published products based on product_type-like cats.
 * Fixes catalogs imported before parent assignment / after Collections reparent stole leaves.
 *
 * @return array{repaired:int,skipped:int}
 */
function sa_core_repair_product_parent_categories(int $limit = 500): array
{
    $out = ['repaired' => 0, 'skipped' => 0];
    if (!taxonomy_exists('product_cat')) {
        return $out;
    }
    $q = new WP_Query([
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => max(1, $limit),
        'fields'         => 'ids',
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ]);
    foreach ($q->posts as $pid) {
        $pid = (int) $pid;
        $terms = wp_get_post_terms($pid, 'product_cat', ['fields' => 'all']);
        if (is_wp_error($terms) || !$terms) {
            $out['skipped']++;
            continue;
        }
        $term_ids = [];
        $changed = false;
        foreach ($terms as $term) {
            $term_ids[] = (int) $term->term_id;
            // Skip brand/region top-level helpers.
            if (in_array($term->slug, ['brands','collections','american','european','asian'], true)) {
                continue;
            }
            $parent_slug = sa_core_map_product_type_parent_slug($term->name);
            if ($parent_slug === '') {
                $parent_slug = sa_core_map_product_type_parent_slug(str_replace('-', ' ', $term->slug));
            }
            if ($parent_slug === '') {
                continue;
            }
            $labels = [
                'air-intake' => 'Air Intake',
                'brakes' => 'Brakes',
                'drivetrain' => 'Drivetrain',
                'engine' => 'Engine',
                'exhaust' => 'Exhaust',
                'exterior' => 'Exterior',
                'interior' => 'Interior',
                'lighting' => 'Lighting',
                'suspension' => 'Suspension',
                'tires' => 'Tires',
                'wheels' => 'Wheels',
            ];
            $label = $labels[$parent_slug] ?? ucwords(str_replace('-', ' ', $parent_slug));
            $parent_id = sa_core_ensure_product_cat($label, $parent_slug, 0);
            if ($parent_id && !in_array($parent_id, $term_ids, true)) {
                $term_ids[] = $parent_id;
                $changed = true;
            }
            // Ensure leaf sits under IA parent (not Collections).
            if ($parent_id && (int) $term->parent !== $parent_id && $term->slug !== $parent_slug) {
                wp_update_term((int) $term->term_id, 'product_cat', ['parent' => $parent_id]);
                $changed = true;
            }
        }
        if ($changed) {
            wp_set_object_terms($pid, array_values(array_unique(array_map('intval', $term_ids))), 'product_cat', false);
            $out['repaired']++;
        } else {
            $out['skipped']++;
        }
    }
    return $out;
}
