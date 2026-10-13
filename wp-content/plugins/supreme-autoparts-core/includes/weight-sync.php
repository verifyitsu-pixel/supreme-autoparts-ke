<?php
/**
 * One-shot, resumable sync of real shipping weights (lbs) from the Shopify scrape
 * (variant grams) into WooCommerce _weight. Only fills the weight field; never
 * touches other product data. Runs in small batches via WP-Cron until done.
 * Status: GET /wp-json/sa/v1/weight-sync (read-only).
 */
if (!defined('ABSPATH')) {
    exit;
}

const SA_WEIGHT_SYNC_VERSION = '2026-10-13a';
const SA_WEIGHT_SYNC_BATCH   = 1500;

function sa_weight_sync_map(): array
{
    static $map = null;
    if ($map === null) {
        $raw = @file_get_contents(SA_CORE_DIR . 'data/shopify-weights-lbs.json.gz');
        $json = $raw !== false ? @gzdecode($raw) : false;
        $map = $json ? (array) json_decode($json, true) : [];
    }
    return $map;
}

function sa_weight_sync_run_batch(): array
{
    global $wpdb;
    $state = get_option('sa_weight_sync_state', []);
    if (!is_array($state) || ($state['version'] ?? '') !== SA_WEIGHT_SYNC_VERSION) {
        $state = ['version' => SA_WEIGHT_SYNC_VERSION, 'last_id' => 0, 'scanned' => 0, 'set' => 0, 'already' => 0, 'no_source' => 0, 'no_shopify_id' => 0, 'done' => false, 'started' => time()];
    }
    if (!empty($state['done'])) {
        return $state;
    }
    $map = sa_weight_sync_map();
    if (!$map) {
        $state['error'] = 'weights file missing';
        update_option('sa_weight_sync_state', $state, false);
        return $state;
    }
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, sid.meta_value sid, w.meta_value w FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} sid ON sid.post_id=p.ID AND sid.meta_key='_sa_shopify_id'
         LEFT JOIN {$wpdb->postmeta} w ON w.post_id=p.ID AND w.meta_key='_weight'
         WHERE p.post_type='product' AND p.ID>%d ORDER BY p.ID ASC LIMIT %d",
        (int) $state['last_id'], SA_WEIGHT_SYNC_BATCH
    ), ARRAY_A);
    if (!$rows) {
        $state['done'] = true;
        $state['finished'] = time();
        delete_transient('sa_fargo_weight_stats');
        update_option('sa_weight_sync_state', $state, false);
        return $state;
    }
    foreach ($rows as $r) {
        $id = (int) $r['ID'];
        $state['last_id'] = $id;
        $state['scanned']++;
        $sid = trim((string) $r['sid']);
        if ($sid === '') {
            $state['no_shopify_id']++;
            continue;
        }
        if (!isset($map[$sid])) {
            $state['no_source']++;
            continue;
        }
        $lbs = (string) $map[$sid];
        if ((string) $r['w'] === $lbs) {
            $state['already']++;
            continue;
        }
        update_post_meta($id, '_weight', wc_format_decimal($lbs));
        $state['set']++;
    }
    if (function_exists('wc_delete_product_transients')) {
        wc_delete_product_transients();
    }
    delete_transient('sa_fargo_weight_stats');
    $state['updated'] = time();
    update_option('sa_weight_sync_state', $state, false);
    return $state;
}

add_action('sa_weight_sync_tick', static function (): void {
    if (get_transient('sa_weight_sync_lock')) {
        return;
    }
    set_transient('sa_weight_sync_lock', 1, 120);
    @set_time_limit(120);
    $state = sa_weight_sync_run_batch();
    delete_transient('sa_weight_sync_lock');
    if (empty($state['done']) && empty($state['error'])) {
        wp_schedule_single_event(time() + 5, 'sa_weight_sync_tick');
    }
});

add_action('init', static function (): void {
    $state = get_option('sa_weight_sync_state', []);
    $done = is_array($state) && ($state['version'] ?? '') === SA_WEIGHT_SYNC_VERSION && !empty($state['done']);
    if (!$done && !wp_next_scheduled('sa_weight_sync_tick') && !get_transient('sa_weight_sync_lock')) {
        wp_schedule_single_event(time() + 5, 'sa_weight_sync_tick');
    }
}, 99);

add_action('rest_api_init', static function (): void {
    register_rest_route('sa/v1', '/weight-sync', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => static function () {
            $s = get_option('sa_weight_sync_state', []);
            unset($s['error_detail']);
            return ['state' => $s, 'source_weights' => count(sa_weight_sync_map())];
        },
    ]);
});
