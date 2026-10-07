<?php
declare(strict_types=1);

/**
 * Customer-facing order views: real product photos (local thumb or stored
 * Shopify CDN URL), SKU, unit price, and card details on My Account orders,
 * View order, and Order received. Also a staff-only, read-only
 * "preview as customer" mode and a no-send email preview for QA.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * True while rendering a customer order screen on the storefront (not emails, not wp-admin).
 */
function sa_cod_is_customer_order_view(): bool
{
    if (is_admin() || !empty($GLOBALS['sa_in_wc_email'])) {
        return false;
    }
    if (!function_exists('is_wc_endpoint_url')) {
        return false;
    }
    return is_wc_endpoint_url('view-order')
        || is_wc_endpoint_url('order-received')
        || is_wc_endpoint_url('orders')
        || (function_exists('is_account_page') && is_account_page());
}

/**
 * Real product photo <img> for an order line (never a placeholder).
 *
 * @param WC_Order_Item $item
 */
function sa_cod_item_thumb_html($item, int $size = 64): string
{
    if (!is_object($item) || !method_exists($item, 'get_product')) {
        return '';
    }
    $product = $item->get_product();
    if (!$product instanceof WC_Product) {
        return '';
    }
    $url = function_exists('sa_core_get_product_email_image_url')
        ? sa_core_get_product_email_image_url($product, max(64, $size * 2))
        : '';
    if ($url === '') {
        return '';
    }
    return sprintf(
        '<img src="%s" alt="%s" width="%d" height="%d" class="sa-order-item__img" loading="lazy" decoding="async" style="width:%dpx;height:%dpx;object-fit:contain;background:#fff;border-radius:6px;border:1px solid rgba(0,0,0,.08);flex:0 0 auto;" />',
        esc_url($url),
        esc_attr($product->get_name()),
        $size,
        $size,
        $size,
        $size
    );
}

/**
 * Compact photo + title + SKU block for an order (dashboards, order lists).
 */
function sa_cod_order_items_inline_html($order, int $size = 40, int $max = 2): string
{
    if (!$order instanceof WC_Order) {
        return '';
    }
    $items = $order->get_items();
    $out = '<div class="sa-order-items-inline" style="display:flex;flex-direction:column;gap:6px;margin-top:4px;">';
    $shown = 0;
    foreach ($items as $item) {
        if ($shown >= $max) {
            break;
        }
        $product = is_object($item) && method_exists($item, 'get_product') ? $item->get_product() : null;
        $sku = $product instanceof WC_Product ? $product->get_sku() : '';
        $out .= '<div style="display:flex;align-items:center;gap:8px;">' . sa_cod_item_thumb_html($item, $size)
            . '<span style="line-height:1.3;"><span>' . esc_html($item->get_name()) . '</span>'
            . ($sku !== '' ? '<br><small style="opacity:.75;">SKU: ' . esc_html($sku) . ' &times; ' . (int) $item->get_quantity() . '</small>' : '')
            . '</span></div>';
        $shown++;
    }
    $more = count($items) - $shown;
    if ($more > 0) {
        $out .= '<small>+' . (int) $more . ' more</small>';
    }
    return $out . '</div>';
}

/**
 * View order / Order received: photo beside the product name.
 */
add_filter('woocommerce_order_item_name', static function ($name, $item, $is_visible = false) {
    if (!sa_cod_is_customer_order_view()) {
        return $name;
    }
    $thumb = sa_cod_item_thumb_html($item, 64);
    if ($thumb === '') {
        return $name;
    }
    return '<span class="sa-order-item" style="display:inline-flex;align-items:center;gap:.75rem;vertical-align:middle;">'
        . $thumb
        . '<span class="sa-order-item__name">' . $name . '</span></span>';
}, 20, 3);

/**
 * SKU + unit price under the product name on customer order screens.
 */
add_action('woocommerce_order_item_meta_start', static function ($item_id, $item, $order, $plain_text = false): void {
    if ($plain_text || !sa_cod_is_customer_order_view()) {
        return;
    }
    if (!is_object($item) || !method_exists($item, 'get_product') || !$order instanceof WC_Order) {
        return;
    }
    $product = $item->get_product();
    $bits = [];
    if ($product instanceof WC_Product && $product->get_sku() !== '') {
        $bits[] = esc_html__('SKU:', 'supreme-autoparts-core') . ' ' . esc_html($product->get_sku());
    }
    $qty = max(1, (int) $item->get_quantity());
    $unit = (float) $item->get_subtotal() / $qty;
    $bits[] = esc_html__('Unit price:', 'supreme-autoparts-core') . ' ' . wp_kses_post(wc_price($unit, ['currency' => $order->get_currency()]));
    echo '<div class="sa-order-item__meta" style="font-size:.85em;opacity:.8;margin-top:.25rem;">' . implode(' &middot; ', $bits) . '</div>';
}, 10, 4);

/**
 * My Account > Orders: "Items" column with photo, title, and SKU.
 */
add_filter('woocommerce_account_orders_columns', static function (array $columns): array {
    $out = [];
    foreach ($columns as $key => $label) {
        $out[$key] = $label;
        if ($key === 'order-number') {
            $out['sa-order-items'] = __('Items', 'supreme-autoparts-core');
        }
    }
    if (!isset($out['sa-order-items'])) {
        $out = ['sa-order-items' => __('Items', 'supreme-autoparts-core')] + $out;
    }
    return $out;
}, 20);

add_action('woocommerce_my_account_my_orders_column_sa-order-items', static function ($order): void {
    if (!$order instanceof WC_Order) {
        return;
    }
    $items = $order->get_items();
    $shown = 0;
    echo '<div class="sa-orders__items" style="display:flex;flex-direction:column;gap:.5rem;">';
    foreach ($items as $item) {
        if ($shown >= 2) {
            break;
        }
        $product = is_object($item) && method_exists($item, 'get_product') ? $item->get_product() : null;
        $sku = $product instanceof WC_Product ? $product->get_sku() : '';
        echo '<div class="sa-orders__item" style="display:flex;align-items:center;gap:.6rem;">';
        echo sa_cod_item_thumb_html($item, 48); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<span><span class="sa-orders__item-name">' . esc_html($item->get_name()) . '</span>';
        if ($sku !== '') {
            echo '<br><small style="opacity:.75;">' . esc_html__('SKU:', 'supreme-autoparts-core') . ' ' . esc_html($sku) . '</small>';
        }
        echo '</span></div>';
        $shown++;
    }
    $more = count($items) - $shown;
    if ($more > 0) {
        echo '<small>' . esc_html(sprintf(_n('+%d more item', '+%d more items', $more, 'supreme-autoparts-core'), $more)) . '</small>';
    }
    echo '</div>';
});

/**
 * Show card brand + last4 for Whop orders (from stored webhook/import meta).
 */
add_filter('woocommerce_order_get_payment_method_title', static function ($title, $order) {
    if (!$order instanceof WC_Order || $order->get_payment_method() !== 'whop') {
        return $title;
    }
    $last4 = preg_replace('/\D+/', '', (string) $order->get_meta('_whop_card_last4'));
    if ($last4 === '' || str_contains((string) $title, $last4)) {
        return $title;
    }
    $brand = trim((string) $order->get_meta('_whop_card_brand'));
    $brand = $brand !== '' ? ucfirst(strtolower($brand)) : __('Card', 'supreme-autoparts-core');
    $base = trim((string) $title) !== '' ? trim((string) $title) : 'Whop';
    return sprintf('%s – %s ****%s', $base, $brand, $last4);
}, 20, 2);

/**
 * Customer order emails: include SKU next to the product photo + title.
 *
 * @param array<string,mixed> $args
 * @return array<string,mixed>
 */
add_filter('woocommerce_email_order_items_args', static function (array $args): array {
    $args['show_sku'] = true;
    return $args;
}, 25);

/* ---------------------------------------------------------------------------
 * Staff QA: read-only "preview as customer" + email preview (never sends).
 * ------------------------------------------------------------------------- */

function sa_cod_preview_nonce_action(int $order_id): string
{
    return 'sa_customer_preview_' . $order_id;
}

/**
 * @return array<string,string>
 */
function sa_cod_preview_links(WC_Order $order): array
{
    $oid = (int) $order->get_id();
    $nonce = wp_create_nonce(sa_cod_preview_nonce_action($oid));
    $args = ['sa_customer_preview' => $oid, '_sacpv' => $nonce];
    return [
        'orders'         => add_query_arg($args, wc_get_account_endpoint_url('orders')),
        'view_order'     => add_query_arg($args, $order->get_view_order_url()),
        'order_received' => add_query_arg($args, $order->get_checkout_order_received_url()),
        'email'          => add_query_arg(
            ['action' => 'sa_preview_order_email', 'order_id' => $oid, '_sacpv' => $nonce],
            admin_url('admin-post.php')
        ),
    ];
}

/**
 * Switch to the order's customer for this GET request only (staff + nonce).
 * Runs after the WC session/cart load so no session is migrated, and blocks all
 * writes to the customer's user meta during the preview.
 */
add_action('template_redirect', static function (): void {
    if (empty($_GET['sa_customer_preview']) || empty($_GET['_sacpv'])) {
        return;
    }
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        return;
    }
    if (!is_user_logged_in() || !current_user_can('manage_woocommerce')) {
        return;
    }
    $oid = absint($_GET['sa_customer_preview']);
    if (!$oid || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_GET['_sacpv'])), sa_cod_preview_nonce_action($oid))) {
        return;
    }
    $order = wc_get_order($oid);
    if (!$order instanceof WC_Order) {
        return;
    }
    $cid = (int) $order->get_customer_id();
    if ($cid <= 0 || !get_userdata($cid)) {
        return;
    }
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    nocache_headers();
    add_filter('woocommerce_persistent_cart_enabled', '__return_false', 9999);
    add_filter('show_admin_bar', '__return_false', 9999);
    $block = static function ($check, $object_id) use ($cid) {
        return ((int) $object_id === $cid) ? false : $check;
    };
    add_filter('update_user_metadata', $block, 1, 2);
    add_filter('add_user_metadata', $block, 1, 2);
    add_filter('delete_user_metadata', $block, 1, 2);
    $GLOBALS['sa_customer_preview_active'] = $cid;
    wp_set_current_user($cid);
    add_action('wp_footer', static function (): void {
        echo '<div style="position:fixed;left:8px;bottom:8px;z-index:99999;background:#111;color:#fff;font:12px/1.3 sans-serif;padding:6px 10px;border-radius:6px;opacity:.85;">Staff preview (read-only, as customer)</div>';
    }, 999);
}, 0);

/**
 * Email preview: render the customer-facing order email HTML for a real order. Never sends.
 */
add_action('admin_post_sa_preview_order_email', static function (): void {
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('Not allowed.', 'supreme-autoparts-core'), 403);
    }
    $oid = absint($_GET['order_id'] ?? 0);
    if (!$oid || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) ($_GET['_sacpv'] ?? ''))), sa_cod_preview_nonce_action($oid))) {
        wp_die(esc_html__('Bad preview link.', 'supreme-autoparts-core'), 403);
    }
    $order = wc_get_order($oid);
    if (!$order instanceof WC_Order) {
        wp_die(esc_html__('Order not found.', 'supreme-autoparts-core'), 404);
    }
    // Hard stop: nothing may be mailed from this request.
    add_filter('pre_wp_mail', static fn() => false, 9999);

    $mailer = WC()->mailer();
    $emails = $mailer->get_emails();
    $key = $order->has_status('completed') ? 'WC_Email_Customer_Completed_Order' : 'WC_Email_Customer_Processing_Order';
    $email = $emails[$key] ?? null;
    if (!$email) {
        wp_die(esc_html__('Email template unavailable.', 'supreme-autoparts-core'));
    }
    $email->object = $order;
    $email->recipient = $order->get_billing_email();
    $email->placeholders['{order_date}'] = wc_format_datetime($order->get_date_created());
    $email->placeholders['{order_number}'] = $order->get_order_number();
    $html = $email->style_inline($email->get_content_html());
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    echo '<!-- preview only: not sent -->' . $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    exit;
});

/**
 * Order edit screen: staff links to the customer-facing views.
 */
add_action('woocommerce_admin_order_data_after_order_details', static function ($order): void {
    if (!$order instanceof WC_Order || !current_user_can('manage_woocommerce') || (int) $order->get_customer_id() <= 0) {
        return;
    }
    $l = sa_cod_preview_links($order);
    echo '<p class="form-field form-field-wide sa-customer-preview-links"><strong>' . esc_html__('Customer view preview:', 'supreme-autoparts-core') . '</strong><br>';
    echo '<a href="' . esc_url($l['orders']) . '" target="_blank" rel="noopener">' . esc_html__('Orders list', 'supreme-autoparts-core') . '</a> · ';
    echo '<a href="' . esc_url($l['view_order']) . '" target="_blank" rel="noopener">' . esc_html__('View order', 'supreme-autoparts-core') . '</a> · ';
    echo '<a href="' . esc_url($l['order_received']) . '" target="_blank" rel="noopener">' . esc_html__('Thank-you page', 'supreme-autoparts-core') . '</a> · ';
    echo '<a href="' . esc_url($l['email']) . '" target="_blank" rel="noopener">' . esc_html__('Email (preview, not sent)', 'supreme-autoparts-core') . '</a></p>';
});

/**
 * WP admin orders list (HPOS + legacy): "Items" column with photo, title, SKU.
 */
$sa_cod_admin_cols = static function (array $columns): array {
    $out = [];
    foreach ($columns as $k => $v) {
        $out[$k] = $v;
        if ($k === 'order_number') {
            $out['sa_items'] = __('Items', 'supreme-autoparts-core');
        }
    }
    return $out;
};
add_filter('manage_woocommerce_page_wc-orders_columns', $sa_cod_admin_cols, 20);
add_filter('manage_edit-shop_order_columns', $sa_cod_admin_cols, 20);
add_action('manage_woocommerce_page_wc-orders_custom_column', static function ($column, $order): void {
    if ($column === 'sa_items') {
        echo sa_cod_order_items_inline_html($order instanceof WC_Order ? $order : wc_get_order($order), 40); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}, 10, 2);
add_action('manage_shop_order_posts_custom_column', static function ($column, $post_id): void {
    if ($column === 'sa_items') {
        echo sa_cod_order_items_inline_html(wc_get_order($post_id), 40); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}, 10, 2);
