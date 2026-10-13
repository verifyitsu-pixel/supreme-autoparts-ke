<?php
/**
 * Catalog gap list (public product data only) + applier for sourced photo/price/weight fixes.
 * GET /wp-json/sa/v1/catalog-gaps?type=photo|price|weight&page=N
 * Fixes come from data/catalog-fixes.json (each with source URL), applied in WP-Cron batches.
 */
declare(strict_types=1);
if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', static function (): void {
    register_rest_route('sa/v1', '/catalog-gaps', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => static function (WP_REST_Request $r) {
            global $wpdb;
            $p = $wpdb->posts; $m = $wpdb->postmeta;
            $type = (string) $r->get_param('type');
            $page = max(1, (int) $r->get_param('page'));
            $where = [
                'photo'  => "NOT EXISTS (SELECT 1 FROM {$m} x WHERE x.post_id=p.ID AND ((x.meta_key='_thumbnail_id' AND x.meta_value+0>0) OR (x.meta_key IN ('_sa_shopify_image_urls','_sa_shopify_image_src','_sa_primary_image_url','_sa_image_url') AND x.meta_value LIKE '%http%')))",
                'price'  => "NOT EXISTS (SELECT 1 FROM {$m} x WHERE x.post_id=p.ID AND x.meta_key='_price' AND x.meta_value+0>0)",
                'weight' => "NOT EXISTS (SELECT 1 FROM {$m} x WHERE x.post_id=p.ID AND x.meta_key='_sa_weight_source')",
            ][$type] ?? null;
            if (!$where) {
                return new WP_Error('type', 'type must be photo|price|weight', ['status' => 400]);
            }
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT p.ID id, p.post_title title, p.post_name slug,
                  (SELECT meta_value FROM {$m} WHERE post_id=p.ID AND meta_key='_sku' LIMIT 1) sku,
                  (SELECT meta_value FROM {$m} WHERE post_id=p.ID AND meta_key='_sa_shopify_id' LIMIT 1) shopify_id,
                  (SELECT meta_value FROM {$m} WHERE post_id=p.ID AND meta_key='_price' LIMIT 1) price,
                  (SELECT meta_value FROM {$m} WHERE post_id=p.ID AND meta_key='_weight' LIMIT 1) weight
                 FROM {$p} p WHERE p.post_type='product' AND p.post_status='publish' AND {$where}
                 ORDER BY p.ID LIMIT 500 OFFSET %d", ($page - 1) * 500
            ), ARRAY_A);
            foreach ($rows as &$row) {
                $row['brand'] = function_exists('sa_gpf_brand_name') && ($pr = wc_get_product((int) $row['id'])) ? sa_gpf_brand_name($pr) : '';
            }
            return $rows;
        },
    ]);
});

/* ---------- applier ---------- */
const SA_FIX_BATCH = 200;

function sa_fix_data(): array
{
    $f = SA_CORE_DIR . 'data/catalog-fixes.json';
    $d = is_readable($f) ? json_decode((string) file_get_contents($f), true) : null;
    return is_array($d) ? $d : ['version' => '', 'items' => []];
}

function sa_fix_run_batch(): array
{
    $d = sa_fix_data();
    $st = get_option('sa_fix_state', []);
    if (!is_array($st) || ($st['version'] ?? '') !== ($d['version'] ?? '')) {
        $st = ['version' => $d['version'] ?? '', 'pos' => 0, 'photo' => 0, 'price' => 0, 'weight' => 0, 'done' => false];
    }
    if (!empty($st['done'])) {
        return $st;
    }
    $items = array_slice((array) $d['items'], (int) $st['pos'], SA_FIX_BATCH);
    if (!$items) {
        $st['done'] = true;
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients();
        }
        delete_transient('sa_catalog_vis_audit');
        update_option('sa_fix_state', $st, false);
        return $st;
    }
    foreach ($items as $it) {
        $st['pos']++;
        $id = (int) ($it['id'] ?? 0);
        $product = $id ? wc_get_product($id) : null;
        if (!$product) {
            continue;
        }
        if (!empty($it['image']) && preg_match('#^https://#', (string) $it['image'])) {
            update_post_meta($id, '_sa_shopify_image_urls', wp_json_encode(array_values((array) ($it['images'] ?? [$it['image']]))));
            update_post_meta($id, '_sa_shopify_image_src', (string) $it['image']);
            update_post_meta($id, '_sa_photo_source', (string) ($it['image_source'] ?? ''));
            $st['photo']++;
        }
        if (isset($it['price_usd']) && is_numeric($it['price_usd']) && (float) $it['price_usd'] > 0) {
            // Same conversion the importer uses for supreme-mods USD amounts.
            $conv = static fn (float $v): float => function_exists('sa_core_shopify_usd_to_store_amount') ? (float) sa_core_shopify_usd_to_store_amount($v) : $v;
            $reg = $conv((float) $it['price_usd']);
            $cmp = isset($it['compare_usd']) && is_numeric($it['compare_usd']) ? $conv((float) $it['compare_usd']) : 0.0;
            if ($cmp > $reg) {
                $product->set_regular_price((string) $cmp);
                $product->set_sale_price((string) $reg);
            } else {
                $product->set_regular_price((string) $reg);
                $product->set_sale_price('');
            }
            $product->save();
            update_post_meta($id, '_sa_price_source', (string) ($it['price_source'] ?? ''));
            $st['price']++;
        }
        if (isset($it['weight_lbs']) && is_numeric($it['weight_lbs']) && (float) $it['weight_lbs'] > 0) {
            update_post_meta($id, '_weight', wc_format_decimal((string) $it['weight_lbs']));
            update_post_meta($id, '_sa_weight_source', (string) ($it['weight_source'] ?? ''));
            $st['weight']++;
        }
    }
    update_option('sa_fix_state', $st, false);
    return $st;
}

add_action('sa_fix_tick', static function (): void {
    if (get_transient('sa_fix_lock')) {
        return;
    }
    set_transient('sa_fix_lock', 1, 120);
    $st = sa_fix_run_batch();
    delete_transient('sa_fix_lock');
    if (empty($st['done'])) {
        wp_schedule_single_event(time() + 5, 'sa_fix_tick');
    }
});
add_action('init', static function (): void {
    $d = sa_fix_data();
    if (($d['version'] ?? '') === '') {
        return;
    }
    $st = get_option('sa_fix_state', []);
    $done = is_array($st) && ($st['version'] ?? '') === $d['version'] && !empty($st['done']);
    if (!$done && !wp_next_scheduled('sa_fix_tick') && !get_transient('sa_fix_lock')) {
        wp_schedule_single_event(time() + 5, 'sa_fix_tick');
    }
}, 99);
add_action('rest_api_init', static function (): void {
    register_rest_route('sa/v1', '/catalog-fixes', [
        'methods' => 'GET', 'permission_callback' => '__return_true',
        'callback' => static fn () => ['state' => get_option('sa_fix_state', []), 'items' => count((array) sa_fix_data()['items'])],
    ]);
});
