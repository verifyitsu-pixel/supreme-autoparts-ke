<?php
// Inert value-only expectation builder. It never saves a product or changes a manifest.
declare(strict_types=1);
function sa_publication_expected_state(array $before, array $after, array $visibility_ids, bool $campaign_marker): array
{
    $expected = $before;
    $expected['post']['post_status'] = 'publish';
    foreach (['post_modified', 'post_modified_gmt'] as $key) {
        if (!isset($after['post'][$key])) throw new RuntimeException('Missing modified time');
        $expected['post'][$key] = $after['post'][$key];
    }
    $expected['terms'] = array_values(array_filter($before['terms'], static function ($term) use ($visibility_ids): bool {
        // wp_get_object_terms returns WP_Term objects; JSON-recovered snapshots use arrays.
        $row = is_object($term) ? get_object_vars($term) : $term;
        if (!is_array($row) || !isset($row['taxonomy'], $row['term_id'])) throw new RuntimeException('Invalid term snapshot');
        return !($row['taxonomy'] === 'product_visibility' && in_array((int) $row['term_id'], $visibility_ids, true));
    }));
    if ($campaign_marker) $expected['meta']['_sm_campaign_created'] = ['1'];
    ksort($expected['meta']);
    return $expected;
}
