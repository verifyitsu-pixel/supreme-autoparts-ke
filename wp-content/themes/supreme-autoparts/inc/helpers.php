<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Product-type tiles for the homepage.
 *
 * @return array<int, array{title:string,slug:string,icon:string}>
 */
function sa_product_types(): array
{
    return [
        ['title' => 'Air Intake', 'slug' => 'air-intake', 'icon' => '🌀'],
        ['title' => 'Brakes', 'slug' => 'brakes', 'icon' => '🛑'],
        ['title' => 'Drivetrain', 'slug' => 'drivetrain', 'icon' => '⚙️'],
        ['title' => 'Engine', 'slug' => 'engine', 'icon' => '🔧'],
        ['title' => 'Exhaust', 'slug' => 'exhaust', 'icon' => '💨'],
        ['title' => 'Exterior', 'slug' => 'exterior', 'icon' => '🚗'],
        ['title' => 'Interior', 'slug' => 'interior', 'icon' => '🪑'],
        ['title' => 'Lighting', 'slug' => 'lighting', 'icon' => '💡'],
        ['title' => 'Suspension', 'slug' => 'suspension', 'icon' => '↕️'],
        ['title' => 'Tires', 'slug' => 'tires', 'icon' => '⭕'],
        ['title' => 'Wheels', 'slug' => 'wheels', 'icon' => '🛞'],
    ];
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
        return add_query_arg('s', $slug, wc_get_page_permalink('shop'));
    }
    return home_url('/shop/');
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
