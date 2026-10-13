<?php
/**
 * Roadside Rescue & Assistance (third service, next to Shop Parts and Book a Service).
 *
 * Customer side (no login): /roadside/ landing, /roadside/request/ form, tokenized live
 * status page /roadside/status/?r=ID&k=KEY (timeline, ETA, live provider location, chat,
 * offers, cancel, rating), /roadside/track/ lookup, /roadside-terms/, My Account history.
 * Provider side: roadside-provider.php. Admin (Super Admin): roadside-admin.php.
 * Prices: data/roadside-catalog.php (published Kenyan rates only), else quote on the call.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const SA_RS_VER = '1';
const SA_RS_PHONE = '+254714498451';
const SA_RS_PHONE_LABEL = '+254 714 498 451';

function sa_rs_catalog(): array
{
    static $c = null;
    if ($c === null) {
        $c = require SA_CORE_DIR . 'data/roadside-catalog.php';
    }
    return $c;
}

function sa_rs_statuses(): array
{
    return [
        'received'  => 'Received',
        'dispatched' => 'Dispatched',
        'en_route'  => 'En route',
        'arrived'   => 'Arrived',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];
}

/** Customer-facing explanation per status. */
function sa_rs_status_text(string $s): string
{
    return [
        'received'  => 'We have your request and are finding the nearest available responder.',
        'dispatched' => 'A responder has been assigned to you.',
        'en_route'  => 'Your responder is on the way.',
        'arrived'   => 'Your responder has arrived.',
        'completed' => 'Job completed. Thank you for choosing Supreme Autoparts.',
        'cancelled' => 'This request was cancelled.',
    ][$s] ?? '';
}

function sa_rs_wa_url(string $text = ''): string
{
    return 'https://wa.me/' . ltrim(SA_RS_PHONE, '+') . ($text !== '' ? '?text=' . rawurlencode($text) : '');
}

function sa_rs_url(string $path = ''): string
{
    return home_url('/roadside/' . ($path !== '' ? trailingslashit($path) : ''));
}

function sa_rs_status_url(int $id): string
{
    return add_query_arg(['r' => $id, 'k' => (string) get_post_meta($id, '_rs_key', true)], sa_rs_url('status'));
}

function sa_rs_price_label(array $svc): string
{
    if (!empty($svc['rates']['tables'])) {
        $min = PHP_INT_MAX;
        foreach ($svc['rates']['tables'] as $t) {
            $min = min($min, (int) min($t));
        }
        return 'From KES ' . number_format($min);
    }
    return 'Quote on request';
}

function sa_rs_country(): string
{
    $o = isset($_GET['region']) ? strtoupper(sanitize_key((string) $_GET['region'])) : '';
    if (in_array($o, ['KE', 'US'], true)) {
        return $o;
    }
    return class_exists('SA_Geo_Detector') ? strtoupper((string) SA_Geo_Detector::country_code()) : '';
}

/* ---------- Data model ---------- */

add_action('init', static function (): void {
    register_post_type('sa_roadside', ['label' => 'Roadside requests', 'public' => false, 'show_ui' => false, 'supports' => ['title']]);
    register_post_type('sa_rs_provider', ['label' => 'Roadside providers', 'public' => false, 'show_ui' => false, 'supports' => ['title']]);
    if (!get_role('sa_rs_provider')) {
        add_role('sa_rs_provider', 'Roadside provider', ['read' => true]);
    }
    add_rewrite_rule('^roadside/?$', 'index.php?sa_rs_view=index', 'top');
    add_rewrite_rule('^roadside/(request|status|track|provider|join|thanks)/?$', 'index.php?sa_rs_view=$matches[1]', 'top');
    add_rewrite_rule('^roadside-terms/?$', 'index.php?sa_rs_view=terms', 'top');
    if (get_option('sa_rs_rewrite_ver') !== SA_RS_VER) {
        flush_rewrite_rules(false);
        update_option('sa_rs_rewrite_ver', SA_RS_VER);
    }
}, 12);

add_filter('query_vars', static function (array $v): array {
    $v[] = 'sa_rs_view';
    return $v;
});

function sa_rs_data(int $id): array
{
    $m = static fn(string $k) => get_post_meta($id, '_rs_' . $k, true);
    $keys = ['ref', 'key', 'type', 'status', 'name', 'phone', 'whatsapp', 'email', 'lat', 'lng', 'address', 'landmark', 'make', 'model', 'year', 'reg',
        'note', 'dest', 'when', 'photo', 'user', 'provider', 'eta', 'eta_at', 'plat', 'plng', 'pts', 'quote', 'pay_order', 'pay_amount', 'paid',
        'rating', 'feedback', 'rated', 'review_hidden', 'review_appeal', 'cancel_reason', 'country', 'created'];
    $d = ['id' => $id];
    foreach ($keys as $k) {
        $d[$k] = $m($k);
    }
    foreach (['history', 'messages', 'notes', 'offers'] as $k) {
        $v = $m($k);
        $d[$k] = is_array($v) ? $v : [];
    }
    $d['status'] = $d['status'] ?: 'received';
    $d['user'] = (int) $d['user'];
    $d['provider'] = (int) $d['provider'];
    $d['created'] = (int) ($d['created'] ?: get_post_time('U', true, $id));
    return $d;
}

function sa_rs_set(int $id, string $k, $v): void
{
    update_post_meta($id, '_rs_' . $k, $v);
}

function sa_rs_log(int $id, string $status, string $note = '', string $by = ''): void
{
    $h = (array) get_post_meta($id, '_rs_history', true);
    $h[] = ['t' => time(), 's' => $status, 'n' => $note, 'by' => $by];
    update_post_meta($id, '_rs_history', $h);
}

/** Change status, log it and email the customer (every update emails the customer). */
function sa_rs_set_status(int $id, string $status, string $note = '', string $by = 'Dispatch'): void
{
    if (!isset(sa_rs_statuses()[$status])) {
        return;
    }
    sa_rs_set($id, 'status', $status);
    sa_rs_log($id, $status, $note, $by);
    sa_rs_email($id, 'status', $note);
}

function sa_rs_new_ref(): string
{
    $day = wp_date('ymd');
    do {
        $ref = 'SA-RS-' . $day . '-' . strtoupper(wp_generate_password(4, false, false));
        $dupe = get_posts(['post_type' => 'sa_roadside', 'numberposts' => 1, 'fields' => 'ids', 'post_status' => 'any', 'meta_key' => '_rs_ref', 'meta_value' => $ref]);
    } while ($dupe);
    return $ref;
}

function sa_rs_km(float $a1, float $o1, float $a2, float $o2): float
{
    $r = 6371;
    $da = deg2rad($a2 - $a1);
    $do = deg2rad($o2 - $o1);
    $x = sin($da / 2) ** 2 + cos(deg2rad($a1)) * cos(deg2rad($a2)) * sin($do / 2) ** 2;
    return 2 * $r * asin(min(1, sqrt($x)));
}

function sa_rs_can_view(array $r, string $k = ''): bool
{
    return ($r['key'] !== '' && $k !== '' && hash_equals((string) $r['key'], $k))
        || ($r['user'] && $r['user'] === get_current_user_id())
        || current_user_can('manage_woocommerce');
}

function sa_rs_setting(string $k)
{
    $d = ['customer_choice' => '0', 'radius' => '40', 'notify_providers' => '1'];
    $o = (array) get_option('sa_rs_settings', []);
    return $o[$k] ?? ($d[$k] ?? '');
}

/* ---------- Request submission (no login) ---------- */

add_action('admin_post_sa_rs_request', 'sa_rs_handle_request');
add_action('admin_post_nopriv_sa_rs_request', 'sa_rs_handle_request');
function sa_rs_handle_request(): void
{
    $back = sa_rs_url('request');
    $fail = static function (string $msg) use ($back): void {
        wp_safe_redirect(add_query_arg('rs_err', rawurlencode($msg), $back));
        exit;
    };
    if (!wp_verify_nonce((string) ($_POST['sa_rs_nonce'] ?? ''), 'sa_rs_request')) {
        $fail('Your session expired. Please try again, or call us on ' . SA_RS_PHONE_LABEL . '.');
    }
    if (!empty($_POST['sa_hp'])) {
        $fail('Unable to submit.');
    }
    $ip = preg_replace('/[^0-9a-fA-F:.]/', '', (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '')) ?: '0';
    $rk = 'sa_rs_rl_' . md5($ip);
    if ((int) get_transient($rk) >= 6) {
        $fail('Too many requests from this connection. Please call or WhatsApp us on ' . SA_RS_PHONE_LABEL . '.');
    }
    $p = static fn(string $k): string => sanitize_text_field(wp_unslash((string) ($_POST[$k] ?? '')));
    $type = sanitize_key($p('type'));
    if (!isset(sa_rs_catalog()[$type])) $fail('Please choose what help you need.');
    $phone = preg_replace('/[^0-9+]/', '', $p('phone'));
    if (strlen($phone) < 9) $fail('Please enter a phone number we can call.');
    if ($p('name') === '') $fail('Please enter your name.');
    $email = sanitize_email($p('email'));
    if ($email !== '' && !is_email($email)) $fail('Please check your email address.');
    $lat = is_numeric($p('lat')) ? (float) $p('lat') : null;
    $lng = is_numeric($p('lng')) ? (float) $p('lng') : null;
    if ($lat !== null && (abs($lat) > 90 || abs($lng) > 180)) { $lat = $lng = null; }
    if ($lat === null && $p('address') === '' && $p('landmark') === '') $fail('Please share your location, drop a pin or type where you are.');
    if (empty($_POST['agree'])) $fail('Please accept the Roadside Terms.');
    set_transient($rk, (int) get_transient($rk) + 1, 10 * MINUTE_IN_SECONDS);

    $svc = sa_rs_catalog()[$type];
    $ref = sa_rs_new_ref();
    $id = wp_insert_post(['post_type' => 'sa_roadside', 'post_status' => 'publish', 'post_title' => $ref . ' ' . $svc['name'] . ' — ' . $p('name')], true);
    if (is_wp_error($id)) $fail('Could not save your request. Please call us on ' . SA_RS_PHONE_LABEL . '.');
    $uid = get_current_user_id();
    if (!$uid && $email !== '' && ($u = get_user_by('email', $email))) {
        $uid = 0; // Matched by email in My Account; never auto-login.
    }
    $year = (int) $p('year');
    $meta = [
        'ref' => $ref, 'key' => wp_generate_password(32, false, false), 'type' => $type, 'status' => 'received',
        'name' => $p('name'), 'phone' => $phone, 'whatsapp' => preg_replace('/[^0-9+]/', '', $p('whatsapp')) ?: $phone, 'email' => $email,
        'lat' => $lat === null ? '' : round($lat, 6), 'lng' => $lng === null ? '' : round($lng, 6),
        'address' => $p('address'), 'landmark' => $p('landmark'), 'make' => $p('make'), 'model' => $p('model'),
        'year' => $year > 1950 && $year <= (int) gmdate('Y') + 1 ? (string) $year : '', 'reg' => strtoupper($p('reg')),
        'note' => sanitize_textarea_field(wp_unslash((string) ($_POST['note'] ?? ''))), 'dest' => $p('dest'),
        'when' => $p('when') === 'later' && $p('when_at') !== '' ? $p('when_at') : 'now',
        'user' => $uid, 'country' => sa_rs_country(), 'created' => time(),
    ];
    foreach ($meta as $k => $v) {
        sa_rs_set((int) $id, $k, $v);
    }
    // Optional photo.
    if (!empty($_FILES['photo']['name']) && (int) $_FILES['photo']['size'] > 0 && (int) $_FILES['photo']['size'] <= 8 * MB_IN_BYTES) {
        $ft = wp_check_filetype_and_ext((string) $_FILES['photo']['tmp_name'], (string) $_FILES['photo']['name']);
        if (in_array($ft['type'] ?? '', ['image/jpeg', 'image/png', 'image/webp', 'image/heic'], true)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $aid = media_handle_upload('photo', (int) $id, ['post_title' => $ref . ' photo']);
            if (!is_wp_error($aid)) {
                sa_rs_set((int) $id, 'photo', (int) $aid);
            }
        }
    }
    if ($uid && $p('make') !== '' && function_exists('sa_svc_save_vehicle')) {
        sa_svc_save_vehicle($uid, ['make' => $p('make'), 'model' => $p('model'), 'year' => $meta['year'], 'reg' => $meta['reg']]);
    }
    if ($uid && $lat !== null) {
        update_user_meta($uid, 'sa_rs_last_loc', ['lat' => $lat, 'lng' => $lng, 'address' => $p('address'), 'landmark' => $p('landmark')]);
    }
    sa_rs_log((int) $id, 'received', 'Request submitted online', 'Customer');
    sa_rs_email((int) $id, 'created');
    sa_rs_notify_providers((int) $id);
    if (function_exists('sa_core_audit_log')) {
        sa_core_audit_log('roadside_request', ['request' => (int) $id]);
    }
    wp_safe_redirect(add_query_arg('new', '1', sa_rs_status_url((int) $id)));
    exit;
}

/* ---------- Customer actions: cancel, chat, choose offer, rate ---------- */

add_action('admin_post_sa_rs_customer', 'sa_rs_customer_action');
add_action('admin_post_nopriv_sa_rs_customer', 'sa_rs_customer_action');
function sa_rs_customer_action(): void
{
    $id = (int) ($_POST['r'] ?? 0);
    $k = (string) ($_POST['k'] ?? '');
    if (get_post_type($id) !== 'sa_roadside' || !wp_verify_nonce((string) ($_POST['_wpnonce'] ?? ''), 'sa_rs_customer_' . $id)) {
        wp_die('This link has expired. Please open the link in your email again.', 403);
    }
    $r = sa_rs_data($id);
    if (!sa_rs_can_view($r, $k)) {
        wp_die('Not allowed', 403);
    }
    $do = sanitize_key((string) ($_POST['do'] ?? ''));
    $back = add_query_arg(['r' => $id, 'k' => $r['key']], sa_rs_url('status'));
    $txt = sanitize_textarea_field(wp_unslash((string) ($_POST['text'] ?? '')));
    if ($do === 'cancel' && in_array($r['status'], ['received', 'dispatched'], true)) {
        sa_rs_set($id, 'cancel_reason', $txt);
        sa_rs_set_status($id, 'cancelled', 'Cancelled by customer' . ($txt !== '' ? ': ' . $txt : ''), 'Customer');
        sa_rs_email($id, 'admin_alert', 'Customer cancelled request ' . $r['ref']);
    } elseif ($do === 'msg' && $txt !== '') {
        sa_rs_add_message($id, 'customer', $r['name'], $txt);
        sa_rs_email($id, 'admin_alert', 'New customer message on ' . $r['ref'] . ': ' . $txt);
    } elseif ($do === 'choose' && $r['status'] === 'received') {
        $pid = (int) ($_POST['provider'] ?? 0);
        foreach ($r['offers'] as $o) {
            if ((int) $o['p'] === $pid) {
                sa_rs_assign($id, $pid, (int) $o['eta'], 'Customer chose this responder');
                break;
            }
        }
    } elseif ($do === 'rate' && $r['status'] === 'completed' && !$r['rated']) {
        $stars = max(1, min(5, (int) ($_POST['stars'] ?? 0)));
        sa_rs_set($id, 'rating', $stars);
        sa_rs_set($id, 'feedback', mb_substr($txt, 0, 1500));
        sa_rs_set($id, 'rated', time());
        sa_rs_log($id, 'completed', 'Customer rated ' . $stars . '/5', 'Customer');
        sa_rs_email($id, 'admin_alert', 'New rating on ' . $r['ref'] . ': ' . $stars . '/5' . ($txt !== '' ? ' — ' . $txt : ''));
        $back = add_query_arg('rated', '1', $back);
    }
    wp_safe_redirect($back . '#rs-main');
    exit;
}

function sa_rs_add_message(int $id, string $from, string $who, string $text): void
{
    $m = (array) get_post_meta($id, '_rs_messages', true);
    $m[] = ['t' => time(), 'f' => $from, 'w' => $who, 'x' => mb_substr($text, 0, 1000)];
    update_post_meta($id, '_rs_messages', array_slice($m, -100));
}

/** Assign a provider (admin, offer acceptance or customer choice). */
function sa_rs_assign(int $id, int $pid, int $eta = 0, string $note = ''): void
{
    if (get_post_type($pid) !== 'sa_rs_provider') {
        return;
    }
    sa_rs_set($id, 'provider', $pid);
    if ($eta > 0) {
        sa_rs_set($id, 'eta', $eta);
        sa_rs_set($id, 'eta_at', time() + $eta * 60);
    }
    sa_rs_set($id, 'offers', []);
    sa_rs_set_status($id, 'dispatched', trim(get_the_title($pid) . ' assigned. ' . ($eta > 0 ? 'ETA about ' . $eta . ' min. ' : '') . $note), 'Dispatch');
    sa_rs_provider_email($pid, $id, 'assigned');
}

/* ---------- Status JSON (polled by the status page) ---------- */

add_action('wp_ajax_sa_rs_poll', 'sa_rs_poll');
add_action('wp_ajax_nopriv_sa_rs_poll', 'sa_rs_poll');
function sa_rs_poll(): void
{
    $id = (int) ($_GET['r'] ?? 0);
    $r = get_post_type($id) === 'sa_roadside' ? sa_rs_data($id) : null;
    if (!$r || !sa_rs_can_view($r, (string) ($_GET['k'] ?? ''))) {
        wp_send_json_error([], 403);
    }
    nocache_headers();
    $prov = $r['provider'] ? sa_rs_provider_data($r['provider']) : null;
    $fresh = $r['pts'] && time() - (int) $r['pts'] < 15 * MINUTE_IN_SECONDS && in_array($r['status'], ['dispatched', 'en_route', 'arrived'], true);
    wp_send_json_success([
        'status' => $r['status'], 'label' => sa_rs_statuses()[$r['status']], 'text' => sa_rs_status_text($r['status']),
        'eta' => $r['eta_at'] ? max(0, (int) ceil(((int) $r['eta_at'] - time()) / 60)) : null,
        'plat' => $fresh ? (float) $r['plat'] : null, 'plng' => $fresh ? (float) $r['plng'] : null,
        'provider' => $prov ? ['name' => $prov['name'], 'phone' => $prov['phone']] : null,
        'v' => md5(wp_json_encode([$r['status'], count($r['history']), count($r['messages']), count($r['offers']), $r['eta_at']])),
    ]);
}

/* ---------- Emails (wp_mail → Brevo, branded logo header, no custom footer) ---------- */

function sa_rs_email(int $id, string $event, string $note = ''): void
{
    $r = sa_rs_data($id);
    $svc = sa_rs_catalog()[$r['type']]['name'] ?? $r['type'];
    $label = sa_rs_statuses()[$r['status']] ?? $r['status'];
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    $admin = (string) get_option('woocommerce_email_from_address', get_option('admin_email'));
    $btn = static fn(string $url, string $t) => '<p style="margin:18px 0"><a href="' . esc_url($url) . '" style="display:inline-block;background:#3665f3;color:#fff;text-decoration:none;font-weight:700;padding:13px 24px;border-radius:10px">' . esc_html($t) . '</a></p>';
    $rows = ['Request number' => $r['ref'], 'Help needed' => $svc, 'Status' => $label,
        'Location' => trim($r['address'] . ($r['landmark'] ? ' (near ' . $r['landmark'] . ')' : '')) ?: ($r['lat'] ? $r['lat'] . ', ' . $r['lng'] : ''),
        'Vehicle' => trim($r['year'] . ' ' . $r['make'] . ' ' . $r['model'] . ' ' . $r['reg']), 'Phone' => $r['phone']];
    if ($r['provider']) {
        $p = sa_rs_provider_data($r['provider']);
        $rows['Responder'] = $p['name'] . ($p['phone'] ? ' · ' . $p['phone'] : '');
    }
    if ($r['eta_at'] && in_array($r['status'], ['dispatched', 'en_route'], true)) {
        $rows['ETA'] = 'About ' . wp_date('g:i A', (int) $r['eta_at']) . ' EAT';
    }
    $table = '<table style="border-collapse:collapse;width:100%;font-size:14px">';
    foreach (array_filter($rows) as $k => $v) {
        $table .= '<tr><td style="padding:7px 8px;border-bottom:1px solid #eee;color:#5b6475">' . esc_html($k) . '</td><td style="padding:7px 8px;border-bottom:1px solid #eee"><strong>' . esc_html((string) $v) . '</strong></td></tr>';
    }
    $table .= '</table>';
    $wrap = static fn(string $inner) => '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:auto;color:#1d2433;line-height:1.5">' . $inner . '</div>';
    $call = '<p style="font-size:14px">Need us now? Call <a href="tel:' . SA_RS_PHONE . '">' . SA_RS_PHONE_LABEL . '</a> or <a href="' . esc_url(sa_rs_wa_url('Roadside request ' . $r['ref'])) . '">WhatsApp us</a>.</p>';

    if ($event === 'admin_alert') {
        wp_mail($admin, '[Roadside] ' . $r['ref'] . ' — ' . mb_substr($note, 0, 80), $wrap('<p>' . esc_html($note) . '</p>' . $table . $btn(admin_url('admin.php?page=sa-super-roadside&r=' . $id), 'Open in Super Admin')), $headers);
        return;
    }
    if ($event === 'created') {
        $dispatch = sa_rs_house_provider_id() ? sa_rs_provider_link(sa_rs_house_provider_id()) : '';
        $body = '<h2 style="margin:0 0 6px">New roadside request ' . esc_html($r['ref']) . '</h2><p>' . esc_html($r['name']) . ' needs <strong>' . esc_html($svc) . '</strong>. Call them back now on <a href="tel:' . esc_attr($r['phone']) . '">' . esc_html($r['phone']) . '</a>.</p>'
            . ($r['note'] ? '<p>Note: ' . esc_html($r['note']) . '</p>' : '') . ($r['dest'] ? '<p>Tow to: ' . esc_html($r['dest']) . '</p>' : '') . $table
            . ($r['lat'] ? '<p><a href="https://www.google.com/maps?q=' . rawurlencode($r['lat'] . ',' . $r['lng']) . '">Open pin in Google Maps</a></p>' : '')
            . $btn(admin_url('admin.php?page=sa-super-roadside&r=' . $id), 'Assign in Super Admin') . ($dispatch ? '<p><a href="' . esc_url($dispatch) . '">Open the in-house responder dashboard</a></p>' : '');
        wp_mail($admin, '[Roadside] NEW ' . $svc . ' — ' . $r['ref'] . ' — ' . $r['name'], $wrap($body), $headers);
    }
    if (!is_email($r['email'])) {
        return;
    }
    if ($event === 'created') {
        $subject = 'Help is being arranged — request ' . $r['ref'];
        $lead = 'Hi ' . esc_html($r['name']) . ', we have your roadside request. Our dispatcher will call you on ' . esc_html($r['phone']) . ' to confirm the details and the price before anyone is sent. No payment is needed to request help.';
    } elseif ($event === 'message') {
        $subject = 'New message about your request ' . $r['ref'];
        $lead = 'Hi ' . esc_html($r['name']) . ', you have a new message from our team: <em>' . esc_html($note) . '</em>';
    } elseif ($event === 'pay') {
        $subject = 'Payment link for roadside request ' . $r['ref'];
        $lead = 'Hi ' . esc_html($r['name']) . ', here is the secure Pesapal payment link for the amount we agreed on the call: <strong>' . esc_html((string) $r['pay_amount']) . '</strong>. Paying online is optional; you can also pay the responder directly.';
    } else {
        $subject = 'Request ' . $r['ref'] . ': ' . $label . ($r['status'] === 'completed' ? ' — rate your help' : '');
        $lead = 'Hi ' . esc_html($r['name']) . ', ' . esc_html(lcfirst(sa_rs_status_text($r['status']))) . ($note !== '' ? '<br><span style="color:#5b6475">' . esc_html($note) . '</span>' : '');
    }
    $cta = $event === 'pay' && $r['pay_order'] && ($o = wc_get_order((int) $r['pay_order'])) ? $btn($o->get_checkout_payment_url(), 'Pay ' . $r['pay_amount'] . ' securely')
        : $btn(sa_rs_status_url($id), $r['status'] === 'completed' && !$r['rated'] ? 'Rate your roadside help' : 'Track live status');
    wp_mail($r['email'], $subject, $wrap('<p>' . $lead . '</p>' . $cta . $table . $call), $headers);
}

/* ---------- Routing ---------- */

add_action('template_redirect', static function (): void {
    $view = (string) get_query_var('sa_rs_view');
    if ($view === '') {
        return;
    }
    if ($view === 'provider') {
        sa_rs_provider_gate();
    }
    if ($view === 'track' && isset($_POST['ref'])) {
        sa_rs_track_lookup();
    }
    global $wp_query;
    $wp_query->is_404 = false;
    $wp_query->is_page = true;
    status_header(200);
    $titles = ['index' => 'Roadside Rescue & Assistance in Nairobi', 'request' => 'Request roadside help', 'status' => 'Roadside request status', 'track' => 'Track a roadside request',
        'provider' => 'Responder dashboard', 'join' => 'Become a roadside responder', 'thanks' => 'Application received', 'terms' => 'Roadside Terms'];
    add_filter('pre_get_document_title', static fn() => ($titles[$view] ?? 'Roadside') . ' | ' . get_bloginfo('name'), 99);
    if (!in_array($view, ['index', 'terms', 'join'], true)) {
        add_filter('wp_robots', static fn(array $r) => array_merge($r, ['noindex' => true]));
        nocache_headers();
    }
    get_header();
    echo '<main id="main" class="sa-svc sa-rs">';
    switch ($view) {
        case 'request': sa_rs_render_request(); break;
        case 'status': sa_rs_render_status(); break;
        case 'track': sa_rs_render_track(); break;
        case 'provider': sa_rs_render_provider(); break;
        case 'join': sa_rs_render_join(); break;
        case 'thanks': echo '<div class="sa-container sa-rs-narrow"><h1>Thanks, application received</h1><p class="sa-svc-lead">Our team will call you to verify your details before you can take jobs.</p></div>'; break;
        case 'terms': sa_rs_render_terms(); break;
        default: sa_rs_render_index();
    }
    echo '</main>';
    get_footer();
    exit;
});

add_action('wp_enqueue_scripts', static function (): void {
    if (get_query_var('sa_rs_view') === '' || get_query_var('sa_rs_view') === null) {
        return;
    }
    wp_enqueue_style('sa-services', SA_CORE_URL . 'assets/services.css', [], SA_CORE_VERSION);
    wp_enqueue_style('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4');
    wp_enqueue_style('sa-roadside', SA_CORE_URL . 'assets/roadside.css', ['sa-services'], SA_CORE_VERSION);
    wp_enqueue_script('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', true);
    wp_enqueue_script('sa-roadside', SA_CORE_URL . 'assets/roadside.js', ['leaflet'], SA_CORE_VERSION, true);
    wp_localize_script('sa-roadside', 'saRs', ['ajax' => admin_url('admin-ajax.php')]);
});

function sa_rs_icon(string $n): string
{
    $extra = [
        'fuel' => '<path d="M4 21V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v16M3 21h12M6 9h6"/><path d="M14 8l3 3v6a1.5 1.5 0 0 0 3 0V9l-3-3"/>',
        'key' => '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M17 6l3 3M15 8l2 2"/>',
        'winch' => '<path d="M3 17h10v-5H3zM13 14h3l2-6h3"/><circle cx="6" cy="19" r="2"/><circle cx="11" cy="19" r="2"/><path d="M21 8v8"/>',
        'phone' => '<path d="M5 3h4l2 5-3 2a12 12 0 0 0 6 6l2-3 5 2v4a2 2 0 0 1-2 2A18 18 0 0 1 3 5a2 2 0 0 1 2-2z"/>',
        'locate' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="7"/>',
        'star' => '<path d="M12 3l2.8 5.7 6.2.9-4.5 4.4 1 6.2L12 17.3 6.5 20.2l1-6.2L3 9.6l6.2-.9z"/>',
        'shield' => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    ];
    if (isset($extra[$n])) {
        return '<svg class="sa-svc-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $extra[$n] . '</svg>';
    }
    return function_exists('sa_svc_icon') ? sa_svc_icon($n) : '';
}

function sa_rs_cta_bar(string $ctx = ''): string
{
    return '<div class="sa-rs-callbar"><a class="sa-svc-btn sa-svc-btn--primary" href="tel:' . SA_RS_PHONE . '">' . sa_rs_icon('phone') . ' Call ' . SA_RS_PHONE_LABEL . '</a>'
        . '<a class="sa-svc-btn sa-rs-btn--wa" href="' . esc_url(sa_rs_wa_url($ctx !== '' ? $ctx : 'Hi, I need roadside help.')) . '" target="_blank" rel="noopener">' . sa_rs_icon('chat') . ' WhatsApp</a></div>';
}

/* ---------- FAQ (editable in Super Admin) ---------- */

function sa_rs_faq_default(): array
{
    return [
        ['Do I need an account or to pay before asking for help?', 'No. Anyone can request help without logging in, and no payment is taken to request help. You get a request number and a live status link by SMS-friendly email and on screen.'],
        ['How much will it cost?', 'Towing shows guide prices. Everything else is quoted on the phone after we know your location and car. You always agree the price before a responder is sent.'],
        ['Which areas do you cover?', 'Nairobi and its environs, from our base at Midax Plaza, Njiru. Further out we will tell you on the call if we can reach you, and how long it will take.'],
        ['How long until someone arrives?', 'It depends on traffic and distance. Your responder sets an ETA when they head out, and you can watch their position live on your status page.'],
        ['How do I pay?', 'Pay the responder on site, or ask us for a secure Pesapal link (M-Pesa or card). Paying online is optional.'],
        ['Can you tow my car to your garage?', 'Yes. Choose "Our Njiru garage" as the destination and we can book the repair with parts from our own shop.'],
        ['What if I am in an unsafe place?', 'Turn on hazard lights, stay inside with the doors locked if traffic is passing, or move well away from the road. If anyone is hurt or in danger, call 999 or 112 first.'],
        ['Can I cancel?', 'Yes, from your status page before the responder is on the way, at no charge. After that, please call us.'],
    ];
}

function sa_rs_faqs(): array
{
    $f = get_option('sa_rs_faqs');
    return is_array($f) && $f ? $f : sa_rs_faq_default();
}

function sa_rs_faq_html(): string
{
    $o = '<section class="sa-svc-faq" id="faq"><h2>Roadside FAQ</h2>';
    $ld = [];
    foreach (sa_rs_faqs() as $f) {
        $o .= '<details><summary>' . esc_html($f[0]) . '</summary><p>' . esc_html($f[1]) . '</p></details>';
        $ld[] = ['@type' => 'Question', 'name' => $f[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]]];
    }
    return $o . '</section><script type="application/ld+json">' . wp_json_encode(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $ld]) . '</script>';
}

/* ---------- Pages ---------- */

function sa_rs_render_index(): void
{
    $cat = sa_rs_catalog();
    $req = sa_rs_url('request');
    $ratings = sa_rs_rating_summary();
    ?>
    <section class="sa-svc-hero sa-rs-hero">
      <div class="sa-container sa-svc-hero__in">
        <div>
          <p class="sa-svc-eyebrow">Roadside Rescue &amp; Assistance · Nairobi</p>
          <h1>Stuck on the road? We'll come to you.</h1>
          <p class="sa-svc-lead">Towing, jump-starts, flat tyres, fuel, lockouts, winching and small fixes on the spot. Send your location in under a minute, no account needed, and follow your responder live.</p>
          <div class="sa-svc-cta">
            <a class="sa-svc-btn sa-svc-btn--primary sa-rs-btn--big" href="<?php echo esc_url($req); ?>"><?php echo sa_rs_icon('locate'); ?> Request help now</a>
            <a class="sa-svc-btn sa-svc-btn--ghost" href="tel:<?php echo esc_attr(SA_RS_PHONE); ?>"><?php echo sa_rs_icon('phone'); ?> Call <?php echo esc_html(SA_RS_PHONE_LABEL); ?></a>
          </div>
          <ul class="sa-svc-points"><li><?php echo sa_svc_icon('check'); ?>No login, no payment to ask</li><li><?php echo sa_svc_icon('check'); ?>Price agreed on the call</li><li><?php echo sa_svc_icon('check'); ?>Live status and ETA</li><li><?php echo sa_svc_icon('check'); ?>Tow to our Njiru garage</li></ul>
          <?php if ($ratings['count'] > 0) : ?><p class="sa-rs-rating"><?php echo sa_rs_icon('star'); ?> <?php echo esc_html(number_format($ratings['avg'], 1)); ?>/5 from <?php echo (int) $ratings['count']; ?> customer rating<?php echo $ratings['count'] === 1 ? '' : 's'; ?></p><?php endif; ?>
        </div>
        <ol class="sa-svc-steps">
          <li><span>1</span><strong>Tell us where</strong><em>GPS, a map pin or a landmark</em></li>
          <li><span>2</span><strong>We call you</strong><em>Confirm the problem and the price</em></li>
          <li><span>3</span><strong>Track your responder</strong><em>Live status, map and ETA</em></li>
          <li><span>4</span><strong>Back on the road</strong><em>Pay on site or by Pesapal link, then rate us</em></li>
        </ol>
      </div>
    </section>
    <section class="sa-container sa-svc-catalog">
      <div class="sa-svc-head"><h2>What do you need?</h2></div>
      <div class="sa-svc-grid">
      <?php foreach ($cat as $slug => $s) : $price = sa_rs_price_label($s); ?>
        <article class="sa-svc-card" id="<?php echo esc_attr($slug); ?>">
          <div class="sa-svc-card__top"><div class="sa-svc-card__ico"><?php echo sa_rs_icon($s['icon']); ?></div></div>
          <h4><?php echo esc_html($s['name']); ?></h4>
          <p><?php echo esc_html($s['summary']); ?></p>
          <ul class="sa-rs-what"><?php foreach ($s['what'] as $w) echo '<li>' . esc_html($w) . '</li>'; ?></ul>
          <div class="sa-svc-card__foot"><span class="sa-svc-price<?php echo $price === 'Quote on request' ? ' is-quote' : ''; ?>"><?php echo esc_html($price === 'Quote on request' ? 'Quote on request, confirmed on call' : $price); ?></span></div>
          <div class="sa-svc-card__act"><a class="sa-svc-btn sa-svc-btn--sm sa-svc-btn--primary" href="<?php echo esc_url(add_query_arg('type', $slug, $req)); ?>">Request</a></div>
        </article>
      <?php endforeach; ?>
      </div>
      <?php $t = $cat['towing']['rates']; ?>
      <section class="sa-rs-rates">
        <h2>Towing guide prices</h2>
        <p class="sa-svc-muted">Guide towing prices. Your exact price is confirmed on the call before we send anyone.</p>
        <div class="sa-rs-rates__grid">
        <?php foreach ($t['tables'] as $label => $rows) : ?>
          <table class="sa-rs-table"><caption><?php echo esc_html($label); ?></caption><?php foreach ($rows as $d => $kes) echo '<tr><td>' . esc_html($d) . '</td><td>KES ' . esc_html(number_format($kes)) . '</td></tr>'; ?></table>
        <?php endforeach; ?>
        </div>
        <p class="sa-svc-fine">Beyond 50 km and all other services: quote on request, confirmed on call.</p>
      </section>
      <div class="sa-rs-safety"><?php echo sa_rs_icon('shield'); ?><div><strong>Safety first</strong><p>Hazard lights on, warning triangle out if safe, and wait away from traffic. If anyone is hurt, call 999 or 112 before anything else.</p></div></div>
      <?php echo sa_rs_faq_html(); ?>
      <div class="sa-rs-links"><a href="<?php echo esc_url(sa_rs_url('track')); ?>">Track a request</a> · <a href="<?php echo esc_url(home_url('/roadside-terms/')); ?>">Roadside Terms</a> · <a href="<?php echo esc_url(sa_rs_url('join')); ?>">Become a responder</a></div>
    </section>
    <?php echo sa_rs_cta_bar(); ?>
    <?php
}

function sa_rs_render_request(): void
{
    $cat = sa_rs_catalog();
    $type = sanitize_key((string) ($_GET['type'] ?? ''));
    $u = wp_get_current_user();
    $veh = $u->ID && function_exists('sa_svc_vehicles') ? sa_svc_vehicles($u->ID) : [];
    $last = $u->ID ? get_user_meta($u->ID, 'sa_rs_last_loc', true) : [];
    $cc = sa_rs_country();
    $err = isset($_GET['rs_err']) ? sanitize_text_field(wp_unslash((string) $_GET['rs_err'])) : '';
    ?>
    <div class="sa-container sa-rs-narrow">
      <h1>Request roadside help</h1>
      <p class="sa-svc-lead">Takes under a minute. No account and no payment needed. We call you to confirm the price before anyone is sent.</p>
      <?php if ($cc === 'US') : ?><div class="sa-svc-alert sa-rs-us">Roadside Rescue covers Nairobi, Kenya only. You seem to be in the United States, so we can't send a responder to you, but you can still request help for someone in Nairobi.</div><?php endif; ?>
      <?php if ($err !== '') : ?><div class="sa-svc-alert sa-svc-alert--err" role="alert"><?php echo esc_html($err); ?></div><?php endif; ?>
      <form class="sa-rs-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-rs-form>
        <input type="hidden" name="action" value="sa_rs_request"><?php wp_nonce_field('sa_rs_request', 'sa_rs_nonce'); ?>
        <input type="text" name="sa_hp" value="" tabindex="-1" autocomplete="off" class="sa-hp" aria-hidden="true">
        <fieldset><legend>1. What do you need?</legend>
          <div class="sa-rs-types">
          <?php foreach ($cat as $slug => $s) : ?>
            <label class="sa-rs-type"><input type="radio" name="type" value="<?php echo esc_attr($slug); ?>" required <?php checked($type, $slug); ?>><span><?php echo sa_rs_icon($s['icon']); ?><strong><?php echo esc_html($s['name']); ?></strong><small><?php echo esc_html(sa_rs_price_label($s) === 'Quote on request' ? 'Quote on call' : sa_rs_price_label($s)); ?></small></span></label>
          <?php endforeach; ?>
          </div>
          <label class="sa-rs-dest" data-rs-dest>Tow to <input name="dest" list="rs-dest" placeholder="Our Njiru garage, your mechanic, home…"><datalist id="rs-dest"><option value="Supreme Autoparts garage, Midax Plaza, Njiru"></option></datalist></label>
        </fieldset>
        <fieldset><legend>2. Where are you?</legend>
          <button type="button" class="sa-svc-btn sa-svc-btn--primary sa-rs-locate" data-rs-locate><?php echo sa_rs_icon('locate'); ?> Use my current location</button>
          <?php if (is_array($last) && !empty($last['lat'])) : ?><button type="button" class="sa-svc-btn sa-svc-btn--ghost sa-svc-btn--sm" data-rs-saved='<?php echo esc_attr(wp_json_encode($last)); ?>'>Use my saved location</button><?php endif; ?>
          <p class="sa-rs-locmsg" data-rs-locmsg aria-live="polite">Or tap the map to drop a pin, or type an address or landmark below.</p>
          <div class="sa-rs-map" data-rs-map data-mode="pick"></div>
          <input type="hidden" name="lat" data-rs-lat><input type="hidden" name="lng" data-rs-lng>
          <label>Address or road <input name="address" data-rs-address placeholder="e.g. Thika Road, near Garden City exit" autocomplete="street-address"></label>
          <label>Landmark <input name="landmark" placeholder="e.g. opposite Shell petrol station"></label>
        </fieldset>
        <fieldset><legend>3. Your car</legend>
          <?php if ($veh) : ?><label>Saved vehicle <select data-rs-veh><option value="">Choose…</option><?php foreach ($veh as $v) echo '<option value="' . esc_attr(wp_json_encode($v)) . '">' . esc_html(trim($v['year'] . ' ' . $v['make'] . ' ' . $v['model'] . ' ' . $v['reg'])) . '</option>'; ?></select></label><?php endif; ?>
          <div class="sa-svc-row"><label>Make <input name="make" placeholder="Toyota"></label><label>Model <input name="model" placeholder="Fielder"></label></div>
          <div class="sa-svc-row"><label>Year <input name="year" type="number" inputmode="numeric" min="1950" max="<?php echo (int) gmdate('Y') + 1; ?>"></label><label>Number plate <input name="reg" placeholder="KDA 123A"></label></div>
        </fieldset>
        <fieldset><legend>4. How do we reach you?</legend>
          <label>Your name <input name="name" required autocomplete="name" value="<?php echo esc_attr(trim($u->first_name . ' ' . $u->last_name) ?: ''); ?>"></label>
          <div class="sa-svc-row"><label>Phone <input name="phone" type="tel" required autocomplete="tel" placeholder="07xx xxx xxx" value="<?php echo esc_attr((string) get_user_meta($u->ID, 'billing_phone', true)); ?>"></label><label>WhatsApp (if different) <input name="whatsapp" type="tel"></label></div>
          <label>Email for your status link (optional) <input name="email" type="email" autocomplete="email" value="<?php echo esc_attr($u->user_email); ?>"></label>
        </fieldset>
        <fieldset><legend>5. Anything else?</legend>
          <label>Note <textarea name="note" rows="3" placeholder="What happened? Any warning lights? Is the car safely off the road?"></textarea></label>
          <label>Photo (optional) <input type="file" name="photo" accept="image/*" capture="environment"></label>
          <div class="sa-svc-row"><label class="sa-rs-inline"><input type="radio" name="when" value="now" checked> Help now</label><label class="sa-rs-inline"><input type="radio" name="when" value="later"> Schedule for later <input type="datetime-local" name="when_at"></label></div>
        </fieldset>
        <label class="sa-rs-inline sa-rs-agree"><input type="checkbox" name="agree" value="1" required> I accept the <a href="<?php echo esc_url(home_url('/roadside-terms/')); ?>" target="_blank">Roadside Terms</a>. I understand the price is agreed on the call.</label>
        <button class="sa-svc-btn sa-svc-btn--primary sa-rs-btn--big sa-rs-submit" type="submit">Send request</button>
        <p class="sa-svc-fine">Kenya (Nairobi and environs) only. In danger or injured? Call 999 or 112 first.</p>
      </form>
    </div>
    <?php echo sa_rs_cta_bar(); ?>
    <?php
}

function sa_rs_timeline(array $r): string
{
    $order = ['received', 'dispatched', 'en_route', 'arrived', 'completed'];
    $at = [];
    foreach ($r['history'] as $h) {
        $at[$h['s']] = $at[$h['s']] ?? (int) $h['t'];
    }
    $cur = array_search($r['status'], $order, true);
    $o = '<ol class="sa-rs-steps">';
    foreach ($order as $i => $s) {
        $cls = $r['status'] === 'cancelled' ? '' : ($cur !== false && $i < $cur ? 'is-done' : ($i === $cur ? 'is-now' : ''));
        $o .= '<li class="' . $cls . '"><span></span><strong>' . esc_html(sa_rs_statuses()[$s]) . '</strong>' . (isset($at[$s]) ? '<em>' . esc_html(wp_date('g:i A', $at[$s])) . '</em>' : '') . '</li>';
    }
    return $o . '</ol>';
}

function sa_rs_render_status(): void
{
    $id = (int) ($_GET['r'] ?? 0);
    $k = sanitize_text_field(wp_unslash((string) ($_GET['k'] ?? '')));
    $r = get_post_type($id) === 'sa_roadside' ? sa_rs_data($id) : null;
    if (!$r || !sa_rs_can_view($r, $k)) {
        echo '<div class="sa-container sa-rs-narrow"><h1>Request not found</h1><p>Open the link from your email again, or <a href="' . esc_url(sa_rs_url('track')) . '">look it up</a> with your request number and phone.</p></div>';
        return;
    }
    $k = $r['key'];
    $svc = sa_rs_catalog()[$r['type']] ?? ['name' => $r['type'], 'icon' => 'service'];
    $prov = $r['provider'] ? sa_rs_provider_data($r['provider']) : null;
    $nonce = wp_nonce_field('sa_rs_customer_' . $id, '_wpnonce', true, false);
    $hidden = '<input type="hidden" name="action" value="sa_rs_customer"><input type="hidden" name="r" value="' . $id . '"><input type="hidden" name="k" value="' . esc_attr($k) . '">' . $nonce;
    $form = esc_url(admin_url('admin-post.php'));
    ?>
    <div class="sa-container sa-rs-narrow" id="rs-main" data-rs-status data-r="<?php echo $id; ?>" data-k="<?php echo esc_attr($k); ?>" data-lat="<?php echo esc_attr((string) $r['lat']); ?>" data-lng="<?php echo esc_attr((string) $r['lng']); ?>">
      <?php if (isset($_GET['new'])) : ?><div class="sa-svc-alert sa-svc-alert--ok"><strong>Request sent.</strong> Your request number is <strong><?php echo esc_html($r['ref']); ?></strong>. Keep this page open, or use the link we emailed you. We'll call you shortly.</div><?php endif; ?>
      <?php if (isset($_GET['rated'])) : ?><div class="sa-svc-alert sa-svc-alert--ok">Thank you for your rating.</div><?php endif; ?>
      <p class="sa-svc-eyebrow">Request <?php echo esc_html($r['ref']); ?></p>
      <h1 class="sa-rs-stat"><span data-rs-label><?php echo esc_html(sa_rs_statuses()[$r['status']]); ?></span></h1>
      <p class="sa-svc-lead" data-rs-text><?php echo esc_html(sa_rs_status_text($r['status'])); ?></p>
      <p class="sa-rs-eta" data-rs-eta<?php echo $r['eta_at'] && in_array($r['status'], ['dispatched', 'en_route'], true) ? '' : ' hidden'; ?>><?php echo sa_rs_icon('clock'); ?> ETA about <strong data-rs-eta-min><?php echo (int) max(0, ceil(((int) $r['eta_at'] - time()) / 60)); ?></strong> min</p>
      <?php echo sa_rs_timeline($r); ?>
      <div class="sa-rs-map sa-rs-map--status" data-rs-map data-mode="view"></div>
      <?php if ($prov) : ?>
        <div class="sa-rs-prov"><div><small>Your responder</small><strong><?php echo esc_html($prov['name']); ?></strong><?php if ($prov['avg']) echo '<span>' . sa_rs_icon('star') . esc_html(number_format($prov['avg'], 1)) . ' (' . (int) $prov['count'] . ')</span>'; ?></div>
        <?php if ($prov['phone'] && $r['status'] !== 'completed') : ?><a class="sa-svc-btn sa-svc-btn--sm sa-svc-btn--primary" href="tel:<?php echo esc_attr($prov['phone']); ?>"><?php echo sa_rs_icon('phone'); ?> Call</a><?php endif; ?></div>
      <?php endif; ?>
      <?php if ($r['status'] === 'received' && $r['offers']) : ?>
        <section class="sa-rs-card"><h2>Responders who can help</h2><p class="sa-svc-muted">Choose the one you prefer.</p>
        <?php foreach ($r['offers'] as $o) : $pd = sa_rs_provider_data((int) $o['p']); ?>
          <form class="sa-rs-offer" method="post" action="<?php echo $form; ?>"><?php echo $hidden; ?><input type="hidden" name="do" value="choose"><input type="hidden" name="provider" value="<?php echo (int) $o['p']; ?>">
            <div><strong><?php echo esc_html($pd['name']); ?></strong><span>ETA about <?php echo (int) $o['eta']; ?> min<?php echo $pd['avg'] ? ' · ' . esc_html(number_format($pd['avg'], 1)) . '★ (' . (int) $pd['count'] . ')' : ''; ?></span></div>
            <button class="sa-svc-btn sa-svc-btn--sm sa-svc-btn--primary">Choose</button></form>
        <?php endforeach; ?></section>
      <?php endif; ?>
      <?php if ($r['pay_order'] && !$r['paid'] && ($o = wc_get_order((int) $r['pay_order'])) && $o->needs_payment()) : ?>
        <section class="sa-rs-card sa-rs-pay"><div><small>Agreed amount</small><strong><?php echo esc_html((string) $r['pay_amount']); ?></strong></div><a class="sa-svc-btn sa-svc-btn--primary" href="<?php echo esc_url($o->get_checkout_payment_url()); ?>">Pay with Pesapal</a></section>
      <?php elseif ($r['paid']) : ?><div class="sa-svc-alert sa-svc-alert--ok">Payment received. Thank you.</div><?php endif; ?>

      <?php if ($r['status'] === 'completed' && !$r['rated']) : ?>
        <section class="sa-rs-card" id="rate"><h2>How did we do?</h2>
          <form method="post" action="<?php echo $form; ?>" class="sa-rs-rate"><?php echo $hidden; ?><input type="hidden" name="do" value="rate">
            <div class="sa-rs-stars" role="radiogroup" aria-label="Rating"><?php for ($i = 5; $i >= 1; $i--) echo '<input type="radio" id="rs-s' . $i . '" name="stars" value="' . $i . '" required><label for="rs-s' . $i . '" title="' . $i . ' stars">★</label>'; ?></div>
            <label>Feedback (optional) <textarea name="text" rows="3" placeholder="What went well? What could be better?"></textarea></label>
            <button class="sa-svc-btn sa-svc-btn--primary">Submit rating</button></form></section>
      <?php elseif ($r['rated']) : ?>
        <section class="sa-rs-card"><h2>Your rating</h2><p class="sa-rs-given"><?php echo str_repeat('★', (int) $r['rating']) . str_repeat('☆', 5 - (int) $r['rating']); ?></p><?php if ($r['feedback']) echo '<p>' . esc_html($r['feedback']) . '</p>'; ?></section>
      <?php endif; ?>

      <section class="sa-rs-card"><h2>Messages</h2>
        <div class="sa-rs-msgs"><?php if (!$r['messages']) echo '<p class="sa-svc-muted">No messages yet. Send us anything the responder should know.</p>';
        foreach ($r['messages'] as $m) echo '<div class="sa-rs-msg sa-rs-msg--' . esc_attr($m['f']) . '"><small>' . esc_html($m['w'] . ' · ' . wp_date('j M, g:i A', (int) $m['t'])) . '</small><p>' . esc_html($m['x']) . '</p></div>'; ?></div>
        <?php if (!in_array($r['status'], ['completed', 'cancelled'], true)) : ?><form method="post" action="<?php echo $form; ?>" class="sa-rs-msgform"><?php echo $hidden; ?><input type="hidden" name="do" value="msg"><input name="text" required placeholder="Type a message…" aria-label="Message"><button class="sa-svc-btn sa-svc-btn--sm sa-svc-btn--primary">Send</button></form><?php endif; ?>
      </section>

      <section class="sa-rs-card"><h2>Request details</h2><dl class="sa-rs-dl">
        <dt>Help needed</dt><dd><?php echo esc_html($svc['name']); ?></dd>
        <dt>Location</dt><dd><?php echo esc_html(trim($r['address'] . ($r['landmark'] ? ' · near ' . $r['landmark'] : '')) ?: 'Map pin'); ?></dd>
        <?php if ($r['dest']) : ?><dt>Tow to</dt><dd><?php echo esc_html($r['dest']); ?></dd><?php endif; ?>
        <dt>Vehicle</dt><dd><?php echo esc_html(trim($r['year'] . ' ' . $r['make'] . ' ' . $r['model'] . ' ' . $r['reg']) ?: '—'); ?></dd>
        <dt>When</dt><dd><?php echo esc_html($r['when'] === 'now' ? 'As soon as possible' : str_replace('T', ' ', (string) $r['when'])); ?></dd>
        <dt>Price</dt><dd><?php echo esc_html($r['quote'] ?: 'Confirmed on call'); ?></dd>
        <dt>Submitted</dt><dd><?php echo esc_html(wp_date('j M Y, g:i A', $r['created'])); ?> EAT</dd></dl>
        <?php if ($r['history']) : ?><details class="sa-rs-hist"><summary>Full history</summary><ul><?php foreach (array_reverse($r['history']) as $h) echo '<li><time>' . esc_html(wp_date('j M, g:i A', (int) $h['t'])) . '</time> <strong>' . esc_html(sa_rs_statuses()[$h['s']] ?? $h['s']) . '</strong> ' . esc_html((string) $h['n']) . '</li>'; ?></ul></details><?php endif; ?>
      </section>
      <?php if (in_array($r['status'], ['received', 'dispatched'], true)) : ?>
        <details class="sa-rs-cancel"><summary>Cancel this request</summary><form method="post" action="<?php echo $form; ?>"><?php echo $hidden; ?><input type="hidden" name="do" value="cancel"><label>Reason (optional) <input name="text" placeholder="e.g. car started"></label><button class="sa-svc-btn sa-svc-btn--sm sa-svc-btn--ghost" onclick="return confirm('Cancel this request?')">Cancel request</button></form></details>
      <?php endif; ?>
    </div>
    <?php echo sa_rs_cta_bar('Roadside request ' . $r['ref']); ?>
    <?php
}

function sa_rs_track_lookup(): void
{
    $ref = strtoupper(sanitize_text_field(wp_unslash((string) $_POST['ref'])));
    $phone = substr(preg_replace('/\D/', '', (string) ($_POST['phone'] ?? '')), -9);
    if (!wp_verify_nonce((string) ($_POST['_wpnonce'] ?? ''), 'sa_rs_track') || strlen($phone) < 9) {
        wp_safe_redirect(add_query_arg('nf', 1, sa_rs_url('track')));
        exit;
    }
    $ids = get_posts(['post_type' => 'sa_roadside', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_rs_ref', 'meta_value' => $ref]);
    if ($ids && substr(preg_replace('/\D/', '', (string) get_post_meta($ids[0], '_rs_phone', true)), -9) === $phone) {
        wp_safe_redirect(sa_rs_status_url((int) $ids[0]));
        exit;
    }
    wp_safe_redirect(add_query_arg('nf', 1, sa_rs_url('track')));
    exit;
}

function sa_rs_render_track(): void
{
    echo '<div class="sa-container sa-rs-narrow"><h1>Track a roadside request</h1><p class="sa-svc-lead">Enter your request number (SA-RS-…) and the phone number you used.</p>';
    if (isset($_GET['nf'])) {
        echo '<div class="sa-svc-alert sa-svc-alert--err">We couldn\'t find that request. Check the number, or call ' . esc_html(SA_RS_PHONE_LABEL) . '.</div>';
    }
    echo '<form method="post" class="sa-rs-form">' . wp_nonce_field('sa_rs_track', '_wpnonce', true, false) . '<label>Request number <input name="ref" required placeholder="SA-RS-261014-ABCD"></label><label>Phone <input name="phone" type="tel" required></label><button class="sa-svc-btn sa-svc-btn--primary">Track</button></form></div>';
}

function sa_rs_render_terms(): void
{
    $t = [
        'What this service is' => 'Supreme Autoparts (Standard Driveworks) arranges roadside help in Nairobi and its environs: towing, jump-starts, flat tyres, fuel delivery, lockouts, winching and minor on-site repairs. Help is provided by our own team or by vetted responders we work with.',
        'Requesting help' => 'You can request help without an account and without paying anything. A request is not a booking until we call you and you agree the price. Please give an accurate location and a phone number we can reach.',
        'Prices' => 'We agree the price with you on the phone before anyone is sent. Towing prices on our site are a guide only. Fuel, parts and batteries are charged on top at the price we tell you. Extra work found on site is only done if you agree.',
        'Paying' => 'You pay when the job is done: to the responder, or through a secure Pesapal link (M-Pesa or card) that we send you. We never ask for payment before agreeing the price with you.',
        'Arrival times' => 'ETAs are our best estimate and can change with traffic, weather and road conditions. We keep your status page updated.',
        'Cancelling' => 'You can cancel free of charge from your status page before the responder is on the way. If you cancel after they have set off, we may charge a call-out amount, which we will tell you before you cancel.',
        'Your car and belongings' => 'Remove valuables before towing. For lockouts we ask for proof that the car is yours (ID plus logbook or insurance). We may refuse a job that is unsafe or unlawful.',
        'Safety' => 'If anyone is injured or in danger, call 999 or 112 first. Stay away from moving traffic while you wait.',
        'Damage and complaints' => 'We take care with every vehicle. Tell us about any problem within 48 hours on ' . SA_RS_PHONE_LABEL . ' and we will look into it. Nothing in these terms limits your rights under Kenyan consumer law.',
        'Ratings' => 'After a job you can rate it. We only show real ratings from customers who used the service, and we do not edit what you write. We may hide abusive content.',
        'Your data' => 'We use your name, phone, location and car details only to arrange help, keep you updated and keep records. Your live location is used for this request only.',
    ];
    echo '<div class="sa-container sa-rs-narrow sa-rs-terms"><h1>Roadside Terms</h1><p class="sa-svc-lead">Plain-language terms for Roadside Rescue &amp; Assistance. Last updated ' . esc_html(wp_date('j F Y', strtotime('2026-10-14'))) . '.</p>';
    foreach ($t as $h => $p) {
        echo '<h2>' . esc_html($h) . '</h2><p>' . esc_html($p) . '</p>';
    }
    echo '</div>';
}

/* ---------- Ratings ---------- */

function sa_rs_rating_summary(int $provider = 0): array
{
    $mq = [['key' => '_rs_rated', 'compare' => 'EXISTS'], ['key' => '_rs_review_hidden', 'compare' => 'NOT EXISTS']];
    if ($provider) {
        $mq[] = ['key' => '_rs_provider', 'value' => (string) $provider];
    }
    $ids = get_posts(['post_type' => 'sa_roadside', 'numberposts' => -1, 'fields' => 'ids', 'meta_query' => $mq]);
    $sum = 0;
    foreach ($ids as $i) {
        $sum += (int) get_post_meta($i, '_rs_rating', true);
    }
    return ['count' => count($ids), 'avg' => $ids ? $sum / count($ids) : 0.0];
}

/* ---------- My Account ---------- */

add_filter('woocommerce_get_query_vars', static function (array $v): array {
    $v['roadside'] = 'roadside';
    return $v;
});
add_filter('woocommerce_account_menu_items', static function (array $items): array {
    $out = [];
    foreach ($items as $k => $l) {
        $out[$k] = $l;
        if ($k === 'bookings' || ($k === 'orders' && !isset($items['bookings']))) {
            $out['roadside'] = 'Roadside requests';
        }
    }
    if (!isset($out['roadside'])) {
        $out['roadside'] = 'Roadside requests';
    }
    return $out;
}, 30);
add_action('woocommerce_account_roadside_endpoint', static function (): void {
    $u = wp_get_current_user();
    $ids = get_posts(['post_type' => 'sa_roadside', 'numberposts' => 50, 'fields' => 'ids', 'meta_query' => ['relation' => 'OR', ['key' => '_rs_user', 'value' => (string) $u->ID], ['key' => '_rs_email', 'value' => $u->user_email]]]);
    echo '<div class="sa-svc sa-rs"><div class="sa-svc-head"><h2>Roadside requests</h2><a class="sa-svc-btn sa-svc-btn--sm sa-svc-btn--primary" href="' . esc_url(sa_rs_url('request')) . '">Request help</a></div>';
    if (!$ids) {
        echo '<p class="sa-svc-muted">No roadside requests yet. If you ever get stuck, <a href="' . esc_url(sa_rs_url()) . '">we can come to you</a>.</p></div>';
        return;
    }
    echo '<div class="sa-rs-acct">';
    foreach ($ids as $id) {
        $r = sa_rs_data((int) $id);
        echo '<a class="sa-rs-acct__row" href="' . esc_url(sa_rs_status_url((int) $id)) . '"><span><strong>' . esc_html($r['ref']) . '</strong><small>' . esc_html((sa_rs_catalog()[$r['type']]['name'] ?? $r['type']) . ' · ' . wp_date('j M Y', $r['created'])) . '</small></span>'
            . '<span class="sa-rs-badge sa-rs-badge--' . esc_attr($r['status']) . '">' . esc_html(sa_rs_statuses()[$r['status']]) . '</span>'
            . ($r['status'] === 'completed' && !$r['rated'] ? '<em>Rate it</em>' : ($r['rated'] ? '<em>' . str_repeat('★', (int) $r['rating']) . '</em>' : '')) . '</a>';
    }
    echo '</div></div>';
});
add_action('woocommerce_account_dashboard', static function (): void {
    echo '<p class="sa-rs-acct-tip">Stuck on the road? <a href="' . esc_url(sa_rs_url('request')) . '">Request roadside help</a> or see your <a href="' . esc_url(wc_get_account_endpoint_url('roadside')) . '">roadside requests</a>.</p>';
}, 25);

/* ---------- Pesapal payment link: mark paid ---------- */

add_action('woocommerce_order_status_changed', static function ($oid, $from, $to, $order): void {
    if (!$order instanceof WC_Order || !in_array($to, ['processing', 'completed'], true)) {
        return;
    }
    $rid = (int) $order->get_meta('_sa_rs_for');
    if ($rid && get_post_type($rid) === 'sa_roadside' && !get_post_meta($rid, '_rs_paid', true)) {
        sa_rs_set($rid, 'paid', time());
        sa_rs_log($rid, (string) get_post_meta($rid, '_rs_status', true), 'Payment received via Pesapal (order #' . $order->get_id() . ')', 'Pesapal');
        sa_rs_email($rid, 'admin_alert', 'Payment received for ' . get_post_meta($rid, '_rs_ref', true));
    }
}, 10, 4);

/* Pay-link orders are a service payment: no shipping. */
add_filter('woocommerce_order_needs_shipping_address', static function ($needs, $hide, $order) {
    return $order instanceof WC_Order && $order->get_meta('_sa_rs_for') ? false : $needs;
}, 10, 3);

require_once SA_CORE_DIR . 'includes/roadside-provider.php';
require_once SA_CORE_DIR . 'includes/roadside-admin.php';
