<?php
/**
 * Parts-store faceted filters (vehicle, brand, category, specs, price, stock, free shipping).
 *
 * Data sources (real product data only):
 * - Vehicle: product_tag `fits_<years>`Make`Model`Submodel~...` (Turn14 fitment table).
 *   Title fallback only for makes/models that exist in that fitment data.
 * - Brand: product_cat children of `brands`.
 * - Category: product_cat tree (ancestors included).
 * - Specs: parsed from titles (material, position, pipe diameter, wheel size, lift, finish).
 * - Price / stock: wc_product_meta_lookup at query time.
 * - Free shipping: price >= store free-shipping threshold (US + Kenya zones, shipping.php).
 *
 * Precomputed index table {prefix}sa_facet_index, built in batches (cron + shutdown),
 * swapped atomically; counts cached per filter-set.
 *
 * @package Supreme_Autoparts_Core
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const SA_FX_VER = '3';
const SA_FX_BATCH = 600;

function sa_fx_table(bool $build = false): string
{
    global $wpdb;
    return $wpdb->prefix . ($build ? 'sa_facet_index_new' : 'sa_facet_index');
}

function sa_fx_create_table(string $table): void
{
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query("CREATE TABLE IF NOT EXISTS {$table} (
        product_id BIGINT UNSIGNED NOT NULL,
        facet VARCHAR(24) NOT NULL,
        value VARCHAR(120) NOT NULL,
        label VARCHAR(160) NOT NULL DEFAULT '',
        PRIMARY KEY (facet, value, product_id),
        KEY product_id (product_id)
    ) {$charset}");
}

function sa_fx_ready(): bool
{
    static $ok = null;
    if ($ok === null) {
        global $wpdb;
        $t = sa_fx_table();
        $ok = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t)
            && (string) get_option('sa_fx_built_ver', '') !== '';
    }
    return $ok;
}

function sa_fx_free_ship_min(): float
{
    if (function_exists('sa_core_shipping_free_min')) {
        return (float) sa_core_shipping_free_min();
    }
    $raw = getenv('SUPREME_FREE_SHIPPING_THRESHOLD');
    $v = (float) (($raw === false || $raw === '') ? get_option('sa_free_shipping_threshold', '99') : $raw);
    return ($v <= 0 || $v > 1000) ? 99.0 : $v;
}

/* ------------------------------------------------------------------ */
/* Parsing                                                             */
/* ------------------------------------------------------------------ */

function sa_fx_make_label(string $make): string
{
    $m = trim($make);
    $map = ['chevy' => 'Chevrolet', 'vw' => 'Volkswagen', 'mercedes' => 'Mercedes-Benz', 'mercedes benz' => 'Mercedes-Benz'];
    return $map[strtolower($m)] ?? $m;
}

/**
 * @return list<array{make:string,model:string,years:list<int>}>
 */
function sa_fx_parse_fits_tag(string $tag): array
{
    $tag = html_entity_decode($tag, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (strpos($tag, 'fits_') !== 0) {
        return [];
    }
    $out = [];
    foreach (explode('~', substr($tag, 5)) as $entry) {
        $p = explode('`', $entry);
        if (count($p) < 3) {
            continue;
        }
        $make = sa_fx_make_label(trim($p[1]));
        $model = trim($p[2]);
        if ($make === '' || $model === '') {
            continue;
        }
        $years = [];
        if (preg_match('/^((?:19|20)\d{2})(?:-((?:19|20)\d{2}))?$/', trim($p[0]), $m)) {
            $a = (int) $m[1];
            $b = isset($m[2]) ? (int) $m[2] : $a;
            if ($b >= $a && $b - $a <= 60) {
                $years = range($a, $b);
            }
        }
        $out[] = ['make' => $make, 'model' => $model, 'years' => $years];
    }
    return $out;
}

/** Years from a title: 2021-2024, 21-24, 2019+, 2020. */
function sa_fx_title_years(string $title): array
{
    $now = (int) gmdate('Y') + 1;
    if (preg_match('/\b((?:19|20)\d{2})\s*[-–]\s*((?:19|20)\d{2})\b/', $title, $m)) {
        $a = (int) $m[1]; $b = (int) $m[2];
    } elseif (preg_match('/\b(\d{2})\s*[-–]\s*(\d{2})\b/', $title, $m)) {
        $a = (int) $m[1]; $b = (int) $m[2];
        $a += $a > 40 ? 1900 : 2000; $b += $b > 40 ? 1900 : 2000;
    } elseif (preg_match('/\b((?:19|20)\d{2})\s*\+/', $title, $m)) {
        $a = (int) $m[1]; $b = $now;
    } elseif (preg_match('/\b((?:19[5-9]|20[0-3])\d)\b/', $title, $m)) {
        $a = $b = (int) $m[1];
    } else {
        return [];
    }
    return ($b >= $a && $b - $a <= 40) ? range($a, $b) : [];
}

/**
 * Known make => [model, ...] from fitment tags (the only allowed vocabulary).
 *
 * @return array<string, list<string>>
 */
function sa_fx_vehicle_vocab(bool $refresh = false): array
{
    $v = get_option('sa_fx_vocab');
    if (!$refresh && is_array($v)) {
        return $v;
    }
    global $wpdb;
    $names = $wpdb->get_col(
        "SELECT t.name FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
         WHERE tt.taxonomy = 'product_tag' AND t.name LIKE 'fits\\_%'"
    );
    $vocab = [];
    foreach ((array) $names as $n) {
        foreach (sa_fx_parse_fits_tag((string) $n) as $e) {
            $vocab[$e['make']][$e['model']] = true;
        }
    }
    $out = [];
    foreach ($vocab as $make => $models) {
        $list = array_keys($models);
        usort($list, static fn ($a, $b) => strlen((string) $b) <=> strlen((string) $a));
        $out[$make] = array_map('strval', $list);
    }
    update_option('sa_fx_vocab', $out, false);
    return $out;
}

/**
 * Vehicles for a product: fitment tags first, else title against known vocab.
 *
 * @return list<array{make:string,model:string,years:list<int>}>
 */
function sa_fx_vehicles_for(string $title, array $tag_names, array $vocab): array
{
    $out = [];
    foreach ($tag_names as $t) {
        foreach (sa_fx_parse_fits_tag((string) $t) as $e) {
            $out[] = $e;
        }
    }
    if ($out !== []) {
        return $out;
    }
    $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    foreach ($vocab as $make => $models) {
        $aliases = [$make];
        if ($make === 'Chevrolet') { $aliases[] = 'Chevy'; }
        if ($make === 'Volkswagen') { $aliases[] = 'VW'; }
        if ($make === 'Mercedes-Benz') { $aliases[] = 'Mercedes'; }
        $hit = false;
        foreach ($aliases as $al) {
            if (preg_match('/\b' . preg_quote($al, '/') . '\b/i', $title)) { $hit = true; break; }
        }
        if (!$hit) {
            continue;
        }
        foreach ($models as $model) {
            if (strlen($model) < 2) { continue; }
            $pat = '/(?<![A-Za-z0-9])' . str_replace('\-', '[- ]?', preg_quote($model, '/')) . '(?![A-Za-z0-9])/i';
            if (preg_match($pat, $title)) {
                $out[] = ['make' => $make, 'model' => $model, 'years' => sa_fx_title_years($title)];
                break;
            }
        }
        if ($out === []) {
            $out[] = ['make' => $make, 'model' => '', 'years' => []];
        }
        break;
    }
    return $out;
}

/**
 * Title spec facets. Only emitted where the title states the value.
 *
 * @param list<string> $cat_slugs category slugs incl. ancestors
 * @return list<array{0:string,1:string,2:string}> [facet, value, label]
 */
function sa_fx_specs_for(string $title, array $cat_slugs): array
{
    $t = ' ' . strtolower(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8')) . ' ';
    $cats = ' ' . implode(' ', $cat_slugs) . ' ';
    $has = static fn (string $needle): bool => strpos($cats, $needle) !== false;
    $rows = [];

    $materials = [
        'titanium' => 'Titanium', 'carbon fiber' => 'Carbon fiber', 'carbon-fiber' => 'Carbon fiber',
        'stainless' => 'Stainless steel', 'aluminized' => 'Aluminized steel', 'aluminum' => 'Aluminum',
        'billet' => 'Billet aluminum', 'polyurethane' => 'Polyurethane', 'chromoly' => 'Chromoly',
        'ceramic' => 'Ceramic', 'semi-metallic' => 'Semi-metallic', 'rubber' => 'Rubber', 'leather' => 'Leather',
    ];
    foreach ($materials as $k => $label) {
        if (strpos($t, $k) !== false) {
            $rows[] = ['material', sanitize_title($label), $label];
            break;
        }
    }

    $front = (bool) preg_match('/\bfront\b/', $t);
    $rear = (bool) preg_match('/\brear\b/', $t);
    if ($front && $rear) {
        $rows[] = ['position', 'front-rear', 'Front & rear'];
    } elseif ($front) {
        $rows[] = ['position', 'front', 'Front'];
    } elseif ($rear) {
        $rows[] = ['position', 'rear', 'Rear'];
    }

    if (($has('exhaust') || $has('catback') || $has('axle-back') || $has('header') || $has('intake') || $has('downpipe'))
        && preg_match('/\b(\d(?:\.\d{1,2})?)\s*(?:in\b|inch|")/', $t, $m)) {
        $d = (float) $m[1];
        if ($d >= 1.5 && $d <= 6) {
            $lab = rtrim(rtrim(number_format($d, 2, '.', ''), '0'), '.') . ' in';
            $rows[] = ['diameter', sanitize_title($lab), $lab];
        }
    }

    if ($has('wheel') && preg_match('/\b(1[3-9]|2[0-6])\s*x\s*(\d{1,2}(?:\.\d)?)\b/', $t, $m)) {
        $rows[] = ['wheel_dia', $m[1], $m[1] . ' in'];
        $rows[] = ['wheel_w', sanitize_title($m[2]), $m[2] . ' in'];
    }

    if (($has('suspension') || $has('lift') || $has('leveling')) && preg_match('/\b(\d(?:\.\d{1,2})?)\s*(?:in|inch|")\s*(?:suspension\s+)?(?:lift|leveling)/', $t, $m)) {
        $lab = rtrim(rtrim($m[1], '0'), '.') . ' in';
        $rows[] = ['lift', sanitize_title($lab), $lab];
    }

    if ($has('wheel') || $has('exhaust') || $has('tips') || $has('light') || $has('grille')) {
        $finishes = ['gloss black' => 'Gloss black', 'matte black' => 'Matte black', 'satin black' => 'Satin black',
            'gunmetal' => 'Gunmetal', 'bronze' => 'Bronze', 'chrome' => 'Chrome', 'polished' => 'Polished',
            'silver' => 'Silver', 'black' => 'Black', 'white' => 'White', 'burnt' => 'Burnt titanium'];
        foreach ($finishes as $k => $label) {
            if (preg_match('/\b' . preg_quote($k, '/') . '\b/', $t)) {
                $rows[] = ['finish', sanitize_title($label), $label];
                break;
            }
        }
    }
    return $rows;
}

/** Spec facet labels and display order. */
function sa_fx_spec_facets(): array
{
    return [
        'material'  => __('Material', 'supreme-autoparts-core'),
        'diameter'  => __('Pipe diameter', 'supreme-autoparts-core'),
        'wheel_dia' => __('Wheel diameter', 'supreme-autoparts-core'),
        'wheel_w'   => __('Wheel width', 'supreme-autoparts-core'),
        'lift'      => __('Lift height', 'supreme-autoparts-core'),
        'position'  => __('Position', 'supreme-autoparts-core'),
        'finish'    => __('Finish / colour', 'supreme-autoparts-core'),
    ];
}

/* ------------------------------------------------------------------ */
/* Indexing                                                            */
/* ------------------------------------------------------------------ */

/** @return array{0:array<int,int>,1:array<int,string>,2:int} parent map, slug map, brands root id */
function sa_fx_cat_maps(): array
{
    static $maps = null;
    if ($maps !== null) {
        return $maps;
    }
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT t.term_id, t.slug, tt.parent FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = 'product_cat'"
    );
    $parent = []; $slug = []; $brands = 0;
    foreach ((array) $rows as $r) {
        $parent[(int) $r->term_id] = (int) $r->parent;
        $slug[(int) $r->term_id] = (string) $r->slug;
        if ($r->slug === 'brands') { $brands = (int) $r->term_id; }
    }
    $maps = [$parent, $slug, $brands];
    return $maps;
}

/**
 * Build index rows for a set of product ids.
 *
 * @param list<int> $ids
 * @return list<array{0:int,1:string,2:string,3:string}>
 */
function sa_fx_rows_for(array $ids, array $vocab): array
{
    global $wpdb;
    if ($ids === []) {
        return [];
    }
    $in = implode(',', array_map('intval', $ids));
    $titles = [];
    foreach ((array) $wpdb->get_results("SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ({$in})") as $r) {
        $titles[(int) $r->ID] = (string) $r->post_title;
    }
    $terms = $wpdb->get_results(
        "SELECT tr.object_id, tt.taxonomy, t.term_id, t.name, t.slug FROM {$wpdb->term_relationships} tr
         INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
         INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
         WHERE tr.object_id IN ({$in}) AND tt.taxonomy IN ('product_cat','product_tag')"
    );
    $tags = []; $cats = [];
    foreach ((array) $terms as $r) {
        $pid = (int) $r->object_id;
        if ($r->taxonomy === 'product_tag') {
            $tags[$pid][] = (string) $r->name;
        } else {
            $cats[$pid][] = (int) $r->term_id;
        }
    }
    [$parent, $slugs, $brands_root] = sa_fx_cat_maps();
    $rows = [];
    foreach ($titles as $pid => $title) {
        $seen = [];
        $add = static function (string $f, string $v, string $l) use (&$rows, &$seen, $pid): void {
            $v = substr($v, 0, 120);
            if ($v === '' || isset($seen[$f . '|' . $v])) {
                return;
            }
            $seen[$f . '|' . $v] = true;
            $rows[] = [$pid, $f, $v, substr($l, 0, 160)];
        };
        // Categories / brands.
        $cat_slugs = [];
        foreach ($cats[$pid] ?? [] as $tid) {
            $chain = [];
            $cur = $tid; $guard = 0;
            while ($cur > 0 && $guard++ < 10) { $chain[] = $cur; $cur = $parent[$cur] ?? 0; }
            $is_brand = $brands_root > 0 && in_array($brands_root, $chain, true);
            if ($is_brand) {
                if ($tid !== $brands_root) {
                    $add('brand', $slugs[$tid] ?? (string) $tid, (string) get_term_field('name', $tid, 'product_cat'));
                }
                continue;
            }
            foreach ($chain as $c) {
                $add('cat', (string) $c, '');
                $cat_slugs[] = $slugs[$c] ?? '';
            }
        }
        // Vehicles.
        foreach (sa_fx_vehicles_for($title, $tags[$pid] ?? [], $vocab) as $veh) {
            $mk = sanitize_title($veh['make']);
            $add('make', $mk, $veh['make']);
            if ($veh['model'] === '') { continue; }
            $mo = $mk . '/' . sanitize_title($veh['model']);
            $add('model', $mo, $veh['model']);
            foreach ($veh['years'] as $y) {
                $add('ymm', $mo . '/' . $y, (string) $y);
            }
        }
        // Specs.
        foreach (sa_fx_specs_for($title, array_filter($cat_slugs)) as [$f, $v, $l]) {
            $add($f, $v, $l);
        }
    }
    return $rows;
}

function sa_fx_insert_rows(string $table, array $rows): void
{
    global $wpdb;
    foreach (array_chunk($rows, 400) as $chunk) {
        $ph = []; $vals = [];
        foreach ($chunk as [$pid, $f, $v, $l]) {
            $ph[] = '(%d,%s,%s,%s)';
            array_push($vals, $pid, $f, $v, $l);
        }
        // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$table} (product_id, facet, value, label) VALUES " . implode(',', $ph), $vals));
    }
}

/** Process one rebuild batch; returns true when finished. */
function sa_fx_rebuild_step(int $batch = SA_FX_BATCH): bool
{
    global $wpdb;
    $state = get_option('sa_fx_build');
    if (!is_array($state) || ($state['ver'] ?? '') !== SA_FX_VER) {
        $new = sa_fx_table(true);
        $wpdb->query("DROP TABLE IF EXISTS {$new}");
        sa_fx_create_table($new);
        sa_fx_create_table(sa_fx_table());
        sa_fx_vehicle_vocab(true);
        $state = ['ver' => SA_FX_VER, 'cursor' => 0, 'started' => time()];
        update_option('sa_fx_build', $state, false);
    }
    $vocab = sa_fx_vehicle_vocab();
    $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' AND ID > %d ORDER BY ID ASC LIMIT %d",
        (int) $state['cursor'],
        $batch
    )));
    if ($ids === []) {
        $live = sa_fx_table(); $new = sa_fx_table(true); $old = $wpdb->prefix . 'sa_facet_index_old';
        $wpdb->query("DROP TABLE IF EXISTS {$old}");
        $wpdb->query("RENAME TABLE {$live} TO {$old}, {$new} TO {$live}");
        $wpdb->query("DROP TABLE IF EXISTS {$old}");
        update_option('sa_fx_built_ver', SA_FX_VER, false);
        update_option('sa_fx_built_at', time(), false);
        update_option('sa_fx_build', ['ver' => SA_FX_VER, 'done' => 1, 'cursor' => PHP_INT_MAX], false);
        sa_fx_bump();
        return true;
    }
    sa_fx_insert_rows(sa_fx_table(true), sa_fx_rows_for($ids, $vocab));
    $state['cursor'] = max($ids);
    update_option('sa_fx_build', $state, false);
    return false;
}

function sa_fx_needs_build(): bool
{
    $s = get_option('sa_fx_build');
    return !is_array($s) || ($s['ver'] ?? '') !== SA_FX_VER || empty($s['done']);
}

function sa_fx_bump(): void
{
    update_option('sa_fx_gen', (string) time(), false);
}

/** Reindex single product in the live table. */
function sa_fx_reindex_product(int $id): void
{
    if ($id <= 0 || !sa_fx_ready()) {
        return;
    }
    global $wpdb;
    $t = sa_fx_table();
    $wpdb->delete($t, ['product_id' => $id], ['%d']);
    if (get_post_status($id) === 'publish' && get_post_type($id) === 'product') {
        sa_fx_insert_rows($t, sa_fx_rows_for([$id], sa_fx_vehicle_vocab()));
    }
    // Throttle cache invalidation (live import touches many products).
    $gen = (int) get_option('sa_fx_gen', '0');
    if (time() - $gen > 600) {
        sa_fx_bump();
    }
}

add_action('woocommerce_update_product', static fn ($id) => sa_fx_reindex_product((int) $id), 60);
add_action('woocommerce_new_product', static fn ($id) => sa_fx_reindex_product((int) $id), 60);
add_action('before_delete_post', static fn ($id) => sa_fx_reindex_product((int) $id));

add_filter('cron_schedules', static function (array $s): array {
    $s['sa_fx_minute'] = ['interval' => 60, 'display' => 'Every minute (facet index)'];
    return $s;
});
add_action('init', static function (): void {
    if (!wp_next_scheduled('sa_fx_tick')) {
        wp_schedule_event(time() + 30, 'sa_fx_minute', 'sa_fx_tick');
    }
    // Weekly full rebuild picks up new tags/categories.
    $at = (int) get_option('sa_fx_built_at', 0);
    if ($at > 0 && time() - $at > WEEK_IN_SECONDS && !sa_fx_needs_build()) {
        update_option('sa_fx_build', ['ver' => 'stale'], false);
    }
});
add_action('sa_fx_tick', static function (): void {
    if (!sa_fx_needs_build() || get_transient('sa_fx_lock')) {
        return;
    }
    set_transient('sa_fx_lock', 1, 120);
    $start = microtime(true);
    while (microtime(true) - $start < 25) {
        if (sa_fx_rebuild_step()) {
            break;
        }
    }
    delete_transient('sa_fx_lock');
});

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('sa facets-rebuild', static function (): void {
        update_option('sa_fx_build', ['ver' => 'force'], false);
        $n = 0;
        while (!sa_fx_rebuild_step(1000)) {
            $n++;
            WP_CLI::log('batch ' . $n);
        }
        WP_CLI::success('Facet index rebuilt.');
    });
}

/* ------------------------------------------------------------------ */
/* Request state                                                       */
/* ------------------------------------------------------------------ */

/**
 * @return array{make:string,model:string,year:string,brand:list<string>,specs:array<string,list<string>>,instock:bool,freeship:bool,min:string,max:string,cat:int,s:string,pn:string}
 */
function sa_fx_state(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $g = static function (string $k): string {
        return isset($_GET[$k]) ? trim(sanitize_text_field(wp_unslash((string) $_GET[$k]))) : ''; // phpcs:ignore
    };
    $list = static function (string $raw): array {
        $out = [];
        foreach (explode(',', $raw) as $p) {
            $p = sanitize_title($p);
            if ($p !== '') { $out[] = $p; }
        }
        return array_values(array_unique(array_slice($out, 0, 20)));
    };
    $specs = [];
    foreach (array_keys(sa_fx_spec_facets()) as $f) {
        $v = $list($g('f_' . $f));
        if ($v !== []) { $specs[$f] = $v; }
    }
    $cat = 0;
    if (function_exists('is_product_category') && did_action('wp') && is_product_category()) {
        $o = get_queried_object();
        $cat = $o instanceof WP_Term ? (int) $o->term_id : 0;
    }
    $num = static fn (string $v): string => ($v !== '' && is_numeric($v)) ? (string) (float) $v : '';
    $make = sanitize_title($g('sa_make'));
    $model = sanitize_title($g('sa_model'));
    $year = preg_match('/^(19|20)\d{2}$/', $g('sa_year')) ? $g('sa_year') : '';
    $st = [
        'make'     => $make,
        'model'    => $make !== '' ? $model : '',
        'year'     => ($make !== '' && $model !== '') ? $year : '',
        'brand'    => $list($g('filter_brand')),
        'specs'    => $specs,
        'instock'  => $g('instock') === '1',
        'freeship' => $g('freeship') === '1',
        'min'      => $num($g('min_price')),
        'max'      => $num($g('max_price')),
        'cat'      => $cat,
        's'        => function_exists('sa_core_sanitize_filter_text') ? sa_core_sanitize_filter_text($g('s')) : $g('s'),
        'pn'       => function_exists('sa_core_sanitize_filter_text') ? sa_core_sanitize_filter_text($g('sa_pn')) : $g('sa_pn'),
    ];
    if (did_action('wp')) {
        $cached = $st;
    }
    return $st;
}

/**
 * SQL WHERE fragments (on alias p = posts, l = lookup) for current filters.
 *
 * @param string $skip facet group to omit (for its own counts)
 * @return list<string>
 */
function sa_fx_where(array $st, string $skip = '', bool $include_base = true): array
{
    global $wpdb;
    $t = sa_fx_table();
    $w = [];
    $in = static function (string $facet, array $values) use ($wpdb, $t): string {
        $ph = implode(',', array_fill(0, count($values), '%s'));
        return $wpdb->prepare("p.ID IN (SELECT product_id FROM {$t} WHERE facet = %s AND value IN ({$ph}))", array_merge([$facet], $values));
    };
    if ($include_base) {
        if ($st['cat'] > 0 && $skip !== 'cat') {
            $w[] = $in('cat', [(string) $st['cat']]);
        }
        foreach (['s' => $st['s'], 'pn' => $st['pn']] as $k => $needle) {
            if ($needle === '') { continue; }
            $like = '%' . $wpdb->esc_like($needle) . '%';
            $w[] = $k === 'pn'
                ? $wpdb->prepare('(l.sku LIKE %s)', $like)
                : $wpdb->prepare('(l.sku LIKE %s OR p.post_title LIKE %s)', $like, $like);
        }
        if ($skip !== 'price') {
            if ($st['min'] !== '') { $w[] = $wpdb->prepare('l.max_price >= %f', (float) $st['min']); }
            if ($st['max'] !== '') { $w[] = $wpdb->prepare('l.min_price <= %f', (float) $st['max']); }
        }
    }
    if ($st['year'] !== '' && $skip !== 'vehicle' && $skip !== 'year') {
        $w[] = $in('ymm', [$st['make'] . '/' . $st['model'] . '/' . $st['year']]);
    } elseif ($st['model'] !== '' && $skip !== 'vehicle' && $skip !== 'model') {
        $w[] = $in('model', [$st['make'] . '/' . $st['model']]);
    } elseif ($st['make'] !== '' && $skip !== 'vehicle' && $skip !== 'make') {
        $w[] = $in('make', [$st['make']]);
    }
    if ($skip === 'model' && $st['make'] !== '') { $w[] = $in('make', [$st['make']]); }
    if ($skip === 'year' && $st['model'] !== '') { $w[] = $in('model', [$st['make'] . '/' . $st['model']]); }
    if ($st['brand'] !== [] && $skip !== 'brand') {
        $w[] = $in('brand', $st['brand']);
    }
    foreach ($st['specs'] as $f => $vals) {
        if ($skip !== $f) { $w[] = $in($f, $vals); }
    }
    if ($st['instock'] && $skip !== 'instock') {
        $w[] = "l.stock_status IN ('instock','onbackorder')";
    }
    if ($st['freeship'] && $skip !== 'freeship') {
        $w[] = $wpdb->prepare('l.min_price >= %f', sa_fx_free_ship_min());
    }
    return $w;
}

function sa_fx_base_sql(array $st, string $skip = ''): string
{
    global $wpdb;
    $w = sa_fx_where($st, $skip);
    $vis = '';
    $hidden = get_term_by('slug', 'exclude-from-catalog', 'product_visibility');
    if ($hidden instanceof WP_Term) {
        $vis = ' AND p.ID NOT IN (SELECT object_id FROM ' . $wpdb->term_relationships . ' WHERE term_taxonomy_id = ' . (int) $hidden->term_taxonomy_id . ')';
    }
    return "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->prefix}wc_product_meta_lookup l ON l.product_id = p.ID
            WHERE p.post_type = 'product' AND p.post_status = 'publish'{$vis}" . ($w ? ' AND ' . implode(' AND ', $w) : '');
}

function sa_fx_cache(string $key, callable $fn)
{
    $k = 'sa_fx_' . md5(SA_FX_VER . '|' . get_option('sa_fx_gen', '0') . '|' . $key);
    $v = get_transient($k);
    if ($v !== false) {
        return $v;
    }
    $v = $fn();
    set_transient($k, $v, 30 * MINUTE_IN_SECONDS);
    return $v;
}

/**
 * Option counts for a facet under the other active filters.
 *
 * @return list<array{value:string,label:string,count:int}>
 */
function sa_fx_counts(string $facet, string $skip = '', int $limit = 300, string $value_prefix = ''): array
{
    if (!sa_fx_ready()) {
        return [];
    }
    $st = sa_fx_state();
    $skip = $skip !== '' ? $skip : $facet;
    return sa_fx_cache('c|' . $facet . '|' . $skip . '|' . $value_prefix . '|' . wp_json_encode($st), static function () use ($facet, $skip, $st, $limit, $value_prefix): array {
        global $wpdb;
        $t = sa_fx_table();
        $base = sa_fx_base_sql($st, $skip);
        $pre = $value_prefix !== '' ? $wpdb->prepare(' AND i.value LIKE %s', $wpdb->esc_like($value_prefix) . '%') : '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT i.value, MAX(i.label) label, COUNT(*) c FROM {$t} i INNER JOIN ({$base}) b ON b.ID = i.product_id
             WHERE i.facet = %s{$pre} GROUP BY i.value ORDER BY c DESC LIMIT %d",
            $facet,
            $limit
        ));
        $out = [];
        foreach ((array) $rows as $r) {
            $out[] = ['value' => (string) $r->value, 'label' => (string) $r->label, 'count' => (int) $r->c];
        }
        return $out;
    });
}

/** @return array{min:float,max:float,total:int,freeship:int,instock:int} */
function sa_fx_stats(): array
{
    if (!sa_fx_ready()) {
        return ['min' => 0, 'max' => 0, 'total' => 0, 'freeship' => 0, 'instock' => 0];
    }
    $st = sa_fx_state();
    return sa_fx_cache('stats|' . wp_json_encode($st), static function () use ($st): array {
        global $wpdb;
        $r = $wpdb->get_row(
            'SELECT MIN(l.min_price) mn, MAX(l.max_price) mx, COUNT(*) c FROM (' . sa_fx_base_sql($st, 'price') . ') b
             LEFT JOIN ' . $wpdb->prefix . 'wc_product_meta_lookup l ON l.product_id = b.ID WHERE l.min_price > 0'
        );
        $fs = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM (' . sa_fx_base_sql($st, 'freeship') . ') b INNER JOIN ' . $wpdb->prefix . 'wc_product_meta_lookup l ON l.product_id = b.ID WHERE l.min_price >= %f',
            sa_fx_free_ship_min()
        ));
        $is = (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM (' . sa_fx_base_sql($st, 'instock') . ") b INNER JOIN {$wpdb->prefix}wc_product_meta_lookup l ON l.product_id = b.ID WHERE l.stock_status IN ('instock','onbackorder')"
        );
        return ['min' => (float) ($r->mn ?? 0), 'max' => (float) ($r->mx ?? 0), 'total' => (int) ($r->c ?? 0), 'freeship' => $fs, 'instock' => $is];
    });
}

/* ------------------------------------------------------------------ */
/* Main query                                                          */
/* ------------------------------------------------------------------ */

add_action('pre_get_posts', static function ($q): void {
    if (!function_exists('sa_core_is_catalog_product_query') || !sa_core_is_catalog_product_query($q)) {
        return;
    }
    $q->set('sa_fx', 1);
}, 25);

add_filter('posts_clauses', static function (array $c, $q): array {
    if (!($q instanceof WP_Query) || !(int) $q->get('sa_fx') || !sa_fx_ready()) {
        return $c;
    }
    global $wpdb;
    $st = sa_fx_state();
    // Price is handled by Woo's own min_price/max_price; keyword/PN by catalog-search.
    $w = sa_fx_where($st, '', false);
    if ($w === []) {
        return $c;
    }
    $sql = "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->prefix}wc_product_meta_lookup l ON l.product_id = p.ID WHERE " . implode(' AND ', $w);
    $c['where'] .= " AND {$wpdb->posts}.ID IN ({$sql}) ";
    return $c;
}, 40, 2);

/* Sorting: Relevance, price asc/desc, newest only. */
add_filter('woocommerce_catalog_orderby', static function (): array {
    return [
        'menu_order' => __('Relevance', 'supreme-autoparts-core'),
        'price'      => __('Price: low to high', 'supreme-autoparts-core'),
        'price-desc' => __('Price: high to low', 'supreme-autoparts-core'),
        'date'       => __('Newest', 'supreme-autoparts-core'),
    ];
}, 99);
add_filter('woocommerce_default_catalog_orderby', static fn (): string => 'menu_order', 99);
add_filter('woocommerce_default_catalog_orderby_options', static fn (array $o): array => array_intersect_key($o, array_flip(['menu_order', 'price', 'price-desc', 'date'])), 99);

/* ------------------------------------------------------------------ */
/* URL helpers                                                         */
/* ------------------------------------------------------------------ */

/** Query args for the current filter state (for links). */
function sa_fx_query_args(array $override = []): array
{
    $st = sa_fx_state();
    $a = [
        's'            => $st['s'],
        'post_type'    => $st['s'] !== '' ? 'product' : '',
        'sa_pn'        => $st['pn'],
        'sa_make'      => $st['make'],
        'sa_model'     => $st['model'],
        'sa_year'      => $st['year'],
        'filter_brand' => implode(',', $st['brand']),
        'instock'      => $st['instock'] ? '1' : '',
        'freeship'     => $st['freeship'] ? '1' : '',
        'min_price'    => $st['min'],
        'max_price'    => $st['max'],
        'orderby'      => isset($_GET['orderby']) ? sanitize_key(wp_unslash((string) $_GET['orderby'])) : '', // phpcs:ignore
    ];
    foreach (array_keys(sa_fx_spec_facets()) as $f) {
        $a['f_' . $f] = implode(',', $st['specs'][$f] ?? []);
    }
    $a = array_merge($a, $override);
    return array_filter($a, static fn ($v) => $v !== '' && $v !== null);
}

function sa_fx_base_url(): string
{
    return function_exists('sa_core_catalog_filter_form_action') ? sa_core_catalog_filter_form_action() : home_url('/shop/');
}

function sa_fx_url(array $override = [], ?string $base = null): string
{
    return add_query_arg(array_map('rawurlencode', sa_fx_query_args($override)), $base ?? sa_fx_base_url());
}

/**
 * Active chips with remove URLs.
 *
 * @return list<array{label:string,url:string}>
 */
function sa_fx_chips(): array
{
    $st = sa_fx_state();
    $chips = [];
    $label_of = static function (string $facet, string $value): string {
        global $wpdb;
        if (!sa_fx_ready()) { return $value; }
        $l = $wpdb->get_var($wpdb->prepare('SELECT label FROM ' . sa_fx_table() . ' WHERE facet = %s AND value = %s LIMIT 1', $facet, $value));
        return $l ? (string) $l : $value;
    };
    if ($st['pn'] !== '') {
        $chips[] = ['label' => sprintf(__('Part #: %s', 'supreme-autoparts-core'), $st['pn']), 'url' => sa_fx_url(['sa_pn' => ''])];
    }
    if ($st['s'] !== '') {
        $chips[] = ['label' => sprintf(__('“%s”', 'supreme-autoparts-core'), $st['s']), 'url' => sa_fx_url(['s' => '', 'post_type' => ''])];
    }
    if ($st['make'] !== '') {
        $veh = $label_of('make', $st['make']);
        if ($st['model'] !== '') { $veh .= ' ' . $label_of('model', $st['make'] . '/' . $st['model']); }
        if ($st['year'] !== '') { $veh = $st['year'] . ' ' . $veh; }
        $chips[] = ['label' => $veh, 'url' => sa_fx_url(['sa_make' => '', 'sa_model' => '', 'sa_year' => ''])];
    }
    foreach ($st['brand'] as $b) {
        $rest = array_diff($st['brand'], [$b]);
        $chips[] = ['label' => $label_of('brand', $b), 'url' => sa_fx_url(['filter_brand' => implode(',', $rest)])];
    }
    $names = sa_fx_spec_facets();
    foreach ($st['specs'] as $f => $vals) {
        foreach ($vals as $v) {
            $rest = array_diff($vals, [$v]);
            $chips[] = ['label' => $names[$f] . ': ' . $label_of($f, $v), 'url' => sa_fx_url(['f_' . $f => implode(',', $rest)])];
        }
    }
    if ($st['min'] !== '' || $st['max'] !== '') {
        $cur = function_exists('get_woocommerce_currency_symbol') ? html_entity_decode(get_woocommerce_currency_symbol()) : '$';
        $chips[] = ['label' => $cur . ($st['min'] !== '' ? $st['min'] : '0') . ' – ' . ($st['max'] !== '' ? $cur . $st['max'] : __('any', 'supreme-autoparts-core')), 'url' => sa_fx_url(['min_price' => '', 'max_price' => ''])];
    }
    if ($st['instock']) {
        $chips[] = ['label' => __('In stock', 'supreme-autoparts-core'), 'url' => sa_fx_url(['instock' => ''])];
    }
    if ($st['freeship']) {
        $chips[] = ['label' => __('Free shipping', 'supreme-autoparts-core'), 'url' => sa_fx_url(['freeship' => ''])];
    }
    return $chips;
}
