<?php
/**
 * Enquire-when-unavailable: empty catalog, OOS, product 404, standing page.
 *
 * @package Supreme_Autoparts
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Store contact channels for part enquiries.
 *
 * @return array{phone:string,phone_display:string,whatsapp:string,email:string}
 */
function sa_enquire_contact(): array
{
    return [
        'phone'         => '+254714498451',
        'phone_display' => '+254 714 498 451',
        'whatsapp'      => '254714498451',
        'email'         => 'calvin@supremeautoparts.co.ke',
    ];
}

/**
 * Humanize a URL slug into a product-name guess.
 */
function sa_enquire_humanize_slug(string $slug): string
{
    $slug = rawurldecode($slug);
    $slug = preg_replace('/\.(html?|php)$/i', '', $slug) ?? $slug;
    $slug = str_replace(['-', '_', '+'], ' ', $slug);
    $slug = preg_replace('/\s+/', ' ', trim($slug)) ?? '';
    if ($slug === '') {
        return '';
    }
    return ucwords(strtolower($slug));
}

/**
 * Detect product-like path from the current request (for 404 prefill).
 *
 * @return array{product:string,context:string}
 */
function sa_enquire_prefill_from_request(): array
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $path = (string) (wp_parse_url($uri, PHP_URL_PATH) ?: '');
    $path = trim($path, '/');

    $product = '';
    $context = '404';

    if (preg_match('#(?:^|/)product/([^/]+)#i', $path, $m)) {
        $product = sa_enquire_humanize_slug($m[1]);
        $context = 'product-404';
    } elseif (preg_match('#(?:^|/)(?:shop|products)/([^/]+)#i', $path, $m)) {
        $leaf = $m[1];
        if (!in_array(strtolower($leaf), ['page', 'feed'], true)) {
            $product = sa_enquire_humanize_slug($leaf);
            $context = 'product-404';
        }
    } elseif (isset($_GET['s']) && is_string($_GET['s']) && $_GET['s'] !== '') { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $product = sanitize_text_field(wp_unslash($_GET['s'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $context = 'search';
    }

    return [
        'product' => $product,
        'context' => $context,
    ];
}

/**
 * Whether the 404 request looks like a missing product / shop path.
 */
function sa_enquire_is_productish_404(): bool
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $path = (string) (wp_parse_url($uri, PHP_URL_PATH) ?: '');
    if (preg_match('#/(?:product|product-category|shop|products)(/|$)#i', $path)) {
        return true;
    }
    // Pretty permalinks sometimes leave post_type=product on 404.
    if (isset($_GET['post_type']) && (string) $_GET['post_type'] === 'product') { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return true;
    }
    return false;
}

/**
 * Build default message body from field values (shared by channels).
 *
 * @param array{product?:string,car?:string,brand?:string,year?:string,notes?:string} $fields
 */
function sa_enquire_message_body(array $fields): string
{
    $lines = [
        'Hi Supreme Autoparts — I am looking for a part:',
        '',
        'Product: ' . trim((string) ($fields['product'] ?? '')),
        'Car / model: ' . trim((string) ($fields['car'] ?? '')),
        'Brand: ' . trim((string) ($fields['brand'] ?? '')),
        'Year: ' . trim((string) ($fields['year'] ?? '')),
    ];
    $notes = trim((string) ($fields['notes'] ?? ''));
    if ($notes !== '') {
        $lines[] = 'Notes: ' . $notes;
    }
    $lines[] = '';
    $lines[] = 'Please let me know availability and price. Thank you.';
    return implode("\n", $lines);
}

/**
 * Render the enquire panel. Safe to call from templates and shortcodes.
 *
 * @param array{
 *   context?:string,
 *   title?:string,
 *   lead?:string,
 *   product?:string,
 *   car?:string,
 *   brand?:string,
 *   year?:string,
 *   notes?:string,
 *   compact?:bool
 * } $args
 */
function sa_render_enquire(array $args = []): void
{
    $contact = sa_enquire_contact();
    $context = (string) ($args['context'] ?? 'general');

    $defaults = [
        'title' => __('We are updating the catalogue', 'supreme-autoparts'),
        'lead'  => __('Need a part that is not listed or temporarily unavailable? Tell us what you need and we will help source it.', 'supreme-autoparts'),
    ];

    switch ($context) {
        case 'empty-shop':
        case 'empty-category':
            $defaults['title'] = __('We are updating the catalogue', 'supreme-autoparts');
            $defaults['lead']  = __('This section has no products to show right now. Enquire for the part you need — WhatsApp, SMS, or email.', 'supreme-autoparts');
            break;
        case 'outofstock':
        case 'unavailable':
            $defaults['title'] = __('This part is currently unavailable', 'supreme-autoparts');
            $defaults['lead']  = __('We are updating stock. Send your vehicle details and we will check availability for you.', 'supreme-autoparts');
            break;
        case 'product-404':
        case '404':
            $defaults['title'] = __('Part not found', 'supreme-autoparts');
            $defaults['lead']  = __('That listing is not available. Enquire below and we will help you find the right part.', 'supreme-autoparts');
            break;
        case 'account':
            $defaults['title'] = __('Can\'t find a part?', 'supreme-autoparts');
            $defaults['lead']  = __('Describe the part and your vehicle. We will reply with availability and pricing.', 'supreme-autoparts');
            break;
        default:
            break;
    }

    $title = (string) ($args['title'] ?? $defaults['title']);
    $lead  = (string) ($args['lead'] ?? $defaults['lead']);
    $compact = !empty($args['compact']);

    $prefill = [
        'product' => (string) ($args['product'] ?? ''),
        'car'     => (string) ($args['car'] ?? ''),
        'brand'   => (string) ($args['brand'] ?? ''),
        'year'    => (string) ($args['year'] ?? ''),
        'notes'   => (string) ($args['notes'] ?? ''),
    ];

    $uid = 'sa-enq-' . wp_unique_id();

    include SA_THEME_DIR . '/template-parts/enquire.php';
}

/**
 * Shortcode [sa_enquire] for the standing enquire page and embeds.
 *
 * @param array<string,string>|string $atts
 */
function sa_enquire_shortcode($atts = []): string
{
    $atts = shortcode_atts([
        'context' => 'general',
        'product' => '',
        'car'     => '',
        'brand'   => '',
        'year'    => '',
        'compact' => '',
    ], is_array($atts) ? $atts : [], 'sa_enquire');

    ob_start();
    sa_render_enquire([
        'context' => sanitize_key((string) $atts['context']),
        'product' => sanitize_text_field((string) $atts['product']),
        'car'     => sanitize_text_field((string) $atts['car']),
        'brand'   => sanitize_text_field((string) $atts['brand']),
        'year'    => sanitize_text_field((string) $atts['year']),
        'compact' => $atts['compact'] === '1' || $atts['compact'] === 'true',
    ]);
    return (string) ob_get_clean();
}
add_shortcode('sa_enquire', 'sa_enquire_shortcode');

/**
 * Empty shop / category: replace Woo bare message with enquire UI.
 */
add_action('init', static function (): void {
    remove_action('woocommerce_no_products_found', 'wc_no_products_found', 10);
    add_action('woocommerce_no_products_found', static function (): void {
        $context = 'empty-shop';
        if (function_exists('is_product_category') && is_product_category()) {
            $context = 'empty-category';
        } elseif (function_exists('is_product_tag') && is_product_tag()) {
            $context = 'empty-category';
        } elseif (function_exists('is_search') && is_search()) {
            $context = 'search';
        }
        $args = ['context' => $context];
        if (function_exists('is_search') && is_search()) {
            $args['product'] = get_search_query();
            $args['title']   = __('No matching parts in the catalogue', 'supreme-autoparts');
            $args['lead']    = __('We are updating listings. Enquire with your vehicle details and we will source what you need.', 'supreme-autoparts');
        }
        sa_render_enquire($args);
    }, 10);
}, 20);

/**
 * Out-of-stock / unavailable single product: show enquire block after summary actions.
 */
add_action('woocommerce_single_product_summary', static function (): void {
    if (!function_exists('wc_get_product')) {
        return;
    }
    global $product;
    if (!$product instanceof WC_Product) {
        $product = wc_get_product(get_the_ID());
    }
    if (!$product instanceof WC_Product) {
        return;
    }

    // Skip when the product can be bought normally.
    if ($product->is_in_stock() && $product->is_purchasable()) {
        return;
    }

    $brand = '';
    if (taxonomy_exists('product_brand')) {
        $terms = get_the_terms($product->get_id(), 'product_brand');
        if (is_array($terms) && $terms && !is_wp_error($terms)) {
            $brand = $terms[0]->name;
        }
    }
    if ($brand === '') {
        $attrs = $product->get_attribute('brand');
        if (is_string($attrs) && $attrs !== '') {
            $brand = $attrs;
        }
    }

    $context = !$product->is_in_stock() ? 'outofstock' : 'unavailable';
    sa_render_enquire([
        'context' => $context,
        'product' => $product->get_name(),
        'brand'   => $brand,
    ]);
}, 35);

/**
 * Permalink helper for the standing enquire page.
 */
function sa_enquire_page_url(): string
{
    return function_exists('sa_page_url') ? sa_page_url('enquire') : home_url('/enquire/');
}
