<?php
/**
 * Catalog search & filters — part number first, then brand / make / model / year.
 *
 * Live data map:
 * - Part number / OE / SKU → Woo `_sku` (+ wc_product_meta_lookup.sku)
 * - Brand → product_cat under parent slug `brands` (Shopify vendor)
 * - Make / model / year → product titles; optional meta `_sa_make|_sa_model|_sa_year`
 *
 * Shareable query args: s, sa_pn, filter_brand, sa_make, sa_model, sa_year
 *
 * @package Supreme_Autoparts_Core
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @return list<string>
 */
function sa_core_vehicle_makes(): array
{
    return [
        'Land Rover', 'Range Rover', 'Alfa Romeo', 'Aston Martin', 'Rolls Royce',
        'Mercedes-Benz', 'Mercedes', 'Volkswagen', 'Chevrolet', 'Mitsubishi',
        'Chrysler', 'Cadillac', 'Infiniti', 'Porsche', 'Maserati', 'Lamborghini',
        'Bentley', 'Ferrari', 'Hyundai', 'Genesis', 'Lincoln', 'Pontiac',
        'Oldsmobile', 'Hummer', 'Buick', 'Lexus', 'Acura', 'Scion', 'Toyota',
        'Honda', 'Nissan', 'Mazda', 'Subaru', 'Suzuki', 'Isuzu', 'Ford', 'Dodge',
        'Jeep', 'Ram', 'GMC', 'BMW', 'Audi', 'Volvo', 'Jaguar', 'Mini', 'Fiat',
        'Peugeot', 'Renault', 'Citroen', 'Opel', 'Skoda', 'Seat', 'Tesla',
        'Rivian', 'Polestar', 'Harley-Davidson', 'Harley', 'Yamaha', 'Kawasaki',
        'Ducati', 'Triumph', 'Aprilia', 'Can-Am', 'Polaris', 'KTM', 'Husqvarna',
        'VW', 'Chevy',
    ];
}

function sa_core_sanitize_filter_text(string $raw): string
{
    $raw = wp_strip_all_tags(wp_unslash($raw));
    $raw = trim(preg_replace('/\s+/', ' ', $raw) ?? '');
    $raw = preg_replace('/[^\p{L}\p{N}\s\-\+\.\/_]/u', '', $raw) ?? '';
    return trim(mb_substr($raw, 0, 80));
}

/**
 * @return array{s:string,sa_pn:string,filter_brand:string,sa_make:string,sa_model:string,sa_year:string}
 */
function sa_core_catalog_filters_from_request(): array
{
    $get = static function (string $key): string {
        if (!isset($_GET[$key])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return '';
        }
        return sa_core_sanitize_filter_text((string) wp_unslash($_GET[$key])); // phpcs:ignore
    };

    $s = $get('s');
    if ($s === '' && function_exists('is_search') && is_search()) {
        $s = sa_core_sanitize_filter_text((string) get_search_query(false));
    }

    return [
        's'            => $s,
        'sa_pn'        => $get('sa_pn'),
        'filter_brand' => sanitize_title($get('filter_brand')),
        'sa_make'      => $get('sa_make'),
        'sa_model'     => $get('sa_model'),
        'sa_year'      => $get('sa_year'),
    ];
}

function sa_core_catalog_filters_active(?array $filters = null): bool
{
    $f = $filters ?? sa_core_catalog_filters_from_request();
    foreach (['s', 'sa_pn', 'filter_brand', 'sa_make', 'sa_model', 'sa_year'] as $k) {
        if (($f[$k] ?? '') !== '') {
            return true;
        }
    }
    return false;
}

/**
 * @return list<array{slug:string,name:string,count:int}>
 */
function sa_core_brand_filter_options(): array
{
    $cached = get_transient('sa_core_brand_filter_opts_v1');
    if (is_array($cached)) {
        return $cached;
    }
    $out = [];
    if (!taxonomy_exists('product_cat')) {
        return $out;
    }
    $parent = get_term_by('slug', 'brands', 'product_cat');
    if (!$parent || is_wp_error($parent)) {
        set_transient('sa_core_brand_filter_opts_v1', [], 5 * MINUTE_IN_SECONDS);
        return $out;
    }
    $terms = get_terms([
        'taxonomy'   => 'product_cat',
        'parent'     => (int) $parent->term_id,
        'hide_empty' => true,
        'orderby'    => 'count',
        'order'      => 'DESC',
        'number'     => 200,
    ]);
    if (!is_wp_error($terms) && is_array($terms)) {
        foreach ($terms as $t) {
            $out[] = [
                'slug'  => (string) $t->slug,
                'name'  => html_entity_decode((string) $t->name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'count' => (int) $t->count,
            ];
        }
    }
    set_transient('sa_core_brand_filter_opts_v1', $out, 15 * MINUTE_IN_SECONDS);
    return $out;
}

/**
 * @return list<string>
 */
function sa_core_make_filter_options(): array
{
    $cached = get_transient('sa_core_make_filter_opts_v1');
    if (is_array($cached) && $cached !== []) {
        return $cached;
    }
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $rows = $wpdb->get_col(
        "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE pm.meta_key = '_sa_make' AND pm.meta_value <> ''
           AND p.post_type = 'product' AND p.post_status = 'publish'
         ORDER BY meta_value ASC LIMIT 80"
    );
    $meta_makes = [];
    if (is_array($rows)) {
        foreach ($rows as $m) {
            $m = trim((string) $m);
            if ($m !== '') {
                $meta_makes[] = $m;
            }
        }
    }
    if ($meta_makes !== []) {
        set_transient('sa_core_make_filter_opts_v1', $meta_makes, 30 * MINUTE_IN_SECONDS);
        return $meta_makes;
    }
    $fallback = [
        'Ford', 'Chevrolet', 'Toyota', 'Honda', 'Nissan', 'Jeep', 'Dodge', 'Ram',
        'GMC', 'BMW', 'Mercedes-Benz', 'Audi', 'Volkswagen', 'Subaru', 'Mazda',
        'Lexus', 'Acura', 'Infiniti', 'Cadillac', 'Buick', 'Chrysler', 'Hyundai',
        'Kia', 'Mitsubishi', 'Volvo', 'Porsche', 'Tesla', 'Land Rover', 'Jaguar',
        'Mini', 'Fiat', 'Suzuki', 'Yamaha', 'Kawasaki', 'Harley-Davidson', 'Ducati',
    ];
    set_transient('sa_core_make_filter_opts_v1', $fallback, 30 * MINUTE_IN_SECONDS);
    return $fallback;
}

/**
 * @return array{make:string,model:string,year:string}
 */
function sa_core_parse_vehicle_from_title(string $title): array
{
    $title = html_entity_decode(wp_strip_all_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $out = ['make' => '', 'model' => '', 'year' => ''];

    if (preg_match('/\b((?:19|20)\d{2})\s*\+/', $title, $m)) {
        $out['year'] = $m[1];
    } elseif (preg_match('/\b((?:19|20)\d{2})\s*[-–]\s*((?:19|20)\d{2})\b/', $title, $m)) {
        $out['year'] = $m[1] . '-' . $m[2];
    } elseif (preg_match('/\b(\d{2})\s*[-–]\s*(\d{2})\b/', $title, $m)) {
        $out['year'] = $m[1] . '-' . $m[2];
    } elseif (preg_match('/\b((?:19|20)\d{2})\b/', $title, $m)) {
        $out['year'] = $m[1];
    }

    foreach (sa_core_vehicle_makes() as $make) {
        if (!preg_match('/\b' . preg_quote($make, '/') . '\b/i', $title)) {
            continue;
        }
        $canon = $make;
        if (strcasecmp($make, 'Chevy') === 0) {
            $canon = 'Chevrolet';
        } elseif (strcasecmp($make, 'VW') === 0) {
            $canon = 'Volkswagen';
        } elseif (strcasecmp($make, 'Mercedes') === 0) {
            $canon = 'Mercedes-Benz';
        }
        $out['make'] = $canon;
        if (preg_match('/\b' . preg_quote($make, '/') . '\b\s+(.+)$/i', $title, $mm)) {
            $tokens = preg_split('/\s+/', $mm[1]) ?: [];
            $model_bits = [];
            $stop = ['2wd', '4wd', 'awd', 'rwd', 'fwd', 'front', 'rear', 'left', 'right', 'series', 'kit', 'pair', 'in', 'lift', 'lowered', 'vs', 'rr', 'w/', 'with'];
            foreach ($tokens as $tok) {
                $clean = trim($tok, " \t\n\r\0\x0B,;/|");
                if ($clean === '') {
                    continue;
                }
                $low = strtolower($clean);
                if (in_array($low, $stop, true)) {
                    break;
                }
                if (preg_match('/^\d+(\.\d+)?(in|mm|cm)?$/i', $clean)) {
                    break;
                }
                if (preg_match('/^\d{2}[-–]\d{2}$/', $clean) || preg_match('/^(?:19|20)\d{2}/', $clean)) {
                    continue;
                }
                $model_bits[] = $clean;
                if (count($model_bits) >= 3) {
                    break;
                }
            }
            if ($model_bits) {
                $out['model'] = implode(' ', $model_bits);
            }
        }
        break;
    }
    return $out;
}

function sa_core_maybe_stamp_vehicle_meta(int $product_id): void
{
    if ($product_id <= 0) {
        return;
    }
    $title = get_the_title($product_id);
    if ($title === '') {
        return;
    }
    $parsed = sa_core_parse_vehicle_from_title($title);
    foreach (['make' => '_sa_make', 'model' => '_sa_model', 'year' => '_sa_year'] as $key => $meta) {
        $val = trim((string) ($parsed[$key] ?? ''));
        if ($val === '') {
            continue;
        }
        if ((string) get_post_meta($product_id, $meta, true) === '') {
            update_post_meta($product_id, $meta, $val);
        }
    }
}

add_action('woocommerce_new_product', static function ($id): void {
    sa_core_maybe_stamp_vehicle_meta((int) $id);
}, 40);
add_action('woocommerce_update_product', static function ($id): void {
    sa_core_maybe_stamp_vehicle_meta((int) $id);
}, 40);

/** Small daily backfill — does not touch scrape PID. */
add_action('init', static function (): void {
    if (is_admin() || (string) get_option('sa_boot_import_running', '') === '1') {
        return;
    }
    $last = (int) get_option('sa_vehicle_meta_backfill_ts', 0);
    if ($last > 0 && (time() - $last) < DAY_IN_SECONDS) {
        return;
    }
    add_action('shutdown', static function (): void {
        if ((string) get_option('sa_boot_import_running', '') === '1') {
            return;
        }
        $ids = get_posts([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 40,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'meta_query'     => [['key' => '_sa_make', 'compare' => 'NOT EXISTS']],
            'no_found_rows'  => true,
        ]);
        foreach ($ids as $id) {
            sa_core_maybe_stamp_vehicle_meta((int) $id);
        }
        update_option('sa_vehicle_meta_backfill_ts', time(), false);
        delete_transient('sa_core_make_filter_opts_v1');
    }, 5);
}, 80);

function sa_core_is_catalog_product_query($query): bool
{
    if (!($query instanceof WP_Query) || is_admin() || !$query->is_main_query()) {
        return false;
    }
    if (!function_exists('is_shop')) {
        return false;
    }
    if ($query->is_search()) {
        $pt = $query->get('post_type');
        if ($pt === 'product' || (is_array($pt) && in_array('product', $pt, true))) {
            return true;
        }
        if (isset($_GET['post_type']) && (string) wp_unslash($_GET['post_type']) === 'product') { // phpcs:ignore
            return true;
        }
    }
    if (is_shop() || (function_exists('is_product_taxonomy') && is_product_taxonomy())) {
        return true;
    }
    return false;
}

/**
 * SKU column expression — always the Woo lookup table (joined without alias, WC-compatible).
 */
function sa_core_catalog_sku_expr(): string
{
    return 'wc_product_meta_lookup.sku';
}

/**
 * Ensure Woo lookup join uses the same alias WC orderby expects: `wc_product_meta_lookup`.
 */
function sa_core_catalog_ensure_lookup_join(string $join): string
{
    global $wpdb;
    // Already joined with WC alias or table name.
    if (preg_match('/\bwc_product_meta_lookup\b/i', $join)) {
        return $join;
    }
    $table = $wpdb->prefix . 'wc_product_meta_lookup';
    static $ok = null;
    if ($ok === null) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $ok = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table);
    }
    if (!$ok) {
        if (stripos($join, 'sa_sku_pm') === false) {
            $join .= " LEFT JOIN {$wpdb->postmeta} AS sa_sku_pm ON ({$wpdb->posts}.ID = sa_sku_pm.post_id AND sa_sku_pm.meta_key = '_sku') ";
        }
        return $join;
    }
    // Match WooCommerce\WC_Query join shape exactly.
    $join .= " LEFT JOIN {$table} wc_product_meta_lookup ON {$wpdb->posts}.ID = wc_product_meta_lookup.product_id ";
    return $join;
}

function sa_core_catalog_sku_expr_for_join(string $join): string
{
    if (preg_match('/\bwc_product_meta_lookup\b/i', $join)) {
        return 'wc_product_meta_lookup.sku';
    }
    return 'sa_sku_pm.meta_value';
}

add_action('pre_get_posts', static function ($query): void {
    if (!sa_core_is_catalog_product_query($query)) {
        return;
    }
    $f = sa_core_catalog_filters_from_request();

    if ($query->is_search()) {
        $query->set('post_type', 'product');
    }

    // Enable clause filters when searching or any catalog filter is set.
    // Note: /shop/?s=keyword is NOT is_search() — still apply SKU/title match.
    $need_clauses = $query->is_search()
        || $f['s'] !== ''
        || $f['sa_pn'] !== ''
        || $f['filter_brand'] !== ''
        || $f['sa_make'] !== ''
        || $f['sa_model'] !== ''
        || $f['sa_year'] !== '';
    if (!$need_clauses) {
        return;
    }

    if ($f['filter_brand'] !== '') {
        $tax_query = $query->get('tax_query');
        if (!is_array($tax_query)) {
            $tax_query = [];
        }
        $tax_query[] = [
            'taxonomy'         => 'product_cat',
            'field'            => 'slug',
            'terms'            => [$f['filter_brand']],
            'operator'         => 'IN',
            'include_children' => true,
        ];
        if (!isset($tax_query['relation'])) {
            $tax_query['relation'] = 'AND';
        }
        $query->set('tax_query', $tax_query);
    }

    $query->set('sa_core_catalog_search', 1);
    $query->set('sa_core_catalog_filters', $f);
}, 20);

add_filter('posts_join', static function (string $join, $query): string {
    if (!($query instanceof WP_Query) || !(int) $query->get('sa_core_catalog_search')) {
        return $join;
    }
    return sa_core_catalog_ensure_lookup_join($join);
}, 20, 2);

/**
 * Make WP search also match SKU (exact / prefix / contains).
 * Note: posts_where runs BEFORE posts_join in WP_Query — use posts_clauses for SKU filters.
 */
add_filter('posts_clauses', static function (array $clauses, $query): array {
    if (!($query instanceof WP_Query) || !(int) $query->get('sa_core_catalog_search')) {
        return $clauses;
    }
    global $wpdb;

    $clauses['join'] = sa_core_catalog_ensure_lookup_join((string) ($clauses['join'] ?? ''));
    $sku = sa_core_catalog_sku_expr_for_join($clauses['join']);

    $f = $query->get('sa_core_catalog_filters');
    if (!is_array($f)) {
        $f = sa_core_catalog_filters_from_request();
    }

    // Keyword on shop/category (not native WP search) OR reinforce search SKU match.
    $s = trim((string) ($f['s'] ?? ''));
    if ($s !== '' && !$query->is_search()) {
        $clauses['where'] .= $wpdb->prepare(
            " AND (
                {$sku} = %s OR {$sku} LIKE %s OR {$sku} LIKE %s
                OR {$wpdb->posts}.post_title LIKE %s
                OR {$wpdb->posts}.post_content LIKE %s
              ) ",
            $s,
            $wpdb->esc_like($s) . '%',
            '%' . $wpdb->esc_like($s) . '%',
            '%' . $wpdb->esc_like($s) . '%',
            '%' . $wpdb->esc_like($s) . '%'
        );
    }

    if (($f['sa_pn'] ?? '') !== '') {
        $pn = (string) $f['sa_pn'];
        $clauses['where'] .= $wpdb->prepare(
            " AND ({$sku} = %s OR {$sku} LIKE %s OR {$sku} LIKE %s) ",
            $pn,
            $wpdb->esc_like($pn) . '%',
            '%' . $wpdb->esc_like($pn) . '%'
        );
    }

    if (($f['sa_make'] ?? '') !== '') {
        $make = (string) $f['sa_make'];
        $like = '%' . $wpdb->esc_like($make) . '%';
        $clauses['where'] .= $wpdb->prepare(
            " AND (
                EXISTS (
                  SELECT 1 FROM {$wpdb->postmeta} sam
                  WHERE sam.post_id = {$wpdb->posts}.ID AND sam.meta_key = '_sa_make' AND sam.meta_value = %s
                ) OR {$wpdb->posts}.post_title LIKE %s
              ) ",
            $make,
            $like
        );
    }

    if (($f['sa_model'] ?? '') !== '') {
        $model = (string) $f['sa_model'];
        $like = '%' . $wpdb->esc_like($model) . '%';
        $compact = str_replace(['-', ' ', '/'], '', $model);
        if ($compact !== '' && strcasecmp($compact, $model) !== 0) {
            $like2 = '%' . $wpdb->esc_like($compact) . '%';
            $clauses['where'] .= $wpdb->prepare(
                " AND (
                    EXISTS (
                      SELECT 1 FROM {$wpdb->postmeta} samo
                      WHERE samo.post_id = {$wpdb->posts}.ID AND samo.meta_key = '_sa_model'
                        AND (samo.meta_value LIKE %s OR samo.meta_value LIKE %s)
                    )
                    OR {$wpdb->posts}.post_title LIKE %s
                    OR REPLACE(REPLACE({$wpdb->posts}.post_title,'-',''),' ','') LIKE %s
                  ) ",
                $like,
                $like2,
                $like,
                $like2
            );
        } else {
            $clauses['where'] .= $wpdb->prepare(
                " AND (
                    EXISTS (
                      SELECT 1 FROM {$wpdb->postmeta} samo
                      WHERE samo.post_id = {$wpdb->posts}.ID AND samo.meta_key = '_sa_model' AND samo.meta_value LIKE %s
                    ) OR {$wpdb->posts}.post_title LIKE %s
                  ) ",
                $like,
                $like
            );
        }
    }

    if (($f['sa_year'] ?? '') !== '') {
        $year = (string) $f['sa_year'];
        $like = '%' . $wpdb->esc_like($year) . '%';
        $yy = (preg_match('/^(?:19|20)(\d{2})$/', $year, $ym)) ? $ym[1] : '';
        if ($yy !== '') {
            $clauses['where'] .= $wpdb->prepare(
                " AND (
                    EXISTS (
                      SELECT 1 FROM {$wpdb->postmeta} say
                      WHERE say.post_id = {$wpdb->posts}.ID AND say.meta_key = '_sa_year' AND say.meta_value LIKE %s
                    )
                    OR {$wpdb->posts}.post_title LIKE %s
                    OR {$wpdb->posts}.post_title LIKE %s
                    OR {$wpdb->posts}.post_title LIKE %s
                  ) ",
                $like,
                $like,
                '%' . $wpdb->esc_like($yy) . '-%',
                '%-' . $wpdb->esc_like($yy) . '%'
            );
        } else {
            $clauses['where'] .= $wpdb->prepare(
                " AND (
                    EXISTS (
                      SELECT 1 FROM {$wpdb->postmeta} say
                      WHERE say.post_id = {$wpdb->posts}.ID AND say.meta_key = '_sa_year' AND say.meta_value LIKE %s
                    ) OR {$wpdb->posts}.post_title LIKE %s
                  ) ",
                $like,
                $like
            );
        }
    }

    // Ranking: exact SKU → prefix → title prefix → contains.
    $needle = trim((string) (($f['sa_pn'] ?? '') !== '' ? $f['sa_pn'] : ($f['s'] ?? '')));
    if ($needle !== '') {
        $rank = $wpdb->prepare(
            "CASE
                WHEN {$sku} = %s THEN 0
                WHEN {$sku} LIKE %s THEN 1
                WHEN {$wpdb->posts}.post_title LIKE %s THEN 2
                WHEN {$sku} LIKE %s THEN 3
                WHEN {$wpdb->posts}.post_title LIKE %s THEN 4
                ELSE 5
             END ASC",
            $needle,
            $wpdb->esc_like($needle) . '%',
            $wpdb->esc_like($needle) . '%',
            '%' . $wpdb->esc_like($needle) . '%',
            '%' . $wpdb->esc_like($needle) . '%'
        );
        $orderby = (string) ($clauses['orderby'] ?? '');
        $clauses['orderby'] = $rank . ($orderby !== '' ? ', ' . $orderby : '');
    }

    $clauses['distinct'] = 'DISTINCT';
    return $clauses;
}, 30, 2);

add_filter('posts_search', static function (string $search, $query): string {
    if (!($query instanceof WP_Query) || !(int) $query->get('sa_core_catalog_search') || $search === '') {
        return $search;
    }
    $f = $query->get('sa_core_catalog_filters');
    if (!is_array($f)) {
        $f = sa_core_catalog_filters_from_request();
    }
    $s = trim((string) ($f['s'] ?? ''));
    if ($s === '') {
        return $search;
    }
    global $wpdb;
    // posts_search runs before join — reference lookup table name; posts_clauses ensures join.
    $sku = 'wc_product_meta_lookup.sku';
    $sku_or = $wpdb->prepare(
        "({$sku} = %s OR {$sku} LIKE %s OR {$sku} LIKE %s)",
        $s,
        $wpdb->esc_like($s) . '%',
        '%' . $wpdb->esc_like($s) . '%'
    );
    if (preg_match('/^\s*AND\s*\(/', $search)) {
        $patched = (string) preg_replace('/^\s*AND\s*\(/', ' AND (' . $sku_or . ' OR (', $search, 1);
        return $patched . ')';
    }
    return ' AND (' . $sku_or . ' OR 1=0) ';
}, 20, 2);

add_action('set_object_terms', static function ($object_id, $terms, $tt_ids, $taxonomy): void {
    if ($taxonomy === 'product_cat') {
        delete_transient('sa_core_brand_filter_opts_v1');
    }
}, 10, 4);

function sa_core_catalog_clear_filters_url(): string
{
    if (function_exists('is_product_taxonomy') && is_product_taxonomy()) {
        $link = get_term_link(get_queried_object());
        if (!is_wp_error($link)) {
            return (string) $link;
        }
    }
    return function_exists('wc_get_page_permalink') ? (string) wc_get_page_permalink('shop') : home_url('/shop/');
}

/**
 * Form action URL for filters (shop, current category, or home for search).
 */
function sa_core_catalog_filter_form_action(): string
{
    // Always shop or current category so filter GETs stay on product archives
    // ( /?s= is header search only; /shop/?s= is handled by catalog-search ).
    if (function_exists('is_product_taxonomy') && is_product_taxonomy()) {
        $link = get_term_link(get_queried_object());
        if (!is_wp_error($link)) {
            return (string) $link;
        }
    }
    return function_exists('wc_get_page_permalink') ? (string) wc_get_page_permalink('shop') : home_url('/shop/');
}
