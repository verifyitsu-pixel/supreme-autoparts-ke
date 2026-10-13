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

const SA_SVC_VER = '1';
const SA_SVC_SLOT_CAPACITY = 2;

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
        'cancelled'   => 'Cancelled',
    ];
}

function sa_svc_locations(): array
{
    return [
        'nairobi' => ['label' => 'Nairobi, Kenya — Supreme Autoparts service partner', 'region' => 'KE', 'note' => 'Exact workshop address is confirmed by our team with your booking.'],
        'kenya-mobile' => ['label' => 'Nairobi — mobile service at your location', 'region' => 'KE', 'note' => 'Selected services only; our team confirms availability.'],
        'usa' => ['label' => 'United States — partner garage near you', 'region' => 'US', 'note' => 'We match you with a partner garage near your ZIP code and confirm by email.'],
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
        return 'Reference price published by AutoXpress Kenya (Oct 2026). Final price confirmed before work starts.';
    }
    if ($region === 'US' && !empty($svc['usd'])) {
        return 'US national average range (RepairPal, Sept 2026). Your partner garage confirms the exact price before work starts.';
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
        'notes' => $m('notes'), 'estimate' => $m('estimate'), 'user' => (int) $m('user'), 'key' => $m('key'),
        'created' => get_post_time('U', true, $id),
    ];
}

function sa_svc_slot_times(): array
{
    return ['08:00', '09:00', '10:00', '11:00', '12:00', '14:00', '15:00', '16:00'];
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
        $t = (string) get_post_meta($id, '_sa_bk_time', true);
        $count[$t] = ($count[$t] ?? 0) + 1;
    }
    return $count;
}

/** Available slots: Mon–Sat, future only (store timezone), capacity per slot/location. */
function sa_svc_available_slots(string $date, string $location): array
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
    foreach (sa_svc_slot_times() as $t) {
        if ((int) $d->format('w') === 6 && $t >= '14:00') {
            continue; // Saturday half-day.
        }
        $slot = DateTimeImmutable::createFromFormat('Y-m-d H:i', $date . ' ' . $t, $tz);
        if ($slot <= $now->modify('+2 hours')) {
            continue;
        }
        if (($taken[$t] ?? 0) >= SA_SVC_SLOT_CAPACITY) {
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
    wp_send_json_success(['slots' => sa_svc_available_slots($date, $loc)]);
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
    if (!in_array($time, sa_svc_available_slots($date, $loc), true)) $fail('That time slot is no longer available. Please pick another.');
    if (!is_email($email)) $fail('Please enter a valid email address.');
    if ($p('name') === '' || $p('phone') === '') $fail('Please enter your name and phone number.');
    if ($p('make') === '' || $p('model') === '') $fail('Please enter your vehicle make and model.');

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

    $ref = 'SVC-' . strtoupper(wp_generate_password(6, false, false));
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
        'estimate' => $estimate, 'user' => (string) $user, 'key' => wp_generate_password(20, false, false),
    ];
    foreach ($meta as $k => $v) {
        update_post_meta((int) $id, '_sa_bk_' . $k, $v);
    }
    if ($user && !empty($_POST['save_vehicle'])) {
        sa_svc_save_vehicle($user, ['make' => $meta['make'], 'model' => $meta['model'], 'year' => $meta['year'], 'reg' => $meta['reg']]);
    }
    sa_svc_send_emails((int) $id, 'created');
    wp_safe_redirect(add_query_arg(['b' => (int) $id, 'k' => $meta['key']], home_url('/book-service/confirmed/')));
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
    update_post_meta($id, '_sa_bk_status', 'cancelled');
    sa_svc_send_emails($id, 'cancelled');
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
    ];
    $rows = [
        'Reference' => $b['ref'], 'Service' => $svc['name'] . ($b['option'] ? ' — ' . $b['option'] : ''),
        'When' => $when, 'Where' => $loc, 'Vehicle' => trim($b['year'] . ' ' . $b['make'] . ' ' . $b['model'] . ' ' . $b['reg']),
        'Estimate' => $b['estimate'] . ' (pay at service)', 'Status' => $status,
    ];
    $html = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;color:#222">'
        . '<h2 style="color:#0b1f3a">Supreme Autoparts — Car Service</h2><p>Hi ' . esc_html($b['name']) . ',</p><p>'
        . ($event === 'created' ? 'Thanks for booking. Our team will confirm your appointment shortly.' : ($event === 'cancelled' ? 'Your booking has been cancelled.' : 'Your booking status has changed.'))
        . '</p><table style="border-collapse:collapse;width:100%">';
    foreach ($rows as $k => $v) {
        $html .= '<tr><td style="padding:6px 8px;border-bottom:1px solid #eee;color:#666">' . esc_html($k) . '</td><td style="padding:6px 8px;border-bottom:1px solid #eee"><strong>' . esc_html($v) . '</strong></td></tr>';
    }
    $html .= '</table><p><a href="' . esc_url(add_query_arg(['b' => $id, 'k' => $b['key']], home_url('/book-service/confirmed/'))) . '">View or manage your booking</a></p></div>';
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    wp_mail($b['email'], $subjects[$event] ?? $subjects['status'], $html, $headers);
    if ($event !== 'status') {
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
    global $wp_query;
    $wp_query->is_404 = false;
    $wp_query->is_page = true;
    status_header(200);
    $titles = ['index' => 'Car Services & Repairs', 'book' => 'Book a Service', 'confirmed' => 'Booking confirmed'];
    $title = $view === 'detail' ? sa_svc_catalog()[(string) get_query_var('sa_svc')]['name'] : $titles[$view] ?? 'Services';
    add_filter('pre_get_document_title', static fn() => $title . ' | ' . get_bloginfo('name'), 99);
    if ($view === 'confirmed' || $view === 'book') {
        add_filter('wp_robots', static fn(array $r) => array_merge($r, ['noindex' => true]));
    }
    get_header();
    echo '<main id="main" class="sa-svc">';
    switch ($view) {
        case 'detail': sa_svc_render_detail((string) get_query_var('sa_svc')); break;
        case 'book': sa_svc_render_book(); break;
        case 'confirmed': sa_svc_render_confirmed(); break;
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
          <p class="sa-svc-eyebrow">Parts + service, one place</p>
          <h1>Book trusted car service in minutes</h1>
          <p class="sa-svc-lead">Oil changes, brakes, alignment, AC, batteries, diagnostics and full servicing. Pick a time slot online, pay at service, and get the right parts from our shop.</p>
          <div class="sa-svc-cta">
            <a class="sa-svc-btn sa-svc-btn--primary" href="<?php echo esc_url(sa_svc_book_url()); ?>"><?php echo sa_svc_icon('calendar'); ?> Book a service</a>
            <a class="sa-svc-btn sa-svc-btn--ghost" href="<?php echo esc_url(function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/shop/')); ?>">Shop parts</a>
          </div>
          <ul class="sa-svc-points"><li><?php echo sa_svc_icon('check'); ?>Live time slots</li><li><?php echo sa_svc_icon('check'); ?>Written quote before work</li><li><?php echo sa_svc_icon('check'); ?>Pay at service</li></ul>
        </div>
        <ol class="sa-svc-steps">
          <li><span>1</span><strong>Choose a service</strong><em>Transparent published pricing</em></li>
          <li><span>2</span><strong>Add your vehicle</strong><em>Make, model, year</em></li>
          <li><span>3</span><strong>Pick a slot</strong><em>Nairobi or a US partner garage</em></li>
          <li><span>4</span><strong>Get confirmed</strong><em>Email confirmation + tracking</em></li>
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
                <span class="sa-svc-dur">~<?php echo (int) $s['duration']; ?> min</span>
              </div>
              <div class="sa-svc-card__act">
                <a class="sa-svc-btn sa-svc-btn--sm sa-svc-btn--primary" href="<?php echo esc_url(sa_svc_book_url($slug)); ?>">Book</a>
                <a class="sa-svc-btn sa-svc-btn--sm sa-svc-btn--ghost" href="<?php echo esc_url(sa_svc_url($slug)); ?>">Details</a>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <p class="sa-svc-fine">Kenya prices are reference prices published by AutoXpress Kenya; US prices are national average ranges from RepairPal. Services without a published price are quoted for your vehicle. You always approve the final price before work starts.</p>
    </section>
    <?php
}

function sa_svc_related_parts(string $term, int $n = 4): array
{
    if (!function_exists('wc_get_products') || $term === '') {
        return [];
    }
    $key = 'sa_svc_parts_' . md5($term);
    $ids = get_transient($key);
    if (!is_array($ids)) {
        $q = new WP_Query(['post_type' => 'product', 'post_status' => 'publish', 's' => $term, 'posts_per_page' => 12, 'fields' => 'ids', 'no_found_rows' => true]);
        $ids = [];
        foreach ($q->posts as $pid) {
            if (has_post_thumbnail($pid)) {
                $ids[] = (int) $pid;
            }
        }
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
          <p class="sa-svc-muted">Approx. <?php echo (int) $s['duration']; ?> minutes · Pay at service</p>
          <a class="sa-svc-btn sa-svc-btn--primary sa-svc-btn--block" href="<?php echo esc_url(sa_svc_book_url($slug)); ?>"><?php echo sa_svc_icon('calendar'); ?> Book this service</a>
          <p class="sa-svc-fine"><?php echo esc_html(sa_svc_source_note($s, $region)); ?></p>
        </aside>
      </div>
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

function sa_svc_render_book(): void
{
    $catalog = sa_svc_catalog();
    $region = sa_svc_region();
    $sel = sanitize_key((string) ($_GET['service'] ?? ''));
    $user = wp_get_current_user();
    $vehicles = $user->ID ? sa_svc_vehicles($user->ID) : [];
    $err = isset($_GET['sa_err']) ? sanitize_text_field(wp_unslash((string) $_GET['sa_err'])) : '';
    $tz = wp_timezone();
    $min = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    $max = (new DateTimeImmutable('now', $tz))->modify('+60 days')->format('Y-m-d');
    ?>
    <div class="sa-container sa-svc-book">
      <nav class="sa-svc-crumbs"><a href="<?php echo esc_url(sa_svc_url()); ?>">Services</a> / <span>Book</span></nav>
      <h1>Book a service</h1>
      <p class="sa-svc-lead">Four quick steps. No payment now — you pay at the service after approving the final quote.</p>
      <?php if ($err) : ?><div class="sa-svc-alert"><?php echo esc_html($err); ?></div><?php endif; ?>
      <form class="sa-svc-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-sa-svc-form>
        <input type="hidden" name="action" value="sa_book_service">
        <?php wp_nonce_field('sa_book_service', 'sa_svc_nonce'); ?>
        <input type="text" name="sa_hp" value="" tabindex="-1" autocomplete="off" class="sa-svc-hp" aria-hidden="true">
        <fieldset class="sa-svc-step"><legend><span>1</span> Service</legend>
          <div class="sa-svc-choices">
          <?php foreach ($catalog as $slug => $s) : ?>
            <label class="sa-svc-choice"><input type="radio" name="service" value="<?php echo esc_attr($slug); ?>" <?php checked($sel === $slug || ($sel === '' && $slug === 'oil-change')); ?> required
              data-kes='<?php echo esc_attr(wp_json_encode($s['kes']['options'] ?? [])); ?>'>
              <span><?php echo sa_svc_icon($s['icon']); ?><strong><?php echo esc_html($s['name']); ?></strong><em data-price-ke="<?php echo esc_attr(sa_svc_price_label($s, 'KE')); ?>" data-price-us="<?php echo esc_attr(sa_svc_price_label($s, 'US')); ?>"><?php echo esc_html(sa_svc_price_label($s, $region)); ?></em></span></label>
          <?php endforeach; ?>
          </div>
          <div class="sa-svc-option" data-sa-option hidden><label>Option <select name="option"></select></label></div>
        </fieldset>
        <fieldset class="sa-svc-step"><legend><span>2</span> Vehicle</legend>
          <?php if ($vehicles) : ?>
            <label class="sa-svc-full">Saved vehicles <select data-sa-vehicle><option value="">— Choose saved vehicle —</option>
              <?php foreach ($vehicles as $v) : ?><option data-v='<?php echo esc_attr(wp_json_encode($v)); ?>'><?php echo esc_html(trim($v['year'] . ' ' . $v['make'] . ' ' . $v['model'] . ' ' . $v['reg'])); ?></option><?php endforeach; ?>
            </select></label>
          <?php endif; ?>
          <div class="sa-svc-row">
            <label>Make* <input name="make" required placeholder="Toyota" autocomplete="off"></label>
            <label>Model* <input name="model" required placeholder="Corolla"></label>
            <label>Year <input name="year" type="number" min="1960" max="<?php echo (int) gmdate('Y') + 1; ?>" placeholder="2015"></label>
            <label>Plate / reg. <input name="reg" placeholder="KDA 123A"></label>
          </div>
          <?php if ($user->ID) : ?><label class="sa-svc-check"><input type="checkbox" name="save_vehicle" value="1" checked> Save to My vehicles</label><?php endif; ?>
        </fieldset>
        <fieldset class="sa-svc-step"><legend><span>3</span> Location, date &amp; time</legend>
          <div class="sa-svc-locs">
          <?php foreach (sa_svc_locations() as $k => $l) : ?>
            <label class="sa-svc-choice sa-svc-choice--row"><input type="radio" name="location" value="<?php echo esc_attr($k); ?>" data-region="<?php echo esc_attr($l['region']); ?>" <?php checked(($region === 'KE' && $k === 'nairobi') || ($region === 'US' && $k === 'usa')); ?> required>
              <span><strong><?php echo esc_html($l['label']); ?></strong><em><?php echo esc_html($l['note']); ?></em></span></label>
          <?php endforeach; ?>
          </div>
          <div class="sa-svc-row">
            <label>Date* <input type="date" name="date" required min="<?php echo esc_attr($min); ?>" max="<?php echo esc_attr($max); ?>" data-sa-date></label>
          </div>
          <div class="sa-svc-slots" data-sa-slots><p class="sa-svc-muted">Choose a date to see available times (Mon–Fri 8:00–16:00, Sat 8:00–12:00).</p></div>
        </fieldset>
        <fieldset class="sa-svc-step"><legend><span>4</span> Your details</legend>
          <div class="sa-svc-row">
            <label>Full name* <input name="name" required value="<?php echo esc_attr($user->ID ? trim($user->first_name . ' ' . $user->last_name) ?: $user->display_name : ''); ?>"></label>
            <label>Email* <input type="email" name="email" required value="<?php echo esc_attr($user->ID ? $user->user_email : ''); ?>"></label>
            <label>Phone / WhatsApp* <input name="phone" required value="<?php echo esc_attr($user->ID ? (string) get_user_meta($user->ID, 'billing_phone', true) : ''); ?>"></label>
          </div>
          <label class="sa-svc-full">Notes for the technician <textarea name="notes" rows="3" placeholder="Noises, warning lights, mileage…"></textarea></label>
        </fieldset>
        <div class="sa-svc-summary" data-sa-summary></div>
        <button class="sa-svc-btn sa-svc-btn--primary sa-svc-btn--lg" type="submit"><?php echo sa_svc_icon('calendar'); ?> Confirm booking</button>
        <p class="sa-svc-fine">By booking you agree we may contact you by email, phone or WhatsApp about this appointment. Free cancellation online while the booking is pending or confirmed.</p>
      </form>
    </div>
    <?php
}

function sa_svc_render_booking_card(array $b, bool $cancel = true): void
{
    $svc = sa_svc_catalog()[$b['service']] ?? ['name' => $b['service'], 'icon' => 'service'];
    $loc = sa_svc_locations()[$b['location']]['label'] ?? $b['location'];
    ?>
    <div class="sa-svc-bk">
      <div class="sa-svc-bk__top"><span class="sa-svc-bk__ref"><?php echo esc_html($b['ref']); ?></span><span class="sa-svc-status sa-svc-status--<?php echo esc_attr($b['status']); ?>"><?php echo esc_html(sa_svc_statuses()[$b['status']] ?? $b['status']); ?></span></div>
      <h3><?php echo sa_svc_icon($svc['icon'] ?? 'service'); ?> <?php echo esc_html($svc['name'] . ($b['option'] ? ' — ' . $b['option'] : '')); ?></h3>
      <dl>
        <dt>When</dt><dd><?php echo esc_html(date_i18n('D j M Y', strtotime($b['date'])) . ', ' . $b['time']); ?></dd>
        <dt>Where</dt><dd><?php echo esc_html($loc); ?></dd>
        <dt>Vehicle</dt><dd><?php echo esc_html(trim($b['year'] . ' ' . $b['make'] . ' ' . $b['model'] . ' ' . $b['reg'])); ?></dd>
        <dt>Estimate</dt><dd><?php echo esc_html($b['estimate']); ?> · pay at service</dd>
      </dl>
      <?php if ($cancel && in_array($b['status'], ['pending', 'confirmed'], true)) : ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Cancel this booking?');">
          <input type="hidden" name="action" value="sa_svc_cancel"><input type="hidden" name="b" value="<?php echo (int) $b['id']; ?>"><input type="hidden" name="k" value="<?php echo esc_attr($b['key']); ?>">
          <?php wp_nonce_field('sa_svc_cancel_' . $b['id']); ?>
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
      <h1><?php echo $b['status'] === 'cancelled' ? 'Booking cancelled' : 'Booking received'; ?></h1>
      <p class="sa-svc-lead">We've emailed <?php echo esc_html($b['email']); ?>. Our team will confirm your slot shortly.</p>
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
    echo '<h2 style="margin-top:28px">My vehicles</h2><div class="sa-svc-vehs">';
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
    if (!empty($_POST['delete'])) {
        wp_delete_post($id, true);
    } else {
        $st = sanitize_key((string) ($_POST['status'] ?? ''));
        if (isset(sa_svc_statuses()[$st]) && $st !== get_post_meta($id, '_sa_bk_status', true)) {
            update_post_meta($id, '_sa_bk_status', $st);
            if (!empty($_POST['notify'])) {
                sa_svc_send_emails($id, $st === 'cancelled' ? 'cancelled' : 'status');
            }
        }
        if (isset($_POST['estimate'])) {
            update_post_meta($id, '_sa_bk_estimate', sanitize_text_field(wp_unslash((string) $_POST['estimate'])));
        }
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
    echo '<div class="wrap sa-ultra sa-svc-admin"><h1>Service bookings</h1><p class="sa-ultra__sub">Appointments from <a href="' . esc_url(sa_svc_url()) . '" target="_blank">/services/</a>. Changing status can email the customer.</p>';
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
        echo '<tr><td><strong>' . esc_html($b['ref']) . '</strong><br><small>' . esc_html(wp_date('j M H:i', (int) $b['created'])) . '</small></td>'
            . '<td>' . esc_html($b['date'] . ' ' . $b['time']) . '</td><td>' . esc_html($svc . ($b['option'] ? ' — ' . $b['option'] : '')) . ($b['notes'] ? '<br><small>' . esc_html($b['notes']) . '</small>' : '') . '</td>'
            . '<td>' . esc_html($b['name']) . '<br><a href="mailto:' . esc_attr($b['email']) . '">' . esc_html($b['email']) . '</a><br>' . esc_html($b['phone']) . '</td>'
            . '<td>' . esc_html(trim($b['year'] . ' ' . $b['make'] . ' ' . $b['model'] . ' ' . $b['reg'])) . '</td>'
            . '<td>' . esc_html(sa_svc_locations()[$b['location']]['label'] ?? $b['location']) . '</td>'
            . '<td colspan="2"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">'
            . '<input type="hidden" name="action" value="sa_svc_admin"><input type="hidden" name="b" value="' . (int) $id . '">' . wp_nonce_field('sa_svc_admin', '_wpnonce', true, false)
            . '<input name="estimate" value="' . esc_attr($b['estimate']) . '" style="width:150px"><select name="status">';
        foreach (sa_svc_statuses() as $k => $l) {
            echo '<option value="' . esc_attr($k) . '"' . selected($b['status'], $k, false) . '>' . esc_html($l) . '</option>';
        }
        echo '</select><label><input type="checkbox" name="notify" value="1" checked> email</label><button class="button button-primary">Save</button>'
            . '<button class="button-link-delete" name="delete" value="1" onclick="return confirm(\'Delete booking permanently?\')">Delete</button></form></td></tr>';
    }
    echo '</tbody></table></div>';
}

