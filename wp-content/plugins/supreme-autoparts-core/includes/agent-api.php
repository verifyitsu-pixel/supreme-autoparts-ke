<?php
/**
 * Read-only JSON endpoints for the website AI assistant (Jotform AI Agent "API actions").
 *
 *  GET /wp-json/supreme/v1/agent/parts?q=&sku=&make=&model=&year=&country=&limit=
 *      Public catalogue search (published, visible products only). Live price + stock.
 *  GET /wp-json/supreme/v1/agent/order-status?order=&email=
 *      Status/shipping/tracking ONLY when order number + billing email match.
 *      Never returns addresses, phone, payment or item prices.
 *
 * Both rate-limited (per client IP, plus per-order failed-attempt lockout). No auth, no writes.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

function sa_agent_ip(): string
{
    $ip = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
}

/** Fixed-window counter. Returns false when over the limit. */
function sa_agent_rate_ok(string $bucket, int $limit, int $window): bool
{
    $k = 'sa_ag_rl_' . md5($bucket . '|' . (int) floor(time() / $window));
    $n = (int) get_transient($k);
    if ($n >= $limit) {
        return false;
    }
    set_transient($k, $n + 1, $window + 5);
    return true;
}

function sa_agent_err(string $code, string $msg, int $status): WP_REST_Response
{
    $r = new WP_REST_Response(['ok' => false, 'error' => $code, 'message' => $msg], $status);
    $r->header('Cache-Control', 'no-store');
    return $r;
}

function sa_agent_kes(float $usd): ?float
{
    if (!class_exists('SA_FX_Rates')) {
        return null;
    }
    $k = SA_FX_Rates::convert_usd($usd, 'KES');
    if ($k === null) {
        return null;
    }
    return class_exists('SA_Geo_Display') ? SA_Geo_Display::snap($k, $usd, 'KES') : round($k);
}

function sa_agent_stock_label(WC_Product $p): string
{
    $s = $p->get_stock_status();
    return $s === 'instock' ? 'in_stock' : ($s === 'onbackorder' ? 'on_backorder' : 'out_of_stock');
}

/** Product ids matching make/model/year via the fitment facet index (catalog-facets.php). */
function sa_agent_vehicle_ids(string $make, string $model, string $year): ?array
{
    if ($make === '' || !function_exists('sa_fx_ready') || !sa_fx_ready()) {
        return null;
    }
    global $wpdb;
    $mk = sanitize_title($make);
    $map = ['chevy' => 'chevrolet', 'vw' => 'volkswagen', 'mercedes' => 'mercedes-benz'];
    $mk = $map[$mk] ?? $mk;
    if ($model !== '' && $year !== '' && preg_match('/^(19|20)\d{2}$/', $year)) {
        $facet = 'ymm'; $val = $mk . '/' . sanitize_title($model) . '/' . $year;
    } elseif ($model !== '') {
        $facet = 'model'; $val = $mk . '/' . sanitize_title($model);
    } else {
        $facet = 'make'; $val = $mk;
    }
    $t = sa_fx_table();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $ids = $wpdb->get_col($wpdb->prepare("SELECT product_id FROM {$t} WHERE facet=%s AND value=%s LIMIT 5000", $facet, $val));
    return array_map('intval', $ids);
}

/** Treat Jotform AI placeholder / junk values as empty. */
function sa_agent_junk(string $v, string $name): string
{
    $t = strtolower(trim($v, " \t\n\r\0\x0B\"'"));
    $junk = ['ai generated field', 'ai-generated field', 'unknown', 'n/a', 'na', 'none', 'null', 'undefined', '-', '?', 'nil', 'any', 'all', 'not provided', 'not specified'];
    if ($t === '' || in_array($t, $junk, true) || $t === strtolower($name) || preg_match('/^\{\{.*\}\}$|^<.*>$|^\[.*\]$/', $t)) {
        return '';
    }
    return trim($v);
}

/** Parse free text like "2018 Toyota Tacoma" into [year, make, model]. */
function sa_agent_parse_vehicle(string $v): array
{
    $v = trim(preg_replace('/\s+/', ' ', $v) ?? '');
    $year = '';
    if (preg_match('/\b((?:19|20)\d{2})\b/', $v, $m)) {
        $year = $m[1];
        $v = trim(str_replace($m[0], ' ', $v));
        $v = trim(preg_replace('/\s+/', ' ', $v) ?? '');
    }
    $parts = $v === '' ? [] : explode(' ', $v);
    $make = '';
    $model = '';
    if ($parts) {
        $two = isset($parts[1]) ? strtolower($parts[0] . ' ' . $parts[1]) : '';
        if (in_array($two, ['land rover', 'alfa romeo', 'aston martin', 'mercedes benz', 'rolls royce'], true)) {
            $make = $parts[0] . ' ' . $parts[1];
            $model = implode(' ', array_slice($parts, 2));
        } else {
            $make = $parts[0];
            $model = implode(' ', array_slice($parts, 1));
        }
    }
    return [$year, $make, $model];
}

/** Published product ids whose SKU equals $sku exactly (case-insensitive). */
function sa_agent_exact_sku_ids(string $sku): array
{
    if ($sku === '') { return []; }
    global $wpdb;
    $lk = $wpdb->wc_product_meta_lookup;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p JOIN {$lk} l ON l.product_id=p.ID WHERE p.post_type='product' AND p.post_status='publish' AND l.sku = %s ORDER BY (l.stock_status='instock') DESC, p.ID DESC LIMIT 10",
        $sku
    ));
    return array_map('intval', $ids);
}

/** Product ids for a filtered search (SQL only; visibility checked by caller). */
function sa_agent_find_ids(string $q, string $sku, string $make, string $model, string $year): array
{
    global $wpdb;
    $lk = $wpdb->wc_product_meta_lookup;
    $where = ["p.post_type='product'", "p.post_status='publish'"];
    $args = [];
    $rank = '';
    $needle = $sku !== '' ? $sku : $q;
    if ($sku !== '') {
        $where[] = '(l.sku = %s OR l.sku LIKE %s OR p.post_title LIKE %s)';
        array_push($args, $sku, '%' . $wpdb->esc_like($sku) . '%', '%' . $wpdb->esc_like($sku) . '%');
    }
    if ($q !== '') {
        $words = array_slice(array_filter(preg_split('/\s+/', $q) ?: [], static fn($w) => mb_strlen($w) >= 2), 0, 6);
        if (!$words) { $words = [$q]; }
        $or = '(l.sku = %s OR l.sku LIKE %s)';
        array_push($args, $q, $wpdb->esc_like($q) . '%');
        $and = [];
        foreach ($words as $w) {
            $and[] = 'p.post_title LIKE %s';
            $args[] = '%' . $wpdb->esc_like($w) . '%';
        }
        $where[] = '(' . $or . ' OR (' . implode(' AND ', $and) . '))';
    }
    $vids = sa_agent_vehicle_ids($make, $model, $year);
    if ($make !== '') {
        if ($vids === null) {
            // No fitment index yet: fall back to title words.
            foreach (array_filter([$make, $model, $year]) as $w) {
                $where[] = 'p.post_title LIKE %s';
                $args[] = '%' . $wpdb->esc_like($w) . '%';
            }
        } elseif ($vids === []) {
            $where[] = '1=0';
        } else {
            $where[] = 'p.ID IN (' . implode(',', array_slice($vids, 0, 5000)) . ')';
        }
    }
    $fee = (int) get_option('sa_svc_fee_product', 0);
    if ($fee) { $where[] = 'p.ID <> ' . $fee; }
    if ($needle !== '') {
        $rank = $wpdb->prepare('CASE WHEN l.sku = %s THEN 0 WHEN l.sku LIKE %s THEN 1 WHEN p.post_title LIKE %s THEN 2 ELSE 3 END', $needle, $wpdb->esc_like($needle) . '%', $wpdb->esc_like($needle) . '%');
    }
    $sql = "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$lk} l ON l.product_id=p.ID WHERE " . implode(' AND ', $where)
        . " ORDER BY " . ($rank !== '' ? "({$rank} = 0) DESC, " : '') . "(COALESCE(l.stock_status,'') = 'instock') DESC, "
        . ($rank !== '' ? "{$rank} ASC, " : '') . "COALESCE(l.total_sales,0) DESC, p.ID DESC LIMIT 60";
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    $ids = $wpdb->get_col($args ? $wpdb->prepare($sql, $args) : $sql);
    return array_map('intval', $ids);
}

function sa_agent_parts(WP_REST_Request $r): WP_REST_Response
{
    if (!sa_agent_rate_ok('parts|' . sa_agent_ip(), 60, 60) || !sa_agent_rate_ok('parts|all', 600, 60)) {
        return sa_agent_err('rate_limited', 'Too many searches. Please wait a minute.', 429);
    }
    $clean = static fn($k, $max = 80) => mb_substr(trim(sanitize_text_field((string) $r->get_param($k))), 0, $max);
    $get = static fn($k, $max = 80) => sa_agent_junk($clean($k, $max), $k);
    $q = $get('q'); $sku = $get('sku', 60); $make = $get('make', 40); $model = $get('model', 60); $year = $get('year', 10);
    if ($sku === '') { $sku = $get('part_number', 60); }
    $veh = $get('vehicle', 120);
    if ($veh !== '') {
        [$vy, $vm, $vmo] = sa_agent_parse_vehicle($veh);
        if ($year === '') { $year = $vy; }
        if ($make === '') { $make = $vm; }
        if ($model === '') { $model = $vmo; }
    }
    if (!preg_match('/^(19|20)\d{2}$/', $year)) {
        $year = preg_match('/\b((?:19|20)\d{2})\b/', $year, $ym) ? $ym[1] : '';
    }
    $cc = strtoupper(sa_agent_junk($clean('country', 20), 'country'));
    $cc = strlen($cc) === 2 ? $cc : (in_array($cc, ['KENYA'], true) ? 'KE' : (in_array($cc, ['USA', 'US', 'UNITED STATES'], true) ? 'US' : ''));
    $limit = max(1, min(10, (int) ($r->get_param('limit') ?: 10)));
    if ($q === '' && $sku === '' && $make === '') {
        return sa_agent_err('missing_query', 'Give a part name (q), a part number (sku) or a vehicle make.', 400);
    }

    $ck = 'sa_ag_p2_' . md5(wp_json_encode([$q, $sku, $make, $model, $year, $limit, $cc]));
    $cached = get_transient($ck);
    if (is_array($cached)) {
        $resp = new WP_REST_Response($cached, 200);
        $resp->header('Cache-Control', 'public, max-age=120');
        return $resp;
    }

    // Exact SKU wins regardless of other filters.
    $relaxed = [];
    $ids = [];
    if ($sku !== '') {
        $ids = sa_agent_exact_sku_ids($sku);
        if ($ids) { $relaxed[] = 'exact part number match (other filters ignored)'; }
    }
    if (!$ids && ($q !== '' || $sku !== '' || $make !== '')) {
        $ids = sa_agent_find_ids($q, $sku, $make, $model, $year);
    }
    // Progressive relaxation when filtered search returns nothing.
    if (!$ids) {
        $steps = [];
        if ($year !== '') { $steps[] = ['year', [$q, $sku, $make, $model, '']]; }
        if ($model !== '') { $steps[] = ['model', [$q, $sku, $make, '', '']]; }
        if ($make !== '' && ($q !== '' || $sku !== '')) { $steps[] = ['make', [$q, $sku, '', '', '']]; }
        if ($sku !== '' && $q !== '') { $steps[] = ['sku', [$q, '', '', '', '']]; }
        $dropped = [];
        foreach ($steps as [$what, $a]) {
            $dropped[] = $what;
            $ids = sa_agent_find_ids(...$a);
            if ($ids) { $relaxed[] = 'no exact match; dropped ' . implode(', ', $dropped); break; }
        }
        if (!$ids && $q !== '' && $sku === '') {
            $ids = sa_agent_exact_sku_ids($q);
            if (!$ids) { $ids = sa_agent_find_ids('', $q, '', '', ''); }
            if ($ids) { $relaxed[] = 'no match by name' . ($dropped ? ' (dropped ' . implode(', ', $dropped) . ')' : '') . '; matched q as part number'; }
        }
    }

    $out = [];
    foreach ($ids as $id) {
        $p = wc_get_product((int) $id);
        if (!$p instanceof WC_Product || !$p->is_visible() || $p->get_catalog_visibility() === 'hidden') {
            continue;
        }
        $usd = (float) $p->get_price();
        $brand = '';
        if (function_exists('sa_fx_cat_maps')) {
            foreach (wp_get_post_terms($p->get_id(), 'product_cat', ['fields' => 'all']) as $t) {
                $par = $t->parent ? get_term($t->parent, 'product_cat') : null;
                if ($par && !is_wp_error($par) && $par->slug === 'brands') { $brand = html_entity_decode($t->name); break; }
            }
        }
        $kes = $usd > 0 ? sa_agent_kes($usd) : null;
        $img = wp_get_attachment_image_url((int) $p->get_image_id(), 'woocommerce_thumbnail');
        $row = [
            'name' => html_entity_decode(wp_strip_all_tags($p->get_name()), ENT_QUOTES),
            'part_number' => (string) $p->get_sku(),
            'brand' => $brand,
            'price_usd' => $usd > 0 ? round($usd, 2) : null,
            'price_kes' => $kes,
            'price_display' => $usd <= 0 ? 'Price on request' : (($cc === 'KE' && $kes) ? 'KES ' . number_format($kes) : ($cc !== '' && $cc !== 'KE' ? 'US$' . number_format($usd, 2) : 'US$' . number_format($usd, 2) . ($kes ? ' / KES ' . number_format($kes) : ''))),
            'stock_status' => sa_agent_stock_label($p),
            'type' => $p->get_type(),
            'url' => $p->get_permalink(),
            'image' => $img ?: null,
        ];
        if ($p->is_type('variable')) {
            $row['note'] = 'Has options (size/side/etc.). Price shown is the lowest; choose the option on the product page.';
        }
        $out[] = $row;
        if (count($out) >= $limit) {
            break;
        }
    }
    $data = [
        'ok' => true,
        'query' => array_filter(['q' => $q, 'sku' => $sku, 'make' => $make, 'model' => $model, 'year' => $year, 'country' => $cc]),
        'count' => count($out),
        'relaxed' => $relaxed ? implode('; ', $relaxed) : null,
        'results' => $out,
        'search_url' => add_query_arg(array_map('rawurlencode', array_filter(['s' => $sku ?: $q, 'post_type' => 'product', 'sa_make' => $make ? sanitize_title($make) : '', 'sa_model' => $model ? sanitize_title($model) : '', 'sa_year' => $year])), home_url('/')),
        'notes' => 'Live data. Fitment from catalogue data only; confirm with a fitment check (VIN) when unsure. Kenyan visitors pay in KES at checkout, others in USD.',
    ];
    set_transient($ck, $data, 5 * MINUTE_IN_SECONDS);
    $resp = new WP_REST_Response($data, 200);
    $resp->header('Cache-Control', 'public, max-age=120');
    return $resp;
}

function sa_agent_order_status(WP_REST_Request $r): WP_REST_Response
{
    $ip = sa_agent_ip();
    if (!sa_agent_rate_ok('ord|' . $ip, 20, 60) || !sa_agent_rate_ok('ord|all', 200, 60)) {
        return sa_agent_err('rate_limited', 'Too many lookups. Please wait a minute.', 429);
    }
    $num = preg_replace('/[^0-9]/', '', (string) $r->get_param('order')) ?? '';
    $email = strtolower(trim(sanitize_email((string) $r->get_param('email'))));
    if ($num === '' || !is_email($email)) {
        return sa_agent_err('missing_params', 'Order number and the billing email used on the order are both required.', 400);
    }
    $lock = 'sa_ag_lock_' . md5($num);
    if ((int) get_transient($lock) >= 5) {
        return sa_agent_err('locked', 'Too many failed attempts for this order. Try again in an hour or contact support.', 429);
    }
    $o = wc_get_order((int) $num);
    $match = $o instanceof WC_Order && !($o instanceof WC_Order_Refund)
        && (string) $o->get_order_number() === $num
        && hash_equals(strtolower((string) $o->get_billing_email()), $email);
    if (!$match) {
        set_transient($lock, (int) get_transient($lock) + 1, HOUR_IN_SECONDS);
        return sa_agent_err('not_found', 'We could not match that order number with that email. Check both and try again.', 404);
    }
    delete_transient($lock);

    $courier = (string) $o->get_meta('_sa_courier');
    $trk = (string) $o->get_meta('_sa_tracking_no');
    $couriers = function_exists('sa_ol_couriers') ? sa_ol_couriers() : [];
    $pickup = $o->has_shipping_method('local_pickup') || (string) $o->get_meta('_sa_pickup') === '1';
    $fargo_live = null;
    if ($courier === 'fargo' && $trk !== '' && function_exists('sa_fargo_track') && sa_agent_rate_ok('ordft|' . $ip, 8, 60)) {
        $t = sa_fargo_track($trk);
        if (!empty($t['ok'])) {
            $ev = $t['events'][0] ?? null;
            $fargo_live = ['status' => (string) ($t['status'] ?? ''), 'latest_event' => $ev ? ['time' => (string) ($ev['time'] ?? ''), 'status' => (string) ($ev['status'] ?? ''), 'location' => (string) ($ev['location'] ?? '')] : null];
        }
    }
    $items = [];
    foreach ($o->get_items() as $it) {
        $items[] = ['name' => html_entity_decode(wp_strip_all_tags($it->get_name()), ENT_QUOTES), 'qty' => (int) $it->get_quantity()];
    }
    $created = $o->get_date_created();
    $data = [
        'ok' => true,
        'order' => $o->get_order_number(),
        'status' => $o->get_status(),
        'status_label' => wc_get_order_status_name($o->get_status()),
        'placed' => $created ? $created->date_i18n('Y-m-d H:i') . ' EAT' : null,
        'paid' => $o->is_paid(),
        'currency' => $o->get_currency(),
        'items' => $items,
        'fulfilment' => $pickup ? 'store_pickup' : ($o->get_shipping_method() !== '' ? 'delivery' : 'none'),
        'shipping_method' => html_entity_decode((string) $o->get_shipping_method(), ENT_QUOTES),
        'pickup_slot' => function_exists('sa_pk_slot_text') && $pickup ? (sa_pk_slot_text($o) ?: null) : null,
        'courier' => $courier !== '' ? ($couriers[$courier]['label'] ?? $courier) : null,
        'tracking_number' => $trk !== '' ? $trk : null,
        'tracking_url' => ($trk !== '' && function_exists('sa_ol_tracking_url')) ? (sa_ol_tracking_url($o) ?: null) : null,
        'courier_live' => $fargo_live,
        'eta' => ((string) $o->get_meta('_sa_eta')) ?: null,
        'on_hold_info_requested' => $o->get_status() === 'on-hold' ? true : null,
        'view_url' => home_url('/track-order/'),
    ];
    $resp = new WP_REST_Response(array_filter($data, static fn($v) => $v !== null), 200);
    $resp->header('Cache-Control', 'private, no-store');
    return $resp;
}

add_action('rest_api_init', static function (): void {
    register_rest_route('supreme/v1', '/agent/parts', [
        'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'sa_agent_parts',
    ]);
    register_rest_route('supreme/v1', '/agent/order-status', [
        'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'sa_agent_order_status',
    ]);
});
