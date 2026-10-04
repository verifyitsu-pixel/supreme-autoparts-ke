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

add_filter('gettext', static function ($translated, $text, $domain) {
    if (is_string($text) && $text === 'Proceed to checkout' && in_array($domain, ['woocommerce', 'default'], true)) {
        return __('Checkout', 'supreme-autoparts');
    }
    return $translated;
}, 20, 3);

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


/**
 * Checkout order-summary qty update (AJAX) — refreshes review + shipping totals.
 */
function sa_theme_checkout_update_qty(): void
{
    if (!check_ajax_referer('sa_checkout_qty', 'nonce', false)) {
        wp_send_json_error(['message' => 'Invalid nonce'], 403);
    }
    if (!function_exists('WC') || !WC()->cart) {
        wp_send_json_error(['message' => 'Cart unavailable'], 400);
    }

    $key = isset($_POST['cart_key']) ? wc_clean(wp_unslash((string) $_POST['cart_key'])) : '';
    $qty = isset($_POST['qty']) ? (float) wp_unslash($_POST['qty']) : -1;
    if ($key === '' || $qty < 0) {
        wp_send_json_error(['message' => 'Missing cart key or quantity'], 400);
    }

    $cart = WC()->cart->get_cart();
    if (!isset($cart[$key])) {
        wp_send_json_error(['message' => 'Item not in cart'], 404);
    }

    $qty = (int) floor($qty);
    if ($qty <= 0) {
        WC()->cart->remove_cart_item($key);
    } else {
        WC()->cart->set_quantity($key, $qty, true);
    }

    WC()->cart->calculate_totals();

    if (WC()->cart->is_empty()) {
        wp_send_json_success([
            'empty'    => true,
            'redirect' => wc_get_cart_url(),
        ]);
    }

    wp_send_json_success([
        'empty' => false,
        'count' => (int) WC()->cart->get_cart_contents_count(),
        'total' => wp_strip_all_tags(WC()->cart->get_cart_total()),
    ]);
}
add_action('wp_ajax_sa_checkout_update_qty', 'sa_theme_checkout_update_qty');
add_action('wp_ajax_nopriv_sa_checkout_update_qty', 'sa_theme_checkout_update_qty');

/**
 * USD amount that qualifies the cart for free US shipping. Store option, default 99.
 */
function sa_theme_free_ship_amount(): float
{
    $raw = getenv('SUPREME_FREE_SHIPPING_THRESHOLD');
    if ($raw === false || $raw === '') {
        $raw = (string) get_option('sa_free_shipping_threshold', '99');
    }
    $usd = (float) $raw;
    if ($usd <= 0 || $usd > 1000) {
        $usd = 99.0;
    }
    return $usd;
}

/**
 * True only when the cart merchandise total (after discounts, store currency) meets the threshold.
 */
function sa_theme_cart_has_free_shipping(): bool
{
    if (!function_exists('WC') || !WC()->cart) {
        return false;
    }
    $subtotal = (float) WC()->cart->get_subtotal();
    $discount = (float) WC()->cart->get_discount_total();
    if ($discount > 0) {
        $subtotal -= $discount;
    }
    if ($subtotal < 0) {
        $subtotal = 0.0;
    }
    return $subtotal + 0.001 >= sa_theme_free_ship_amount();
}

/**
 * Stock line, plus free-shipping only when this item's price itself meets the threshold.
 */
function sa_theme_product_card_meta(WC_Product $product): string
{
    if (!$product->is_in_stock()) {
        return __('Out of stock', 'supreme-autoparts');
    }
    $stock = $product->is_on_backorder(1)
        ? __('Available on backorder', 'supreme-autoparts')
        : __('In stock', 'supreme-autoparts');
    $price = (float) $product->get_price();
    if ($price > 0 && $price + 0.001 >= sa_theme_free_ship_amount()) {
        return $stock . ' · ' . __('Free US shipping', 'supreme-autoparts');
    }
    return $stock;
}

/**
 * Buy now: add the simple product and continue to checkout. Pay still leaves the site for Whop.
 */
add_filter('woocommerce_add_to_cart_redirect', static function ($url) {
    if (isset($_REQUEST['sa_buy_now']) && (string) wp_unslash($_REQUEST['sa_buy_now']) === '1') { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : $url;
    }
    return $url;
});

add_action('woocommerce_after_add_to_cart_button', static function (): void {
    global $product;
    if (!$product instanceof WC_Product) {
        return;
    }
    if (!$product->is_type('simple') || !$product->is_purchasable() || !$product->is_in_stock()) {
        return;
    }
    $url = add_query_arg(
        [
            'add-to-cart' => $product->get_id(),
            'sa_buy_now'  => '1',
        ],
        $product->get_permalink()
    );
    printf(
        '<a class="button sa-btn sa-btn--buy" href="%s">%s</a>',
        esc_url($url),
        esc_html__('Buy now', 'supreme-autoparts')
    );
});

/**
 * Real min/max price on shop and search. Empty fields are ignored.
 */
add_action('pre_get_posts', static function ($query): void {
    if (is_admin() || !($query instanceof WP_Query) || !$query->is_main_query()) {
        return;
    }
    if (!function_exists('sa_core_is_catalog_product_query') || !sa_core_is_catalog_product_query($query)) {
        return;
    }
    $read = static function (string $key): ?float {
        if (!isset($_GET[$key])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return null;
        }
        $raw = trim((string) wp_unslash($_GET[$key])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }
        return (float) $raw;
    };
    $min = $read('min_price');
    $max = $read('max_price');
    if ($min === null && $max === null) {
        return;
    }
    if ($min !== null && $min < 0) {
        $min = 0.0;
    }
    if ($max !== null && $min !== null && $max < $min) {
        return;
    }
    $meta = $query->get('meta_query');
    if (!is_array($meta)) {
        $meta = [];
    }
    $clause = [
        'key'     => '_price',
        'type'    => 'DECIMAL(10,2)',
        'compare' => 'BETWEEN',
        'value'   => [$min ?? 0, $max ?? 99999999],
    ];
    $meta[] = $clause;
    $query->set('meta_query', $meta);
}, 30);

/**
 * Same-category products for the add-to-cart sheet. Brand-only terms are skipped when a real category exists.
 *
 * @return list<int>
 */
function sa_theme_related_product_ids(WC_Product $product, int $limit = 8): array
{
    $cat_ids = array_map('intval', $product->get_category_ids());
    $brand_ids = [];
    $brands = get_term_by('slug', 'brands', 'product_cat');
    if ($brands instanceof WP_Term) {
        $children = get_term_children((int) $brands->term_id, 'product_cat');
        if (is_array($children)) {
            $brand_ids = array_map('intval', $children);
        }
        $brand_ids[] = (int) $brands->term_id;
    }
    $use = array_values(array_diff($cat_ids, $brand_ids));
    if ($use === []) {
        $use = $cat_ids;
    }
    if ($use === []) {
        return [];
    }
    $q = new WP_Query([
        'post_type'           => 'product',
        'post_status'         => 'publish',
        'posts_per_page'      => $limit,
        'post__not_in'        => [$product->get_id()],
        'fields'              => 'ids',
        'no_found_rows'       => true,
        'ignore_sticky_posts' => true,
        'tax_query'           => [[
            'taxonomy' => 'product_cat',
            'field'    => 'term_id',
            'terms'    => $use,
        ]],
        'orderby'             => 'date',
        'order'               => 'DESC',
    ]);
    $ids = [];
    foreach ($q->posts as $id) {
        $ids[] = (int) $id;
    }
    return $ids;
}

function sa_theme_added_sheet(): void
{
    $id = isset($_REQUEST['product_id']) ? absint(wp_unslash($_REQUEST['product_id'])) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $product = $id > 0 ? wc_get_product($id) : false;
    if (!$product instanceof WC_Product || $product->get_status() !== 'publish') {
        wp_send_json_error(['message' => 'missing'], 404);
    }

    $cart_url = function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/cart/');
    $image = $product->get_image('woocommerce_thumbnail', [
        'class'    => 'sa-added__img',
        'loading'  => 'lazy',
        'decoding' => 'async',
        'alt'      => $product->get_name(),
    ]);

    ob_start();
    ?>
    <div class="sa-added__sheet" role="dialog" aria-modal="true" aria-labelledby="sa-added-title">
      <button type="button" class="sa-added__close" data-sa-added-close aria-label="<?php esc_attr_e('Close', 'supreme-autoparts'); ?>">&times;</button>
      <p class="sa-added__status" id="sa-added-title">
        <span class="sa-added__check" aria-hidden="true">&#10003;</span>
        <?php esc_html_e('Added to cart', 'supreme-autoparts'); ?>
      </p>
      <div class="sa-added__item">
        <div class="sa-added__thumb"><?php echo $image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
        <div>
          <p class="sa-added__name"><?php echo esc_html($product->get_name()); ?></p>
          <p class="sa-added__price"><?php echo wp_kses_post($product->get_price_html()); ?></p>
        </div>
      </div>
      <a class="sa-btn sa-btn--block sa-added__go" href="<?php echo esc_url($cart_url); ?>"><?php esc_html_e('Go to cart', 'supreme-autoparts'); ?></a>
      <?php
      $related = sa_theme_related_product_ids($product, 8);
      if ($related !== []) :
          ?>
        <h3 class="sa-added__more-title"><?php esc_html_e('More like this', 'supreme-autoparts'); ?></h3>
        <ul class="sa-added__row">
          <?php foreach ($related as $rel_id) :
              $rel = wc_get_product($rel_id);
              if (!$rel instanceof WC_Product || !$rel->is_visible()) {
                  continue;
              }
              $rel_img = $rel->get_image('woocommerce_thumbnail', [
                  'class'    => 'sa-added__card-img',
                  'loading'  => 'lazy',
                  'decoding' => 'async',
                  'alt'      => '',
              ]);
              ?>
            <li class="sa-added__card">
              <a href="<?php echo esc_url($rel->get_permalink()); ?>">
                <span class="sa-added__card-photo"><?php echo $rel_img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                <span class="sa-added__card-name"><?php echo esc_html($rel->get_name()); ?></span>
                <span class="sa-added__card-price"><?php echo wp_kses_post($rel->get_price_html()); ?></span>
                <span class="sa-added__card-meta"><?php echo esc_html(sa_theme_product_card_meta($rel)); ?></span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
    <?php
    $html = (string) ob_get_clean();
    wp_send_json_success(['html' => $html]);
}
add_action('wp_ajax_sa_added_sheet', 'sa_theme_added_sheet');
add_action('wp_ajax_nopriv_sa_added_sheet', 'sa_theme_added_sheet');
