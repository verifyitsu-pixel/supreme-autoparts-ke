<?php
/**
 * Car services + appointment booking (services landing, detail pages, booking flow,
 * My Account bookings + vehicles, Super Admin booking manager, email confirmations).
 * Pay-at-service; no online deposit. Prices: data/service-catalog.php (real published only).
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const SA_SVC_VER = '3';
const SA_SVC_SLOT_CAPACITY = 2;
// Registered business address (Business Registration Certificate BN-93S9AZEJ, Standard Driveworks).
const SA_SVC_GARAGE_ADDRESS = 'Ground Floor, Midax Plaza, Kangundo Rd, Njiru, Nairobi, Kenya';
const SA_SVC_GARAGE_POSTAL = 'P.O. Box 00516-00100 G.P.O Nairobi';

function sa_svc_map_url(): string
{
    if (function_exists('sa_geo_is_kenya') && !sa_geo_is_kenya()) {
        return sa_geo_map_url();
    }
    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode('Midax Plaza, Kangundo Rd, Njiru, Nairobi');
}

function sa_svc_address_html(bool $compact = false): string
{
    if (function_exists('sa_geo_is_kenya') && !sa_geo_is_kenya()) {
        $b = sa_geo_business();
        return '<div class="sa-svc-addr' . ($compact ? ' sa-svc-addr--compact' : '') . '">' . sa_svc_icon('pin')
            . '<div><strong>Supreme Autoparts</strong><span>' . esc_html($b['street']) . '</span><span>' . esc_html($b['city'] . ', ' . $b['region'] . ' ' . $b['postal']) . '</span>'
            . '<span><a href="tel:' . esc_attr($b['phone']) . '">' . esc_html($b['phone_display']) . '</a> · <a href="mailto:' . esc_attr($b['email']) . '">' . esc_html($b['email']) . '</a></span>'
            . '<a href="' . esc_url(sa_geo_map_url()) . '" target="_blank" rel="noopener">Open in Google Maps →</a></div></div>';
    }
    return '<div class="sa-svc-addr' . ($compact ? ' sa-svc-addr--compact' : '') . '">' . sa_svc_icon('pin')
        . '<div><strong>Supreme Autoparts garage</strong><span>' . esc_html(SA_SVC_GARAGE_ADDRESS) . '</span>'
        . ($compact ? '' : '<span class="sa-svc-muted">' . esc_html(SA_SVC_GARAGE_POSTAL) . '</span>')
        . '<a href="' . esc_url(sa_svc_map_url()) . '" target="_blank" rel="noopener">Open in Google Maps →</a></div></div>';
}

function sa_svc_catalog(): array
{
    static $c = null;
    if ($c === null) {
        $c = require SA_CORE_DIR . 'data/service-catalog.php';
    }
    return $c;
}

function sa_svc_statuses(): array
{
    return [
        'pending'     => 'Pending',
        'confirmed'   => 'Confirmed',
        'in_progress' => 'In progress',
        'done'        => 'Done',
        'cancel_requested' => 'Cancellation requested',
        'cancelled'   => 'Cancelled',
    ];
}

function sa_svc_locations(): array
{
    return [
        'nairobi' => ['label' => 'Nairobi garage — Midax Plaza, Kangundo Rd, Njiru', 'region' => 'KE', 'note' => SA_SVC_GARAGE_ADDRESS],
        'usa' => ['label' => 'United States', 'region' => 'US', 'note' => ''],
    ];
}

function sa_svc_region(): string
{
    $override = isset($_GET['region']) ? strtoupper(sanitize_key((string) $_GET['region'])) : '';
    if (in_array($override, ['KE', 'US'], true)) {
        return $override;
    }
    $cc = class_exists('SA_Geo_Detector') ? (string) SA_Geo_Detector::country_code() : '';
    return strtoupper($cc) === 'KE' ? 'KE' : 'US';
}

/** Human price label for a service in a region. */
function sa_svc_price_label(array $svc, string $region): string
{
    if ($region === 'KE') {
        $k = $svc['kes'] ?? null;
        if (!$k) {
            return 'Quote on request';
        }
        $prices = array_column($k['options'], 'price');
        return 'From KES ' . number_format((float) min($prices));
    }
    $u = $svc['usd'] ?? null;
    if (!$u) {
        return 'Quote on request';
    }
    return 'US$' . number_format((float) $u['min']) . '–' . number_format((float) $u['max']);
}

function sa_svc_source_note(array $svc, string $region): string
{
    if ($region === 'KE' && !empty($svc['kes'])) {
        return 'Guide price. The final price for your vehicle is confirmed before work starts.';
    }
    if ($region === 'US' && !empty($svc['usd'])) {
        return 'Typical price range. The exact price is confirmed before work starts.';
    }
    return 'We confirm a written quote for your vehicle before any work starts.';
}

function sa_svc_url(string $path = ''): string
{
    return home_url('/services/' . ($path !== '' ? trailingslashit($path) : ''));
}

function sa_svc_book_url(string $slug = ''): string
{
    return home_url('/book-service/' . ($slug !== '' ? '?service=' . rawurlencode($slug) : ''));
}

/* ---------- Data model ---------- */

add_action('init', static function (): void {
    register_post_type('sa_booking', [
        'label'        => 'Service bookings',
        'public'       => false,
        'show_ui'      => false,
        'supports'     => ['title'],
        'capability_type' => 'post',
    ]);

    add_rewrite_rule('^services/?$', 'index.php?sa_svc_view=index', 'top');
    add_rewrite_rule('^services/([a-z0-9-]+)/?$', 'index.php?sa_svc_view=detail&sa_svc=$matches[1]', 'top');
    add_rewrite_rule('^book-service/?$', 'index.php?sa_svc_view=book', 'top');
    add_rewrite_rule('^book-service/confirmed/?$', 'index.php?sa_svc_view=confirmed', 'top');
    add_rewrite_rule('^track-order/?$', 'index.php?sa_svc_view=track', 'top');
    add_rewrite_rule('^book-service/pay/?$', 'index.php?sa_svc_view=pay', 'top');
    add_rewrite_rule('^manage-booking/?$', 'index.php?sa_svc_view=manage', 'top');
    add_rewrite_rule('^booking-terms/?$', 'index.php?sa_svc_view=terms', 'top');

    if (get_option('sa_svc_rewrite_ver') !== SA_SVC_VER) {
        flush_rewrite_rules(false);
        update_option('sa_svc_rewrite_ver', SA_SVC_VER);
    }
}, 12);

add_filter('query_vars', static function (array $v): array {
    $v[] = 'sa_svc_view';
    $v[] = 'sa_svc';
    return $v;
});

function sa_svc_booking_data(int $id): array
{
    $m = static fn(string $k) => (string) get_post_meta($id, '_sa_bk_' . $k, true);
    return [
        'id' => $id, 'ref' => $m('ref'), 'service' => $m('service'), 'option' => $m('option'),
        'date' => $m('date'), 'time' => $m('time'), 'location' => $m('location'), 'region' => $m('region'),
        'status' => $m('status') ?: 'pending', 'name' => $m('name'), 'email' => $m('email'), 'phone' => $m('phone'),
        'make' => $m('make'), 'model' => $m('model'), 'year' => $m('year'), 'reg' => $m('reg'),
        'notes' => $m('notes'), 'estimate' => $m('estimate'), 'cancel_note' => $m('cancel_note'), 'fee' => $m('fee') ?: 'unpaid', 'fee_kes' => $m('fee_kes'), 'fee_order' => $m('fee_order'), 'mode' => $m('mode') ?: 'dropoff', 'pickup' => $m('pickup'), 'gcal' => $m('gcal'), 'user' => (int) $m('user'), 'key' => $m('key'),
        'created' => get_post_time('U', true, $id),
    ];
}

function sa_svc_slot_times(): array
{
    return ['08:00', '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00'];
}

/** Number of 1-hour slots a service occupies (sized from its estimated duration). */
function sa_svc_blocks(string $service): int
{
    $d = (int) (sa_svc_catalog()[$service]['duration'] ?? 60);
    return max(1, (int) ceil($d / 60));
}

function sa_svc_duration_label(string $service): string
{
    $s = sa_svc_catalog()[$service] ?? null;
    if (!$s) {
        return '';
    }
    $m = (int) $s['duration'];
    $txt = $m >= 60 ? (floor($m / 60) . ' h' . ($m % 60 ? ' ' . ($m % 60) . ' min' : '')) : $m . ' min';
    return '≈ ' . $txt . (empty($s['duration_src']) ? ' (estimate)' : '');
}

function sa_svc_slots_taken(string $date, string $location): array
{
    $q = get_posts([
        'post_type' => 'sa_booking', 'post_status' => 'publish', 'numberposts' => 200, 'fields' => 'ids',
        'meta_query' => [
            ['key' => '_sa_bk_date', 'value' => $date],
            ['key' => '_sa_bk_location', 'value' => $location],
            ['key' => '_sa_bk_status', 'value' => 'cancelled', 'compare' => '!='],
        ],
    ]);
    $count = [];
    foreach ($q as $id) {
        $t = (int) substr((string) get_post_meta($id, '_sa_bk_time', true), 0, 2);
        $mins = (int) (sa_svc_catalog()[(string) get_post_meta($id, '_sa_bk_service', true)]['duration'] ?? 60);
        foreach (array_filter(explode(',', (string) get_post_meta($id, '_sa_bk_addons', true))) as $ad) {
            $mins += (int) (sa_svc_catalog()[$ad]['duration'] ?? 0);
        }
        $n = max(1, (int) ceil($mins / 60));
        for ($h = $t; $h < $t + $n; $h++) {
            $k = sprintf('%02d:00', $h);
            $count[$k] = ($count[$k] ?? 0) + 1;
        }
    }
    return $count;
}

/** Available slots: Mon–Sat, future only (store timezone), capacity per slot/location. */
function sa_svc_available_slots(string $date, string $location, string $service = '', int $extra = 0): array
{
    $tz = wp_timezone();
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz);
    if (!$d || $d->format('Y-m-d') !== $date || (int) $d->format('w') === 0) {
        return [];
    }
    $now = new DateTimeImmutable('now', $tz);
    if ($d > $now->modify('+60 days')) {
        return [];
    }
    $taken = sa_svc_slots_taken($date, $location);
    $out = [];
    $close = (int) $d->format('w') === 6 ? 13 : 17; // Sat half-day.
    $blocks = $service !== '' ? max(1, (int) ceil(((int) (sa_svc_catalog()[$service]['duration'] ?? 60) + $extra) / 60)) : 1;
    foreach (sa_svc_slot_times() as $t) {
        $h = (int) substr($t, 0, 2);
        if ($h + $blocks > $close) {
            continue; // Job must finish before closing.
        }
        for ($x = $h; $x < $h + $blocks; $x++) {
            if (($taken[sprintf('%02d:00', $x)] ?? 0) >= SA_SVC_SLOT_CAPACITY) {
                continue 2;
            }
        }
        $slot = DateTimeImmutable::createFromFormat('Y-m-d H:i', $date . ' ' . $t, $tz);
        if ($slot <= $now->modify('+2 hours')) {
            continue;
        }
        $out[] = $t;
    }
    return $out;
}

add_action('wp_ajax_sa_svc_slots', 'sa_svc_ajax_slots');
add_action('wp_ajax_nopriv_sa_svc_slots', 'sa_svc_ajax_slots');
function sa_svc_ajax_slots(): void
{
    $date = sanitize_text_field((string) ($_GET['date'] ?? ''));
    $loc  = sanitize_key((string) ($_GET['location'] ?? ''));
    if (!isset(sa_svc_locations()[$loc])) {
        wp_send_json_error(['message' => 'Unknown location'], 400);
    }
    $svc = sanitize_key((string) ($_GET['service'] ?? ''));
    $svc = isset(sa_svc_catalog()[$svc]) ? $svc : '';
    $extra = max(0, min(600, (int) ($_GET['extra'] ?? 0)));
    wp_send_json_success(['slots' => sa_svc_available_slots($date, $loc, $svc, $extra), 'duration' => $svc ? sa_svc_duration_label($svc) : '']);
}

/* ---------- Booking submit ---------- */

add_action('admin_post_sa_book_service', 'sa_svc_handle_booking');
add_action('admin_post_nopriv_sa_book_service', 'sa_svc_handle_booking');
function sa_svc_handle_booking(): void
{
    $back = wp_get_referer() ?: sa_svc_book_url();
    $fail = static function (string $msg) use ($back): void {
        wp_safe_redirect(add_query_arg('sa_err', rawurlencode($msg), $back));
        exit;
    };
    if (!isset($_POST['sa_svc_nonce']) || !wp_verify_nonce((string) $_POST['sa_svc_nonce'], 'sa_book_service')) {
        $fail('Your session expired. Please try again.');
    }
    if (!empty($_POST['sa_hp'])) {
        $fail('Unable to submit.');
    }
    $p = static fn(string $k): string => sanitize_text_field(wp_unslash((string) ($_POST[$k] ?? '')));
    $catalog = sa_svc_catalog();
    $service = sanitize_key($p('service'));
    $loc = sanitize_key($p('location'));
    $date = $p('date');
    $time = $p('time');
    $email = sanitize_email($p('email'));
    if (!isset($catalog[$service])) $fail('Please choose a service.');
    if (!isset(sa_svc_locations()[$loc])) $fail('Please choose a location.');
    $addmin = 0;
    foreach ((array) ($_POST['addons'] ?? []) as $ad) { $ad = sanitize_key((string) $ad); if (isset($catalog[$ad]) && $ad !== $service) $addmin += (int) $catalog[$ad]['duration']; }
    if (!in_array($time, sa_svc_available_slots($date, $loc, $service, $addmin), true)) $fail('That time slot is no longer available. Please pick another.');
    if (!is_email($email)) $fail('Please enter a valid email address.');
    if (empty($_POST['agree_terms'])) $fail('Please accept the Booking Terms to continue.');
    $sa_fw = function_exists('sa_fw_validate_request') ? sa_fw_validate_request($date, $fail) : null;
    if ($sa_fw === null && sa_svc_fee_usd() === null) $fail('Online booking is temporarily unavailable (currency rate missing). Please contact us on WhatsApp.');
    if ($p('name') === '' || $p('phone') === '') $fail('Please enter your name and phone number.');
    if ($p('make') === '' || $p('model') === '') $fail('Please enter your vehicle make and model.');
    $mode = $p('mode') === 'pickup' ? 'pickup' : 'dropoff';
    if ($mode === 'pickup' && $loc === 'usa') $mode = 'dropoff';
    $pickup = '';
    if ($mode === 'pickup') {
        foreach (['pk_street' => 'street / road', 'pk_building' => 'building / estate', 'pk_area' => 'area', 'pk_city' => 'city / town', 'pk_phone' => 'pickup contact phone', 'pk_time' => 'preferred pickup time'] as $k => $lab) {
            if (trim($p($k)) === '') $fail('Please enter the pickup ' . $lab . '.');
        }
        if (!preg_match('/^[+0-9 ]{9,16}$/', $p('pk_phone'))) $fail('Please enter a valid pickup contact phone.');
        $pin = preg_match('/^-?\d{1,3}\.\d+,-?\d{1,3}\.\d+$/', $p('pk_pin')) ? $p('pk_pin') : '';
        $pickup = implode(', ', array_filter([$p('pk_house'), $p('pk_building'), $p('pk_street'), $p('pk_area'), $p('pk_city')]))
            . ($p('pk_landmark') !== '' ? ' | Landmark: ' . sanitize_text_field(wp_unslash((string) $_POST['pk_landmark'])) : '')
            . ' | Pickup phone: ' . $p('pk_phone') . ' | Pickup time: ' . $p('pk_time')
            . ($pin !== '' ? ' | Pin: https://www.google.com/maps?q=' . $pin : '');
    }

    $svc = $catalog[$service];
    $region = sa_svc_locations()[$loc]['region'];
    $option = $p('option');
    $estimate = sa_svc_price_label($svc, $region);
    if ($region === 'KE' && !empty($svc['kes']['options'])) {
        foreach ($svc['kes']['options'] as $o) {
            if ($o['label'] === $option) {
                $estimate = 'KES ' . number_format((float) $o['price']);
            }
        }
    }

    $addons = array_values(array_filter(array_map('sanitize_key', (array) ($_POST['addons'] ?? [])), static fn($x) => isset($catalog[$x]) && $x !== $service));
    $part = (int) ($_POST['part'] ?? 0);
    $partname = $part && get_post_type($part) === 'product' ? get_the_title($part) : '';
    $ref = sa_svc_new_ref();
    $id = wp_insert_post([
        'post_type' => 'sa_booking', 'post_status' => 'publish',
        'post_title' => $ref . ' ' . $svc['name'] . ' — ' . $p('name'),
    ], true);
    if (is_wp_error($id)) $fail('Could not save your booking. Please try again.');

    $user = get_current_user_id();
    $year = (string) max(0, min((int) gmdate('Y') + 1, (int) $p('year')));
    $meta = [
        'ref' => $ref, 'service' => $service, 'option' => $option, 'date' => $date, 'time' => $time,
        'location' => $loc, 'region' => $region, 'status' => 'pending', 'name' => $p('name'), 'email' => $email,
        'phone' => $p('phone'), 'make' => $p('make'), 'model' => $p('model'), 'year' => $year === '0' ? '' : $year,
        'reg' => strtoupper($p('reg')), 'notes' => sanitize_textarea_field(wp_unslash((string) ($_POST['notes'] ?? ''))),
        'estimate' => $estimate . ($addons ? ' + add-ons: ' . implode(', ', array_map(static fn($x) => $catalog[$x]['name'] . ' (' . sa_svc_price_label($catalog[$x], $region) . ')', $addons)) : ''),
        'addons' => implode(',', $addons), 'part' => $partname, 'mode' => $mode, 'pickup' => $mode === 'pickup' ? $pickup : '', 'fee' => 'unpaid', 'fee_kes' => (string) sa_svc_setting('fee_kes'), 'user' => (string) $user, 'key' => wp_generate_password(20, false, false),
    ];
    foreach ($meta as $k => $v) {
        update_post_meta((int) $id, '_sa_bk_' . $k, $v);
    }
    if ($user && !empty($_POST['save_vehicle'])) {
        sa_svc_save_vehicle($user, ['make' => $meta['make'], 'model' => $meta['model'], 'year' => $meta['year'], 'reg' => $meta['reg']]);
    }
    $rs = (int) ($_POST['reschedule'] ?? 0);
    if ($rs && get_post_type($rs) === 'sa_booking') {
        $ob = sa_svc_booking_data($rs);
        if (hash_equals($ob['key'], (string) ($_POST['rk'] ?? '')) && in_array($ob['status'], ['pending', 'confirmed'], true)) {
            update_post_meta($rs, '_sa_bk_status', 'cancelled');
            update_post_meta((int) $id, '_sa_bk_notes', trim('Rescheduled from ' . $ob['ref'] . '. ' . $meta['notes']));
            sa_svc_gcal_sync($rs);
        }
    }
    if ($partname !== '') {
        update_post_meta((int) $id, '_sa_bk_notes', trim('Install part: ' . $partname . '. ' . (string) get_post_meta((int) $id, '_sa_bk_notes', true)));
    }
    if ($sa_fw) {
        sa_fw_apply((int) $id, $sa_fw);
    }
    sa_svc_send_emails((int) $id, 'created');
    if (function_exists('sa_bk_invoice_email')) {
        sa_bk_invoice_email((int) $id);
    }
    sa_svc_gcal_sync((int) $id);
    if ($sa_fw) {
        wp_safe_redirect(add_query_arg(['b' => (int) $id, 'k' => $meta['key']], home_url('/book-service/confirmed/')));
        exit;
    }
    wp_safe_redirect(add_query_arg(['b' => (int) $id, 'k' => $meta['key']], home_url('/book-service/pay/')));
    exit;
}

/* ---------- Vehicles ---------- */

function sa_svc_vehicles(int $user): array
{
    $v = get_user_meta($user, 'sa_vehicles', true);
    return is_array($v) ? $v : [];
}

function sa_svc_save_vehicle(int $user, array $veh): void
{
    $list = sa_svc_vehicles($user);
    $key = strtolower(trim($veh['make'] . '|' . $veh['model'] . '|' . $veh['year'] . '|' . $veh['reg']));
    foreach ($list as $v) {
        if (strtolower(trim($v['make'] . '|' . $v['model'] . '|' . $v['year'] . '|' . $v['reg'])) === $key) {
            return;
        }
    }
    $list[] = $veh;
    update_user_meta($user, 'sa_vehicles', array_slice($list, -10));
}

add_action('admin_post_sa_svc_vehicle', static function (): void {
    $user = get_current_user_id();
    if (!$user || !wp_verify_nonce((string) ($_POST['_wpnonce'] ?? ''), 'sa_svc_vehicle')) {
        wp_die('Not allowed', 403);
    }
    $p = static fn(string $k): string => sanitize_text_field(wp_unslash((string) ($_POST[$k] ?? '')));
    if (isset($_POST['remove'])) {
        $list = sa_svc_vehicles($user);
        unset($list[(int) $_POST['remove']]);
        update_user_meta($user, 'sa_vehicles', array_values($list));
    } elseif ($p('make') !== '' && $p('model') !== '') {
        sa_svc_save_vehicle($user, ['make' => $p('make'), 'model' => $p('model'), 'year' => $p('year'), 'reg' => strtoupper($p('reg'))]);
    }
    wp_safe_redirect(wc_get_account_endpoint_url('bookings'));
    exit;
});

/* ---------- Customer cancel ---------- */

add_action('admin_post_sa_svc_cancel', 'sa_svc_customer_cancel');
add_action('admin_post_nopriv_sa_svc_cancel', 'sa_svc_customer_cancel');
function sa_svc_customer_cancel(): void
{
    $id = (int) ($_POST['b'] ?? 0);
    if (!wp_verify_nonce((string) ($_POST['_wpnonce'] ?? ''), 'sa_svc_cancel_' . $id)) {
        wp_die('Link expired', 403);
    }
    $b = sa_svc_booking_data($id);
    $owner = ($b['user'] && $b['user'] === get_current_user_id()) || hash_equals($b['key'], (string) ($_POST['k'] ?? ''));
    if (!$owner || !in_array($b['status'], ['pending', 'confirmed'], true)) {
        wp_die('This booking cannot be cancelled online. Please contact us.', 403);
    }
    if ($b['fee'] === 'paid') {
        // Paid bookings: cancellation REQUEST, reviewed by admin.
        $g = static fn(string $k): string => sanitize_text_field(wp_unslash((string) ($_POST[$k] ?? '')));
        $reasons = sa_svc_cancel_reasons();
        $method = $g('refund_method');
        if (!isset($reasons[$g('reason')]) || !in_array($method, ['mpesa', 'card', 'bank'], true) || $g('refund_details') === '' || $g('contact') === '') {
            wp_safe_redirect(add_query_arg(['b' => $id, 'k' => $b['key'], 'cr_err' => 1], home_url('/book-service/confirmed/')) . '#cancel');
            exit;
        }
        $req = ['reason' => $reasons[$g('reason')], 'details' => sanitize_textarea_field(wp_unslash((string) ($_POST['details'] ?? ''))), 'method' => $method,
            'refund_details' => $g('refund_details'), 'contact' => $g('contact'), 'prev' => $b['status'], 'within' => sa_svc_within_window($b) ? 'yes' : 'no', 't' => time()];
        update_post_meta($id, '_sa_bk_cancel_req', $req);
        update_post_meta($id, '_sa_bk_status', 'cancel_requested');
        sa_svc_send_emails($id, 'cancel_request');
        wp_safe_redirect(add_query_arg(['b' => $id, 'k' => $b['key'], 'cr' => 1], home_url('/book-service/confirmed/')));
        exit;
    }
    update_post_meta($id, '_sa_bk_status', 'cancelled');
    sa_svc_send_emails($id, 'cancelled');
    sa_svc_gcal_sync($id);
    $to = wp_get_referer() ?: home_url('/');
    wp_safe_redirect(add_query_arg('sa_cancelled', '1', $to));
    exit;
}

/* ---------- Emails (wp_mail → Brevo SMTP) ---------- */

function sa_svc_send_emails(int $id, string $event): void
{
    $b = sa_svc_booking_data($id);
    $svc = sa_svc_catalog()[$b['service']] ?? ['name' => $b['service']];
    $loc = sa_svc_locations()[$b['location']]['label'] ?? $b['location'];
    $status = sa_svc_statuses()[$b['status']] ?? $b['status'];
    $when = date_i18n('l j F Y', strtotime($b['date'])) . ' at ' . $b['time'];
    $subjects = [
        'created' => 'Booking received: ' . $svc['name'] . ' (' . $b['ref'] . ')',
        'cancelled' => 'Booking cancelled: ' . $b['ref'],
        'status' => 'Booking update: ' . $b['ref'] . ' is ' . $status,
        'cancel_request' => 'Cancellation request received: ' . $b['ref'],
        'cancel_approved' => 'Cancellation approved — booking fee refund: ' . $b['ref'],
        'cancel_declined' => 'Cancellation request declined: ' . $b['ref'],
        'reminder' => 'Reminder: your ' . $svc['name'] . ' is tomorrow at ' . $b['time'] . ' (' . $b['ref'] . ')',
    ];
    $rows = [
        'Booking number' => $b['ref'], 'Service' => $svc['name'] . ($b['option'] ? ' — ' . $b['option'] : ''),
        'When' => $when, 'Duration' => sa_svc_duration_label($b['service']), 'Where' => $loc,
        'Handover' => $b['mode'] === 'pickup' ? 'Vehicle pickup from: ' . $b['pickup'] . ' (pickup fee confirmed on booking)' : 'Drop-off at the garage', 'Vehicle' => trim($b['year'] . ' ' . $b['make'] . ' ' . $b['model'] . ' ' . $b['reg']),
        'Estimate' => $b['estimate'] . ($b['fee'] === 'waived' ? '' : ' (booking fee deducted from the final bill)'), 'Booking fee' => sa_svc_fee_label($b), 'Status' => $status,
    ];
    $html = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;color:#222">'
        . '<h2 style="color:#111111">Supreme Autoparts — Car Service</h2><p>Hi ' . esc_html($b['name']) . ',</p><p>'
        . ($event === 'cancel_request' ? 'We have received your cancellation request. Our team will review it and email you the decision. Your booking stays on hold until then.' : ($event === 'cancel_approved' ? 'Your cancellation has been approved and your booking is cancelled. Your KES ' . number_format((int) ($b['fee_kes'] ?: 1000)) . ' booking fee is being refunded to the method you chose.' : ($event === 'cancel_declined' ? 'Your cancellation request was declined, so your booking remains active. Please contact us if you have questions.' . ($b['cancel_note'] ? ' Note from our team: ' . $b['cancel_note'] : '') : ''))) . ($event === 'reminder' ? 'This is a reminder of your appointment tomorrow.' : (in_array($event, ['cancel_request', 'cancel_approved', 'cancel_declined'], true) ? '' : ($event === 'created' ? ($b['fee'] === 'unpaid' ? 'Thanks for booking. Pay the KES ' . number_format((int) ($b['fee_kes'] ?: sa_svc_setting('fee_kes'))) . ' booking fee to secure your slot: <a href="' . esc_url(add_query_arg(['b' => $id, 'k' => $b['key']], home_url('/book-service/pay/'))) . '">Pay booking fee</a>. Unpaid bookings are released after 2 hours.' : ($b['fee'] === 'waived' ? 'Thanks for booking. Your booking is confirmed. Booking fee: ' . sa_fw_label($id) . '. Labour is quoted as normal before any work starts.' : 'Thanks for booking. Your slot is secured.')) : ($event === 'cancelled' ? 'Your booking has been cancelled.' : 'Your booking status has changed.'))))
        . '</p><table style="border-collapse:collapse;width:100%">';
    foreach ($rows as $k => $v) {
        $html .= '<tr><td style="padding:6px 8px;border-bottom:1px solid #eee;color:#666">' . esc_html($k) . '</td><td style="padding:6px 8px;border-bottom:1px solid #eee"><strong>' . esc_html($v) . '</strong></td></tr>';
    }
    $html .= '</table><p><a href="' . esc_url(add_query_arg(['b' => $id, 'k' => $b['key']], home_url('/book-service/confirmed/'))) . '">View, cancel or reschedule your booking</a> · <a href="' . esc_url(home_url('/booking-terms/')) . '">Booking Terms</a> · <a href="' . esc_url(add_query_arg(['service' => $b['service'], 'reschedule' => $id, 'rk' => $b['key']], home_url('/book-service/'))) . '">Reschedule</a></p></div>';
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    // Unpaid new bookings: the customer gets the combined invoice + pay email instead (no duplicate).
    if (!($event === 'created' && in_array($b['fee'], ['unpaid', 'waived'], true) && function_exists('sa_bk_invoice_email'))) {
        wp_mail($b['email'], $subjects[$event] ?? $subjects['status'], $html, $headers);
    }
    if (!in_array($event, ['status', 'reminder', 'cancel_approved', 'cancel_declined'], true)) {
        $admin = (string) get_option('woocommerce_email_from_address', get_option('admin_email'));
        wp_mail($admin, '[Bookings] ' . ($subjects[$event] ?? '') . ' — ' . $b['name'], $html, $headers);
    }
}

/* ---------- Front-end routing ---------- */

add_action('template_redirect', static function (): void {
    $view = (string) get_query_var('sa_svc_view');
    if ($view === '') {
        return;
    }
    if ($view === 'detail' && !isset(sa_svc_catalog()[(string) get_query_var('sa_svc')])) {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        return;
    }
    if ($view === 'pay') {
        sa_svc_route_pay();
    }
    if ($view === 'manage' && isset($_POST['ref'])) {
        sa_svc_route_manage_lookup();
    }
    global $wp_query;
    $wp_query->is_404 = false;
    $wp_query->is_page = true;
    status_header(200);
    $titles = ['manage' => 'Manage booking', 'terms' => 'Booking Terms & Policies', 'track' => 'Track your order', 'index' => 'Car Services & Repairs', 'book' => 'Book a Service', 'confirmed' => 'Booking confirmed'];
    $title = $view === 'detail' ? sa_svc_catalog()[(string) get_query_var('sa_svc')]['name'] : $titles[$view] ?? 'Services';
    add_filter('pre_get_document_title', static fn() => $title . ' | ' . get_bloginfo('name'), 99);
    if (in_array($view, ['confirmed', 'book', 'track', 'manage'], true)) {
        add_filter('wp_robots', static fn(array $r) => array_merge($r, ['noindex' => true]));
    }
    get_header();
    echo '<main id="main" class="sa-svc">';
    switch ($view) {
        case 'detail': sa_svc_render_detail((string) get_query_var('sa_svc')); break;
        case 'book': sa_svc_render_book(); break;
        case 'confirmed': sa_svc_render_confirmed(); break;
        case 'manage': sa_svc_render_manage(); break;
        case 'terms': sa_svc_render_terms(); break;
        case 'track': echo '<div class="sa-container sa-svc-book"><h1>Track your order</h1><p class="sa-svc-lead">Enter your order number and billing email.</p>' . do_shortcode('[woocommerce_order_tracking]') . '</div>'; break;
        default: sa_svc_render_index();
    }
    echo '</main>';
    get_footer();
    exit;
});

add_action('wp_enqueue_scripts', static function (): void {
    wp_enqueue_style('sa-services', SA_CORE_URL . 'assets/services.css', [], SA_CORE_VERSION);
    if (get_query_var('sa_svc_view') === 'book') {
        wp_enqueue_script('sa-services', SA_CORE_URL . 'assets/services.js', [], SA_CORE_VERSION, true);
        wp_localize_script('sa-services', 'saSvc', ['ajax' => admin_url('admin-ajax.php')]);
        wp_enqueue_script('sa-fitting-waiver', SA_CORE_URL . 'assets/fitting-waiver.js', ['sa-services'], SA_CORE_VERSION, true);
    }
});

function sa_svc_icon(string $name): string
{
    $p = [
        'oil' => '<path d="M3 14h11l5-5 2 2-6 7H3z"/><path d="M7 14V9h5l2 2"/><path d="M20 17c0 1.1-.9 2-2 2s-2-.9-2-2 2-4 2-4 2 2.9 2 4z"/>',
        'brake' => '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3"/><path d="M5 7a9 9 0 0 1 4-3"/>',
        'align' => '<circle cx="6" cy="12" r="3"/><circle cx="18" cy="12" r="3"/><path d="M9 12h6M6 4v4M18 4v4M6 16v4M18 16v4"/>',
        'tyre' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4"/><path d="M12 3v5M12 16v5M3 12h5M16 12h5"/>',
        'ac' => '<path d="M12 2v20M4.9 6.5l14.2 11M4.9 17.5l14.2-11"/><path d="M9 3l3 2 3-2M9 21l3-2 3 2"/>',
        'battery' => '<rect x="3" y="7" width="18" height="12" rx="2"/><path d="M7 7V5h3v2M14 7V5h3v2M7 13h4M15 11v4M13 13h4"/>',
        'diag' => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M6 11l3-3 3 4 2-2 3 2M8 20h8M12 16v4"/>',
        'suspension' => '<path d="M12 2v3M12 19v3"/><path d="M8 5h8M8 19h8"/><path d="M9 7l6 2-6 2 6 2-6 2 6 2"/>',
        'service' => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.5 2.5-2.4-.6-.6-2.4z"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
        'check' => '<path d="M5 12l5 5 9-10"/>',
        'pin' => '<path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
        'cart' => '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.5 12h11L21 7H6.5"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/>',
        'box' => '<path d="M21 8l-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8M12 13v8"/>',
        'truck' => '<path d="M1 6h13v10H1zM14 10h4l3 3v3h-7"/><circle cx="5" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
        'car' => '<path d="M3 13l2-5a2 2 0 0 1 2-1h10a2 2 0 0 1 2 1l2 5v5H3z"/><circle cx="7" cy="16" r="1.4"/><circle cx="17" cy="16" r="1.4"/>',
        'chat' => '<path d="M21 12a8 8 0 0 1-11.8 7L3 21l2-6A8 8 0 1 1 21 12z"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'close' => '<path d="M6 6l12 12M18 6L6 18"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
    ][$name] ?? '<circle cx="12" cy="12" r="9"/>';
    return '<svg class="sa-svc-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

function sa_svc_region_switch(string $region, string $base): string
{
    $o = '<div class="sa-svc-region" role="group" aria-label="Pricing region">';
    foreach (['KE' => 'Kenya · KES', 'US' => 'USA · USD'] as $r => $l) {
        $o .= '<a class="' . ($r === $region ? 'is-active' : '') . '" href="' . esc_url(add_query_arg('region', strtolower($r), $base)) . '">' . esc_html($l) . '</a>';
    }
    return $o . '</div>';
}

function sa_svc_render_index(): void
{
    $region = sa_svc_region();
    ?>
    <section class="sa-svc-hero">
      <div class="sa-container sa-svc-hero__in">
        <div>
          <p class="sa-svc-eyebrow">Supreme Autoparts garage</p>
          <h1>Car service, booked in two minutes</h1>
          <p class="sa-svc-lead">From oil changes and brake pads to alignment, AC and full servicing. Pick a time online, drop the car off or have us collect it, and we fit real parts from our own shop. Your KES 1,000 booking fee is deducted from the final bill.</p>
          <div class="sa-svc-cta">
            <a class="sa-svc-btn sa-svc-btn--primary" href="<?php echo esc_url(sa_svc_book_url()); ?>"><?php echo sa_svc_icon('calendar'); ?> Book a service</a>
            <a class="sa-svc-btn sa-svc-btn--ghost" href="<?php echo esc_url(function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/shop/')); ?>">Shop parts</a>
          </div>
          <ul class="sa-svc-points"><li><?php echo sa_svc_icon('check'); ?>Garage at Midax Plaza, Kangundo Rd</li><li><?php echo sa_svc_icon('check'); ?>Book online, any time</li><li><?php echo sa_svc_icon('check'); ?>Parts from our own shop</li><li><?php echo sa_svc_icon('check'); ?>Booking number + email confirmation</li><li><?php echo sa_svc_icon('check'); ?>Drop-off or pickup</li></ul>
        </div>
        <ol class="sa-svc-steps">
          <li><span>1</span><strong>Book</strong><em>Pick a service and a time slot</em></li>
          <li><span>2</span><strong>Drop off or we pick up</strong><em>Drop it off or we collect it</em></li>
          <li><span>3</span><strong>We fix it</strong><em>You approve the quote before work starts</em></li>
          <li><span>4</span><strong>Drive away</strong><em>Pay the balance, fee already deducted</em></li>
        </ol>
      </div>
    </section>
    <section class="sa-container sa-svc-catalog">
      <div class="sa-svc-head"><h2>All services</h2><?php echo sa_svc_region_switch($region, sa_svc_url()); ?></div>
        <div class="sa-svc-grid">
          <?php foreach (sa_svc_catalog() as $slug => $s) : ?>
            <article class="sa-svc-card">
              <div class="sa-svc-card__top"><div class="sa-svc-card__ico"><?php echo sa_svc_icon($s['icon']); ?></div><span class="sa-svc-tag"><?php echo esc_html($s['group']); ?></span></div>
              <h4><a href="<?php echo esc_url(sa_svc_url($slug)); ?>"><?php echo esc_html($s['name']); ?></a></h4>
              <p><?php echo esc_html($s['summary']); ?></p>
              <div class="sa-svc-card__foot">
                <span class="sa-svc-price<?php echo sa_svc_price_label($s, $region) === 'Quote on request' ? ' is-quote' : ''; ?>"><?php echo esc_html(sa_svc_price_label($s, $region)); ?></span>
                <span class="sa-svc-dur"><?php echo esc_html(sa_svc_duration_label($slug)); ?></span>
              </div>
              <div class="sa-svc-card__act">
                <a class="sa-svc-btn sa-svc-btn--sm sa-svc-btn--primary" href="<?php echo esc_url(sa_svc_book_url($slug)); ?>">Book</a>
                <a class="sa-svc-btn sa-svc-btn--sm sa-svc-btn--ghost" href="<?php echo esc_url(sa_svc_url($slug)); ?>">Details</a>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <div class="sa-svc-locbar"><?php echo sa_svc_address_html(); ?><iframe title="Garage map" loading="lazy" src="https://www.google.com/maps?q=<?php echo rawurlencode(function_exists('sa_geo_business') ? sa_geo_business()['map_q'] : 'Midax Plaza, Kangundo Rd, Njiru, Nairobi'); ?>&output=embed"></iframe></div>
      <?php echo sa_svc_faq_html(sa_svc_faq_general(), 'Car service FAQ'); ?>
      <p class="sa-svc-fine">Job durations are typical estimates; your technician confirms timing. Prices shown are guide prices. Services without a listed price are quoted for your vehicle. You always approve the final price before work starts.</p>
    </section>
    <?php
}

function sa_svc_related_parts(string $term, int $n = 4): array
{
    if (!function_exists('wc_get_products') || $term === '') {
        return [];
    }
    $key = 'sa_svc_parts2_' . md5($term);
    $ids = get_transient($key);
    if (!is_array($ids)) {
        $q = new WP_Query(['post_type' => 'product', 'post_status' => 'publish', 's' => $term, 'posts_per_page' => 8, 'fields' => 'ids', 'no_found_rows' => true]);
        $ids = array_map('intval', $q->posts);
        set_transient($key, $ids, 6 * HOUR_IN_SECONDS);
    }
    return array_slice(array_filter(array_map('wc_get_product', $ids)), 0, $n);
}

function sa_svc_render_detail(string $slug): void
{
    $s = sa_svc_catalog()[$slug];
    $region = sa_svc_region();
    $parts = sa_svc_related_parts((string) ($s['parts'] ?? ''));
    ?>
    <div class="sa-container sa-svc-detail">
      <nav class="sa-svc-crumbs"><a href="<?php echo esc_url(home_url('/')); ?>">Home</a> / <a href="<?php echo esc_url(sa_svc_url()); ?>">Services</a> / <span><?php echo esc_html($s['name']); ?></span></nav>
      <div class="sa-svc-detail__grid">
        <div>
          <div class="sa-svc-card__ico sa-svc-card__ico--lg"><?php echo sa_svc_icon($s['icon']); ?></div>
          <h1><?php echo esc_html($s['name']); ?></h1>
          <p class="sa-svc-lead"><?php echo esc_html($s['summary']); ?></p>
          <h2>What's included</h2>
          <ul class="sa-svc-incl"><?php foreach ($s['includes'] as $i) : ?><li><?php echo sa_svc_icon('check'); ?><?php echo esc_html($i); ?></li><?php endforeach; ?></ul>
        </div>
        <aside class="sa-svc-pricebox">
          <?php echo sa_svc_region_switch($region, sa_svc_url($slug)); ?>
          <?php if ($region === 'KE' && !empty($s['kes'])) : ?>
            <table class="sa-svc-opts"><?php foreach ($s['kes']['options'] as $o) : ?><tr><td><?php echo esc_html($o['label']); ?></td><td>KES <?php echo esc_html(number_format((float) $o['price'])); ?></td></tr><?php endforeach; ?></table>
          <?php else : ?>
            <div class="sa-svc-bigprice<?php echo sa_svc_price_label($s, $region) === 'Quote on request' ? ' is-quote' : ''; ?>"><?php echo esc_html(sa_svc_price_label($s, $region)); ?></div>
            <?php if ($region === 'US' && !empty($s['usd']['note'])) : ?><p class="sa-svc-muted"><?php echo esc_html($s['usd']['note']); ?></p><?php endif; ?>
          <?php endif; ?>
          <p class="sa-svc-muted"><?php echo esc_html(sa_svc_duration_label($slug)); ?> · KES 1,000 fee deducted from bill · Drop-off or pickup</p>
          <a class="sa-svc-btn sa-svc-btn--primary sa-svc-btn--block" href="<?php echo esc_url(sa_svc_book_url($slug)); ?>"><?php echo sa_svc_icon('calendar'); ?> Book this service</a>
          <p class="sa-svc-fine"><?php echo esc_html(sa_svc_source_note($s, $region)); ?></p>
          <?php echo sa_svc_address_html(true); ?>
        </aside>
      </div>
      <?php echo sa_svc_faq_html(array_merge(sa_svc_faq_for($slug), array_slice(sa_svc_faq_general(), 1, 6)), $s['name'] . ' — FAQ'); ?>
      <?php if ($parts) : ?>
        <section class="sa-svc-parts">
          <div class="sa-svc-head"><h2>Parts for this service</h2><a href="<?php echo esc_url(add_query_arg(['s' => $s['parts'], 'post_type' => 'product'], home_url('/'))); ?>">See all</a></div>
          <div class="sa-svc-parts__grid">
            <?php foreach ($parts as $p) : ?>
              <a class="sa-svc-part" href="<?php echo esc_url($p->get_permalink()); ?>">
                <?php echo $p->get_image('woocommerce_thumbnail'); ?>
                <span class="sa-svc-part__name"><?php echo esc_html($p->get_name()); ?></span>
                <span class="sa-svc-part__price"><?php echo wp_kses_post($p->get_price_html()); ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>
    </div>
    <?php
}

function sa_svc_vehicle_db(): array
{
    // Common makes/models in Kenya and the US (for type-ahead only; any value is accepted).
    return [
        'Toyota' => ['Corolla', 'Fielder', 'Axio', 'Premio', 'Allion', 'Vitz', 'Probox', 'Succeed', 'Harrier', 'RAV4', 'Prado', 'Land Cruiser', 'Hilux', 'Camry', 'Tacoma', 'Tundra', 'Highlander', 'Wish', 'Noah', 'Voxy', 'Mark X', 'Crown'],
        'Nissan' => ['Note', 'March', 'Tiida', 'Sylphy', 'X-Trail', 'Navara', 'Juke', 'Dualis', 'Wingroad', 'AD Van', 'Altima', 'Rogue', 'Frontier', 'Sentra'],
        'Mazda' => ['Demio', 'Axela', 'Atenza', 'CX-5', 'CX-3', 'Mazda3', 'Mazda6', 'BT-50'],
        'Subaru' => ['Impreza', 'Forester', 'Outback', 'Legacy', 'XV', 'WRX'],
        'Honda' => ['Fit', 'Civic', 'Accord', 'CR-V', 'HR-V', 'Vezel', 'Pilot'],
        'Mitsubishi' => ['Pajero', 'Outlander', 'L200', 'Lancer', 'RVR', 'Colt'],
        'Volkswagen' => ['Golf', 'Polo', 'Passat', 'Tiguan', 'Touareg', 'Jetta', 'Amarok'],
        'Mercedes-Benz' => ['C-Class', 'E-Class', 'S-Class', 'GLE', 'GLC', 'ML', 'Sprinter'],
        'BMW' => ['3 Series', '5 Series', 'X1', 'X3', 'X5'],
        'Audi' => ['A3', 'A4', 'A6', 'Q5', 'Q7'],
        'Isuzu' => ['D-Max', 'MU-X', 'NQR', 'NPR'],
        'Suzuki' => ['Swift', 'Alto', 'Vitara', 'Jimny', 'Every'],
        'Ford' => ['F-150', 'Ranger', 'Everest', 'Escape', 'Explorer', 'Mustang', 'Focus'],
        'Chevrolet' => ['Silverado', 'Equinox', 'Malibu', 'Tahoe'],
        'Hyundai' => ['Tucson', 'Santa Fe', 'Elantra', 'Sonata'],
        'Kia' => ['Sportage', 'Sorento', 'Rio', 'Optima'],
        'Land Rover' => ['Range Rover', 'Range Rover Sport', 'Discovery', 'Defender', 'Evoque'],
        'Jeep' => ['Wrangler', 'Grand Cherokee', 'Cherokee'],
        'Ram' => ['1500', '2500'],
        'Tesla' => ['Model 3', 'Model Y', 'Model S', 'Model X'],
    ];
}

function sa_svc_render_book(): void
{
    $catalog = sa_svc_catalog();
    $region = sa_svc_region();
    $sel = sanitize_key((string) ($_GET['service'] ?? ''));
    if (!isset($catalog[$sel])) {
        $sel = 'oil-change';
    }
    $user = wp_get_current_user();
    $vehicles = $user->ID ? sa_svc_vehicles($user->ID) : [];
    $err = isset($_GET['sa_err']) ? sanitize_text_field(wp_unslash((string) $_GET['sa_err'])) : '';
    $resch = (int) ($_GET['reschedule'] ?? 0);
    $rk = sanitize_text_field((string) ($_GET['rk'] ?? ''));
    $old = $resch && get_post_type($resch) === 'sa_booking' ? sa_svc_booking_data($resch) : null;
    if ($old && !hash_equals($old['key'], $rk)) {
        $old = null;
    }
    $part = (int) ($_GET['part'] ?? 0);
    $partname = $part && get_post_type($part) === 'product' ? get_the_title($part) : '';
    $tz = wp_timezone();
    $min = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    $max = (new DateTimeImmutable('now', $tz))->modify('+60 days')->format('Y-m-d');
    $v = static fn(string $k): string => $old ? (string) $old[$k] : '';
    ?>
    <div class="sa-container sa-svc-wiz">
      <nav class="sa-svc-crumbs"><a href="<?php echo esc_url(home_url('/')); ?>">Home</a> / <a href="<?php echo esc_url(sa_svc_url()); ?>">Services</a> / <span>Book</span></nav>
      <h1><?php echo $old ? 'Reschedule ' . esc_html($old['ref']) : 'Book a service'; ?></h1>
      <p class="sa-svc-lead">Five quick steps. Pay a KES <?php echo esc_html(number_format((float) sa_svc_setting('fee_kes'))); ?> booking fee to confirm, which is credited to your bill. You pay the rest at the service, after approving the final quote.</p>
      <ul class="sa-svc-trust"><li><?php echo sa_svc_icon('check'); ?>Written quote before work</li><li><?php echo sa_svc_icon('check'); ?>Free online cancellation</li><li><?php echo sa_svc_icon('check'); ?>Parts from our own shop</li><li><?php echo sa_svc_icon('pin'); ?>Our garage on Kangundo Rd</li></ul>
      <?php if ($err) : ?><div class="sa-svc-alert"><?php echo esc_html($err); ?></div><?php endif; ?>
      <ol class="sa-svc-progress" data-sa-progress><li class="is-on">Vehicle</li><li>Services</li><li>Location</li><li>Date &amp; time</li><li>Review</li></ol>
      <div class="sa-svc-wiz__grid">
      <form class="sa-svc-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-sa-svc-form>
        <input type="hidden" name="action" value="sa_book_service">
        <?php wp_nonce_field('sa_book_service', 'sa_svc_nonce'); ?>
        <?php if ($old) : ?><input type="hidden" name="reschedule" value="<?php echo (int) $resch; ?>"><input type="hidden" name="rk" value="<?php echo esc_attr($rk); ?>"><?php endif; ?>
        <?php if ($partname) : ?><input type="hidden" name="part" value="<?php echo (int) $part; ?>"><?php endif; ?>
        <input type="text" name="sa_hp" value="" tabindex="-1" autocomplete="off" class="sa-svc-hp" aria-hidden="true">

        <fieldset class="sa-svc-step is-active" data-step="1"><legend><span>1</span> Your vehicle</legend>
          <?php if ($vehicles) : ?>
            <label class="sa-svc-full">My Garage <select data-sa-vehicle><option value="">Choose a saved vehicle</option>
              <?php foreach ($vehicles as $vv) : ?><option data-v='<?php echo esc_attr(wp_json_encode($vv)); ?>'><?php echo esc_html(trim($vv['year'] . ' ' . $vv['make'] . ' ' . $vv['model'] . ' ' . $vv['reg'])); ?></option><?php endforeach; ?>
            </select></label>
          <?php endif; ?>
          <div class="sa-svc-row">
            <label>Year <select name="year"><option value="">Year</option><?php for ($y = (int) gmdate('Y') + 1; $y >= 1985; $y--) : ?><option <?php selected($v('year'), (string) $y); ?>><?php echo (int) $y; ?></option><?php endfor; ?></select></label>
            <label>Make* <input name="make" required list="sa-makes" placeholder="Start typing, e.g. Toyota" value="<?php echo esc_attr($v('make')); ?>" data-sa-make autocomplete="off"></label>
            <label>Model* <input name="model" required list="sa-models" placeholder="e.g. Fielder" value="<?php echo esc_attr($v('model')); ?>" autocomplete="off"></label>
            <label>Plate / reg. <input name="reg" placeholder="KDA 123A" value="<?php echo esc_attr($v('reg')); ?>"></label>
          </div>
          <datalist id="sa-makes"><?php foreach (array_keys(sa_svc_vehicle_db()) as $mk) : ?><option value="<?php echo esc_attr($mk); ?>"><?php endforeach; ?></datalist>
          <datalist id="sa-models"></datalist>
          <script type="application/json" data-sa-vdb><?php echo wp_json_encode(sa_svc_vehicle_db()); ?></script>
          <?php if ($user->ID) : ?><label class="sa-svc-check"><input type="checkbox" name="save_vehicle" value="1" checked> Save to My Garage</label><?php endif; ?>
        </fieldset>

        <fieldset class="sa-svc-step" data-step="2"><legend><span>2</span> Main service</legend>
          <?php if ($partname) : ?><p class="sa-svc-alert sa-svc-alert--ok">Installation for: <strong><?php echo esc_html($partname); ?></strong></p><?php endif; ?>
          <?php $fwo = sanitize_text_field(wp_unslash((string) ($_GET['fw_order'] ?? ''))); $fwk = sanitize_text_field(wp_unslash((string) ($_GET['fw_key'] ?? ''))); ?>
          <div class="sa-fw" data-sa-fw>
            <label class="sa-svc-check sa-fw__toggle"><input type="checkbox" name="fw_on" value="1" data-fw-on <?php checked($fwo !== ''); ?>> <span><strong>I bought the part from Supreme Autoparts</strong> <em>No booking fee for fitting parts bought from us.</em></span></label>
            <div class="sa-fw__box" data-fw-box <?php echo $fwo !== '' ? '' : 'hidden'; ?>>
              <input type="hidden" name="fw_key" value="<?php echo esc_attr($fwk); ?>" data-fw-key>
              <div class="sa-svc-row">
                <label>Order number <input name="fw_order" value="<?php echo esc_attr($fwo); ?>" placeholder="e.g. 26555" inputmode="numeric" data-fw-order></label>
                <label <?php echo $fwk !== '' ? 'hidden' : ''; ?>>Email or phone on the order <input name="fw_contact" placeholder="you@email.com or 07xx…" data-fw-contact autocomplete="email"></label>
              </div>
              <button type="button" class="sa-svc-btn sa-svc-btn--sm sa-svc-btn--ghost" data-fw-check>Check order</button>
              <label class="sa-svc-full" data-fw-itemwrap hidden>Part we are fitting <select name="fw_item" data-fw-item></select></label>
              <p class="sa-fw__msg" data-fw-msg role="status"></p>
              <p class="sa-svc-fine">No login needed. The waiver covers the booking fee only; labour is quoted as normal. One fee-free booking per part. Other services in the same visit are included with no fee.</p>
            </div>
          </div>
          <div class="sa-svc-choices">
          <?php foreach ($catalog as $slug => $s) : ?>
            <label class="sa-svc-choice"><input type="radio" name="service" value="<?php echo esc_attr($slug); ?>" <?php checked($sel === $slug); ?> required data-dur="<?php echo (int) $s['duration']; ?>"
              data-kes='<?php echo esc_attr(wp_json_encode($s['kes']['options'] ?? [])); ?>'>
              <span><?php echo sa_svc_icon($s['icon']); ?><strong><?php echo esc_html($s['name']); ?></strong><small class="sa-svc-durtag"><?php echo esc_html(sa_svc_duration_label($slug)); ?></small><em data-price-ke="<?php echo esc_attr(sa_svc_price_label($s, 'KE')); ?>" data-price-us="<?php echo esc_attr(sa_svc_price_label($s, 'US')); ?>"><?php echo esc_html(sa_svc_price_label($s, $region)); ?></em></span></label>
          <?php endforeach; ?>
          </div>
          <div class="sa-svc-option" data-sa-option hidden><label>Option <select name="option"></select></label></div>
          <h4 class="sa-svc-sub">Add-ons <small>(optional, done in the same visit)</small></h4>
          <div class="sa-svc-addons">
          <?php foreach ($catalog as $slug => $s) : ?>
            <label class="sa-svc-addon" data-addon="<?php echo esc_attr($slug); ?>"><input type="checkbox" name="addons[]" value="<?php echo esc_attr($slug); ?>" data-dur="<?php echo (int) $s['duration']; ?>"><span><?php echo esc_html($s['name']); ?> <em data-price-ke="<?php echo esc_attr(sa_svc_price_label($s, 'KE')); ?>" data-price-us="<?php echo esc_attr(sa_svc_price_label($s, 'US')); ?>"><?php echo esc_html(sa_svc_price_label($s, $region)); ?></em></span></label>
          <?php endforeach; ?>
          </div>
        </fieldset>

        <fieldset class="sa-svc-step" data-step="3"><legend><span>3</span> Location &amp; handover</legend>
          <div class="sa-svc-locs">
          <?php foreach (sa_svc_locations() as $k => $l) : ?>
            <label class="sa-svc-choice sa-svc-choice--row"><input type="radio" name="location" value="<?php echo esc_attr($k); ?>" data-region="<?php echo esc_attr($l['region']); ?>" <?php checked($old ? $old['location'] === $k : (($region === 'KE' && $k === 'nairobi') || ($region === 'US' && $k === 'usa'))); ?> required>
              <span><strong><?php echo esc_html($l['label']); ?></strong><em><?php echo esc_html($l['note']); ?></em></span></label>
          <?php endforeach; ?>
          </div>
          <style>.sa-svc-wiz:has(input[name="location"][value="usa"]:checked) .sa-svc-usnote{display:block!important}</style><p class="sa-svc-usnote" hidden style="margin:12px 0;padding:10px 14px;border-left:3px solid #e0a200;background:#f4f4f1;color:#111111;font-size:14px;line-height:1.5"><strong style="color:#111111">Booking from the US?</strong> Customers in the US: we forward your request to our support team, who route you to a suitable service centre near you. Services, pricing and booking work differently for customers outside Kenya.</p>
          <div class="sa-svc-modes" data-sa-modes>
            <label class="sa-svc-choice sa-svc-choice--row"><input type="radio" name="mode" value="dropoff" checked><span><strong>Drop-off at the garage</strong><em>Bring the car in at your slot time. <a href="<?php echo esc_url(sa_svc_map_url()); ?>" target="_blank" rel="noopener">Map</a></em></span></label>
            <label class="sa-svc-choice sa-svc-choice--row" data-sa-pickup-opt><input type="radio" name="mode" value="pickup"><span><strong>Vehicle pickup from my address</strong><em>Nairobi area. Pickup fee confirmed on booking.</em></span></label>
          </div>
          <div class="sa-svc-pickup" data-sa-pickup hidden>
            <h4 class="sa-svc-sub">Pickup address</h4>
            <div class="sa-svc-row">
              <label>Street / road* <input name="pk_street" data-pk-req placeholder="Kangundo Rd"></label>
              <label>Building / estate* <input name="pk_building" data-pk-req placeholder="Building or estate name"></label>
              <label>House / apartment no. <input name="pk_house" placeholder="e.g. B12"></label>
              <label>Area / neighbourhood* <input name="pk_area" data-pk-req placeholder="e.g. Njiru"></label>
              <label>City / town* <input name="pk_city" data-pk-req value="Nairobi"></label>
              <label>Contact phone at pickup* <input name="pk_phone" type="tel" data-pk-req pattern="[+0-9 ]{9,16}" placeholder="+254 7xx xxx xxx"></label>
              <label>Preferred pickup time* <select name="pk_time" data-pk-req><option value="">Choose</option><option>1 hour before my slot</option><option>2 hours before my slot</option><option>Early morning (7:00–8:00)</option><option>Day before (evening)</option></select></label>
            </div>
            <label class="sa-svc-full">Landmark / directions <textarea name="pk_landmark" rows="2" placeholder="Near…, gate colour, which entrance"></textarea></label>
            <div class="sa-svc-pin"><input type="hidden" name="pk_pin" data-pk-pin><button type="button" class="sa-svc-btn sa-svc-btn--sm sa-svc-btn--ghost" data-pk-locate>📍 Use my location</button><span class="sa-svc-muted" data-pk-pinlabel>Optional: share a map pin so the driver finds you faster.</span></div>
          </div>
        </fieldset>

        <fieldset class="sa-svc-step" data-step="4"><legend><span>4</span> Date &amp; time</legend>
          <div class="sa-svc-row"><label>Date* <input type="date" name="date" required min="<?php echo esc_attr($min); ?>" max="<?php echo esc_attr($max); ?>" data-sa-date></label></div>
          <div class="sa-svc-slots" data-sa-slots><p class="sa-svc-muted">Choose a date to see free times (Mon–Fri 8:00–17:00, Sat 8:00–13:00). Longer jobs reserve consecutive hours.</p></div>
        </fieldset>

        <fieldset class="sa-svc-step" data-step="5"><legend><span>5</span> Your details &amp; review</legend>
          <p class="sa-svc-usnote" hidden style="margin:12px 0;padding:10px 14px;border-left:3px solid #e0a200;background:#f4f4f1;color:#111111;font-size:14px;line-height:1.5"><strong style="color:#111111">Booking from the US?</strong> Customers in the US: we forward your request to our support team, who route you to a suitable service centre near you. Services, pricing and booking work differently for customers outside Kenya.</p>
          <div class="sa-svc-row">
            <label>Full name* <input name="name" required value="<?php echo esc_attr($old ? $old['name'] : ($user->ID ? (trim($user->first_name . ' ' . $user->last_name) ?: $user->display_name) : '')); ?>"></label>
            <label>Email* <input type="email" name="email" required value="<?php echo esc_attr($old ? $old['email'] : ($user->ID ? $user->user_email : '')); ?>"></label>
            <label>Phone / WhatsApp* <input name="phone" required value="<?php echo esc_attr($old ? $old['phone'] : ($user->ID ? (string) get_user_meta($user->ID, 'billing_phone', true) : '')); ?>"></label>
          </div>
          <label class="sa-svc-full">Notes for the technician <textarea name="notes" rows="3" placeholder="Noises, warning lights, mileage…"><?php echo esc_textarea($v('notes')); ?></textarea></label>
          <div class="sa-svc-feebox sa-fw-waived" data-fw-feebox hidden><strong data-fw-feetext>Booking fee: waived</strong><span>Nothing to pay now. Your booking is confirmed as soon as you submit. Labour is quoted as normal, and you approve the final quote before any work starts.</span></div>
          <div class="sa-svc-feebox" data-fw-normalfee><strong>Booking fee: KES <?php echo esc_html(number_format((float) sa_svc_setting('fee_kes'))); ?><?php $usd = sa_svc_fee_usd(); unset($usd); ?></strong>
            <span>Paid now through our secure checkout. It is deducted from your final service bill. It is refunded if we cancel, or if you cancel at least <?php echo (int) sa_svc_setting('cancel_hours'); ?> hours before your appointment. Your booking is confirmed once the fee is paid.</span></div>
          <label class="sa-svc-check"><input type="checkbox" name="agree_terms" value="1" required> I agree to the <a href="<?php echo esc_url(home_url('/booking-terms/')); ?>" target="_blank">Booking Terms &amp; Policies</a></label>
          <p class="sa-svc-fine">By booking you agree that we may contact you by email, phone or WhatsApp about this appointment. You can cancel for free online while the booking is pending or confirmed. You'll get a reminder the day before.</p>
        </fieldset>

        <div class="sa-svc-nav">
          <button type="button" class="sa-svc-btn sa-svc-btn--ghost" data-sa-back hidden>Back</button>
          <button type="button" class="sa-svc-btn sa-svc-btn--primary" data-sa-next>Continue</button>
          <button class="sa-svc-btn sa-svc-btn--primary sa-svc-btn--lg" type="submit" data-sa-submit hidden><?php echo sa_svc_icon('calendar'); ?> <span data-fw-btn data-free="Confirm booking (no fee)"><?php echo $old ? 'Confirm new time & pay fee' : 'Continue to pay booking fee'; ?></span></button>
        </div>
      </form>
      <aside class="sa-svc-side">
        <div class="sa-svc-sum"><h3>Your estimate</h3><div data-sa-summary></div>
          <p class="sa-svc-fine" data-fw-normalfee>This is an estimate. You approve the final quote before any work starts. The KES 1,000 booking fee is deducted from your bill.</p></div>
        <?php echo sa_svc_address_html(true); ?>
      </aside>
      </div>
      <?php echo sa_svc_faq_html(sa_svc_faq_general(), 'Booking FAQ'); ?>
    </div>
    <?php
}

function sa_svc_render_booking_card(array $b, bool $cancel = true): void
{
    $svc = sa_svc_catalog()[$b['service']] ?? ['name' => $b['service'], 'icon' => 'service'];
    $loc = sa_svc_locations()[$b['location']]['label'] ?? $b['location'];
    ?>
    <div class="sa-svc-bk">
      <div class="sa-svc-bk__top"><span class="sa-svc-bk__ref">Booking no. <?php echo esc_html($b['ref']); ?></span><span class="sa-svc-status sa-svc-status--<?php echo esc_attr($b['status']); ?>"><?php echo esc_html(sa_svc_statuses()[$b['status']] ?? $b['status']); ?></span></div>
      <h3><?php echo sa_svc_icon($svc['icon'] ?? 'service'); ?> <?php echo esc_html($svc['name'] . ($b['option'] ? ' — ' . $b['option'] : '')); ?></h3>
      <dl>
        <dt>When</dt><dd><?php echo esc_html(date_i18n('D j M Y', strtotime($b['date'])) . ', ' . $b['time']); ?></dd>
        <dt>Duration</dt><dd><?php echo esc_html(sa_svc_duration_label($b['service'])); ?></dd>
        <dt>Where</dt><dd><?php echo esc_html($loc); ?><?php if ($b['location'] === 'nairobi') : ?> · <a href="<?php echo esc_url(sa_svc_map_url()); ?>" target="_blank" rel="noopener">Map</a><?php endif; ?></dd>
        <dt>Handover</dt><dd><?php echo $b['mode'] === 'pickup' ? esc_html('Pickup from ' . $b['pickup']) . ' <small>(fee confirmed on booking)</small>' : 'Drop-off at garage'; ?></dd>
        <dt>Vehicle</dt><dd><?php echo esc_html(trim($b['year'] . ' ' . $b['make'] . ' ' . $b['model'] . ' ' . $b['reg'])); ?></dd>
        <dt>Estimate</dt><dd><?php echo esc_html($b['estimate']); ?><?php echo $b['fee'] === 'waived' ? ' · labour quoted as normal' : ' · booking fee deducted from final bill'; ?></dd>
        <dt>Booking fee</dt><dd><?php echo esc_html(sa_svc_fee_label($b)); ?><?php if ($b['fee'] === 'unpaid' && $b['status'] === 'pending') : ?> · <a href="<?php echo esc_url(add_query_arg(['b' => $b['id'], 'k' => $b['key']], home_url('/book-service/pay/'))); ?>">Pay now</a><?php endif; ?></dd>
      </dl>
      <?php if (function_exists('sa_bk_invoice_url')) : ?><p class="sa-svc-inv"><a class="sa-svc-btn sa-svc-btn--sm sa-svc-btn--ghost" href="<?php echo esc_url(sa_bk_invoice_url((int) $b['id'])); ?>" target="_blank" rel="noopener">View invoice<?php echo in_array($b['fee'], ['paid', 'applied', 'waived'], true) ? ($b['fee'] === 'waived' ? ' (KES 0)' : ' (paid)') : ' (unpaid)'; ?></a> <a class="sa-svc-btn sa-svc-btn--sm sa-svc-btn--ghost" href="<?php echo esc_url(sa_bk_invoice_url((int) $b['id'], true)); ?>">Download</a></p><?php endif; ?>
      <?php if ($b['status'] === 'cancel_requested') : ?><p class="sa-svc-alert">Cancellation requested. We'll email you our decision.</p><?php endif; ?>
      <?php if ($cancel && in_array($b['status'], ['pending', 'confirmed'], true) && $b['fee'] === 'paid') : ?>
        <a class="sa-svc-btn sa-svc-btn--sm sa-svc-btn--primary" href="<?php echo esc_url(add_query_arg(['service' => $b['service'], 'reschedule' => $b['id'], 'rk' => $b['key']], home_url('/book-service/'))); ?>">Reschedule</a>
        <details class="sa-svc-cr" id="cancel"<?php echo isset($_GET['cr_err']) ? ' open' : ''; ?>><summary class="sa-svc-btn sa-svc-btn--sm sa-svc-btn--ghost">Request cancellation</summary>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sa-svc-form">
            <?php if (isset($_GET['cr_err'])) : ?><p class="sa-svc-alert">Please complete all required fields.</p><?php endif; ?>
            <input type="hidden" name="action" value="sa_svc_cancel"><input type="hidden" name="b" value="<?php echo (int) $b['id']; ?>"><input type="hidden" name="k" value="<?php echo esc_attr($b['key']); ?>">
            <?php wp_nonce_field('sa_svc_cancel_' . $b['id']); ?>
            <p class="sa-svc-muted">Booking <?php echo esc_html($b['ref']); ?>. <?php echo sa_svc_within_window($b) ? 'You are within the free-cancellation window, so the fee is refundable.' : 'This is less than ' . (int) sa_svc_setting('cancel_hours') . ' hours before your appointment, so the fee may not be refundable (see Booking Terms).'; ?></p>
            <label>Reason* <select name="reason" required><option value="">Choose</option><?php foreach (sa_svc_cancel_reasons() as $rk2 => $rl) : ?><option value="<?php echo esc_attr($rk2); ?>"><?php echo esc_html($rl); ?></option><?php endforeach; ?></select></label>
            <label>Details <textarea name="details" rows="2"></textarea></label>
            <label>Preferred refund method* <select name="refund_method" required><option value="">Choose</option><option value="mpesa">M-Pesa</option><option value="card">Original card</option><option value="bank">Bank transfer</option></select></label>
            <label>Refund details* <input name="refund_details" required placeholder="M-Pesa number / last 4 card digits / bank, account name & number"></label>
            <label>Contact (phone or email)* <input name="contact" required value="<?php echo esc_attr($b['phone']); ?>"></label>
            <button class="sa-svc-btn sa-svc-btn--primary" type="submit">Submit cancellation request</button>
          </form></details>
      <?php elseif ($cancel && in_array($b['status'], ['pending', 'confirmed'], true)) : ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Cancel this booking?');">
          <input type="hidden" name="action" value="sa_svc_cancel"><input type="hidden" name="b" value="<?php echo (int) $b['id']; ?>"><input type="hidden" name="k" value="<?php echo esc_attr($b['key']); ?>">
          <?php wp_nonce_field('sa_svc_cancel_' . $b['id']); ?>
          <a class="sa-svc-btn sa-svc-btn--sm sa-svc-btn--primary" href="<?php echo esc_url(add_query_arg(['service' => $b['service'], 'reschedule' => $b['id'], 'rk' => $b['key']], home_url('/book-service/'))); ?>">Reschedule</a>
          <button class="sa-svc-btn sa-svc-btn--sm sa-svc-btn--ghost" type="submit">Cancel booking</button>
        </form>
      <?php endif; ?>
    </div>
    <?php
}

function sa_svc_render_confirmed(): void
{
    $id = (int) ($_GET['b'] ?? 0);
    $b = $id && get_post_type($id) === 'sa_booking' ? sa_svc_booking_data($id) : null;
    if (!$b || !hash_equals($b['key'], (string) ($_GET['k'] ?? ''))) {
        echo '<div class="sa-container sa-svc-book"><h1>Booking not found</h1><p><a href="' . esc_url(sa_svc_book_url()) . '">Book a service</a></p></div>';
        return;
    }
    $svc = sa_svc_catalog()[$b['service']] ?? ['parts' => ''];
    $parts = sa_svc_related_parts((string) ($svc['parts'] ?? ''), 4);
    ?>
    <div class="sa-container sa-svc-book sa-svc-done">
      <div class="sa-svc-done__badge"><?php echo sa_svc_icon('check'); ?></div>
      <h1><?php echo $b['status'] === 'cancelled' ? 'Booking cancelled' : ($b['fee'] === 'unpaid' ? 'Booking held — pay the fee to confirm' : 'Booking confirmed'); ?></h1>
      <?php $sa_pay = sanitize_key((string) ($_GET['sa_pay'] ?? '')); if ($sa_pay !== '') : ?><p class="sa-svc-alert<?php echo $sa_pay === 'paid' ? ' sa-svc-alert--ok' : ''; ?>"><?php echo esc_html(['paid' => 'Your booking fee is already paid. Nothing more to pay.', 'closed' => 'This booking is no longer awaiting payment (it was cancelled or released after 2 hours unpaid). Please book a new slot.', 'unavailable' => 'Online payment is temporarily unavailable. Please contact us on WhatsApp and we will help you pay.'][$sa_pay] ?? ''); ?></p><?php endif; ?>
      <p class="sa-svc-refbig">Booking number <strong><?php echo esc_html($b['ref']); ?></strong></p>
      <p class="sa-svc-lead">We've emailed the details to <?php echo esc_html($b['email']); ?>. Use your booking number and email under <a href="<?php echo esc_url(home_url('/manage-booking/')); ?>">Manage booking</a>.</p>
      <?php sa_svc_render_booking_card($b); ?>
      <div class="sa-svc-cta"><a class="sa-svc-btn sa-svc-btn--primary" href="<?php echo esc_url(is_user_logged_in() ? wc_get_account_endpoint_url('bookings') : sa_svc_url()); ?>"><?php echo is_user_logged_in() ? 'My bookings' : 'Back to services'; ?></a></div>
      <?php if ($parts) : ?>
        <section class="sa-svc-parts"><div class="sa-svc-head"><h2>Bring your own parts? Shop matching parts</h2></div><div class="sa-svc-parts__grid">
        <?php foreach ($parts as $p) : ?><a class="sa-svc-part" href="<?php echo esc_url($p->get_permalink()); ?>"><?php echo $p->get_image('woocommerce_thumbnail'); ?><span class="sa-svc-part__name"><?php echo esc_html($p->get_name()); ?></span><span class="sa-svc-part__price"><?php echo wp_kses_post($p->get_price_html()); ?></span></a><?php endforeach; ?>
        </div></section>
      <?php endif; ?>
    </div>
    <?php
}

/* ---------- My Account ---------- */

add_filter('woocommerce_get_query_vars', static function (array $vars): array {
    $vars['bookings'] = 'bookings';
    return $vars;
});

add_action('woocommerce_account_bookings_endpoint', static function (): void {
    $uid = get_current_user_id();
    $email = wp_get_current_user()->user_email;
    $ids = get_posts(['post_type' => 'sa_booking', 'numberposts' => 50, 'fields' => 'ids', 'meta_query' => ['relation' => 'OR', ['key' => '_sa_bk_user', 'value' => (string) $uid], ['key' => '_sa_bk_email', 'value' => $email]]]);
    echo '<div class="sa-svc">';
    echo '<div class="sa-svc-head"><h2>Service bookings</h2><a class="sa-svc-btn sa-svc-btn--sm sa-svc-btn--primary" href="' . esc_url(sa_svc_book_url()) . '">Book a service</a></div>';
    if (isset($_GET['sa_cancelled'])) {
        echo '<div class="sa-svc-alert sa-svc-alert--ok">Booking cancelled.</div>';
    }
    if (!$ids) {
        echo '<p class="sa-svc-muted">No bookings yet. <a href="' . esc_url(sa_svc_url()) . '">Browse services</a>.</p>';
    } else {
        echo '<div class="sa-svc-bks">';
        foreach ($ids as $id) {
            sa_svc_render_booking_card(sa_svc_booking_data((int) $id));
        }
        echo '</div>';
    }
    echo '<h2 id="vehicles" style="margin-top:28px">My Garage / Vehicles</h2><div class="sa-svc-vehs">';
    foreach (sa_svc_vehicles($uid) as $i => $v) {
        echo '<form class="sa-svc-veh" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><span>' . esc_html(trim($v['year'] . ' ' . $v['make'] . ' ' . $v['model'] . ' ' . $v['reg'])) . '</span>'
            . '<input type="hidden" name="action" value="sa_svc_vehicle"><input type="hidden" name="remove" value="' . (int) $i . '">' . wp_nonce_field('sa_svc_vehicle', '_wpnonce', true, false)
            . '<button class="sa-svc-btn sa-svc-btn--sm sa-svc-btn--ghost">Remove</button></form>';
    }
    echo '</div><form class="sa-svc-row sa-svc-vehadd" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="sa_svc_vehicle">' . wp_nonce_field('sa_svc_vehicle', '_wpnonce', true, false)
        . '<input name="make" placeholder="Make" required><input name="model" placeholder="Model" required><input name="year" type="number" placeholder="Year"><input name="reg" placeholder="Plate"><button class="sa-svc-btn sa-svc-btn--sm sa-svc-btn--primary">Add vehicle</button></form>';
    echo '</div>';
});

/* ---------- Super Admin manager ---------- */

add_action('admin_post_sa_svc_admin', static function (): void {
    if (!current_user_can('manage_woocommerce') || !wp_verify_nonce((string) ($_POST['_wpnonce'] ?? ''), 'sa_svc_admin')) {
        wp_die('Not allowed', 403);
    }
    $id = (int) ($_POST['b'] ?? 0);
    if (get_post_type($id) !== 'sa_booking') {
        wp_die('Unknown booking', 404);
    }
    if (!empty($_POST['cr_decision'])) {
        $req = (array) get_post_meta($id, '_sa_bk_cancel_req', true);
        update_post_meta($id, '_sa_bk_cancel_note', sanitize_text_field(wp_unslash((string) ($_POST['cr_note'] ?? ''))));
        if ($_POST['cr_decision'] === 'approve') {
            update_post_meta($id, '_sa_bk_status', 'cancelled');
            update_post_meta($id, '_sa_bk_fee', 'refunded');
            sa_svc_send_emails($id, 'cancel_approved');
        } else {
            update_post_meta($id, '_sa_bk_status', in_array($req['prev'] ?? '', ['pending', 'confirmed'], true) ? $req['prev'] : 'confirmed');
            sa_svc_send_emails($id, 'cancel_declined');
        }
        sa_svc_gcal_sync($id);
        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=sa-super-bookings'));
        exit;
    }
    if (!empty($_POST['delete'])) {
        sa_svc_gcal_sync($id, true);
        wp_delete_post($id, true);
    } else {
        $st = sanitize_key((string) ($_POST['status'] ?? ''));
        if (isset(sa_svc_statuses()[$st]) && $st !== get_post_meta($id, '_sa_bk_status', true)) {
            update_post_meta($id, '_sa_bk_status', $st);
            if (!empty($_POST['notify'])) {
                sa_svc_send_emails($id, $st === 'cancelled' ? 'cancelled' : 'status');
            }
        }
        $fee = sanitize_key((string) ($_POST['fee'] ?? ''));
        if (isset(sa_svc_fee_statuses()[$fee])) {
            update_post_meta($id, '_sa_bk_fee', $fee);
        }
        if ($st === 'cancelled' && get_post_meta($id, '_sa_bk_fee', true) === 'paid') {
            update_post_meta($id, '_sa_bk_fee', 'refund_due'); // We cancelled → refund.
        }
        if (function_exists('sa_fw_admin_save')) {
            sa_fw_admin_save($id);
        }
        if (isset($_POST['estimate'])) {
            update_post_meta($id, '_sa_bk_estimate', sanitize_text_field(wp_unslash((string) $_POST['estimate'])));
        }
        sa_svc_gcal_sync($id);
    }
    if (function_exists('sa_core_audit_log')) {
        sa_core_audit_log('booking_update', ['booking' => $id]);
    }
    wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=sa-super-bookings'));
    exit;
});

function sa_svc_admin_render(): void
{
    $filter = sanitize_key((string) ($_GET['st'] ?? ''));
    $search = sanitize_text_field(wp_unslash((string) ($_GET['q'] ?? '')));
    $args = ['post_type' => 'sa_booking', 'numberposts' => 200, 'fields' => 'ids'];
    if ($filter && isset(sa_svc_statuses()[$filter])) {
        $args['meta_query'] = [['key' => '_sa_bk_status', 'value' => $filter]];
    }
    if ($search !== '') {
        $args['s'] = $search;
    }
    $ids = get_posts($args);
    $all = get_posts(['post_type' => 'sa_booking', 'numberposts' => -1, 'fields' => 'ids']);
    $counts = array_fill_keys(array_keys(sa_svc_statuses()), 0);
    foreach ($all as $i) {
        $s = (string) get_post_meta($i, '_sa_bk_status', true) ?: 'pending';
        $counts[$s] = ($counts[$s] ?? 0) + 1;
    }
    $base = admin_url('admin.php?page=sa-super-bookings');
    echo '<div class="wrap sa-ultra sa-svc-admin"><h1>Service bookings</h1><p class="sa-ultra__sub">Appointments from <a href="' . esc_url(sa_svc_url()) . '" target="_blank">/services/</a>. Changing status can email the customer.</p>' . sa_svc_gcal_status_html() . sa_svc_settings_form();
    echo '<ul class="subsubsub"><li><a href="' . esc_url($base) . '"' . ($filter === '' ? ' class="current"' : '') . '>All (' . count($all) . ')</a></li>';
    foreach (sa_svc_statuses() as $k => $l) {
        echo '<li> | <a href="' . esc_url(add_query_arg('st', $k, $base)) . '"' . ($filter === $k ? ' class="current"' : '') . '>' . esc_html($l) . ' (' . (int) $counts[$k] . ')</a></li>';
    }
    echo '</ul><form method="get" style="float:right"><input type="hidden" name="page" value="sa-super-bookings"><input type="search" name="q" value="' . esc_attr($search) . '" placeholder="Ref or name"> <button class="button">Search</button></form>';
    echo '<table class="widefat striped" style="margin-top:12px"><thead><tr><th>Ref</th><th>When</th><th>Service</th><th>Customer</th><th>Vehicle</th><th>Location</th><th>Estimate</th><th>Status / actions</th></tr></thead><tbody>';
    if (!$ids) {
        echo '<tr><td colspan="8">No bookings.</td></tr>';
    }
    foreach ($ids as $id) {
        $b = sa_svc_booking_data((int) $id);
        $svc = sa_svc_catalog()[$b['service']]['name'] ?? $b['service'];
        echo '<tr><td><strong>' . esc_html($b['ref']) . '</strong><br><small>' . esc_html(wp_date('j M H:i', (int) $b['created'])) . '</small>' . (function_exists('sa_bk_invoice_url') ? '<br><a href="' . esc_url(sa_bk_invoice_url((int) $id)) . '" target="_blank">Invoice</a> · <a href="' . esc_url(sa_bk_invoice_url((int) $id, true)) . '">Download</a>' : '') . '</td>'
            . '<td>' . esc_html($b['date'] . ' ' . $b['time']) . '</td><td>' . esc_html($svc . ($b['option'] ? ' — ' . $b['option'] : '')) . ($b['notes'] ? '<br><small>' . esc_html($b['notes']) . '</small>' : '') . '</td>'
            . '<td>' . esc_html($b['name']) . '<br><a href="mailto:' . esc_attr($b['email']) . '">' . esc_html($b['email']) . '</a><br>' . esc_html($b['phone']) . '</td>'
            . '<td>' . esc_html(trim($b['year'] . ' ' . $b['make'] . ' ' . $b['model'] . ' ' . $b['reg'])) . '</td>'
            . '<td>' . esc_html(sa_svc_locations()[$b['location']]['label'] ?? $b['location']) . '<br><small>' . ($b['mode'] === 'pickup' ? 'PICKUP: ' . esc_html($b['pickup']) : 'Drop-off') . ' · ' . esc_html(sa_svc_duration_label($b['service'])) . ($b['gcal'] ? ' · 📅 synced' : '') . '</small></td>'
            . '<td colspan="2">' . sa_svc_admin_cr_box((int) $id, $b) . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">'
            . '<input type="hidden" name="action" value="sa_svc_admin"><input type="hidden" name="b" value="' . (int) $id . '">' . wp_nonce_field('sa_svc_admin', '_wpnonce', true, false)
            . (function_exists('sa_fw_admin_box') ? sa_fw_admin_box((int) $id, $b) : '') . '<input name="estimate" value="' . esc_attr($b['estimate']) . '" style="width:150px"><select name="fee" title="Booking fee">';
        foreach (sa_svc_fee_statuses() as $k => $l) {
            echo '<option value="' . esc_attr($k) . '"' . selected($b['fee'], $k, false) . '>Fee: ' . esc_html($l) . '</option>';
        }
        echo '</select>' . ($b['fee_order'] ? '<a href="' . esc_url(admin_url('post.php?post=' . (int) $b['fee_order'] . '&action=edit')) . '">order #' . (int) $b['fee_order'] . '</a>' : '') . '<select name="status">';
        foreach (sa_svc_statuses() as $k => $l) {
            echo '<option value="' . esc_attr($k) . '"' . selected($b['status'], $k, false) . '>' . esc_html($l) . '</option>';
        }
        echo '</select><label><input type="checkbox" name="notify" value="1" checked> email</label><button class="button button-primary">Save</button>'
            . '<button class="button-link-delete" name="delete" value="1" onclick="return confirm(\'Delete booking permanently?\')">Delete</button></form></td></tr>';
    }
    echo '</tbody></table></div>';
}



/* ---------- Contact page: garage address + map ---------- */

add_filter('the_content', static function (string $c): string {
    if (is_page('contact') && in_the_loop() && is_main_query()) {
        if (function_exists('sa_geo_is_kenya') && !sa_geo_is_kenya()) {
            $q = rawurlencode(sa_geo_business()['map_q']);
            $c .= '<h2>Our address</h2>' . sa_svc_address_html() . '<iframe title="Map" loading="lazy" style="width:100%;height:320px;border:0;margin-top:12px" src="https://www.google.com/maps?q=' . $q . '&output=embed"></iframe>';
        } else {
            $c .= '<h2>Visit our garage</h2>' . sa_svc_address_html() . '<p><a class="sa-svc-btn sa-svc-btn--primary" href="' . esc_url(sa_svc_book_url()) . '">Book a service</a></p>';
        }
    }
    return $c;
}, 30);

/* ---------- Right-hand side menu (drawer) ---------- */

add_action('wp_footer', static function (): void {
    if (is_admin()) {
        return;
    }
    $acct = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/');
    $ep = static fn(string $e): string => function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url($e) : $acct;
    $shop = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/shop/');
    $wa = 'https://wa.me/19174375121';
    $user = wp_get_current_user();
    $cats = function_exists('sa_megamenu_tree') ? sa_megamenu_tree() : [];
    $link = static fn(string $u, string $i, string $l, string $sub = '', string $extra = ''): string =>
        '<a class="sa-side__link" href="' . esc_url($u) . '"' . $extra . '>' . sa_svc_icon($i) . '<span>' . esc_html($l) . ($sub !== '' ? '<small>' . esc_html($sub) . '</small>' : '') . '</span></a>';
    echo '<div class="sa-side" data-sa-side aria-hidden="true"><div class="sa-side__backdrop" data-sa-side-close></div>'
        . '<aside class="sa-side__panel" role="dialog" aria-modal="true" aria-label="Menu">'
        . '<div class="sa-side__head"><div><strong>' . ($user->ID ? 'Hi, ' . esc_html($user->first_name ?: $user->display_name) : 'Welcome') . '</strong>'
        . ($user->ID ? (function_exists('sa_core_logout_link_url') ? '<a href="' . esc_url(sa_core_logout_link_url()) . '">Log out</a>' : '') : '<a href="' . esc_url($acct) . '">Sign in or create account</a>') . '</div>'
        . '<button type="button" class="sa-side__x" data-sa-side-close aria-label="Close menu">' . sa_svc_icon('close') . '</button></div>'
        . '<div class="sa-side__quick">'
        . '<a href="' . esc_url($shop) . '">' . sa_svc_icon('grid') . 'Shop Parts</a>'
        . '<a href="' . esc_url(sa_svc_book_url()) . '" class="is-hl">' . sa_svc_icon('calendar') . 'Book a Service</a>'
        . (function_exists('sa_rs_url') && (!function_exists('sa_geo_is_kenya') || sa_geo_is_kenya()) ? '<a href="' . esc_url(sa_rs_url()) . '">' . sa_svc_icon('truck') . 'Roadside Rescue</a>' : '')
        . '<a href="#" data-sa-side-cart>' . sa_svc_icon('cart') . 'Cart</a>'
        . '<a href="' . esc_url(home_url('/track-order/')) . '">' . sa_svc_icon('truck') . 'Track Order</a></div>'
        . '<nav class="sa-side__sec"><h4>Car service</h4>'
        . $link(sa_svc_url(), 'service', 'All services', 'Oil, brakes, alignment, AC…')
        . $link($ep('bookings'), 'calendar', 'My Bookings')
        . $link($ep('bookings') . '#vehicles', 'car', 'My Garage / Vehicles')
        . $link(home_url('/manage-booking/'), 'box', 'Manage booking', 'Booking number + email')
        . $link(home_url('/booking-terms/'), 'check', 'Booking Terms & Policies')
        . '</nav>' . (function_exists('sa_rs_url') && (!function_exists('sa_geo_is_kenya') || sa_geo_is_kenya()) ? '<nav class="sa-side__sec"><h4>Roadside Rescue</h4>'
        . $link(sa_rs_url('request'), 'truck', 'Request roadside help', 'Towing, battery, tyre, fuel, lockout')
        . $link(sa_rs_url('track'), 'pin', 'Track a roadside request')
        . ($user->ID ? $link($ep('roadside'), 'box', 'My roadside requests') : '')
        . $link('tel:+254714498451', 'chat', 'Call roadside', '+254 714 498 451')
        . '</nav>' : '') . '<nav class="sa-side__sec"><h4>Shop by category</h4><div class="sa-side__cats">';
    foreach ($cats as $m) {
        $u = get_term_link($m['slug'], 'product_cat');
        echo '<a href="' . esc_url(is_wp_error($u) ? $shop : $u) . '">' . esc_html($m['label']) . '</a>';
    }
    foreach (['tires' => 'Tires', 'wheels' => 'Wheels'] as $sl => $lb) {
        $u = get_term_link($sl, 'product_cat');
        echo '<a href="' . esc_url(is_wp_error($u) ? $shop : $u) . '">' . esc_html($lb) . '</a>';
    }
    echo '</div></nav><nav class="sa-side__sec"><h4>Account</h4>'
        . $link($ep('orders'), 'box', 'Orders')
        . $link($acct, 'user', 'Account')
        . ($user->ID && function_exists('sa_core_logout_link_url') ? str_replace('class="sa-side__link"', 'class="sa-side__link sa-side__link--logout"', $link(sa_core_logout_link_url(), 'close', 'Log out')) : '')
        . '</nav><nav class="sa-side__sec"><h4>Help</h4>'
        . $link($wa, 'chat', 'WhatsApp us', '+1 917 437 5121', ' target="_blank" rel="noopener"')
        . $link(function_exists('sa_page_url') ? sa_page_url('contact') : home_url('/contact/'), 'chat', 'Contact')
        . ((!function_exists('sa_geo_is_kenya') || sa_geo_is_kenya()) ? $link(sa_svc_map_url(), 'pin', 'Location', SA_SVC_GARAGE_ADDRESS, ' target="_blank" rel="noopener"') : '')
        . '</nav></aside></div>';
    ?>
<script>
(function(){var s=document.querySelector('[data-sa-side]');if(!s)return;
function o(e){if(e)e.preventDefault();s.classList.add('is-open');s.setAttribute('aria-hidden','false');document.body.classList.add('sa-side-open');}
function c(){s.classList.remove('is-open');s.setAttribute('aria-hidden','true');document.body.classList.remove('sa-side-open');}
document.addEventListener('click',function(e){var t=e.target.closest('[data-sa-side-open],[data-sa-nav-toggle]');if(t){e.stopImmediatePropagation();o(e);return;}
if(e.target.closest('[data-sa-side-close]'))c();
if(e.target.closest('[data-sa-side-cart]')){e.preventDefault();c();var b=document.querySelector('[data-sa-cart-open]');if(b)b.click();}},true);
document.addEventListener('keydown',function(e){if(e.key==='Escape')c();});})();
</script>
    <?php
}, 20);

/* ---------- Google Calendar sync (service account; disabled until configured) ---------- */

function sa_svc_gcal_config(): ?array
{
    $raw = (string) (getenv('SA_GCAL_SERVICE_ACCOUNT_JSON') ?: '');
    $cal = (string) (getenv('SA_GCAL_CALENDAR_ID') ?: '');
    if ($raw === '' || $cal === '') {
        return null;
    }
    if ($raw[0] !== '{') {
        $raw = (string) base64_decode($raw, true);
    }
    $j = json_decode($raw, true);
    if (!is_array($j) || empty($j['client_email']) || empty($j['private_key'])) {
        return null;
    }
    return ['email' => $j['client_email'], 'key' => $j['private_key'], 'calendar' => $cal, 'subject' => (string) (getenv('SA_GCAL_IMPERSONATE') ?: '')];
}

function sa_svc_gcal_status_html(): string
{
    $cfg = sa_svc_gcal_config();
    $last = get_option('sa_svc_gcal_last', []);
    if (!$cfg) {
        return '<p class="notice notice-info" style="padding:8px 12px">Google Calendar sync: <strong>off</strong>. Set Railway env <code>SA_GCAL_SERVICE_ACCOUNT_JSON</code> and <code>SA_GCAL_CALENDAR_ID</code> (share the calendar with the service account, “Make changes to events”).</p>';
    }
    return '<p class="notice notice-success" style="padding:8px 12px">Google Calendar sync: <strong>on</strong> → ' . esc_html($cfg['calendar']) . (is_array($last) && $last ? ' · last: ' . esc_html(($last['ok'] ? 'OK' : 'ERROR ' . $last['msg']) . ' at ' . wp_date('j M H:i', (int) $last['t'])) : '') . '</p>';
}

function sa_svc_b64u(string $d): string
{
    return rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
}

function sa_svc_gcal_token(array $cfg): string
{
    $cached = get_transient('sa_svc_gcal_tok');
    if (is_string($cached) && $cached !== '') {
        return $cached;
    }
    $now = time();
    $claims = ['iss' => $cfg['email'], 'scope' => 'https://www.googleapis.com/auth/calendar.events', 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600];
    if ($cfg['subject'] !== '') {
        $claims['sub'] = $cfg['subject'];
    }
    $unsigned = sa_svc_b64u((string) wp_json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . sa_svc_b64u((string) wp_json_encode($claims));
    $sig = '';
    if (!openssl_sign($unsigned, $sig, $cfg['key'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Could not sign JWT (bad private key)');
    }
    $r = wp_remote_post('https://oauth2.googleapis.com/token', ['timeout' => 10, 'body' => ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $unsigned . '.' . sa_svc_b64u($sig)]]);
    $j = json_decode((string) wp_remote_retrieve_body($r), true);
    if (empty($j['access_token'])) {
        throw new RuntimeException('Token error: ' . substr((string) wp_remote_retrieve_body($r), 0, 160));
    }
    set_transient('sa_svc_gcal_tok', $j['access_token'], 3000);
    return (string) $j['access_token'];
}

/** Create/update the booking's event; delete it when cancelled or the booking is deleted. */
function sa_svc_gcal_sync(int $id, bool $deleting = false): void
{
    $cfg = sa_svc_gcal_config();
    if (!$cfg) {
        return;
    }
    $b = sa_svc_booking_data($id);
    try {
        $tok = sa_svc_gcal_token($cfg);
        $base = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($cfg['calendar']) . '/events';
        $hdr = ['Authorization' => 'Bearer ' . $tok, 'Content-Type' => 'application/json'];
        if ($deleting || $b['status'] === 'cancelled') {
            if ($b['gcal'] !== '') {
                wp_remote_request($base . '/' . rawurlencode($b['gcal']), ['method' => 'DELETE', 'headers' => $hdr, 'timeout' => 10]);
                delete_post_meta($id, '_sa_bk_gcal');
            }
        } else {
            $tz = wp_timezone();
            $start = new DateTimeImmutable($b['date'] . ' ' . $b['time'], $tz);
            $end = $start->modify('+' . (int) (sa_svc_catalog()[$b['service']]['duration'] ?? 60) . ' minutes');
            $svc = sa_svc_catalog()[$b['service']]['name'] ?? $b['service'];
            $ev = [
                'summary' => '[' . (sa_svc_statuses()[$b['status']] ?? $b['status']) . '] ' . $svc . ' — ' . $b['name'] . ' (' . $b['ref'] . ')',
                'location' => $b['mode'] === 'pickup' ? 'PICKUP: ' . $b['pickup'] : (sa_svc_locations()[$b['location']]['label'] ?? ''),
                'description' => "Vehicle: {$b['year']} {$b['make']} {$b['model']} {$b['reg']}\nPhone: {$b['phone']}\nEmail: {$b['email']}\nEstimate: {$b['estimate']}\nNotes: {$b['notes']}\nAdmin: " . admin_url('admin.php?page=sa-super-bookings'),
                'start' => ['dateTime' => $start->format(DATE_RFC3339), 'timeZone' => $tz->getName()],
                'end' => ['dateTime' => $end->format(DATE_RFC3339), 'timeZone' => $tz->getName()],
            ];
            $url = $b['gcal'] !== '' ? $base . '/' . rawurlencode($b['gcal']) : $base;
            $r = wp_remote_request($url, ['method' => $b['gcal'] !== '' ? 'PATCH' : 'POST', 'headers' => $hdr, 'body' => wp_json_encode($ev), 'timeout' => 10]);
            $j = json_decode((string) wp_remote_retrieve_body($r), true);
            if (empty($j['id'])) {
                throw new RuntimeException('Calendar API: ' . substr((string) wp_remote_retrieve_body($r), 0, 160));
            }
            update_post_meta($id, '_sa_bk_gcal', (string) $j['id']);
        }
        update_option('sa_svc_gcal_last', ['ok' => true, 'msg' => '', 't' => time()], false);
    } catch (Throwable $e) {
        update_option('sa_svc_gcal_last', ['ok' => false, 'msg' => $e->getMessage(), 't' => time()], false);
    }
}

/* ---------- FAQ (accordion + FAQPage schema) ---------- */

function sa_svc_faq_general(): array
{
    return [
        ['How long will my service take?', 'Each service shows an approximate job time (marked as an estimate). Longer jobs reserve consecutive hours in our calendar. Your technician confirms the timing when your booking is confirmed, and tells you if extra work is found.'],
        ['Can I wait at the garage, or should I drop the car off?', 'Short jobs such as wheel balancing or a battery test are often done while you wait. For longer jobs we recommend dropping the car off. Choose “Drop-off at our garage” when you book, and our team will tell you if waiting on site is practical.'],
        ['Do you collect the car from my home or office?', 'Yes, in the Nairobi area. Choose “Vehicle pickup from my address” and enter the address. Any pickup fee depends on distance and is confirmed with you before the booking is confirmed. Nothing is charged automatically.'],
        ['Where is your garage?', 'Ground Floor, Midax Plaza, Kangundo Rd, Njiru, Nairobi. The services page and the side menu have a Google Maps link.'],
        ['Do I need an appointment, or can I walk in?', 'Booking online holds a time slot for you and is the fastest way to be seen. Walk-ins depend on how busy we are that day, so we recommend booking, even if it is for later the same day.'],
        ['Is the price shown the final price?', 'No. The price shown is a guide estimate. Services marked “Quote on request” are priced for your vehicle. We give you the final price before any work starts, and nothing extra is done without your approval.'],
        ['Is there a booking fee?', 'Yes. You pay a KES ' . number_format((float) sa_svc_setting('fee_kes')) . ' booking fee online to confirm your slot. It is deducted from your final service bill, so it is not an extra cost.'],
        ['Do I pay a booking fee to fit a part I bought from you?', 'No. If the main job is fitting a part you bought from Supreme Autoparts, there is no booking fee. Tick “I bought the part from Supreme Autoparts” and enter your order number with the email or phone used on the order (no login needed), or use Book fitting on your order page. Labour is still quoted as normal. One fee-free booking per part on the order. If the part has not arrived yet, pick a date on or after its expected delivery or pickup.'],
        ['Will I get the booking fee back if I cancel?', 'Yes, if you cancel at least ' . (int) sa_svc_setting('cancel_hours') . ' hours before your appointment, or if we cancel. For later cancellations and no-shows the fee may be kept. See the Booking Terms.'],
        ['How do I find my booking?', 'Every booking has a booking number (for example SA-BK-261016-AB12). Enter it with your email under Manage booking, or open My Account → Service bookings.'],
        ['When and how do I pay?', 'You pay the booking fee online at checkout when you book. You pay the rest at the service, after approving the final quote. Accepted payment methods for the balance are confirmed with your booking.'],
        ['What parts do you use? Can I bring my own?', 'We fit parts that match your vehicle’s specification, and you can buy them from our shop. Each service page suggests matching parts. If you want to bring your own parts, mention it in the booking notes so the technician can check them first.'],
        ['Is there a warranty on the work?', 'Any parts warranty is the manufacturer’s, and the terms vary by part. A workmanship warranty, where it applies, is confirmed in writing with your booking. We don’t promise any cover that isn’t on your confirmation.'],
        ['How do I cancel or reschedule?', 'Use Manage booking (booking number + email), the links in your emails, or My Account → Service bookings. Reschedule picks a new slot and moves your booking. Fee refunds follow the cancellation window in the Booking Terms.'],
        ['How often should my car be serviced?', 'Follow the service intervals in your owner’s manual. They depend on your engine, oil type and driving conditions. Heavy traffic, dusty roads and towing usually mean shorter intervals. If you’re not sure, book a Full Service or Diagnostics and we’ll advise you.'],
        ['Will I be told if more work is needed?', 'Yes. If the technician finds something else, we contact you with the details and a price, and only go ahead if you approve it.'],
        ['What do I need to bring?', 'Bring the vehicle, its keys (including the wheel-lock key if you have one), and your service book if you have it. Remove valuables before drop-off or pickup.'],
    ];
}

function sa_svc_faq_for(string $slug): array
{
    $s = sa_svc_catalog()[$slug] ?? null;
    if (!$s) {
        return [];
    }
    $n = $s['name'];
    $ke = sa_svc_price_label($s, 'KE');
    $us = sa_svc_price_label($s, 'US');
    return [
        ['How much does ' . strtolower($n) . ' cost?', 'Kenya: ' . $ke . '. US: ' . $us . '. ' . sa_svc_source_note($s, 'KE') . ' The final price for your vehicle is confirmed before work starts.'],
        ['How long does ' . strtolower($n) . ' take?', 'About ' . trim(str_replace(['≈', '(estimate)'], '', sa_svc_duration_label($slug))) . ' for a typical car. This is an estimate: the technician confirms the time for your vehicle when your booking is confirmed.'],
        ['What is included?', implode('; ', $s['includes']) . '.'],
        ['Can I buy the parts from you?', 'Yes. The parts suggested on this page come from our shop catalog. Tell us your vehicle when you book and we’ll check that they fit before fitting them.'],
    ];
}

function sa_svc_faq_html(array $faqs, string $title): string
{
    if (!$faqs) {
        return '';
    }
    $o = '<section class="sa-svc-faq"><h2>' . esc_html($title) . '</h2>';
    $schema = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => []];
    foreach ($faqs as $i => [$q, $a]) {
        $o .= '<details class="sa-svc-faq__item"' . ($i === 0 ? ' open' : '') . '><summary>' . esc_html($q) . '</summary><div>' . esc_html($a) . '</div></details>';
        $schema['mainEntity'][] = ['@type' => 'Question', 'name' => $q, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a]];
    }
    return $o . '</section><script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
}


/* ---------- Cross-link: product page → book installation ---------- */

function sa_svc_for_product(int $pid): string
{
    $t = strtolower(get_the_title($pid) . ' ' . implode(' ', wp_get_post_terms($pid, 'product_cat', ['fields' => 'slugs']) ?: []));
    $map = ['pad' => 'brake-pads', 'rotor' => 'brake-pads', 'brake' => 'brake-pads', 'battery' => 'battery', 'shock' => 'suspension', 'strut' => 'suspension', 'coilover' => 'suspension', 'spring' => 'suspension', 'suspension' => 'suspension', 'tire' => 'tyre-rotation', 'wheel' => 'wheel-balancing', 'compressor' => 'ac-service', 'a/c' => 'ac-service', 'oil filter' => 'oil-change', 'filter' => 'full-service', 'spark plug' => 'full-service', 'sensor' => 'diagnostics', 'tie rod' => 'wheel-alignment', 'control arm' => 'wheel-alignment'];
    foreach ($map as $k => $svc) {
        if (str_contains($t, $k)) {
            return $svc;
        }
    }
    return 'diagnostics';
}

add_action('woocommerce_single_product_summary', static function (): void {
    global $product;
    if (!$product) {
        return;
    }
    if (function_exists('sa_geo_is_kenya') && !sa_geo_is_kenya()) {
        // Fitting happens at our Nairobi garage only; outside Kenya offer fitment help instead.
        $sku = (string) $product->get_sku();
        $msg = 'Hi, I need fitment help for ' . $product->get_name() . ($sku !== '' ? ' (part # ' . $sku . ')' : '') . '. My vehicle is: ';
        echo '<div class="sa-svc-fit">' . sa_svc_icon('service') . '<div><strong>Not sure it fits?</strong><span>'
            . 'Send us your year, make, model and VIN and we will check the part number for you before you order. Fitting is done by your own mechanic or shop.</span></div>'
            . '<a class="sa-svc-btn sa-svc-btn--sm sa-svc-btn--primary" href="' . esc_url('https://wa.me/19174375121?text=' . rawurlencode($msg)) . '" rel="noopener">Ask about fitment</a></div>';
        return;
    }
    $svc = sa_svc_for_product((int) $product->get_id());
    $name = sa_svc_catalog()[$svc]['name'] ?? 'installation';
    $fo = function_exists('sa_fw_customer_order_for_product') ? sa_fw_customer_order_for_product((int) $product->get_id()) : null;
    $direct = !$fo && function_exists('sa_fit_add_url') && $product->is_purchasable() && $product->is_in_stock() && !$product->is_type('variable');
    $url = $fo ? sa_fw_book_url($fo, (int) $product->get_id()) : ($direct ? sa_fit_add_url((int) $product->get_id()) : add_query_arg(['service' => $svc, 'part' => (int) $product->get_id()], home_url('/book-service/')));
    echo '<div class="sa-svc-fit">' . sa_svc_icon('service') . '<div><strong>Need it fitted?</strong><span>'
        . ($fo ? 'You bought this from us (order #' . esc_html($fo->get_order_number()) . '). Book fitting with no booking fee.' : ($direct ? 'Buy it with fitting at our garage. No booking fee; labour quoted at the garage.' : 'Fitting at our garage. No booking fee for parts bought from us.')) . '</span></div>'
        . '<a class="sa-svc-btn sa-svc-btn--sm sa-svc-btn--primary" href="' . esc_url($url) . '">Book installation</a></div>';
}, 36);

/* ---------- Unified My Account: orders + bookings on dashboard ---------- */

add_action('woocommerce_account_dashboard', static function (): void {
    $uid = get_current_user_id();
    $email = wp_get_current_user()->user_email;
    $ids = get_posts(['post_type' => 'sa_booking', 'numberposts' => 3, 'fields' => 'ids', 'meta_query' => ['relation' => 'OR', ['key' => '_sa_bk_user', 'value' => (string) $uid], ['key' => '_sa_bk_email', 'value' => $email]]]);
    $orders = function_exists('wc_get_orders') ? wc_get_orders(['customer_id' => $uid, 'limit' => 3]) : [];
    echo '<div class="sa-svc sa-acct-unified"><div class="sa-acct-tiles">'
        . '<a href="' . esc_url(wc_get_account_endpoint_url('orders')) . '">' . sa_svc_icon('box') . '<strong>Orders</strong><small>' . count($orders) . ' recent</small></a>'
        . '<a href="' . esc_url(wc_get_account_endpoint_url('bookings')) . '">' . sa_svc_icon('calendar') . '<strong>Service bookings</strong><small>' . count($ids) . ' recent</small></a>'
        . '<a href="' . esc_url(wc_get_account_endpoint_url('bookings') . '#vehicles') . '">' . sa_svc_icon('car') . '<strong>My Garage</strong><small>' . count(sa_svc_vehicles($uid)) . ' vehicles</small></a>'
        . '<a href="' . esc_url(sa_svc_book_url()) . '" class="is-hl">' . sa_svc_icon('service') . '<strong>Book a service</strong><small>Pick a slot</small></a></div>';
    if ($orders) {
        echo '<h3>Recent orders</h3><ul class="sa-acct-list">';
        foreach ($orders as $o) {
            echo '<li><a href="' . esc_url($o->get_view_order_url()) . '">#' . esc_html($o->get_order_number()) . '</a> · ' . esc_html(wc_get_order_status_name($o->get_status())) . ' · ' . wp_kses_post($o->get_formatted_order_total()) . '</li>';
        }
        echo '</ul>';
    }
    if ($ids) {
        echo '<h3>Upcoming & recent bookings</h3><div class="sa-svc-bks">';
        foreach ($ids as $id) {
            sa_svc_render_booking_card(sa_svc_booking_data((int) $id));
        }
        echo '</div>';
    }
    echo '</div>';
}, 5);

/* ---------- Reminders (WP-Cron, ~24h before) ---------- */

add_action('init', static function (): void {
    if (!wp_next_scheduled('sa_svc_reminders')) {
        wp_schedule_event(time() + 600, 'hourly', 'sa_svc_reminders');
    }
});
add_action('sa_svc_reminders', static function (): void {
    $tz = wp_timezone();
    $tomorrow = (new DateTimeImmutable('now', $tz))->modify('+1 day')->format('Y-m-d');
    $ids = get_posts(['post_type' => 'sa_booking', 'numberposts' => 100, 'fields' => 'ids', 'meta_query' => [
        ['key' => '_sa_bk_date', 'value' => $tomorrow],
        ['key' => '_sa_bk_status', 'value' => ['pending', 'confirmed'], 'compare' => 'IN'],
        ['key' => '_sa_bk_reminded', 'compare' => 'NOT EXISTS'],
    ]]);
    foreach ($ids as $id) {
        update_post_meta((int) $id, '_sa_bk_reminded', time());
        sa_svc_send_emails((int) $id, 'reminder');
    }
});


/* ---------- Booking number, fee, settings ---------- */

function sa_svc_setting(string $k)
{
    $d = ['fee_kes' => 1000, 'cancel_hours' => 24];
    $o = get_option('sa_svc_settings', []);
    return is_array($o) && isset($o[$k]) ? $o[$k] : $d[$k];
}

function sa_svc_settings_form(): string
{
    return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="notice" style="padding:10px 12px;display:flex;gap:14px;align-items:center;flex-wrap:wrap">'
        . '<input type="hidden" name="action" value="sa_svc_settings">' . wp_nonce_field('sa_svc_settings', '_wpnonce', true, false)
        . '<strong>Booking settings</strong><label>Booking fee (KES) <input type="number" min="0" name="fee_kes" value="' . (int) sa_svc_setting('fee_kes') . '" style="width:90px"></label>'
        . '<label>Free-cancellation window (hours before appointment) <input type="number" min="0" max="336" name="cancel_hours" value="' . (int) sa_svc_setting('cancel_hours') . '" style="width:70px"></label>'
        . '<button class="button">Save settings</button><small>Defaults: KES 1,000 and 24 h (owner can change).</small></form>';
}

add_action('admin_post_sa_svc_settings', static function (): void {
    if (!current_user_can('manage_woocommerce') || !wp_verify_nonce((string) ($_POST['_wpnonce'] ?? ''), 'sa_svc_settings')) {
        wp_die('Not allowed', 403);
    }
    update_option('sa_svc_settings', ['fee_kes' => max(0, (int) ($_POST['fee_kes'] ?? 1000)), 'cancel_hours' => max(0, min(336, (int) ($_POST['cancel_hours'] ?? 24)))]);
    wp_safe_redirect(admin_url('admin.php?page=sa-super-bookings'));
    exit;
});

function sa_svc_new_ref(): string
{
    $day = wp_date('ymd');
    do {
        $ref = 'SA-BK-' . $day . '-' . strtoupper(wp_generate_password(4, false, false));
        $dupe = get_posts(['post_type' => 'sa_booking', 'numberposts' => 1, 'fields' => 'ids', 'post_status' => 'any', 'meta_key' => '_sa_bk_ref', 'meta_value' => $ref]);
    } while ($dupe);
    return $ref;
}

function sa_svc_fee_usd(): ?float
{
    $kes = (float) sa_svc_setting('fee_kes');
    if (!class_exists('SA_FX_Rates')) {
        return null;
    }
    $u = SA_FX_Rates::convert_local_to_usd($kes, 'KES');
    return $u === null ? null : round($u, 2);
}

function sa_svc_fee_statuses(): array
{
    return ['unpaid' => 'Unpaid', 'paid' => 'Paid', 'applied' => 'Applied to bill', 'refund_due' => 'Refund due', 'refunded' => 'Refunded', 'forfeited' => 'Forfeited (late cancel / no-show)', 'waived' => 'Waived (part bought from us)'];
}

function sa_svc_fee_label(array $b): string
{
    if ($b['fee'] === 'waived' && function_exists('sa_fw_label')) {
        return 'KES 0 — ' . sa_fw_label((int) $b['id']);
    }
    $kes = (int) ($b['fee_kes'] ?: sa_svc_setting('fee_kes'));
    return 'KES ' . number_format($kes) . ' — ' . (sa_svc_fee_statuses()[$b['fee']] ?? $b['fee']);
}

function sa_svc_within_window(array $b): bool
{
    $tz = wp_timezone();
    $at = DateTimeImmutable::createFromFormat('Y-m-d H:i', $b['date'] . ' ' . $b['time'], $tz);
    return $at && ($at->getTimestamp() - time()) >= (int) sa_svc_setting('cancel_hours') * HOUR_IN_SECONDS;
}

function sa_svc_fee_product_id(): int
{
    $id = (int) get_option('sa_svc_fee_product', 0);
    if ($id && get_post_status($id) === 'publish') {
        return $id;
    }
    if (!class_exists('WC_Product_Simple')) {
        return 0;
    }
    $p = new WC_Product_Simple();
    $p->set_name('Car service booking fee');
    $p->set_slug('car-service-booking-fee');
    $p->set_status('publish');
    $p->set_catalog_visibility('hidden');
    $p->set_virtual(true);
    $p->set_sold_individually(true);
    $p->set_regular_price((string) (sa_svc_fee_usd() ?? 0));
    $p->set_description('Booking fee for a Supreme Autoparts car service appointment. Credited against the final service bill.');
    $id = (int) $p->save();
    update_option('sa_svc_fee_product', $id);
    return $id;
}

/**
 * Front-end route /book-service/pay/?b=ID&k=KEY (the key is the booking's secret token).
 * Creates (or reuses) a guest-payable WooCommerce order for the fee and goes straight to its
 * order-pay page (order key in URL). No login, no lookup. Valid until the booking is released.
 */
function sa_svc_route_pay(): void
{
    $id = (int) ($_GET['b'] ?? 0);
    $k = (string) ($_GET['k'] ?? '');
    $b = $id && get_post_type($id) === 'sa_booking' ? sa_svc_booking_data($id) : null;
    if (!$b || $b['key'] === '' || !hash_equals($b['key'], $k)) {
        wp_safe_redirect(home_url('/manage-booking/'));
        exit;
    }
    $view = add_query_arg(['b' => $id, 'k' => $b['key']], home_url('/book-service/confirmed/'));
    if ($b['fee'] !== 'unpaid' || $b['status'] !== 'pending' || !function_exists('wc_create_order')) {
        wp_safe_redirect(add_query_arg('sa_pay', $b['fee'] !== 'unpaid' ? 'paid' : 'closed', $view));
        exit;
    }
    $oid = (int) get_post_meta($id, '_sa_bk_pay_order', true);
    $o = $oid ? wc_get_order($oid) : null;
    if (!$o || !$o->needs_payment()) {
        $usd = sa_svc_fee_usd();
        $pid = sa_svc_fee_product_id();
        $prod = wc_get_product($pid);
        if ($usd === null || !$prod) {
            wp_safe_redirect(add_query_arg('sa_pay', 'unavailable', $view));
            exit;
        }
        $o = wc_create_order(['customer_id' => 0, 'created_via' => 'sa_booking']);
        $item_id = $o->add_product($prod, 1, ['subtotal' => $usd, 'total' => $usd]);
        $it = $o->get_item($item_id);
        if ($it) {
            $it->add_meta_data('_sa_booking', $id, true);
            $it->add_meta_data('Booking number', $b['ref'], true);
            $it->save();
        }
        $parts = preg_split('/\s+/', trim($b['name']), 2);
        $o->set_billing_first_name($parts[0] ?? '');
        $o->set_billing_last_name($parts[1] ?? '');
        $o->set_billing_email($b['email']);
        $o->set_billing_phone($b['phone']);
        $o->set_billing_country($b['location'] === 'usa' ? 'US' : 'KE');
        $o->set_billing_city($b['location'] === 'usa' ? '' : 'Nairobi');
        // customer_id stays 0 so the order-pay link works logged-out (key in URL is the token).
        $o->update_meta_data('_sa_booking_fee_for', $id);
        $o->calculate_totals(false);
        $o->set_status('pending');
        $o->add_order_note('Booking fee order for ' . $b['ref'] . ' (created from the pay link).');
        $o->save();
        update_post_meta($id, '_sa_bk_pay_order', $o->get_id());
    }
    if (function_exists('WC') && WC()->session) {
        WC()->session->set('sa_pr_last_order', $o->get_id());
    }
    wp_safe_redirect($o->get_checkout_payment_url());
    exit;
}

/* Fee orders stop being payable once their booking is paid, released or cancelled (link expiry). */
add_filter('woocommerce_order_needs_payment', static function ($needs, $order) {
    if ($needs && $order instanceof WC_Order && ($bid = (int) $order->get_meta('_sa_booking_fee_for'))) {
        if (get_post_meta($bid, '_sa_bk_fee', true) !== 'unpaid' || get_post_meta($bid, '_sa_bk_status', true) !== 'pending') {
            return false;
        }
    }
    return $needs;
}, 20, 2);


add_action('woocommerce_before_calculate_totals', static function ($cart): void {
    $usd = sa_svc_fee_usd();
    foreach ($cart->get_cart() as $item) {
        if (!empty($item['sa_booking']) && $usd !== null) {
            $item['data']->set_price($usd);
        }
    }
}, 20);

add_filter('woocommerce_get_item_data', static function (array $data, array $item): array {
    if (!empty($item['sa_booking_ref'])) {
        $data[] = ['name' => 'Booking number', 'value' => $item['sa_booking_ref']];
        $data[] = ['name' => 'Fee', 'value' => 'KES ' . number_format((float) sa_svc_setting('fee_kes')) . ' (credited to your service bill)'];
    }
    return $data;
}, 10, 2);

/* Booking fee has no product photo: show the store logo in cart / checkout rows. */
add_filter('woocommerce_cart_item_thumbnail', static function ($thumb, $item) {
    if (empty($item['sa_booking']) || !function_exists('sa_theme_logo_url')) {
        return $thumb;
    }
    return '<img class="sa-fee-thumb sa-checkout-line__thumb sa-cart-line__img" src="' . esc_url(SA_THEME_URI . '/assets/emblem.png?v=emblem2') . '" alt="Car service booking" width="80" height="80" />';
}, 30, 2);

add_action('woocommerce_checkout_create_order_line_item', static function ($line, $key, $values): void {
    if (!empty($values['sa_booking'])) {
        $line->add_meta_data('_sa_booking', (int) $values['sa_booking'], true);
        $line->add_meta_data('Booking number', (string) $values['sa_booking_ref'], true);
    }
}, 10, 3);

function sa_svc_order_paid(int $order_id): void
{
    $o = wc_get_order($order_id);
    if (!$o) {
        return;
    }
    foreach ($o->get_items() as $it) {
        $bid = (int) $it->get_meta('_sa_booking');
        if ($bid && get_post_type($bid) === 'sa_booking' && get_post_meta($bid, '_sa_bk_fee', true) === 'unpaid') {
            update_post_meta($bid, '_sa_bk_fee', 'paid');
            update_post_meta($bid, '_sa_bk_fee_order', $order_id);
            if (get_post_meta($bid, '_sa_bk_status', true) === 'pending') {
                update_post_meta($bid, '_sa_bk_status', 'confirmed');
            }
            if (!function_exists('sa_bk_invoice_email')) {
                sa_svc_send_emails($bid, 'status'); // otherwise the PAID invoice is the confirmation
            }
            sa_svc_gcal_sync($bid);
        }
    }
}
add_action('woocommerce_payment_complete', 'sa_svc_order_paid');
add_action('woocommerce_order_status_processing', 'sa_svc_order_paid');
add_action('woocommerce_order_status_completed', 'sa_svc_order_paid');

/* Thank-you page: link back to the booking. */
add_action('woocommerce_thankyou', static function ($order_id): void {
    $o = wc_get_order($order_id);
    if (!$o) return;
    foreach ($o->get_items() as $it) {
        $bid = (int) $it->get_meta('_sa_booking');
        if ($bid) {
            $b = sa_svc_booking_data($bid);
            echo '<div class="sa-svc-alert sa-svc-alert--ok">Booking fee received for <strong>' . esc_html($b['ref']) . '</strong>. <a href="' . esc_url(add_query_arg(['b' => $bid, 'k' => $b['key']], home_url('/book-service/confirmed/'))) . '">View your booking</a></div>';
        }
    }
}, 5);

/* Release unpaid holds after 2 hours. */
add_action('sa_svc_reminders', static function (): void {
    $ids = get_posts(['post_type' => 'sa_booking', 'numberposts' => 100, 'fields' => 'ids', 'date_query' => [['before' => '2 hours ago']], 'meta_query' => [['key' => '_sa_bk_fee', 'value' => 'unpaid'], ['key' => '_sa_bk_status', 'value' => 'pending']]]);
    foreach ($ids as $id) {
        if (apply_filters('sa_svc_skip_auto_release', false, (int) $id)) {
            continue;
        }
        update_post_meta((int) $id, '_sa_bk_status', 'cancelled');
        update_post_meta((int) $id, '_sa_bk_notes', 'Auto-released: booking fee not paid within 2 hours. ' . get_post_meta((int) $id, '_sa_bk_notes', true));
        sa_svc_gcal_sync((int) $id);
    }
}, 20);

/* ---------- Manage booking lookup ---------- */

function sa_svc_route_manage_lookup(): void
{
    if (!wp_verify_nonce((string) ($_POST['_wpnonce'] ?? ''), 'sa_svc_manage')) {
        return;
    }
    $ref = strtoupper(sanitize_text_field(wp_unslash((string) $_POST['ref'])));
    $email = sanitize_email(wp_unslash((string) ($_POST['email'] ?? '')));
    $ids = get_posts(['post_type' => 'sa_booking', 'numberposts' => 1, 'fields' => 'ids', 'meta_query' => [['key' => '_sa_bk_ref', 'value' => $ref], ['key' => '_sa_bk_email', 'value' => $email]]]);
    if ($ids) {
        $b = sa_svc_booking_data((int) $ids[0]);
        wp_safe_redirect(add_query_arg(['b' => $b['id'], 'k' => $b['key']], home_url('/book-service/confirmed/')));
        exit;
    }
    wp_safe_redirect(add_query_arg('nf', '1', home_url('/manage-booking/')));
    exit;
}

function sa_svc_render_manage(): void
{
    echo '<div class="sa-container sa-svc-book"><h1>Manage booking</h1><p class="sa-svc-lead">Enter your booking number (e.g. SA-BK-261016-AB12) and the email address you booked with to view, pay, reschedule or cancel your booking.</p>';
    if (isset($_GET['nf'])) {
        echo '<div class="sa-svc-alert">We couldn’t find a booking with that number and email.</div>';
    }
    echo '<form method="post" class="sa-svc-form sa-svc-step" style="display:grid;gap:12px;max-width:520px">' . wp_nonce_field('sa_svc_manage', '_wpnonce', true, false)
        . '<label>Booking number <input name="ref" required placeholder="SA-BK-YYMMDD-XXXX"></label><label>Email <input type="email" name="email" required></label>'
        . '<button class="sa-svc-btn sa-svc-btn--primary">Find my booking</button></form></div>';
}

/* ---------- Booking Terms & Policies ---------- */

function sa_svc_terms(): array
{
    $fee = number_format((float) sa_svc_setting('fee_kes'));
    $h = (int) sa_svc_setting('cancel_hours');
    return [
        ['Who we are', 'Car services are provided by Supreme Autoparts (Standard Driveworks, BN-93S9AZEJ), at Ground Floor, Midax Plaza, Kangundo Rd, Njiru, Nairobi. These terms apply together with our store Terms of Service.'],
        ['Booking fee', "A booking fee of KES {$fee} is paid online through our secure checkout to confirm your appointment. The fee is not an extra charge: it is deducted from your final service bill. Your booking is only confirmed once the fee is paid, and unpaid bookings are released after 2 hours. There is no booking fee for fitting a part you bought from us (see below)."],
        ['No booking fee for parts bought from us', 'If the main job of a booking is fitting or installing a part you bought from Supreme Autoparts, there is no booking fee and the booking is confirmed straight away. The order must be paid (processing, on hold or completed), and the email or phone on the order must match. Each part on an order gets one fee-free fitting booking. The waiver covers only the booking fee: labour and any other work are quoted as normal, and other services added to the same visit are included with no fee. If the part is still on its way, the appointment must be on or after its expected delivery or pickup date. If the order is later cancelled or refunded, the waiver ends and the normal booking fee is due to keep the appointment. We may correct a waiver given in error.'],
        ['Cancelling and rescheduling', "You can cancel or reschedule online with your booking number and email. If you cancel at least {$h} hours before your appointment, or if we cancel for any reason, the booking fee is refunded to your original payment method. If you cancel later than that, or you don't attend, the fee may be kept. You can reschedule once without charge within the same window. The {$h}-hour window is the current setting and may be updated; the window shown when you book applies."],
        ['Late arrival and no-shows', 'Please arrive at your slot time. If you are more than 30 minutes late, we may need to move you to the next free slot. If you neither arrive nor contact us, the booking counts as a no-show and the booking fee may be kept.'],
        ['Estimates, final price and extra work', 'Prices shown online are guide estimates or “quote on request”. Before starting, we give you the final price for your vehicle. We will not do any extra work, or go above the agreed price, without your approval by phone, WhatsApp or in person.'],
        ['Customer-supplied parts', 'You may bring your own parts if you say so in the booking notes. We check that they are suitable before fitting them. We can refuse parts that look unsafe, used or wrong for the vehicle. Parts you supply are not covered by any parts warranty from us, and any workmanship cover for that job is confirmed on your job card.'],
        ['Drop-off, pickup and liability', 'For pickup bookings, any pickup fee is confirmed with you before the booking is confirmed. Our driver checks the vehicle’s condition with you at handover. Please tell us about existing damage or warning lights. Our responsibility for the vehicle while it is in our care is set out on your job card.'],
        ['Keys and personal belongings', 'Please hand over all keys needed for the work, including any wheel-lock key. Remove valuables, cash and documents before drop-off or pickup. We cannot accept responsibility for personal items left in the vehicle.'],
        ['Payment', 'The booking fee is paid online at checkout. The balance is paid when the work is finished, before the vehicle is released. Accepted payment methods for the balance are confirmed with your booking.'],
        ['Uncollected vehicles', 'Please collect your vehicle on the day we tell you it is ready. If a vehicle is not collected, storage arrangements and any charges are confirmed with you on your job card.'],
        ['Warranties', 'Parts are covered by their manufacturer’s warranty, where one applies. Any workmanship warranty, including its length and what it covers, is confirmed on your job card. No other warranty or guarantee is implied by these terms.'],
        ['Your data and privacy', 'We use your name, contact details, vehicle details and booking history to manage your appointment, send confirmations and reminders, and keep service records. Booking events may be added to our internal calendar. We do not sell your data. See our Privacy Policy for your rights.'],
        ['Changes and contact', 'We may update these terms; the version shown when you book applies to that booking. Questions: WhatsApp +1 917 437 5121, +254 714 498 451, or calvin@supremeautoparts.co.ke.'],
    ];
}

function sa_svc_render_terms(): void
{
    echo '<div class="sa-container sa-svc-book sa-svc-terms"><h1>Booking Terms &amp; Policies</h1><p class="sa-svc-lead">Plain-language terms for car service bookings. Last updated ' . esc_html(wp_date('j F Y', (int) get_option('sa_svc_terms_date', strtotime('2026-10-14')))) . '.</p><ol>';
    foreach (sa_svc_terms() as [$t, $b]) {
        echo '<li><h2>' . esc_html($t) . '</h2><p>' . esc_html($b) . '</p></li>';
    }
    echo '</ol><p><a class="sa-svc-btn sa-svc-btn--primary" href="' . esc_url(sa_svc_book_url()) . '">Book a service</a></p></div>';
}

/* Booking fee is a fixed deposit: never discounted by coupons. */
add_filter('woocommerce_coupon_is_valid_for_product', static function ($valid, $product) {
    return $product && (int) $product->get_id() === (int) get_option('sa_svc_fee_product', 0) ? false : $valid;
}, 20, 2);


function sa_svc_cancel_reasons(): array
{
    return ['schedule' => 'Schedule changed', 'fixed' => 'Problem fixed / no longer needed', 'elsewhere' => 'Booked elsewhere', 'price' => 'Price or estimate', 'vehicle' => 'Vehicle sold or unavailable', 'other' => 'Other'];
}

function sa_svc_admin_cr_box(int $id, array $b): string
{
    if ($b['status'] !== 'cancel_requested') {
        return '';
    }
    $r = (array) get_post_meta($id, '_sa_bk_cancel_req', true);
    $m = ['mpesa' => 'M-Pesa', 'card' => 'Card', 'bank' => 'Bank'][$r['method'] ?? ''] ?? '';
    return '<div style="background:#fff7e6;border:1px solid #f5a623;border-radius:8px;padding:8px;margin-bottom:6px"><strong>Cancellation request</strong> (' . esc_html(wp_date('j M H:i', (int) ($r['t'] ?? 0))) . ', ' . (($r['within'] ?? '') === 'yes' ? 'within window' : 'LATE: outside window') . ')<br>'
        . 'Reason: ' . esc_html((string) ($r['reason'] ?? '')) . ($r['details'] ?? '' ? ' — ' . esc_html((string) $r['details']) : '') . '<br>Refund: ' . esc_html($m . ' ' . ($r['refund_details'] ?? '')) . '<br>Contact: ' . esc_html((string) ($r['contact'] ?? ''))
        . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;gap:6px;margin-top:6px;flex-wrap:wrap"><input type="hidden" name="action" value="sa_svc_admin"><input type="hidden" name="b" value="' . $id . '">' . wp_nonce_field('sa_svc_admin', '_wpnonce', true, false)
        . '<input name="cr_note" placeholder="Note to customer (optional)" style="width:200px"><button class="button button-primary" name="cr_decision" value="approve">Approve & mark refunded</button><button class="button" name="cr_decision" value="decline">Decline</button></form>'
        . '<small>Approving marks the fee refunded. Send the actual refund via M-Pesa, bank, or the Woo order refund.</small></div>';
}
