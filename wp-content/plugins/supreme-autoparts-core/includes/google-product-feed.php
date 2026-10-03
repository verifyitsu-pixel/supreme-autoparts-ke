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
}
add_action('init', 'sa_gpf_register', 20);

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

    return $markup;
}
add_filter('woocommerce_structured_data_product', 'sa_gpf_enrich_product_schema', 20, 2);

/**
 * Stream the Merchant Center feed.
 */
function sa_gpf_render(bool $for_comments = false): void
{
    if ($for_comments || !function_exists('wc_get_product')) {
        status_header(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Feed unavailable.';
        exit;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

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
    $availability = $product->is_in_stock() ? 'in_stock' : 'out_of_stock';
    $brand = sa_gpf_brand_name($product);
    $gtin = sa_gpf_gtin($product);
    $product_type = sa_gpf_product_type($product);

    echo "<item>\n";
    echo '<g:id>' . sa_gpf_xml($id) . "</g:id>\n";
    echo '<g:title>' . sa_gpf_xml($title) . "</g:title>\n";
    echo '<g:description>' . sa_gpf_xml($description) . "</g:description>\n";
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
    return number_format($amount, 2, '.', '') . ' USD';
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
        $text = $title . '. Auto part sold by Supreme Autoparts. USD checkout.';
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
