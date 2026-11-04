<?php
/** Display/search budgets only. Catalogue storage and order maths remain USD. */
declare(strict_types=1);
defined('ABSPATH') || exit;
function sa_catalog_display_currency(): string {
    $cur = function_exists('sa_storefront_country') && sa_storefront_country() === 'KE' ? 'KES' : 'USD';
    return $cur === 'KES' && class_exists('SA_FX_Rates') && SA_FX_Rates::rate_for('KES') > 0 ? 'KES' : 'USD';
}
function sa_catalog_price_from_usd(float $usd): float {
    return sa_catalog_display_currency() === 'KES' ? $usd * (float)SA_FX_Rates::rate_for('KES') : $usd;
}
function sa_catalog_price_to_usd(float $amount): float {
    return sa_catalog_display_currency() === 'KES' ? $amount / (float)SA_FX_Rates::rate_for('KES') : $amount;
}
// Woo reads min/max directly from GET. Keep the local inputs for controls/chips,
// and give Woo the equivalent stored-USD bounds before its catalogue query runs.
add_action('pre_get_posts', static function($query): void {
    if (!$query->is_main_query() || !empty($GLOBALS['sa_catalog_local_prices'])) return;
    if (is_admin() || wp_doing_ajax() || is_checkout() || is_cart() || is_account_page()) return;
    if (!function_exists('sa_core_is_catalog_product_query') || !sa_core_is_catalog_product_query($query)) return;
    if (sa_catalog_display_currency() !== 'KES') return;
    foreach (['min_price','max_price'] as $key) {
        if (isset($_GET[$key]) && is_scalar($_GET[$key]) && is_numeric($_GET[$key])) {
            $GLOBALS['sa_catalog_local_prices'][$key] = (string)$_GET[$key];
            $_GET[$key] = (string)sa_catalog_price_to_usd(max(0,(float)$_GET[$key]));
        }
    }
}, 0);

// Once the main query has used stored-USD bounds, all rendered forms and URLs
// must keep the local budget. Otherwise changing sort submits USD as KES.
add_action('wp', static function(): void {
    foreach (($GLOBALS['sa_catalog_local_prices'] ?? []) as $key => $value) {
        $_GET[$key] = $value;
    }
}, 0);
