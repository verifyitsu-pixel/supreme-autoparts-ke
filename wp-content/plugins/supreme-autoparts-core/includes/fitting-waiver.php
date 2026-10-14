<?php
/**
 * Fitting waiver: no booking fee when a customer books fitting/installation of a part
 * they bought from Supreme Autoparts.
 *
 * Rules
 *  - The booking must be linked to a real Woo order from this store (status processing,
 *    completed or on-hold) that contains the part (one order line).
 *  - Ownership: the order's billing email or phone must match (or the order key from the
 *    order page / My Account / thank-you link). No login needed.
 *  - One waived booking per order line. A cancelled booking frees the line again.
 *  - If the order is cancelled, refunded or failed (or fully refunded), the waiver is revoked
 *    and the normal booking fee is due (booking goes back to pending + pay link, 24h grace).
 *  - The waiver covers the booking fee only. Labour is quoted as normal. Add-ons in the same
 *    visit ride along with no fee because the main job is fitting our part.
 *  - Parts still in transit: the slot must be on/after the expected delivery or pickup date.
 *  - Admin can override per booking: auto / force waive / force charge.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const SA_FW_OK_STATUSES = ['processing', 'completed', 'on-hold'];

function sa_fw_digits(string $s): string
{
    $d = preg_replace('/\D+/', '', $s) ?? '';
    return strlen($d) >= 9 ? substr($d, -9) : $d;
}

function sa_fw_find_order(string $num): ?WC_Order
{
    $num = trim(ltrim(trim($num), '#'));
    if ($num === '' || !function_exists('wc_get_order')) {
        return null;
    }
    $o = ctype_digit($num) ? wc_get_order((int) $num) : null;
    if (!$o instanceof WC_Order || $o instanceof WC_Order_Refund) {
        // Order numbers may differ from IDs (e.g. imported refs).
        $ids = wc_get_orders(['limit' => 1, 'return' => 'ids', 'meta_key' => '_order_number', 'meta_value' => $num]);
        $o = $ids ? wc_get_order((int) $ids[0]) : null;
    }
    return $o instanceof WC_Order && !$o instanceof WC_Order_Refund ? $o : null;
}

/** Ownership by billing email / phone (last 9 digits) or by the order key. */
function sa_fw_owner_ok(WC_Order $o, string $contact, string $key = ''): bool
{
    if ($key !== '' && hash_equals((string) $o->get_order_key(), $key)) {
        return true;
    }
    $contact = trim($contact);
    if ($contact === '') {
        return false;
    }
    if (is_email($contact)) {
        return strtolower($contact) === strtolower((string) $o->get_billing_email());
    }
    $d = sa_fw_digits($contact);
    return strlen($d) === 9 && $d === sa_fw_digits((string) $o->get_billing_phone());
}

/** Map of item_id => booking_id for lines already used for a waived booking (active bookings only). */
function sa_fw_used_lines(WC_Order $o): array
{
    $used = [];
    foreach (array_filter((array) ($o->get_meta('_sa_fw_bookings') ?: [])) as $item => $bid) {
        $bid = (int) $bid;
        if ($bid && get_post_type($bid) === 'sa_booking' && get_post_meta($bid, '_sa_bk_status', true) !== 'cancelled' && get_post_meta($bid, '_sa_bk_fee', true) === 'waived') {
            $used[(int) $item] = $bid;
        }
    }
    return $used;
}

/** Earliest Y-m-d the car can be booked: after delivery / pickup of the part. */
function sa_fw_earliest_date(WC_Order $o): string
{
    $tz = wp_timezone();
    $today = new DateTimeImmutable('now', $tz);
    if ($o->get_status() === 'completed' || $o->get_meta('_sa_fit_visit')) {
        return $today->format('Y-m-d'); // Delivered, or a fitting-at-garage order: next open slot.
    }
    $pickup = false;
    foreach ($o->get_shipping_methods() as $sm) {
        if (str_contains((string) $sm->get_method_id(), 'pickup')) {
            $pickup = true;
        }
    }
    $base = $o->get_date_paid() ?: $o->get_date_created();
    $start = $base ? (new DateTimeImmutable('@' . $base->getTimestamp()))->setTimezone($tz) : $today;
    if ($pickup) {
        return $today->format('Y-m-d'); // Store pickup: part is already at our garage; the slot picker shows the next open slot.
    }
    $eta = $start->modify('+3 days');
    $custom = (string) $o->get_meta('_sa_expected_delivery'); // optional admin-set Y-m-d
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $custom)) {
        $eta = new DateTimeImmutable($custom, $tz);
    }
    return max($today, $eta)->format('Y-m-d');
}

/**
 * Check eligibility. Returns ['ok'=>bool,'msg'=>string,'order'=>?WC_Order,'items'=>[[id,name,product]], 'item'=>int,'earliest'=>Y-m-d].
 */
function sa_fw_check(string $num, string $contact, string $key = '', int $product = 0, int $item = 0): array
{
    $r = ['ok' => false, 'msg' => '', 'order' => null, 'items' => [], 'item' => 0, 'earliest' => ''];
    $o = sa_fw_find_order($num);
    if (!$o || !sa_fw_owner_ok($o, $contact, $key)) {
        // Same message for "no order" and "wrong contact" so order numbers can't be probed.
        $r['msg'] = 'We could not match that order number with that email or phone. Check both, or book with the normal fee.';
        return $r;
    }
    if (!in_array($o->get_status(), SA_FW_OK_STATUSES, true)) {
        $r['msg'] = in_array($o->get_status(), ['refunded', 'cancelled', 'failed'], true)
            ? 'Order #' . $o->get_order_number() . ' was ' . $o->get_status() . ', so the booking fee applies.'
            : 'Order #' . $o->get_order_number() . ' is not paid yet. Once it is paid, fitting is booked with no fee.';
        return $r;
    }
    $used = sa_fw_used_lines($o);
    $fee_pid = (int) get_option('sa_svc_fee_product', 0);
    foreach ($o->get_items() as $iid => $it) {
        $pid = (int) $it->get_product_id();
        if (!$pid || $pid === $fee_pid || isset($used[(int) $iid]) || (float) $o->get_qty_refunded_for_item($iid) >= (float) $it->get_quantity()) {
            continue;
        }
        $r['items'][] = ['id' => (int) $iid, 'name' => $it->get_name(), 'product' => $pid];
    }
    $r['order'] = $o;
    $r['earliest'] = sa_fw_earliest_date($o);
    if (!$r['items']) {
        $r['msg'] = $used ? 'Every part on order #' . $o->get_order_number() . ' already has a fee-free fitting booking.' : 'Order #' . $o->get_order_number() . ' has no parts that can be fitted.';
        return $r;
    }
    foreach ($r['items'] as $x) {
        if (($item && $x['id'] === $item) || (!$item && $product && $x['product'] === $product)) {
            $r['item'] = $x['id'];
        }
    }
    if (!$r['item'] && $product && !$item) {
        $r['msg'] = 'This part is not on order #' . $o->get_order_number() . ' (or it already has a fee-free fitting booking).';
        return $r;
    }
    if (!$r['item'] && count($r['items']) === 1) {
        $r['item'] = $r['items'][0]['id'];
    }
    $r['ok'] = true;
    return $r;
}

/* ---------- AJAX lookup for the booking form (no login) ---------- */
add_action('wp_ajax_sa_fw_check', 'sa_fw_ajax');
add_action('wp_ajax_nopriv_sa_fw_check', 'sa_fw_ajax');
function sa_fw_ajax(): void
{
    $ip = preg_replace('/[^0-9a-f:.]/i', '', (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $tk = 'sa_fw_rl_' . md5($ip);
    $n = (int) get_transient($tk);
    if ($n > 20) {
        wp_send_json(['ok' => false, 'msg' => 'Too many checks. Please wait a few minutes.']);
    }
    set_transient($tk, $n + 1, 10 * MINUTE_IN_SECONDS);
    $g = static fn(string $k): string => sanitize_text_field(wp_unslash((string) ($_POST[$k] ?? '')));
    $r = sa_fw_check($g('order'), $g('contact'), $g('key'), (int) $g('part'), (int) $g('item'));
    wp_send_json([
        'ok' => $r['ok'], 'msg' => $r['msg'], 'items' => $r['ok'] ? $r['items'] : [], 'item' => $r['item'],
        'order' => $r['order'] ? $r['order']->get_order_number() : '', 'earliest' => $r['ok'] ? $r['earliest'] : '',
    ]);
}

/**
 * Called from sa_svc_handle_booking BEFORE the booking is saved.
 * Returns null when the customer did not ask for the waiver; otherwise the check result.
 * Calls $fail on a problem so the customer sees a clear message (never a silent charge).
 */
function sa_fw_validate_request(string $date, callable $fail): ?array
{
    if (empty($_POST['fw_on'])) {
        return null;
    }
    $g = static fn(string $k): string => sanitize_text_field(wp_unslash((string) ($_POST[$k] ?? '')));
    $r = sa_fw_check($g('fw_order'), $g('fw_contact'), $g('fw_key'), (int) $g('part'), (int) $g('fw_item'));
    if (!$r['ok']) {
        $fail($r['msg'] . ' (Untick “I bought the part from Supreme Autoparts” to book with the normal fee.)');
    }
    if (!$r['item']) {
        $fail('Please choose which part from order #' . $r['order']->get_order_number() . ' we are fitting.');
    }
    if ($date < $r['earliest']) {
        $fail('Your part from order #' . $r['order']->get_order_number() . ' is expected by ' . wp_date('D j M', strtotime($r['earliest'])) . '. Please pick a date on or after that.');
    }
    return $r;
}

/** Called after the booking meta is saved. */
function sa_fw_apply(int $bid, array $r): void
{
    $o = $r['order'];
    $name = '';
    foreach ($r['items'] as $x) {
        if ($x['id'] === $r['item']) {
            $name = $x['name'];
        }
    }
    update_post_meta($bid, '_sa_bk_fee', 'waived');
    update_post_meta($bid, '_sa_bk_status', 'confirmed');
    update_post_meta($bid, '_sa_bk_waiver', [
        'order' => $o->get_id(), 'number' => $o->get_order_number(), 'item' => $r['item'], 'part' => $name,
        'reason' => 'Part bought from us (order #' . $o->get_order_number() . ')', 'by' => 'auto', 't' => time(),
    ]);
    if ($name !== '' && get_post_meta($bid, '_sa_bk_part', true) === '') {
        update_post_meta($bid, '_sa_bk_part', $name);
        update_post_meta($bid, '_sa_bk_notes', trim('Install part: ' . $name . '. ' . (string) get_post_meta($bid, '_sa_bk_notes', true)));
    }
    $map = array_filter((array) ($o->get_meta('_sa_fw_bookings') ?: []));
    $map[$r['item']] = $bid;
    $o->update_meta_data('_sa_fw_bookings', $map);
    $o->add_order_note('Fee-free fitting booked for "' . $name . '": ' . get_post_meta($bid, '_sa_bk_ref', true) . '.');
    $o->save();
}

function sa_fw_waiver(int $bid): array
{
    $w = get_post_meta($bid, '_sa_bk_waiver', true);
    return is_array($w) ? $w : [];
}

/** Customer-facing label, e.g. "waived (part bought from us, order #26555)". */
function sa_fw_label(int $bid): string
{
    $w = sa_fw_waiver($bid);
    return !empty($w['number']) ? 'waived (part bought from us, order #' . $w['number'] . ')' : 'waived';
}

/* ---------- Revoke on refund / cancel ---------- */
function sa_fw_revoke_for_order($order_id): void
{
    $o = wc_get_order($order_id);
    if (!$o instanceof WC_Order) {
        return;
    }
    $full = in_array($o->get_status(), ['refunded', 'cancelled', 'failed'], true) || ((float) $o->get_total_refunded() > 0 && (float) $o->get_total_refunded() >= (float) $o->get_total());
    foreach (array_filter((array) ($o->get_meta('_sa_fw_bookings') ?: [])) as $item => $bid) {
        $bid = (int) $bid;
        if (!$bid || get_post_type($bid) !== 'sa_booking' || get_post_meta($bid, '_sa_bk_fee', true) !== 'waived') {
            continue;
        }
        $w = sa_fw_waiver($bid);
        if (($w['by'] ?? '') === 'admin') {
            continue; // Manual override wins.
        }
        $line_refunded = false;
        foreach ($o->get_items() as $iid => $it) {
            if ((int) $iid === (int) $item && (float) $o->get_qty_refunded_for_item($iid) >= (float) $it->get_quantity()) {
                $line_refunded = true;
            }
        }
        if (!$full && !$line_refunded) {
            continue;
        }
        if (!in_array(get_post_meta($bid, '_sa_bk_status', true), ['pending', 'confirmed'], true)) {
            continue;
        }
        update_post_meta($bid, '_sa_bk_fee', 'unpaid');
        update_post_meta($bid, '_sa_bk_status', 'pending');
        update_post_meta($bid, '_sa_bk_fw_revoked', time());
        $w['revoked'] = time();
        $w['revoked_reason'] = 'Order #' . $o->get_order_number() . ' ' . ($full ? $o->get_status() : 'part refunded');
        update_post_meta($bid, '_sa_bk_waiver', $w);
        update_post_meta($bid, '_sa_bk_notes', trim('Fee waiver revoked: ' . $w['revoked_reason'] . '. ' . (string) get_post_meta($bid, '_sa_bk_notes', true)));
        $o->add_order_note('Fitting fee waiver revoked for booking ' . get_post_meta($bid, '_sa_bk_ref', true) . '; booking fee now due.');
        if (function_exists('sa_bk_invoice_email')) {
            sa_bk_invoice_email($bid, 'revoked');
        }
    }
    $o->save();
}
foreach (['refunded', 'cancelled', 'failed'] as $st) {
    add_action('woocommerce_order_status_' . $st, 'sa_fw_revoke_for_order', 20);
}
add_action('woocommerce_order_refunded', 'sa_fw_revoke_for_order', 20);

/* ---------- Entry points ---------- */

/** Book-fitting URL for an order (key-authenticated, no login). */
function sa_fw_book_url(WC_Order $o, int $product = 0): string
{
    $args = ['fw_order' => $o->get_order_number(), 'fw_key' => $o->get_order_key()];
    if ($product) {
        $args['part'] = $product;
        $args['service'] = function_exists('sa_svc_for_product') ? sa_svc_for_product($product) : 'diagnostics';
    }
    return add_query_arg($args, home_url('/book-service/'));
}

/** My Account → Orders: "Book fitting" action. */
add_filter('woocommerce_my_account_my_orders_actions', static function (array $actions, $order): array {
    if ($order instanceof WC_Order && in_array($order->get_status(), SA_FW_OK_STATUSES, true)) {
        $r = sa_fw_check($order->get_order_number(), '', $order->get_order_key());
        if ($r['ok']) {
            $actions['sa_fit'] = ['url' => sa_fw_book_url($order), 'name' => 'Book fitting (no fee)'];
        }
    }
    return $actions;
}, 20, 2);

/** Thank-you page + order view: book fitting with no fee (covers "add fitting at checkout"). */
function sa_fw_order_cta($order_id): void
{
    $o = wc_get_order($order_id);
    if (!$o instanceof WC_Order || (int) $o->get_meta('_sa_booking_fee_for')) {
        return;
    }
    if (!in_array($o->get_status(), SA_FW_OK_STATUSES, true)) {
        return;
    }
    $r = sa_fw_check($o->get_order_number(), '', $o->get_order_key());
    if (!$r['ok']) {
        return;
    }
    echo '<div class="sa-svc-fit" style="margin:18px 0"><div><strong>Want us to fit it?</strong><span>Book fitting at our Njiru, Nairobi garage with <b>no booking fee</b>, because you bought the part from us. Labour is quoted as normal. Pick a date on or after ' . esc_html(wp_date('D j M', strtotime($r['earliest']))) . '.</span></div>'
        . '<a class="sa-svc-btn sa-svc-btn--sm sa-svc-btn--primary" href="' . esc_url(sa_fw_book_url($o)) . '">Book fitting</a></div>';
}
add_action('woocommerce_thankyou', 'sa_fw_order_cta', 30);
add_action('woocommerce_view_order', 'sa_fw_order_cta', 30);

/** Logged-in customer who bought this product: product-page link carries the order. */
function sa_fw_customer_order_for_product(int $pid): ?WC_Order
{
    $uid = get_current_user_id();
    if (!$uid || !function_exists('wc_get_orders')) {
        return null;
    }
    foreach (wc_get_orders(['customer_id' => $uid, 'status' => SA_FW_OK_STATUSES, 'limit' => 20]) as $o) {
        $r = sa_fw_check($o->get_order_number(), '', $o->get_order_key(), $pid);
        if ($r['ok'] && $r['item']) {
            return $o;
        }
    }
    return null;
}

/* ---------- Cart / checkout note ---------- */
add_action('woocommerce_review_order_before_payment', static function (): void {
    echo '<p class="sa-fw-cartnote" style="margin:0 0 12px;font-size:13px;color:#4a5160">Need it fitted? After you pay, use <strong>Book fitting</strong> on your order page. There is no booking fee for parts bought from us.</p>';
}, 5);
add_action('woocommerce_cart_totals_after_order_total', static function (): void {
    echo '<tr class="sa-fw-cartnote"><td colspan="2" style="font-size:13px;color:#4a5160">Fitting at our Nairobi garage: no booking fee for parts bought here. Book it from your order page after checkout.</td></tr>';
});

/* ---------- Auto-release: give revoked bookings 24h to pay ---------- */
add_filter('sa_svc_skip_auto_release', static function (bool $skip, int $bid): bool {
    $t = (int) get_post_meta($bid, '_sa_bk_fw_revoked', true);
    return $skip || ($t && $t > time() - DAY_IN_SECONDS);
}, 10, 2);

/* ---------- Admin override ---------- */
function sa_fw_admin_box(int $bid, array $b): string
{
    $w = sa_fw_waiver($bid);
    $h = '';
    if ($w) {
        $h .= '<div style="margin:0 0 6px;padding:6px 8px;background:#f0f7ee;border-left:3px solid #2e7d32;font-size:12px">'
            . '<strong>Fee waiver:</strong> ' . esc_html($w['reason'] ?? '')
            . (!empty($w['order']) ? ' · <a href="' . esc_url(admin_url('post.php?post=' . (int) $w['order'] . '&action=edit')) . '">order #' . esc_html((string) ($w['number'] ?? $w['order'])) . '</a>' : '')
            . (!empty($w['part']) ? ' · ' . esc_html($w['part']) : '')
            . (!empty($w['revoked']) ? '<br><strong style="color:#b00">Revoked</strong> ' . esc_html(wp_date('j M H:i', (int) $w['revoked']) . ': ' . ($w['revoked_reason'] ?? '')) : '')
            . (($w['by'] ?? '') === 'admin' ? '<br>Manual override by ' . esc_html((string) ($w['admin'] ?? 'admin')) : '') . '</div>';
    }
    $cur = ($w['by'] ?? '') === 'admin' ? (($b['fee'] === 'waived') ? 'waive' : 'charge') : 'auto';
    $h .= '<select name="fw_override" title="Fee waiver override"><option value="auto"' . selected($cur, 'auto', false) . '>Waiver: automatic</option><option value="waive"' . selected($cur, 'waive', false) . '>Waiver: force waive</option><option value="charge"' . selected($cur, 'charge', false) . '>Waiver: force charge fee</option></select>';
    return $h;
}

function sa_fw_admin_save(int $bid): void
{
    $ov = sanitize_key((string) ($_POST['fw_override'] ?? 'auto'));
    $w = sa_fw_waiver($bid);
    $cur = ($w['by'] ?? '') === 'admin' ? (get_post_meta($bid, '_sa_bk_fee', true) === 'waived' ? 'waive' : 'charge') : 'auto';
    if ($ov === $cur || !in_array($ov, ['auto', 'waive', 'charge'], true)) {
        return;
    }
    $me = wp_get_current_user()->user_login;
    if ($ov === 'waive') {
        $w = array_merge($w, ['by' => 'admin', 'admin' => $me, 't' => time(), 'reason' => $w['reason'] ?? 'Manual waiver by admin']);
        unset($w['revoked']);
        update_post_meta($bid, '_sa_bk_fee', 'waived');
        if (get_post_meta($bid, '_sa_bk_status', true) === 'pending') {
            update_post_meta($bid, '_sa_bk_status', 'confirmed');
        }
    } elseif ($ov === 'charge') {
        $w = array_merge($w, ['by' => 'admin', 'admin' => $me, 't' => time()]);
        if (get_post_meta($bid, '_sa_bk_fee', true) === 'waived') {
            update_post_meta($bid, '_sa_bk_fee', 'unpaid');
            update_post_meta($bid, '_sa_bk_status', 'pending');
            update_post_meta($bid, '_sa_bk_fw_revoked', time());
        }
    } else {
        $w['by'] = 'auto';
    }
    update_post_meta($bid, '_sa_bk_waiver', $w);
}
