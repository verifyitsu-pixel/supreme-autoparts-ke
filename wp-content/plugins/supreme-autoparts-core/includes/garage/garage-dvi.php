<?php
/**
 * Digital vehicle inspections: pickup / drop-off condition cards and full DVI (red/yellow/green),
 * mobile mechanic app at /workshop/, customer report at /inspection-report/, PDF with logo.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

function sa_g_dvi_kinds(): array
{
    return ['pickup' => 'Pickup condition card', 'dropoff' => 'Drop-off condition card', 'inspection' => 'Vehicle health inspection'];
}

function sa_g_dvi_checks(): array
{
    return [
        'Exterior & lights' => ['headlights' => 'Headlights', 'brake_lights' => 'Brake lights', 'indicators' => 'Indicators / hazards', 'reverse_lights' => 'Reverse lights', 'windscreen' => 'Windscreen & glass', 'wipers' => 'Wipers & washers', 'mirrors' => 'Mirrors'],
        'Interior' => ['seats' => 'Seats & upholstery', 'dashboard' => 'Dashboard & trim', 'ac' => 'A/C & heater', 'audio' => 'Radio / infotainment', 'windows' => 'Power windows & locks', 'horn' => 'Horn', 'seatbelts' => 'Seatbelts'],
        'Under the bonnet' => ['engine_oil' => 'Engine oil', 'coolant' => 'Coolant', 'brake_fluid' => 'Brake fluid', 'battery' => 'Battery', 'belts_hoses' => 'Belts & hoses', 'leaks' => 'Leaks'],
        'Underbody' => ['brakes' => 'Brake pads & discs', 'suspension' => 'Suspension & steering', 'exhaust' => 'Exhaust', 'cv_joints' => 'CV joints & boots'],
    ];
}
function sa_g_dvi_tyres(): array
{
    return ['fl' => 'Front left', 'fr' => 'Front right', 'rl' => 'Rear left', 'rr' => 'Rear right', 'spare' => 'Spare'];
}
function sa_g_dvi_items_left(): array
{
    return ['spare_wheel' => 'Spare wheel', 'jack' => 'Jack', 'wheel_spanner' => 'Wheel spanner', 'triangle' => 'Warning triangles', 'extinguisher' => 'Fire extinguisher', 'documents' => 'Vehicle documents', 'mats' => 'Floor mats', 'child_seat' => 'Child seat'];
}
function sa_g_dvi_warning_lights(): array
{
    return ['check_engine' => 'Check engine', 'abs' => 'ABS', 'airbag' => 'Airbag', 'battery' => 'Battery / charging', 'oil' => 'Oil pressure', 'temp' => 'Engine temperature', 'brake' => 'Brake system', 'tpms' => 'Tyre pressure', 'other' => 'Other'];
}
function sa_g_damage_types(): array
{
    return ['S' => 'Scratch', 'D' => 'Dent', 'C' => 'Crack', 'X' => 'Chip', 'M' => 'Missing part'];
}

function sa_g_dvi(int $id): ?array
{
    global $wpdb;
    $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . sa_g_t('dvi') . ' WHERE id=%d', $id), ARRAY_A);
    if (!$r) {
        return null;
    }
    $r['d'] = json_decode((string) $r['data'], true) ?: [];
    return $r;
}
function sa_g_dvis_for(array $where, int $limit = 50): array
{
    global $wpdb;
    $sql = 'SELECT * FROM ' . sa_g_t('dvi') . ' WHERE 1=1';
    $args = [];
    foreach ($where as $k => $v) {
        $sql .= ' AND ' . preg_replace('/[^a-z_]/', '', $k) . '=%d';
        $args[] = (int) $v;
    }
    $sql .= ' ORDER BY id DESC LIMIT ' . (int) $limit;
    $rows = $wpdb->get_results($args ? $wpdb->prepare($sql, $args) : $sql, ARRAY_A) ?: [];
    foreach ($rows as &$r) {
        $r['d'] = json_decode((string) $r['data'], true) ?: [];
    }
    return $rows;
}
function sa_g_dvi_report_url(array $d, bool $pdf = false): string
{
    return add_query_arg(array_filter(['i' => (int) $d['id'], 'k' => $d['token'], 'pdf' => $pdf ? 1 : null]), home_url('/inspection-report/'));
}

/* ---------- Mechanic app ---------- */

function sa_g_app_guard(): void
{
    if (!is_user_logged_in()) {
        auth_redirect();
    }
    if (!sa_g_can()) {
        wp_die('This area is for Supreme Autoparts workshop staff only.', 'Workshop', ['response' => 403]);
    }
}

add_action('template_redirect', static function (): void {
    $view = (string) get_query_var('sa_g_app');
    if ($view === '') {
        return;
    }
    sa_g_app_guard();
    if ($view === 'inspect' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        sa_g_dvi_save();
        return;
    }
    if ($view === 'clock' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        check_admin_referer('sa_g_clock');
        $ro = (int) ($_POST['ro'] ?? 0);
        $me = get_current_user_id();
        if (($_POST['do'] ?? '') === 'in' && sa_g_ro($ro)) {
            sa_g_clock_in($ro, $me, (int) ($_POST['item'] ?? 0));
        } elseif (($_POST['do'] ?? '') === 'out') {
            sa_g_clock_out($me);
        } elseif (($_POST['do'] ?? '') === 'status' && sa_g_ro($ro)) {
            $st = sanitize_key((string) ($_POST['status'] ?? ''));
            if (in_array($st, ['in_progress', 'waiting_parts', 'qc', 'ready'], true)) {
                sa_g_ro_set_status($ro, $st, sanitize_text_field(wp_unslash((string) ($_POST['note'] ?? ''))));
            }
        }
        wp_safe_redirect(wp_get_referer() ?: home_url('/workshop/'));
        exit;
    }
    sa_g_public_head('Workshop');
    wp_enqueue_script('sa-garage', SA_CORE_URL . 'assets/garage.js', [], SA_CORE_VERSION, true);
    echo '<main class="sa-g-app">';
    if ($view === 'inspect') {
        sa_g_render_inspect_form();
    } elseif ($view === 'job') {
        sa_g_render_job((int) ($_GET['ro'] ?? 0));
    } else {
        sa_g_render_app_home();
    }
    echo '</main>';
    get_footer();
    exit;
});

function sa_g_render_app_home(): void
{
    global $wpdb;
    $me = get_current_user_id();
    $open = sa_g_clock_open($me);
    echo '<header class="sa-g-app__head"><div><p class="sa-g-eyebrow">Workshop</p><h1>Hi ' . esc_html(wp_get_current_user()->display_name) . '</h1></div>';
    echo '<a class="sa-btn" href="' . esc_url(home_url('/workshop/inspect/')) . '">+ New inspection</a></header>';
    if ($open) {
        $r = sa_g_ro((int) $open['ro_id']);
        echo '<div class="sa-g-clockbar"><span>⏱ Clocked in on <strong>' . esc_html($r['ref'] ?? '') . '</strong> since ' . esc_html(mysql2date('g:i A', $open['started'])) . '</span><form method="post" action="' . esc_url(home_url('/workshop/clock/')) . '">' . wp_nonce_field('sa_g_clock', '_wpnonce', true, false) . '<input type="hidden" name="do" value="out"><button class="sa-btn sa-btn--outline">Clock out</button></form></div>';
    }
    $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . sa_g_t('ro') . " WHERE status IN ('approved','in_progress','waiting_parts','qc','ready','estimate','sent') AND (tech_id=%d OR %d=1) ORDER BY FIELD(status,'in_progress','approved','waiting_parts','qc','ready','sent','estimate'), sched_start LIMIT 60", $me, sa_g_can_manage() ? 1 : 0), ARRAY_A) ?: [];
    echo '<h2 class="sa-g-h2">' . (sa_g_can_manage() ? 'Open jobs' : 'My jobs') . '</h2><div class="sa-g-cards">';
    if (!$rows) {
        echo '<p class="sa-g-muted">No open jobs right now.</p>';
    }
    foreach ($rows as $r) {
        $v = sa_g_vehicle((int) $r['vehicle_id']);
        echo '<a class="sa-g-jobcard" href="' . esc_url(add_query_arg('ro', (int) $r['id'], home_url('/workshop/job/'))) . '"><span class="sa-g-pill sa-g-pill--' . esc_attr($r['status']) . '">' . esc_html(sa_g_ro_statuses()[$r['status']]) . '</span><strong>' . esc_html(sa_g_vehicle_label($v)) . '</strong><small>' . esc_html($r['ref'] . ($r['sched_start'] ? ' · ' . mysql2date('D j M, g:i A', $r['sched_start']) : '') . ($r['bay'] ? ' · ' . $r['bay'] : '')) . '</small></a>';
    }
    echo '</div>';
    $today = wp_date('Y-m-d');
    $bk = get_posts(['post_type' => 'sa_booking', 'numberposts' => 30, 'fields' => 'ids', 'meta_query' => [['key' => '_sa_bk_date', 'value' => [$today, wp_date('Y-m-d', time() + 2 * DAY_IN_SECONDS)], 'compare' => 'BETWEEN']]]);
    if ($bk) {
        echo '<h2 class="sa-g-h2">Bookings (next 3 days)</h2><div class="sa-g-cards">';
        foreach ($bk as $id) {
            $b = sa_svc_booking_data((int) $id);
            if (in_array($b['status'], ['cancelled'], true)) {
                continue;
            }
            echo '<a class="sa-g-jobcard" href="' . esc_url(add_query_arg(['booking' => (int) $id, 'kind' => $b['mode'] === 'pickup' ? 'pickup' : 'dropoff'], home_url('/workshop/inspect/'))) . '"><span class="sa-g-pill">' . esc_html($b['mode'] === 'pickup' ? 'Pickup' : 'Drop-off') . '</span><strong>' . esc_html(trim($b['year'] . ' ' . $b['make'] . ' ' . $b['model']) . ($b['reg'] ? ' · ' . $b['reg'] : '')) . '</strong><small>' . esc_html($b['ref'] . ' · ' . $b['date'] . ' ' . $b['time'] . ' · ' . $b['name']) . ' — tap to inspect</small></a>';
        }
        echo '</div>';
    }
    $mine = sa_g_dvis_for(sa_g_can_manage() ? [] : ['mechanic_id' => $me], 10);
    if ($mine) {
        echo '<h2 class="sa-g-h2">Recent inspections</h2><div class="sa-g-cards">';
        foreach ($mine as $d) {
            $v = sa_g_vehicle((int) $d['vehicle_id']);
            echo '<a class="sa-g-jobcard" href="' . esc_url(sa_g_dvi_report_url($d)) . '"><span class="sa-g-pill">' . esc_html(sa_g_dvi_kinds()[$d['kind']]) . '</span><strong>' . esc_html(sa_g_vehicle_label($v)) . '</strong><small>' . esc_html($d['ref'] . ' · ' . mysql2date('j M, g:i A', $d['created'])) . '</small></a>';
        }
        echo '</div>';
    }
}

function sa_g_render_job(int $ro): void
{
    $r = sa_g_ro($ro);
    if (!$r) {
        echo '<p>Job not found.</p>';
        return;
    }
    $v = sa_g_vehicle((int) $r['vehicle_id']);
    $c = sa_g_customer((int) $r['customer_id']);
    $me = get_current_user_id();
    $open = sa_g_clock_open($me);
    $nonce = wp_nonce_field('sa_g_clock', '_wpnonce', true, false);
    $act = esc_url(home_url('/workshop/clock/'));
    echo '<p><a href="' . esc_url(home_url('/workshop/')) . '">← All jobs</a></p><p class="sa-g-eyebrow">' . esc_html($r['ref']) . '</p><h1>' . esc_html(sa_g_vehicle_label($v)) . '</h1>';
    echo '<p class="sa-g-muted">' . esc_html(($c['name'] ?? '') . ' · ' . ($c['phone'] ?? '')) . '</p><p><span class="sa-g-pill sa-g-pill--' . esc_attr($r['status']) . '">' . esc_html(sa_g_ro_statuses()[$r['status']]) . '</span></p>';
    if ($r['complaint']) {
        echo '<div class="sa-g-card"><strong>Customer concern</strong><p>' . esc_html($r['complaint']) . '</p></div>';
    }
    echo '<div class="sa-g-card"><strong>Work</strong><ul class="sa-g-list">';
    foreach (sa_g_ro_items($ro) as $i) {
        echo '<li><span>' . esc_html(ucfirst($i['type']) . ' · ' . $i['description']) . ($i['type'] === 'labour' && (float) $i['hours'] ? ' <small>(' . esc_html((string) (float) $i['hours']) . ' h)</small>' : '') . '</span><em class="sa-g-appr sa-g-appr--' . esc_attr($i['approval']) . '">' . esc_html(ucfirst($i['approval'])) . '</em></li>';
    }
    echo '</ul></div><div class="sa-g-actions">';
    if ($open && (int) $open['ro_id'] === $ro) {
        echo '<form method="post" action="' . $act . '">' . $nonce . '<input type="hidden" name="do" value="out"><button class="sa-btn sa-btn--outline">⏹ Clock out (' . esc_html(mysql2date('g:i A', $open['started'])) . ')</button></form>';
    } else {
        echo '<form method="post" action="' . $act . '">' . $nonce . '<input type="hidden" name="do" value="in"><input type="hidden" name="ro" value="' . $ro . '"><button class="sa-btn">▶ Clock in</button></form>';
    }
    foreach (['waiting_parts' => 'Waiting parts', 'qc' => 'Ready for QC', 'ready' => 'Job ready'] as $st => $lab) {
        if ($st === 'ready' && !sa_g_can_manage()) {
            continue;
        }
        echo '<form method="post" action="' . $act . '">' . $nonce . '<input type="hidden" name="do" value="status"><input type="hidden" name="status" value="' . $st . '"><input type="hidden" name="ro" value="' . $ro . '"><button class="sa-btn sa-btn--outline">' . esc_html($lab) . '</button></form>';
    }
    echo '<a class="sa-btn sa-btn--outline" href="' . esc_url(add_query_arg(['ro' => $ro, 'kind' => 'inspection'], home_url('/workshop/inspect/'))) . '">Inspection (DVI)</a></div>';
    echo '<p class="sa-g-muted">My time on this job: ' . esc_html((string) sa_g_clocked_hours(['ro_id' => $ro, 'tech_id' => $me])) . ' h</p>';
}

function sa_g_rag(string $name, string $val = ''): string
{
    $h = '<span class="sa-g-rag" role="radiogroup">';
    foreach (['g' => 'Good', 'y' => 'Soon', 'r' => 'Now', 'na' => 'N/A'] as $k => $l) {
        $h .= '<label class="sa-g-rag__' . $k . '"><input type="radio" name="' . esc_attr($name) . '" value="' . $k . '"' . checked($val, $k, false) . '><span>' . $l . '</span></label>';
    }
    return $h . '</span>';
}

function sa_g_car_svg(): string
{
    // Top-down outline, front at the top. viewBox 200x400 — coordinates are stored as % of this box.
    return '<svg viewBox="0 0 200 400" class="sa-g-car" aria-label="Car diagram, front at top. Tap to mark damage.">'
        . '<rect x="30" y="20" width="140" height="360" rx="46" fill="#fff" stroke="#191919" stroke-width="3"/>'
        . '<rect x="48" y="105" width="104" height="58" rx="12" fill="#eef2fe" stroke="#191919" stroke-width="2"/>'
        . '<rect x="48" y="270" width="104" height="44" rx="12" fill="#eef2fe" stroke="#191919" stroke-width="2"/>'
        . '<rect x="52" y="168" width="96" height="96" rx="6" fill="none" stroke="#999" stroke-width="1.5" stroke-dasharray="4 3"/>'
        . '<line x1="100" y1="22" x2="100" y2="100" stroke="#bbb" stroke-width="1.5"/>'
        . '<rect x="14" y="70" width="18" height="50" rx="5" fill="#333"/><rect x="168" y="70" width="18" height="50" rx="5" fill="#333"/>'
        . '<rect x="14" y="282" width="18" height="50" rx="5" fill="#333"/><rect x="168" y="282" width="18" height="50" rx="5" fill="#333"/>'
        . '<text x="100" y="14" text-anchor="middle" font-size="11" fill="#666">FRONT</text><text x="100" y="396" text-anchor="middle" font-size="11" fill="#666">REAR</text>'
        . '<text x="8" y="205" font-size="10" fill="#666" transform="rotate(-90 8 205)">LEFT</text><text x="194" y="190" font-size="10" fill="#666" transform="rotate(90 194 190)">RIGHT</text>'
        . '<g class="sa-g-car__marks"></g></svg>';
}

function sa_g_render_inspect_form(): void
{
    $kind = sanitize_key((string) ($_GET['kind'] ?? 'pickup'));
    $kind = isset(sa_g_dvi_kinds()[$kind]) ? $kind : 'pickup';
    $pre = ['name' => '', 'phone' => '', 'email' => '', 'plate' => '', 'make' => '', 'model' => '', 'year' => '', 'vin' => '', 'mileage' => ''];
    $bid = (int) ($_GET['booking'] ?? 0);
    $rid = (int) ($_GET['roadside'] ?? 0);
    $ro = (int) ($_GET['ro'] ?? 0);
    if ($bid && get_post_type($bid) === 'sa_booking') {
        $b = sa_svc_booking_data($bid);
        $pre = array_merge($pre, ['name' => $b['name'], 'phone' => $b['phone'], 'email' => $b['email'], 'plate' => $b['reg'], 'make' => $b['make'], 'model' => $b['model'], 'year' => $b['year']]);
    } elseif ($rid && get_post_type($rid) === 'sa_roadside') {
        $r = sa_rs_data($rid);
        $pre = array_merge($pre, ['name' => (string) $r['name'], 'phone' => (string) $r['phone'], 'email' => (string) $r['email'], 'plate' => (string) $r['reg'], 'make' => (string) $r['make'], 'model' => (string) $r['model'], 'year' => (string) $r['year']]);
    }
    if ($ro && ($rr = sa_g_ro($ro))) {
        $v = sa_g_vehicle((int) $rr['vehicle_id']) ?: [];
        $c = sa_g_customer((int) $rr['customer_id']) ?: [];
        $pre = array_merge($pre, array_filter(['name' => $c['name'] ?? '', 'phone' => $c['phone'] ?? '', 'email' => $c['email'] ?? '', 'plate' => str_starts_with((string) ($v['plate'] ?? ''), 'NOPLATE') ? '' : ($v['plate'] ?? ''), 'make' => $v['make'] ?? '', 'model' => $v['model'] ?? '', 'year' => (int) ($v['year'] ?? 0) ?: '', 'vin' => $v['vin'] ?? '']));
        $bid = $bid ?: (int) $rr['booking_id'];
        $rid = $rid ?: (int) $rr['roadside_id'];
    }
    $f = static fn(string $n, string $label, string $type = 'text', string $extra = '') => '<label class="sa-g-field"><span>' . esc_html($label) . '</span><input type="' . $type . '" name="' . $n . '" value="' . esc_attr((string) $pre[$n]) . '" ' . $extra . '></label>';
    echo '<form method="post" class="sa-g-insp" id="sa-g-insp" novalidate>';
    wp_nonce_field('sa_g_dvi', '_sa_g_dvi');
    echo '<input type="hidden" name="booking_id" value="' . $bid . '"><input type="hidden" name="roadside_id" value="' . $rid . '"><input type="hidden" name="ro_id" value="' . $ro . '"><input type="hidden" name="lat"><input type="hidden" name="lng"><input type="hidden" name="damage" value="[]"><input type="hidden" name="sig_customer"><input type="hidden" name="sig_mechanic">';
    echo '<header class="sa-g-app__head"><div><p class="sa-g-eyebrow"><a href="' . esc_url(home_url('/workshop/')) . '">← Workshop</a></p><h1>Vehicle inspection</h1></div><span class="sa-g-gps" data-gps>📍 Getting location…</span></header>';
    echo '<section class="sa-g-sec"><h2>Card type</h2><div class="sa-g-seg">';
    foreach (sa_g_dvi_kinds() as $k => $l) {
        echo '<label><input type="radio" name="kind" value="' . $k . '"' . checked($kind, $k, false) . '><span>' . esc_html($l) . '</span></label>';
    }
    echo '</div>';
    if ($bid || $rid || $ro) {
        echo '<p class="sa-g-muted">Linked to ' . esc_html(implode(', ', array_filter([$bid ? 'booking ' . get_post_meta($bid, '_sa_bk_ref', true) : '', $rid ? 'roadside ' . get_post_meta($rid, '_rs_ref', true) : '', $ro ? 'repair order ' . (sa_g_ro($ro)['ref'] ?? '') : '']))) . '</p>';
    }
    echo '</section>';
    echo '<section class="sa-g-sec"><h2>Customer</h2><div class="sa-g-grid">' . $f('name', 'Name', 'text', 'required autocomplete="off"') . $f('phone', 'Phone', 'tel', 'required') . $f('email', 'Email (report is sent here)', 'email') . '</div></section>';
    echo '<section class="sa-g-sec"><h2>Vehicle</h2><div class="sa-g-grid">' . $f('plate', 'Reg plate', 'text', 'required style="text-transform:uppercase" placeholder="KDA 123A"') . $f('make', 'Make', 'text', 'required') . $f('model', 'Model', 'text', 'required') . $f('year', 'Year', 'number', 'min="1950" max="' . ((int) gmdate('Y') + 1) . '" inputmode="numeric"') . $f('vin', 'VIN / chassis', 'text', 'style="text-transform:uppercase"') . $f('mileage', 'Mileage (km)', 'number', 'inputmode="numeric" min="0" required') . '</div>';
    echo '<label class="sa-g-field"><span>Fuel level: <output data-fuel-out>4/8</output></span><input type="range" name="fuel" min="0" max="8" value="4" data-fuel></label></section>';
    echo '<section class="sa-g-sec"><h2>Exterior damage</h2><p class="sa-g-muted">Pick a type, then tap the car where the damage is. Tap a mark to remove it.</p><div class="sa-g-seg sa-g-seg--sm" data-dmg-types>';
    $first = true;
    foreach (sa_g_damage_types() as $k => $l) {
        echo '<label><input type="radio" name="dmg_type" value="' . $k . '"' . ($first ? ' checked' : '') . '><span><b>' . $k . '</b> ' . esc_html($l) . '</span></label>';
        $first = false;
    }
    echo '</div><div class="sa-g-carwrap" data-car>' . sa_g_car_svg() . '</div><ol class="sa-g-dmglist" data-dmg-list></ol></section>';
    echo '<section class="sa-g-sec"><h2>Photos</h2><p class="sa-g-muted">Take photos of all four sides, the dashboard (mileage and warning lights) and any damage.</p><label class="sa-btn sa-btn--outline sa-g-photo-btn">📷 Add photos<input type="file" accept="image/*" capture="environment" multiple data-photos hidden></label><div class="sa-g-thumbs" data-thumbs></div></section>';
    echo '<section class="sa-g-sec"><h2>Tyres</h2><div class="sa-g-checks">';
    foreach (sa_g_dvi_tyres() as $k => $l) {
        echo '<div class="sa-g-check"><span>' . esc_html($l) . '</span>' . sa_g_rag('tyre[' . $k . '][r]') . '<input type="number" name="tyre[' . $k . '][mm]" placeholder="Tread mm" step="0.5" min="0" max="20" inputmode="decimal" class="sa-g-mm"></div>';
    }
    echo '</div></section>';
    foreach (sa_g_dvi_checks() as $group => $items) {
        echo '<section class="sa-g-sec"><h2>' . esc_html($group) . '</h2><div class="sa-g-checks">';
        foreach ($items as $k => $l) {
            echo '<div class="sa-g-check"><span>' . esc_html($l) . '</span>' . sa_g_rag('chk[' . $k . '][r]') . '<input type="text" name="chk[' . $k . '][n]" placeholder="Note" class="sa-g-note"></div>';
        }
        echo '</div></section>';
    }
    echo '<section class="sa-g-sec"><h2>Warning lights on</h2><div class="sa-g-chips">';
    foreach (sa_g_dvi_warning_lights() as $k => $l) {
        echo '<label><input type="checkbox" name="warn[]" value="' . $k . '"><span>' . esc_html($l) . '</span></label>';
    }
    echo '</div></section><section class="sa-g-sec"><h2>Items left in the car</h2><div class="sa-g-chips">';
    foreach (sa_g_dvi_items_left() as $k => $l) {
        echo '<label><input type="checkbox" name="items[]" value="' . $k . '"><span>' . esc_html($l) . '</span></label>';
    }
    echo '</div><label class="sa-g-field"><span>Other items / valuables</span><input type="text" name="items_other" placeholder="e.g. sunglasses in glovebox"></label></section>';
    echo '<section class="sa-g-sec"><h2>Notes</h2><textarea name="notes" rows="4" class="sa-g-textarea" placeholder="Anything the customer should know"></textarea></section>';
    echo '<section class="sa-g-sec"><h2>Signatures</h2><div class="sa-g-sigs"><div><span>Customer</span><canvas class="sa-g-sig" data-sig="sig_customer" width="600" height="200"></canvas><button type="button" class="sa-g-link" data-sig-clear="sig_customer">Clear</button></div><div><span>Mechanic (' . esc_html(wp_get_current_user()->display_name) . ')</span><canvas class="sa-g-sig" data-sig="sig_mechanic" width="600" height="200"></canvas><button type="button" class="sa-g-link" data-sig-clear="sig_mechanic">Clear</button></div></div>';
    echo '<label class="sa-g-check sa-g-check--email"><input type="checkbox" name="send" value="1" checked> Email the PDF report to the customer</label></section>';
    echo '<div class="sa-g-submitbar"><button class="sa-btn" type="submit" data-submit>Save inspection</button></div></form>';
}

function sa_g_dvi_new_ref(): string
{
    global $wpdb;
    do {
        $ref = 'INSP-' . wp_date('ymd') . '-' . strtoupper(wp_generate_password(4, false, false));
    } while ($wpdb->get_var($wpdb->prepare('SELECT id FROM ' . sa_g_t('dvi') . ' WHERE ref=%s', $ref)));
    return $ref;
}

function sa_g_dvi_save(): void
{
    global $wpdb;
    if (!wp_verify_nonce((string) ($_POST['_sa_g_dvi'] ?? ''), 'sa_g_dvi')) {
        wp_die('Session expired, go back and save again.', 'Workshop', ['response' => 400, 'back_link' => true]);
    }
    $p = static fn(string $k): string => sanitize_text_field(wp_unslash((string) ($_POST[$k] ?? '')));
    $kind = isset(sa_g_dvi_kinds()[$p('kind')]) ? $p('kind') : 'pickup';
    $rag = static fn($v): string => in_array($v, ['g', 'y', 'r', 'na'], true) ? $v : '';
    $tyres = [];
    foreach (sa_g_dvi_tyres() as $k => $l) {
        $tyres[$k] = ['r' => $rag($_POST['tyre'][$k]['r'] ?? ''), 'mm' => is_numeric($_POST['tyre'][$k]['mm'] ?? '') ? (float) $_POST['tyre'][$k]['mm'] : null];
    }
    $chk = [];
    foreach (sa_g_dvi_checks() as $items) {
        foreach ($items as $k => $l) {
            $chk[$k] = ['r' => $rag($_POST['chk'][$k]['r'] ?? ''), 'n' => sanitize_text_field(wp_unslash((string) ($_POST['chk'][$k]['n'] ?? '')))];
        }
    }
    $dmg = [];
    foreach ((array) json_decode(wp_unslash((string) ($_POST['damage'] ?? '[]')), true) as $m) {
        if (isset($m['x'], $m['y'], $m['t']) && isset(sa_g_damage_types()[$m['t']])) {
            $dmg[] = ['x' => max(0, min(100, round((float) $m['x'], 1))), 'y' => max(0, min(100, round((float) $m['y'], 1))), 't' => $m['t'], 'n' => sanitize_text_field((string) ($m['n'] ?? ''))];
        }
    }
    $warn = array_values(array_intersect(array_map('sanitize_key', (array) ($_POST['warn'] ?? [])), array_keys(sa_g_dvi_warning_lights())));
    $items = array_values(array_intersect(array_map('sanitize_key', (array) ($_POST['items'] ?? [])), array_keys(sa_g_dvi_items_left())));
    $bid = (int) ($_POST['booking_id'] ?? 0);
    $rid = (int) ($_POST['roadside_id'] ?? 0);
    $ro = (int) ($_POST['ro_id'] ?? 0);
    $km = max(0, (int) $p('mileage'));
    $ref = sa_g_dvi_new_ref();
    $src = $ro ? 'ro' : ($bid ? 'booking' : ($rid ? 'roadside' : 'dvi'));
    $vid = sa_garage_link_vehicle(['plate' => $p('plate'), 'make' => $p('make'), 'model' => $p('model'), 'year' => $p('year'), 'vin' => $p('vin'), 'mileage' => $km], ['name' => $p('name'), 'email' => $p('email'), 'phone' => $p('phone')], 'dvi_tmp', 0);
    $cid = sa_g_upsert_customer(['name' => $p('name'), 'email' => $p('email'), 'phone' => $p('phone')]);
    $photos = [];
    foreach (array_slice((array) ($_POST['photos'] ?? []), 0, 12) as $n => $du) {
        $aid = sa_g_save_dataurl((string) wp_unslash($du), $ref . '-photo-' . ($n + 1));
        if ($aid) {
            $photos[] = $aid;
        }
    }
    $sig_c = sa_g_save_dataurl((string) wp_unslash($_POST['sig_customer'] ?? ''), $ref . '-sig-customer');
    $sig_m = sa_g_save_dataurl((string) wp_unslash($_POST['sig_mechanic'] ?? ''), $ref . '-sig-mechanic');
    $data = ['customer' => ['name' => $p('name'), 'phone' => $p('phone'), 'email' => sanitize_email($p('email'))], 'vehicle' => ['plate' => sa_g_plate($p('plate')), 'make' => $p('make'), 'model' => $p('model'), 'year' => $p('year'), 'vin' => strtoupper($p('vin'))],
        'damage' => $dmg, 'photos' => $photos, 'tyres' => $tyres, 'checks' => $chk, 'warn' => $warn, 'items' => $items, 'items_other' => $p('items_other'),
        'notes' => sanitize_textarea_field(wp_unslash((string) ($_POST['notes'] ?? ''))), 'sig_customer' => $sig_c, 'sig_mechanic' => $sig_m, 'mechanic_name' => wp_get_current_user()->display_name];
    $lat = is_numeric($_POST['lat'] ?? '') ? (float) $_POST['lat'] : null;
    $lng = is_numeric($_POST['lng'] ?? '') ? (float) $_POST['lng'] : null;
    $wpdb->insert(sa_g_t('dvi'), ['ref' => $ref, 'kind' => $kind, 'ro_id' => $ro, 'booking_id' => $bid, 'roadside_id' => $rid, 'vehicle_id' => $vid, 'customer_id' => $cid, 'mechanic_id' => get_current_user_id(),
        'mileage' => $km, 'fuel' => max(0, min(8, (int) $p('fuel'))), 'data' => wp_json_encode($data), 'lat' => $lat, 'lng' => $lng, 'token' => wp_generate_password(32, false, false), 'created' => sa_g_now()]);
    $id = (int) $wpdb->insert_id;
    if (!$id) {
        wp_die('Could not save the inspection. Please try again.', 'Workshop', ['response' => 500, 'back_link' => true]);
    }
    $wpdb->delete(sa_g_t('links'), ['src_type' => 'dvi_tmp', 'src_id' => 0]);
    $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . sa_g_t('links') . ' (vehicle_id,customer_id,src_type,src_id,created) VALUES (%d,%d,%s,%d,%s)', $vid, $cid, 'dvi', $id, sa_g_now()));
    if ($km && $vid) {
        $wpdb->update(sa_g_t('mileage'), ['source' => 'dvi', 'source_id' => $id], ['vehicle_id' => $vid, 'source' => 'dvi_tmp']);
    }
    if ($bid) {
        $l = (array) get_post_meta($bid, '_sa_bk_inspections', true);
        $l[] = $id;
        update_post_meta($bid, '_sa_bk_inspections', array_values(array_filter($l)));
        $wpdb->query($wpdb->prepare('UPDATE ' . sa_g_t('links') . ' SET vehicle_id=%d WHERE src_type=%s AND src_id=%d', $vid, 'booking', $bid));
    }
    if ($rid) {
        $l = (array) get_post_meta($rid, '_rs_inspections', true);
        $l[] = $id;
        update_post_meta($rid, '_rs_inspections', array_values(array_filter($l)));
        if (function_exists('sa_rs_log')) {
            sa_rs_log($rid, (string) get_post_meta($rid, '_rs_status', true), 'Vehicle inspection ' . $ref . ' recorded', wp_get_current_user()->display_name);
        }
    }
    if ($ro) {
        $wpdb->update(sa_g_t('ro'), array_filter(['vehicle_id' => $vid, 'customer_id' => $cid, 'mileage_in' => $km]) + ['updated' => sa_g_now()], ['id' => $ro]);
        sa_g_ro_log($ro, (string) sa_g_ro($ro)['status'], sa_g_dvi_kinds()[$kind] . ' ' . $ref . ' recorded');
    }
    $pdf = sa_g_dvi_pdf($id);
    if (!empty($_POST['send'])) {
        sa_g_dvi_email($id);
    }
    unset($pdf);
    wp_safe_redirect(add_query_arg('saved', 1, sa_g_dvi_report_url(sa_g_dvi($id))));
    exit;
}

/* ---------- PDF ---------- */

function sa_g_pdf_dir(): string
{
    $u = wp_upload_dir();
    $dir = trailingslashit($u['basedir']) . 'sa-garage-private';
    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
        @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        @file_put_contents($dir . '/index.html', '');
    }
    return $dir;
}

function sa_g_pdf_text(string $s): string
{
    $s = strtr($s, ['—' => '-', '–' => '-', '·' => '-', '’' => "'", '‘' => "'", '“' => '"', '”' => '"', '…' => '...', '×' => 'x', '✓' => 'v']);
    $c = @iconv('UTF-8', 'windows-1252//TRANSLIT', $s);
    return $c === false ? (string) preg_replace('/[^\x20-\x7E]/', '', $s) : $c;
}

/** Local file path for an attachment usable by FPDF (jpg/png only). */
function sa_g_pdf_img(int $aid): string
{
    $f = $aid ? (string) get_attached_file($aid) : '';
    return ($f !== '' && is_readable($f) && preg_match('/\.(jpe?g|png)$/i', $f)) ? $f : '';
}

function sa_g_dvi_pdf(int $id): string
{
    $d = sa_g_dvi($id);
    if (!$d) {
        return '';
    }
    if (!class_exists('FPDF')) {
        if (!defined('FPDF_FONTPATH')) {
            define('FPDF_FONTPATH', SA_CORE_DIR . 'lib/fpdf/font/');
        }
        require_once SA_CORE_DIR . 'lib/fpdf/fpdf.php';
    }
    $x = $d['d'];
    $T = 'sa_g_pdf_text';
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetTitle($T(sa_g_dvi_kinds()[$d['kind']] . ' ' . $d['ref']));
    $pdf->SetAuthor('Supreme Autoparts');
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->AddPage();
    $logo = defined('SA_THEME_DIR') ? SA_THEME_DIR . '/assets/logo-light.jpg' : get_theme_root() . '/supreme-autoparts/assets/logo-light.jpg';
    if (is_readable($logo)) {
        $pdf->Image($logo, 12, 10, 0, 13);
    }
    $pdf->SetFont('Helvetica', 'B', 15);
    $pdf->SetXY(100, 10);
    $pdf->Cell(98, 7, $T(sa_g_dvi_kinds()[$d['kind']]), 0, 2, 'R');
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(98, 5, $T($d['ref'] . '  -  ' . mysql2date('j M Y, g:i A', $d['created']) . ' EAT'), 0, 2, 'R');
    $pdf->Cell(98, 5, $T('Ground Floor, Midax Plaza, Kangundo Rd, Njiru, Nairobi'), 0, 2, 'R');
    if ($d['lat'] !== null) {
        $pdf->Cell(98, 5, $T('GPS: ' . round((float) $d['lat'], 5) . ', ' . round((float) $d['lng'], 5)), 0, 2, 'R');
    }
    $pdf->SetDrawColor(228, 228, 228);
    $pdf->Line(12, 33, 198, 33);
    $pdf->SetY(36);
    $sec = static function (string $t) use ($pdf, $T): void {
        $pdf->Ln(2);
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->SetTextColor(25, 25, 25);
        $pdf->Cell(0, 7, $T($t), 0, 1);
        $pdf->SetFont('Helvetica', '', 9);
    };
    $kv = static function (array $pairs) use ($pdf, $T): void {
        $pdf->SetFont('Helvetica', '', 9);
        foreach (array_chunk($pairs, 2, true) as $row) {
            foreach ($row as $k => $v) {
                $pdf->SetTextColor(107, 107, 107);
                $pdf->Cell(28, 5.5, $T($k), 0, 0);
                $pdf->SetTextColor(25, 25, 25);
                $pdf->Cell(65, 5.5, $T((string) $v), 0, 0);
            }
            $pdf->Ln();
        }
    };
    $sec('Customer & vehicle');
    $v = $x['vehicle'] ?? [];
    $kv(['Customer' => $x['customer']['name'] ?? '', 'Phone' => $x['customer']['phone'] ?? '', 'Vehicle' => trim(($v['year'] ?? '') . ' ' . ($v['make'] ?? '') . ' ' . ($v['model'] ?? '')), 'Reg plate' => $v['plate'] ?? '',
        'VIN' => $v['vin'] ?: '-', 'Mileage' => $d['mileage'] ? number_format((int) $d['mileage']) . ' km' : '-', 'Fuel' => (int) $d['fuel'] . '/8', 'Mechanic' => $x['mechanic_name'] ?? '']);
    // Damage diagram (scaled 200x400 -> 40x80 mm).
    $y0 = $pdf->GetY() + 3;
    $sec('Exterior damage');
    $y0 = $pdf->GetY();
    $ox = 14;
    $sx = 40 / 200;
    $pdf->SetDrawColor(25, 25, 25);
    $pdf->SetLineWidth(0.5);
    $pdf->Rect($ox + 30 * $sx, $y0 + 20 * $sx, 140 * $sx, 360 * $sx);
    $pdf->SetLineWidth(0.3);
    $pdf->Rect($ox + 48 * $sx, $y0 + 105 * $sx, 104 * $sx, 58 * $sx);
    $pdf->Rect($ox + 48 * $sx, $y0 + 270 * $sx, 104 * $sx, 44 * $sx);
    $pdf->SetFillColor(51, 51, 51);
    foreach ([[14, 70], [168, 70], [14, 282], [168, 282]] as $w) {
        $pdf->Rect($ox + $w[0] * $sx, $y0 + $w[1] * $sx, 18 * $sx, 50 * $sx, 'F');
    }
    $pdf->SetFont('Helvetica', '', 6);
    $pdf->SetXY($ox, $y0);
    $pdf->Cell(40, 3, 'FRONT', 0, 0, 'C');
    $pdf->SetXY($ox, $y0 + 78);
    $pdf->Cell(40, 3, 'REAR', 0, 0, 'C');
    $pdf->SetFillColor(193, 53, 21);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Helvetica', 'B', 6);
    foreach ($x['damage'] ?? [] as $n => $m) {
        $cx = $ox + (float) $m['x'] / 100 * 40;
        $cy = $y0 + (float) $m['y'] / 100 * 80;
        $pdf->Rect($cx - 2, $cy - 2, 4, 4, 'F');
        $pdf->SetXY($cx - 2, $cy - 2);
        $pdf->Cell(4, 4, (string) ($n + 1), 0, 0, 'C');
    }
    $pdf->SetTextColor(25, 25, 25);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetXY(62, $y0);
    if (empty($x['damage'])) {
        $pdf->MultiCell(136, 5, $T('No exterior damage marked.'));
    } else {
        foreach ($x['damage'] as $n => $m) {
            $pdf->SetX(62);
            $pdf->MultiCell(136, 5, $T(($n + 1) . '. ' . sa_g_damage_types()[$m['t']] . ($m['n'] !== '' ? ' - ' . $m['n'] : '')));
        }
    }
    $pdf->SetX(62);
    $warn = array_map(static fn($k) => sa_g_dvi_warning_lights()[$k] ?? $k, $x['warn'] ?? []);
    $pdf->Ln(2);
    $pdf->SetX(62);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(0, 5, 'Warning lights on dashboard', 0, 1);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetX(62);
    $pdf->MultiCell(136, 5, $T($warn ? implode(', ', $warn) : 'None'));
    $pdf->SetX(62);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(0, 5, 'Items left in the car', 0, 1);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetX(62);
    $il = array_map(static fn($k) => sa_g_dvi_items_left()[$k] ?? $k, $x['items'] ?? []);
    if (($x['items_other'] ?? '') !== '') {
        $il[] = $x['items_other'];
    }
    $pdf->MultiCell(136, 5, $T($il ? implode(', ', $il) : 'None recorded'));
    $pdf->SetY(max($pdf->GetY(), $y0 + 84));
    $ragc = ['g' => [22, 163, 74, 'Good'], 'y' => [245, 166, 35, 'Attention soon'], 'r' => [193, 53, 21, 'Needs attention now'], 'na' => [160, 160, 160, 'N/A']];
    $line = static function (string $label, string $r, string $note) use ($pdf, $T, $ragc): void {
        if ($r === '' && $note === '') {
            return;
        }
        if ($pdf->GetY() > 275) {
            $pdf->AddPage();
        }
        $c = $ragc[$r] ?? [200, 200, 200, '-'];
        $pdf->SetFillColor($c[0], $c[1], $c[2]);
        $y = $pdf->GetY();
        $pdf->Rect(14, $y + 1.2, 3, 3, 'F');
        $pdf->SetX(19);
        $pdf->Cell(55, 5.5, $T($label), 0, 0);
        $pdf->SetTextColor(107, 107, 107);
        $pdf->Cell(35, 5.5, $T($c[3]), 0, 0);
        $pdf->SetTextColor(25, 25, 25);
        $pdf->MultiCell(0, 5.5, $T($note));
    };
    $sec('Tyres');
    foreach (sa_g_dvi_tyres() as $k => $l) {
        $t = $x['tyres'][$k] ?? [];
        $line($l, (string) ($t['r'] ?? ''), isset($t['mm']) && $t['mm'] !== null ? 'Tread ' . $t['mm'] . ' mm' : '');
    }
    foreach (sa_g_dvi_checks() as $g => $items) {
        $sec($g);
        $any = false;
        foreach ($items as $k => $l) {
            $c = $x['checks'][$k] ?? [];
            if (($c['r'] ?? '') !== '' || ($c['n'] ?? '') !== '') {
                $any = true;
            }
            $line($l, (string) ($c['r'] ?? ''), (string) ($c['n'] ?? ''));
        }
        if (!$any) {
            $pdf->SetTextColor(107, 107, 107);
            $pdf->Cell(0, 5, 'Not checked on this card.', 0, 1);
            $pdf->SetTextColor(25, 25, 25);
        }
    }
    if (($x['notes'] ?? '') !== '') {
        $sec('Notes');
        $pdf->MultiCell(0, 5, $T($x['notes']));
    }
    $ph = array_values(array_filter(array_map('sa_g_pdf_img', (array) ($x['photos'] ?? []))));
    if ($ph) {
        if ($pdf->GetY() > 215) {
            $pdf->AddPage();
        }
        $sec('Photos');
        $col = 0;
        $y = $pdf->GetY();
        foreach ($ph as $f) {
            if ($y > 230) {
                $pdf->AddPage();
                $y = 15;
            }
            try {
                $pdf->Image($f, 14 + $col * 62, $y, 58, 0);
            } catch (\Throwable $e) {
                continue;
            }
            $col++;
            if ($col === 3) {
                $col = 0;
                $y += 46;
            }
        }
        $pdf->SetY($y + ($col ? 46 : 0));
    }
    if ($pdf->GetY() > 240) {
        $pdf->AddPage();
    }
    $sec('Signatures');
    $y = $pdf->GetY();
    foreach ([['sig_customer', 'Customer: ' . ($x['customer']['name'] ?? ''), 14], ['sig_mechanic', 'Mechanic: ' . ($x['mechanic_name'] ?? ''), 108]] as [$k, $lab, $sx2]) {
        $f = sa_g_pdf_img((int) ($x[$k] ?? 0));
        if ($f) {
            try {
                $pdf->Image($f, $sx2, $y, 70, 0);
            } catch (\Throwable $e) {
            }
        }
        $pdf->Line($sx2, $y + 25, $sx2 + 80, $y + 25);
        $pdf->SetXY($sx2, $y + 26);
        $pdf->Cell(80, 5, $T($lab . ($f ? '' : ' (not signed)')), 0, 0);
    }
    $pdf->SetY($y + 34);
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(107, 107, 107);
    $pdf->MultiCell(0, 4, $T('This card records the visible condition of the vehicle at the time shown. Supreme Autoparts (Standard Driveworks), Ground Floor, Midax Plaza, Kangundo Rd, Njiru, Nairobi. P.O. Box 00516-00100 G.P.O Nairobi.'));
    $file = sa_g_pdf_dir() . '/' . sanitize_file_name($d['ref'] . '-' . substr($d['token'], 0, 10)) . '.pdf';
    $pdf->Output('F', $file);
    global $wpdb;
    $wpdb->update(sa_g_t('dvi'), ['pdf' => basename($file)], ['id' => $id]);
    return $file;
}

function sa_g_dvi_email(int $id): bool
{
    global $wpdb;
    $d = sa_g_dvi($id);
    $email = (string) ($d['d']['customer']['email'] ?? '');
    if (!$d || !is_email($email)) {
        return false;
    }
    $file = $d['pdf'] ? sa_g_pdf_dir() . '/' . $d['pdf'] : sa_g_dvi_pdf($id);
    $reds = $yel = 0;
    foreach (array_merge(array_values($d['d']['checks'] ?? []), array_values($d['d']['tyres'] ?? [])) as $c) {
        $reds += ($c['r'] ?? '') === 'r' ? 1 : 0;
        $yel += ($c['r'] ?? '') === 'y' ? 1 : 0;
    }
    $v = $d['d']['vehicle'];
    $summary = $d['kind'] === 'inspection' ? '<p>Summary: <strong style="color:#c13515">' . $reds . ' need attention now</strong>, <strong style="color:#b7770f">' . $yel . ' soon</strong>.</p>' : '<p>' . count($d['d']['damage'] ?? []) . ' exterior mark(s) recorded, fuel ' . (int) $d['fuel'] . '/8, mileage ' . number_format((int) $d['mileage']) . ' km.</p>';
    $subject = sa_g_dvi_kinds()[$d['kind']] . ' for ' . trim($v['make'] . ' ' . $v['model'] . ' ' . $v['plate']) . ' (' . $d['ref'] . ')';
    $html = sa_mail_layout(sa_g_dvi_kinds()[$d['kind']], '<p>' . sa_email_greeting((string) $d['d']['customer']['name']) . '</p><p>We recorded the condition of your ' . esc_html(trim($v['year'] . ' ' . $v['make'] . ' ' . $v['model'])) . ' (' . esc_html($v['plate']) . ') on ' . esc_html(mysql2date('j M Y \a\t g:i A', $d['created'])) . ' EAT. The full report with photos is attached as a PDF.</p>' . $summary, 'View report online', sa_g_dvi_report_url($d), $subject);
    $ok = (bool) wp_mail($email, $subject, $html, ['Content-Type: text/html; charset=UTF-8'], is_readable($file) ? [$file] : []);
    if ($ok) {
        $wpdb->update(sa_g_t('dvi'), ['emailed_at' => sa_g_now()], ['id' => $id]);
    }
    return $ok;
}

/* ---------- Customer report page ---------- */

add_action('template_redirect', static function (): void {
    if (!get_query_var('sa_g_report')) {
        return;
    }
    $d = sa_g_dvi((int) ($_GET['i'] ?? 0));
    $ok = $d && (hash_equals((string) $d['token'], (string) wp_unslash($_GET['k'] ?? '')) || sa_g_can());
    if ($ok && !empty($_GET['pdf'])) {
        $file = $d['pdf'] ? sa_g_pdf_dir() . '/' . $d['pdf'] : '';
        if (!is_readable($file)) {
            $file = sa_g_dvi_pdf((int) $d['id']);
        }
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $d['ref'] . '.pdf"');
        readfile($file);
        exit;
    }
    sa_g_public_head('Inspection report');
    echo '<main class="sa-g-public sa-container">';
    if (!$ok) {
        echo '<h1>Link not valid</h1><p>This report link is incorrect. Please use the link in your email.</p></main>';
        get_footer();
        exit;
    }
    $x = $d['d'];
    $v = $x['vehicle'];
    if (!empty($_GET['saved'])) {
        echo '<div class="woocommerce-message" role="status">Inspection saved' . ($d['emailed_at'] ? ' and emailed to the customer' : '') . '.</div><p><a href="' . esc_url(home_url('/workshop/')) . '">← Back to workshop</a></p>';
    }
    echo '<p class="sa-g-eyebrow">' . esc_html($d['ref']) . ' · ' . esc_html(mysql2date('j M Y, g:i A', $d['created'])) . ' EAT</p><h1>' . esc_html(sa_g_dvi_kinds()[$d['kind']]) . '</h1>';
    echo '<p class="sa-g-muted">' . esc_html(trim($v['year'] . ' ' . $v['make'] . ' ' . $v['model']) . ' · ' . $v['plate'] . ' · ' . number_format((int) $d['mileage']) . ' km · Fuel ' . (int) $d['fuel'] . '/8') . '</p>';
    echo '<p><a class="sa-btn" href="' . esc_url(sa_g_dvi_report_url($d, true)) . '" target="_blank" rel="noopener">Download PDF</a></p>';
    echo '<div class="sa-g-report"><div class="sa-g-card"><h2>Exterior</h2><div class="sa-g-carwrap sa-g-carwrap--ro" data-car-static=\'' . esc_attr(wp_json_encode($x['damage'] ?? [])) . '\'>' . sa_g_car_svg() . '</div><ol class="sa-g-dmglist">';
    foreach ($x['damage'] ?? [] as $m) {
        echo '<li>' . esc_html(sa_g_damage_types()[$m['t']] . ($m['n'] ? ' — ' . $m['n'] : '')) . '</li>';
    }
    echo '</ol></div><div class="sa-g-card"><h2>Checks</h2><ul class="sa-g-list">';
    $lab = ['g' => 'Good', 'y' => 'Attention soon', 'r' => 'Needs attention now', 'na' => 'N/A'];
    foreach (sa_g_dvi_tyres() as $k => $l) {
        $t = $x['tyres'][$k] ?? [];
        if (($t['r'] ?? '') !== '') {
            echo '<li><span><i class="sa-g-dot sa-g-dot--' . esc_attr($t['r']) . '"></i>Tyre ' . esc_html(strtolower($l)) . ($t['mm'] !== null ? ' · ' . esc_html((string) $t['mm']) . ' mm' : '') . '</span><em>' . esc_html($lab[$t['r']]) . '</em></li>';
        }
    }
    foreach (sa_g_dvi_checks() as $items) {
        foreach ($items as $k => $l) {
            $c = $x['checks'][$k] ?? [];
            if (($c['r'] ?? '') !== '') {
                echo '<li><span><i class="sa-g-dot sa-g-dot--' . esc_attr($c['r']) . '"></i>' . esc_html($l) . ($c['n'] ? ' — ' . esc_html($c['n']) : '') . '</span><em>' . esc_html($lab[$c['r']]) . '</em></li>';
            }
        }
    }
    echo '</ul>';
    $warn = array_map(static fn($k) => sa_g_dvi_warning_lights()[$k] ?? $k, $x['warn'] ?? []);
    echo '<p><strong>Warning lights:</strong> ' . esc_html($warn ? implode(', ', $warn) : 'None') . '</p>';
    $il = array_map(static fn($k) => sa_g_dvi_items_left()[$k] ?? $k, $x['items'] ?? []);
    if ($x['items_other'] ?? '') {
        $il[] = $x['items_other'];
    }
    echo '<p><strong>Items in the car:</strong> ' . esc_html($il ? implode(', ', $il) : 'None recorded') . '</p>' . (($x['notes'] ?? '') !== '' ? '<p><strong>Notes:</strong> ' . esc_html($x['notes']) . '</p>' : '') . '</div></div>';
    if (!empty($x['photos'])) {
        echo '<div class="sa-g-thumbs sa-g-thumbs--lg">';
        foreach ($x['photos'] as $aid) {
            $u = wp_get_attachment_image_url((int) $aid, 'large');
            if ($u) {
                echo '<a href="' . esc_url($u) . '" target="_blank" rel="noopener"><img src="' . esc_url($u) . '" alt="Inspection photo" loading="lazy"></a>';
            }
        }
        echo '</div>';
    }
    echo '</main>';
    get_footer();
    exit;
});
