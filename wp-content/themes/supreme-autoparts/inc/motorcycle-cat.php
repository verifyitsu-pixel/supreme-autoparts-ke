<?php
declare(strict_types=1);
/**
 * Motorcycle category seed: creates the top-level "Motorcycle" product_cat and
 * tags the existing motorcycle products into it (append-only; source categories
 * and URLs untouched), so /product-category/motorcycle/ behaves like every
 * other category page (brand picker, recommendations, same template).
 * Versioned one-time seed, same pattern as the plugin's page seed.
 */

add_action('init', static function (): void {
    if (get_option('sa_moto_cat_seed_ver') === '2') {
        return;
    }
    if (!function_exists('wc_get_products')) {
        return;
    }
    $term = get_term_by('slug', 'motorcycle', 'product_cat');
    if (!$term || is_wp_error($term)) {
        $r = wp_insert_term('Motorcycle', 'product_cat', [
            'slug'        => 'motorcycle',
            'description' => 'Sport, cruiser and dirt bike parts and riding gear.',
        ]);
        if (is_wp_error($r)) {
            return;
        }
        $tid = (int) $r['term_id'];
    } else {
        $tid = (int) $term->term_id;
    }
    $source_slugs = [
        'motorcycle-boots',
        'motorcycle-slip-on',
        'motorcycle-tires-on-road',
        'motorcycle-tires-off-road',
        'one-piece-motorcycle-seats',
        'two-piece-motorcycle-seats',
        'passenger-motorcycle-seats',
        'solo-motorcycle-seats',
        'bikemaster',
        'bikers-choice',
        'mustang-motorcycle',
    ];
    $ids = [];
    foreach ($source_slugs as $s) {
        $t = get_term_by('slug', $s, 'product_cat');
        if (!$t || is_wp_error($t)) {
            continue;
        }
        $q = get_posts([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'numberposts'    => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'tax_query'      => [['taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => (int) $t->term_id]],
        ]);
        $ids = array_merge($ids, $q);
    }
    foreach (array_unique($ids) as $pid) {
        wp_set_object_terms((int) $pid, [$tid], 'product_cat', true);
    }
    clean_term_cache($tid);
    // wp_set_object_terms fires no product-save hooks, so refresh each product's
    // facet-index row directly (targeted, live table) - otherwise the category's
    // brand picker and filters wait for the weekly full rebuild.
    if (function_exists('sa_fx_reindex_product')) {
        foreach (array_unique($ids) as $pid) {
            sa_fx_reindex_product((int) $pid);
        }
    }
    update_option('sa_moto_cat_seed_ver', '2');
}, 30);
