<?php
/** Source-reviewed equivalent part names. Local only; no provider/request logging. */
declare(strict_types=1);
defined('ABSPATH') || exit;

function sa_sf_intent_rules(): array
{
    return [
        // https://www.mann-filter.com/en/parts/cabin-air-filter.html
        ['aliases' => ['pollen filters', 'pollen filter'], 'label' => 'cabin air filters', 'category' => 202, 'noun' => '/\bcabin (?:air )?filters?\b/'],
        // https://whitelineperformance.com/au/vehicle-swaybars
        ['aliases' => ['anti roll bars', 'anti roll bar', 'stabiliser bars', 'stabiliser bar', 'stabilizer bars', 'stabilizer bar'], 'label' => 'sway bars', 'category' => 123, 'noun' => '/\b(sway\s?bars?|anti roll bars?|stabili[sz]er bars?)\b/'],
        // https://www.bremboparts.com/america/en/products/prime/brake-rotors
        ['aliases' => ['brake discs', 'brake disc'], 'label' => 'brake rotors', 'category' => 32, 'noun' => '/\b(rotors?|brake discs?)\b/'],
    ];
}
function sa_sf_intent_normalize(string $s): string
{
    return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower(html_entity_decode($s, ENT_QUOTES, 'UTF-8'))));
}
/** null = no reviewed equivalent; blocked=true = explicit ambiguity, never relax. */
function sa_sf_intent_plan(string $q): ?array
{
    $q = sa_sf_intent_normalize($q);
    if (strlen($q) > 240) { return ['blocked' => true]; }
    $found = [];
    foreach (sa_sf_intent_rules() as $rule) {
        foreach ($rule['aliases'] as $alias) {
            if (preg_match('/\b' . preg_quote($alias, '/') . '\b/', $q)) {
                $found[] = $rule + ['alias' => $alias];
                break;
            }
        }
    }
    if ($found === []) { return null; }
    if (count($found) !== 1 || preg_match('/\b(no|not|without|except|or|noise|noisy|rattl\w*|misfir\w*|overheat\w*|squeal\w*|squeak\w*|vibrat\w*|diagnos\w*|fix|repair)\b/', $q)) {
        return ['blocked' => true];
    }
    $plan = $found[0];
    $rest = (string) preg_replace('/\b' . preg_quote($plan['alias'], '/') . '\b/', ' ', $q, 1);
    // Do not remove identifiers, dimensions, side, make/model or year tokens.
    $rest = (string) preg_replace('/\b(i need|i want|looking for|please|show me|can you find|for my|for|my|a|an|the)\b/', ' ', $rest);
    $plan['constraints'] = array_values(array_filter(explode(' ', trim((string) preg_replace('/\s+/', ' ', $rest))), 'strlen'));
    $plan['blocked'] = false;
    return $plan;
}
function sa_sf_intent_title_ok(string $title, array $plan): bool
{
    $title = sa_sf_intent_normalize($title);
    if (!preg_match($plan['noun'], $title)) { return false; }
    if ($plan['category'] === 123 && preg_match('/bush|end\s?link|\blink\b|brace|bracket|mount|support/', $title)) { return false; }
    if ($plan['category'] === 32 && preg_match('/\bpads?\b|\bcalipers?\b|\bbrake kit\b/', $title)) { return false; }
    foreach ($plan['constraints'] as $token) {
        if (!preg_match('/\b' . preg_quote($token, '/') . '\b/', $title)) { return false; }
    }
    return true;
}
/** Strict title candidates from existing local index, then category intersection. */
function sa_sf_intent_product_ids(array $plan, int $limit = 24, array $tax = [], array $meta = []): array
{
    if (!empty($plan['blocked'])) { return []; }
    if (function_exists('sa_fx_ready') && !sa_fx_ready()) { return []; } // cannot verify active filters
    $ids = [];
    foreach (sa_sf_search_index() as $id => $row) {
        $title = explode('|', (string) $row, 2)[0];
        if (sa_sf_intent_title_ok($title, $plan)) { $ids[] = (int) $id; }
    }
    if ($ids === []) { return []; }
    $category = ['taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => [$plan['category']]];
    $tq = $tax === [] ? [$category] : ['relation' => 'AND', $category, $tax];
    $args = ['post_type' => 'product', 'post_status' => 'publish', 'post__in' => $ids, 'posts_per_page' => max(1, min(48, $limit)), 'fields' => 'ids', 'no_found_rows' => true, 'tax_query' => $tq];
    if ($meta !== []) { $args['meta_query'] = $meta; }
    // Do not reapply global keyword clauses to the strict candidate query.
    $args['sa_intent_candidates'] = 1;
    return array_map('intval', (new WP_Query($args))->posts);
}

// Apply the site's existing verified facet constraints to strict candidates,
// excluding ONLY the original wording being expanded. Keep PN, price, brand,
// category, vehicle, stock and specs. Never modify the facet index or scoring.
add_filter('posts_clauses', static function (array $clauses, $query): array {
    if (!($query instanceof WP_Query) || !$query->get('sa_intent_candidates')) { return $clauses; }
    if (function_exists('sa_fx_state') && function_exists('sa_fx_base_sql') && sa_fx_ready()) {
        global $wpdb;
        $state = sa_fx_state();
        $state['s'] = '';
        $clauses['where'] .= " AND {$wpdb->posts}.ID IN (" . sa_fx_base_sql($state) . ') ';
    }
    return $clauses;
}, 45, 2);
