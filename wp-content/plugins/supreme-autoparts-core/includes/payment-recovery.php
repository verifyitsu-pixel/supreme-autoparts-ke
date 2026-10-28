<?php
/**
 * Checkout gateways + cart recovery.
 * - Whop hidden from checkout (code + saved methods untouched). Re-enable with env
 *   SA_WHOP_CHECKOUT=1 (shop admins) or SA_WHOP_CHECKOUT_OFF=0 (everyone).
 * - Failed / cancelled / abandoned payments never lose cart items.
 * - My Account → Unpaid orders (Complete payment · Return items to cart · Cancel).
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

add_filter('woocommerce_available_payment_gateways', static function ($gateways) {
    if (is_admin() && !wp_doing_ajax()) {
        return $gateways;
    }
    if (getenv('SA_WHOP_CHECKOUT') !== '1' && getenv('SA_WHOP_CHECKOUT_OFF') !== '0' && is_array($gateways)) {
        unset($gateways['whop']);
    }
    return $gateways;
}, 50);

/** Statuses that count as "not paid yet". */
function sa_pr_unpaid_statuses(): array
{
    return ['pending', 'failed'];
}

/** Put an order's line items back into the cart (skips items already there). Returns count added. */
function sa_pr_restore_order_to_cart(WC_Order $order): int
{
    if (!function_exists('WC') || !WC()->cart) {
        return 0;
    }
    $have = [];
    foreach (WC()->cart->get_cart() as $ci) {
        $have[(int) $ci['product_id'] . ':' . (int) $ci['variation_id']] = true;
    }
    $n = 0;
    foreach ($order->get_items() as $it) {
        if (!$it instanceof WC_Order_Item_Product) {
            continue;
        }
        $pid = (int) $it->get_product_id();
        $vid = (int) $it->get_variation_id();
        if (!$pid || isset($have[$pid . ':' . $vid])) {
            continue;
        }
        $data = [];
        $bid = (int) $it->get_meta('_sa_booking');
        if ($bid) {
            if (get_post_type($bid) !== 'sa_booking' || get_post_meta($bid, '_sa_bk_fee', true) !== 'unpaid' || get_post_meta($bid, '_sa_bk_status', true) !== 'pending') {
                continue; // booking already paid / released
            }
            $data = ['sa_booking' => $bid, 'sa_booking_ref' => (string) get_post_meta($bid, '_sa_bk_ref', true)];
        }
        $p = wc_get_product($vid ?: $pid);
        if (!$p || !$p->is_purchasable()) {
            continue;
        }
        if (WC()->cart->add_to_cart($pid, max(1, (int) $it->get_quantity()), $vid, $vid ? $p->get_attributes() : [], $data)) {
            $n++;
        }
    }
    return $n;
}

/* Remember the order this session is paying for. */
add_action('woocommerce_checkout_order_processed', static function ($order_id): void {
    if (function_exists('WC') && WC()->session) {
        WC()->session->set('sa_pr_last_order', (int) $order_id);
    }
}, 5);

/*
 * Gateways (Pesapal etc.) empty the cart before the customer pays. If the session's last order
 * is still unpaid and the cart is empty, put the items back once — after that the customer
 * controls the cart (removing items sticks).
 */
add_action('woocommerce_cart_loaded_from_session', static function (): void {
    if (is_admin() || !WC()->session || !WC()->cart->is_empty()) {
        return;
    }
    $oid = (int) WC()->session->get('sa_pr_last_order');
    if (!$oid) {
        return;
    }
    $o = wc_get_order($oid);
    if (!$o || $o->is_paid() || !in_array($o->get_status(), array_merge(sa_pr_unpaid_statuses(), ['cancelled']), true)) {
        if ($o && $o->is_paid()) {
            WC()->session->set('sa_pr_last_order', null);
        }
        return;
    }
    // Don't refill while the customer is on the order-pay / received page for this order.
    if (isset($_GET['pay_for_order']) || isset($_GET['key'])) {
        return;
    }
    WC()->session->set('sa_pr_last_order', null);
    sa_pr_restore_order_to_cart($o);
}, 40);

/* A customer removing an item is intentional: never refill after that. */
add_action('woocommerce_cart_item_removed', static function (): void {
    if (function_exists('WC') && WC()->session) {
        WC()->session->set('sa_pr_last_order', null);
    }
});

/* Failure / cancel messaging. */
function sa_pr_saved_notice_html(?WC_Order $o = null): string
{
    $pay = $o && $o->needs_payment() ? '<a class="button" href="' . esc_url($o->get_checkout_payment_url()) . '">Try payment again</a> ' : '';
    return '<div class="sa-pr-notice woocommerce-info" role="alert"><strong>Payment didn\'t go through — your items are saved.</strong> Nothing was charged for this attempt. ' . $pay . '<a href="' . esc_url(wc_get_cart_url()) . '">View cart</a></div>';
}
add_action('woocommerce_before_thankyou', static function ($order_id): void {
    $o = wc_get_order($order_id);
    if ($o && $o->has_status(['failed', 'cancelled'])) {
        echo sa_pr_saved_notice_html($o); // phpcs:ignore
    }
}, 1);
add_action('before_woocommerce_pay', static function (): void {
    $oid = absint(get_query_var('order-pay'));
    $o = $oid ? wc_get_order($oid) : null;
    if ($o && $o->has_status('failed')) {
        echo sa_pr_saved_notice_html($o); // phpcs:ignore
    }
});
add_action('woocommerce_cancelled_order', static function ($order_id): void {
    $o = wc_get_order($order_id);
    if ($o) {
        sa_pr_restore_order_to_cart($o);
        wc_add_notice('Payment cancelled — your items are saved in your cart.', 'notice');
    }
});

/* ---------- My Account → Unpaid orders ---------- */
add_filter('woocommerce_get_query_vars', static function (array $v): array {
    $v['unpaid-orders'] = 'unpaid-orders';
    return $v;
});
add_action('init', static function (): void {
    add_rewrite_endpoint('unpaid-orders', EP_ROOT | EP_PAGES);
    if (get_option('sa_pr_rewrite_v') !== '1') {
        flush_rewrite_rules(false);
        update_option('sa_pr_rewrite_v', '1');
    }
}, 20);
add_filter('woocommerce_account_menu_items', static function (array $items): array {
    $out = [];
    foreach ($items as $k => $l) {
        $out[$k] = $l;
        if ($k === 'orders') {
            $out['unpaid-orders'] = 'Unpaid orders';
        }
    }
    if (!isset($out['unpaid-orders'])) {
        $out['unpaid-orders'] = 'Unpaid orders';
    }
    return $out;
}, 30);

function sa_pr_user_unpaid_orders(int $uid): array
{
    if (!$uid) {
        return [];
    }
    return wc_get_orders(['customer_id' => $uid, 'status' => sa_pr_unpaid_statuses(), 'limit' => 20, 'orderby' => 'date', 'order' => 'DESC']);
}

add_action('woocommerce_account_unpaid-orders_endpoint', static function (): void {
    $orders = sa_pr_user_unpaid_orders(get_current_user_id());
    echo '<h2>Unpaid orders — finish paying</h2>';
    if (!$orders) {
        echo '<p>You have no unpaid orders.</p>';
        return;
    }
    echo '<div class="sa-pr-list">';
    foreach ($orders as $o) {
        $names = [];
        foreach ($o->get_items() as $it) {
            $names[] = $it->get_name() . ' × ' . $it->get_quantity();
        }
        $back = wp_nonce_url(add_query_arg(['action' => 'sa_pr_to_cart', 'o' => $o->get_id()], admin_url('admin-post.php')), 'sa_pr_to_cart_' . $o->get_id());
        echo '<div class="sa-pr-card" style="border:1px solid #e6e8ee;border-radius:14px;padding:16px;margin:0 0 12px">'
            . '<strong>Order #' . esc_html($o->get_order_number()) . '</strong> · ' . esc_html(wc_get_order_status_name($o->get_status())) . ' · ' . wp_kses_post($o->get_formatted_order_total())
            . '<p style="margin:.4rem 0;color:#5b6475">' . esc_html(implode(', ', $names)) . '</p>'
            . '<p style="display:flex;gap:8px;flex-wrap:wrap;margin:0"><a class="button" href="' . esc_url($o->get_checkout_payment_url()) . '">Complete payment</a>'
            . '<a class="button" href="' . esc_url($back) . '">Return items to cart</a>'
            . '<a class="button" style="background:transparent;color:#b42318;border:1px solid #f1c0bb" href="' . esc_url($o->get_cancel_order_url(wc_get_account_endpoint_url('unpaid-orders'))) . '" onclick="return confirm(\'Cancel this order?\')">Cancel order</a></p></div>';
    }
    echo '</div>';
});

add_action('admin_post_sa_pr_to_cart', static function (): void {
    $oid = (int) ($_GET['o'] ?? 0);
    $o = wc_get_order($oid);
    if (!$o || !wp_verify_nonce((string) ($_GET['_wpnonce'] ?? ''), 'sa_pr_to_cart_' . $oid) || (int) $o->get_customer_id() !== get_current_user_id()) {
        wp_die('Not allowed', 403);
    }
    if (function_exists('wc_load_cart')) {
        wc_load_cart();
    }
    $n = sa_pr_restore_order_to_cart($o);
    wc_add_notice($n ? 'Items returned to your cart.' : 'Those items are already in your cart.', 'success');
    wp_safe_redirect(wc_get_cart_url());
    exit;
});

/* Notice for logged-in customers with unpaid orders (cart, checkout, account dashboard). */
function sa_pr_unpaid_banner(): void
{
    if (!is_user_logged_in() || (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('unpaid-orders'))) {
        return;
    }
    $n = count(sa_pr_user_unpaid_orders(get_current_user_id()));
    if ($n) {
        echo '<div class="woocommerce-info sa-pr-banner">You have ' . (int) $n . ' unpaid order' . ($n > 1 ? 's' : '') . '. <a href="' . esc_url(wc_get_account_endpoint_url('unpaid-orders')) . '">Finish paying</a></div>';
    }
}
add_action('woocommerce_before_cart', 'sa_pr_unpaid_banner', 3);
add_action('woocommerce_before_checkout_form', 'sa_pr_unpaid_banner', 3);
add_action('woocommerce_account_dashboard', 'sa_pr_unpaid_banner', 1);
