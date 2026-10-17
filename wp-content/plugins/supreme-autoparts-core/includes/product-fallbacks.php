<?php
/**
 * Every published product is browsable:
 *  - No real photo  -> neutral store logo placeholder (never AI/stock).
 *  - No real price  -> "Quote on request" + WhatsApp CTA, not purchasable. Never invents a price.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

function sa_core_product_has_no_price($product): bool
{
    if (!$product instanceof WC_Product || $product->is_type('variable') || $product->is_type('grouped')) {
        return false;
    }
    $p = $product->get_price('edit');
    return $p === '' || $p === null || !is_numeric($p) || (float) $p <= 0;
}

function sa_core_quote_whatsapp_url($product): string
{
    $msg = 'Hi Supreme Autoparts, please quote: ' . $product->get_name();
    $sku = (string) $product->get_sku();
    if ($sku !== '') {
        $msg .= ' (Part ' . $sku . ')';
    }
    $msg .= ' ' . get_permalink($product->get_id());
    return 'https://wa.me/supremeautopart?text=' . rawurlencode($msg);
}

/* Logo as the clean placeholder for photo-less products. */
add_filter('woocommerce_placeholder_img_src', static function ($src) {
    if (function_exists('sa_core_invoice_logo_url')) {
        $logo = 'https://www.supremeautoparts.co.ke/wp-content/themes/supreme-autoparts/assets/logo-placeholder.jpg';
        if ($logo !== '') {
            return $logo;
        }
    }
    return $src;
}, 20);
add_filter('woocommerce_placeholder_img', static function ($html) {
    return is_string($html) ? str_replace('class="', 'style="object-fit:contain;padding:18%;background:#f4f4f1" class="sa-logo-placeholder ', $html) : $html;
}, 20);

/* Quote on request for products without a real source price. */
add_filter('woocommerce_is_purchasable', static function ($ok, $product) {
    return sa_core_product_has_no_price($product) ? false : $ok;
}, 50, 2);

add_filter('woocommerce_get_price_html', static function ($html, $product) {
    if (!sa_core_product_has_no_price($product)) {
        return $html;
    }
    return '<span class="sa-quote-price">Quote on request</span>';
}, 50, 2);
add_filter('woocommerce_empty_price_html', static function ($html, $product) {
    return '<span class="sa-quote-price">Quote on request</span>';
}, 50, 2);

add_filter('woocommerce_loop_add_to_cart_link', static function ($link, $product) {
    if (!sa_core_product_has_no_price($product)) {
        return $link;
    }
    return sprintf(
        '<a href="%s" class="button sa-quote-btn" target="_blank" rel="noopener">Ask for a quote on WhatsApp</a>',
        esc_url(sa_core_quote_whatsapp_url($product))
    );
}, 50, 2);

add_action('woocommerce_single_product_summary', static function (): void {
    global $product;
    if (!sa_core_product_has_no_price($product)) {
        return;
    }
    printf(
        '<p class="sa-quote-cta"><a class="button alt" href="%s" target="_blank" rel="noopener">Ask for a quote on WhatsApp</a></p>',
        esc_url(sa_core_quote_whatsapp_url($product))
    );
}, 31);
