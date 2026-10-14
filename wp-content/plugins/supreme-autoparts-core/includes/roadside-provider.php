<?php
/**
 * Roadside providers (mechanics / tow operators): data, sign-in (WP role sa_rs_provider or a
 * private dashboard link), mobile dashboard at /roadside/provider/, job matching by distance,
 * offers/accept, status updates with ETA, live location sharing, chat, earnings, rating appeals,
 * and the public "Become a responder" application.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

function sa_rs_provider_data(int $pid): array
{
    $m = static fn(string $k) => get_post_meta($pid, '_rsp_' . $k, true);
    $sum = sa_rs_rating_summary($pid);
    $svc = $m('services');
    return ['id' => $pid, 'name' => get_the_title($pid), 'phone' => (string) $m('phone'), 'email' => (string) $m('email'), 'services' => is_array($svc) ? $svc : array_keys(sa_rs_catalog()),
        'radius' => (int) ($m('radius') ?: sa_rs_setting('radius')), 'active' => $m('active') !== '0', 'available' => $m('available') === '1', 'approved' => $m('approved') !== '0',
        'lat' => $m('lat'), 'lng' => $m('lng'), 'lts' => (int) $m('lts'), 'user' => (int) $m('user'), 'token' => (string) $m('token'), 'vehicle' => (string) $m('vehicle'),
        'area' => (string) $m('area'), 'avg' => $sum['avg'], 'count' => $sum['count']];
}

function sa_rs_provider_link(int $pid): string
{
    $t = (string) get_post_meta($pid, '_rsp_token', true);
    if ($t === '') {
        $t = wp_generate_password(40, false, false);
        update_post_meta($pid, '_rsp_token', $t);
    }
    return add_query_arg('pt', $pid . '.' . $t, sa_rs_url('provider'));
}

function sa_rs_create_provider(array $a): int
{
    $pid = wp_insert_post(['post_type' => 'sa_rs_provider', 'post_status' => 'publish', 'post_title' => $a['name']], true);
    if (is_wp_error($pid)) {
        return 0;
    }
    foreach (['phone', 'email', 'services', 'radius', 'vehicle', 'area', 'user'] as $k) {
        if (isset($a[$k])) {
            update_post_meta($pid, '_rsp_' . $k, $a[$k]);
        }
    }
    update_post_meta($pid, '_rsp_active', $a['active'] ?? '1');
    update_post_meta($pid, '_rsp_approved', $a['approved'] ?? '1');
    update_post_meta($pid, '_rsp_available', '1');
    sa_rs_provider_link((int) $pid);
    return (int) $pid;
}

/** The in-house team is always a provider, so dispatch works before any partner joins. */
function sa_rs_house_provider_id(): int
{
    $id = (int) get_option('sa_rs_house_provider', 0);
    if ($id && get_post_type($id) === 'sa_rs_provider') {
        return $id;
    }
    $id = sa_rs_create_provider(['name' => 'Supreme Autoparts Rescue Team', 'phone' => SA_RS_PHONE_LABEL, 'area' => 'Njiru, Nairobi', 'radius' => 60,
        'email' => (string) get_option('woocommerce_email_from_address', get_option('admin_email'))]);
    if ($id) {
        update_option('sa_rs_house_provider', $id);
    }
    return $id;
}
add_action('init', static function (): void {
    if (did_action('init') && !get_option('sa_rs_house_provider')) {
        sa_rs_house_provider_id();
    }
}, 40);

function sa_rs_current_provider(): int
{
    static $cur = null;
    if ($cur !== null) {
        return $cur;
    }
    $cur = 0;
    $uid = get_current_user_id();
    if ($uid) {
        $ids = get_posts(['post_type' => 'sa_rs_provider', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_rsp_user', 'meta_value' => (string) $uid]);
        if ($ids) {
            $cur = (int) $ids[0];
        }
    }
    if (!$cur && !empty($_COOKIE['sa_rs_pt'])) {
        [$pid, $tok] = array_pad(explode('.', (string) $_COOKIE['sa_rs_pt'], 2), 2, '');
        $pid = (int) $pid;
        if ($pid && get_post_type($pid) === 'sa_rs_provider' && $tok !== '' && hash_equals((string) get_post_meta($pid, '_rsp_token', true), $tok)) {
            $cur = $pid;
        }
    }
    if ($cur && (get_post_meta($cur, '_rsp_active', true) === '0' || get_post_meta($cur, '_rsp_approved', true) === '0')) {
        $cur = 0;
    }
    return $cur;
}

/** ?pt=ID.TOKEN → secure cookie, then a clean URL (token never stays in the address bar). */
function sa_rs_provider_gate(): void
{
    if (!empty($_GET['pt'])) {
        $v = sanitize_text_field(wp_unslash((string) $_GET['pt']));
        setcookie('sa_rs_pt', $v, ['expires' => time() + 60 * DAY_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE['sa_rs_pt'] = $v;
        wp_safe_redirect(sa_rs_url('provider'));
        exit;
    }
    if (isset($_GET['signout'])) {
        setcookie('sa_rs_pt', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
        wp_safe_redirect(sa_rs_url('provider'));
        exit;
    }
}

/** Jobs a provider can see: open requests within radius offering their services + their own jobs. */
function sa_rs_provider_jobs(int $pid): array
{
    $p = sa_rs_provider_data($pid);
    $open = [];
    $mine = [];
    $done = [];
    $ids = get_posts(['post_type' => 'sa_roadside', 'numberposts' => 200, 'fields' => 'ids', 'date_query' => [['after' => '60 days ago']]]);
    foreach ($ids as $id) {
        $r = sa_rs_data((int) $id);
        $r['km'] = ($p['lat'] !== '' && $r['lat'] !== '') ? sa_rs_km((float) $p['lat'], (float) $p['lng'], (float) $r['lat'], (float) $r['lng']) : null;
        if ($r['provider'] === $pid) {
            if (in_array($r['status'], ['completed', 'cancelled'], true)) {
                $done[] = $r;
            } else {
                $mine[] = $r;
            }
        } elseif ($r['status'] === 'received' && in_array($r['type'], $p['services'], true)
            && !in_array($pid, sa_rs_meta_list($id, '_rs_declined'), true)
            && ($r['km'] === null || $r['km'] <= $p['radius'])) {
            $open[] = $r;
        }
    }
    usort($open, static fn($a, $b) => ($a['km'] ?? 9999) <=> ($b['km'] ?? 9999));
    return ['open' => $open, 'mine' => $mine, 'done' => $done];
}

function sa_rs_notify_providers(int $id): void
{
    if (sa_rs_setting('notify_providers') !== '1') {
        return;
    }
    $r = sa_rs_data($id);
    foreach (get_posts(['post_type' => 'sa_rs_provider', 'numberposts' => 50, 'fields' => 'ids']) as $pid) {
        $p = sa_rs_provider_data((int) $pid);
        if ((int) $pid === sa_rs_house_provider_id() || !$p['active'] || !$p['approved'] || !$p['available'] || !is_email($p['email']) || !in_array($r['type'], $p['services'], true)) {
            continue; // The in-house team already gets the admin alert.
        }
        if ($p['lat'] !== '' && $r['lat'] !== '' && sa_rs_km((float) $p['lat'], (float) $p['lng'], (float) $r['lat'], (float) $r['lng']) > $p['radius']) {
            continue;
        }
        sa_rs_provider_email((int) $pid, $id, 'new');
    }
}

function sa_rs_provider_email(int $pid, int $id, string $kind): void
{
    $p = sa_rs_provider_data($pid);
    if (!is_email($p['email'])) {
        return;
    }
    $r = sa_rs_data($id);
    $svc = sa_rs_catalog()[$r['type']]['name'] ?? $r['type'];
    $subject = ($kind === 'new' ? 'New roadside job near you: ' : 'Job assigned to you: ') . $svc . ' (' . $r['ref'] . ')';
    $body = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:auto;color:#1d2433"><p>' . sa_email_greeting((string) $p['name']) . '</p><p>'
        . ($kind === 'new' ? 'A new <strong>' . esc_html($svc) . '</strong> request is waiting near you.' : 'Request <strong>' . esc_html($r['ref']) . '</strong> (' . esc_html($svc) . ') has been assigned to you. Customer: ' . esc_html($r['name'] . ' · ' . $r['phone']))
        . '</p><p>Location: ' . esc_html(trim($r['address'] . ' ' . $r['landmark']) ?: 'map pin') . '</p>'
        . '<p><a href="' . esc_url(sa_rs_provider_link($pid)) . '" style="display:inline-block;background:#0a0a0a;color:#fff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:10px">Open responder dashboard</a></p></div>';
    wp_mail($p['email'], $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
}

/* ---------- Provider actions (AJAX, JSON) ---------- */

add_action('wp_ajax_sa_rs_pv', 'sa_rs_provider_ajax');
add_action('wp_ajax_nopriv_sa_rs_pv', 'sa_rs_provider_ajax');
function sa_rs_provider_ajax(): void
{
    $pid = sa_rs_current_provider();
    if (!$pid || !wp_verify_nonce((string) ($_POST['_n'] ?? ''), 'sa_rs_pv_' . $pid)) {
        wp_send_json_error(['msg' => 'Please open your dashboard link again.'], 403);
    }
    $do = sanitize_key((string) ($_POST['do'] ?? ''));
    $id = (int) ($_POST['r'] ?? 0);
    $p = sa_rs_provider_data($pid);
    $txt = sanitize_textarea_field(wp_unslash((string) ($_POST['text'] ?? '')));
    if ($do === 'loc') {
        $lat = (float) ($_POST['lat'] ?? 0);
        $lng = (float) ($_POST['lng'] ?? 0);
        if (abs($lat) > 90 || abs($lng) > 180 || ($lat == 0.0 && $lng == 0.0)) {
            wp_send_json_error(['msg' => 'Bad location']);
        }
        update_post_meta($pid, '_rsp_lat', round($lat, 6));
        update_post_meta($pid, '_rsp_lng', round($lng, 6));
        update_post_meta($pid, '_rsp_lts', time());
        foreach (sa_rs_provider_jobs($pid)['mine'] as $j) {
            if (in_array($j['status'], ['dispatched', 'en_route', 'arrived'], true)) {
                sa_rs_set($j['id'], 'plat', round($lat, 6));
                sa_rs_set($j['id'], 'plng', round($lng, 6));
                sa_rs_set($j['id'], 'pts', time());
            }
        }
        wp_send_json_success(['msg' => 'Location shared ' . wp_date('g:i:s A')]);
    }
    if ($do === 'avail') {
        update_post_meta($pid, '_rsp_available', $p['available'] ? '0' : '1');
        wp_send_json_success(['reload' => 1]);
    }
    if ($do === 'profile') {
        $g = static fn(string $k): string => sanitize_text_field(wp_unslash((string) ($_POST[$k] ?? '')));
        if ($g('phone') !== '') update_post_meta($pid, '_rsp_phone', $g('phone'));
        if ($g('vehicle') !== '') update_post_meta($pid, '_rsp_vehicle', $g('vehicle'));
        update_post_meta($pid, '_rsp_radius', max(5, min(200, (int) $g('radius'))));
        $sv = array_values(array_intersect(array_map('sanitize_key', (array) ($_POST['services'] ?? [])), array_keys(sa_rs_catalog())));
        if ($sv) update_post_meta($pid, '_rsp_services', $sv);
        wp_send_json_success(['reload' => 1]);
    }
    if (get_post_type($id) !== 'sa_roadside') {
        wp_send_json_error(['msg' => 'Unknown job']);
    }
    $r = sa_rs_data($id);
    $eta = max(0, min(600, (int) ($_POST['eta'] ?? 0)));
    $who = $p['name'];
    if ($do === 'offer' && $r['status'] === 'received' && in_array($r['type'], $p['services'], true)) {
        if (sa_rs_setting('customer_choice') === '1' && $pid !== sa_rs_house_provider_id()) {
            $offers = array_values(array_filter($r['offers'], static fn($o) => (int) $o['p'] !== $pid));
            $offers[] = ['p' => $pid, 'eta' => $eta ?: 45, 't' => time()];
            sa_rs_set($id, 'offers', $offers);
            sa_rs_log($id, 'received', $who . ' offered to help (ETA ~' . ($eta ?: 45) . ' min)', $who);
            sa_rs_email($id, 'message', 'A responder is ready to help. Open your status page to choose.');
        } else {
            sa_rs_assign($id, $pid, $eta, 'Accepted by responder.');
        }
        wp_send_json_success(['reload' => 1]);
    }
    if ($do === 'decline') {
        $d = sa_rs_meta_list($id, '_rs_declined');
        $d[] = $pid;
        update_post_meta($id, '_rs_declined', array_values(array_unique($d)));
        if ($r['provider'] === $pid && $r['status'] === 'dispatched') {
            sa_rs_set($id, 'provider', 0);
            sa_rs_set_status($id, 'received', 'Responder released the job; we are finding another.', $who);
            sa_rs_email($id, 'admin_alert', $who . ' released ' . $r['ref']);
        }
        wp_send_json_success(['reload' => 1]);
    }
    if ($r['provider'] !== $pid) {
        wp_send_json_error(['msg' => 'This job is not assigned to you.']);
    }
    if ($do === 'msg' && $txt !== '') {
        sa_rs_add_message($id, 'team', $who, $txt);
        sa_rs_email($id, 'message', $txt);
        wp_send_json_success(['reload' => 1]);
    }
    $flow = ['en_route' => ['dispatched'], 'arrived' => ['dispatched', 'en_route'], 'completed' => ['arrived', 'en_route', 'dispatched']];
    if (isset($flow[$do]) && in_array($r['status'], $flow[$do], true)) {
        if ($do === 'en_route' && $eta > 0) {
            sa_rs_set($id, 'eta', $eta);
            sa_rs_set($id, 'eta_at', time() + $eta * 60);
        }
        if ($do === 'completed' && $txt !== '') {
            sa_rs_set($id, 'quote', mb_substr($txt, 0, 120));
        }
        $note = $do === 'en_route' ? ($eta ? 'ETA about ' . $eta . ' min.' : '') : ($do === 'completed' && $txt !== '' ? 'Amount: ' . $txt : '');
        sa_rs_set_status($id, $do, $note, $who);
        if ($do === 'completed') {
            sa_rs_email($id, 'admin_alert', $r['ref'] . ' completed by ' . $who . ($txt !== '' ? ' (' . $txt . ')' : ''));
        }
        wp_send_json_success(['reload' => 1]);
    }
    if ($do === 'appeal' && $r['rated'] && $txt !== '') {
        sa_rs_set($id, 'review_appeal', ['t' => time(), 'x' => mb_substr($txt, 0, 800), 'state' => 'open']);
        sa_rs_email($id, 'admin_alert', $who . ' appealed the rating on ' . $r['ref'] . ': ' . $txt);
        wp_send_json_success(['reload' => 1]);
    }
    wp_send_json_error(['msg' => 'That action is not available for this job right now.']);
}

/* ---------- Provider dashboard ---------- */

function sa_rs_job_card(array $r, string $mode): string
{
    $svc = sa_rs_catalog()[$r['type']]['name'] ?? $r['type'];
    $nav = $r['lat'] !== '' ? 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($r['lat'] . ',' . $r['lng']) : 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($r['address'] . ' ' . $r['landmark'] . ' Nairobi');
    $o = '<article class="sa-rs-job" data-job="' . (int) $r['id'] . '"><header><strong>' . esc_html($svc) . '</strong><span class="sa-rs-badge sa-rs-badge--' . esc_attr($r['status']) . '">' . esc_html(sa_rs_statuses()[$r['status']]) . '</span></header>'
        . '<p class="sa-rs-job__ref">' . esc_html($r['ref']) . ' · ' . esc_html(human_time_diff($r['created']) . ' ago') . ($r['km'] !== null ? ' · ' . esc_html(number_format((float) $r['km'], 1)) . ' km away' : '') . '</p>'
        . '<p>' . esc_html(trim($r['address'] . ($r['landmark'] ? ' · near ' . $r['landmark'] : '')) ?: 'Map pin only') . '</p>'
        . '<p class="sa-svc-muted">' . esc_html(trim($r['year'] . ' ' . $r['make'] . ' ' . $r['model'] . ' ' . $r['reg'])) . ($r['dest'] ? ' · Tow to: ' . esc_html($r['dest']) : '') . '</p>'
        . ($r['note'] ? '<p class="sa-rs-job__note">' . esc_html($r['note']) . '</p>' : '')
        . ($r['photo'] ? '<p><a href="' . esc_url((string) wp_get_attachment_url((int) $r['photo'])) . '" target="_blank">View customer photo</a></p>' : '');
    if ($mode === 'open') {
        $o .= '<div class="sa-rs-job__act"><label>ETA (min) <input type="number" min="5" max="600" value="30" data-eta></label><button class="sa-svc-btn sa-svc-btn--sm sa-svc-btn--primary" data-pv="offer">Accept job</button><button class="sa-svc-btn sa-svc-btn--sm sa-svc-btn--ghost" data-pv="decline">Not for me</button></div>';
    } elseif ($mode === 'mine') {
        $o .= '<div class="sa-rs-job__contact"><a class="sa-svc-btn sa-svc-btn--sm sa-svc-btn--primary" href="tel:' . esc_attr($r['phone']) . '">Call ' . esc_html($r['name']) . '</a><a class="sa-svc-btn sa-svc-btn--sm sa-rs-btn--wa" target="_blank" rel="noopener" href="https://wa.me/' . esc_attr(ltrim(preg_replace('/^0/', '254', (string) $r['whatsapp']), '+')) . '">WhatsApp</a><a class="sa-svc-btn sa-svc-btn--sm sa-svc-btn--ghost" target="_blank" rel="noopener" href="' . esc_url($nav) . '">Navigate</a></div><div class="sa-rs-job__act">';
        if ($r['status'] === 'dispatched') {
            $o .= '<label>ETA (min) <input type="number" min="1" max="600" value="' . (int) ($r['eta'] ?: 30) . '" data-eta></label><button class="sa-svc-btn sa-svc-btn--sm sa-svc-btn--primary" data-pv="en_route">On my way</button>';
        }
        if (in_array($r['status'], ['dispatched', 'en_route'], true)) {
            $o .= '<button class="sa-svc-btn sa-svc-btn--sm sa-svc-btn--primary" data-pv="arrived">I\'ve arrived</button>';
        }
        if (in_array($r['status'], ['dispatched', 'en_route', 'arrived'], true)) {
            $o .= '<label>Amount charged <input data-text placeholder="e.g. KES 2,000 cash"></label><button class="sa-svc-btn sa-svc-btn--sm sa-svc-btn--primary" data-pv="completed">Job completed</button>';
        }
        if ($r['status'] === 'dispatched') {
            $o .= '<button class="sa-svc-btn sa-svc-btn--sm sa-svc-btn--ghost" data-pv="decline">Release job</button>';
        }
        $o .= '</div><div class="sa-rs-msgs">';
        foreach (array_slice($r['messages'], -5) as $m) {
            $o .= '<div class="sa-rs-msg sa-rs-msg--' . esc_attr($m['f']) . '"><small>' . esc_html($m['w']) . '</small><p>' . esc_html($m['x']) . '</p></div>';
        }
        $o .= '</div><div class="sa-rs-msgform"><input data-msg placeholder="Message the customer…"><button class="sa-svc-btn sa-svc-btn--sm sa-svc-btn--ghost" data-pv="msg">Send</button></div>';
    } else {
        $o .= '<p>' . esc_html($r['quote'] ?: '') . ($r['rated'] ? ' · Rating: ' . str_repeat('★', (int) $r['rating']) . ($r['feedback'] ? ' “' . esc_html($r['feedback']) . '”' : '') : '') . '</p>';
        if ($r['rated'] && empty($r['review_appeal'])) {
            $o .= '<details><summary>Appeal this rating</summary><div class="sa-rs-msgform"><input data-text placeholder="Why is this rating unfair?"><button class="sa-svc-btn sa-svc-btn--sm sa-svc-btn--ghost" data-pv="appeal">Send appeal</button></div></details>';
        } elseif (!empty($r['review_appeal'])) {
            $o .= '<p class="sa-svc-muted">Appeal: ' . esc_html((string) ($r['review_appeal']['state'] ?? 'open')) . '</p>';
        }
    }
    return $o . '</article>';
}

function sa_rs_render_provider(): void
{
    $pid = sa_rs_current_provider();
    if (!$pid) {
        echo '<div class="sa-container sa-rs-narrow"><h1>Responder dashboard</h1><p class="sa-svc-lead">Open the private dashboard link we sent you, or sign in with your responder account.</p>'
            . '<p><a class="sa-svc-btn sa-svc-btn--primary" href="' . esc_url(wp_login_url(sa_rs_url('provider'))) . '">Sign in</a> <a class="sa-svc-btn sa-svc-btn--ghost" href="' . esc_url(sa_rs_url('join')) . '">Become a responder</a></p></div>';
        return;
    }
    $p = sa_rs_provider_data($pid);
    $jobs = sa_rs_provider_jobs($pid);
    $earn = 0;
    $week = 0;
    foreach ($jobs['done'] as $d) {
        if ($d['status'] === 'completed') {
            $earn++;
            $week += $d['created'] > time() - WEEK_IN_SECONDS ? 1 : 0;
        }
    }
    ?>
    <div class="sa-container sa-rs-pv" data-rs-pv data-n="<?php echo esc_attr(wp_create_nonce('sa_rs_pv_' . $pid)); ?>" data-share="<?php echo $jobs['mine'] ? '1' : '0'; ?>">
      <header class="sa-rs-pv__head"><div><small>Responder</small><h1><?php echo esc_html($p['name']); ?></h1>
        <span class="sa-svc-muted"><?php echo $p['avg'] ? esc_html(number_format($p['avg'], 1)) . '★ from ' . (int) $p['count'] . ' rating' . ($p['count'] === 1 ? '' : 's') . ' · ' : ''; ?><?php echo (int) $earn; ?> jobs completed · <?php echo (int) $week; ?> this week</span></div>
        <button class="sa-rs-avail <?php echo $p['available'] ? 'is-on' : ''; ?>" data-pv="avail"><?php echo $p['available'] ? 'Available' : 'Offline'; ?></button></header>
      <div class="sa-rs-pv__loc"><button class="sa-svc-btn sa-svc-btn--sm sa-svc-btn--primary" data-rs-share><?php echo sa_rs_icon('locate'); ?> Share my location</button><span data-rs-sharemsg class="sa-svc-muted"><?php echo $p['lts'] ? 'Last shared ' . esc_html(human_time_diff($p['lts'])) . ' ago' : 'Not shared yet. Sharing lets us match nearby jobs and shows customers where you are.'; ?></span></div>
      <p class="sa-rs-pv__err" data-rs-err role="alert" hidden></p>
      <h2>My active jobs (<?php echo count($jobs['mine']); ?>)</h2>
      <?php echo $jobs['mine'] ? implode('', array_map(static fn($r) => sa_rs_job_card($r, 'mine'), $jobs['mine'])) : '<p class="sa-svc-muted">No active jobs.</p>'; ?>
      <h2>Open requests near you (<?php echo count($jobs['open']); ?>)</h2>
      <?php echo !$p['available'] ? '<p class="sa-svc-muted">You are offline. Switch to Available to see new requests.</p>' : ($jobs['open'] ? implode('', array_map(static fn($r) => sa_rs_job_card($r, 'open'), $jobs['open'])) : '<p class="sa-svc-muted">No open requests in your ' . (int) $p['radius'] . ' km area right now. This page refreshes itself.</p>'); ?>
      <details class="sa-rs-card"><summary><strong>Job history (<?php echo count($jobs['done']); ?>)</strong></summary><?php echo implode('', array_map(static fn($r) => sa_rs_job_card($r, 'done'), $jobs['done'])); ?></details>
      <details class="sa-rs-card"><summary><strong>My profile</strong></summary>
        <form data-rs-profile class="sa-rs-form"><label>Phone <input name="phone" value="<?php echo esc_attr($p['phone']); ?>"></label><label>Vehicle <input name="vehicle" value="<?php echo esc_attr($p['vehicle']); ?>" placeholder="e.g. Flatbed KCX 123B"></label>
        <label>Job radius (km) <input name="radius" type="number" min="5" max="200" value="<?php echo (int) $p['radius']; ?>"></label><fieldset><legend>Services</legend><?php foreach (sa_rs_catalog() as $s => $c) echo '<label class="sa-rs-inline"><input type="checkbox" name="services[]" value="' . esc_attr($s) . '"' . checked(in_array($s, $p['services'], true), true, false) . '> ' . esc_html($c['name']) . '</label>'; ?></fieldset>
        <button class="sa-svc-btn sa-svc-btn--sm sa-svc-btn--primary" data-pv="profile">Save profile</button></form></details>
      <p class="sa-svc-fine"><a href="<?php echo esc_url(add_query_arg('signout', 1, sa_rs_url('provider'))); ?>">Sign out on this device</a></p>
    </div>
    <?php
}

/* ---------- Public responder application ---------- */

add_action('admin_post_sa_rs_join', 'sa_rs_handle_join');
add_action('admin_post_nopriv_sa_rs_join', 'sa_rs_handle_join');
function sa_rs_handle_join(): void
{
    if (!wp_verify_nonce((string) ($_POST['_wpnonce'] ?? ''), 'sa_rs_join') || !empty($_POST['sa_hp'])) {
        wp_die('Expired form, please go back and try again.', 403);
    }
    $g = static fn(string $k): string => sanitize_text_field(wp_unslash((string) ($_POST[$k] ?? '')));
    $email = sanitize_email($g('email'));
    if ($g('name') === '' || strlen(preg_replace('/\D/', '', $g('phone'))) < 9 || !is_email($email)) {
        wp_safe_redirect(add_query_arg('err', 1, sa_rs_url('join')));
        exit;
    }
    $sv = array_values(array_intersect(array_map('sanitize_key', (array) ($_POST['services'] ?? [])), array_keys(sa_rs_catalog())));
    $pid = sa_rs_create_provider(['name' => $g('name'), 'phone' => $g('phone'), 'email' => $email, 'services' => $sv ?: array_keys(sa_rs_catalog()), 'vehicle' => $g('vehicle'), 'area' => $g('area'), 'approved' => '0', 'radius' => 30]);
    if ($pid) {
        update_post_meta($pid, '_rsp_application', ['t' => time(), 'experience' => sanitize_textarea_field(wp_unslash((string) ($_POST['experience'] ?? '')))]);
        $admin = (string) get_option('woocommerce_email_from_address', get_option('admin_email'));
        wp_mail($admin, '[Roadside] New responder application — ' . $g('name'), '<p>' . esc_html($g('name') . ' · ' . $g('phone') . ' · ' . $email . ' · ' . $g('area')) . '</p><p><a href="' . esc_url(admin_url('admin.php?page=sa-super-roadside&tab=providers')) . '">Review in Super Admin</a></p>', ['Content-Type: text/html; charset=UTF-8']);
    }
    wp_safe_redirect(sa_rs_url('thanks'));
    exit;
}

function sa_rs_render_join(): void
{
    echo '<div class="sa-container sa-rs-narrow"><h1>Become a roadside responder</h1><p class="sa-svc-lead">Tow operators and mobile mechanics in Nairobi: get jobs near you, update customers from your phone and get rated for good work. We verify every responder before they can take jobs.</p>';
    if (isset($_GET['err'])) {
        echo '<div class="sa-svc-alert sa-svc-alert--err">Please fill in your name, a valid phone and email.</div>';
    }
    echo '<form class="sa-rs-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="sa_rs_join">' . wp_nonce_field('sa_rs_join', '_wpnonce', true, false)
        . '<input type="text" name="sa_hp" class="sa-hp" tabindex="-1" autocomplete="off" aria-hidden="true">'
        . '<label>Name or business name <input name="name" required></label><div class="sa-svc-row"><label>Phone <input name="phone" type="tel" required></label><label>Email <input name="email" type="email" required></label></div>'
        . '<div class="sa-svc-row"><label>Base area <input name="area" placeholder="e.g. Embakasi"></label><label>Vehicle / equipment <input name="vehicle" placeholder="e.g. Flatbed, booster pack"></label></div><fieldset><legend>Services you offer</legend>';
    foreach (sa_rs_catalog() as $s => $c) {
        echo '<label class="sa-rs-inline"><input type="checkbox" name="services[]" value="' . esc_attr($s) . '"> ' . esc_html($c['name']) . '</label>';
    }
    echo '</fieldset><label>Experience <textarea name="experience" rows="3"></textarea></label><button class="sa-svc-btn sa-svc-btn--primary">Apply</button></form></div>';
}
