<?php
/**
 * Preserve WooCommerce's visible term counts without rewriting its whole-site
 * wc_term_counts transient for every newly encountered term.
 *
 * WooCommerce's source of truth is the same per-term product_count_* metadata.
 * WordPress primes and request-caches term metadata for normal term queries.
 * This changes no product, term, stock, price, recommendation or scoring data.
 */
if (!defined('ABSPATH')) {
    exit;
}

function sa_term_counts_from_metadata($terms, $taxonomies) {
    if (is_admin() || wp_doing_ajax()) {
        return $terms;
    }

    $valid_taxonomies = apply_filters(
        'woocommerce_change_term_counts',
        ['product_cat', 'product_tag', 'product_brand']
    );
    $current_taxonomies = array_intersect((array) $taxonomies, $valid_taxonomies);
    if (empty($current_taxonomies)) {
        return $terms;
    }

    // Prime once for this result set, rather than issuing a query per term.
    $term_ids = [];
    foreach ($terms as $term) {
        if ($term instanceof WP_Term && in_array($term->taxonomy, $current_taxonomies, true)) {
            $term_ids[] = $term->term_id;
        }
    }
    if ($term_ids) {
        update_meta_cache('term', array_values(array_unique($term_ids)));
    }

    foreach ($terms as $term) {
        if ($term instanceof WP_Term && in_array($term->taxonomy, $current_taxonomies, true)) {
            $count = get_term_meta($term->term_id, 'product_count_' . $term->taxonomy, true);
            $term->count = '' !== $count ? absint($count) : 0;
        }
    }
    return $terms;
}

add_action('plugins_loaded', static function () {
    // If WooCommerce is absent or another component already replaced its
    // handler, leave the site's filter stack alone.
    if (function_exists('wc_change_term_counts') && has_filter('get_terms', 'wc_change_term_counts') === 10) {
        remove_filter('get_terms', 'wc_change_term_counts', 10);
        add_filter('get_terms', 'sa_term_counts_from_metadata', 10, 2);
    }
}, 30);
