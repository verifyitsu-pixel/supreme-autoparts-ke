<?php
/**
 * Smart catalog ranking + first-party personalisation.
 *
 * 1. Precomputed, diversified default order for /shop and category archives
 *    (table sa_rank: one sequence for the whole shop, one per top-level category).
 *    Score = real photo, real price, sales, views, recency, brand popularity, stock.
 *    Sequence = greedy pick of the best remaining item whose leaf category / top
 *    category / brand was not just used (max 2 in a row per brand). Photo-less last.
 * 2. Personal boost from a first-party cookie `sa_pz` (written by sa-reco.js from
 *    localStorage): recently viewed categories, brands and the selected vehicle.
 *    Order key = pos / (1 + boost)^2. Respects DNT / GPC. No third-party trackers.
 * 3. Recommendation rows via REST (/sa/v1/recs), rendered with the theme's card.
 *
 * @package Supreme_Autoparts_Core
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

const SA_RK_VER = '3';

function sa_rk_t(string $n): string
{
    global $wpdb;
    return $wpdb->prefix . $n;
}

function sa_rk_tables(): void
{
    global $wpdb;
    if (get_option('sa_rk_schema') === SA_RK_VER) {
        return;
    }
    $cs = $wpdb->get_charset_collate();
    $wpdb->query('CREATE TABLE IF NOT EXISTS ' . sa_rk_t('sa_rank') . " (scope BIGINT UNSIGNED NOT NULL, product_id BIGINT UNSIGNED NOT NULL, pos INT UNSIGNED NOT NULL, PRIMARY KEY (scope, product_id), KEY scope_pos (scope, pos)) {$cs}");
    $wpdb->query('CREATE TABLE IF NOT EXISTS ' . sa_rk_t('sa_rank_score') . " (product_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, score FLOAT NOT NULL DEFAULT 0, leaf BIGINT UNSIGNED NOT NULL DEFAULT 0, top BIGINT UNSIGNED NOT NULL DEFAULT 0, brand VARCHAR(120) NOT NULL DEFAULT '', img TINYINT NOT NULL DEFAULT 0, priced TINYINT NOT NULL DEFAULT 0, KEY leaf (leaf)) {$cs}");
    $wpdb->query('CREATE TABLE IF NOT EXISTS ' . sa_rk_t('sa_views') . " (product_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, n INT UNSIGNED NOT NULL DEFAULT 0, updated INT UNSIGNED NOT NULL DEFAULT 0) {$cs}");
    update_option('sa_rk_schema', SA_RK_VER, true);
}

function sa_rk_ready(): bool
{
    static $ok = null;
    if ($ok === null) {
        $ok = (int) get_option('sa_rk_built_at', 0) > 0;
    }
    return $ok;
}

/* ------------------------------------------------------------------ */
/* Build                                                               */
/* ------------------------------------------------------------------ */

/** @return array{0:array<int,int>,1:array<int,string>,2:int} parent map, slug map, brands root */
function sa_rk_cat_maps(): array
{
    if (function_exists('sa_fx_cat_maps')) {
        return sa_fx_cat_maps();
    }
    return [[], [], 0];
}

function sa_rk_depth(int $tid, array $parent): int
{
    $d = 0;
    while ($tid > 0 && $d < 12) {
        $tid = $parent[$tid] ?? 0;
        $d++;
    }
    return $d;
}

function sa_rk_root(int $tid, array $parent): int
{
    $g = 0;
    while (($parent[$tid] ?? 0) > 0 && $g++ < 12) {
        $tid = $parent[$tid];
    }
    return $tid;
}

/**
 * Greedy diversified sequence.
 *
 * @param array<int,array{s:float,leaf:int,top:int,brand:string}> $items
 * @return list<int>
 */
function sa_rk_sequence(array $items, int $leaf_win, int $top_win, int $brand_win = 3): array
{
    // Diminishing returns: the n-th best item of a brand / leaf category is worth less,
    // so big brands and big categories cannot crowd the first pages.
    $by_b = []; $by_l = [];
    foreach ($items as $pid => $it) {
        $by_b[$it['brand'] !== '' ? $it['brand'] : 'pid' . $pid][] = [$it['s'], $pid];
        $by_l[$it['leaf']][] = [$it['s'], $pid];
    }
    $adj = [];
    foreach ([[$by_b, 4.0], [$by_l, 2.0]] as [$groups, $k]) {
        foreach ($groups as $g) {
            usort($g, static fn ($a, $b) => $b[0] <=> $a[0]);
            foreach ($g as $rank => [, $pid]) {
                $adj[$pid] = ($adj[$pid] ?? 0) - $k * log(1 + $rank, 2);
            }
        }
    }
    $queues = [];
    foreach ($items as $pid => $it) {
        $queues[$it['leaf']][] = [$it['s'] + ($adj[$pid] ?? 0), $pid];
    }
    $brands = [];
    foreach ($items as $it) { if ($it['brand'] !== '') { $brands[$it['brand']] = true; } }
    $brand_win = min($brand_win, max(0, count($brands) - 1));
    $heap = new SplPriorityQueue();
    $heap->setExtractFlags(SplPriorityQueue::EXTR_DATA);
    $serial = PHP_INT_MAX;
    foreach ($queues as $leaf => &$q) {
        usort($q, static fn ($a, $b) => $b[0] <=> $a[0] ?: $a[1] <=> $b[1]);
        $heap->insert($leaf, [$q[0][0], $serial--]);
    }
    unset($q);
    $heads = array_fill_keys(array_keys($queues), 0);
    $n_leaves = count($queues);
    $leaf_win = min($leaf_win, max(0, $n_leaves - 1));
    $tops = [];
    foreach ($items as $it) { $tops[$it['top']] = true; }
    $top_win = min($top_win, max(0, count($tops) - 1));
    $recent_leaf = []; $recent_top = []; $recent_b = []; $b1 = ''; $b2 = '';
    $out = [];
    while (!$heap->isEmpty()) {
        $deferred = [];
        $pick = null;
        while (!$heap->isEmpty()) {
            $leaf = $heap->extract();
            $pid = $queues[$leaf][$heads[$leaf]][1];
            $it = $items[$pid];
            $ok = !in_array($leaf, $recent_leaf, true)
                && !in_array($it['top'], $recent_top, true)
                && !($it['brand'] !== '' && $it['brand'] === $b1 && $it['brand'] === $b2)
                && !($it['brand'] !== '' && in_array($it['brand'], $recent_b, true));
            if ($ok) { $pick = $leaf; break; }
            $deferred[] = $leaf;
            if (count($deferred) > 400) { break; }
        }
        if ($pick === null) {
            // Relax: nothing eligible, take the best deferred head.
            $pick = array_shift($deferred);
        }
        foreach ($deferred as $d) {
            $heap->insert($d, [$queues[$d][$heads[$d]][0], $serial--]);
        }
        $pid = $queues[$pick][$heads[$pick]][1];
        $it = $items[$pid];
        $out[] = $pid;
        $b2 = $b1; $b1 = $it['brand'];
        if ($brand_win > 0 && $it['brand'] !== '') { $recent_b[] = $it['brand']; if (count($recent_b) > $brand_win) { array_shift($recent_b); } }
        if ($leaf_win > 0) { $recent_leaf[] = $pick; if (count($recent_leaf) > $leaf_win) { array_shift($recent_leaf); } }
        if ($top_win > 0) { $recent_top[] = $it['top']; if (count($recent_top) > $top_win) { array_shift($recent_top); } }
        $heads[$pick]++;
        if (isset($queues[$pick][$heads[$pick]])) {
            $heap->insert($pick, [$queues[$pick][$heads[$pick]][0], $serial--]);
        }
    }
    return $out;
}

/** Full rebuild (bulk SQL, ~25k products in a few seconds). */
function sa_rk_build(): array
{
    global $wpdb;
    sa_rk_tables();
    @set_time_limit(300);
    $t0 = microtime(true);
    $fx = function_exists('sa_fx_table') ? sa_fx_table() : sa_rk_t('sa_facet_index');
    [$parent, $slugs] = sa_rk_cat_maps();

    $rows = $wpdb->get_results(
        "SELECT p.ID, UNIX_TIMESTAMP(p.post_date_gmt) AS ts, l.min_price, l.total_sales, l.stock_status
         FROM {$wpdb->posts} p LEFT JOIN {$wpdb->prefix}wc_product_meta_lookup l ON l.product_id = p.ID
         WHERE p.post_type = 'product' AND p.post_status = 'publish'"
    );
    $img = array_flip(array_map('intval', (array) $wpdb->get_col(
        "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_thumbnail_id','_sa_shopify_image_src') AND meta_value <> '' AND meta_value <> '0'"
    )));
    $views = [];
    foreach ((array) $wpdb->get_results('SELECT product_id, n FROM ' . sa_rk_t('sa_views')) as $r) {
        $views[(int) $r->product_id] = (int) $r->n;
    }
    $leaf = []; $brand = [];
    foreach ((array) $wpdb->get_results("SELECT product_id, facet, value FROM {$fx} WHERE facet IN ('cat','brand')") as $r) {
        $pid = (int) $r->product_id;
        if ($r->facet === 'brand') {
            $brand[$pid] = $brand[$pid] ?? (string) $r->value;
            continue;
        }
        $tid = (int) $r->value;
        $d = sa_rk_depth($tid, $parent);
        if (!isset($leaf[$pid]) || $d > $leaf[$pid][1] || ($d === $leaf[$pid][1] && $tid < $leaf[$pid][0])) {
            $leaf[$pid] = [$tid, $d];
        }
    }
    // Brand popularity.
    $bpop = [];
    foreach ($rows as $r) {
        $pid = (int) $r->ID;
        $b = $brand[$pid] ?? '';
        if ($b !== '') {
            $bpop[$b] = ($bpop[$b] ?? 0) + (int) $r->total_sales + ($views[$pid] ?? 0) / 10;
        }
    }
    $now = time();
    $items = []; $meta = [];
    foreach ($rows as $r) {
        $pid = (int) $r->ID;
        $has_img = isset($img[$pid]);
        $priced = (float) $r->min_price > 0;
        $age = max(0, ($now - (int) $r->ts) / DAY_IN_SECONDS);
        $b = $brand[$pid] ?? '';
        $s = ($priced ? 20 : 0)
            + log1p((float) $r->total_sales) * 6
            + log1p((float) ($views[$pid] ?? 0)) * 3
            + max(0, 5 - $age / 60)
            + ($b !== '' ? log1p($bpop[$b] ?? 0) * 2 : 0)
            + ($r->stock_status === 'instock' ? 4 : 0)
            + (crc32((string) $pid) % 1000) / 1000; // stable tie-break, avoids ID clusters
        $lf = $leaf[$pid][0] ?? 0;
        $top = $lf > 0 ? sa_rk_root($lf, $parent) : 0;
        $key = $lf > 0 ? $lf : -crc32('b:' . $b); // loose products grouped by brand
        $items[$pid] = ['s' => $s, 'leaf' => $key, 'top' => $top, 'brand' => $b, 'img' => $has_img];
        $meta[$pid] = [$s + ($has_img ? 100 : 0), $lf, $top, $b, $has_img ? 1 : 0, $priced ? 1 : 0];
    }

    // Sequences: global (scope 0) + each top-level category.
    $scopes = [0 => $items];
    foreach ($items as $pid => $it) {
        if ($it['top'] > 0) { $scopes[$it['top']][$pid] = $it; }
    }
    $new = sa_rk_t('sa_rank_new');
    $wpdb->query("DROP TABLE IF EXISTS {$new}");
    $wpdb->query("CREATE TABLE {$new} LIKE " . sa_rk_t('sa_rank'));
    $stats = [];
    foreach ($scopes as $scope => $set) {
        $with = array_filter($set, static fn ($i) => $i['img']);
        $without = array_diff_key($set, $with);
        $seq = array_merge(
            sa_rk_sequence($with, $scope === 0 ? 8 : 4, $scope === 0 ? 4 : 0),
            sa_rk_sequence($without, $scope === 0 ? 8 : 4, $scope === 0 ? 4 : 0)
        );
        $stats[$scope] = count($seq);
        foreach (array_chunk($seq, 1000, true) as $chunk) {
            $v = [];
            foreach ($chunk as $i => $pid) {
                $v[] = '(' . (int) $scope . ',' . (int) $pid . ',' . ((int) $i + 1) . ')';
            }
            $wpdb->query("INSERT INTO {$new} (scope, product_id, pos) VALUES " . implode(',', $v));
        }
    }
    $old = sa_rk_t('sa_rank_old');
    $live = sa_rk_t('sa_rank');
    $wpdb->query("DROP TABLE IF EXISTS {$old}");
    $wpdb->query("RENAME TABLE {$live} TO {$old}, {$new} TO {$live}");
    $wpdb->query("DROP TABLE IF EXISTS {$old}");

    $sc = sa_rk_t('sa_rank_score');
    $wpdb->query("TRUNCATE TABLE {$sc}");
    foreach (array_chunk($meta, 800, true) as $chunk) {
        $v = [];
        foreach ($chunk as $pid => [$s, $lf, $top, $b, $im, $pr]) {
            $v[] = $wpdb->prepare('(%d,%f,%d,%d,%s,%d,%d)', $pid, $s, $lf, $top, $b, $im, $pr);
        }
        $wpdb->query("INSERT INTO {$sc} (product_id, score, leaf, top, brand, img, priced) VALUES " . implode(',', $v));
    }
    $state = ['ver' => SA_RK_VER, 'built_at' => time(), 'products' => count($items), 'scopes' => count($scopes), 'secs' => round(microtime(true) - $t0, 2)];
    update_option('sa_rk_built_at', time(), true);
    update_option('sa_rk_state', $state, false);
    sa_rk_flush_cache();
    return $state;
}

function sa_rk_flush_cache(): void
{
    update_option('sa_rk_cache_gen', (int) get_option('sa_rk_cache_gen', 0) + 1, true);
}

add_filter('cron_schedules', static function (array $s): array {
    $s['sa_rk_6h'] = ['interval' => 6 * HOUR_IN_SECONDS, 'display' => 'Every 6 hours (smart rank)'];
    return $s;
});
add_action('init', static function (): void {
    sa_rk_tables();
    if (!wp_next_scheduled('sa_rk_tick')) {
        wp_schedule_event(time() + 60, 'sa_rk_6h', 'sa_rk_tick');
    }
});
add_action('sa_rk_tick', static function (): void {
    if (get_transient('sa_rk_lock')) { return; }
    set_transient('sa_rk_lock', 1, 300);
    try { sa_rk_build(); } finally { delete_transient('sa_rk_lock'); }
});

/* ------------------------------------------------------------------ */
/* Visitor profile (first-party cookie)                                */
/* ------------------------------------------------------------------ */

function sa_rk_dnt(): bool
{
    return (isset($_SERVER['HTTP_DNT']) && (string) $_SERVER['HTTP_DNT'] === '1')
        || (isset($_SERVER['HTTP_SEC_GPC']) && (string) $_SERVER['HTTP_SEC_GPC'] === '1');
}

/** Fill an empty profile vehicle from the logged-in customer's My Garage default vehicle. */
function sa_rk_garage_vehicle(array $pz): array
{
    if ($pz['mk'] === '' && is_user_logged_in() && function_exists('sa_garage_vehicle_pz')) {
        $g = sa_garage_vehicle_pz(get_current_user_id());
        if ($g['mk'] !== '') {
            $pz['mk'] = $g['mk'];
            $pz['mo'] = $g['mo'];
            $pz['y'] = $g['y'];
        }
    }
    return $pz;
}

/** @return array{c:array<int,float>,b:array<string,float>,mk:string,mo:string,y:string,p:list<int>,q:list<string>,cart:list<int>} */
function sa_rk_profile(): array
{
    static $pz = null;
    if ($pz !== null) { return $pz; }
    $pz = ['c' => [], 'b' => [], 'mk' => '', 'mo' => '', 'y' => '', 'p' => [], 'q' => [], 'cart' => []];
    if (sa_rk_dnt() || empty($_COOKIE['sa_pz'])) { return sa_rk_garage_vehicle($pz); }
    $raw = json_decode(wp_unslash((string) $_COOKIE['sa_pz']), true); // phpcs:ignore
    if (!is_array($raw)) { return sa_rk_garage_vehicle($pz); }
    foreach (array_slice((array) ($raw['c'] ?? []), 0, 8, true) as $k => $w) {
        if ((int) $k > 0 && is_numeric($w)) { $pz['c'][(int) $k] = max(0.0, min(1.0, (float) $w)); }
    }
    foreach (array_slice((array) ($raw['b'] ?? []), 0, 5, true) as $k => $w) {
        $k = sanitize_title((string) $k);
        if ($k !== '' && is_numeric($w)) { $pz['b'][$k] = max(0.0, min(1.0, (float) $w)); }
    }
    $pz['mk'] = sanitize_title((string) ($raw['mk'] ?? ''));
    $mo = (string) ($raw['mo'] ?? '');
    if ($pz['mk'] !== '' && $mo !== '') {
        $parts = explode('/', $mo);
        $pz['mo'] = $pz['mk'] . '/' . sanitize_title((string) end($parts));
    }
    $pz['y'] = preg_match('/^(19|20)\d{2}$/', (string) ($raw['y'] ?? '')) ? (string) $raw['y'] : '';
    $pz['p'] = array_values(array_filter(array_map('intval', array_slice((array) ($raw['p'] ?? []), 0, 12))));
    $pz['cart'] = array_values(array_filter(array_map('intval', array_slice((array) ($raw['cart'] ?? []), 0, 20))));
    foreach (array_slice((array) ($raw['q'] ?? []), 0, 5) as $q) {
        $q = sanitize_text_field((string) $q);
        if ($q !== '') { $pz['q'][] = substr($q, 0, 60); }
    }
    return sa_rk_garage_vehicle($pz);
}

function sa_rk_has_profile(array $pz): bool
{
    return $pz['c'] !== [] || $pz['b'] !== [] || $pz['mk'] !== '';
}

/** SQL derived table "product_id, b" with the personal boost, or '' when none. */
function sa_rk_boost_sql(array $pz, bool $vehicle = true): string
{
    global $wpdb;
    $fx = function_exists('sa_fx_table') ? sa_fx_table() : sa_rk_t('sa_facet_index');
    $case = []; $or = [];
    foreach ($pz['c'] as $tid => $w) {
        $case[] = $wpdb->prepare("WHEN facet='cat' AND value=%s THEN %f", (string) $tid, 3 * $w);
        $or[] = $wpdb->prepare("(facet='cat' AND value=%s)", (string) $tid);
    }
    foreach ($pz['b'] as $slug => $w) {
        $case[] = $wpdb->prepare("WHEN facet='brand' AND value=%s THEN %f", $slug, 2 * $w);
        $or[] = $wpdb->prepare("(facet='brand' AND value=%s)", $slug);
    }
    if ($vehicle && $pz['mk'] !== '') {
        $case[] = $wpdb->prepare("WHEN facet='make' AND value=%s THEN 1.5", $pz['mk']);
        $or[] = $wpdb->prepare("(facet='make' AND value=%s)", $pz['mk']);
        if ($pz['mo'] !== '') {
            $case[] = $wpdb->prepare("WHEN facet='model' AND value=%s THEN 3", $pz['mo']);
            $or[] = $wpdb->prepare("(facet='model' AND value=%s)", $pz['mo']);
            if ($pz['y'] !== '') {
                $case[] = $wpdb->prepare("WHEN facet='ymm' AND value=%s THEN 1", $pz['mo'] . '/' . $pz['y']);
                $or[] = $wpdb->prepare("(facet='ymm' AND value=%s)", $pz['mo'] . '/' . $pz['y']);
            }
        }
    }
    if ($or === []) { return ''; }
    return "(SELECT product_id, LEAST(SUM(CASE " . implode(' ', $case) . " ELSE 0 END), 9) AS b FROM {$fx} WHERE " . implode(' OR ', $or) . ' GROUP BY product_id)';
}

/* ------------------------------------------------------------------ */
/* Main catalog query                                                  */
/* ------------------------------------------------------------------ */

function sa_rk_is_default_sort(WP_Query $q): bool
{
    $ob = isset($_GET['orderby']) ? sanitize_key(wp_unslash((string) $_GET['orderby'])) : ''; // phpcs:ignore
    if ($ob !== '' && $ob !== 'menu_order' && $ob !== 'relevance') { return false; }
    if ($q->is_search() || (isset($_GET['s']) && $_GET['s'] !== '')) { return false; } // phpcs:ignore — search keeps its relevance rank
    return true;
}

function sa_rk_scope_for_query(): int
{
    if (function_exists('is_product_category') && is_product_category()) {
        $o = get_queried_object();
        if ($o instanceof WP_Term) {
            [$parent, , $brands] = sa_rk_cat_maps();
            $root = sa_rk_root((int) $o->term_id, $parent);
            if ($root !== $brands) {
                return $root;
            }
        }
    }
    return 0;
}

add_filter('posts_clauses', static function (array $c, $q): array {
    if (!($q instanceof WP_Query) || !(int) $q->get('sa_fx') || !sa_rk_ready() || !sa_rk_is_default_sort($q)) {
        return $c;
    }
    global $wpdb;
    $scope = sa_rk_scope_for_query();
    $c['join'] .= ' LEFT JOIN ' . sa_rk_t('sa_rank') . " sa_rk ON (sa_rk.product_id = {$wpdb->posts}.ID AND sa_rk.scope = " . $scope . ') ';
    $key = 'COALESCE(sa_rk.pos, 9999999)';
    $boost = sa_rk_boost_sql(sa_rk_profile());
    if ($boost !== '') {
        $c['join'] .= " LEFT JOIN {$boost} sa_pb ON sa_pb.product_id = {$wpdb->posts}.ID ";
        $key = "({$key} / POW(1 + COALESCE(sa_pb.b, 0), 2))";
    }
    $c['orderby'] = "{$key} ASC, {$wpdb->posts}.ID DESC";
    return $c;
}, 60, 2);

/* ------------------------------------------------------------------ */
/* Recommendation rows                                                 */
/* ------------------------------------------------------------------ */

/** Ranked ids by global position with optional boost and constraints. */
function sa_rk_query(array $pz, array $opts): array
{
    global $wpdb;
    $fx = function_exists('sa_fx_table') ? sa_fx_table() : sa_rk_t('sa_facet_index');
    $join = ' INNER JOIN ' . sa_rk_t('sa_rank_score') . ' s ON s.product_id = r.product_id AND s.img = 1 AND s.priced = 1';
    $where = ['r.scope = 0'];
    $key = 'r.pos';
    if (!empty($opts['facet'])) {
        [$f, $vals] = $opts['facet'];
        $vals = array_values(array_filter(array_map('strval', (array) $vals)));
        if ($vals === []) { return []; }
        $ph = implode(',', array_fill(0, count($vals), '%s'));
        $where[] = $wpdb->prepare("r.product_id IN (SELECT product_id FROM {$fx} WHERE facet = %s AND value IN ({$ph}))", array_merge([$f], $vals));
    }
    $boost = !empty($opts['boost']) ? sa_rk_boost_sql($pz, !empty($opts['veh_boost'])) : '';
    if ($boost !== '') {
        $join .= " LEFT JOIN {$boost} pb ON pb.product_id = r.product_id";
        $key = '(r.pos / POW(1 + COALESCE(pb.b, 0), 2))';
    }
    $ex = array_values(array_filter(array_map('intval', (array) ($opts['exclude'] ?? []))));
    if ($ex !== []) {
        $where[] = 'r.product_id NOT IN (' . implode(',', $ex) . ')';
    }
    $lim = (int) ($opts['limit'] ?? 8);
    // phpcs:ignore WordPress.DB.PreparedSQL
    return array_map('intval', (array) $wpdb->get_col('SELECT r.product_id FROM ' . sa_rk_t('sa_rank') . " r {$join} WHERE " . implode(' AND ', $where) . " ORDER BY {$key} ASC LIMIT {$lim}"));
}

/** Category chain slugs of a product (from the facet index). */
function sa_rk_product_facets(int $pid): array
{
    global $wpdb;
    $fx = function_exists('sa_fx_table') ? sa_fx_table() : sa_rk_t('sa_facet_index');
    $out = ['cat' => [], 'brand' => [], 'make' => [], 'model' => []];
    foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT facet, value FROM {$fx} WHERE product_id = %d AND facet IN ('cat','brand','make','model')", $pid)) as $r) {
        $out[$r->facet][] = (string) $r->value;
    }
    return $out;
}

/** "Completes the job" category pairs (slug keyword → complementary slug keywords). */
function sa_rk_complements(): array
{
    return [
        'brake-pad'     => ['rotor', 'brake-hardware', 'brake-fluid', 'brake-line'],
        'brake-kit'     => ['brake-hardware', 'brake-fluid', 'brake-line'],
        'rotor'         => ['brake-pad', 'brake-hardware'],
        'caliper'       => ['brake-pad', 'brake-line', 'brake-fluid'],
        'big-brake'     => ['brake-line', 'brake-fluid'],
        'exhaust'       => ['gasket', 'clamp', 'hanger', 'exhaust-tip'],
        'axle-back'     => ['gasket', 'clamp', 'exhaust-tip'],
        'cat-back'      => ['gasket', 'clamp', 'exhaust-tip'],
        'header'        => ['gasket', 'bolt', 'stud'],
        'downpipe'      => ['gasket', 'clamp'],
        'gasket'        => ['bolt', 'stud'],
        'air-intake'    => ['air-filter', 'coupler'],
        'air-filter'    => ['air-intake-component'],
        'coilover'      => ['alignment', 'bushing', 'sway-bar', 'end-link'],
        'shock'         => ['bushing', 'alignment', 'end-link'],
        'strut'         => ['bushing', 'alignment'],
        'lift-kit'      => ['shock', 'alignment'],
        'spark-plug'    => ['ignition-coil', 'plug-wire'],
        'ignition-coil' => ['spark-plug'],
        'turbo'         => ['blow-off', 'intercooler', 'gasket'],
        'intercooler'   => ['coupler', 'clamp'],
        'clutch'        => ['flywheel', 'bearing'],
        'wheel'         => ['lug', 'tire', 'tpms'],
        'tire'          => ['wheel', 'lug'],
        'floor-mat'     => ['cargo-liner', 'seat-cover'],
        'headlight'     => ['bulb', 'wiring'],
        'light'         => ['wiring', 'bulb'],
        'radiator'      => ['hose', 'coolant', 'fan'],
        'fuel-pump'     => ['fuel-filter', 'fuel-line', 'injector'],
        'injector'      => ['fuel-pump', 'fuel-rail'],
    ];
}

/** Complement category ids for a set of category ids. */
function sa_rk_complement_cats(array $cat_ids): array
{
    [, $slugs, $brands] = sa_rk_cat_maps();
    $want = [];
    foreach ($cat_ids as $tid) {
        $slug = $slugs[(int) $tid] ?? '';
        foreach (sa_rk_complements() as $kw => $targets) {
            if ($slug !== '' && str_contains($slug, $kw)) {
                foreach ($targets as $t) { $want[$t] = true; }
            }
        }
    }
    if ($want === []) { return []; }
    $own = array_flip(array_map('intval', $cat_ids));
    $out = [];
    foreach ($slugs as $tid => $slug) {
        if ($tid === $brands || isset($own[$tid])) { continue; }
        foreach (array_keys($want) as $kw) {
            if (str_contains($slug, $kw)) { $out[] = (string) $tid; break; }
        }
    }
    return array_slice($out, 0, 40);
}

/** Co-purchased product ids from past orders. */
function sa_rk_bought_together(array $pids, int $limit = 8): array
{
    global $wpdb;
    $pids = array_values(array_filter(array_map('intval', $pids)));
    if ($pids === []) { return []; }
    $in = implode(',', $pids);
    $lk = $wpdb->prefix . 'wc_order_product_lookup';
    // phpcs:ignore WordPress.DB.PreparedSQL
    return array_map('intval', (array) $wpdb->get_col("SELECT b.product_id FROM {$lk} a INNER JOIN {$lk} b ON b.order_id = a.order_id AND b.product_id NOT IN ({$in}) INNER JOIN " . sa_rk_t('sa_rank_score') . " s ON s.product_id = b.product_id AND s.img = 1 WHERE a.product_id IN ({$in}) GROUP BY b.product_id ORDER BY COUNT(*) DESC LIMIT " . (int) $limit));
}

function sa_rk_vehicle_label(array $pz): string
{
    global $wpdb;
    if ($pz['mk'] === '') { return ''; }
    $fx = function_exists('sa_fx_table') ? sa_fx_table() : sa_rk_t('sa_facet_index');
    $mk = (string) $wpdb->get_var($wpdb->prepare("SELECT label FROM {$fx} WHERE facet='make' AND value=%s LIMIT 1", $pz['mk']));
    $mo = $pz['mo'] !== '' ? (string) $wpdb->get_var($wpdb->prepare("SELECT label FROM {$fx} WHERE facet='model' AND value=%s LIMIT 1", $pz['mo'])) : '';
    return trim(($pz['y'] !== '' && $mo !== '' ? $pz['y'] . ' ' : '') . ($mk !== '' ? $mk : ucfirst($pz['mk'])) . ' ' . $mo);
}

/**
 * Build rows for a context.
 *
 * @return list<array{key:string,title:string,ids:list<int>}>
 */
function sa_rk_rows(string $ctx, int $pid, array $pz): array
{
    $rows = [];
    $seen = array_merge([$pid], $pz['cart']);
    $add = static function (string $key, string $title, array $ids) use (&$rows, &$seen): void {
        $ids = array_values(array_diff(array_unique($ids), $seen));
        $ids = array_slice($ids, 0, 8);
        if (count($ids) >= 4) {
            $rows[] = ['key' => $key, 'title' => $title, 'ids' => $ids];
            $seen = array_merge($seen, $ids);
        }
    };
    $has = sa_rk_has_profile($pz);

    // Completes the job: product page or cart.
    $base = $ctx === 'product' && $pid > 0 ? [$pid] : ($ctx === 'cart' ? $pz['cart'] : []);
    if ($base !== []) {
        $ids = sa_rk_bought_together($base, 8);
        $cats = []; $makes = [];
        foreach (array_slice($base, 0, 6) as $b) {
            $f = sa_rk_product_facets((int) $b);
            $cats = array_merge($cats, $f['cat']);
            $makes = array_merge($makes, $f['make']);
        }
        $comp = sa_rk_complement_cats($cats);
        if ($comp !== []) {
            $vz = $pz;
            if ($vz['mk'] === '' && $makes !== []) { $vz['mk'] = $makes[0]; $vz['mo'] = ''; }
            $ids = array_merge($ids, sa_rk_query($vz, ['facet' => ['cat', $comp], 'boost' => true, 'veh_boost' => true, 'exclude' => $seen, 'limit' => 12]));
        }
        $add('fbt', __('Completes the job', 'supreme-autoparts-core'), $ids);
    }

    if ($pz['mk'] !== '') {
        $label = sa_rk_vehicle_label($pz);
        $facet = $pz['mo'] !== '' ? ['model', [$pz['mo']]] : ['make', [$pz['mk']]];
        $cz = $pz; $cz['mk'] = ''; $cz['mo'] = '';
        /* translators: %s: vehicle */
        $add('veh', sprintf(__('Fits your %s', 'supreme-autoparts-core'), $label), sa_rk_query($cz, ['facet' => $facet, 'boost' => true, 'exclude' => $seen, 'limit' => 16]));
    }

    if ($ctx !== 'product' && $pz['p'] !== []) {
        $last = (int) $pz['p'][0];
        $p = $last > 0 && function_exists('wc_get_product') ? wc_get_product($last) : null;
        if ($p && $p->is_visible()) {
            global $wpdb;
            $leaf = (int) $wpdb->get_var($wpdb->prepare('SELECT leaf FROM ' . sa_rk_t('sa_rank_score') . ' WHERE product_id = %d', $last));
            if ($leaf > 0) {
                $seen[] = $last;
                /* translators: %s: product name */
                $add('viewed', sprintf(__('Because you viewed %s', 'supreme-autoparts-core'), wp_trim_words($p->get_name(), 7, '…')), sa_rk_query($pz, ['facet' => ['cat', [(string) $leaf]], 'boost' => true, 'veh_boost' => true, 'exclude' => $seen, 'limit' => 16]));
            }
        }
    }

    if ($has && $ctx !== 'shop') {
        $add('foryou', __('Recommended for you', 'supreme-autoparts-core'), sa_rk_query($pz, ['boost' => true, 'veh_boost' => true, 'exclude' => array_merge($seen, $pz['p']), 'limit' => 16]));
    } elseif (!$has && ($ctx === 'cart' || $ctx === 'product')) {
        $add('popular', __('Popular right now', 'supreme-autoparts-core'), sa_rk_query($pz, ['exclude' => $seen, 'limit' => 12]));
    }
    return $ctx === 'shop' ? array_slice($rows, 0, 1) : array_slice($rows, 0, 3);
}

function sa_rk_render_rows(array $rows): string
{
    if ($rows === [] || !function_exists('wc_get_products')) { return ''; }
    if (!function_exists('woocommerce_template_loop_add_to_cart') && function_exists('WC')) {
        WC()->frontend_includes();
    }
    $all = [];
    foreach ($rows as $r) { $all = array_merge($all, $r['ids']); }
    _prime_post_caches(array_unique($all), true, true);
    $prods = [];
    foreach (wc_get_products(['include' => array_unique($all), 'limit' => count($all), 'status' => 'publish']) as $p) {
        $prods[$p->get_id()] = $p;
    }
    ob_start();
    foreach ($rows as $r) {
        echo '<section class="sa-reco" data-sa-reco="' . esc_attr($r['key']) . '"><div class="sa-reco__head"><h2>' . esc_html($r['title']) . '</h2></div><ul class="products columns-4 sa-reco__list">';
        foreach ($r['ids'] as $id) {
            if (empty($prods[$id])) { continue; }
            $GLOBALS['product'] = $prods[$id];
            $GLOBALS['post'] = get_post($id); // phpcs:ignore
            setup_postdata($GLOBALS['post']);
            $GLOBALS['sa_loop_product_index'] = 50; // lazy images
            wc_get_template_part('content', 'product');
        }
        echo '</ul></section>';
    }
    wp_reset_postdata();
    return (string) ob_get_clean();
}

add_action('rest_api_init', static function (): void {
    register_rest_route('sa/v1', '/recs', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => static function (WP_REST_Request $req) {
            if (!sa_rk_ready()) { return ['html' => '', 'rows' => []]; }
            $ctx = in_array($req->get_param('ctx'), ['home', 'shop', 'product', 'cart'], true) ? (string) $req->get_param('ctx') : 'home';
            $pid = (int) $req->get_param('pid');
            $pz = sa_rk_profile();
            if ($ctx === 'cart') {
                $c = array_filter(array_map('intval', explode(',', (string) $req->get_param('cart'))));
                $pz['cart'] = array_slice(array_values(array_unique(array_merge($c, $pz['cart']))), 0, 20);
            }
            // Rendered HTML carries prices + zone copy: key by the SAME zone the page uses
            // (detector country + display currency). CF-IPCountry is not set on Railway, so
            // keying on it mixed KES and USD rows across visitors.
            $country = function_exists('sa_geo_zone_key') ? sa_geo_zone_key() : (isset($_SERVER['HTTP_CF_IPCOUNTRY']) ? sanitize_key((string) $_SERVER['HTTP_CF_IPCOUNTRY']) : '');
            $ck = 'sa_rk_' . md5(wp_json_encode([$ctx, $pid, $pz, $country, (int) get_option('sa_rk_cache_gen', 0), SA_RK_VER]));
            $hit = get_transient($ck);
            if (!is_array($hit)) {
                $rows = sa_rk_rows($ctx, $pid, $pz);
                $hit = ['rows' => array_map(static fn ($r) => ['key' => $r['key'], 'title' => $r['title'], 'n' => count($r['ids']), 'ids' => $r['ids']], $rows), 'html' => sa_rk_render_rows($rows)];
                set_transient($ck, $hit, 15 * MINUTE_IN_SECONDS);
            }
            $res = new WP_REST_Response($hit);
            $res->header('Cache-Control', 'private, no-store');
            $res->header('Vary', 'Cookie, CF-IPCountry');
            return $res;
        },
    ]);
    // Anonymous view beacon: product view counter (first-party, no identifiers stored).
    register_rest_route('sa/v1', '/pz', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => static function (WP_REST_Request $req) {
            if (sa_rk_dnt()) { return ['ok' => false]; }
            $pid = (int) $req->get_param('pid');
            if ($pid > 0 && get_post_type($pid) === 'product') {
                $ip = isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? (string) $_SERVER['HTTP_CF_CONNECTING_IP'] : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
                $k = 'sa_rkv_' . md5($ip . '|' . $pid);
                if (!get_transient($k)) {
                    set_transient($k, 1, HOUR_IN_SECONDS);
                    global $wpdb;
                    $wpdb->query($wpdb->prepare('INSERT INTO ' . sa_rk_t('sa_views') . ' (product_id, n, updated) VALUES (%d, 1, %d) ON DUPLICATE KEY UPDATE n = n + 1, updated = VALUES(updated)', $pid, time()));
                }
            }
            return ['ok' => true];
        },
    ]);
    // Status + (rate-limited) rebuild trigger.
    register_rest_route('sa/v1', '/smart-rank', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => static function (WP_REST_Request $req) {
            $st = (array) get_option('sa_rk_state', []);
            if ($req->get_param('run') && (time() - (int) ($st['built_at'] ?? 0) > 10 * MINUTE_IN_SECONDS || ($st['ver'] ?? '') !== SA_RK_VER) && !get_transient('sa_rk_lock')) {
                set_transient('sa_rk_lock', 1, 300);
                try { $st = sa_rk_build(); } finally { delete_transient('sa_rk_lock'); }
            }
            return ['state' => $st, 'fx_ready' => function_exists('sa_fx_ready') ? sa_fx_ready() : null];
        },
    ]);
});

/* ------------------------------------------------------------------ */
/* Front-end: page context + script                                    */
/* ------------------------------------------------------------------ */

add_action('wp_enqueue_scripts', static function (): void {
    if (is_admin()) { return; }
    wp_enqueue_script('sa-reco', SA_CORE_URL . 'assets/js/sa-reco.js', [], SA_CORE_VERSION, true);
    $ctx = ['t' => 'other'];
    if (is_front_page()) {
        $ctx = ['t' => 'home'];
    } elseif (function_exists('is_product') && is_product()) {
        $pid = (int) get_queried_object_id();
        global $wpdb;
        $row = sa_rk_ready() ? $wpdb->get_row($wpdb->prepare('SELECT leaf, top, brand FROM ' . sa_rk_t('sa_rank_score') . ' WHERE product_id = %d', $pid)) : null;
        $f = sa_rk_product_facets($pid);
        $ctx = ['t' => 'product', 'id' => $pid, 'leaf' => (int) ($row->leaf ?? 0), 'top' => (int) ($row->top ?? 0), 'b' => (string) ($row->brand ?? ''), 'mk' => $f['make'][0] ?? '', 'mo' => $f['model'][0] ?? ''];
    } elseif (function_exists('is_cart') && is_cart()) {
        $ids = [];
        if (function_exists('WC') && WC()->cart) {
            foreach (WC()->cart->get_cart() as $it) { $ids[] = (int) ($it['product_id'] ?? 0); }
        }
        $ctx = ['t' => 'cart', 'cart' => array_values(array_filter($ids))];
    } elseif (function_exists('is_product_category') && is_product_category()) {
        $o = get_queried_object();
        [$parent, , $brands] = sa_rk_cat_maps();
        if ($o instanceof WP_Term) {
            $root = sa_rk_root((int) $o->term_id, $parent);
            $ctx = $root === $brands ? ['t' => 'shop', 'b' => (string) $o->slug] : ['t' => 'shop', 'leaf' => (int) $o->term_id, 'top' => $root];
        }
    } elseif (function_exists('is_shop') && is_shop()) {
        $ctx = ['t' => 'shop'];
    }
    if (isset($_GET['s']) && (string) $_GET['s'] !== '') { // phpcs:ignore
        $ctx['q'] = substr(sanitize_text_field(wp_unslash((string) $_GET['s'])), 0, 60); // phpcs:ignore
    }
    $ctx['paged'] = max(1, (int) get_query_var('paged'));
    $ctx['api'] = esc_url_raw(rest_url('sa/v1/'));
    wp_add_inline_script('sa-reco', 'window.saPzCtx=' . wp_json_encode($ctx) . ';', 'before');
    wp_add_inline_style('supreme-autoparts', '.sa-reco{margin:48px 0 8px}.sa-reco__head h2{font-size:1.25rem;font-weight:600;letter-spacing:-.01em;margin:0 0 16px}.sa-reco__list{position:relative;display:grid!important;grid-template-columns:repeat(4,minmax(0,1fr));gap:20px;margin:0!important;padding:0!important;list-style:none}.sa-reco__list>li{width:auto!important;margin:0!important;float:none!important}@media(max-width:900px){.sa-reco__list{grid-auto-flow:column;grid-template-columns:none;grid-auto-columns:46%;overflow-x:auto;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch}.sa-reco__list>li{scroll-snap-align:start}}');
}, 30);

/* Privacy note (appended to the Privacy Policy page). */
add_filter('the_content', static function (string $html): string {
    $pp = (int) get_option('wp_page_for_privacy_policy');
    if (!(($pp > 0 && is_page($pp)) || is_page('privacy-policy')) || !is_main_query() || str_contains($html, 'sa-pz-note')) { return $html; }
    return $html . '<h2 class="sa-pz-note">' . esc_html__('Personalised recommendations', 'supreme-autoparts-core') . '</h2><p>' . esc_html__('To show parts that suit you, our site remembers on your own device the products, categories and brands you look at, your searches, the vehicle you pick in the finder and what is in your cart. This is stored in your browser (local storage and a first-party cookie called sa_pz, kept for up to 30 days) and is only used on supremeautoparts.co.ke to order the catalogue and suggest parts. We count product views anonymously to rank popular parts. We do not use third-party trackers for this, sell this data or link it to your identity. If your browser sends Do Not Track or Global Privacy Control, personalisation is switched off. You can clear it at any time by clearing your browser data for this site.', 'supreme-autoparts-core') . '</p>';
}, 99);
