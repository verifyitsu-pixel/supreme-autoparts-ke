<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Import products from a Shopify products.json-style file OR NDJSON stream.
 *
 * Images: only real http(s) Shopify/CDN URLs from the scrape are used.
 * Never invents, generates, or substitutes placeholder/stock imagery.
 *
 * @param string $path File path (.json with {products:[...]} or .ndjson)
 * @param array{limit?:int,offset?:int,skip_images?:bool,require_images?:bool} $opts
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
    $require_images = !empty($opts['require_images']);

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'ndjson' || $ext === 'jsonl') {
        return sa_core_import_shopify_ndjson($path, $limit, $offset, $skip_images, $require_images, $result);
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
        if ($require_images && !sa_core_shopify_item_has_images($item)) {
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
function sa_core_import_shopify_ndjson(
    string $path,
    int $limit,
    int $offset,
    bool $skip_images,
    bool $require_images,
    array $result
): array {
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
        if ($require_images && !sa_core_shopify_item_has_images($item)) {
            $result['skipped']++;
            $processed++;
            continue;
        }
        sa_core_import_one_shopify_product($item, $result, $skip_images);
        $processed++;
    }
    fclose($fh);
    $result['messages'][] = "NDJSON processed={$processed} offset={$offset} limit=" . ($limit ?: 'all')
        . ' require_images=' . ($require_images ? '1' : '0');
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

        $image_urls = sa_core_collect_shopify_image_urls($item);
        // Always persist real CDN URLs for display/backfill — never invent images.
        if ($image_urls) {
            update_post_meta($id, '_sa_shopify_image_urls', wp_json_encode($image_urls));
            update_post_meta($id, '_sa_shopify_image_src', $image_urls[0]);
        }

        if (!$skip_images && $image_urls) {
            $needs_gallery = sa_core_product_needs_image_backfill((int) $id);
            if ($needs_gallery) {
                sa_core_sideload_product_gallery((int) $id, $image_urls);
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

/**
 * Map Shopify product_type string → IA parent slug (brakes, engine, …).
 */
function sa_core_map_product_type_parent_slug(string $ptype): string
{
    $p = strtolower($ptype);
    $rules = [
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
    foreach ($rules as $parent => $kws) {
        foreach ($kws as $kw) {
            if (str_contains($p, $kw)) {
                return $parent;
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
        'shocks-struts' => ['shock', 'strut'],
        'coilovers' => ['coilover'],
        'sway-bars' => ['sway bar'],
        'cat-back-exhaust' => ['catback', 'cat-back', 'cat back'],
        'axle-back-exhaust' => ['axle back', 'axle-back'],
        'air-intakes' => ['cold air', 'air intake'],
        'fog-lights' => ['fog light'],
        'tonneau-covers' => ['tonneau'],
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
        // Avoid re-downloading the same CDN URL onto this product.
        $existing = sa_core_find_attachment_by_source_url($product_id, $url);
        if ($existing) {
            $attachment_ids[] = $existing;
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
