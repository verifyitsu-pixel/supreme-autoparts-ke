<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
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

function sa_free_shipping_threshold(): string
{
    $threshold = getenv('SUPREME_FREE_SHIPPING_THRESHOLD') ?: get_option('sa_free_shipping_threshold', '15000');
    $currency  = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'KES';
    if (function_exists('wc_price')) {
        return wp_strip_all_tags(wc_price((float) $threshold));
    }
    return $currency . ' ' . number_format((float) $threshold);
}

function sa_page_url(string $slug): string
{
    $page = get_page_by_path($slug);
    return $page ? get_permalink($page) : home_url('/' . $slug . '/');
}
