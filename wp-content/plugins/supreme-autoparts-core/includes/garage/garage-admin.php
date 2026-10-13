<?php
/** Super Admin → Workshop: board, repair orders, scheduler, vehicles, customers, technicians, inspections, reminders, reports. */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}


function sa_g_admin_url(string $view = 'board', array $args = []): string
{
    return add_query_arg(array_merge(['page' => 'sa-super-workshop', 'view' => $view], $args), admin_url('admin.php'));
}

/* Menu entry comes from sa_core_super_nav() (admin-super.php). */

add_action('admin_enqueue_scripts', static function (string $hook): void {
    if (str_contains($hook, 'sa-super-workshop')) {
        wp_enqueue_style('sa-garage', SA_CORE_URL . 'assets/garage.css', [], SA_CORE_VERSION);
        wp_enqueue_style('sa-garage-admin', SA_CORE_URL . 'assets/garage-admin.css', ['sa-garage'], SA_CORE_VERSION);
    }
});

function sa_g_admin_tabs(string $cur): string
{
    $tabs = ['board' => 'Board', 'ros' => 'Repair orders', 'schedule' => 'Schedule', 'vehicles' => 'Vehicles', 'customers' => 'Customers', 'techs' => 'Technicians', 'inspections' => 'Inspections', 'reminders' => 'Reminders', 'reports' => 'Reports', 'settings' => 'Settings'];
    $h = '<nav class="sa-ga-tabs">';
    foreach ($tabs as $k => $l) {
        $h .= '<a class="' . ($k === $cur ? 'is-on' : '') . '" href="' . esc_url(sa_g_admin_url($k)) . '">' . esc_html($l) . '</a>';
    }
    return $h . '<a class="sa-ga-tabs__app" href="' . esc_url(home_url('/workshop/')) . '" target="_blank">Mechanic app ↗</a></nav>';
}

function sa_g_admin_render(): void
{
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Not allowed');
    }
    $view = sanitize_key((string) ($_GET['view'] ?? 'board'));
    $base = ['ro' => 'ros', 'new_ro' => 'ros', 'vehicle' => 'vehicles', 'customer' => 'customers'][$view] ?? $view;
    echo '<div class="wrap sa-ga"><h1 class="sa-ga-title">Workshop</h1>' . sa_g_admin_tabs($base);
    if (!empty($_GET['msg'])) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sanitize_text_field(wp_unslash((string) $_GET['msg']))) . '</p></div>';
    }
    if ($q = sanitize_text_field(wp_unslash((string) ($_GET['q'] ?? '')))) {
        sa_g_admin_search($q);
    }
    echo '<form class="sa-ga-search" method="get"><input type="hidden" name="page" value="sa-super-workshop"><input type="hidden" name="view" value="' . esc_attr($base) . '"><input type="search" name="q" placeholder="Search plate, phone, email, name, VIN or RO…" value="' . esc_attr($q) . '"><button class="button">Search</button></form>';
    $fn = 'sa_g_admin_view_' . $view;
    if (function_exists($fn)) {
        $fn();
    } else {
        sa_g_admin_view_board();
    }
    echo '</div>';
}

function sa_g_admin_search(string $q): void
{
    global $wpdb;
    $like = '%' . $wpdb->esc_like($q) . '%';
    $plate = '%' . $wpdb->esc_like(sa_g_plate($q)) . '%';
    $ph = sa_g_phone($q);
    $veh = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . sa_g_t('vehicles') . ' WHERE plate LIKE %s OR vin LIKE %s OR CONCAT(make," ",model) LIKE %s LIMIT 20', $plate, $like, $like), ARRAY_A) ?: [];
    $cus = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . sa_g_t('customers') . ' WHERE name LIKE %s OR email LIKE %s OR phone LIKE %s OR (%s<>"" AND phone=%s) LIMIT 20', $like, $like, $like, $ph, $ph), ARRAY_A) ?: [];
    $ros = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . sa_g_t('ro') . ' WHERE ref LIKE %s LIMIT 10', $like), ARRAY_A) ?: [];
    echo '<div class="sa-ga-card"><h2>Results for “' . esc_html($q) . '”</h2><div class="sa-ga-cols3"><div><h3>Vehicles</h3>';
    foreach ($veh as $v) {
        echo '<p><a href="' . esc_url(sa_g_admin_url('vehicle', ['id' => $v['id']])) . '">' . esc_html(sa_g_vehicle_label($v)) . '</a></p>';
    }
    echo $veh ? '' : '<p class="description">None</p>';
    echo '</div><div><h3>Customers</h3>';
    foreach ($cus as $c) {
        echo '<p><a href="' . esc_url(sa_g_admin_url('customer', ['id' => $c['id']])) . '">' . esc_html($c['name'] ?: $c['email']) . '</a><br><small>' . esc_html($c['phone'] . ' ' . $c['email']) . '</small></p>';
    }
    echo $cus ? '' : '<p class="description">None</p>';
    echo '</div><div><h3>Repair orders</h3>';
    foreach ($ros as $r) {
        echo '<p><a href="' . esc_url(sa_g_admin_url('ro', ['id' => $r['id']])) . '">' . esc_html($r['ref']) . '</a></p>';
    }
    echo ($ros ? '' : '<p class="description">None</p>') . '</div></div></div>';
}

function sa_g_post_form(string $op, string $inner, array $hidden = [], string $cls = ''): string
{
    $h = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="' . esc_attr($cls) . '"><input type="hidden" name="action" value="sa_g_admin"><input type="hidden" name="op" value="' . esc_attr($op) . '">' . wp_nonce_field('sa_g_admin', '_wpnonce', true, false);
    foreach ($hidden as $k => $v) {
        $h .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr((string) $v) . '">';
    }
    return $h . $inner . '</form>';
}

/* ---------- Board ---------- */

function sa_g_admin_view_board(): void
{
    global $wpdb;
    $rows = $wpdb->get_results('SELECT * FROM ' . sa_g_t('ro') . " WHERE status NOT IN ('cancelled','declined') AND (status<>'delivered' OR updated > DATE_SUB(NOW(), INTERVAL 7 DAY)) ORDER BY updated DESC LIMIT 300", ARRAY_A) ?: [];
    $by = [];
    foreach ($rows as $r) {
        $by[$r['status']][] = $r;
    }
    $techs = [];
    foreach (sa_g_techs() as $t) {
        $techs[(int) $t->ID] = $t->display_name;
    }
    echo '<p><a class="button button-primary" href="' . esc_url(sa_g_admin_url('new_ro')) . '">+ New repair order</a></p><div class="sa-ga-board">';
    foreach (sa_g_ro_board_columns() as $st) {
        $list = $by[$st] ?? [];
        echo '<section class="sa-ga-col"><h3>' . esc_html(sa_g_ro_statuses()[$st]) . ' <span>' . count($list) . '</span></h3>';
        foreach ($list as $r) {
            $v = sa_g_vehicle((int) $r['vehicle_id']);
            $c = sa_g_customer((int) $r['customer_id']);
            $next = sa_g_ro_board_columns()[array_search($st, sa_g_ro_board_columns(), true) + 1] ?? '';
            echo '<article class="sa-ga-ticket"><a href="' . esc_url(sa_g_admin_url('ro', ['id' => $r['id']])) . '"><strong>' . esc_html(sa_g_vehicle_label($v)) . '</strong></a><small>' . esc_html($r['ref'] . ' · ' . ($c['name'] ?? '')) . '</small>'
                . '<small>' . esc_html(sa_g_kes($r['total_kes']) . ($r['tech_id'] ? ' · ' . ($techs[(int) $r['tech_id']] ?? '') : ' · unassigned') . ($r['paid_at'] ? ' · paid' : '')) . '</small>'
                . ($next ? sa_g_post_form('ro_status', '<button class="button button-small">→ ' . esc_html(sa_g_ro_statuses()[$next]) . '</button>', ['id' => $r['id'], 'status' => $next, 'back' => 'board']) : '') . '</article>';
        }
        echo '</section>';
    }
    echo '</div>';
}

/* ---------- Repair orders ---------- */

function sa_g_admin_view_ros(): void
{
    global $wpdb;
    $st = sanitize_key((string) ($_GET['status'] ?? ''));
    $rows = $st !== '' ? $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . sa_g_t('ro') . ' WHERE status=%s ORDER BY id DESC LIMIT 200', $st), ARRAY_A) : $wpdb->get_results('SELECT * FROM ' . sa_g_t('ro') . ' ORDER BY id DESC LIMIT 200', ARRAY_A);
    echo '<p><a class="button button-primary" href="' . esc_url(sa_g_admin_url('new_ro')) . '">+ New repair order</a> ';
    foreach (['' => 'All'] + sa_g_ro_statuses() as $k => $l) {
        echo '<a class="button' . ($k === $st ? ' button-primary' : '') . '" href="' . esc_url(sa_g_admin_url('ros', $k !== '' ? ['status' => $k] : [])) . '">' . esc_html($l) . '</a> ';
    }
    echo '</p><table class="widefat striped sa-ga-table"><thead><tr><th>RO</th><th>Vehicle</th><th>Customer</th><th>Status</th><th>Scheduled</th><th>Total</th><th>Paid</th></tr></thead><tbody>';
    foreach ($rows ?: [] as $r) {
        $c = sa_g_customer((int) $r['customer_id']);
        echo '<tr><td><a href="' . esc_url(sa_g_admin_url('ro', ['id' => $r['id']])) . '"><strong>' . esc_html($r['ref']) . '</strong></a></td><td>' . esc_html(sa_g_vehicle_label(sa_g_vehicle((int) $r['vehicle_id']))) . '</td><td>' . esc_html($c['name'] ?? '') . '</td><td><span class="sa-g-pill sa-g-pill--' . esc_attr($r['status']) . '">' . esc_html(sa_g_ro_statuses()[$r['status']] ?? $r['status']) . '</span></td><td>' . esc_html($r['sched_start'] ? mysql2date('j M, g:i A', $r['sched_start']) : '—') . '</td><td>' . esc_html(sa_g_kes($r['total_kes'])) . '</td><td>' . ($r['paid_at'] ? '✓' : '') . '</td></tr>';
    }
    if (!$rows) {
        echo '<tr><td colspan="7">No repair orders yet. Create one from a booking (Schedule tab) or with “New repair order”.</td></tr>';
    }
    echo '</tbody></table>';
}

function sa_g_admin_view_new_ro(): void
{
    $vid = (int) ($_GET['vehicle'] ?? 0);
    $v = $vid ? sa_g_vehicle($vid) : null;
    $c = $v ? sa_g_customer((int) $v['customer_id']) : null;
    $in = static fn(string $n, string $l, string $val = '', string $t = 'text') => '<label>' . esc_html($l) . '<input type="' . $t . '" name="' . $n . '" value="' . esc_attr($val) . '"></label>';
    echo '<div class="sa-ga-card"><h2>New repair order</h2>' . sa_g_post_form('ro_create', '<div class="sa-ga-form">'
        . $in('name', 'Customer name', (string) ($c['name'] ?? '')) . $in('phone', 'Phone', (string) ($c['phone'] ?? ''), 'tel') . $in('email', 'Email', (string) ($c['email'] ?? ''), 'email')
        . $in('plate', 'Reg plate', $v && !str_starts_with($v['plate'], 'NOPLATE') ? $v['plate'] : '') . $in('make', 'Make', (string) ($v['make'] ?? '')) . $in('model', 'Model', (string) ($v['model'] ?? '')) . $in('year', 'Year', (string) (($v['year'] ?? 0) ?: ''), 'number') . $in('vin', 'VIN', (string) ($v['vin'] ?? '')) . $in('mileage', 'Mileage in (km)', '', 'number')
        . '<label class="sa-ga-wide">Customer concern<textarea name="complaint" rows="3"></textarea></label></div><p><button class="button button-primary">Create repair order</button></p>') . '</div>';
}

function sa_g_admin_view_ro(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    $r = sa_g_ro($id);
    if (!$r) {
        echo '<p>Not found.</p>';
        return;
    }
    $v = sa_g_vehicle((int) $r['vehicle_id']);
    $c = sa_g_customer((int) $r['customer_id']);
    $t = sa_g_ro_totals($id);
    $techs = sa_g_techs();
    $o = (int) $r['order_id'] ? wc_get_order((int) $r['order_id']) : null;
    echo '<div class="sa-ga-head"><div><p class="sa-ga-eyebrow">Repair order</p><h2>' . esc_html($r['ref']) . ' <span class="sa-g-pill sa-g-pill--' . esc_attr($r['status']) . '">' . esc_html(sa_g_ro_statuses()[$r['status']]) . '</span></h2><p>'
        . ($v ? '<a href="' . esc_url(sa_g_admin_url('vehicle', ['id' => $v['id']])) . '">' . esc_html(sa_g_vehicle_label($v)) . '</a>' : 'No vehicle') . ' · ' . ($c ? '<a href="' . esc_url(sa_g_admin_url('customer', ['id' => $c['id']])) . '">' . esc_html($c['name'] ?: $c['email']) . '</a> ' . esc_html($c['phone']) : 'No customer')
        . ($r['booking_id'] ? ' · Booking ' . esc_html((string) get_post_meta((int) $r['booking_id'], '_sa_bk_ref', true)) : '') . ($r['roadside_id'] ? ' · Roadside ' . esc_html((string) get_post_meta((int) $r['roadside_id'], '_rs_ref', true)) : '') . '</p></div>';
    echo '<div class="sa-ga-actions">';
    foreach (sa_g_ro_statuses() as $k => $l) {
        if ($k !== $r['status']) {
            echo sa_g_post_form('ro_status', '<button class="button button-small">' . esc_html($l) . '</button>', ['id' => $id, 'status' => $k]);
        }
    }
    echo '</div></div>';
    echo '<div class="sa-ga-grid2"><div>';
    // Items
    echo '<div class="sa-ga-card"><h3>Estimate / work</h3><table class="widefat sa-ga-table"><thead><tr><th>Type</th><th>Description</th><th>Qty</th><th>Hours</th><th>Unit KES</th><th>Line</th><th>Approval</th><th>Tech</th><th></th></tr></thead><tbody>';
    foreach (sa_g_ro_items($id) as $i) {
        $tn = '';
        foreach ($techs as $tt) {
            $tn = (int) $tt->ID === (int) $i['tech_id'] ? $tt->display_name : $tn;
        }
        echo '<tr><td>' . esc_html(ucfirst($i['type'])) . '</td><td>' . esc_html($i['description']) . ($i['sku'] ? '<br><small>SKU ' . esc_html($i['sku']) . ($i['deducted'] ? ' · taken from stock' : '') . '</small>' : '') . '</td><td>' . esc_html((string) (float) $i['qty']) . '</td><td>' . esc_html($i['type'] === 'labour' ? (string) (float) $i['hours'] : '') . '</td><td>' . esc_html(number_format((float) $i['unit_kes'])) . '</td><td>' . esc_html(sa_g_kes((float) $i['qty'] * (float) $i['unit_kes'])) . '</td>'
            . '<td>' . sa_g_post_form('item_approval', '<select name="approval" onchange="this.form.submit()">' . implode('', array_map(static fn($a) => '<option' . selected($i['approval'], $a, false) . '>' . $a . '</option>', ['pending', 'approved', 'declined'])) . '</select>', ['id' => $id, 'item' => $i['id']]) . '</td><td>' . esc_html($tn) . '</td>'
            . '<td>' . sa_g_post_form('item_remove', '<button class="button-link-delete" onclick="return confirm(\'Remove this line?\')">Remove</button>', ['id' => $id, 'item' => $i['id']]) . '</td></tr>';
    }
    echo '</tbody><tfoot><tr><th colspan="5">Labour ' . esc_html(sa_g_kes($t['labour'])) . ' · Parts ' . esc_html(sa_g_kes($t['parts'])) . ' · ' . esc_html((string) $t['hours']) . ' h billed</th><th colspan="4">Total ' . esc_html(sa_g_kes($t['total'])) . ' · Approved ' . esc_html(sa_g_kes($t['approved'])) . '</th></tr></tfoot></table>';
    $techopt = '<option value="0">—</option>' . implode('', array_map(static fn($tt) => '<option value="' . (int) $tt->ID . '">' . esc_html($tt->display_name) . '</option>', $techs));
    echo '<h4>Add labour</h4>' . sa_g_post_form('item_add', '<div class="sa-ga-inline"><input name="description" placeholder="Labour description" required><input name="hours" type="number" step="0.25" min="0" placeholder="Hours"><input name="unit_kes" type="number" step="1" min="0" placeholder="Price KES" required><select name="tech_id">' . $techopt . '</select><button class="button">Add</button></div>', ['id' => $id, 'type' => 'labour']);
    echo '<h4>Add part from catalogue</h4>' . sa_g_post_form('item_add', '<div class="sa-ga-inline"><input name="part_q" placeholder="SKU or product ID" required><input name="qty" type="number" step="1" min="1" value="1"><input name="unit_kes" type="number" min="0" placeholder="KES (blank = shop price)"><button class="button">Add part</button></div><p class="description">Price defaults to the live shop price converted to KES. Stock is deducted when work starts.</p>', ['id' => $id, 'type' => 'part']);
    echo '<h4>Add other charge</h4>' . sa_g_post_form('item_add', '<div class="sa-ga-inline"><input name="description" placeholder="e.g. Towing, sublet, disposal" required><input name="unit_kes" type="number" min="0" placeholder="Price KES" required><button class="button">Add</button></div>', ['id' => $id, 'type' => 'fee']) . '</div>';
    // History
    echo '<div class="sa-ga-card"><h3>Activity</h3><ul class="sa-g-hist">';
    foreach (array_reverse((array) get_option('sa_g_ro_hist_' . $id)) as $h) {
        echo '<li><strong>' . esc_html(sa_g_ro_statuses()[$h['s']] ?? $h['s']) . '</strong> ' . esc_html($h['n']) . '<br><small>' . esc_html(wp_date('j M Y, g:i A', (int) $h['t']) . ' · ' . $h['by']) . '</small></li>';
    }
    echo '</ul></div></div><div>';
    // Side: customer actions
    echo '<div class="sa-ga-card"><h3>Customer</h3>'
        . sa_g_post_form('ro_send_estimate', '<button class="button button-primary">Send estimate for approval</button>', ['id' => $id])
        . '<p class="description">Customer approves or declines each line from a link, no login. ' . ($r['sent_at'] ? 'Last sent ' . esc_html(mysql2date('j M, g:i A', $r['sent_at'])) . '.' : '') . '</p>'
        . '<p><a href="' . esc_url(sa_g_estimate_url($r)) . '" target="_blank">Open customer estimate link ↗</a></p>'
        . sa_g_post_form('ro_invoice', '<button class="button">' . ($o ? 'Update &amp; resend invoice' : 'Create invoice &amp; email pay link') . '</button>', ['id' => $id])
        . ($o ? '<p>Invoice: <a href="' . esc_url($o->get_edit_order_url()) . '">order #' . esc_html($o->get_order_number()) . '</a> · ' . esc_html(wc_get_order_status_name($o->get_status())) . ($o->is_paid() ? '' : ' · <a href="' . esc_url($o->get_checkout_payment_url()) . '" target="_blank">Pesapal pay link ↗</a>') . '</p>' : '')
        . '<p><a class="button" href="' . esc_url(add_query_arg(['ro' => $id, 'kind' => 'inspection'], home_url('/workshop/inspect/'))) . '" target="_blank">Start inspection (DVI) ↗</a></p></div>';
    // Assignment & schedule
    $topt = '<option value="0">— Unassigned —</option>' . implode('', array_map(static fn($tt) => '<option value="' . (int) $tt->ID . '"' . selected((int) $r['tech_id'], (int) $tt->ID, false) . '>' . esc_html($tt->display_name) . '</option>', $techs));
    $bopt = '<option value="">—</option>' . implode('', array_map(static fn($b) => '<option' . selected($r['bay'], $b, false) . '>' . esc_html($b) . '</option>', sa_g_bays()));
    $dt = static fn($s) => $s ? str_replace(' ', 'T', substr((string) $s, 0, 16)) : '';
    echo '<div class="sa-ga-card"><h3>Job &amp; bay</h3>' . sa_g_post_form('ro_assign', '<label>Technician<select name="tech_id">' . $topt . '</select></label><label>Bay<select name="bay">' . $bopt . '</select></label><label>Start<input type="datetime-local" name="sched_start" value="' . esc_attr($dt($r['sched_start'])) . '"></label><label>End<input type="datetime-local" name="sched_end" value="' . esc_attr($dt($r['sched_end'])) . '"></label><label>Mileage in<input type="number" name="mileage_in" value="' . esc_attr((string) ((int) $r['mileage_in'] ?: '')) . '"></label><label>Internal notes<textarea name="notes" rows="2">' . esc_textarea((string) $r['notes']) . '</textarea></label><button class="button">Save</button>', ['id' => $id], 'sa-ga-stack')
        . '<p>Clocked: <strong>' . esc_html((string) sa_g_clocked_hours(['ro_id' => $id])) . ' h</strong> vs ' . esc_html((string) $t['hours']) . ' h billed</p></div>';
    // Reminder
    echo '<div class="sa-ga-card"><h3>Next service reminder</h3>' . sa_g_post_form('reminder_add', '<label>Service<input name="service" placeholder="e.g. Oil &amp; filter change" required></label><label>Due date<input type="date" name="due_date"></label><label>or due at (km)<input type="number" name="due_km"></label><button class="button">Add reminder</button>', ['id' => $id, 'vehicle_id' => (int) $r['vehicle_id']], 'sa-ga-stack') . '</div>';
    $dv = sa_g_dvis_for(['ro_id' => $id]);
    if ($dv) {
        echo '<div class="sa-ga-card"><h3>Inspections</h3>';
        foreach ($dv as $d) {
            echo '<p><a href="' . esc_url(sa_g_dvi_report_url($d)) . '" target="_blank">' . esc_html($d['ref'] . ' · ' . sa_g_dvi_kinds()[$d['kind']]) . '</a></p>';
        }
        echo '</div>';
    }
    echo '</div></div>';
}

/* ---------- Schedule ---------- */

function sa_g_admin_view_schedule(): void
{
    global $wpdb;
    $start = strtotime((string) ($_GET['week'] ?? '')) ?: strtotime('monday this week', current_time('timestamp'));
    $days = [];
    for ($i = 0; $i < 7; $i++) {
        $days[] = gmdate('Y-m-d', $start + $i * DAY_IN_SECONDS);
    }
    $ros = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . sa_g_t('ro') . " WHERE sched_start BETWEEN %s AND %s AND status NOT IN ('cancelled','declined')", $days[0] . ' 00:00:00', $days[6] . ' 23:59:59'), ARRAY_A) ?: [];
    $bk = get_posts(['post_type' => 'sa_booking', 'numberposts' => 300, 'fields' => 'ids', 'meta_query' => [['key' => '_sa_bk_date', 'value' => [$days[0], $days[6]], 'compare' => 'BETWEEN']]]);
    $linked = array_map('intval', array_column($ros, 'booking_id'));
    echo '<p><a class="button" href="' . esc_url(sa_g_admin_url('schedule', ['week' => gmdate('Y-m-d', $start - 7 * DAY_IN_SECONDS)])) . '">← Previous week</a> <strong>' . esc_html(wp_date('j M', $start) . ' – ' . wp_date('j M Y', $start + 6 * DAY_IN_SECONDS)) . '</strong> <a class="button" href="' . esc_url(sa_g_admin_url('schedule', ['week' => gmdate('Y-m-d', $start + 7 * DAY_IN_SECONDS)])) . '">Next week →</a></p>';
    echo '<div class="sa-ga-cal"><div class="sa-ga-cal__hd"></div>';
    foreach ($days as $d) {
        echo '<div class="sa-ga-cal__hd">' . esc_html(wp_date('D j M', strtotime($d))) . '</div>';
    }
    foreach (array_merge(sa_g_bays(), ['Unassigned bay']) as $bay) {
        echo '<div class="sa-ga-cal__bay">' . esc_html($bay) . '</div>';
        foreach ($days as $d) {
            echo '<div class="sa-ga-cal__cell">';
            foreach ($ros as $r) {
                $rb = $r['bay'] !== '' && in_array($r['bay'], sa_g_bays(), true) ? $r['bay'] : 'Unassigned bay';
                if ($rb === $bay && substr((string) $r['sched_start'], 0, 10) === $d) {
                    echo '<a class="sa-ga-ev sa-ga-ev--' . esc_attr($r['status']) . '" href="' . esc_url(sa_g_admin_url('ro', ['id' => $r['id']])) . '"><b>' . esc_html(mysql2date('H:i', $r['sched_start'])) . '</b> ' . esc_html(sa_g_vehicle_label(sa_g_vehicle((int) $r['vehicle_id']))) . '</a>';
                }
            }
            if ($bay === 'Unassigned bay') {
                foreach ($bk as $bid) {
                    $b = sa_svc_booking_data((int) $bid);
                    if ($b['date'] === $d && !in_array((int) $bid, $linked, true) && $b['status'] !== 'cancelled') {
                        echo '<div class="sa-ga-ev sa-ga-ev--booking"><b>' . esc_html($b['time']) . '</b> ' . esc_html(trim($b['make'] . ' ' . $b['model']) . ' · ' . (sa_svc_catalog()[$b['service']]['name'] ?? '')) . sa_g_post_form('ro_from_booking', '<button class="button-link">Create RO →</button>', ['booking' => $bid]) . '</div>';
                    }
                }
            }
            echo '</div>';
        }
    }
    echo '</div><p class="description">Service bookings without a repair order show in the Unassigned row. Create the RO, then set its bay and technician.</p>';
}

/* ---------- Vehicles & customers ---------- */

function sa_g_admin_view_vehicles(): void
{
    global $wpdb;
    $rows = $wpdb->get_results('SELECT v.*, c.name cname, c.phone cphone FROM ' . sa_g_t('vehicles') . ' v LEFT JOIN ' . sa_g_t('customers') . ' c ON c.id=v.customer_id ORDER BY v.updated DESC LIMIT 300', ARRAY_A) ?: [];
    echo '<table class="widefat striped sa-ga-table"><thead><tr><th>Plate</th><th>Vehicle</th><th>VIN</th><th>Mileage</th><th>Owner</th><th>Records</th></tr></thead><tbody>';
    foreach ($rows as $v) {
        $n = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . sa_g_t('links') . ' WHERE vehicle_id=%d', (int) $v['id']));
        echo '<tr><td><a href="' . esc_url(sa_g_admin_url('vehicle', ['id' => $v['id']])) . '"><span class="sa-g-plate">' . esc_html(str_starts_with($v['plate'], 'NOPLATE') ? 'NO PLATE' : $v['plate']) . '</span></a></td><td>' . esc_html(trim(($v['year'] ?: '') . ' ' . $v['make'] . ' ' . $v['model'])) . '</td><td>' . esc_html($v['vin']) . '</td><td>' . esc_html($v['mileage'] ? number_format((int) $v['mileage']) . ' km' : '') . '</td><td>' . ($v['customer_id'] ? '<a href="' . esc_url(sa_g_admin_url('customer', ['id' => $v['customer_id']])) . '">' . esc_html((string) $v['cname']) . '</a> ' . esc_html((string) $v['cphone']) : '') . '</td><td>' . $n . '</td></tr>';
    }
    echo $rows ? '' : '<tr><td colspan="6">No vehicles yet. They are created automatically from bookings, roadside requests, inspections and repair orders.</td></tr>';
    echo '</tbody></table>';
}

function sa_g_admin_view_vehicle(): void
{
    global $wpdb;
    $v = sa_g_vehicle((int) ($_GET['id'] ?? 0));
    if (!$v) {
        echo '<p>Not found.</p>';
        return;
    }
    $c = sa_g_customer((int) $v['customer_id']);
    $hist = sa_g_vehicle_history((int) $v['id']);
    $miles = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . sa_g_t('mileage') . ' WHERE vehicle_id=%d ORDER BY created DESC LIMIT 30', (int) $v['id']), ARRAY_A) ?: [];
    $rem = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . sa_g_t('reminders') . ' WHERE vehicle_id=%d ORDER BY id DESC', (int) $v['id']), ARRAY_A) ?: [];
    $spent = (float) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(total_kes),0) FROM ' . sa_g_t('ro') . ' WHERE vehicle_id=%d AND paid_at IS NOT NULL', (int) $v['id']));
    echo '<div class="sa-ga-head"><div><p class="sa-ga-eyebrow">Vehicle</p><h2>' . esc_html(trim(($v['year'] ?: '') . ' ' . $v['make'] . ' ' . $v['model'])) . ' <span class="sa-g-plate">' . esc_html(str_starts_with($v['plate'], 'NOPLATE') ? 'NO PLATE' : $v['plate']) . '</span></h2>'
        . '<p>Owner: ' . ($c ? '<a href="' . esc_url(sa_g_admin_url('customer', ['id' => $c['id']])) . '">' . esc_html($c['name'] ?: $c['email']) . '</a> · ' . esc_html($c['phone'] . ' · ' . $c['email']) : '—') . '</p></div>'
        . '<div class="sa-ga-actions"><a class="button button-primary" href="' . esc_url(sa_g_admin_url('new_ro', ['vehicle' => $v['id']])) . '">+ Repair order</a><a class="button" target="_blank" href="' . esc_url(add_query_arg(['kind' => 'pickup'], home_url('/workshop/inspect/'))) . '">Inspection ↗</a></div></div>';
    echo '<div class="sa-ga-kpis"><div><span>Mileage</span><strong>' . esc_html($v['mileage'] ? number_format((int) $v['mileage']) . ' km' : '—') . '</strong></div><div><span>Records</span><strong>' . count($hist) . '</strong></div><div><span>Paid workshop spend</span><strong>' . esc_html(sa_g_kes($spent)) . '</strong></div><div><span>VIN</span><strong>' . esc_html($v['vin'] ?: '—') . '</strong></div></div>';
    echo '<div class="sa-ga-grid2"><div class="sa-ga-card"><h3>Full history</h3><ul class="sa-g-hist">';
    foreach ($hist as $h) {
        echo '<li><a href="' . esc_url($h['admin_url']) . '"><strong>' . esc_html($h['type']) . ':</strong> ' . esc_html($h['title']) . '</a><br><small>' . esc_html(wp_date('j M Y', $h['t']) . ' · ' . $h['sub']) . '</small></li>';
    }
    echo $hist ? '' : '<li>No history yet.</li>';
    echo '</ul></div><div><div class="sa-ga-card"><h3>Edit vehicle</h3>' . sa_g_post_form('vehicle_save', '<label>Reg plate<input name="plate" value="' . esc_attr(str_starts_with($v['plate'], 'NOPLATE') ? '' : $v['plate']) . '"></label><label>Make<input name="make" value="' . esc_attr($v['make']) . '"></label><label>Model<input name="model" value="' . esc_attr($v['model']) . '"></label><label>Year<input type="number" name="year" value="' . esc_attr((string) ((int) $v['year'] ?: '')) . '"></label><label>VIN<input name="vin" value="' . esc_attr($v['vin']) . '"></label><label>Engine<input name="engine" value="' . esc_attr($v['engine']) . '"></label><label>Colour<input name="color" value="' . esc_attr($v['color']) . '"></label><label>Add mileage reading (km)<input type="number" name="mileage"></label><label>Notes<textarea name="notes" rows="2">' . esc_textarea((string) $v['notes']) . '</textarea></label><button class="button">Save</button>', ['id' => $v['id']], 'sa-ga-stack') . '</div>';
    echo '<div class="sa-ga-card"><h3>Mileage history</h3><ul class="sa-g-list">';
    foreach ($miles as $m) {
        echo '<li><span>' . esc_html(number_format((int) $m['km'])) . ' km</span><em>' . esc_html(mysql2date('j M Y', $m['created']) . ' · ' . $m['source']) . '</em></li>';
    }
    echo ($miles ? '' : '<li>No readings yet.</li>') . '</ul></div><div class="sa-ga-card"><h3>Reminders</h3><ul class="sa-g-list">';
    foreach ($rem as $r) {
        echo '<li><span>' . esc_html($r['service']) . '</span><em>' . esc_html(trim(($r['due_date'] ? mysql2date('j M Y', $r['due_date']) : '') . ((int) $r['due_km'] ? ' / ' . number_format((int) $r['due_km']) . ' km' : '')) . ' · ' . $r['status']) . '</em></li>';
    }
    echo ($rem ? '' : '<li>None.</li>') . '</ul>' . sa_g_post_form('reminder_add', '<label>Service<input name="service" required></label><label>Due date<input type="date" name="due_date"></label><label>or km<input type="number" name="due_km"></label><button class="button">Add reminder</button>', ['vehicle_id' => $v['id'], 'back_vehicle' => $v['id']], 'sa-ga-stack') . '</div></div></div>';
}

function sa_g_admin_view_customers(): void
{
    global $wpdb;
    $rows = $wpdb->get_results('SELECT c.*, (SELECT COUNT(*) FROM ' . sa_g_t('vehicles') . ' v WHERE v.customer_id=c.id) nveh, (SELECT COUNT(*) FROM ' . sa_g_t('ro') . ' r WHERE r.customer_id=c.id) nro FROM ' . sa_g_t('customers') . ' c ORDER BY c.id DESC LIMIT 300', ARRAY_A) ?: [];
    echo '<table class="widefat striped sa-ga-table"><thead><tr><th>Name</th><th>Phone</th><th>Email</th><th>Vehicles</th><th>Repair orders</th><th>Account</th></tr></thead><tbody>';
    foreach ($rows as $c) {
        echo '<tr><td><a href="' . esc_url(sa_g_admin_url('customer', ['id' => $c['id']])) . '"><strong>' . esc_html($c['name'] ?: '(no name)') . '</strong></a></td><td>' . esc_html($c['phone']) . '</td><td>' . esc_html($c['email']) . '</td><td>' . (int) $c['nveh'] . '</td><td>' . (int) $c['nro'] . '</td><td>' . ((int) $c['user_id'] ? '<a href="' . esc_url(get_edit_user_link((int) $c['user_id'])) . '">Yes</a>' : '—') . '</td></tr>';
    }
    echo ($rows ? '' : '<tr><td colspan="6">No customers yet.</td></tr>') . '</tbody></table>';
}

function sa_g_admin_view_customer(): void
{
    global $wpdb;
    $c = sa_g_customer((int) ($_GET['id'] ?? 0));
    if (!$c) {
        echo '<p>Not found.</p>';
        return;
    }
    $veh = $wpdb->get_results($wpdb->prepare('SELECT DISTINCT v.* FROM ' . sa_g_t('vehicles') . ' v LEFT JOIN ' . sa_g_t('links') . ' l ON l.vehicle_id=v.id WHERE v.customer_id=%d OR l.customer_id=%d', (int) $c['id'], (int) $c['id']), ARRAY_A) ?: [];
    $orders = $c['email'] !== '' ? wc_get_orders(['billing_email' => $c['email'], 'limit' => 30, 'orderby' => 'date', 'order' => 'DESC']) : [];
    $ros = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . sa_g_t('ro') . ' WHERE customer_id=%d ORDER BY id DESC', (int) $c['id']), ARRAY_A) ?: [];
    $bk = $c['email'] !== '' ? get_posts(['post_type' => 'sa_booking', 'numberposts' => 30, 'fields' => 'ids', 'meta_key' => '_sa_bk_email', 'meta_value' => $c['email']]) : [];
    $rs = $c['email'] !== '' || $c['phone'] !== '' ? get_posts(['post_type' => 'sa_roadside', 'numberposts' => 30, 'fields' => 'ids', 'meta_query' => ['relation' => 'OR', ['key' => '_rs_email', 'value' => $c['email'] ?: '-'], ['key' => '_rs_phone', 'value' => $c['phone'] ?: '-']]]) : [];
    $ltv = 0.0;
    foreach ($orders as $o) {
        $ltv += $o->is_paid() ? (float) $o->get_total() : 0;
    }
    echo '<div class="sa-ga-head"><div><p class="sa-ga-eyebrow">Customer</p><h2>' . esc_html($c['name'] ?: $c['email']) . '</h2><p>' . esc_html($c['phone']) . ' · <a href="mailto:' . esc_attr($c['email']) . '">' . esc_html($c['email']) . '</a>' . ($c['phone'] ? ' · <a target="_blank" href="https://wa.me/' . esc_attr(ltrim($c['phone'], '+')) . '">WhatsApp</a>' : '') . '</p></div></div>';
    echo '<div class="sa-ga-kpis"><div><span>Vehicles</span><strong>' . count($veh) . '</strong></div><div><span>Repair orders</span><strong>' . count($ros) . '</strong></div><div><span>Bookings</span><strong>' . count($bk) . '</strong></div><div><span>Roadside</span><strong>' . count($rs) . '</strong></div><div><span>Paid orders (store currency)</span><strong>' . wp_kses_post(wc_price($ltv)) . '</strong></div></div>';
    echo '<div class="sa-ga-grid2"><div><div class="sa-ga-card"><h3>Vehicles</h3>';
    foreach ($veh as $v) {
        echo '<p><a href="' . esc_url(sa_g_admin_url('vehicle', ['id' => $v['id']])) . '">' . esc_html(sa_g_vehicle_label($v)) . '</a></p>';
    }
    echo ($veh ? '' : '<p>None.</p>') . '</div><div class="sa-ga-card"><h3>Repair orders</h3>';
    foreach ($ros as $r) {
        echo '<p><a href="' . esc_url(sa_g_admin_url('ro', ['id' => $r['id']])) . '">' . esc_html($r['ref']) . '</a> · ' . esc_html(sa_g_ro_statuses()[$r['status']] . ' · ' . sa_g_kes($r['total_kes'])) . '</p>';
    }
    echo ($ros ? '' : '<p>None.</p>') . '</div><div class="sa-ga-card"><h3>Service bookings &amp; roadside</h3>';
    foreach ($bk as $id) {
        $b = sa_svc_booking_data((int) $id);
        echo '<p>' . esc_html($b['ref'] . ' · ' . (sa_svc_catalog()[$b['service']]['name'] ?? '') . ' · ' . $b['date'] . ' · ' . $b['status']) . '</p>';
    }
    foreach ($rs as $id) {
        $r = sa_rs_data((int) $id);
        echo '<p>' . esc_html($r['ref'] . ' · ' . (sa_rs_catalog()[$r['type']]['name'] ?? '') . ' · ' . (sa_rs_statuses()[$r['status']] ?? '')) . '</p>';
    }
    echo ($bk || $rs ? '' : '<p>None.</p>') . '</div></div><div><div class="sa-ga-card"><h3>Orders &amp; invoices</h3>';
    foreach ($orders as $o) {
        echo '<p><a href="' . esc_url($o->get_edit_order_url()) . '">#' . esc_html($o->get_order_number()) . '</a> · ' . esc_html(wc_get_order_status_name($o->get_status())) . ' · ' . wp_kses_post($o->get_formatted_order_total()) . ($o->get_meta('_sa_ro_id') ? ' · workshop' : '') . '</p>';
    }
    echo ($orders ? '' : '<p>None.</p>') . '</div><div class="sa-ga-card"><h3>Edit</h3>' . sa_g_post_form('customer_save', '<label>Name<input name="name" value="' . esc_attr($c['name']) . '"></label><label>Phone<input name="phone" value="' . esc_attr($c['phone']) . '"></label><label>Email<input name="email" type="email" value="' . esc_attr($c['email']) . '"></label><label>Notes<textarea name="notes" rows="3">' . esc_textarea((string) $c['notes']) . '</textarea></label><button class="button">Save</button>', ['id' => $c['id']], 'sa-ga-stack') . '</div></div></div>';
}

/* ---------- Technicians ---------- */

function sa_g_tech_stats(int $tech, string $from, string $to): array
{
    global $wpdb;
    $clocked = round((float) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, started, COALESCE(ended, NOW()))),0) FROM ' . sa_g_t('time') . ' WHERE tech_id=%d AND started BETWEEN %s AND %s', $tech, $from, $to)) / 3600, 2);
    $billed = (float) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(i.hours),0) FROM ' . sa_g_t('ro_items') . ' i JOIN ' . sa_g_t('ro') . " r ON r.id=i.ro_id WHERE i.type='labour' AND i.approval='approved' AND (i.tech_id=%d OR (i.tech_id=0 AND r.tech_id=%d)) AND r.status IN ('ready','delivered') AND r.updated BETWEEN %s AND %s", $tech, $tech, $from, $to));
    $jobs = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . sa_g_t('ro') . " WHERE tech_id=%d AND status NOT IN ('delivered','cancelled','declined')", $tech));
    $done = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . sa_g_t('ro') . " WHERE tech_id=%d AND status='delivered' AND updated BETWEEN %s AND %s", $tech, $from, $to));
    return ['clocked' => $clocked, 'billed' => round($billed, 2), 'eff' => $clocked > 0 ? round($billed / $clocked * 100) : null, 'open' => $jobs, 'done' => $done];
}

function sa_g_admin_view_techs(): void
{
    $from = gmdate('Y-m-d 00:00:00', strtotime('-30 days'));
    $to = gmdate('Y-m-d 23:59:59');
    echo '<div class="sa-ga-card"><h3>Technicians — last 30 days</h3><table class="widefat striped sa-ga-table"><thead><tr><th>Name</th><th>Role</th><th>Open jobs</th><th>Delivered</th><th>Clocked h</th><th>Billed h</th><th>Efficiency</th><th>Now</th></tr></thead><tbody>';
    foreach (sa_g_techs() as $t) {
        $s = sa_g_tech_stats((int) $t->ID, $from, $to);
        $open = sa_g_clock_open((int) $t->ID);
        $u = get_userdata((int) $t->ID);
        echo '<tr><td>' . esc_html($t->display_name) . '</td><td>' . esc_html(in_array('sa_mechanic', (array) $u->roles, true) ? 'Mechanic' : 'Admin') . '</td><td>' . $s['open'] . '</td><td>' . $s['done'] . '</td><td>' . $s['clocked'] . '</td><td>' . $s['billed'] . '</td><td>' . ($s['eff'] === null ? '—' : $s['eff'] . '%') . '</td><td>' . ($open ? 'On ' . esc_html(sa_g_ro((int) $open['ro_id'])['ref'] ?? '') : '—') . '</td></tr>';
    }
    echo '</tbody></table><p class="description">Efficiency = approved labour hours on finished jobs ÷ hours clocked. Mechanics clock in and out per job in the mechanic app.</p></div>';
    echo '<div class="sa-ga-card"><h3>Add a mechanic</h3>' . sa_g_post_form('tech_add', '<div class="sa-ga-inline"><input name="name" placeholder="Full name" required><input name="email" type="email" placeholder="Email" required><button class="button button-primary">Create &amp; email login link</button></div><p class="description">Mechanics get the mobile workshop app only (no wp-admin). Existing users are given the Mechanic role.</p>') . '</div>';
}

/* ---------- Inspections / reminders ---------- */

function sa_g_admin_view_inspections(): void
{
    $rows = sa_g_dvis_for([], 200);
    echo '<p><a class="button button-primary" target="_blank" href="' . esc_url(home_url('/workshop/inspect/')) . '">+ New inspection ↗</a></p><table class="widefat striped sa-ga-table"><thead><tr><th>Ref</th><th>Type</th><th>Vehicle</th><th>Customer</th><th>Mechanic</th><th>When</th><th>Emailed</th><th>PDF</th></tr></thead><tbody>';
    foreach ($rows as $d) {
        $m = get_userdata((int) $d['mechanic_id']);
        echo '<tr><td><a target="_blank" href="' . esc_url(sa_g_dvi_report_url($d)) . '">' . esc_html($d['ref']) . '</a></td><td>' . esc_html(sa_g_dvi_kinds()[$d['kind']]) . '</td><td><a href="' . esc_url(sa_g_admin_url('vehicle', ['id' => $d['vehicle_id']])) . '">' . esc_html(sa_g_vehicle_label(sa_g_vehicle((int) $d['vehicle_id']))) . '</a></td><td>' . esc_html((string) ($d['d']['customer']['name'] ?? '')) . '</td><td>' . esc_html($m ? $m->display_name : '') . '</td><td>' . esc_html(mysql2date('j M, g:i A', $d['created'])) . '</td><td>' . ($d['emailed_at'] ? '✓' : '—') . '</td><td><a target="_blank" href="' . esc_url(sa_g_dvi_report_url($d, true)) . '">PDF</a></td></tr>';
    }
    echo ($rows ? '' : '<tr><td colspan="8">No inspections yet.</td></tr>') . '</tbody></table>';
}

function sa_g_admin_view_reminders(): void
{
    global $wpdb;
    $rows = $wpdb->get_results('SELECT r.*, v.plate, v.make, v.model, v.mileage FROM ' . sa_g_t('reminders') . ' r JOIN ' . sa_g_t('vehicles') . ' v ON v.id=r.vehicle_id ORDER BY r.status, r.due_date LIMIT 300', ARRAY_A) ?: [];
    $due = sa_g_reminders_run(true);
    echo '<p>' . sa_g_post_form('reminders_run', '<button class="button button-primary">Send due reminders now (' . count($due) . ')</button>') . '</p><p class="description">Reminders are emailed automatically each morning when the date is within 7 days or the last mileage is within 500 km of the target.</p>';
    echo '<table class="widefat striped sa-ga-table"><thead><tr><th>Vehicle</th><th>Service</th><th>Due date</th><th>Due km</th><th>Last km</th><th>Status</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td><a href="' . esc_url(sa_g_admin_url('vehicle', ['id' => $r['vehicle_id']])) . '">' . esc_html(trim($r['make'] . ' ' . $r['model'] . ' ' . (str_starts_with($r['plate'], 'NOPLATE') ? '' : $r['plate']))) . '</a></td><td>' . esc_html($r['service']) . '</td><td>' . esc_html($r['due_date'] ? mysql2date('j M Y', $r['due_date']) : '') . '</td><td>' . esc_html((int) $r['due_km'] ? number_format((int) $r['due_km']) : '') . '</td><td>' . esc_html((int) $r['mileage'] ? number_format((int) $r['mileage']) : '') . '</td><td>' . esc_html($r['status'] . ($r['sent_at'] ? ' ' . mysql2date('j M', $r['sent_at']) : '')) . '</td></tr>';
    }
    echo ($rows ? '' : '<tr><td colspan="6">No reminders yet. Add them from a repair order or vehicle profile.</td></tr>') . '</tbody></table>';
}

/* ---------- Reports ---------- */

function sa_g_report_data(string $from, string $to): array
{
    global $wpdb;
    $R = sa_g_t('ro');
    $I = sa_g_t('ro_items');
    $f = $from . ' 00:00:00';
    $t = $to . ' 23:59:59';
    $rev = $wpdb->get_results($wpdb->prepare("SELECT DATE_FORMAT(paid_at,'%%Y-%%m') m, COUNT(*) n, SUM(total_kes) kes FROM $R WHERE paid_at BETWEEN %s AND %s GROUP BY m ORDER BY m", $f, $t), ARRAY_A) ?: [];
    $status = $wpdb->get_results($wpdb->prepare("SELECT status, COUNT(*) n, SUM(total_kes) kes FROM $R WHERE created BETWEEN %s AND %s GROUP BY status", $f, $t), ARRAY_A) ?: [];
    $top = $wpdb->get_results($wpdb->prepare("SELECT i.description d, COUNT(*) n, SUM(i.qty*i.unit_kes) kes, SUM(i.hours) h FROM $I i JOIN $R r ON r.id=i.ro_id WHERE i.type='labour' AND i.approval='approved' AND r.created BETWEEN %s AND %s GROUP BY i.description ORDER BY n DESC, kes DESC LIMIT 20", $f, $t), ARRAY_A) ?: [];
    $parts = $wpdb->get_results($wpdb->prepare("SELECT i.sku, i.description d, SUM(i.qty) q, SUM(i.qty*i.unit_kes) kes, SUM(i.deducted) ded FROM $I i JOIN $R r ON r.id=i.ro_id WHERE i.type='part' AND i.approval='approved' AND r.created BETWEEN %s AND %s GROUP BY i.product_id, i.sku, i.description ORDER BY q DESC LIMIT 50", $f, $t), ARRAY_A) ?: [];
    $techs = [];
    foreach (sa_g_techs() as $u) {
        $s = sa_g_tech_stats((int) $u->ID, $f, $t);
        if ($s['clocked'] || $s['billed'] || $s['done']) {
            $techs[] = ['name' => $u->display_name] + $s;
        }
    }
    return compact('rev', 'status', 'top', 'parts', 'techs');
}

function sa_g_admin_view_reports(): void
{
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : wp_date('Y-m-01', strtotime('-5 months'));
    $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : wp_date('Y-m-d');
    $d = sa_g_report_data($from, $to);
    $csv = static fn(string $r) => esc_url(wp_nonce_url(add_query_arg(['action' => 'sa_g_csv', 'r' => $r, 'from' => $from, 'to' => $to], admin_url('admin-post.php')), 'sa_g_csv'));
    echo '<form method="get" class="sa-ga-inline"><input type="hidden" name="page" value="sa-super-workshop"><input type="hidden" name="view" value="reports"><label>From <input type="date" name="from" value="' . esc_attr($from) . '"></label><label>To <input type="date" name="to" value="' . esc_attr($to) . '"></label><button class="button">Apply</button></form>';
    $tbl = static function (string $title, array $head, array $rows, string $csvurl): void {
        echo '<div class="sa-ga-card"><h3>' . esc_html($title) . ' <a class="button button-small" href="' . $csvurl . '">CSV</a></h3><table class="widefat striped sa-ga-table"><thead><tr>';
        foreach ($head as $h) {
            echo '<th>' . esc_html($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr>';
            foreach ($r as $c) {
                echo '<td>' . esc_html((string) $c) . '</td>';
            }
            echo '</tr>';
        }
        echo ($rows ? '' : '<tr><td colspan="' . count($head) . '">No data in this period.</td></tr>') . '</tbody></table></div>';
    };
    $total = array_sum(array_column($d['rev'], 'kes'));
    echo '<div class="sa-ga-kpis"><div><span>Paid workshop revenue</span><strong>' . esc_html(sa_g_kes($total)) . '</strong></div><div><span>Paid ROs</span><strong>' . (int) array_sum(array_column($d['rev'], 'n')) . '</strong></div><div><span>ROs opened</span><strong>' . (int) array_sum(array_column($d['status'], 'n')) . '</strong></div></div>';
    echo '<div class="sa-ga-grid2"><div>';
    $tbl('Revenue by month (paid ROs)', ['Month', 'ROs', 'KES'], array_map(static fn($r) => [$r['m'], $r['n'], number_format((float) $r['kes'])], $d['rev']), $csv('revenue'));
    $tbl('Repair orders by status', ['Status', 'Count', 'Value KES'], array_map(static fn($r) => [sa_g_ro_statuses()[$r['status']] ?? $r['status'], $r['n'], number_format((float) $r['kes'])], $d['status']), $csv('ros'));
    $tbl('Technician productivity', ['Technician', 'Clocked h', 'Billed h', 'Efficiency', 'Delivered'], array_map(static fn($r) => [$r['name'], $r['clocked'], $r['billed'], $r['eff'] === null ? '—' : $r['eff'] . '%', $r['done']], $d['techs']), $csv('techs'));
    echo '</div><div>';
    $tbl('Top services (approved labour)', ['Service', 'Jobs', 'Hours', 'KES'], array_map(static fn($r) => [$r['d'], $r['n'], (float) $r['h'], number_format((float) $r['kes'])], $d['top']), $csv('services'));
    $tbl('Parts usage', ['SKU', 'Part', 'Qty', 'KES', 'Lines deducted'], array_map(static fn($r) => [$r['sku'], $r['d'], (float) $r['q'], number_format((float) $r['kes']), $r['ded']], $d['parts']), $csv('parts'));
    echo '</div></div>';
}

add_action('admin_post_sa_g_csv', static function (): void {
    if (!current_user_can('manage_woocommerce') || !wp_verify_nonce((string) ($_GET['_wpnonce'] ?? ''), 'sa_g_csv')) {
        wp_die('Not allowed', 403);
    }
    $from = (string) ($_GET['from'] ?? wp_date('Y-01-01'));
    $to = (string) ($_GET['to'] ?? wp_date('Y-m-d'));
    $d = sa_g_report_data($from, $to);
    $r = sanitize_key((string) ($_GET['r'] ?? 'revenue'));
    $map = [
        'revenue' => [['Month', 'ROs', 'KES'], array_map(static fn($x) => [$x['m'], $x['n'], $x['kes']], $d['rev'])],
        'ros' => [['Status', 'Count', 'KES'], array_map(static fn($x) => [$x['status'], $x['n'], $x['kes']], $d['status'])],
        'techs' => [['Technician', 'Clocked h', 'Billed h', 'Efficiency %', 'Delivered'], array_map(static fn($x) => [$x['name'], $x['clocked'], $x['billed'], $x['eff'], $x['done']], $d['techs'])],
        'services' => [['Service', 'Jobs', 'Hours', 'KES'], array_map(static fn($x) => [$x['d'], $x['n'], $x['h'], $x['kes']], $d['top'])],
        'parts' => [['SKU', 'Part', 'Qty', 'KES', 'Deducted lines'], array_map(static fn($x) => [$x['sku'], $x['d'], $x['q'], $x['kes'], $x['ded']], $d['parts'])],
    ];
    [$head, $rows] = $map[$r] ?? $map['revenue'];
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="workshop-' . $r . '-' . $from . '-to-' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $head);
    foreach ($rows as $row) {
        fputcsv($out, array_map(static fn($c) => is_string($c) && preg_match('/^[=+\-@]/', $c) ? "'" . $c : $c, $row));
    }
    fclose($out);
    exit;
});

function sa_g_admin_view_settings(): void
{
    echo '<div class="sa-ga-card"><h3>Workshop bays</h3>' . sa_g_post_form('bays', '<label>One bay per line<textarea name="bays" rows="5">' . esc_textarea(implode("\n", sa_g_bays())) . '</textarea></label><button class="button button-primary">Save bays</button>', [], 'sa-ga-stack') . '</div>';
    echo '<div class="sa-ga-card"><h3>Link existing records</h3>' . sa_g_post_form('backfill', '<button class="button">Link bookings &amp; roadside requests to vehicles</button>') . '<p class="description">Runs automatically once; use this to re-run after imports.</p></div>';
}

/* ---------- Actions ---------- */

add_action('admin_post_sa_g_admin', static function (): void {
    if (!current_user_can('manage_woocommerce') || !wp_verify_nonce((string) ($_POST['_wpnonce'] ?? ''), 'sa_g_admin')) {
        wp_die('Not allowed', 403);
    }
    global $wpdb;
    $p = static fn(string $k): string => sanitize_text_field(wp_unslash((string) ($_POST[$k] ?? '')));
    $id = (int) ($_POST['id'] ?? 0);
    $op = sanitize_key((string) ($_POST['op'] ?? ''));
    $go = sa_g_admin_url('ro', ['id' => $id]);
    $msg = 'Saved.';
    switch ($op) {
        case 'ro_create':
            $vid = sa_garage_link_vehicle(['plate' => $p('plate'), 'make' => $p('make'), 'model' => $p('model'), 'year' => $p('year'), 'vin' => $p('vin')], ['name' => $p('name'), 'email' => $p('email'), 'phone' => $p('phone')], 'manual', 0);
            $cid = sa_g_upsert_customer(['name' => $p('name'), 'email' => $p('email'), 'phone' => $p('phone')]);
            $id = sa_g_ro_create(['customer_id' => $cid, 'vehicle_id' => $vid, 'mileage_in' => (int) $p('mileage'), 'complaint' => sanitize_textarea_field(wp_unslash((string) ($_POST['complaint'] ?? '')))]);
            $go = sa_g_admin_url('ro', ['id' => $id]);
            $msg = 'Repair order created.';
            break;
        case 'ro_from_booking':
            $id = sa_g_ro_from_booking((int) ($_POST['booking'] ?? 0));
            $go = sa_g_admin_url('ro', ['id' => $id]);
            $msg = 'Repair order created from booking.';
            break;
        case 'ro_status':
            sa_g_ro_set_status($id, sanitize_key($p('status')));
            if ($p('back') === 'board') {
                $go = sa_g_admin_url('board');
            }
            break;
        case 'item_add':
            $type = sanitize_key($p('type'));
            $pid = 0;
            if ($type === 'part') {
                $q = $p('part_q');
                $pid = ctype_digit($q) && get_post_type((int) $q) === 'product' ? (int) $q : (int) wc_get_product_id_by_sku($q);
                if (!$pid) {
                    $msg = 'No product found for “' . $q . '”.';
                    break;
                }
            }
            sa_g_ro_add_item($id, ['type' => $type, 'product_id' => $pid, 'description' => $p('description'), 'qty' => (float) ($p('qty') ?: 1), 'hours' => (float) $p('hours'), 'unit_kes' => $p('unit_kes') !== '' ? (float) $p('unit_kes') : 0, 'tech_id' => (int) $p('tech_id')]);
            $msg = 'Line added.';
            break;
        case 'item_approval':
            $a = sanitize_key($p('approval'));
            if (in_array($a, ['pending', 'approved', 'declined'], true)) {
                $wpdb->update(sa_g_t('ro_items'), ['approval' => $a], ['id' => (int) $_POST['item'], 'ro_id' => $id]);
                sa_g_ro_recalc($id);
                sa_g_ro_log($id, (string) sa_g_ro($id)['status'], 'Line marked ' . $a . ' by staff');
            }
            break;
        case 'item_remove':
            sa_g_ro_remove_item((int) $_POST['item']);
            break;
        case 'ro_send_estimate':
            $ok = sa_g_ro_email($id, 'estimate');
            if ($ok) {
                $wpdb->update(sa_g_t('ro'), ['sent_at' => sa_g_now()], ['id' => $id]);
                if (in_array(sa_g_ro($id)['status'], ['estimate'], true)) {
                    sa_g_ro_set_status($id, 'sent', 'Estimate emailed');
                }
            }
            $msg = $ok ? 'Estimate emailed to the customer.' : 'Could not email: customer email missing.';
            break;
        case 'ro_invoice':
            $msg = sa_g_ro_email($id, 'invoice') ? 'Invoice created and pay link emailed.' : 'Invoice not sent (no approved lines or no customer email).';
            break;
        case 'ro_assign':
            $dt = static fn(string $s) => preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $s) ? str_replace('T', ' ', $s) . ':00' : null;
            $wpdb->update(sa_g_t('ro'), ['tech_id' => (int) $p('tech_id'), 'bay' => $p('bay'), 'sched_start' => $dt($p('sched_start')), 'sched_end' => $dt($p('sched_end')), 'mileage_in' => (int) $p('mileage_in'), 'notes' => sanitize_textarea_field(wp_unslash((string) ($_POST['notes'] ?? ''))), 'updated' => sa_g_now()], ['id' => $id]);
            $r = sa_g_ro($id);
            if ((int) $p('mileage_in') && $r['vehicle_id']) {
                sa_g_add_mileage((int) $r['vehicle_id'], (int) $p('mileage_in'), 'ro', $id);
            }
            sa_g_ro_log($id, $r['status'], 'Job details updated');
            break;
        case 'reminder_add':
            $vid = (int) ($_POST['vehicle_id'] ?? 0);
            $dd = preg_match('/^\d{4}-\d{2}-\d{2}$/', $p('due_date')) ? $p('due_date') : '';
            $msg = sa_g_reminder_add($vid, $p('service'), $dd, (int) $p('due_km'), $id) ? 'Reminder added.' : 'Reminder needs a vehicle, a service and a date or mileage.';
            if (!empty($_POST['back_vehicle'])) {
                $go = sa_g_admin_url('vehicle', ['id' => $vid]);
            }
            break;
        case 'reminders_run':
            $msg = count(sa_g_reminders_run()) . ' reminder(s) sent.';
            $go = sa_g_admin_url('reminders');
            break;
        case 'vehicle_save':
            $plate = sa_g_plate($p('plate'));
            $dupe = $plate !== '' ? (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . sa_g_t('vehicles') . ' WHERE plate=%s AND id<>%d', $plate, $id)) : 0;
            if ($dupe) {
                $msg = 'Plate ' . $plate . ' already belongs to another vehicle.';
            } else {
                $upd = ['make' => $p('make'), 'model' => $p('model'), 'year' => (int) $p('year'), 'vin' => strtoupper($p('vin')), 'engine' => $p('engine'), 'color' => $p('color'), 'notes' => sanitize_textarea_field(wp_unslash((string) ($_POST['notes'] ?? ''))), 'updated' => sa_g_now()];
                if ($plate !== '') {
                    $upd['plate'] = $plate;
                }
                $wpdb->update(sa_g_t('vehicles'), $upd, ['id' => $id]);
                if ((int) $p('mileage')) {
                    sa_g_add_mileage($id, (int) $p('mileage'), 'manual');
                }
            }
            $go = sa_g_admin_url('vehicle', ['id' => $id]);
            break;
        case 'customer_save':
            $wpdb->update(sa_g_t('customers'), ['name' => $p('name'), 'phone' => sa_g_phone($p('phone')), 'email' => strtolower(sanitize_email($p('email'))), 'notes' => sanitize_textarea_field(wp_unslash((string) ($_POST['notes'] ?? '')))], ['id' => $id]);
            $go = sa_g_admin_url('customer', ['id' => $id]);
            break;
        case 'tech_add':
            $email = sanitize_email($p('email'));
            $u = get_user_by('email', $email);
            if (!$u && is_email($email)) {
                $uid = wp_insert_user(['user_login' => sanitize_user(strtok($email, '@') . '-' . wp_generate_password(4, false, false), true), 'user_email' => $email, 'display_name' => $p('name'), 'first_name' => strtok($p('name'), ' '), 'user_pass' => wp_generate_password(24), 'role' => 'sa_mechanic']);
                if (!is_wp_error($uid)) {
                    $u = get_userdata((int) $uid);
                    $key = get_password_reset_key($u);
                    if (!is_wp_error($key)) {
                        wp_mail($email, 'Your Supreme Autoparts workshop login', sa_mail_layout('Welcome to the workshop app', '<p>Hi ' . esc_html($p('name')) . ',</p><p>You have been added as a mechanic. Set your password, then open the workshop app on your phone to see your jobs, clock in and fill inspection cards.</p><p>Workshop app: ' . esc_html(home_url('/workshop/')) . '</p>', 'Set my password', network_site_url('wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode($u->user_login), 'login')), ['Content-Type: text/html; charset=UTF-8']);
                    }
                }
            } elseif ($u) {
                $u->add_role('sa_mechanic');
            }
            $msg = $u ? 'Mechanic ready: ' . $email : 'Could not add that email.';
            $go = sa_g_admin_url('techs');
            break;
        case 'bays':
            $b = array_values(array_filter(array_map('sanitize_text_field', array_map('trim', explode("\n", wp_unslash((string) ($_POST['bays'] ?? '')))))));
            update_option('sa_garage_bays', $b ?: ['Bay 1'], false);
            $go = sa_g_admin_url('settings');
            break;
        case 'backfill':
            $r = sa_g_backfill(1000);
            $msg = 'Linked ' . $r['booking'] . ' booking(s) and ' . $r['roadside'] . ' roadside request(s).';
            $go = sa_g_admin_url('settings');
            break;
    }
    wp_safe_redirect(add_query_arg('msg', rawurlencode($msg), $go));
    exit;
});

/* Quick links from bookings / roadside admin: create a repair order. */
add_action('admin_post_sa_g_ro_from', static function (): void {
    if (!current_user_can('manage_woocommerce') || !wp_verify_nonce((string) ($_GET['_wpnonce'] ?? ''), 'sa_g_ro_from')) {
        wp_die('Not allowed', 403);
    }
    $id = !empty($_GET['booking']) ? sa_g_ro_from_booking((int) $_GET['booking']) : sa_g_ro_from_roadside((int) ($_GET['roadside'] ?? 0));
    wp_safe_redirect(sa_g_admin_url('ro', ['id' => $id]));
    exit;
});
