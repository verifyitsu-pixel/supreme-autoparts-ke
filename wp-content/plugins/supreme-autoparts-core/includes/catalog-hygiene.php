<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Identify the accidental demo/free-shipping utility product.
 *
 * Keep this title-based as well as slug-based: imports and manual clones can
 * assign a suffixed slug while retaining the same junk title.
 */
function sa_core_is_junk_product_title(string $title, string $slug = ''): bool
{
    $key = sanitize_title(wp_strip_all_tags($title));
    $slug_key = sanitize_title($slug);

    return (bool) preg_match('/^free-shipping-service-do-not-order(?:-\d+)?$/', $key)
        || (bool) preg_match('/^free-shipping-service-do-not-order(?:-\d+)?$/', $slug_key);
}

/**
 * Keep the accidental demo product out of every public catalog surface.
 * Draft removes it from shop/search/bestsellers/featured loops; hidden
 * visibility protects against an accidental republish or catalog sync.
 */
function sa_core_hide_junk_product(int $product_id): void
{
    $post = get_post($product_id);
    if (!$post || $post->post_type !== 'product' || !sa_core_is_junk_product_title((string) $post->post_title, (string) $post->post_name)) {
        return;
    }

    static $busy = [];
    if (!empty($busy[$product_id])) {
        return;
    }
    $busy[$product_id] = true;

    if ($post->post_status === 'publish') {
        wp_update_post([
            'ID'          => $product_id,
            'post_status' => 'draft',
        ]);
    }

    update_post_meta($product_id, '_sa_catalog_excluded', '1');
    update_post_meta($product_id, '_sa_junk_product_cleanup_v1', gmdate('c'));

    if (function_exists('wc_get_product')) {
        $product = wc_get_product($product_id);
        if ($product) {
            $product->set_catalog_visibility('hidden');
            $product->save();
        }
    }

    unset($busy[$product_id]);
}

/**
 * Enforce the block on future imports/manual clones before they can publish.
 */
add_filter('wp_insert_post_data', static function (array $data, array $postarr): array {
    if (($data['post_type'] ?? '') === 'product'
        && sa_core_is_junk_product_title((string) ($data['post_title'] ?? ''), (string) ($data['post_name'] ?? ''))) {
        $data['post_status'] = 'draft';
    }
    return $data;
}, 99, 2);

add_action('save_post_product', static function (int $post_id, WP_Post $post): void {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }
    sa_core_hide_junk_product($post_id);
}, 100, 2);

/**
 * One-shot cleanup for existing live rows after WooCommerce has initialized. The hook above remains active so
 * a later scrape/import cannot recreate this junk in public loops.
 */
add_action('woocommerce_init', static function (): void {
    if (get_option('sa_junk_product_cleanup_v2') === '1') {
        return;
    }

    global $wpdb;
    $ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish','draft','pending','private') AND post_title LIKE %s",
            'product',
            '%Free shipping service%'
        )
    );
    foreach ($ids ?: [] as $id) {
        sa_core_hide_junk_product((int) $id);
    }
    update_option('sa_junk_product_cleanup_v2', '1');
}, 20);

/**
 * Hide junk supplier tags on the front end: Turn14 fitment terms
 * (`fits_...`), Parts Authority fitment (`...-esi<ID>`), supplier attribute
 * dumps (`ClearanceItem:...`, `TURN14_ID:...`, `EPA:...` - any name with a
 * colon) and supplier category-path dumps (names containing '>').
 * Import writes (wp_set_object_terms) and the catalog-facets Vehicle filter
 * (raw SQL) never pass through get_the_terms, so they are unaffected;
 * wp-admin keeps every tag.
 */
add_filter('get_the_terms', static function ($terms, $post_id, $taxonomy) {
    if ($taxonomy !== 'product_tag' || is_admin() || ! is_array($terms)) {
        return $terms;
    }
    $kept = [];
    foreach ($terms as $term) {
        $name = (string) ($term->name ?? '');
        if (str_starts_with($name, 'fits_') || str_contains($name, ':') || str_contains($name, '>') || preg_match('/-esi\d+$/i', $name)) {
            continue;
        }
        $kept[] = $term;
    }
    return $kept;
}, 10, 3);
