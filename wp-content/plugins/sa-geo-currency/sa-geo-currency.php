<?php
/**
 * Plugin Name: Supreme Geo Currency Display
 * Description: Display prices in visitor local currency (CF-IPCountry / geo fallback) while WooCommerce + Whop checkout remain USD.
 * Version: 1.1.6
 * Author: Supreme Autoparts
 * Text Domain: sa-geo-currency
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('SA_GEO_CURRENCY_VERSION', '1.1.6');
define('SA_GEO_CURRENCY_FILE', __FILE__);
define('SA_GEO_CURRENCY_DIR', plugin_dir_path(__FILE__));
define('SA_GEO_CURRENCY_URL', plugin_dir_url(__FILE__));

require_once SA_GEO_CURRENCY_DIR . 'includes/class-sa-geo-detector.php';
require_once SA_GEO_CURRENCY_DIR . 'includes/class-sa-fx-rates.php';
require_once SA_GEO_CURRENCY_DIR . 'includes/class-sa-geo-display.php';

/**
 * Feature flag: SA_GEO_CURRENCY=1 enables adaptive display.
 */

/**
 * Run a callback with storefront geo price conversion disabled.
 * Use for order emails, PDFs, receipts, hold requests, admin previews.
 *
 * @template T
 * @param callable():T $cb
 * @return T
 */
function sa_geo_without_conversion(callable $cb)
{
    if (class_exists('SA_Geo_Display')) {
        return SA_Geo_Display::without_conversion($cb);
    }
    return $cb();
}

function sa_geo_currency_enabled(): bool
{
    $env = getenv('SA_GEO_CURRENCY');
    if ($env === false || $env === '') {
        // Default on when plugin is active — env can force off with 0.
        return true;
    }
    return in_array(strtolower(trim((string) $env)), ['1', 'true', 'yes', 'on'], true);
}

/**
 * Store / order / Whop currency — always USD for this storefront.
 */
function sa_geo_checkout_currency(): string
{
    $env = getenv('SA_CHECKOUT_CURRENCY') ?: getenv('WOO_CURRENCY');
    if (is_string($env) && preg_match('/^[A-Za-z]{3}$/', trim($env))) {
        return strtoupper(trim($env));
    }
    return 'USD';
}

/**
 * Ensure Woo store currency stays checkout currency (USD). Idempotent.
 */
function sa_geo_enforce_store_currency(): void
{
    if (!function_exists('update_option')) {
        return;
    }
    $wanted = sa_geo_checkout_currency();
    if (get_option('woocommerce_currency') !== $wanted) {
        update_option('woocommerce_currency', $wanted);
    }
}

add_action('plugins_loaded', static function (): void {
    if (!class_exists('WooCommerce') && !function_exists('WC')) {
        return;
    }

    sa_geo_enforce_store_currency();

    // Never let display logic change order currency.
    add_filter('woocommerce_currency', static function (string $currency): string {
        // During order creation / payment / admin, force checkout currency.
        if (!sa_geo_currency_enabled()) {
            return sa_geo_checkout_currency();
        }
        // Keep store currency as USD always — display conversion is separate.
        return sa_geo_checkout_currency();
    }, 5);

    if (sa_geo_currency_enabled()) {
        SA_Geo_Display::init();
    }
}, 20);

add_action('init', static function (): void {
    sa_geo_enforce_store_currency();
}, 5);

// Soft bump so existing installs flip from KES → USD once.
add_action('init', static function (): void {
    if (get_option('sa_geo_currency_boot_ver') === '2') {
        return;
    }
    sa_geo_enforce_store_currency();
    update_option('sa_geo_currency_boot_ver', '2');
}, 25);

/**
 * Hard-guarantee Whop/Woo order currency is checkout currency (USD).
 */
/**
 * Snapshot display currency/total + force order currency USD for Whop reconciliation.
 */
function sa_geo_stamp_order_currency_meta($order): void
{
    if (!is_object($order) || !method_exists($order, 'set_currency')) {
        return;
    }
    // Open-pay orders are charged in the exact currency/amount the customer entered.
    if (method_exists($order, 'get_meta') && $order->get_meta('_sa_native_currency')) {
        return;
    }
    $checkout = sa_geo_checkout_currency();
    $order->set_currency($checkout);

    $display = $checkout;
    $display_total = null;
    if (class_exists('SA_Geo_Detector')) {
        $display = SA_Geo_Detector::display_currency();
    }
    $usd_total = method_exists($order, 'get_total') ? (float) $order->get_total() : 0.0;
    if (class_exists('SA_FX_Rates') && strtoupper($display) !== strtoupper($checkout)) {
        $converted = SA_FX_Rates::convert_usd($usd_total, $display);
        if ($converted !== null) {
            $display_total = $converted;
        }
    }
    if ($display_total === null) {
        $display_total = $usd_total;
        $display = $checkout;
    }

    if (method_exists($order, 'update_meta_data')) {
        $order->update_meta_data('_sa_display_currency', strtoupper((string) $display));
        $order->update_meta_data('_sa_display_total', (string) round((float) $display_total, 4));
        $order->update_meta_data('_sa_charged_usd', (string) round($usd_total, 2));
        if (class_exists('SA_Geo_Detector')) {
            $order->update_meta_data('_sa_geo_country', SA_Geo_Detector::country_code());
        }
    }
}

add_action('woocommerce_checkout_create_order', static function ($order): void {
    sa_geo_stamp_order_currency_meta($order);
}, 5);

// Also stamp when payment starts (covers admin / open-pay / API-created orders).
add_action('woocommerce_before_pay_action', static function ($order): void {
    sa_geo_stamp_order_currency_meta($order);
}, 5);


/**
 * Visitor zone helpers used by theme/core to tailor Kenya-only services.
 * KE = Kenya (Fargo, store pickup, Nairobi garage + roadside). Everyone else = international.
 */
function sa_geo_country(): string
{
    return class_exists('SA_Geo_Detector') ? strtoupper((string) SA_Geo_Detector::country_code()) : 'KE';
}

function sa_geo_is_kenya(): bool
{
    return sa_geo_country() === 'KE';
}

/** Cache-key fragment so any cached HTML containing prices/zone copy never crosses zones. */
function sa_geo_zone_key(): string
{
    $cur = class_exists('SA_Geo_Detector') ? SA_Geo_Detector::display_currency() : 'USD';
    return sa_geo_country() . '|' . $cur;
}

/**
 * Safety net for Kenyan visitors: any hard-coded US support line left in page copy,
 * seeded pages or emails is swapped for the Kenyan line. (Content is gated per zone
 * at the source; this catches stragglers so Kenya never sees +1 917.)
 */
function sa_geo_ke_swap(string $html): string
{
    return strtr($html, [
        '+1 917 437 5121' => '+254 714 498 451',
        '+1&nbsp;917&nbsp;437&nbsp;5121' => '+254&nbsp;714&nbsp;498&nbsp;451',
        '+19174375121'    => '+254714498451',
        '19174375121'     => '254714498451',
        '(917) 437-5121'  => '+254 714 498 451',
    ]);
}

add_action('template_redirect', static function (): void {
    if (is_admin() || wp_doing_ajax() || is_feed() || (defined('REST_REQUEST') && REST_REQUEST) || is_robots()) {
        return;
    }
    if (!sa_geo_is_kenya()) {
        return;
    }
    ob_start('sa_geo_ke_swap');
}, 0);

// Woo cart/checkout fragments and our REST recs are HTML too.
add_filter('woocommerce_add_to_cart_fragments', static function ($f) {
    if (!is_array($f) || !sa_geo_is_kenya()) { return $f; }
    return array_map(static fn ($v) => is_string($v) ? sa_geo_ke_swap($v) : $v, $f);
}, 999);
add_filter('woocommerce_update_order_review_fragments', static function ($f) {
    if (!is_array($f) || !sa_geo_is_kenya()) { return $f; }
    return array_map(static fn ($v) => is_string($v) ? sa_geo_ke_swap($v) : $v, $f);
}, 999);

add_filter('wp_mail', static function (array $a): array {
    // Customer emails sent while a Kenyan visitor is checking out / booking.
    if (!is_admin() && !wp_doing_cron() && sa_geo_is_kenya() && isset($a['message']) && is_string($a['message'])) {
        $a['message'] = sa_geo_ke_swap($a['message']);
    }
    return $a;
}, 999);

add_action('send_headers', static function (): void {
    if (!headers_sent()) {
        header('Vary: X-Country-Code', false);
    }
}, 20);

/**
 * Business contact block per country. Kenya: registered Nairobi shop/garage.
 * Everyone else (US and rest of world): Portland, OR office + US line.
 *
 * @return array{zone:string,name:string,street:string,city:string,region:string,postal:string,country:string,country_name:string,one_line:string,map_q:string,phone:string,phone_display:string,whatsapp:string,email:string}
 */
function sa_geo_business(?string $cc = null): array
{
    $cc = strtoupper($cc ?? sa_geo_country());
    $email = 'calvin@supremeautoparts.co.ke';
    if ($cc === 'KE') {
        return [
            'zone' => 'KE', 'name' => 'Supreme Autoparts',
            'street' => 'Ground Floor, Midax Plaza, Kangundo Rd, Njiru', 'city' => 'Nairobi', 'region' => 'Nairobi', 'postal' => '00100',
            'country' => 'KE', 'country_name' => 'Kenya',
            'one_line' => 'Ground Floor, Midax Plaza, Kangundo Rd, Njiru, Nairobi, Kenya',
            'map_q' => 'Midax Plaza, Kangundo Rd, Njiru, Nairobi',
            'phone' => '+254714498451', 'phone_display' => '+254 714 498 451', 'whatsapp' => '254714498451', 'email' => $email,
        ];
    }
    return [
        'zone' => $cc === 'US' ? 'US' : 'ROW', 'name' => 'Supreme Autoparts',
        'street' => '12800 NE Sandy Blvd', 'city' => 'Portland', 'region' => 'OR', 'postal' => '97230',
        'country' => 'US', 'country_name' => 'United States',
        'one_line' => '12800 NE Sandy Blvd, Portland, OR 97230',
        'map_q' => '12800 NE Sandy Blvd, Portland, OR 97230',
        'phone' => '+19174375121', 'phone_display' => '+1 (917) 437-5121', 'whatsapp' => '19174375121', 'email' => $email,
    ];
}

function sa_geo_map_url(?string $cc = null): string
{
    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(sa_geo_business($cc)['map_q']);
}

/* Rock 2026-10-17: customer-facing WhatsApp entry points use the business username
   link (wa.me/<username>, per WhatsApp official FAQ faq.whatsapp.com/1561101675623754)
   and show a "Chat on WhatsApp" button - the number/username is never displayed.
   tel: call links keep the numbers. Glyph extracted from WhatsApp-served wa.me page. */
if (!function_exists('sa_whatsapp_username')) {
    function sa_whatsapp_username(): string {
        return 'supremeautopart';
    }
}
if (!function_exists('sa_whatsapp_url')) {
    function sa_whatsapp_url(string $prefill = ''): string {
        $u = 'https://wa.me/' . sa_whatsapp_username();
        return $prefill === '' ? $u : $u . '?text=' . rawurlencode($prefill);
    }
}
if (!function_exists('sa_whatsapp_icon')) {
    function sa_whatsapp_icon(): string {
        return '<svg class="sa-wa-ic" viewBox="0 0 1024 1024" fill="none" aria-hidden="true" focusable="false"><rect width="1024" height="1024" rx="265" fill="#25D366"></rect><path d="M522 141C320.419 141 157 304.419 157 506C157 572.322 174.689 634.507 205.606 688.101L157 871L346.617 826.173C398.664 854.744 458.429 871 522 871C723.581 871 887 707.581 887 506C887 304.419 723.581 141 522 141ZM522 805.624C460.998 805.624 404.254 787.388 356.919 756.079L244.891 784.61L276.314 677.536C242.322 628.944 222.376 569.801 222.376 506C222.376 340.52 356.52 206.376 522 206.376C687.48 206.376 821.624 340.52 821.624 506C821.624 671.48 687.48 805.624 522 805.624Z" fill="#fff"></path><path d="M607.527 554.187L695.836 595.825C699.892 597.737 702.488 601.847 702.123 606.315C701.163 617.934 696.506 641.226 675.626 662.099C616.692 721.033 510.876 654.36 506.577 651.778C480.554 637.799 455.815 619.09 432.374 595.642C408.933 572.201 390.217 547.462 376.238 521.439C373.656 517.14 306.983 411.317 365.917 352.39C386.796 331.51 410.082 326.853 421.701 325.893C426.169 325.528 430.279 328.124 432.192 332.18L473.829 420.489C475.802 424.667 474.937 429.635 471.666 432.899L440.627 463.938C433.915 470.65 431.942 481.1 436.565 489.393C447.887 509.705 463.115 529.259 480.764 547.252C498.757 564.894 518.318 580.129 538.623 591.451C546.917 596.075 557.366 594.108 564.078 587.389L595.117 556.35C598.381 553.086 603.35 552.221 607.527 554.187Z" fill="#fff"></path></svg>';
    }
}

if (!function_exists('sa_instagram_url')) {
    function sa_instagram_url(): string {
        return 'https://www.instagram.com/standarddriveworks/';
    }
}
if (!function_exists('sa_instagram_icon')) {
    function sa_instagram_icon(): string {
        return '<svg class="sa-ig-ic" viewBox="0 0 448 512" fill="currentColor" aria-hidden="true" focusable="false"><path d="M224.1 141c-63.6 0-114.9 51.3-114.9 114.9s51.3 114.9 114.9 114.9S339 319.5 339 255.9 287.7 141 224.1 141zm0 189.6c-41.1 0-74.7-33.5-74.7-74.7s33.5-74.7 74.7-74.7 74.7 33.5 74.7 74.7-33.6 74.7-74.7 74.7zm146.4-194.3c0 14.9-12 26.8-26.8 26.8-14.9 0-26.8-12-26.8-26.8s12-26.8 26.8-26.8 26.8 12 26.8 26.8zm76.1 27.2c-1.7-35.9-9.9-67.7-36.2-93.9-26.2-26.2-58-34.4-93.9-36.2-37-2.1-147.9-2.1-184.9 0-35.8 1.7-67.6 9.9-93.9 36.1s-34.4 58-36.2 93.9c-2.1 37-2.1 147.8 0 184.8 1.7 35.9 9.9 67.7 36.2 93.9s58 34.4 93.9 36.2c37 2.1 147.9 2.1 184.9 0 35.9-1.7 67.7-9.9 93.9-36.2 26.2-26.2 34.4-58 36.2-93.9 2.1-37 2.1-147.8 0-184.8zM398.8 388c-7.8 19.6-22.9 34.7-42.6 42.6-29.5 11.7-99.5 9-132.1 9s-102.7 2.6-132.1-9c-19.6-7.8-34.7-22.9-42.6-42.6-11.7-29.5-9-99.5-9-132.1s-2.6-102.7 9-132.1c7.8-19.6 22.9-34.7 42.6-42.6 29.5-11.7 99.5-9 132.1-9s102.7-2.6 132.1 9c19.6 7.8 34.7 22.9 42.6 42.6 11.7 29.5 9 99.5 9 132.1s2.7 102.7-9 132.1z"/></svg>';
    }
}
