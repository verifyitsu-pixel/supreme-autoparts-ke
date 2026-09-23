<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

remove_action('woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10);
remove_action('woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10);

add_action('woocommerce_before_main_content', static function (): void {
    echo '<main id="primary" class="sa-main sa-woo"><div class="sa-container">';
}, 10);

add_action('woocommerce_after_main_content', static function (): void {
    echo '</div></main>';
}, 10);

add_filter('woocommerce_enqueue_styles', static function (array $styles): array {
    return $styles;
});

add_filter('loop_shop_per_page', static fn (): int => 24);
add_filter('loop_shop_columns', static fn (): int => 4);

// Mega-store default: popularity, then newest — not random clone dumps.
add_filter('woocommerce_default_catalog_orderby', static fn (): string => 'popularity');
add_filter('woocommerce_catalog_orderby', static function (array $options): array {
    // Lead with shopper-familiar labels; keep Woo keys intact.
    $ordered = [];
    foreach (['popularity' => __('Best selling', 'supreme-autoparts'), 'date' => __('Newest', 'supreme-autoparts'), 'price' => __('Price: low to high', 'supreme-autoparts'), 'price-desc' => __('Price: high to low', 'supreme-autoparts')] as $key => $label) {
        if (isset($options[$key])) {
            $ordered[$key] = $label;
        }
    }
    foreach ($options as $key => $label) {
        if (!isset($ordered[$key])) {
            $ordered[$key] = $label;
        }
    }
    return $ordered;
});


add_filter('woocommerce_product_add_to_cart_text', static function (string $text): string {
    return __('Add to cart', 'supreme-autoparts');
});

add_filter('woocommerce_add_to_cart_fragments', static function (array $fragments): array {
    $count = (function_exists('WC') && WC()->cart) ? (int) WC()->cart->get_cart_contents_count() : 0;
    $fragments['span.sa-cart-count'] = '<span class="sa-cart-count" data-sa-cart-count>' . esc_html((string) $count) . '</span>';
    ob_start();
    woocommerce_mini_cart();
    $mini = ob_get_clean();
    $fragments['div.widget_shopping_cart_content'] = '<div class="widget_shopping_cart_content">' . $mini . '</div>';
    return $fragments;
});

// Quantity steppers markup wrapper.
add_action('wp_footer', static function (): void {
    if (!function_exists('is_woocommerce')) {
        return;
    }
}, 5);

add_filter('woocommerce_sale_flash', static function (string $html): string {
    return '<span class="onsale sa-badge sa-badge--sale">' . esc_html__('Sale', 'supreme-autoparts') . '</span>';
});


add_filter('woocommerce_loop_add_to_cart_args', static function (array $args): array {
    $class = isset($args['class']) ? (string) $args['class'] : 'button';
    if (strpos($class, 'sa-btn') === false) {
        $class .= ' sa-btn sa-btn--block sa-product-card__atc';
    }
    $args['class'] = trim(preg_replace('/\s+/', ' ', $class));
    return $args;
});

// Keep KES code readable when display currency is KES (geo layer may pass KES).
add_filter('woocommerce_currency_symbol', static function (string $symbol, string $currency): string {
    if (strtoupper($currency) === 'KES' && $symbol !== '' && stripos($symbol, 'KES') === false) {
        return 'KES';
    }
    return $symbol;
}, 10, 2);

// Custom product cards — suppress default loop title/price/thumb/button (rendered in content-product.php).
add_action('init', static function (): void {
    remove_action('woocommerce_before_shop_loop_item_title', 'woocommerce_show_product_loop_sale_flash', 10);
    remove_action('woocommerce_before_shop_loop_item_title', 'woocommerce_template_loop_product_thumbnail', 10);
    remove_action('woocommerce_shop_loop_item_title', 'woocommerce_template_loop_product_title', 10);
    remove_action('woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_price', 10);
    remove_action('woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_rating', 5);
    remove_action('woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10);
    remove_action('woocommerce_before_shop_loop_item', 'woocommerce_template_loop_product_link_open', 10);
    remove_action('woocommerce_after_shop_loop_item', 'woocommerce_template_loop_product_link_close', 5);
});


/**
 * Checkout / order-received body classes for styling hooks.
 */
add_filter('body_class', static function (array $classes): array {
    if (function_exists('is_checkout') && is_checkout()) {
        $classes[] = 'sa-is-checkout';
    }
    if (function_exists('is_order_received_page') && is_order_received_page()) {
        $classes[] = 'sa-is-order-received';
    }
    return $classes;
});

/**
 * Empty checkout cart → redirect to cart (which shows enquire CTA).
 */
add_action('template_redirect', static function (): void {
    if (!function_exists('is_checkout') || !is_checkout() || is_order_received_page()) {
        return;
    }
    if (!function_exists('WC') || !WC()->cart) {
        return;
    }
    if (WC()->cart->is_empty()) {
        wp_safe_redirect(wc_get_cart_url());
        exit;
    }
}, 20);

/**
 * Default create-account checkbox off (guest-friendly); remember me handled in templates.
 */
add_filter('woocommerce_create_account_default_checked', static fn (): bool => false);

/**
 * WooCommerce transactional email branding (light logo on light header).
 */
add_filter('woocommerce_email_header_image', static function ($url) {
    // Always prefer a stable theme-hosted logo so every transactional email shows brand.
    foreach (['logo-light.png', 'logo-light.jpg', 'logo.png', 'icon.png'] as $f) {
        if (is_readable(SA_THEME_DIR . '/assets/' . $f)) {
            return set_url_scheme(SA_THEME_URI . '/assets/' . $f, 'https');
        }
    }
    $forced = (string) get_option('sa_email_logo_url', '');
    if ($forced !== '' && filter_var($forced, FILTER_VALIDATE_URL)) {
        return set_url_scheme($forced, 'https');
    }
    $opt = (string) get_option('woocommerce_email_header_image', '');
    if ($opt !== '' && filter_var($opt, FILTER_VALIDATE_URL)) {
        return set_url_scheme($opt, 'https');
    }
    if (function_exists('sa_theme_logo_url')) {
        return set_url_scheme(sa_theme_logo_url(true), 'https');
    }
    return $url;
});

add_filter('woocommerce_email_styles', static function (string $css): string {
    $css .= "\nbody { background-color: #f4f4f5; }\n";
    $css .= "#wrapper { background-color: #f4f4f5; }\n";
    $css .= "#template_header { background-color: #ffffff !important; border-radius: 8px 8px 0 0; }\n";
    $css .= "#template_header h1 { color: #0B0B0D !important; }\n";
    $css .= "#template_header_image img { max-height: 64px; width: auto; max-width: 220px; margin: 16px 0; }\n";
    $css .= "#template_footer { color: #71717a; }\n";
    $css .= "a { color: #F5A623; }\n";
    // Order line-item product thumbnails (email clients).
    $css .= "td.td img, #body_content_inner img.sa-email-product-thumb, #body_content_inner table.td img {";
    $css .= " height: auto !important; max-width: 64px !important; width: 64px !important;";
    $css .= " border: 0; display: block; object-fit: contain; }\n";
    $css .= "td.td img.sa-shopify-cdn-photo { max-width: 64px !important; }\n";
    return $css;
});

add_filter('woocommerce_email_base_color', static fn (): string => '#0B0B0D');
add_filter('woocommerce_email_background_color', static fn (): string => '#F4F4F5');
add_filter('woocommerce_email_body_background_color', static fn (): string => '#ffffff');
add_filter('woocommerce_email_text_color', static fn (): string => '#0B0B0D');

/** Friendly From name — never the mailbox address as the visible label. */
add_filter('woocommerce_email_from_name', static fn (): string => 'Supreme Autoparts', 100);

/**
 * Storefront product-loop dedupe: hide near-identical titles on one page of results.
 * Instant relief while DB cleanup drafts the clones. Keep first occurrence.
 */
add_filter('the_posts', static function (array $posts, $query) {
    if (is_admin() || !($query instanceof WP_Query) || !$query->is_main_query()) {
        return $posts;
    }
    if (!function_exists('is_shop')) {
        return $posts;
    }
    $on_catalog = is_shop() || is_product_category() || is_product_tag() || is_search();
    if (!$on_catalog) {
        return $posts;
    }
    if (!function_exists('sa_product_loop_fingerprint')) {
        return $posts;
    }

    $seen = [];
    $out = [];
    foreach ($posts as $post) {
        if (!($post instanceof WP_Post) || $post->post_type !== 'product') {
            $out[] = $post;
            continue;
        }
        $fp = sa_product_loop_fingerprint($post);
        if ($fp !== '' && isset($seen[$fp])) {
            continue; // drop near-duplicate
        }
        if ($fp !== '') {
            $seen[$fp] = true;
        }
        $out[] = $post;
    }
    return $out;
}, 20, 2);

