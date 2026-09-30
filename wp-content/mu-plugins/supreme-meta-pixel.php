<?php
/**
 * Plugin Name: Supreme Meta Pixel
 * Description: Meta Pixel 1455607103130157 — PageView + WooCommerce Purchase / ATC / InitiateCheckout / ViewContent / Search.
 * Version: 1.1.0
 * Author: Supreme Autoparts
 *
 * Pixel ID: 1455607103130157
 * Events Manager: https://business.facebook.com/events_manager2
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const SA_META_PIXEL_ID = '1455607103130157';

function sa_meta_pixel_should_inject(): bool
{
    if (is_admin()) {
        return false;
    }
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return false;
    }
    if (defined('DOING_AJAX') && DOING_AJAX) {
        return false;
    }
    if (defined('DOING_CRON') && DOING_CRON) {
        return false;
    }
    if (function_exists('is_feed') && is_feed()) {
        return false;
    }

    return true;
}

/**
 * Escape a value for inline JS (JSON).
 */
function sa_meta_pixel_js($value): string
{
    return wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

add_action('wp_head', static function (): void {
    if (!sa_meta_pixel_should_inject()) {
        return;
    }

    $id = SA_META_PIXEL_ID;
    echo <<<HTML
<!-- Meta Pixel Code -->
<script>
!function(f,b,e,v,n,t,s)
{if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};
if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];
s.parentNode.insertBefore(t,s)}(window, document,'script',
'https://connect.facebook.net/en_US/fbevents.js');
fbq('init', '{$id}');
fbq('track', 'PageView');
</script>
<!-- End Meta Pixel Code -->

HTML;
}, 5);

add_action('wp_body_open', static function (): void {
    if (!sa_meta_pixel_should_inject()) {
        return;
    }

    $id = SA_META_PIXEL_ID;
    echo <<<HTML
<!-- Meta Pixel noscript -->
<noscript><img height="1" width="1" style="display:none"
src="https://www.facebook.com/tr?id={$id}&ev=PageView&noscript=1"
/></noscript>

HTML;
}, 5);

/**
 * ViewContent on single product pages.
 */
add_action('wp_footer', static function (): void {
    if (!sa_meta_pixel_should_inject() || !function_exists('is_product') || !is_product()) {
        return;
    }
    $product = wc_get_product(get_the_ID());
    if (!$product) {
        return;
    }
    $payload = [
        'content_ids'  => [(string) $product->get_id()],
        'content_name' => $product->get_name(),
        'content_type' => 'product',
        'content_ids_sku' => [(string) $product->get_sku()],
        'value'        => (float) $product->get_price(),
        'currency'     => get_woocommerce_currency(),
    ];
    // Meta wants content_ids; drop helper key from payload
    unset($payload['content_ids_sku']);
    if ($product->get_sku()) {
        $payload['content_ids'] = [(string) $product->get_sku()];
    }
    echo '<script>fbq("track","ViewContent",' . sa_meta_pixel_js($payload) . ');</script>' . "\n";
}, 20);

/**
 * Search event when a product search is performed.
 */
add_action('wp_footer', static function (): void {
    if (!sa_meta_pixel_should_inject()) {
        return;
    }
    $q = isset($_GET['s']) ? sanitize_text_field(wp_unslash((string) $_GET['s'])) : '';
    if ($q === '') {
        return;
    }
    // Only fire on search / shop filter contexts
    $is_search = function_exists('is_search') && is_search();
    $on_shop = function_exists('is_shop') && is_shop();
    if (!$is_search && !$on_shop) {
        return;
    }
    $payload = ['search_string' => $q, 'content_category' => 'product'];
    echo '<script>fbq("track","Search",' . sa_meta_pixel_js($payload) . ');</script>' . "\n";
}, 21);

/**
 * AddToCart — classic + AJAX.
 */
add_action('woocommerce_add_to_cart', static function ($cart_item_key, $product_id, $quantity): void {
    if (!sa_meta_pixel_should_inject()) {
        return;
    }
    $product = wc_get_product($product_id);
    if (!$product) {
        return;
    }
    $cid = $product->get_sku() !== '' ? (string) $product->get_sku() : (string) $product_id;
    $payload = [
        'content_ids'  => [$cid],
        'content_name' => $product->get_name(),
        'content_type' => 'product',
        'value'        => (float) $product->get_price() * (float) $quantity,
        'currency'     => get_woocommerce_currency(),
        'contents'     => [['id' => $cid, 'quantity' => (int) $quantity]],
    ];
    // Stash for footer print (add_to_cart often redirects)
    $pending = WC()->session ? (array) WC()->session->get('sa_meta_pending_atc', []) : [];
    $pending[] = $payload;
    if (WC()->session) {
        WC()->session->set('sa_meta_pending_atc', $pending);
    }
}, 10, 3);

add_action('wp_footer', static function (): void {
    if (!sa_meta_pixel_should_inject() || !function_exists('WC') || !WC()->session) {
        return;
    }
    $pending = (array) WC()->session->get('sa_meta_pending_atc', []);
    if ($pending === []) {
        return;
    }
    WC()->session->set('sa_meta_pending_atc', []);
    foreach ($pending as $payload) {
        echo '<script>fbq("track","AddToCart",' . sa_meta_pixel_js($payload) . ');</script>' . "\n";
    }
}, 22);

/** AJAX AddToCart listener (client-side) */
add_action('wp_footer', static function (): void {
    if (!sa_meta_pixel_should_inject() || !function_exists('is_woocommerce')) {
        return;
    }
    $currency = sa_meta_pixel_js(get_woocommerce_currency());
    echo <<<HTML
<script>
(function(){
  if (typeof jQuery === 'undefined' || typeof fbq !== 'function') return;
  jQuery(document.body).on('added_to_cart', function(_e, _frags, _hash, \$btn){
    try {
      var id = (\$btn && \$btn.data('product_sku')) || (\$btn && \$btn.data('product_id')) || '';
      var qty = (\$btn && \$btn.data('quantity')) || 1;
      fbq('track','AddToCart',{content_ids:[String(id)],content_type:'product',currency:{$currency},contents:[{id:String(id),quantity:Number(qty)||1}]});
    } catch (err) {}
  });
})();
</script>
HTML;
}, 23);

/**
 * InitiateCheckout on checkout page.
 */
add_action('wp_footer', static function (): void {
    if (!sa_meta_pixel_should_inject() || !function_exists('is_checkout') || !is_checkout() || is_order_received_page()) {
        return;
    }
    if (!function_exists('WC') || !WC()->cart) {
        return;
    }
    $contents = [];
    $ids = [];
    foreach (WC()->cart->get_cart() as $item) {
        $p = $item['data'] ?? null;
        if (!$p) {
            continue;
        }
        $cid = $p->get_sku() !== '' ? (string) $p->get_sku() : (string) $item['product_id'];
        $ids[] = $cid;
        $contents[] = ['id' => $cid, 'quantity' => (int) $item['quantity']];
    }
    $payload = [
        'content_ids'  => $ids,
        'content_type' => 'product',
        'contents'     => $contents,
        'value'        => (float) WC()->cart->get_total('edit'),
        'currency'     => get_woocommerce_currency(),
        'num_items'    => (int) WC()->cart->get_cart_contents_count(),
    ];
    echo '<script>fbq("track","InitiateCheckout",' . sa_meta_pixel_js($payload) . ');</script>' . "\n";
}, 24);

/**
 * Purchase on thank-you / order-received (once per order).
 */
add_action('woocommerce_thankyou', static function ($order_id): void {
    if (!sa_meta_pixel_should_inject() || !$order_id) {
        return;
    }
    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }
    if ($order->get_meta('_sa_meta_purchase_tracked')) {
        return;
    }
    $order->update_meta_data('_sa_meta_purchase_tracked', '1');
    $order->save();

    $contents = [];
    $ids = [];
    foreach ($order->get_items() as $item) {
        $pid = $item->get_product_id();
        $product = $item->get_product();
        $cid = ($product && $product->get_sku() !== '') ? (string) $product->get_sku() : (string) $pid;
        $ids[] = $cid;
        $contents[] = ['id' => $cid, 'quantity' => (int) $item->get_quantity()];
    }
    $payload = [
        'content_ids'  => $ids,
        'content_type' => 'product',
        'contents'     => $contents,
        'value'        => (float) $order->get_total(),
        'currency'     => $order->get_currency(),
        'num_items'    => (int) $order->get_item_count(),
    ];
    // Print immediately in thankyou context (footer may already have run)
    echo '<script>if(typeof fbq==="function"){fbq("track","Purchase",' . sa_meta_pixel_js($payload) . ');}</script>' . "\n";
}, 5);
