<?php
/**
 * Repair orders: pipeline, estimates with per-line customer approval (tokenized, no login),
 * technician assignment + time clock, parts from the Woo catalogue with stock deduction,
 * Pesapal invoice (Woo order) tied to the RO.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

function sa_g_ro_statuses(): array
{
    return [
        'estimate' => 'Estimate', 'sent' => 'Awaiting approval', 'approved' => 'Approved', 'in_progress' => 'In progress',
        'waiting_parts' => 'Waiting parts', 'qc' => 'Quality check', 'ready' => 'Ready', 'delivered' => 'Delivered',
        'declined' => 'Declined', 'cancelled' => 'Cancelled',
    ];
}
function sa_g_ro_board_columns(): array
{
    return ['estimate', 'sent', 'approved', 'in_progress', 'waiting_parts', 'qc', 'ready', 'delivered'];
}
function sa_g_bays(): array
{
    $b = get_option('sa_garage_bays');
    return is_array($b) && $b ? $b : ['Bay 1', 'Bay 2', 'Bay 3'];
}

function sa_g_ro(int $id): ?array
{
    global $wpdb;
    $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . sa_g_t('ro') . ' WHERE id=%d', $id), ARRAY_A);
    return $r ?: null;
}

function sa_g_ro_items(int $ro): array
{
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . sa_g_t('ro_items') . ' WHERE ro_id=%d ORDER BY sort, id', $ro), ARRAY_A) ?: [];
}

function sa_g_ro_totals(int $ro): array
{
    $t = ['labour' => 0.0, 'parts' => 0.0, 'total' => 0.0, 'approved' => 0.0, 'pending' => 0.0, 'hours' => 0.0];
    foreach (sa_g_ro_items($ro) as $i) {
        $line = (float) $i['qty'] * (float) $i['unit_kes'];
        if ($i['approval'] === 'declined') {
            continue;
        }
        $t[$i['type'] === 'part' ? 'parts' : 'labour'] += $line;
        $t['total'] += $line;
        $t[$i['approval'] === 'approved' ? 'approved' : 'pending'] += $line;
        if ($i['type'] === 'labour') {
            $t['hours'] += (float) $i['hours'];
        }
    }
    return $t;
}

function sa_g_ro_new_ref(): string
{
    global $wpdb;
    do {
        $ref = 'RO-' . wp_date('ymd') . '-' . strtoupper(wp_generate_password(3, false, false));
    } while ($wpdb->get_var($wpdb->prepare('SELECT id FROM ' . sa_g_t('ro') . ' WHERE ref=%s', $ref)));
    return $ref;
}

function sa_g_ro_create(array $a): int
{
    global $wpdb;
    $wpdb->insert(sa_g_t('ro'), [
        'ref' => sa_g_ro_new_ref(), 'status' => 'estimate', 'customer_id' => (int) ($a['customer_id'] ?? 0), 'vehicle_id' => (int) ($a['vehicle_id'] ?? 0),
        'booking_id' => (int) ($a['booking_id'] ?? 0), 'roadside_id' => (int) ($a['roadside_id'] ?? 0), 'tech_id' => (int) ($a['tech_id'] ?? 0),
        'bay' => (string) ($a['bay'] ?? ''), 'sched_start' => $a['sched_start'] ?? null, 'sched_end' => $a['sched_end'] ?? null,
        'mileage_in' => (int) ($a['mileage_in'] ?? 0), 'complaint' => (string) ($a['complaint'] ?? ''), 'notes' => '',
        'token' => wp_generate_password(32, false, false), 'created' => sa_g_now(), 'updated' => sa_g_now(),
    ]);
    $id = (int) $wpdb->insert_id;
    if ($id) {
        sa_g_ro_log($id, 'estimate', 'Repair order created');
        if (!empty($a['vehicle_id']) && !empty($a['mileage_in'])) {
            sa_g_add_mileage((int) $a['vehicle_id'], (int) $a['mileage_in'], 'ro', $id);
        }
        $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . sa_g_t('links') . ' (vehicle_id,customer_id,src_type,src_id,created) VALUES (%d,%d,%s,%d,%s)', (int) ($a['vehicle_id'] ?? 0), (int) ($a['customer_id'] ?? 0), 'ro', $id, sa_g_now()));
    }
    return $id;
}

/** Create an RO from a service booking (labour line from the booked service, real catalogue price only). */
function sa_g_ro_from_booking(int $bid): int
{
    global $wpdb;
    $have = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . sa_g_t('ro') . ' WHERE booking_id=%d LIMIT 1', $bid));
    if ($have) {
        return $have;
    }
    $vid = sa_g_link_booking($bid);
    $link = sa_g_link_for('booking', $bid);
    $b = sa_svc_booking_data($bid);
    $start = $b['date'] && $b['time'] ? $b['date'] . ' ' . $b['time'] . ':00' : null;
    $dur = function_exists('sa_svc_blocks') ? sa_svc_blocks($b['service']) : 1;
    $id = sa_g_ro_create(['customer_id' => $link['customer_id'], 'vehicle_id' => $vid, 'booking_id' => $bid, 'sched_start' => $start,
        'sched_end' => $start ? gmdate('Y-m-d H:i:s', strtotime($start) + $dur * HOUR_IN_SECONDS) : null, 'complaint' => trim($b['notes'])]);
    $svc = function_exists('sa_svc_catalog') ? (sa_svc_catalog()[$b['service']] ?? null) : null;
    if ($id && $svc) {
        $price = 0.0;
        if (!empty($svc['kes']['options'])) {
            foreach ($svc['kes']['options'] as $o) {
                if ($o['label'] === $b['option']) {
                    $price = (float) $o['price'];
                }
            }
        } elseif (isset($svc['kes']['price'])) {
            $price = (float) $svc['kes']['price'];
        }
        sa_g_ro_add_item($id, ['type' => 'labour', 'description' => $svc['name'] . ($b['option'] ? ' — ' . $b['option'] : ''), 'qty' => 1, 'hours' => round(((int) ($svc['duration'] ?? 60)) / 60, 2), 'unit_kes' => $price]);
    }
    return $id;
}

function sa_g_ro_from_roadside(int $rid): int
{
    global $wpdb;
    $have = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . sa_g_t('ro') . ' WHERE roadside_id=%d LIMIT 1', $rid));
    if ($have) {
        return $have;
    }
    $vid = sa_g_link_roadside($rid);
    $link = sa_g_link_for('roadside', $rid);
    $r = sa_rs_data($rid);
    return sa_g_ro_create(['customer_id' => $link['customer_id'], 'vehicle_id' => $vid, 'roadside_id' => $rid, 'complaint' => trim((sa_rs_catalog()[$r['type']]['name'] ?? $r['type']) . '. ' . $r['note'])]);
}

function sa_g_part_price_kes(int $pid): float
{
    $p = wc_get_product($pid);
    if (!$p) {
        return 0.0;
    }
    $price = (float) $p->get_price();
    $cur = get_woocommerce_currency();
    if ($cur === 'KES') {
        return round($price);
    }
    if (class_exists('SA_FX_Rates') && $price > 0) {
        $kes = SA_FX_Rates::rate_for('KES');
        $per = SA_FX_Rates::rate_for($cur) ?: 1.0;
        if ($kes) {
            return round($price / $per * $kes);
        }
    }
    return 0.0;
}

function sa_g_ro_add_item(int $ro, array $i): int
{
    global $wpdb;
    $type = in_array($i['type'] ?? '', ['labour', 'part', 'fee'], true) ? $i['type'] : 'labour';
    $pid = (int) ($i['product_id'] ?? 0);
    $desc = sanitize_text_field((string) ($i['description'] ?? ''));
    $sku = '';
    if ($type === 'part' && $pid && ($p = wc_get_product($pid))) {
        $desc = $desc !== '' ? $desc : $p->get_name();
        $sku = (string) $p->get_sku();
        if (!isset($i['unit_kes']) || (float) $i['unit_kes'] <= 0) {
            $i['unit_kes'] = sa_g_part_price_kes($pid);
        }
    }
    if ($desc === '') {
        return 0;
    }
    $wpdb->insert(sa_g_t('ro_items'), ['ro_id' => $ro, 'type' => $type, 'product_id' => $pid, 'sku' => $sku, 'description' => $desc, 'qty' => max(0.01, (float) ($i['qty'] ?? 1)),
        'hours' => max(0, (float) ($i['hours'] ?? 0)), 'unit_kes' => max(0, (float) ($i['unit_kes'] ?? 0)), 'approval' => in_array($i['approval'] ?? '', ['approved', 'declined'], true) ? $i['approval'] : 'pending',
        'tech_id' => (int) ($i['tech_id'] ?? 0), 'sort' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . sa_g_t('ro_items') . ' WHERE ro_id=%d', $ro)), 'created' => sa_g_now()]);
    sa_g_ro_recalc($ro);
    return (int) $wpdb->insert_id;
}

function sa_g_ro_recalc(int $ro): void
{
    global $wpdb;
    $wpdb->update(sa_g_t('ro'), ['total_kes' => sa_g_ro_totals($ro)['total'], 'updated' => sa_g_now()], ['id' => $ro]);
}

function sa_g_ro_log(int $ro, string $status, string $note = ''): void
{
    $h = get_option('sa_g_ro_hist_' . $ro);
    $h = is_array($h) ? $h : [];
    $h[] = ['t' => time(), 's' => $status, 'n' => $note, 'by' => wp_get_current_user()->display_name ?: 'Customer'];
    update_option('sa_g_ro_hist_' . $ro, array_slice($h, -80), false);
}

function sa_g_ro_set_status(int $ro, string $status, string $note = ''): void
{
    global $wpdb;
    if (!isset(sa_g_ro_statuses()[$status])) {
        return;
    }
    $r = sa_g_ro($ro);
    if (!$r || $r['status'] === $status) {
        return;
    }
    $wpdb->update(sa_g_t('ro'), ['status' => $status, 'updated' => sa_g_now()], ['id' => $ro]);
    sa_g_ro_log($ro, $status, $note);
    if (in_array($status, ['in_progress', 'qc', 'ready', 'delivered'], true)) {
        sa_g_ro_deduct_parts($ro);
    }
    if ($status === 'ready') {
        sa_g_ro_email($ro, 'ready');
    }
    if ($status === 'delivered') {
        sa_g_clock_stop_all($ro);
        if ($r['booking_id'] && function_exists('sa_svc_booking_data')) {
            update_post_meta((int) $r['booking_id'], '_sa_bk_status', 'completed');
        }
    }
    do_action('sa_garage_ro_status', $ro, $status, $r['status']);
}

/** Deduct stock for approved, catalogue-linked parts once. */
function sa_g_ro_deduct_parts(int $ro): int
{
    global $wpdb;
    $n = 0;
    foreach (sa_g_ro_items($ro) as $i) {
        if ($i['type'] !== 'part' || !(int) $i['product_id'] || (int) $i['deducted'] || $i['approval'] !== 'approved') {
            continue;
        }
        $p = wc_get_product((int) $i['product_id']);
        if ($p && $p->managing_stock()) {
            wc_update_product_stock($p, (int) ceil((float) $i['qty']), 'decrease');
        }
        $wpdb->update(sa_g_t('ro_items'), ['deducted' => 1], ['id' => (int) $i['id']]);
        $n++;
    }
    if ($n) {
        sa_g_ro_log($ro, (string) sa_g_ro($ro)['status'], $n . ' part line(s) taken from inventory');
    }
    return $n;
}

function sa_g_ro_remove_item(int $item_id): void
{
    global $wpdb;
    $i = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . sa_g_t('ro_items') . ' WHERE id=%d', $item_id), ARRAY_A);
    if (!$i) {
        return;
    }
    if ((int) $i['deducted'] && (int) $i['product_id'] && ($p = wc_get_product((int) $i['product_id'])) && $p->managing_stock()) {
        wc_update_product_stock($p, (int) ceil((float) $i['qty']), 'increase');
    }
    $wpdb->delete(sa_g_t('ro_items'), ['id' => $item_id]);
    sa_g_ro_recalc((int) $i['ro_id']);
}

/* ---------- Time clock ---------- */

function sa_g_clock_open(int $tech): ?array
{
    global $wpdb;
    $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . sa_g_t('time') . ' WHERE tech_id=%d AND ended IS NULL ORDER BY id DESC LIMIT 1', $tech), ARRAY_A);
    return $r ?: null;
}
function sa_g_clock_in(int $ro, int $tech, int $item = 0): void
{
    global $wpdb;
    if ($open = sa_g_clock_open($tech)) {
        $wpdb->update(sa_g_t('time'), ['ended' => sa_g_now()], ['id' => (int) $open['id']]);
    }
    $wpdb->insert(sa_g_t('time'), ['ro_id' => $ro, 'item_id' => $item, 'tech_id' => $tech, 'started' => sa_g_now()]);
    $r = sa_g_ro($ro);
    if ($r && in_array($r['status'], ['approved', 'estimate', 'sent'], true)) {
        sa_g_ro_set_status($ro, 'in_progress', 'Technician clocked in');
    }
}
function sa_g_clock_out(int $tech): void
{
    global $wpdb;
    if ($open = sa_g_clock_open($tech)) {
        $wpdb->update(sa_g_t('time'), ['ended' => sa_g_now()], ['id' => (int) $open['id']]);
    }
}
function sa_g_clock_stop_all(int $ro): void
{
    global $wpdb;
    $wpdb->query($wpdb->prepare('UPDATE ' . sa_g_t('time') . ' SET ended=%s WHERE ro_id=%d AND ended IS NULL', sa_g_now(), $ro));
}
function sa_g_clocked_hours(array $where): float
{
    global $wpdb;
    $sql = 'SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, started, COALESCE(ended, %s))),0) FROM ' . sa_g_t('time') . ' WHERE 1=1';
    $args = [sa_g_now()];
    foreach ($where as $k => $v) {
        $sql .= " AND $k=%d";
        $args[] = (int) $v;
    }
    return round((float) $wpdb->get_var($wpdb->prepare($sql, $args)) / 3600, 2);
}

function sa_g_techs(): array
{
    return get_users(['role__in' => ['sa_mechanic', 'administrator', 'shop_manager'], 'orderby' => 'display_name', 'fields' => ['ID', 'display_name', 'user_email']]);
}

/* ---------- Invoice + Pesapal ---------- */

function sa_g_ro_invoice(int $ro): ?WC_Order
{
    $r = sa_g_ro($ro);
    if (!$r) {
        return null;
    }
    if ((int) $r['order_id'] && ($o = wc_get_order((int) $r['order_id'])) && !$o->is_paid()) {
        $o->remove_order_items('fee');
    } elseif ((int) $r['order_id'] && ($o = wc_get_order((int) $r['order_id'])) && $o->is_paid()) {
        return $o;
    } else {
        $o = wc_create_order(['created_via' => 'sa_workshop']);
    }
    if (is_wp_error($o) || !$o) {
        return null;
    }
    $c = sa_g_customer((int) $r['customer_id']) ?: ['name' => '', 'email' => '', 'phone' => '', 'user_id' => 0];
    $parts = explode(' ', trim((string) $c['name']), 2);
    $o->set_billing_first_name($parts[0] ?? '');
    $o->set_billing_last_name($parts[1] ?? '');
    $o->set_billing_email((string) $c['email']);
    $o->set_billing_phone((string) $c['phone']);
    $o->set_billing_country('KE');
    if ((int) $c['user_id']) {
        $o->set_customer_id((int) $c['user_id']);
    }
    foreach (sa_g_ro_items($ro) as $i) {
        if ($i['approval'] !== 'approved') {
            continue;
        }
        $kes = (float) $i['qty'] * (float) $i['unit_kes'];
        $amt = function_exists('sa_fargo_kes_to_store') ? sa_fargo_kes_to_store($kes) : $kes;
        if ($amt === null || $amt <= 0) {
            continue;
        }
        $fee = new WC_Order_Item_Fee();
        $fee->set_name(($i['type'] === 'part' ? 'Part: ' : ($i['type'] === 'fee' ? '' : 'Labour: ')) . $i['description'] . ((float) $i['qty'] != 1 ? ' × ' . (float) $i['qty'] : '') . ' (' . sa_g_kes($kes) . ')');
        $fee->set_amount((string) round($amt, 2));
        $fee->set_total((string) round($amt, 2));
        $fee->set_tax_status('none');
        $o->add_item($fee);
    }
    $o->update_meta_data('_sa_ro_id', $ro);
    $o->update_meta_data('_sa_ro_ref', $r['ref']);
    $o->update_meta_data('_sa_ro_total_kes', sa_g_ro_totals($ro)['approved']);
    $o->calculate_totals(false);
    $o->set_status('pending');
    $o->add_order_note('Workshop invoice for ' . $r['ref']);
    $o->save();
    global $wpdb;
    $wpdb->update(sa_g_t('ro'), ['order_id' => $o->get_id(), 'updated' => sa_g_now()], ['id' => $ro]);
    $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . sa_g_t('links') . ' (vehicle_id,customer_id,src_type,src_id,created) VALUES (%d,%d,%s,%d,%s)', (int) $r['vehicle_id'], (int) $r['customer_id'], 'order', $o->get_id(), sa_g_now()));
    return $o;
}

add_filter('woocommerce_order_needs_shipping_address', static fn($n, $h, $o) => $o instanceof WC_Order && $o->get_meta('_sa_ro_id') ? false : $n, 10, 3);
add_action('woocommerce_order_status_changed', static function ($oid, $from, $to, $o): void {
    if (!$o instanceof WC_Order || !in_array($to, ['processing', 'completed'], true) || !($ro = (int) $o->get_meta('_sa_ro_id'))) {
        return;
    }
    global $wpdb;
    $r = sa_g_ro($ro);
    if ($r && !$r['paid_at']) {
        $wpdb->update(sa_g_t('ro'), ['paid_at' => sa_g_now()], ['id' => $ro]);
        sa_g_ro_log($ro, $r['status'], 'Invoice paid (order #' . $o->get_id() . ')');
    }
}, 10, 4);
/* A workshop invoice is a service: once paid, go straight to completed (no shipping stages). */
add_filter('woocommerce_payment_complete_order_status', static fn($s, $id, $o = null) => ($o instanceof WC_Order && $o->get_meta('_sa_ro_id')) ? 'completed' : $s, 10, 3);

/* ---------- Customer emails ---------- */

function sa_g_estimate_url(array $r): string
{
    return add_query_arg(['ro' => (int) $r['id'], 'k' => $r['token']], home_url('/estimate/'));
}

function sa_g_ro_email(int $ro, string $kind): bool
{
    $r = sa_g_ro($ro);
    $c = $r ? sa_g_customer((int) $r['customer_id']) : null;
    if (!$r || !$c || !is_email($c['email'])) {
        return false;
    }
    $v = sa_g_vehicle((int) $r['vehicle_id']);
    $hi = '<p>' . sa_email_greeting((string) $c['name']) . '</p>';
    $veh = '<p><strong>Vehicle:</strong> ' . esc_html(sa_g_vehicle_label($v)) . '<br><strong>Repair order:</strong> ' . esc_html($r['ref']) . '</p>';
    $t = sa_g_ro_totals($ro);
    switch ($kind) {
        case 'estimate':
            $subject = 'Your estimate ' . $r['ref'] . ' is ready to approve';
            $html = sa_mail_layout('Your estimate is ready', $hi . '<p>We have checked your vehicle and prepared an estimate. You can approve or decline each item. Nothing is done without your approval.</p>' . $veh . sa_g_items_email_table($ro) . '<p><strong>Estimate total: ' . esc_html(sa_g_kes($t['total'])) . '</strong></p>', 'Review and approve', sa_g_estimate_url($r), $subject);
            break;
        case 'ready':
            $o = (int) $r['order_id'] ? wc_get_order((int) $r['order_id']) : null;
            $pay = $o && !$o->is_paid() ? $o->get_checkout_payment_url() : '';
            $subject = 'Your vehicle is ready — ' . $r['ref'];
            $html = sa_mail_layout('Your vehicle is ready for collection', $hi . '<p>Good news, the work on your vehicle is done and it is ready to collect at Ground Floor, Midax Plaza, Kangundo Rd, Njiru.</p>' . $veh . '<p><strong>Amount due: ' . esc_html(sa_g_kes($t['approved'])) . '</strong></p>', $pay ? 'Pay invoice online' : 'View your repair order', $pay ?: sa_g_estimate_url($r), $subject);
            break;
        case 'invoice':
            $o = sa_g_ro_invoice($ro);
            if (!$o) {
                return false;
            }
            $subject = 'Invoice for ' . $r['ref'] . ' — ' . sa_g_kes($t['approved']);
            $html = sa_mail_layout('Your invoice', $hi . '<p>Here is the invoice for the approved work on your vehicle.</p>' . $veh . sa_g_items_email_table($ro, true) . '<p><strong>Total: ' . esc_html(sa_g_kes($t['approved'])) . '</strong></p><p style="font-size:13px;color:#6b6b6b">Pay securely by M-Pesa or card through Pesapal.</p>', 'Pay now', $o->get_checkout_payment_url(), $subject);
            break;
        default:
            return false;
    }
    $pdf = ($kind === 'invoice' || $kind === 'ready') && isset($o) && $o instanceof WC_Order && function_exists('sa_rcpt_order_pdf') ? sa_rcpt_order_pdf($o) : '';
    $ok = (bool) wp_mail($c['email'], $subject, $html, ['Content-Type: text/html; charset=UTF-8'], $pdf !== '' ? [$pdf] : []);
    if ($pdf !== '') {
        sa_rcpt_cleanup($pdf);
    }
    sa_g_ro_log($ro, $r['status'], ucfirst($kind) . ' email ' . ($ok ? 'sent' : 'failed') . ' to ' . $c['email']);
    return $ok;
}

function sa_g_items_email_table(int $ro, bool $approved_only = false): string
{
    $rows = '';
    foreach (sa_g_ro_items($ro) as $i) {
        if ($approved_only && $i['approval'] !== 'approved') {
            continue;
        }
        $rows .= '<tr><td style="padding:7px 0;border-bottom:1px solid #eee">' . esc_html(($i['type'] === 'part' ? 'Part · ' : ($i['type'] === 'labour' ? 'Labour · ' : '')) . $i['description']) . ((float) $i['qty'] != 1 ? ' × ' . (float) $i['qty'] : '') . '</td><td style="padding:7px 0;border-bottom:1px solid #eee;text-align:right;white-space:nowrap">' . esc_html((float) $i['unit_kes'] > 0 ? sa_g_kes((float) $i['qty'] * (float) $i['unit_kes']) : 'Quote') . '</td></tr>';
    }
    return '<table role="presentation" width="100%" style="border-collapse:collapse;font-size:14px;margin:10px 0">' . $rows . '</table>';
}

/* ---------- Public estimate approval page: /estimate/?ro=ID&k=TOKEN ---------- */

add_action('init', static function (): void {
    add_rewrite_rule('^estimate/?$', 'index.php?sa_g_estimate=1', 'top');
    add_rewrite_rule('^workshop/?$', 'index.php?sa_g_app=home', 'top');
    add_rewrite_rule('^workshop/(inspect|job|clock)/?$', 'index.php?sa_g_app=$matches[1]', 'top');
    add_rewrite_rule('^inspection-report/?$', 'index.php?sa_g_report=1', 'top');
});
add_filter('query_vars', static fn(array $v): array => array_merge($v, ['sa_g_estimate', 'sa_g_app', 'sa_g_report']));

function sa_g_public_head(string $title): void
{
    nocache_headers();
    add_filter('pre_get_document_title', static fn() => $title . ' | Supreme Autoparts');
    add_action('wp_head', static function (): void {
        echo '<meta name="robots" content="noindex,nofollow">';
    });
    wp_enqueue_style('sa-garage', SA_CORE_URL . 'assets/garage.css', [], SA_CORE_VERSION);
    get_header();
}

add_action('template_redirect', static function (): void {
    if (!get_query_var('sa_g_estimate')) {
        return;
    }
    $r = sa_g_ro((int) ($_REQUEST['ro'] ?? 0));
    $ok = $r && hash_equals((string) $r['token'], (string) wp_unslash($_REQUEST['k'] ?? ''));
    if ($ok && $_SERVER['REQUEST_METHOD'] === 'POST' && wp_verify_nonce((string) ($_POST['_sa_est'] ?? ''), 'sa_est_' . $r['id'])) {
        global $wpdb;
        $changed = [];
        foreach (sa_g_ro_items((int) $r['id']) as $i) {
            $d = sanitize_key((string) ($_POST['item'][$i['id']] ?? ''));
            if (in_array($d, ['approved', 'declined'], true) && $d !== $i['approval'] && !(int) $i['deducted']) {
                $wpdb->update(sa_g_t('ro_items'), ['approval' => $d], ['id' => (int) $i['id']]);
                $changed[] = $i['description'] . ': ' . $d;
            }
        }
        sa_g_ro_recalc((int) $r['id']);
        $wpdb->update(sa_g_t('ro'), ['approved_at' => sa_g_now()], ['id' => (int) $r['id']]);
        $note = sanitize_textarea_field(wp_unslash((string) ($_POST['note'] ?? '')));
        sa_g_ro_log((int) $r['id'], $r['status'], 'Customer decision: ' . implode('; ', $changed) . ($note !== '' ? ' — "' . $note . '"' : ''));
        $any = false;
        foreach (sa_g_ro_items((int) $r['id']) as $i) {
            $any = $any || $i['approval'] === 'approved';
        }
        if (in_array($r['status'], ['estimate', 'sent'], true)) {
            sa_g_ro_set_status((int) $r['id'], $any ? 'approved' : 'declined', 'Customer responded online');
        }
        wp_mail((string) get_option('admin_email'), 'Estimate ' . $r['ref'] . ' answered by customer', sa_mail_layout('Customer answered estimate ' . $r['ref'], '<p>' . esc_html(implode('<br>', $changed) ?: 'No changes') . '</p>' . ($note !== '' ? '<p>Note: ' . esc_html($note) . '</p>' : ''), 'Open repair order', admin_url('admin.php?page=sa-super-workshop&view=ro&id=' . (int) $r['id'])), ['Content-Type: text/html; charset=UTF-8']);
        wp_safe_redirect(add_query_arg(['ro' => (int) $r['id'], 'k' => $r['token'], 'done' => 1], home_url('/estimate/')));
        exit;
    }
    sa_g_public_head('Your estimate');
    echo '<main class="sa-g-public sa-container">';
    if (!$ok) {
        echo '<h1>Link not valid</h1><p>This estimate link is incorrect or has expired. Please use the link in your latest email, or <a href="https://wa.me/supremeautopart">chat with us on WhatsApp</a>.</p></main>';
        get_footer();
        exit;
    }
    $v = sa_g_vehicle((int) $r['vehicle_id']);
    $t = sa_g_ro_totals((int) $r['id']);
    $locked = in_array($r['status'], ['delivered', 'cancelled'], true);
    echo '<p class="sa-g-eyebrow">Repair order ' . esc_html($r['ref']) . '</p><h1>Your estimate</h1><p class="sa-g-muted">' . esc_html(sa_g_vehicle_label($v)) . ' · Status: <strong>' . esc_html(sa_g_ro_statuses()[$r['status']] ?? $r['status']) . '</strong></p>';
    if (!empty($_GET['done'])) {
        echo '<div class="woocommerce-message" role="status">Thank you. We have your decision and will be in touch.</div>';
    }
    if ($r['complaint']) {
        echo '<p><strong>Your concern:</strong> ' . esc_html($r['complaint']) . '</p>';
    }
    echo '<form method="post" class="sa-g-est">';
    wp_nonce_field('sa_est_' . $r['id'], '_sa_est');
    foreach (sa_g_ro_items((int) $r['id']) as $i) {
        $line = (float) $i['qty'] * (float) $i['unit_kes'];
        echo '<div class="sa-g-est__row sa-g-est__row--' . esc_attr($i['approval']) . '"><div><span class="sa-g-tag">' . esc_html(ucfirst($i['type'])) . '</span><strong>' . esc_html($i['description']) . '</strong>'
            . ((float) $i['qty'] != 1 ? '<small> × ' . esc_html((string) (float) $i['qty']) . '</small>' : '') . '</div><div class="sa-g-est__price">' . esc_html($line > 0 ? sa_g_kes($line) : 'Quote') . '</div>';
        if (!$locked && !(int) $i['deducted']) {
            echo '<div class="sa-g-est__choice"><label><input type="radio" name="item[' . (int) $i['id'] . ']" value="approved"' . checked($i['approval'], 'approved', false) . '> Approve</label><label><input type="radio" name="item[' . (int) $i['id'] . ']" value="declined"' . checked($i['approval'], 'declined', false) . '> Decline</label></div>';
        } else {
            echo '<div class="sa-g-est__choice"><em>' . esc_html(ucfirst($i['approval'])) . '</em></div>';
        }
        echo '</div>';
    }
    echo '<div class="sa-g-est__total"><span>Total (excluding declined)</span><strong>' . esc_html(sa_g_kes($t['total'])) . '</strong></div>';
    if (!$locked) {
        echo '<label class="sa-g-field">Message for the workshop (optional)<textarea name="note" rows="3"></textarea></label><button class="sa-btn" type="submit">Send my decision</button>';
    }
    echo '</form>';
    $o = (int) $r['order_id'] ? wc_get_order((int) $r['order_id']) : null;
    if ($o && !$o->is_paid()) {
        echo '<p style="margin-top:18px"><a class="sa-btn" href="' . esc_url($o->get_checkout_payment_url()) . '">Pay invoice (' . esc_html(sa_g_kes($t['approved'])) . ')</a></p>';
    } elseif ($o && $o->is_paid()) {
        echo '<p class="woocommerce-message">Invoice paid. Thank you.</p>';
    }
    foreach (sa_g_dvis_for(['ro_id' => (int) $r['id']]) as $d) {
        echo '<p><a href="' . esc_url(sa_g_dvi_report_url($d)) . '">View ' . esc_html(sa_g_dvi_kinds()[$d['kind']] ?? 'inspection') . ' report ' . esc_html($d['ref']) . '</a></p>';
    }
    echo '</main>';
    get_footer();
    exit;
});
