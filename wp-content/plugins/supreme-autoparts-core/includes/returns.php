<?php
/**
 * Returns and refunds: login-gated, order and item picker, structured reasons with photos,
 * 15-day window, status timeline shared by My Account and wp-admin, ship-back or drop-off,
 * and a contextual email at every step.
 *
 * Data lives in the private post type `sa_return` (one post per request, details in post meta).
 *
 * @package Supreme_Autoparts_Core
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

const SA_RET_DAYS = 15;
const SA_RET_EP = 'return-requests';

/* ---------- vocabulary ---------- */

function sa_ret_statuses(): array
{
    return [
        'requested' => 'Return requested',
        'approved'  => 'Approved',
        'rejected'  => 'Declined',
        'shipped'   => 'Return shipped',
        'dropoff'   => 'Drop-off planned',
        'received'  => 'Item received',
        'inspected' => 'Inspected',
        'refunded'  => 'Refunded',
        'cancelled' => 'Cancelled',
    ];
}

function sa_ret_reasons(): array
{
    // key => [label, photo required, hint]
    return [
        'wrong_part'       => ['Wrong part received', true, 'Add a photo of the part and the label on the box.'],
        'no_fit'           => ['Part does not fit my car', false, 'Tell us your car (make, model, year) so we can check.'],
        'damaged'          => ['Arrived damaged', true, 'Add photos of the damage and the packaging.'],
        'defective'        => ['Defective or does not work', true, 'Add a photo and describe what happens.'],
        'not_as_described' => ['Not as described', true, 'Add a photo and tell us what is different.'],
        'missing'          => ['Item or parts missing from the package', false, 'Tell us what is missing. A photo of the package helps.'],
        'late'             => ['Arrived too late', false, 'Tell us when you needed it.'],
        'changed_mind'     => ['Changed my mind', false, 'Item must be unused and in its original packaging.'],
        'other'            => ['Something else', false, 'Tell us what happened.'],
    ];
}

function sa_ret_pill_colors(string $s): string
{
    $m = ['requested' => '#92400e;background:#fef3c7', 'approved' => '#1e40af;background:#dbeafe', 'rejected' => '#991b1b;background:#fee2e2', 'shipped' => '#1e40af;background:#dbeafe', 'dropoff' => '#1e40af;background:#dbeafe', 'received' => '#065f46;background:#d1fae5', 'inspected' => '#065f46;background:#d1fae5', 'refunded' => '#065f46;background:#a7f3d0', 'cancelled' => '#374151;background:#e5e7eb'];
    return 'color:' . ($m[$s] ?? '#374151;background:#e5e7eb');
}

function sa_ret_ref(int $rid): string
{
    return 'RT' . $rid;
}

/* ---------- post type ---------- */

add_action('init', static function (): void {
    register_post_type('sa_return', [
        'labels' => ['name' => 'Returns', 'singular_name' => 'Return', 'menu_name' => 'Returns', 'all_items' => 'All returns', 'edit_item' => 'Return request', 'search_items' => 'Search returns', 'not_found' => 'No return requests yet'],
        'public' => false, 'show_ui' => true, 'show_in_menu' => true, 'menu_icon' => 'dashicons-undo', 'menu_position' => 56,
        'supports' => ['title'], 'capability_type' => 'post', 'map_meta_cap' => true,
        'capabilities' => ['create_posts' => 'do_not_allow'],
        'has_archive' => false, 'rewrite' => false, 'query_var' => false,
    ]);
});

function sa_ret_meta(int $rid, string $k, $d = '')
{
    $v = get_post_meta($rid, '_sa_r_' . $k, true);
    return ($v === '' || $v === false || $v === null) ? $d : $v;
}

function sa_ret_status(int $rid): string
{
    return (string) sa_ret_meta($rid, 'status', 'requested');
}

/** Returns for one order (all, or only those still counting against the items). */
function sa_ret_for_order(int $order_id, bool $active_only = false): array
{
    $ids = get_posts(['post_type' => 'sa_return', 'post_status' => 'any', 'numberposts' => 50, 'fields' => 'ids', 'meta_key' => '_sa_r_order', 'meta_value' => $order_id]);
    if ($active_only) {
        $ids = array_filter($ids, static fn($id) => !in_array(sa_ret_status((int) $id), ['rejected', 'cancelled'], true));
    }
    return array_map('intval', $ids);
}

/* ---------- eligibility ---------- */

function sa_ret_courier_options(): array
{
    $o = [];
    if (function_exists('sa_ol_couriers')) {
        foreach (sa_ol_couriers() as $k => $c) {
            $o[$k] = (string) ($c['label'] ?? $k);
        }
    }
    return $o ?: ['fargo' => 'Fargo Courier', 'other' => 'Other courier'];
}

/** Timestamp the 15 days count from, and which date it is. */
function sa_ret_basis(WC_Order $o): array
{
    $h = $o->get_meta('_sa_timeline');
    if (is_array($h)) {
        foreach (array_reverse($h) as $e) {
            if (in_array($e['s'] ?? '', ['delivered', 'completed'], true) && !empty($e['t'])) {
                return [(int) $e['t'], ($e['s'] === 'delivered' ? 'delivered' : 'completed')];
            }
        }
    }
    if ($o->get_date_completed()) {
        return [$o->get_date_completed()->getTimestamp(), 'completed'];
    }
    return [$o->get_date_created() ? $o->get_date_created()->getTimestamp() : time(), 'ordered'];
}

function sa_ret_state(WC_Order $o): array
{
    [$ts, $basis] = sa_ret_basis($o);
    $deadline = $ts + SA_RET_DAYS * DAY_IN_SECONDS;
    $left = (int) ceil(($deadline - time()) / DAY_IN_SECONDS);
    $st = $o->get_status();
    $r = ['ok' => false, 'why' => '', 'msg' => '', 'deadline' => $deadline, 'days_left' => $left, 'basis' => $basis];
    if (in_array($st, ['cancelled', 'failed'], true)) {
        $r['why'] = 'cancelled';
        $r['msg'] = 'This order was ' . ($st === 'failed' ? 'not paid' : 'cancelled') . ', so there is nothing to return.';
    } elseif (in_array($st, ['pending', 'on-hold', 'awaiting-info'], true)) {
        $r['why'] = 'unpaid';
        $r['msg'] = 'This order is not paid or confirmed yet. To change or cancel it, contact us.';
    } elseif ($st === 'refunded') {
        $r['why'] = 'refunded';
        $r['msg'] = 'This order was already refunded.';
    } elseif (!in_array($st, ['delivered', 'completed'], true)) {
        $r['why'] = 'not_delivered';
        $r['msg'] = 'Not delivered yet. You can start a return once it arrives.';
    } elseif (time() > $deadline) {
        $r['why'] = 'expired';
        $r['msg'] = 'The ' . SA_RET_DAYS . '-day return window ended on ' . wp_date('j M Y', $deadline) . '. You can still contact us with your issue.';
    } else {
        $r['ok'] = true;
        $r['msg'] = $left . ' day' . ($left === 1 ? '' : 's') . ' left to start a return (counted from ' . ($basis === 'ordered' ? 'the order date' : 'the ' . $basis . ' date') . ').';
    }
    return $r;
}

/** Quantity of each line item that can still be returned. */
function sa_ret_available(WC_Order $o): array
{
    $used = [];
    foreach (sa_ret_for_order($o->get_id(), true) as $rid) {
        foreach ((array) sa_ret_meta($rid, 'items', []) as $it) {
            $used[(int) $it['item_id']] = ($used[(int) $it['item_id']] ?? 0) + (int) $it['qty'];
        }
    }
    $out = [];
    foreach ($o->get_items() as $id => $it) {
        $q = (int) $it->get_quantity() + (int) $o->get_qty_refunded_for_item($id); // refunded qty is negative
        $q -= $used[(int) $id] ?? 0;
        if ($q > 0) {
            $out[(int) $id] = ['name' => $it->get_name(), 'qty' => $q, 'product' => $it->get_product_id()];
        }
    }
    return $out;
}

function sa_ret_contact_url(?WC_Order $o = null): string
{
    return add_query_arg($o ? ['order' => $o->get_order_number()] : [], home_url('/contact/')) . '#sa-form';
}

/* ---------- return address / drop-off ---------- */

function sa_ret_region(WC_Order $o): string
{
    $c = strtoupper((string) ($o->get_shipping_country() ?: $o->get_billing_country()));
    if ($c === 'KE') {
        return 'KE';
    }
    if ($c !== '' && function_exists('WC') && WC()->countries && WC()->countries->get_continent_code_for_country($c) === 'AF') {
        return 'AF';
    }
    return 'US';
}

function sa_ret_address(WC_Order $o): array
{
    $b = function_exists('sa_geo_business') ? sa_geo_business(sa_ret_region($o) === 'US' ? 'US' : 'KE') : ['one_line' => 'Ground Floor, Midax Plaza, Kangundo Rd, Njiru, Nairobi, Kenya', 'phone_display' => '+254 714 498 451'];
    return ['line' => (string) $b['one_line'], 'phone' => (string) $b['phone_display'], 'dropoff' => sa_ret_region($o) === 'KE', 'hours' => 'Mon to Fri 8:00 to 17:00, Sat 8:00 to 13:00'];
}

/* ---------- status changes ---------- */

function sa_ret_log(int $rid, string $status, string $note = '', string $by = 'system'): void
{
    $h = sa_ret_meta($rid, 'timeline', []);
    $h = is_array($h) ? $h : [];
    $h[] = ['t' => time(), 's' => $status, 'n' => $note, 'by' => $by];
    update_post_meta($rid, '_sa_r_timeline', array_slice($h, -80));
}

function sa_ret_set_status(int $rid, string $status, string $note = '', string $by = 'staff', bool $notify = true): void
{
    if (!isset(sa_ret_statuses()[$status])) {
        return;
    }
    update_post_meta($rid, '_sa_r_status', $status);
    sa_ret_log($rid, $status, $note, $by);
    $oid = (int) sa_ret_meta($rid, 'order', 0);
    $o = $oid ? wc_get_order($oid) : null;
    if ($o instanceof WC_Order) {
        $o->add_order_note('Return ' . sa_ret_ref($rid) . ': ' . sa_ret_statuses()[$status] . ($note !== '' ? ' - ' . $note : ''), 0, false);
    }
    if ($notify) {
        sa_ret_email_customer($rid, $status, $note);
    }
    if ($by === 'customer') {
        sa_ret_email_team($rid, $status, $note);
    }
}

/* ---------- emails ---------- */

function sa_ret_items_html(int $rid): string
{
    $rs = sa_ret_reasons();
    $h = '<table role="presentation" width="100%" style="border-collapse:collapse;font-size:14px;margin:10px 0">';
    foreach ((array) sa_ret_meta($rid, 'items', []) as $it) {
        $h .= '<tr><td style="padding:6px 0;border-bottom:1px solid #eee">' . esc_html((string) $it['name']) . ' &times; ' . (int) $it['qty'] . '</td><td style="padding:6px 0;border-bottom:1px solid #eee;color:#666;text-align:right">' . esc_html($rs[$it['reason']][0] ?? '') . '</td></tr>';
    }
    return $h . '</table>';
}

function sa_ret_view_url(int $rid): string
{
    return trailingslashit(wc_get_account_endpoint_url(SA_RET_EP)) . $rid . '/';
}

function sa_ret_email_customer(int $rid, string $status, string $note = ''): void
{
    $to = (string) sa_ret_meta($rid, 'email', '');
    if (!is_email($to)) {
        return;
    }
    $ref = sa_ret_ref($rid);
    $oid = (int) sa_ret_meta($rid, 'order', 0);
    $o = $oid ? wc_get_order($oid) : null;
    $name = (string) sa_ret_meta($rid, 'name', '');
    $greet = function_exists('sa_email_greeting') ? sa_email_greeting($name, $to) : 'Hello,';
    $noteh = $note !== '' ? '<p style="margin:12px 0;padding:10px 12px;background:#f6f6f4;border-radius:6px">' . nl2br(esc_html($note)) . '</p>' : '';
    $ordl = $o ? 'order #' . esc_html($o->get_order_number()) : 'your order';
    $head = 'Return update';
    $body = '';
    $cta = ['View your return', sa_ret_view_url($rid)];
    $subj = 'Return ' . $ref . ': ' . sa_ret_statuses()[$status];
    switch ($status) {
        case 'requested':
            $head = 'We received your return request';
            $body = '<p>We have your return request for ' . $ordl . '. We usually review requests within one business day and will email you as soon as it is approved.</p>';
            break;
        case 'approved':
            $head = 'Your return is approved';
            $body = '<p>Good news, your return for ' . $ordl . ' is approved. Next, choose how to send the item back: ship it to us with a tracking number' . ($o && sa_ret_address($o)['dropoff'] ? ', or drop it off at our shop' : '') . '.</p>' . $noteh;
            $cta = ['Choose how to return', sa_ret_view_url($rid)];
            break;
        case 'rejected':
            $head = 'We could not approve this return';
            $body = '<p>We reviewed your return request for ' . $ordl . ' and could not approve it.</p>' . $noteh . '<p>If you think this is a mistake, contact us and we will look again.</p>';
            $cta = ['Contact us', $o ? sa_ret_contact_url($o) : home_url('/contact/')];
            break;
        case 'shipped':
            $head = 'We have your tracking number';
            $body = '<p>Thanks. We saved your tracking number for ' . $ordl . ': <strong>' . esc_html((string) sa_ret_meta($rid, 'tracking', '')) . '</strong> (' . esc_html((string) sa_ret_meta($rid, 'courier', 'courier')) . '). We will update you when the parcel arrives.</p>' . $noteh;
            break;
        case 'dropoff':
            $head = 'Drop-off planned';
            $body = '<p>Bring your item to our shop and show this return code:</p><p style="font-size:22px;font-weight:700;letter-spacing:2px;margin:8px 0">' . esc_html((string) sa_ret_meta($rid, 'code', '')) . '</p>' . ($o ? '<p>' . esc_html(sa_ret_address($o)['line']) . '<br>' . esc_html(sa_ret_address($o)['hours']) . '</p>' : '') . $noteh;
            break;
        case 'received':
            $head = 'We received your return';
            $body = '<p>Your item for ' . $ordl . ' has arrived. We will inspect it and update you shortly.</p>' . $noteh;
            break;
        case 'inspected':
            $head = 'Your return was inspected';
            $body = '<p>We finished checking your item for ' . $ordl . '. Your refund is being prepared.</p>' . $noteh;
            break;
        case 'refunded':
            $head = 'Your refund is on its way';
            $body = '<p>We issued your refund for ' . $ordl . '. Depending on your bank or mobile money provider it can take a few days to show.</p>' . $noteh;
            break;
        case 'cancelled':
            $head = 'Return request cancelled';
            $body = '<p>Your return request for ' . $ordl . ' was cancelled.</p>' . $noteh;
            break;
    }
    $inner = '<!--sa-return-mail--><p>' . $greet . '</p>' . $body . '<p style="margin:12px 0 4px;color:#666;font-size:13px">Return reference <strong>' . esc_html($ref) . '</strong></p>' . sa_ret_items_html($rid);
    $html = function_exists('sa_mail_layout') ? sa_mail_layout($head, $inner, $cta[0], $cta[1]) : '<h1>' . esc_html($head) . '</h1>' . $inner;
    wp_mail($to, $subj, $html, ['Content-Type: text/html; charset=UTF-8']);
}

function sa_ret_email_team(int $rid, string $status, string $note = ''): void
{
    $to = function_exists('sa_core_store_email') && is_email(sa_core_store_email()) ? sa_core_store_email() : (string) get_option('admin_email');
    $lab = sa_ret_statuses()[$status];
    $inner = '<p><strong>' . esc_html((string) sa_ret_meta($rid, 'name', '')) . '</strong> &lt;' . esc_html((string) sa_ret_meta($rid, 'email', '')) . '&gt; ' . esc_html((string) sa_ret_meta($rid, 'phone', '')) . '</p><p>Order #' . esc_html((string) sa_ret_meta($rid, 'order_no', '')) . ', status: <strong>' . esc_html($lab) . '</strong></p>'
        . ($note !== '' ? '<p>' . nl2br(esc_html($note)) . '</p>' : '') . sa_ret_items_html($rid);
    $t = (string) sa_ret_meta($rid, 'tracking', '');
    if ($t !== '') {
        $inner .= '<p>Tracking: <strong>' . esc_html($t) . '</strong> (' . esc_html((string) sa_ret_meta($rid, 'courier', '')) . ')</p>';
    }
    $html = function_exists('sa_mail_layout') ? sa_mail_layout('Return ' . sa_ret_ref($rid) . ': ' . $lab, $inner, 'Open return', admin_url('post.php?post=' . $rid . '&action=edit')) : $inner;
    wp_mail($to, '[' . sa_ret_ref($rid) . '] Return ' . strtolower($lab) . ', order #' . sa_ret_meta($rid, 'order_no', ''), $html, ['Content-Type: text/html; charset=UTF-8']);
}

/* ---------- My Account endpoint ---------- */

add_action('init', static function (): void {
    add_rewrite_endpoint(SA_RET_EP, EP_PAGES);
}, 12);
add_filter('woocommerce_get_query_vars', static function (array $v): array {
    $v[SA_RET_EP] = SA_RET_EP;
    return $v;
});
add_action('init', static function (): void {
    if (get_option('sa_ret_ep_ver') === '1') {
        return;
    }
    flush_rewrite_rules(false);
    update_option('sa_ret_ep_ver', '1');
}, 99);
add_filter('woocommerce_account_menu_items', static function (array $items): array {
    $out = [];
    foreach ($items as $k => $v) {
        $out[$k] = $v;
        if ($k === 'orders') {
            $out[SA_RET_EP] = 'Returns';
        }
    }
    if (!isset($out[SA_RET_EP])) {
        $out[SA_RET_EP] = 'Returns';
    }
    return $out;
}, 30);
add_filter('woocommerce_endpoint_' . SA_RET_EP . '_title', static fn() => 'Returns');

function sa_ret_new_url(WC_Order $o): string
{
    return add_query_arg('order', $o->get_id(), trailingslashit(wc_get_account_endpoint_url(SA_RET_EP)) . 'new/');
}

/* Buttons on the order list and the order page. */
add_filter('woocommerce_my_account_my_orders_actions', static function (array $a, $o): array {
    if ($o instanceof WC_Order && sa_ret_state($o)['ok'] && sa_ret_available($o)) {
        $a['sa_return'] = ['url' => sa_ret_new_url($o), 'name' => 'Return'];
    }
    return $a;
}, 20, 2);
add_action('woocommerce_view_order', static function ($order_id): void {
    $o = wc_get_order($order_id);
    if (!$o instanceof WC_Order || (int) $o->get_customer_id() !== get_current_user_id()) {
        return;
    }
    $st = sa_ret_state($o);
    echo '<section class="sa-ret-box" style="margin:0 0 24px;padding:14px 16px;border:1px solid #e4e3de;border-radius:12px"><strong>Returns and refunds</strong>';
    foreach (sa_ret_for_order($o->get_id()) as $rid) {
        echo '<p style="margin:8px 0 0"><a href="' . esc_url(sa_ret_view_url($rid)) . '">' . esc_html(sa_ret_ref($rid)) . '</a> <span style="font-size:12px;font-weight:700;padding:2px 8px;border-radius:99px;' . esc_attr(sa_ret_pill_colors(sa_ret_status($rid))) . '">' . esc_html(sa_ret_statuses()[sa_ret_status($rid)]) . '</span></p>';
    }
    echo '<p style="margin:8px 0 0;color:#555">' . esc_html($st['msg']) . '</p>';
    if ($st['ok'] && sa_ret_available($o)) {
        echo '<p style="margin:10px 0 0"><a class="sa-btn button" href="' . esc_url(sa_ret_new_url($o)) . '">Start a return</a></p>';
    } elseif (!$st['ok'] && in_array($st['why'], ['expired', 'unpaid', 'cancelled'], true)) {
        echo '<p style="margin:10px 0 0"><a href="' . esc_url(sa_ret_contact_url($o)) . '">Contact us about this order</a></p>';
    }
    echo '</section>';
}, 6);

/* The old open form is replaced: sign in first, then use the account flow. */
add_action('template_redirect', static function (): void {
    if ((string) get_query_var('sa_sf_view') !== 'return') {
        return;
    }
    if (is_user_logged_in()) {
        wp_safe_redirect(trailingslashit(wc_get_account_endpoint_url(SA_RET_EP)) . 'new/' . (isset($_GET['order']) ? '?order=' . absint($_GET['order']) : ''));
    } else {
        wp_safe_redirect(add_query_arg('sa_return_login', '1', wc_get_page_permalink('myaccount')));
    }
    exit;
}, 5);
add_action('woocommerce_before_customer_login_form', static function (): void {
    if (!empty($_GET['sa_return_login'])) {
        echo '<div class="woocommerce-info" role="status" style="margin:0 0 16px"><strong>Sign in to start a return.</strong> We email you a 6-digit code, no password needed. After signing in, open <em>Returns</em> in your account and pick the order.</div>';
    }
});

/* ---------- POST handling (before output so we can redirect) ---------- */

function sa_ret_upload(int $rid): array
{
    if (empty($_FILES['photos']['name']) || !is_array($_FILES['photos']['name'])) {
        return [];
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    $out = [];
    $allowed = ['jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'heic' => 'image/heic', 'heif' => 'image/heif', 'pdf' => 'application/pdf'];
    $sub = static function (array $d) use ($rid): array {
        $d['subdir'] = '/sa-returns/' . $rid;
        $d['path'] = $d['basedir'] . $d['subdir'];
        $d['url'] = $d['baseurl'] . $d['subdir'];
        return $d;
    };
    add_filter('upload_dir', $sub);
    $n = min(5, count($_FILES['photos']['name']));
    for ($i = 0; $i < $n; $i++) {
        if ((int) $_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK || (int) $_FILES['photos']['size'][$i] > 8 * MB_IN_BYTES) {
            continue;
        }
        $f = ['name' => wp_generate_password(8, false) . '-' . sanitize_file_name((string) $_FILES['photos']['name'][$i]), 'type' => $_FILES['photos']['type'][$i], 'tmp_name' => $_FILES['photos']['tmp_name'][$i], 'error' => 0, 'size' => $_FILES['photos']['size'][$i]];
        $r = wp_handle_upload($f, ['test_form' => false, 'mimes' => $allowed]);
        if (!empty($r['url'])) {
            $out[] = ['name' => sanitize_file_name((string) $_FILES['photos']['name'][$i]), 'url' => (string) $r['url']];
        }
    }
    remove_filter('upload_dir', $sub);
    return $out;
}

add_action('template_redirect', static function (): void {
    if (empty($_POST['sa_ret_do']) || !is_user_logged_in() || !function_exists('wc_get_order')) {
        return;
    }
    $uid = get_current_user_id();
    $do = sanitize_key((string) $_POST['sa_ret_do']);
    $base = trailingslashit(wc_get_account_endpoint_url(SA_RET_EP));
    $fail = static function (string $m): void {
        $GLOBALS['sa_ret_err'] = $m;
        $GLOBALS['sa_ret_post'] = wp_unslash($_POST);
    };
    if (!wp_verify_nonce((string) ($_POST['sa_ret_nonce'] ?? ''), 'sa_ret_' . $do)) {
        $fail('Your session expired. Please try again.');
        return;
    }
    $g = static fn(string $k, int $max = 200): string => mb_substr(sanitize_text_field(wp_unslash((string) ($_POST[$k] ?? ''))), 0, $max);

    if ($do === 'submit') {
        $o = wc_get_order(absint($_POST['order_id'] ?? 0));
        if (!$o instanceof WC_Order || (int) $o->get_customer_id() !== $uid) {
            $fail('We could not find that order in your account.');
            return;
        }
        $st = sa_ret_state($o);
        if (!$st['ok']) {
            $fail($st['msg']);
            return;
        }
        $avail = sa_ret_available($o);
        $reasons = sa_ret_reasons();
        $items = [];
        $needphoto = false;
        foreach ((array) ($_POST['pick'] ?? []) as $iid => $_on) {
            $iid = (int) $iid;
            if (!isset($avail[$iid])) {
                continue;
            }
            $qty = max(1, min($avail[$iid]['qty'], (int) ($_POST['qty'][$iid] ?? 1)));
            $rk = sanitize_key((string) ($_POST['reason'][$iid] ?? ''));
            if (!isset($reasons[$rk])) {
                $fail('Choose a reason for each item you are returning.');
                return;
            }
            $needphoto = $needphoto || $reasons[$rk][1];
            $items[] = ['item_id' => $iid, 'name' => $avail[$iid]['name'], 'qty' => $qty, 'reason' => $rk];
        }
        if (!$items) {
            $fail('Select at least one item to return.');
            return;
        }
        $phone = $g('phone', 40);
        if ($phone !== '' && !preg_match('/^\+?[0-9][0-9 ()\-]{7,18}$/', $phone)) {
            $fail('That phone number does not look right. Use digits with an optional +, for example 0712 345 678.');
            return;
        }
        $msg = mb_substr(sanitize_textarea_field(wp_unslash((string) ($_POST['message'] ?? ''))), 0, 3000);
        $need_msg = array_filter($items, static fn($i) => in_array($i['reason'], ['other', 'not_as_described', 'defective', 'missing'], true));
        if ($need_msg && trim($msg) === '') {
            $fail('Please tell us a little more about what happened.');
            return;
        }
        $res = in_array(($_POST['resolution'] ?? ''), ['refund', 'replacement'], true) ? (string) $_POST['resolution'] : 'refund';
        $rid = wp_insert_post(['post_type' => 'sa_return', 'post_status' => 'publish', 'post_title' => 'Return for order #' . $o->get_order_number(), 'post_author' => $uid], true);
        if (is_wp_error($rid) || !$rid) {
            $fail('Could not save your request. Please try again or contact us.');
            return;
        }
        $photos = sa_ret_upload((int) $rid);
        if ($needphoto && !$photos) {
            wp_delete_post((int) $rid, true);
            $fail('A photo is needed for the reason you chose. Add one (JPG, PNG, WebP or PDF, up to 8 MB each).');
            return;
        }
        $u = wp_get_current_user();
        $m = ['order' => $o->get_id(), 'order_no' => $o->get_order_number(), 'user' => $uid, 'items' => $items, 'resolution' => $res, 'message' => $msg, 'vehicle' => $g('vehicle', 120), 'photos' => $photos,
            'name' => trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name()) ?: $u->display_name, 'email' => $u->user_email, 'phone' => $phone, 'basis' => $st['basis']];
        foreach ($m as $k => $v) {
            update_post_meta((int) $rid, '_sa_r_' . $k, $v);
        }
        wp_update_post(['ID' => $rid, 'post_title' => sa_ret_ref((int) $rid) . ' - order #' . $o->get_order_number()]);
        sa_ret_set_status((int) $rid, 'requested', '', 'customer');
        wp_safe_redirect(add_query_arg('sa_ret_msg', 'sent', $base . (int) $rid . '/'));
        exit;
    }

    $rid = absint($_POST['return_id'] ?? 0);
    $p = get_post($rid);
    if (!$p || $p->post_type !== 'sa_return' || (int) sa_ret_meta($rid, 'user', 0) !== $uid) {
        $fail('We could not find that return.');
        return;
    }
    $o = wc_get_order((int) sa_ret_meta($rid, 'order', 0));
    $cur = sa_ret_status($rid);
    $back = $base . $rid . '/';
    if ($do === 'cancel' && $cur === 'requested') {
        sa_ret_set_status($rid, 'cancelled', 'Cancelled by you.', 'customer');
        wp_safe_redirect(add_query_arg('sa_ret_msg', 'cancelled', $back));
        exit;
    }
    if ($do === 'ship' && in_array($cur, ['approved', 'shipped'], true)) {
        $trk = trim((string) preg_replace('/[^A-Za-z0-9\- ]/', '', $g('tracking', 60)));
        if (strlen($trk) < 4) {
            $GLOBALS['sa_ret_err'] = 'Enter the tracking number from your courier receipt.';
            return;
        }
        $couriers = sa_ret_courier_options();
        $ck = sanitize_key((string) ($_POST['courier'] ?? ''));
        $cl = $couriers[$ck] ?? 'Courier';
        update_post_meta($rid, '_sa_r_method', 'ship');
        update_post_meta($rid, '_sa_r_courier', $cl);
        update_post_meta($rid, '_sa_r_tracking', $trk);
        sa_ret_set_status($rid, 'shipped', 'Tracking number ' . $trk . ' (' . $cl . ').', 'customer');
        wp_safe_redirect(add_query_arg('sa_ret_msg', 'shipped', $back));
        exit;
    }
    if ($do === 'dropoff' && $cur === 'approved' && $o instanceof WC_Order && sa_ret_address($o)['dropoff']) {
        update_post_meta($rid, '_sa_r_method', 'dropoff');
        update_post_meta($rid, '_sa_r_code', 'RTN-' . $rid . '-' . strtoupper(wp_generate_password(4, false, false)));
        sa_ret_set_status($rid, 'dropoff', 'You will drop the item off at our shop.', 'customer');
        wp_safe_redirect(add_query_arg('sa_ret_msg', 'dropoff', $back));
        exit;
    }
    $fail('That action is not available for this return right now.');
}, 6);

/* ---------- rendering ---------- */

add_action('woocommerce_account_' . SA_RET_EP . '_endpoint', static function ($value): void {
    echo sa_ret_css(); // phpcs:ignore
    $value = (string) $value;
    if (!empty($GLOBALS['sa_ret_err'])) {
        echo '<div class="sa-ret-alert" role="alert">' . esc_html((string) $GLOBALS['sa_ret_err']) . '</div>';
    }
    if ($value === 'new') {
        sa_ret_render_new();
    } elseif ($value !== '' && ctype_digit($value)) {
        sa_ret_render_detail((int) $value);
    } else {
        sa_ret_render_list();
    }
});

function sa_ret_css(): string
{
    return '<style id="sa-ret-css">.sa-ret h3{margin:0 0 8px}.sa-ret-card{border:1px solid #e4e3de;border-radius:12px;padding:14px 16px;margin:0 0 12px;background:#fff}.sa-ret-card.is-off{background:#fafaf8;color:#6b7280}.sa-ret-row{display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap}.sa-ret-pill{display:inline-block;font-size:12px;font-weight:700;padding:3px 10px;border-radius:99px}.sa-ret-alert{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:12px 14px;margin:0 0 14px}.sa-ret-ok{background:#ecfdf3;border:1px solid #a7f3c4;color:#065f46;border-radius:10px;padding:12px 14px;margin:0 0 14px}.sa-ret-steps{display:flex;gap:6px;list-style:none;padding:0;margin:0 0 18px;flex-wrap:wrap}.sa-ret-steps li{flex:1 1 92px;min-width:92px;padding:8px 10px;border-radius:8px;font-size:12px;font-weight:600;border:1px solid #e4e4e4;background:#fff;color:#888}.sa-ret-steps li.is-done{border-color:#0a0a0a;background:#f3f4f6;color:#111}.sa-ret-steps li.is-now{border-color:#F5A623;background:#fff7e6;color:#111}.sa-ret-tl{list-style:none;padding:0;margin:0;border-left:2px solid #e4e4e4}.sa-ret-tl li{padding:0 0 12px 14px;position:relative}.sa-ret-tl li:before{content:"";position:absolute;left:-6px;top:5px;width:10px;height:10px;border-radius:50%;background:#0a0a0a}.sa-ret-item{display:grid;grid-template-columns:auto 1fr;gap:10px;align-items:start;padding:12px 0;border-bottom:1px solid #eee}.sa-ret-item:last-of-type{border-bottom:0}.sa-ret-item .sa-ret-ctl{display:none;grid-column:2;gap:10px;flex-wrap:wrap}.sa-ret-item.is-on .sa-ret-ctl{display:flex}.sa-ret-ctl label{display:flex;flex-direction:column;gap:4px;font-size:13px;font-weight:600}.sa-ret-ctl select{min-width:200px;max-width:100%}.sa-ret-hint{font-size:13px;color:#6b7280;margin:2px 0 0}.sa-ret-code{font-size:26px;font-weight:800;letter-spacing:2px;margin:6px 0;word-break:break-all}.sa-ret-two{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px}.sa-ret form label{display:flex;flex-direction:column;gap:4px;font-size:13px;font-weight:600;margin:0 0 10px}.sa-ret form input[type=text],.sa-ret form input[type=tel],.sa-ret form select,.sa-ret form textarea{width:100%}</style>';
}

function sa_ret_pill(string $s): string
{
    return '<span class="sa-ret-pill" style="' . esc_attr(sa_ret_pill_colors($s)) . '">' . esc_html(sa_ret_statuses()[$s] ?? $s) . '</span>';
}

function sa_ret_render_list(): void
{
    $uid = get_current_user_id();
    echo '<div class="sa-ret"><h3>Returns and refunds</h3>';
    if (($_GET['sa_ret_msg'] ?? '') === 'cancelled') {
        echo '<div class="sa-ret-ok">Your return request was cancelled.</div>';
    }
    echo '<p>Returns can be started within ' . SA_RET_DAYS . ' days of delivery. Pick an order and the items you want to send back.</p><p><a class="sa-btn button" href="' . esc_url(trailingslashit(wc_get_account_endpoint_url(SA_RET_EP)) . 'new/') . '">Start a return</a></p>';
    $ids = get_posts(['post_type' => 'sa_return', 'post_status' => 'any', 'numberposts' => 50, 'fields' => 'ids', 'meta_key' => '_sa_r_user', 'meta_value' => $uid, 'orderby' => 'date', 'order' => 'DESC']);
    if (!$ids) {
        echo '<p class="sa-ret-hint">No return requests yet.</p></div>';
        return;
    }
    foreach ($ids as $rid) {
        $rid = (int) $rid;
        $n = array_sum(array_map(static fn($i) => (int) $i['qty'], (array) sa_ret_meta($rid, 'items', [])));
        echo '<div class="sa-ret-card"><div class="sa-ret-row"><div><strong><a href="' . esc_url(sa_ret_view_url($rid)) . '">' . esc_html(sa_ret_ref($rid)) . '</a></strong> &middot; Order #' . esc_html((string) sa_ret_meta($rid, 'order_no', '')) . '<div class="sa-ret-hint">' . (int) $n . ' item' . ($n === 1 ? '' : 's') . ' &middot; ' . esc_html(get_the_date('j M Y', $rid)) . '</div></div>' . sa_ret_pill(sa_ret_status($rid)) . '</div></div>'; // phpcs:ignore
    }
    echo '</div>';
}

function sa_ret_render_new(): void
{
    $uid = get_current_user_id();
    $oid = absint($_GET['order'] ?? ($_POST['order_id'] ?? 0));
    $base = trailingslashit(wc_get_account_endpoint_url(SA_RET_EP));
    echo '<div class="sa-ret">';
    if ($oid) {
        $o = wc_get_order($oid);
        if ($o instanceof WC_Order && (int) $o->get_customer_id() === $uid) {
            sa_ret_render_form($o);
            echo '</div>';
            return;
        }
        echo '<div class="sa-ret-alert">We could not find that order in your account.</div>';
    }
    echo '<h3>Which order is this about?</h3><p class="sa-ret-hint" style="margin:0 0 14px">Orders in your account are listed below. You can start a return within ' . SA_RET_DAYS . ' days of delivery.</p>';
    $orders = wc_get_orders(['customer_id' => $uid, 'limit' => 30, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order']);
    if (!$orders) {
        echo '<p>You have no orders in this account yet. If you ordered as a guest, <a href="' . esc_url(wc_get_account_endpoint_url('orders')) . '">confirm your email</a> to link past orders.</p></div>';
        return;
    }
    foreach ($orders as $o) {
        $st = sa_ret_state($o);
        $avail = $st['ok'] ? sa_ret_available($o) : [];
        $ok = $st['ok'] && $avail;
        $names = implode(', ', array_map(static fn($i) => $i->get_name(), array_slice(array_values($o->get_items()), 0, 3)));
        echo '<div class="sa-ret-card' . ($ok ? '' : ' is-off') . '"><div class="sa-ret-row"><div><strong>Order #' . esc_html($o->get_order_number()) . '</strong> &middot; ' . esc_html(wc_format_datetime($o->get_date_created(), 'j M Y')) . ' &middot; ' . esc_html(wc_get_order_status_name($o->get_status())) . '<div class="sa-ret-hint">' . esc_html($names) . '</div>'
            . '<div class="sa-ret-hint">' . esc_html($ok ? $st['msg'] : ($st['ok'] ? 'Every item in this order is already in a return or refunded.' : $st['msg'])) . '</div></div>';
        if ($ok) {
            echo '<a class="sa-btn button" href="' . esc_url(sa_ret_new_url($o)) . '">Select</a>';
        } elseif (in_array($st['why'], ['expired', 'unpaid', 'cancelled'], true)) {
            echo '<a class="button" href="' . esc_url(sa_ret_contact_url($o)) . '">Contact us</a>';
        }
        echo '</div></div>';
    }
    echo '</div>';
}

function sa_ret_render_form(WC_Order $o): void
{
    $st = sa_ret_state($o);
    $base = trailingslashit(wc_get_account_endpoint_url(SA_RET_EP));
    echo '<p><a href="' . esc_url($base . 'new/') . '">&larr; Choose a different order</a></p><h3>Return from order #' . esc_html($o->get_order_number()) . '</h3>';
    if (!$st['ok']) {
        echo '<div class="sa-ret-alert">' . esc_html($st['msg']) . '</div><p><a class="button" href="' . esc_url(sa_ret_contact_url($o)) . '">Contact us with your issue</a></p>';
        return;
    }
    $avail = sa_ret_available($o);
    if (!$avail) {
        echo '<div class="sa-ret-alert">Every item in this order is already in a return or refunded.</div>';
        return;
    }
    $P = $GLOBALS['sa_ret_post'] ?? [];
    $u = wp_get_current_user();
    $name = trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name()) ?: $u->display_name;
    $phone = (string) ($P['phone'] ?? $o->get_billing_phone());
    $reasons = sa_ret_reasons();
    echo '<p class="sa-ret-hint" style="margin:0 0 12px">' . esc_html($st['msg']) . '</p>';
    echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(sa_ret_new_url($o)) . '" data-sa-ret-form>';
    wp_nonce_field('sa_ret_submit', 'sa_ret_nonce');
    echo '<input type="hidden" name="sa_ret_do" value="submit"><input type="hidden" name="order_id" value="' . (int) $o->get_id() . '">';
    echo '<h4 style="margin:0 0 6px">1. Which items?</h4>';
    foreach ($avail as $iid => $it) {
        $on = isset($P['pick'][$iid]);
        $img = '';
        if ($it['product'] && function_exists('wc_get_product')) {
            $pr = wc_get_product($it['product']);
            $img = $pr ? $pr->get_image('thumbnail', ['style' => 'width:56px;height:56px;object-fit:cover;border-radius:8px']) : '';
        }
        echo '<div class="sa-ret-item' . ($on ? ' is-on' : '') . '" data-sa-ret-item><label style="flex-direction:row;align-items:center;gap:8px;margin:0"><input type="checkbox" name="pick[' . (int) $iid . ']" value="1"' . ($on ? ' checked' : '') . '></label><div style="display:flex;gap:10px;align-items:center">' . $img . '<div><strong>' . esc_html($it['name']) . '</strong><div class="sa-ret-hint">Qty you can return: ' . (int) $it['qty'] . '</div></div></div>'; // phpcs:ignore
        echo '<div class="sa-ret-ctl">';
        if ($it['qty'] > 1) {
            echo '<label>How many<select name="qty[' . (int) $iid . ']">';
            for ($q = 1; $q <= $it['qty']; $q++) {
                echo '<option value="' . $q . '"' . ((int) ($P['qty'][$iid] ?? 1) === $q ? ' selected' : '') . '>' . $q . '</option>';
            }
            echo '</select></label>';
        }
        echo '<label>Reason<select name="reason[' . (int) $iid . ']" data-sa-ret-reason><option value="">Choose a reason</option>';
        foreach ($reasons as $k => $r) {
            echo '<option value="' . esc_attr($k) . '" data-photo="' . ($r[1] ? '1' : '0') . '" data-hint="' . esc_attr($r[2]) . '"' . (($P['reason'][$iid] ?? '') === $k ? ' selected' : '') . '>' . esc_html($r[0]) . '</option>';
        }
        echo '</select><span class="sa-ret-hint" data-sa-ret-hint></span></label></div></div>';
    }
    echo '<h4 style="margin:18px 0 6px">2. Details</h4>';
    echo '<label>Vehicle (helps us check fitment)<input type="text" name="vehicle" value="' . esc_attr((string) ($P['vehicle'] ?? '')) . '" placeholder="e.g. 2012 Toyota Premio 1NZ"></label>';
    echo '<label>What happened?<textarea name="message" rows="4" placeholder="Tell us what is wrong or what you need.">' . esc_textarea((string) ($P['message'] ?? '')) . '</textarea></label>';
    echo '<label>Photos <span class="sa-ret-hint" data-sa-ret-photo-need>Optional. JPG, PNG, WebP or PDF, up to 5 files, 8 MB each.</span><input type="file" name="photos[]" accept="image/*,.pdf" multiple></label>';
    echo '<fieldset style="border:0;padding:0;margin:0 0 10px"><legend style="font-size:13px;font-weight:600;margin:0 0 4px">What would you like?</legend><label style="flex-direction:row;gap:8px;font-weight:500"><input type="radio" name="resolution" value="refund"' . (($P['resolution'] ?? 'refund') === 'refund' ? ' checked' : '') . '> Refund</label><label style="flex-direction:row;gap:8px;font-weight:500"><input type="radio" name="resolution" value="replacement"' . (($P['resolution'] ?? '') === 'replacement' ? ' checked' : '') . '> Replacement part</label></fieldset>';
    echo '<h4 style="margin:18px 0 6px">3. Your contact details</h4><div class="sa-ret-two"><label>Name<input type="text" value="' . esc_attr($name) . '" readonly></label><label>Email<input type="text" value="' . esc_attr($u->user_email) . '" readonly></label></div><label>Phone<input type="tel" name="phone" value="' . esc_attr($phone) . '"></label>';
    echo '<button type="submit" class="sa-btn button alt">Send return request</button><p class="sa-ret-hint" style="margin-top:10px">We review requests within one business day. If approved, you choose how to send the item back and follow every step here in your account.</p></form>';
    echo '<script>(function(){var f=document.querySelector("[data-sa-ret-form]");if(!f)return;function upd(){var need=false;f.querySelectorAll("[data-sa-ret-item]").forEach(function(r){var c=r.querySelector("input[type=checkbox]");r.classList.toggle("is-on",c.checked);var s=r.querySelector("[data-sa-ret-reason]"),h=r.querySelector("[data-sa-ret-hint]"),o=s.options[s.selectedIndex];h.textContent=c.checked&&o&&o.dataset.hint?o.dataset.hint:"";if(c.checked&&o&&o.dataset.photo==="1")need=true;});var n=f.querySelector("[data-sa-ret-photo-need]");n.textContent=need?"Required for the reason you chose. JPG, PNG, WebP or PDF, up to 5 files, 8 MB each.":"Optional. JPG, PNG, WebP or PDF, up to 5 files, 8 MB each.";}f.addEventListener("change",upd);upd();f.addEventListener("submit",function(e){var any=f.querySelector("input[type=checkbox]:checked");if(!any){e.preventDefault();alert("Select at least one item to return.");return;}var bad=false;f.querySelectorAll("[data-sa-ret-item]").forEach(function(r){if(r.querySelector("input[type=checkbox]").checked&&!r.querySelector("[data-sa-ret-reason]").value)bad=true;});if(bad){e.preventDefault();alert("Choose a reason for each item you are returning.");}});})();</script>';
}

function sa_ret_steps(int $rid): array
{
    $m = (string) sa_ret_meta($rid, 'method', '');
    $mid = $m === 'dropoff' ? ['dropoff', 'Drop-off planned'] : ['shipped', 'Return shipped'];
    return [['requested', 'Requested'], ['approved', 'Approved'], $mid, ['received', 'Item received'], ['inspected', 'Inspected'], ['refunded', 'Refunded']];
}

function sa_ret_render_detail(int $rid): void
{
    $p = get_post($rid);
    if (!$p || $p->post_type !== 'sa_return' || (int) sa_ret_meta($rid, 'user', 0) !== get_current_user_id()) {
        echo '<div class="sa-ret-alert">We could not find that return.</div>';
        return;
    }
    $o = wc_get_order((int) sa_ret_meta($rid, 'order', 0));
    $s = sa_ret_status($rid);
    $base = trailingslashit(wc_get_account_endpoint_url(SA_RET_EP));
    $msgs = ['sent' => 'Request sent. We emailed you a copy and will review it within one business day.', 'shipped' => 'Tracking number saved. We will update this page when the parcel arrives.', 'dropoff' => 'Drop-off planned. Show the code below at our shop.', 'cancelled' => 'Your return request was cancelled.'];
    echo '<div class="sa-ret"><p><a href="' . esc_url($base) . '">&larr; All returns</a></p>';
    $mk = (string) ($_GET['sa_ret_msg'] ?? '');
    if (isset($msgs[$mk])) {
        echo '<div class="sa-ret-ok">' . esc_html($msgs[$mk]) . '</div>';
    }
    echo '<div class="sa-ret-row" style="margin:0 0 12px"><h3 style="margin:0">Return ' . esc_html(sa_ret_ref($rid)) . '</h3>' . sa_ret_pill($s) . '</div>'; // phpcs:ignore
    echo '<p style="margin:0 0 14px">Order #' . esc_html((string) sa_ret_meta($rid, 'order_no', '')) . ($o ? ' &middot; <a href="' . esc_url($o->get_view_order_url()) . '">View order</a>' : '') . ' &middot; ' . (sa_ret_meta($rid, 'resolution', 'refund') === 'replacement' ? 'Replacement requested' : 'Refund requested') . '</p>';

    if (!in_array($s, ['rejected', 'cancelled'], true)) {
        $seen = array_column((array) sa_ret_meta($rid, 'timeline', []), 's');
        echo '<ol class="sa-ret-steps">';
        foreach (sa_ret_steps($rid) as $i => [$k, $l]) {
            $done = in_array($k, $seen, true) && $k !== $s;
            echo '<li class="' . ($k === $s ? 'is-now' : ($done ? 'is-done' : '')) . '">' . ($done ? '&#10003; ' : ($i + 1) . '. ') . esc_html($l) . '</li>';
        }
        echo '</ol>';
    }

    $addr = $o ? sa_ret_address($o) : ['line' => '', 'phone' => '', 'dropoff' => false, 'hours' => ''];
    $ref = sa_ret_ref($rid);
    if ($s === 'requested') {
        echo '<div class="sa-ret-card"><strong>We are reviewing your request.</strong><p class="sa-ret-hint">Usually within one business day. We email you when it changes.</p><form method="post">' . wp_nonce_field('sa_ret_cancel', 'sa_ret_nonce', true, false) . '<input type="hidden" name="sa_ret_do" value="cancel"><input type="hidden" name="return_id" value="' . $rid . '"><button class="button" type="submit" onclick="return confirm(\'Cancel this return request?\')">Cancel request</button></form></div>'; // phpcs:ignore
    } elseif ($s === 'approved' || $s === 'shipped') {
        $ship = '<h4 style="margin:0 0 4px">Ship it back</h4><p style="margin:0 0 8px">Pack it securely and write <strong>' . esc_html($ref) . '</strong> on the parcel. Send it to:</p><p style="margin:0 0 10px;padding:10px 12px;background:#f6f6f4;border-radius:8px"><strong>Supreme Autoparts, Returns ' . esc_html($ref) . '</strong><br>' . esc_html($addr['line']) . '<br>' . esc_html($addr['phone']) . '</p>'
            . '<form method="post">' . wp_nonce_field('sa_ret_ship', 'sa_ret_nonce', true, false) . '<input type="hidden" name="sa_ret_do" value="ship"><input type="hidden" name="return_id" value="' . $rid . '"><label>Courier<select name="courier">';
        foreach (sa_ret_courier_options() as $k => $l) {
            $ship .= '<option value="' . esc_attr((string) $k) . '">' . esc_html($l) . '</option>';
        }
        $ship .= '</select></label><label>Tracking number<input type="text" name="tracking" value="' . esc_attr((string) sa_ret_meta($rid, 'tracking', '')) . '" required></label><button class="sa-btn button alt" type="submit">' . ($s === 'shipped' ? 'Update tracking number' : 'Save tracking number') . '</button></form>';
        echo '<div class="sa-ret-card"><strong>' . ($s === 'approved' ? 'Approved. Choose how to return the item.' : 'Your parcel is on its way to us.') . '</strong>';
        if ($s === 'approved') {
            echo '<div class="sa-ret-two" style="margin-top:10px"><div class="sa-ret-card" style="margin:0">' . $ship . '</div>'; // phpcs:ignore
            if ($addr['dropoff']) {
                echo '<div class="sa-ret-card" style="margin:0"><h4 style="margin:0 0 4px">Drop it off at our shop</h4><p style="margin:0 0 8px">' . esc_html($addr['line']) . '<br>' . esc_html($addr['hours']) . '</p><p class="sa-ret-hint">You get a return code to show at the counter.</p><form method="post">' . wp_nonce_field('sa_ret_dropoff', 'sa_ret_nonce', true, false) . '<input type="hidden" name="sa_ret_do" value="dropoff"><input type="hidden" name="return_id" value="' . $rid . '"><button class="sa-btn button alt" type="submit">I will drop it off</button></form></div>'; // phpcs:ignore
            }
            echo '</div>';
        } else {
            echo '<p style="margin:8px 0">' . esc_html((string) sa_ret_meta($rid, 'courier', 'Courier')) . ' tracking: <strong>' . esc_html((string) sa_ret_meta($rid, 'tracking', '')) . '</strong></p><details><summary>Change tracking number</summary><div style="margin-top:8px">' . $ship . '</div></details>'; // phpcs:ignore
        }
        echo '</div>';
    } elseif ($s === 'dropoff') {
        echo '<div class="sa-ret-card"><strong>Bring the item to our shop</strong><div class="sa-ret-code">' . esc_html((string) sa_ret_meta($rid, 'code', '')) . '</div><p style="margin:0">' . esc_html($addr['line']) . '<br>' . esc_html($addr['hours']) . '</p><p class="sa-ret-hint">Show this code at the counter. Bring the original packaging if you have it.</p></div>';
    } elseif ($s === 'rejected') {
        echo '<div class="sa-ret-card"><strong>This request was declined.</strong><p class="sa-ret-hint">Think this is a mistake? <a href="' . esc_url(sa_ret_contact_url($o instanceof WC_Order ? $o : null)) . '">Contact us</a> and we will look again.</p></div>';
    }

    echo '<h4 style="margin:18px 0 6px">Items</h4><div class="sa-ret-card">';
    $rs = sa_ret_reasons();
    foreach ((array) sa_ret_meta($rid, 'items', []) as $it) {
        echo '<div class="sa-ret-row" style="padding:4px 0"><span>' . esc_html((string) $it['name']) . ' &times; ' . (int) $it['qty'] . '</span><span class="sa-ret-hint">' . esc_html($rs[$it['reason']][0] ?? '') . '</span></div>';
    }
    $msg = (string) sa_ret_meta($rid, 'message', '');
    if ($msg !== '') {
        echo '<p style="margin:8px 0 0;color:#444">' . nl2br(esc_html($msg)) . '</p>';
    }
    foreach ((array) sa_ret_meta($rid, 'photos', []) as $ph) {
        echo '<a href="' . esc_url((string) $ph['url']) . '" target="_blank" rel="noopener" style="margin-right:8px;font-size:13px">' . esc_html((string) $ph['name']) . '</a>';
    }
    echo '</div>';

    echo '<h4 style="margin:18px 0 8px">Progress</h4><ul class="sa-ret-tl">';
    foreach (array_reverse((array) sa_ret_meta($rid, 'timeline', [])) as $e) {
        echo '<li><strong>' . esc_html(sa_ret_statuses()[$e['s']] ?? (string) $e['s']) . '</strong> <small style="color:#6b6b6b">' . esc_html(wp_date('j M Y, g:i A', (int) $e['t'])) . ' EAT</small>' . ((string) ($e['n'] ?? '') !== '' ? '<div style="color:#444;font-size:.9rem">' . esc_html((string) $e['n']) . '</div>' : '') . '</li>';
    }
    echo '</ul></div>';
}

/* ---------- wp-admin ---------- */

add_action('add_meta_boxes_sa_return', static function (): void {
    add_meta_box('sa_ret_box', 'Return request', 'sa_ret_metabox', 'sa_return', 'normal', 'high');
});

function sa_ret_metabox(WP_Post $post): void
{
    $rid = (int) $post->ID;
    $o = wc_get_order((int) sa_ret_meta($rid, 'order', 0));
    $s = sa_ret_status($rid);
    wp_nonce_field('sa_ret_admin', 'sa_ret_admin_nonce');
    $rs = sa_ret_reasons();
    echo '<p><strong>' . esc_html(sa_ret_ref($rid)) . '</strong> &middot; ' . esc_html((string) sa_ret_meta($rid, 'name', '')) . ' &lt;' . esc_html((string) sa_ret_meta($rid, 'email', '')) . '&gt; ' . esc_html((string) sa_ret_meta($rid, 'phone', '')) . '</p>';
    if ($o) {
        echo '<p>Order <a href="' . esc_url($o->get_edit_order_url()) . '">#' . esc_html($o->get_order_number()) . '</a> (' . esc_html(wc_get_order_status_name($o->get_status())) . ', ' . wp_kses_post($o->get_formatted_order_total()) . '). Window counted from the ' . esc_html((string) sa_ret_meta($rid, 'basis', 'order')) . ' date. Issue the refund in WooCommerce on the order, then set this return to Refunded.</p>';
    }
    echo '<table class="widefat striped" style="margin:8px 0"><thead><tr><th>Item</th><th>Qty</th><th>Reason</th></tr></thead><tbody>';
    foreach ((array) sa_ret_meta($rid, 'items', []) as $it) {
        echo '<tr><td>' . esc_html((string) $it['name']) . '</td><td>' . (int) $it['qty'] . '</td><td>' . esc_html($rs[$it['reason']][0] ?? '') . '</td></tr>';
    }
    echo '</tbody></table>';
    echo '<p>Wants: <strong>' . esc_html((string) sa_ret_meta($rid, 'resolution', 'refund')) . '</strong>' . ((string) sa_ret_meta($rid, 'vehicle', '') !== '' ? ' &middot; Vehicle: ' . esc_html((string) sa_ret_meta($rid, 'vehicle', '')) : '') . '</p>';
    if ((string) sa_ret_meta($rid, 'message', '') !== '') {
        echo '<p>' . nl2br(esc_html((string) sa_ret_meta($rid, 'message', ''))) . '</p>';
    }
    foreach ((array) sa_ret_meta($rid, 'photos', []) as $ph) {
        echo '<a href="' . esc_url((string) $ph['url']) . '" target="_blank" rel="noopener">' . esc_html((string) $ph['name']) . '</a> &nbsp; ';
    }
    $m = (string) sa_ret_meta($rid, 'method', '');
    echo '<hr><p><strong>Return method:</strong> ' . ($m === 'ship' ? 'Shipping back. ' . esc_html((string) sa_ret_meta($rid, 'courier', '')) . ' tracking <strong>' . esc_html((string) sa_ret_meta($rid, 'tracking', '')) . '</strong>' : ($m === 'dropoff' ? 'Drop-off. Code <strong>' . esc_html((string) sa_ret_meta($rid, 'code', '')) . '</strong>' : 'Not chosen yet')) . '</p>';
    echo '<p><label><strong>Status</strong><br><select name="sa_ret_status">';
    foreach (sa_ret_statuses() as $k => $l) {
        echo '<option value="' . esc_attr($k) . '"' . selected($s, $k, false) . '>' . esc_html($l) . '</option>';
    }
    echo '</select></label></p><p><label><strong>Note to the customer</strong> (shown in their account and in the email)<br><textarea name="sa_ret_note" rows="3" style="width:100%"></textarea></label></p><p><label><input type="checkbox" name="sa_ret_notify" value="1" checked> Email the customer when the status changes</label></p><p class="description">Save with Update. A changed status adds a timestamped step the customer sees right away.</p>';
    echo '<hr><p><strong>Progress</strong></p><ul>';
    foreach (array_reverse((array) sa_ret_meta($rid, 'timeline', [])) as $e) {
        echo '<li>' . esc_html(wp_date('j M Y, g:i A', (int) $e['t'])) . ' &middot; <strong>' . esc_html(sa_ret_statuses()[$e['s']] ?? (string) $e['s']) . '</strong> (' . esc_html((string) ($e['by'] ?? '')) . ')' . ((string) ($e['n'] ?? '') !== '' ? ' - ' . esc_html((string) $e['n']) : '') . '</li>';
    }
    echo '</ul>';
}

add_action('save_post_sa_return', static function (int $rid, WP_Post $post): void {
    if (!isset($_POST['sa_ret_admin_nonce']) || !wp_verify_nonce((string) $_POST['sa_ret_admin_nonce'], 'sa_ret_admin') || !current_user_can('edit_post', $rid) || wp_is_post_autosave($rid) || wp_is_post_revision($rid)) {
        return;
    }
    $new = sanitize_key((string) ($_POST['sa_ret_status'] ?? ''));
    $note = mb_substr(sanitize_textarea_field(wp_unslash((string) ($_POST['sa_ret_note'] ?? ''))), 0, 1500);
    if (isset(sa_ret_statuses()[$new]) && $new !== sa_ret_status($rid)) {
        sa_ret_set_status($rid, $new, $note, 'staff', !empty($_POST['sa_ret_notify']));
    } elseif ($note !== '') {
        sa_ret_log($rid, sa_ret_status($rid), $note, 'staff');
        if (!empty($_POST['sa_ret_notify'])) {
            sa_ret_email_customer($rid, sa_ret_status($rid), $note);
        }
    }
}, 10, 2);

add_filter('manage_sa_return_posts_columns', static fn(array $c): array => ['cb' => $c['cb'] ?? '', 'title' => 'Return', 'sa_order' => 'Order', 'sa_cust' => 'Customer', 'sa_status' => 'Status', 'sa_method' => 'Method', 'date' => 'Requested']);
add_action('manage_sa_return_posts_custom_column', static function (string $col, int $rid): void {
    if ($col === 'sa_order') {
        $o = wc_get_order((int) sa_ret_meta($rid, 'order', 0));
        echo $o ? '<a href="' . esc_url($o->get_edit_order_url()) . '">#' . esc_html($o->get_order_number()) . '</a>' : '-'; // phpcs:ignore
    } elseif ($col === 'sa_cust') {
        echo esc_html((string) sa_ret_meta($rid, 'name', '')) . '<br><small>' . esc_html((string) sa_ret_meta($rid, 'email', '')) . '</small>';
    } elseif ($col === 'sa_status') {
        echo sa_ret_pill(sa_ret_status($rid)); // phpcs:ignore
    } elseif ($col === 'sa_method') {
        $m = (string) sa_ret_meta($rid, 'method', '');
        echo $m === 'ship' ? esc_html('Ship: ' . (string) sa_ret_meta($rid, 'tracking', '')) : ($m === 'dropoff' ? esc_html('Drop-off ' . (string) sa_ret_meta($rid, 'code', '')) : '-');
    }
}, 10, 2);
add_filter('post_row_actions', static fn(array $a, WP_Post $p): array => $p->post_type === 'sa_return' ? array_intersect_key($a, ['edit' => 1]) : $a, 10, 2);
add_action('admin_menu', static function (): void {
    global $menu;
    $n = count(get_posts(['post_type' => 'sa_return', 'post_status' => 'any', 'numberposts' => 99, 'fields' => 'ids', 'meta_key' => '_sa_r_status', 'meta_value' => 'requested']));
    if (!$n || !is_array($menu)) {
        return;
    }
    foreach ($menu as $i => $m) {
        if (($m[2] ?? '') === 'edit.php?post_type=sa_return') {
            $menu[$i][0] .= ' <span class="awaiting-mod">' . $n . '</span>';
        }
    }
}, 99);
