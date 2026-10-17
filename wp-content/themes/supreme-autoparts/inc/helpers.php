<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Normalize a product title into a fingerprint for near-duplicate detection.
 *
 * Strips size/length/mm/cm/id/od/colour tails; first 7 tokens.
 * Core plugin may already define this — keep a theme fallback.
 *
 * @param WC_Product|WP_Post|string|int $source
 */
if (!function_exists('sa_product_loop_fingerprint')) {
    function sa_product_loop_fingerprint($source): string
    {
        $title = '';
        if ($source instanceof WC_Product) {
            $title = (string) $source->get_name();
        } elseif ($source instanceof WP_Post) {
            $title = (string) $source->post_title;
        } elseif (is_numeric($source)) {
            $post = get_post((int) $source);
            $title = $post ? (string) $post->post_title : '';
        } else {
            $title = (string) $source;
        }
        $title = strtolower(wp_strip_all_tags($title));
        $title = preg_replace('/[^a-z0-9]+/', ' ', $title) ?? '';
        $title = trim(preg_replace('/\s+/', ' ', $title) ?? '');
        $title = preg_replace(
            '/\b(length|size|colour|color|option|mm|inch|inches|in|cm|id|od)\b.*$/',
            '',
            $title
        ) ?? $title;
        $title = trim($title);
        $parts = array_values(array_filter(explode(' ', $title), static fn ($tok) => $tok !== ''));
        $parts = array_slice($parts, 0, 7);
        $key = implode(' ', $parts);
        if ($key !== '') {
            return $key;
        }
        if (is_object($source) && method_exists($source, 'get_id')) {
            return 'id:' . (string) $source->get_id();
        }
        if ($source instanceof WP_Post) {
            return 'id:' . (string) $source->ID;
        }
        return 'empty';
    }
}


/**
 * Product-type tiles for the homepage (real category photos preferred).
 *
 * @return array<int, array{title:string,slug:string,icon:string}>
 */
function sa_product_types(): array
{
    return [
        ['title' => 'Air Intake', 'slug' => 'air-intake', 'icon' => 'air-intake'],
        ['title' => 'Brakes', 'slug' => 'brakes', 'icon' => 'brakes'],
        ['title' => 'Drivetrain', 'slug' => 'drivetrain', 'icon' => 'drivetrain'],
        ['title' => 'Engine', 'slug' => 'engine', 'icon' => 'engine'],
        ['title' => 'Exhaust', 'slug' => 'exhaust', 'icon' => 'exhaust'],
        ['title' => 'Exterior', 'slug' => 'exterior', 'icon' => 'exterior'],
        ['title' => 'Interior', 'slug' => 'interior', 'icon' => 'interior'],
        ['title' => 'Lighting', 'slug' => 'lighting', 'icon' => 'lighting'],
        ['title' => 'Suspension', 'slug' => 'suspension', 'icon' => 'suspension'],
        ['title' => 'Tires', 'slug' => 'tires', 'icon' => 'tires'],
        ['title' => 'Wheels', 'slug' => 'wheels', 'icon' => 'wheels'],
    ];
}

/**
 * Local theme asset for a product-type category (baked Shopify product photos).
 */
function sa_category_theme_image_url(string $slug): string
{
    $slug = sanitize_title($slug);
    if ($slug === '') {
        return '';
    }
    $rel = '/assets/images/categories/' . $slug . '.jpg';
    $path = SA_THEME_DIR . $rel;
    if (!is_readable($path) || filesize($path) < 1000) {
        return '';
    }
    return SA_THEME_URI . $rel;
}

/**
 * Real category thumbnail URL (theme asset → core CDN map / term meta / product).
 */
function sa_category_image_url(string $slug): string
{
    $local = sa_category_theme_image_url($slug);
    if ($local !== '') {
        return $local;
    }
    if (function_exists('sa_core_get_category_image_url')) {
        return sa_core_get_category_image_url($slug);
    }
    $term = get_term_by('slug', $slug, 'product_cat');
    if ($term && !is_wp_error($term)) {
        $stored = (string) get_term_meta((int) $term->term_id, 'sa_image_url', true);
        if ($stored !== '' && preg_match('#^https?://#i', $stored)) {
            return $stored;
        }
        $thumb_id = (int) get_term_meta((int) $term->term_id, 'thumbnail_id', true);
        if ($thumb_id > 0) {
            $src = wp_get_attachment_image_url($thumb_id, 'woocommerce_thumbnail');
            if (is_string($src) && $src !== '') {
                return $src;
            }
        }
    }
    return '';
}

/**
 * Inline SVG icon for category tiles (24×24, currentColor).
 */
function sa_category_icon_svg(string $key): string
{
    $icons = [
        'air-intake' => '<path d="M4 14h4l2-6h4l2 6h4"/><circle cx="8" cy="17" r="2"/><circle cx="16" cy="17" r="2"/><path d="M9 8V5h6v3"/>',
        'brakes' => '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3"/><path d="M12 4v2M12 18v2M4 12h2M18 12h2"/>',
        'drivetrain' => '<circle cx="6" cy="12" r="3"/><circle cx="18" cy="12" r="3"/><path d="M9 12h6M12 9v6"/>',
        'engine' => '<rect x="5" y="8" width="14" height="9" rx="1"/><path d="M8 8V6h3v2M14 8V5h2v3M9 17v2M15 17v2M3 12h2M19 11h2v3h-2"/>',
        'exhaust' => '<path d="M3 14h10c2 0 3-1 4-2l2-2"/><path d="M13 14v3c0 1.5 1 2.5 2.5 2.5S18 18.5 18 17"/><circle cx="20" cy="10" r="1.5"/>',
        'exterior' => '<path d="M4 15l2-5h12l2 5"/><path d="M4 15h16v3H4z"/><circle cx="7.5" cy="18" r="1.5"/><circle cx="16.5" cy="18" r="1.5"/><path d="M8 10l1.5-3h5L16 10"/>',
        'interior' => '<path d="M5 18V9l7-4 7 4v9"/><path d="M9 18v-5h6v5"/><path d="M5 12h14"/>',
        'lighting' => '<path d="M9 18h6M10 21h4"/><path d="M12 3a5 5 0 0 0-3 9c.5 1 .8 2 .8 3h4.4c0-1 .3-2 .8-3a5 5 0 0 0-3-9z"/>',
        'suspension' => '<path d="M6 5v14M18 5v14M6 12h12"/><path d="M6 8h4M14 8h4M6 16h4M14 16h4"/>',
        'tires' => '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3"/><path d="M12 4l1.5 3.5M12 20l-1.5-3.5M4 12l3.5 1.5M20 12l-3.5-1.5"/>',
        'wheels' => '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="2.5"/><path d="M12 4v5.5M12 14.5V20M4 12h5.5M14.5 12H20M7 7l3.5 3.5M13.5 13.5L17 17M17 7l-3.5 3.5M10.5 13.5L7 17"/>',
        'search' => '<circle cx="11" cy="11" r="6"/><path d="M16 16l4 4"/>',
        'cart' => '<path d="M4 6h2l2.2 10h9.6L20 8H8"/><circle cx="10" cy="20" r="1.5"/><circle cx="17" cy="20" r="1.5"/>',
        'close' => '<path d="M6 6l12 12M18 6L6 18"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'minus' => '<path d="M6 12h12"/>',
        'plus' => '<path d="M12 6v12M6 12h12"/>',
        'user' => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c1.5-3.5 4-5 7-5s5.5 1.5 7 5"/>',
    ];

    $paths = $icons[$key] ?? $icons['engine'];
    return sprintf(
        '<svg class="sa-icon sa-icon--%1$s" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%2$s</svg>',
        esc_attr($key),
        $paths
    );
}

/**
 * @return array<int, array{title:string,slug:string,class:string}>
 */
function sa_regions(): array
{
    return [
        ['title' => 'American', 'slug' => 'american', 'class' => 'sa-region-card--american'],
        ['title' => 'European', 'slug' => 'european', 'class' => 'sa-region-card--european'],
        ['title' => 'Asian', 'slug' => 'asian', 'class' => 'sa-region-card--asian'],
    ];
}

/**
 * @return array<int, array{title:string,slug:string}>
 */
function sa_top_brands(): array
{
    return [
        ['title' => 'ACT Clutch', 'slug' => 'act'],
        ['title' => 'aFe Power', 'slug' => 'afe'],
        ['title' => 'AWE', 'slug' => 'awe'],
        ['title' => 'Bilstein', 'slug' => 'bilstein'],
        ['title' => 'Bushwacker', 'slug' => 'bushwacker'],
        ['title' => 'Corsa', 'slug' => 'corsa'],
        ['title' => 'EBC Brakes', 'slug' => 'ebc'],
        ['title' => 'Fox Shocks', 'slug' => 'fox'],
        ['title' => 'Garrett', 'slug' => 'garrett'],
        ['title' => 'King Shocks', 'slug' => 'king'],
        ['title' => 'Oracle', 'slug' => 'oracle'],
        ['title' => 'Road Armor', 'slug' => 'road-armor'],
        ['title' => 'WeatherTech', 'slug' => 'weathertech'],
    ];
}

function sa_term_link(string $taxonomy, string $slug): string
{
    $term = get_term_by('slug', $slug, $taxonomy);
    if ($term && !is_wp_error($term)) {
        $link = get_term_link($term);
        if (!is_wp_error($link)) {
            return $link;
        }
    }
    if (function_exists('wc_get_page_permalink')) {
        return add_query_arg('product_cat', $slug, wc_get_page_permalink('shop'));
    }
    return home_url('/product-category/' . rawurlencode($slug) . '/');
}

/**
 * Formatted free-shipping threshold for banners.
 *
 * Stored / env value is USD (checkout currency). Geo display converts via wc_price.
 * Default 99 keeps a clean round local amount after FX (no marketplace-looking decimals).
 */
function sa_free_shipping_threshold(): string
{
    $raw = getenv('SUPREME_FREE_SHIPPING_THRESHOLD');
    if ($raw === false || $raw === '') {
        $raw = get_option('sa_free_shipping_threshold', '99');
    }
    $usd = (float) $raw;
    // Guard against legacy KES-mistaken-as-USD values (e.g. 15000 → ~KES 1.9M).
    if ($usd > 1000) {
        $usd = 99.0;
    }
    $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD';
    if (function_exists('wc_price')) {
        return wp_strip_all_tags(wc_price($usd, ['decimals' => 0]));
    }
    return $currency . ' ' . number_format($usd, 0);
}

/**
 * Representative category-tile photos: department slug => product slug whose real
 * photo represents the department on the home grid and on /categories/.
 */
function sa_category_tile_products(): array
{
    return [
        'engine' => 'turbosmart-ts-1-turbocharger-6466-v-band-1-07ar-externally-wastegated-reversed-rotation',
        'interior' => 'sparco-seat-sprint-red',
        'exterior' => 'ford-racing-mustang-fp350s-rear-wing-kit',
        'exhaust' => 'corsa-25-26-ram-1500-rho-crew-cab-3-0l-turbo-cat-back-dual-rear-exit-w-5in-single-satin-pro-series',
    ];
}

/** Shopify CDN photo URL for a category tile, or '' when none is mapped or resolvable. */
function sa_category_tile_image(string $slug): string
{
    $map = sa_category_tile_products();
    if (!isset($map[$slug])) {
        return '';
    }
    $pp = get_page_by_path($map[$slug], OBJECT, 'product');
    $pr = $pp ? wc_get_product($pp->ID) : null;
    if (!$pr || !$pr->is_visible()) {
        return '';
    }
    $src = (string) $pr->get_meta('_sa_shopify_image_src', true);
    return str_starts_with($src, 'https://cdn.shopify.com/') ? $src : '';
}

/**
 * Homepage "Latest Parts" — mega-store merchandising across car-part departments.
 *
 * Round-robins parent categories (Brakes, Suspension, Engine, …), requires a real
 * photo, and dedupes by normalized title so near-identical springs/clones never
 * spam the grid. Recency is a tie-breaker inside each department, not the only sort.
 *
 * @return array<int, \WC_Product>
 */
function sa_get_homepage_latest_products(int $limit = 8): array
{
    $limit = max(1, min(24, $limit));
    if (!function_exists('wc_get_products')) {
        return [];
    }

    $title_key = static function ($product): string {
        return sa_product_loop_fingerprint($product);
    };

    $has_image = static function ($product): bool {
        if ((int) $product->get_image_id() > 0) {
            return true;
        }
        $cdn = trim((string) $product->get_meta('_sa_shopify_image_src', true));
        return $cdn !== '' && str_starts_with($cdn, 'http');
    };

    $seen_titles = [];
    $accept = static function ($product) use ($has_image, $title_key, &$seen_titles): bool {
        if (!$product instanceof \WC_Product || !$product->is_visible()) {
            return false;
        }
        if (!$has_image($product)) {
            return false;
        }
        $tk = $title_key($product);
        if (isset($seen_titles[$tk])) {
            return false;
        }
        $seen_titles[$tk] = true;
        return true;
    };

    $queues = []; // slug => list of WC_Product

    $departments = function_exists('sa_product_types') ? sa_product_types() : [];
    foreach ($departments as $dept) {
        $slug = sanitize_title((string) ($dept['slug'] ?? ''));
        if ($slug === '') {
            continue;
        }
        $term = get_term_by('slug', $slug, 'product_cat');
        if (!$term || is_wp_error($term)) {
            continue;
        }
        $batch = wc_get_products([
            'limit'    => 12,
            'status'   => 'publish',
            'orderby'  => 'date',
            'order'    => 'DESC',
            'type'     => ['simple', 'variable', 'external', 'grouped'],
            'category' => [$slug],
            'return'   => 'objects',
        ]);
        $q = [];
        foreach ($batch as $product) {
            if ($accept($product)) {
                $q[] = $product;
            }
        }
        if ($q) {
            $queues[$slug] = $q;
        }
    }

    $picked = [];
    // Round-robin across departments — AutoZone / RockAuto style aisle mix.
    while (count($picked) < $limit && $queues) {
        $progress = false;
        foreach (array_keys($queues) as $slug) {
            if (count($picked) >= $limit) {
                break;
            }
            if (empty($queues[$slug])) {
                unset($queues[$slug]);
                continue;
            }
            $picked[] = array_shift($queues[$slug]);
            $progress = true;
            if (empty($queues[$slug])) {
                unset($queues[$slug]);
            }
        }
        if (!$progress) {
            break;
        }
    }

    // Fallback fill from recent catalog if some departments were empty.
    if (count($picked) < $limit) {
        $extra = wc_get_products([
            'limit'   => max(48, $limit * 12),
            'status'  => 'publish',
            'orderby' => 'date',
            'order'   => 'DESC',
            'type'    => ['simple', 'variable', 'external', 'grouped'],
            'return'  => 'objects',
        ]);
        foreach ($extra as $product) {
            if (count($picked) >= $limit) {
                break;
            }
            if ($accept($product)) {
                $picked[] = $product;
            }
        }
    }

    return $picked;
}


/**
 * Published WooCommerce product count (real catalog size).
 */
function sa_published_product_count(): int
{
    if (!post_type_exists('product')) {
        return 0;
    }
    $counts = wp_count_posts('product');
    if (!$counts || !isset($counts->publish)) {
        return 0;
    }
    return max(0, (int) $counts->publish);
}

function sa_page_url(string $slug): string
{
    $page = get_page_by_path($slug);
    return $page ? get_permalink($page) : home_url('/' . $slug . '/');
}

/**
 * Brand logos hotlinked from the source store's CDN, keyed by catalog brand slug.
 * supreme-mods.com only ships curated logos for these brands; every other brand
 * gets a real product photo instead (see sa_category_brand_picker()).
 */
function sa_brand_logo_map(): array
{
    $b = 'https://supreme-mods.com/cdn/shop/t/6/assets/';
    return [
        'act'               => $b . 'act-clutch.jpg?v=877660881676118800417120',
        'afe'               => $b . 'afe-power.jpg?v=7379905215355194742171204',
        'awe-tuning'        => $b . 'awe.jpg?v=58668561627008722301712041050',
        'bilstein'          => $b . 'bilstein-logo.jpg?v=106208031347697701921',
        'bushwacker'        => $b . 'bushwacker.jpg?v=793193105674690329717120',
        'corsa-performance' => $b . 'corsa.jpg?v=11053963790621030875171204105',
        'ebc'               => $b . 'ebc-brakes.jpg?v=112805996274052291761712',
        'fox'               => $b . 'fox.jpg?v=56675246140297125901712041050',
        'garrett'           => $b . 'garrett-logo.jpg?v=1447387850268329355317',
        'king-shocks'       => $b . 'king-shocks.jpg?v=17573415329741842147171',
        'oracle-lighting'   => $b . 'oracle-lighting.jpg?v=1528571330345682071',
        'road-armor'        => $b . 'road-armor.jpg?v=166739901789122643117120',
        'weathertech'       => $b . 'weathertech.jpg?v=94333569090744589461712',
    ];
}

/** Hand-picked picture overrides for brands whose auto-picked photo is unsuitable. */
function sa_brand_image_overrides(): array
{
    return [
        // AlphaRex's top-ranked catalog image is a Prop 65 warning graphic; use its brand logo.
        'alpharex' => 'https://cdn.shopify.com/s/files/1/0643/0779/0001/files/0287070f7de7dcad92713342a8224b46L_0391544c-8ab3-411e-8adc-5c50fef5647c.png?v=1784492022',
    ];
}

/** Shopify CDN photo URL for a product id, or '' when none is available. */
function sa_brand_product_photo(int $product_id): string
{
    if (!function_exists('wc_get_product')) {
        return '';
    }
    $p = wc_get_product($product_id);
    if (!$p) {
        return '';
    }
    $src = (string) $p->get_meta('_sa_shopify_image_src', true);
    if (str_starts_with($src, 'https://cdn.shopify.com/')) {
        return $src;
    }
    $att = (int) $p->get_image_id();
    return $att > 0 ? (string) wp_get_attachment_image_url($att, 'medium') : '';
}

/** First photographed product of a brand inside a category (both product_cat slugs). */
function sa_brand_fallback_photo(string $brand_slug, string $category_slug): string
{
    if (!function_exists('wc_get_products')) {
        return '';
    }
    $ids = wc_get_products([
        'limit'    => 8,
        'status'   => 'publish',
        'return'   => 'ids',
        'tax_query' => [
            'relation' => 'AND',
            ['taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => [$category_slug]],
            ['taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => [$brand_slug]],
        ],
    ]);
    foreach ((array) $ids as $pid) {
        $img = sa_brand_product_photo((int) $pid);
        if ($img !== '') {
            return $img;
        }
    }
    return '';
}

/**
 * Brands with products in a product category (subcategories included), each with a
 * picture and product count, for the "Shop by brand" picker on category pages.
 * Counts come from the same facet index as the sidebar brand filter, so the picker
 * always matches what filtering by that brand returns.
 *
 * @return list<array{slug:string,name:string,count:int,image:string}>
 */
function sa_category_brand_picker(WP_Term $term): array
{
    global $wpdb;
    if (!function_exists('sa_fx_table') || !function_exists('sa_fx_ready') || !sa_fx_ready()) {
        return [];
    }
    $cache_key = 'sa_bpick_v2_' . (int) $term->term_id;
    $hit = get_transient($cache_key);
    if (is_array($hit)) {
        return $hit;
    }
    $fx = sa_fx_table();
    // Ancestor category ids are indexed per product, so one term id covers the
    // whole subtree. COUNT(DISTINCT) guards against multi-brand products.
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT i.value AS slug, MAX(i.label) AS name, COUNT(DISTINCT i.product_id) AS c
           FROM {$fx} i
          WHERE i.facet = 'brand'
            AND i.product_id IN (SELECT product_id FROM {$fx} WHERE facet = 'cat' AND value = %s)
          GROUP BY i.value
          ORDER BY c DESC, name ASC
          LIMIT 400",
        (string) $term->term_id
    ));
    if (!$rows) {
        return [];
    }

    // Best photographed product per brand, ranked by the existing smart-rank score.
    $photo_of = [];
    if (function_exists('sa_rk_t') && function_exists('sa_rk_ready') && sa_rk_ready() && function_exists('sa_rk_cat_maps')) {
        [$parent] = sa_rk_cat_maps();
        $ids = [(int) $term->term_id];
        $stack = [(int) $term->term_id];
        while ($stack !== []) {
            $tid = array_pop($stack);
            foreach ($parent as $cid => $pid2) {
                if ((int) $pid2 === (int) $tid) {
                    $ids[] = (int) $cid;
                    $stack[] = (int) $cid;
                }
            }
        }
        $in = implode(',', array_unique(array_map('intval', $ids)));
        $rs = sa_rk_t('sa_rank_score');
        // phpcs:ignore WordPress.DB.PreparedSQL — $in is ints only.
        foreach ((array) $wpdb->get_results("SELECT product_id, brand FROM {$rs} WHERE img = 1 AND leaf IN ({$in}) ORDER BY score DESC LIMIT 3000") as $r) {
            if (!isset($photo_of[(string) $r->brand])) {
                $photo_of[(string) $r->brand] = (int) $r->product_id;
            }
        }
    }

    $logos = sa_brand_logo_map();
    $overrides = sa_brand_image_overrides();
    $out = [];
    foreach ($rows as $r) {
        $slug = (string) $r->slug;
        $img = $overrides[$slug] ?? ($logos[$slug] ?? '');
        if ($img === '' && isset($photo_of[$slug])) {
            $img = sa_brand_product_photo($photo_of[$slug]);
        }
        if ($img === '') {
            $img = sa_brand_fallback_photo($slug, (string) $term->slug);
        }
        $name = trim((string) $r->name);
        $out[] = [
            'slug'  => $slug,
            'name'  => $name !== '' ? $name : ucwords(str_replace('-', ' ', $slug)),
            'count' => (int) $r->c,
            'image' => $img,
        ];
    }
    set_transient($cache_key, $out, 6 * HOUR_IN_SECONDS);
    return $out;
}

/** Render the "Shop <Category> by brand" picker on a product-category archive. */
function sa_render_category_brand_picker(WP_Term $term): void
{
    $items = sa_category_brand_picker($term);
    if ($items === []) {
        return;
    }
    $base = get_term_link($term);
    if (is_wp_error($base)) {
        return;
    }
    ?>
    <section class="sa-ed-sec sa-brand-picker" aria-labelledby="sa-brand-pick-h">
      <div class="sa-container">
        <div class="sa-ed-head"><h2 id="sa-brand-pick-h"><?php echo esc_html(sprintf(/* translators: %s: category name */ __('Shop %s by brand', 'supreme-autoparts'), $term->name)); ?></h2></div>
        <div class="sa-ed-cats">
          <?php foreach ($items as $it) : ?>
            <a class="sa-ed-cat" href="<?php echo esc_url(add_query_arg('filter_brand', $it['slug'], $base)); ?>">
              <span class="sa-ed-im">
                <?php if ($it['image'] !== '') : ?>
                  <img src="<?php echo esc_url($it['image']); ?>" alt="" width="640" height="640" loading="lazy" decoding="async" />
                <?php endif; ?>
              </span>
              <span class="sa-ed-cat__n"><?php echo esc_html($it['name']); ?></span>
              <span class="sa-ed-cat__c"><?php echo esc_html(sprintf(/* translators: %s: product count */ _n('%s part', '%s parts', $it['count'], 'supreme-autoparts'), number_format_i18n($it['count']))); ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
    <?php
}
