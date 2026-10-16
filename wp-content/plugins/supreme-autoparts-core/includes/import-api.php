<?php
/**
 * Authenticated batch import API for the verified Shopify catalog (singles + families).
 *
 * POST /wp-json/supreme/v1/import-batch      (manage_woocommerce)
 * POST /wp-json/supreme/v1/import-rollback   (manage_woocommerce)
 *
 * Reuses sa_core_import_one_shopify_product() for single products. Families
 * (same picture, many sizes) become ONE variable product with one variation per
 * original product. Prices are copied exactly (via sa_core_shopify_usd_to_store_amount).
 * Images are never downloaded: all CDN URLs are stored in _sa_shopify_image_urls.
 * Does not touch recommendations or any other plugin code.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', static function () {
    register_rest_route('supreme/v1', '/import-batch', [
        'methods'             => 'POST',
        'callback'            => 'sa_core_rest_import_batch',
        'permission_callback' => static function () {
            return current_user_can('manage_woocommerce');
        },
    ]);
    register_rest_route('supreme/v1', '/import-rollback', [
        'methods'             => 'POST',
        'callback'            => 'sa_core_rest_import_rollback',
        'permission_callback' => static function () {
            return current_user_can('manage_woocommerce');
        },
    ]);
});

function sa_core_rest_import_batch(WP_REST_Request $req)
{
    if (!function_exists('sa_core_import_one_shopify_product') && defined('SA_CORE_DIR')) {
        require_once SA_CORE_DIR . 'includes/import-shopify.php';
    }
    if (!class_exists('WooCommerce') || !function_exists('sa_core_import_one_shopify_product')) {
        return new WP_Error('sa_import_unavailable', 'Importer not loaded', ['status' => 500]);
    }
    $body    = $req->get_json_params();
    $items   = is_array($body['items'] ?? null) ? $body['items'] : [];
    $dry     = !empty($body['dry_run']);
    $batch   = sanitize_text_field((string) ($body['batch'] ?? 'api'));
    $GLOBALS['sa_core_fast_import'] = true; // never web-search images
    sa_core_fast_bootstrap_claims();
    wp_defer_term_counting(true);   // recount terms once at the end of the request
    wp_defer_comment_counting(true);
    global $wpdb;
    @set_time_limit(280);
    @ini_set('memory_limit', '1024M'); // image-claim registry scans every product
    $out = ['dry_run' => $dry, 'batch' => $batch, 'created' => [], 'updated' => [], 'skipped' => [], 'errors' => []];
    $out['timing'] = [];
    $prof = !empty($body['profile']);
    if ($prof) {
        $GLOBALS['sa_prof_q'] = [];
        add_filter('query', static function ($sql) {
            $k = substr(preg_replace('/\d+|\'[^\']*\'/', '?', (string) $sql), 0, 70);
            $c = '';
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 25) as $f) {
                $fn = (string) ($f['function'] ?? '');
                $file = (string) ($f['file'] ?? '');
                if (strpos($file, 'wp-includes') === false && strpos($file, 'wp-admin') === false && strpos($file, 'import-api') === false) {
                    $c = $fn . '@' . basename($file);
                    break;
                }
            }
            $kk = $k . ' <= ' . $c;
            $GLOBALS['sa_prof_q'][$kk] = ($GLOBALS['sa_prof_q'][$kk] ?? 0) + 1;
            return $sql;
        });
    }
    foreach (array_slice($items, 0, 40) as $entry) {
        $t0 = microtime(true);
        $q0 = (int) $wpdb->num_queries;
        sa_core_prime_recent_products();
        try {
            $type = (string) ($entry['type'] ?? 'single');
            if ($type === 'family') {
                $r = sa_core_import_family($entry, $dry, $batch);
            } else {
                $r = sa_core_import_single_listing($entry['item'] ?? [], $dry, $batch);
            }
            $key = (string) ($entry['key'] ?? '');
            if ($r['status'] === 'created') {
                $out['created'][] = ['key' => $key, 'id' => $r['id']];
            } elseif ($r['status'] === 'updated') {
                $out['updated'][] = ['key' => $key, 'id' => $r['id']];
            } elseif ($r['status'] === 'error') {
                $out['errors'][] = ['key' => $key, 'msg' => $r['msg'] ?? ''];
            } else {
                $out['skipped'][] = ['key' => $key, 'msg' => $r['msg'] ?? ''];
            }
        } catch (Throwable $e) {
            $out['errors'][] = ['key' => (string) ($entry['key'] ?? ''), 'msg' => $e->getMessage()];
        }
        $out['timing'][] = [round((microtime(true) - $t0) * 1000), (int) $wpdb->num_queries - $q0];
    }
    if ($prof) {
        arsort($GLOBALS['sa_prof_q']);
        $out['profile'] = array_slice($GLOBALS['sa_prof_q'], 0, 30, true);
    }
    $t1 = microtime(true);
    wp_defer_term_counting(false);
    wp_defer_comment_counting(false);
    $out['recount_ms'] = round((microtime(true) - $t1) * 1000);
    return rest_ensure_response($out);
}

function sa_core_rest_import_rollback(WP_REST_Request $req)
{
    $body  = $req->get_json_params();
    $batch = sanitize_text_field((string) ($body['batch'] ?? ''));
    if ($batch === '') {
        return new WP_Error('sa_rollback_batch', 'batch required', ['status' => 400]);
    }
    $ids = get_posts([
        'post_type' => 'product', 'post_status' => 'any', 'posts_per_page' => 500, 'fields' => 'ids',
        'meta_key' => '_sa_import_batch', 'meta_value' => $batch,
    ]);
    $n = 0;
    foreach ($ids as $id) {
        if (wp_trash_post((int) $id)) {
            $n++;
        }
    }
    return rest_ensure_response(['batch' => $batch, 'trashed' => $n]);
}

function sa_core_find_product_by_shopify_id(string $sid): int
{
    if ($sid === '') {
        return 0;
    }
    $q = get_posts([
        'post_type' => 'product', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids',
        'meta_key' => '_sa_shopify_id', 'meta_value' => $sid,
    ]);
    return $q ? (int) $q[0] : 0;
}

/** Single (1 variant) product through the existing, unchanged importer. */
function sa_core_import_single_listing(array $item, bool $dry, string $batch): array
{
    if (empty($item['handle']) || empty($item['images'])) {
        return ['status' => 'skipped', 'msg' => 'no handle or no images'];
    }
    $sid    = (string) ($item['id'] ?? '');
    $before = sa_core_find_product_by_shopify_id($sid);
    $result = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'messages' => []];
    sa_core_import_one_shopify_product($item, $result, true, $dry, true);
    if ($dry) {
        return ['status' => 'skipped', 'msg' => 'dry-run ok'];
    }
    $id = sa_core_find_product_by_shopify_id($sid);
    if (!$id || !empty($result['errors'])) {
        return ['status' => 'error', 'msg' => implode('; ', $result['messages']) ?: 'import failed'];
    }
    update_post_meta($id, '_sa_import_batch', $batch);
    return ['status' => $before ? 'updated' : 'created', 'id' => $id];
}

/**
 * Family = several original products (members) sharing one picture.
 * Entry: {key, name, vendor, product_type, tags, body_html, images[], members:[{id,title,handle,sku,price,compare,available,grams,label}]}
 */
function sa_core_import_family(array $f, bool $dry, string $batch): array
{
    $members = is_array($f['members'] ?? null) ? $f['members'] : [];
    $images  = array_values(array_filter(array_map('strval', (array) ($f['images'] ?? []))));
    $name    = trim((string) ($f['name'] ?? ''));
    if (count($members) < 2 || !$images || $name === '') {
        return ['status' => 'skipped', 'msg' => 'family needs 2+ members, images and a name'];
    }
    if ($dry) {
        return ['status' => 'skipped', 'msg' => 'dry-run ok'];
    }
    $fid  = 'family:' . (string) ($f['key'] ?? '');
    $slug = sanitize_title((string) ($f['slug'] ?? $name));
    $existing = sa_core_find_product_by_shopify_id($fid);
    $product  = $existing ? wc_get_product($existing) : new WC_Product_Variable();
    if (!$product || !$product->is_type('variable')) {
        return ['status' => 'error', 'msg' => 'existing product is not variable'];
    }
    $product->set_name(wp_strip_all_tags($name));
    if (!$existing) {
        $product->set_slug($slug);
    }
    $product->set_status('publish');
    $product->set_catalog_visibility('visible');
    $body = (string) ($f['body_html'] ?? '');
    $product->set_description($body);
    $product->set_short_description(trim(wp_strip_all_tags($body)) === '' ? '' : wp_trim_words(wp_strip_all_tags($body), 40));

    // One attribute "Option"; labels must be unique.
    $labels = [];
    $seen   = [];
    foreach ($members as $m) {
        $l = trim((string) ($m['label'] ?? ''));
        if ($l === '' || isset($seen[strtolower($l)])) {
            $l = trim((string) ($m['title'] ?? $l));
        }
        $seen[strtolower($l)] = true;
        $labels[] = $l;
    }
    $attr = new WC_Product_Attribute();
    $attr->set_id(0);
    $attr->set_name('Option');
    $attr->set_options($labels);
    $attr->set_position(0);
    $attr->set_visible(true);
    $attr->set_variation(true);
    $product->set_attributes([$attr]);
    $id = $product->save();
    if (!$id) {
        return ['status' => 'error', 'msg' => 'parent save failed'];
    }

    // Categories + brand (same rules as the single importer).
    $term_ids = [];
    $ptype = (string) ($f['product_type'] ?? '');
    if ($ptype !== '' && function_exists('sa_core_assign_product_type_categories')) {
        foreach (sa_core_assign_product_type_categories($ptype) as $tid) {
            if ($tid) {
                $term_ids[] = (int) $tid;
            }
        }
    }
    $vendor = (string) ($f['vendor'] ?? '');
    if ($vendor !== '') {
        $bp = function_exists('sa_core_ensure_term') ? (int) sa_core_ensure_term('Brands', 'brands') : 0;
        $tid = sa_core_ensure_product_cat($vendor, sanitize_title($vendor), $bp > 0 ? $bp : 0);
        if ($tid) {
            $term_ids[] = (int) $tid;
        }
    }
    if ($term_ids) {
        wp_set_object_terms($id, array_values(array_unique($term_ids)), 'product_cat', false);
    }
    $tags = array_values(array_filter(array_map('trim', (array) ($f['tags'] ?? []))));
    if ($tags) {
        wp_set_object_terms($id, $tags, 'product_tag', false);
    }

    // Variations: replace the set on re-import.
    foreach ($product->get_children() as $child) {
        wp_delete_post((int) $child, true);
    }
    foreach ($members as $i => $m) {
        $v = new WC_Product_Variation();
        $v->set_parent_id($id);
        $v->set_attributes(['option' => $labels[$i]]);
        $price = sa_core_shopify_usd_to_store_amount((float) ($m['price'] ?? 0));
        if ($price > 0) {
            $v->set_regular_price((string) $price);
        }
        $cmp = sa_core_shopify_usd_to_store_amount((float) ($m['compare'] ?? 0));
        if ($cmp > $price && $price > 0) {
            $v->set_sale_price((string) $price);
            $v->set_regular_price((string) $cmp);
        }
        $sku = trim((string) ($m['sku'] ?? ''));
        if ($sku !== '') {
            try {
                $v->set_sku($sku);
            } catch (Throwable $e) {
                // duplicate SKU: leave blank, original id is kept in meta
            }
        }
        $v->set_manage_stock(false);
        $v->set_stock_status(!empty($m['available']) ? 'instock' : 'outofstock');
        $v->set_description(wp_strip_all_tags((string) ($m['title'] ?? '')));
        $grams = (float) ($m['grams'] ?? 0);
        if ($grams > 0) {
            $v->set_weight((string) round($grams / 453.59237, 2));
        }
        $v->set_status('publish');
        $vid = $v->save();
        if ($vid) {
            update_post_meta($vid, '_sa_shopify_id', (string) ($m['id'] ?? ''));
            update_post_meta($vid, '_sa_shopify_handle', (string) ($m['handle'] ?? ''));
            update_post_meta($vid, '_sa_shopify_price_usd', (string) round((float) ($m['price'] ?? 0), 2));
        }
    }
    WC_Product_Variable::sync($id);
    wc_delete_product_transients($id);

    update_post_meta($id, '_sa_shopify_image_urls', wp_json_encode($images));
    update_post_meta($id, '_sa_shopify_image_src', $images[0]);
    update_post_meta($id, '_sa_image_source', 'shopify_cdn');
    update_post_meta($id, '_sa_shopify_id', $fid);
    update_post_meta($id, '_sa_shopify_handle', $slug);
    update_post_meta($id, '_sa_price_currency', sa_core_import_store_currency());
    update_post_meta($id, '_sa_import_batch', $batch);
    if (function_exists('sa_core_claim_image_urls_for_product')) {
        sa_core_claim_image_urls_for_product((int) $id, $images);
    }
    return ['status' => $existing ? 'updated' : 'created', 'id' => (int) $id];
}

/**
 * Same registry the importer builds (image URL -> owning product), filled with a few
 * chunked SQL queries instead of ~75k get_post_meta() calls. Sets the importer's
 * bootstrapped flag so it is not rebuilt per request.
 */
function sa_core_fast_bootstrap_claims()
{
    if (!empty($GLOBALS['sa_core_image_claim_registry_bootstrapped']) || !function_exists('sa_core_image_claim_registry')) {
        return;
    }
    global $wpdb;
    $reg  = &sa_core_image_claim_registry();
    $last = 0;
    while (true) {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_id > %d AND meta_key IN ('_sa_shopify_image_src','_sa_shopify_image_urls','_sa_web_fallback_image_url') ORDER BY meta_id ASC LIMIT 4000",
            $last
        ), ARRAY_A);
        if (!$rows) {
            break;
        }
        foreach ($rows as $row) {
            $last = (int) $row['meta_id'];
            $pid  = (int) $row['post_id'];
            $val  = (string) $row['meta_value'];
            if ($val === '') {
                continue;
            }
            if ($row['meta_key'] === '_sa_shopify_image_urls') {
                $dec = json_decode($val, true);
                if (is_array($dec)) {
                    foreach ($dec as $u) {
                        if (is_string($u) && $u !== '') {
                            $reg[sa_core_image_url_identity_key($u)] = $pid;
                        }
                    }
                }
            } else {
                $reg[sa_core_image_url_identity_key($val)] = $pid;
            }
        }
        unset($rows);
    }
    $rows = $wpdb->get_results(
        "SELECT pm.meta_value AS url, p.post_parent AS parent FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_sa_source_image_url' AND pm.meta_value <> '' AND p.post_parent > 0 LIMIT 50000",
        ARRAY_A
    );
    foreach ((array) $rows as $row) {
        $reg[sa_core_image_url_identity_key((string) $row['url'])] = (int) $row['parent'];
    }
    $GLOBALS['sa_core_image_claim_registry_bootstrapped'] = true;
}

/**
 * The importer's title-fingerprint check reads the 400 newest published products one
 * get_post() at a time (~130 queries per item). Prime them into the object cache with
 * one query so the check returns the same result with far fewer round trips.
 */
function sa_core_prime_recent_products()
{
    global $wpdb;
    $ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' ORDER BY ID DESC LIMIT 400");
    if ($ids && function_exists('_prime_post_caches')) {
        _prime_post_caches(array_map('intval', $ids), false, false);
    }
}
