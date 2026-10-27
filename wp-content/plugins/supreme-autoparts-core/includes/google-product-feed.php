<?php
/**
 * Google Merchant Center product feed.
 *
 * RSS 2.0 + g: namespace at /feed/google.
 * Prices are the stored WooCommerce USD amounts (get_price edit / _price),
 * never the sa-geo-currency display conversion (that plugin only filters wc_price HTML).
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register /feed/google before the core version-bump rewrite flush (init priority 30).
 */
function sa_gpf_register(): void
{
    add_feed('google', 'sa_gpf_render');
    add_feed('google-kes', 'sa_gpf_render');
}
add_action('init', 'sa_gpf_register', 20);

/** Both regional feeds use the store/payment USD currency. Google estimates KES. */
function sa_gpf_currency(): string
{
    return 'USD';
}

function sa_gpf_is_kenya_feed(): bool
{
    return get_query_var('feed') === 'google-kes';
}

/**
 * USD amount -> display-matching KES. Returns null when no rate is available.
 */
function sa_gpf_kes(float $usd): ?float
{
    if ($usd <= 0 || !class_exists('SA_FX_Rates')) {
        return null;
    }
    $rate = SA_FX_Rates::rate_for('KES');
    if ($rate === null || $rate <= 0) {
        return null;
    }
    $converted = $usd * $rate;
    if (class_exists('SA_GEO_Display') && method_exists('SA_GEO_Display', 'snap')) {
        return SA_GEO_Display::snap($converted, $usd, 'KES');
    }
    return (float) round($converted);
}

/** Pin only Kenya feed links, including prebuilt USD chunks. */
function sa_gpf_market_links(string $chunk): string
{
    if (!sa_gpf_is_kenya_feed()) { return $chunk; }
    return (string) preg_replace_callback(
        '#<g:link>(.*?)</g:link>#s',
        static function (array $m): string {
            $url = html_entity_decode($m[1], ENT_XML1 | ENT_QUOTES, 'UTF-8');
            return '<g:link>' . sa_gpf_xml(add_query_arg('sa_market', 'ke', $url)) . '</g:link>';
        },
        $chunk
    );
}

function sa_gpf_format_price(float $usd): string
{
    if (sa_gpf_currency() === 'KES') {
        $kes = sa_gpf_kes($usd);
        if ($kes !== null) {
            return number_format($kes, 0, '.', '') . ' KES';
        }
    }
    return number_format($usd, 2, '.', '') . ' USD';
}

/**
 * Enrich Woo's existing Product node. Do not print a second Product schema.
 *
 * @param mixed $markup
 * @param mixed $product
 * @return mixed
 */
function sa_gpf_enrich_product_schema($markup, $product)
{
    if (!is_array($markup) || !$product instanceof WC_Product) {
        return $markup;
    }

    $brand = sa_gpf_brand_name($product);
    if ($brand !== '') {
        $markup['brand'] = [
            '@type' => 'Brand',
            'name'  => $brand,
        ];
    }

    $markup['itemCondition'] = 'https://schema.org/NewCondition';

    $sku = trim((string) $product->get_sku());
    if ($sku !== '') {
        $markup['mpn'] = $sku;
    }

    $gtin = sa_gpf_gtin($product);
    if ($gtin !== '') {
        $markup['gtin'] = $gtin;
    }

    return $markup;
}
add_filter('woocommerce_structured_data_product', 'sa_gpf_enrich_product_schema', 20, 2);

/**
 * Stream the Merchant Center feed.
 */
function sa_gpf_render(bool $for_comments = false): void
{
    global $wpdb;
    if ($for_comments || !function_exists('wc_get_product')) {
        status_header(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Feed unavailable.';
        exit;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $n = (int) get_option('sa_gpf_chunks_ready', 0);
    if (empty($_GET['live']) && $n > 0) { // phpcs:ignore
        status_header(200);
        header('Content-Type: application/rss+xml; charset=UTF-8');
        header('X-Robots-Tag: noindex, follow');
        header('Cache-Control: public, max-age=1800');
        echo sa_gpf_head();
        for ($i = 0; $i < $n; $i++) {
            // get_option retains every large feed chunk in the request's object cache.
            // Read only the current chunk so a full catalog cannot exhaust PHP memory.
            $chunk = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                'sa_gpf_chunk_r_' . $i
            ));
            if (sa_gpf_currency() === 'KES') {
                $chunk = (string) preg_replace_callback(
                    '#<g:price>([0-9]+(?:\.[0-9]+)?) USD</g:price>#',
                    static function (array $m): string {
                        $kes = sa_gpf_kes((float) $m[1]);
                        if ($kes === null) {
                            return $m[0];
                        }
                        return '<g:price>' . number_format($kes, 0, '.', '') . ' KES</g:price>';
                    },
                    $chunk
                );
            }
            $chunk = sa_gpf_market_links($chunk);
            echo $chunk;
        }
        echo "</channel>\n</rss>\n";
        exit;
    }
    @set_time_limit(300);

    status_header(200);
    header('Content-Type: application/rss+xml; charset=UTF-8');
    header('X-Robots-Tag: noindex, follow');
    nocache_headers();

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
    echo "<channel>\n";
    echo '<title>Supreme Autoparts</title>' . "\n";
    echo '<link>https://www.supremeautoparts.co.ke/</link>' . "\n";
    echo '<description>Supreme Autoparts product catalog for Google Merchant Center. Prices in USD.</description>' . "\n";

    $per_page = 100;
    $page = 1;
    $max_pages = 1;

    do {
        $query = new WP_Query([
            'post_type'              => 'product',
            'post_status'            => 'publish',
            'posts_per_page'         => $per_page,
            'paged'                  => $page,
            'fields'                 => 'ids',
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'no_found_rows'          => false,
            'update_post_term_cache' => true,
            'update_post_meta_cache' => true,
            'ignore_sticky_posts'    => true,
            'tax_query'              => [
                [
                    'taxonomy' => 'product_visibility',
                    'field'    => 'name',
                    'terms'    => ['exclude-from-catalog'],
                    'operator' => 'NOT IN',
                ],
            ],
        ]);

        if ($page === 1) {
            $max_pages = max(1, (int) $query->max_num_pages);
        }

        $ids = is_array($query->posts) ? $query->posts : [];
        foreach ($ids as $product_id) {
            sa_gpf_render_item((int) $product_id);
        }

        wp_reset_postdata();
        foreach ($ids as $product_id) {
            clean_post_cache((int) $product_id);
        }

        $page++;
    } while ($page <= $max_pages);

    echo "</channel>\n</rss>\n";
    exit;
}

function sa_gpf_render_item(int $product_id): void
{
    $product = wc_get_product($product_id);
    if (!$product instanceof WC_Product) {
        return;
    }
    if ($product->get_status() !== 'publish') {
        return;
    }
    $visibility = (string) $product->get_catalog_visibility();
    if (!in_array($visibility, ['visible', 'catalog'], true)) {
        return;
    }
    if (sa_gpf_is_junk($product)) {
        return;
    }

    $price = sa_gpf_price_usd($product);
    if ($price === null) {
        return;
    }

    $sku = trim((string) $product->get_sku());
    $id = $sku !== '' ? $sku : (string) $product->get_id();
    $title = sa_gpf_plain_text((string) $product->get_name());
    if ($title === '') {
        return;
    }
    if (function_exists('mb_substr')) {
        $title = mb_substr($title, 0, 150);
    } else {
        $title = substr($title, 0, 150);
    }

    $link = get_permalink($product_id);
    if (!is_string($link) || $link === '') {
        return;
    }

    $description = sa_gpf_description($product, $title);
    $image = sa_gpf_image_url($product);
    if ($image === '') {
        return; // Merchant Center requires image_link; never substitute a placeholder.
    }
    $availability = $product->is_in_stock() ? 'in_stock' : 'out_of_stock';
    $brand = sa_gpf_brand_name($product);
    $gtin = sa_gpf_gtin($product);
    $product_type = sa_gpf_product_type($product);

    echo "<item>\n";
    echo '<g:id>' . sa_gpf_xml($id) . "</g:id>\n";
    echo '<g:title>' . sa_gpf_xml($title) . "</g:title>\n";
    echo '<g:description>' . sa_gpf_xml($description) . "</g:description>\n";
    if (sa_gpf_is_kenya_feed()) {
        $link = add_query_arg('sa_market', 'ke', $link);
    }
    echo '<g:link>' . sa_gpf_xml($link) . "</g:link>\n";
    if ($image !== '') {
        echo '<g:image_link>' . sa_gpf_xml($image) . "</g:image_link>\n";
    }
    echo '<g:availability>' . $availability . "</g:availability>\n";
    echo '<g:price>' . sa_gpf_xml($price) . "</g:price>\n";
    echo "<g:condition>new</g:condition>\n";
    if ($brand !== '') {
        echo '<g:brand>' . sa_gpf_xml($brand) . "</g:brand>\n";
    }
    if ($sku !== '') {
        echo '<g:mpn>' . sa_gpf_xml($sku) . "</g:mpn>\n";
    }
    if ($gtin !== '') {
        echo '<g:gtin>' . sa_gpf_xml($gtin) . "</g:gtin>\n";
    } else {
        echo "<g:identifier_exists>false</g:identifier_exists>\n";
    }
    echo "<g:google_product_category>Vehicles &amp; Parts &gt; Vehicle Parts &amp; Accessories</g:google_product_category>\n";
    if ($product_type !== '') {
        echo '<g:product_type>' . sa_gpf_xml($product_type) . "</g:product_type>\n";
    }
    echo "</item>\n";
}

function sa_gpf_is_junk(WC_Product $product): bool
{
    $title = (string) $product->get_name();
    $slug = (string) $product->get_slug();
    if (function_exists('sa_core_is_junk_product_title') && sa_core_is_junk_product_title($title, $slug)) {
        return true;
    }
    $key = sanitize_title($title . ' ' . $slug . ' ' . $product->get_sku());
    return str_contains($key, 'free-shipping-service');
}

/**
 * Stored Woo price in USD. Uses the edit context so display-currency filters cannot apply.
 */
function sa_gpf_price_usd(WC_Product $product): ?string
{
    $raw = $product->get_price('edit');
    if (($raw === '' || $raw === null) && $product->is_type('variable')) {
        $prices = $product->get_variation_prices(false);
        if (!empty($prices['price']) && is_array($prices['price'])) {
            $nums = array_filter($prices['price'], static fn ($v): bool => is_numeric($v));
            if ($nums !== []) {
                $raw = min(array_map('floatval', $nums));
            }
        }
    }
    if (($raw === '' || $raw === null)) {
        $meta = get_post_meta($product->get_id(), '_price', true);
        if (is_numeric($meta)) {
            $raw = $meta;
        }
    }
    if (!is_numeric($raw)) {
        return null;
    }
    $amount = (float) $raw;
    if ($amount < 0) {
        return null;
    }
    return sa_gpf_format_price($amount);
}

function sa_gpf_description(WC_Product $product, string $title): string
{
    $raw = (string) $product->get_description();
    if (trim(wp_strip_all_tags($raw)) === '') {
        $raw = (string) $product->get_short_description();
    }
    $text = sa_gpf_plain_text(strip_shortcodes($raw));
    if (function_exists('mb_strlen')) {
        $tiny = mb_strlen($text) < 30;
    } else {
        $tiny = strlen($text) < 30;
    }
    if ($tiny) {
        $text = $title . '. Auto part sold by Supreme Autoparts.';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, 5000);
    }
    return substr($text, 0, 5000);
}

function sa_gpf_image_url(WC_Product $product): string
{
    $image_id = (int) $product->get_image_id();
    if ($image_id <= 0) {
        $gallery = $product->get_gallery_image_ids();
        if (is_array($gallery) && isset($gallery[0])) {
            $image_id = (int) $gallery[0];
        }
    }
    if ($image_id <= 0) {
        // Real scraped supplier photo (Shopify/manufacturer CDN) stored at import.
        if (function_exists('sa_core_get_stored_shopify_image_urls')) {
            $urls = sa_core_get_stored_shopify_image_urls($product->get_id());
            if ($urls !== []) {
                return (string) $urls[0];
            }
        }
        foreach (['_sa_primary_image_url', '_sa_image_url'] as $k) {
            $u = trim((string) get_post_meta($product->get_id(), $k, true));
            if ($u !== '' && preg_match('#^https?://#i', $u)) {
                return $u;
            }
        }
        return '';
    }
    $url = wp_get_attachment_image_url($image_id, 'full');
    if (!is_string($url) || $url === '') {
        return '';
    }
    if (str_starts_with($url, '//')) {
        $url = 'https:' . $url;
    } elseif (!preg_match('#^https?://#i', $url)) {
        $url = home_url($url);
    }
    return $url;
}

function sa_gpf_brand_name(WC_Product $product): string
{
    $terms = get_the_terms($product->get_id(), 'product_cat');
    if (!is_array($terms) || $terms === []) {
        return '';
    }
    $brands_id = sa_gpf_brands_term_id();
    if ($brands_id <= 0) {
        return '';
    }
    foreach ($terms as $term) {
        if (!$term instanceof WP_Term) {
            continue;
        }
        if ((int) $term->parent === $brands_id) {
            $name = sa_gpf_plain_text($term->name);
            if ($name !== '') {
                return $name;
            }
        }
    }
    return '';
}

function sa_gpf_brands_term_id(): int
{
    static $id = null;
    if ($id !== null) {
        return $id;
    }
    $term = get_term_by('slug', 'brands', 'product_cat');
    $id = ($term instanceof WP_Term) ? (int) $term->term_id : 0;
    return $id;
}

function sa_gpf_product_type(WC_Product $product): string
{
    $terms = get_the_terms($product->get_id(), 'product_cat');
    if (!is_array($terms)) {
        return '';
    }
    $brands_id = sa_gpf_brands_term_id();
    $names = [];
    foreach ($terms as $term) {
        if (!$term instanceof WP_Term) {
            continue;
        }
        if ($brands_id > 0 && ((int) $term->term_id === $brands_id || (int) $term->parent === $brands_id)) {
            continue;
        }
        if (sanitize_title($term->slug) === 'uncategorized') {
            continue;
        }
        $name = sa_gpf_plain_text($term->name);
        if ($name !== '') {
            $names[$name] = true;
        }
        if (count($names) >= 5) {
            break;
        }
    }
    return implode(' > ', array_keys($names));
}

function sa_gpf_gtin(WC_Product $product): string
{
    $keys = ['_global_unique_id', '_gtin', '_barcode', '_ean', '_upc', '_wpm_gtin_code', 'hwp_product_gtin'];
    foreach ($keys as $key) {
        $value = trim((string) $product->get_meta($key, true));
        if ($value === '') {
            $value = trim((string) get_post_meta($product->get_id(), $key, true));
        }
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        $len = strlen($digits);
        if (in_array($len, [8, 12, 13, 14], true)) {
            return $digits;
        }
    }
    return '';
}

function sa_gpf_plain_text(string $value): string
{
    $value = wp_strip_all_tags($value);
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? $value;
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return trim($value);
}

function sa_gpf_xml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}


/* ------------------------------------------------------------------ */
/* Prebuilt feed: resumable WP-Cron batches -> uploads/sa-feed/google.xml */
/* ------------------------------------------------------------------ */

const SA_GPF_BUILD_BATCH = 800;
const SA_GPF_BUILD_VERSION = '2026-10-14b';

function sa_gpf_head(): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n<channel>\n"
        . "<title>Supreme Autoparts</title>\n<link>https://www.supremeautoparts.co.ke/</link>\n"
        . "<description>Supreme Autoparts product catalog for Google Merchant Center. Prices in USD.</description>\n";
}

/* Chunks live in the DB (container disk is not shared/persistent across Railway instances). */
function sa_gpf_build_batch(): array
{
    global $wpdb;
    $st = get_option('sa_gpf_build_state', []);
    if (!is_array($st) || ($st['version'] ?? '') !== SA_GPF_BUILD_VERSION || !empty($st['restart'])) {
        $st = ['version' => SA_GPF_BUILD_VERSION, 'last_id' => 0, 'scanned' => 0, 'items' => 0, 'chunk' => 0, 'done' => false, 'started' => time()];
    }
    if (!empty($st['done'])) {
        return $st;
    }
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' AND ID>%d ORDER BY ID ASC LIMIT %d",
        (int) $st['last_id'], SA_GPF_BUILD_BATCH
    ));
    if (!$ids) {
        // Promote the building set to the ready set atomically-ish.
        $old = (int) get_option('sa_gpf_chunks_ready', 0);
        for ($i = 0; $i < (int) $st['chunk']; $i++) {
            update_option('sa_gpf_chunk_r_' . $i, (string) get_option('sa_gpf_chunk_b_' . $i, ''), false);
            delete_option('sa_gpf_chunk_b_' . $i);
        }
        for ($i = (int) $st['chunk']; $i < $old; $i++) {
            delete_option('sa_gpf_chunk_r_' . $i);
        }
        update_option('sa_gpf_chunks_ready', (int) $st['chunk'], false);
        $st['done'] = true;
        $st['finished'] = time();
        update_option('sa_gpf_build_state', $st, false);
        return $st;
    }
    ob_start();
    foreach ($ids as $id) {
        sa_gpf_render_item((int) $id);
        $st['last_id'] = (int) $id;
        $st['scanned']++;
        clean_post_cache((int) $id);
    }
    $chunk = (string) ob_get_clean();
    $st['items'] += substr_count($chunk, "<item>\n");
    update_option('sa_gpf_chunk_b_' . (int) $st['chunk'], $chunk, false);
    $st['chunk']++;
    $st['updated'] = time();
    update_option('sa_gpf_build_state', $st, false);
    return $st;
}

add_action('sa_gpf_build_tick', static function (): void {
    if (get_transient('sa_gpf_build_lock')) {
        return;
    }
    set_transient('sa_gpf_build_lock', 1, 180);
    @set_time_limit(170);
    $st = sa_gpf_build_batch();
    delete_transient('sa_gpf_build_lock');
    if (empty($st['done'])) {
        wp_schedule_single_event(time() + 5, 'sa_gpf_build_tick');
    }
});

/* Start/resume after deploys; rebuild daily (or if the file vanished on redeploy). */
add_action('init', static function (): void {
    $st = get_option('sa_gpf_build_state', []);
    $done = is_array($st) && ($st['version'] ?? '') === SA_GPF_BUILD_VERSION && !empty($st['done']);
    if ($done && (time() - (int) ($st['finished'] ?? 0) > DAY_IN_SECONDS || (int) get_option('sa_gpf_chunks_ready', 0) === 0)) {
        $st['restart'] = 1;
        update_option('sa_gpf_build_state', $st, false);
        $done = false;
    }
    if (!$done && !wp_next_scheduled('sa_gpf_build_tick') && !get_transient('sa_gpf_build_lock')) {
        wp_schedule_single_event(time() + 5, 'sa_gpf_build_tick');
    }
}, 99);

add_action('rest_api_init', static function (): void {
    register_rest_route('sa/v1', '/feed-build', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => static function () {
            return ['state' => get_option('sa_gpf_build_state', []), 'ready_chunks' => (int) get_option('sa_gpf_chunks_ready', 0)];
        },
    ]);
    // Read-only catalog visibility audit (counts only).
    register_rest_route('sa/v1', '/catalog-visibility', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => static function () {
            $c = get_transient('sa_catalog_vis_audit');
            if (is_array($c)) {
                return $c;
            }
            global $wpdb;
            $p = $wpdb->posts; $m = $wpdb->postmeta;
            $c = [
                'by_status' => $wpdb->get_results("SELECT post_status s, COUNT(*) n FROM {$p} WHERE post_type='product' GROUP BY post_status", ARRAY_A),
                'publish_hidden_visibility' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT p.ID) FROM {$p} p JOIN {$wpdb->term_relationships} tr ON tr.object_id=p.ID JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='product_visibility' JOIN {$wpdb->terms} t ON t.term_id=tt.term_id AND t.slug='exclude-from-catalog' WHERE p.post_type='product' AND p.post_status='publish'"),
                'publish_no_price' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p} p LEFT JOIN {$m} pr ON pr.post_id=p.ID AND pr.meta_key='_price' WHERE p.post_type='product' AND p.post_status='publish' AND (pr.meta_value IS NULL OR pr.meta_value='' OR pr.meta_value+0<=0)"),
                'publish_outofstock' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p} p JOIN {$m} s ON s.post_id=p.ID AND s.meta_key='_stock_status' AND s.meta_value='outofstock' WHERE p.post_type='product' AND p.post_status='publish'"),
                'publish_with_attachment' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p} p JOIN {$m} t ON t.post_id=p.ID AND t.meta_key='_thumbnail_id' AND t.meta_value+0>0 WHERE p.post_type='product' AND p.post_status='publish'"),
                'publish_no_photo_at_all' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p} p WHERE p.post_type='product' AND p.post_status='publish' AND NOT EXISTS (SELECT 1 FROM {$m} x WHERE x.post_id=p.ID AND ((x.meta_key='_thumbnail_id' AND x.meta_value+0>0) OR (x.meta_key IN ('_sa_shopify_image_urls','_sa_shopify_image_src','_sa_primary_image_url','_sa_image_url') AND x.meta_value LIKE '%http%')))"),
                'publish_no_category' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p} p WHERE p.post_type='product' AND p.post_status='publish' AND NOT EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='product_cat' WHERE tr.object_id=p.ID)"),
                'lookup_rows' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_product_meta_lookup"),
                'woo_hide_out_of_stock' => get_option('woocommerce_hide_out_of_stock_items'),
                'at' => gmdate('c'),
            ];
            set_transient('sa_catalog_vis_audit', $c, 10 * MINUTE_IN_SECONDS);
            return $c;
        },
    ]);
});
