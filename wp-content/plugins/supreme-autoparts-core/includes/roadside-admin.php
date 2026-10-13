<?php
/**
 * Super Admin → Roadside: dashboard stats, live map, list with filters/search/date range,
 * request detail (assign with nearest-provider suggestions, status updates that email the
 * customer, quote, Pesapal payment link, internal notes, chat, history), providers
 * (add/approve/deactivate/send dashboard link), ratings moderation + appeals, reports
 * (between dates, per provider, by problem type), CSV export, settings and FAQ editor.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}


function sa_rs_admin_url(array $q = []): string
{
    return add_query_arg($q, admin_url('admin.php?page=sa-super-roadside'));
}

add_action('admin_enqueue_scripts', static function (string $hook): void {
    if (strpos($hook, 'sa-super-roadside') === false) {
        return;
    }
    wp_enqueue_style('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4');
    wp_enqueue_style('sa-roadside', SA_CORE_URL . 'assets/roadside.css', [], SA_CORE_VERSION);
    wp_enqueue_script('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', true);
    wp_enqueue_script('sa-roadside', SA_CORE_URL . 'assets/roadside.js', ['leaflet'], SA_CORE_VERSION, true);
    wp_localize_script('sa-roadside', 'saRs', ['ajax' => admin_url('admin-ajax.php')]);
});

/* ---------- Admin actions ---------- */

add_action('admin_post_sa_rs_admin', static function (): void {
    if (!current_user_can('manage_woocommerce') || !wp_verify_nonce((string) ($_POST['_wpnonce'] ?? ''), 'sa_rs_admin')) {
        wp_die('Not allowed', 403);
    }
    $do = sanitize_key((string) ($_POST['do'] ?? ''));
    $id = (int) ($_POST['r'] ?? 0);
    $g = static fn(string $k): string => sanitize_text_field(wp_unslash((string) ($_POST[$k] ?? '')));
    $txt = sanitize_textarea_field(wp_unslash((string) ($_POST['text'] ?? '')));
    $me = wp_get_current_user()->display_name ?: 'Dispatch';
    $back = wp_get_referer() ?: sa_rs_admin_url();
    $isreq = get_post_type($id) === 'sa_roadside';
    if ($isreq && $do === 'assign') {
        sa_rs_assign($id, (int) ($_POST['provider'] ?? 0), (int) $g('eta'), $txt);
    } elseif ($isreq && $do === 'status') {
        $st = sanitize_key($g('status'));
        if ($st === 'en_route' && (int) $g('eta') > 0) {
            sa_rs_set($id, 'eta', (int) $g('eta'));
            sa_rs_set($id, 'eta_at', time() + (int) $g('eta') * 60);
        }
        sa_rs_set_status($id, $st, $txt, $me);
    } elseif ($isreq && $do === 'note' && $txt !== '') {
        $n = sa_rs_meta_list($id, '_rs_notes');
        $n[] = ['t' => time(), 'w' => $me, 'x' => $txt];
        update_post_meta($id, '_rs_notes', $n);
    } elseif ($isreq && $do === 'msg' && $txt !== '') {
        sa_rs_add_message($id, 'team', 'Supreme Autoparts', $txt);
        sa_rs_email($id, 'message', $txt);
    } elseif ($isreq && $do === 'quote') {
        sa_rs_set($id, 'quote', $g('quote'));
        sa_rs_log($id, (string) get_post_meta($id, '_rs_status', true), 'Price agreed: ' . $g('quote'), $me);
    } elseif ($isreq && $do === 'paylink') {
        $amt = round((float) str_replace([',', ' '], '', $g('amount')), 2);
        $cur = $g('currency') === 'USD' ? 'USD' : 'KES';
        $r = sa_rs_data($id);
        if ($amt > 0 && class_exists('Whop_Open_Pay')) {
            $email = is_email($r['email']) ? $r['email'] : 'roadside+' . sanitize_key($r['ref']) . '@supremeautoparts.co.ke';
            $o = Whop_Open_Pay::create_pending_order($amt, $email, 'Roadside ' . $r['ref'], ['_sa_rs_for' => $id], $cur);
            if ($o) {
                $o->set_billing_phone($r['phone']);
                $parts = preg_split('/\s+/', trim($r['name']), 2);
                $o->set_billing_first_name($parts[0] ?? '');
                $o->set_billing_last_name($parts[1] ?? '');
                foreach ($o->get_fees() as $fee) {
                    $fee->set_name('Roadside ' . (sa_rs_catalog()[$r['type']]['name'] ?? '') . ' — ' . $r['ref']);
                    $fee->save();
                }
                $o->add_order_note('Roadside payment link for ' . $r['ref'] . ' created by ' . $me . '.');
                $o->save();
                sa_rs_set($id, 'pay_order', $o->get_id());
                sa_rs_set($id, 'pay_amount', $cur . ' ' . number_format($amt, $cur === 'KES' ? 0 : 2));
                sa_rs_log($id, $r['status'], 'Pesapal payment link sent: ' . $cur . ' ' . number_format($amt, 2) . ' (order #' . $o->get_id() . ')', $me);
                sa_rs_email($id, 'pay');
            }
        }
    } elseif ($isreq && $do === 'delete') {
        wp_delete_post($id, true);
        $back = sa_rs_admin_url();
    } elseif ($isreq && $do === 'review') {
        $act = $g('act');
        if ($act === 'hide') {
            sa_rs_set($id, 'review_hidden', 1);
        } elseif ($act === 'show') {
            delete_post_meta($id, '_rs_review_hidden');
        }
        $ap = get_post_meta($id, '_rs_review_appeal', true);
        if (is_array($ap) && in_array($act, ['hide', 'keep'], true)) {
            $ap['state'] = $act === 'hide' ? 'upheld (rating hidden)' : 'declined (rating kept)';
            sa_rs_set($id, 'review_appeal', $ap);
        }
    } elseif ($do === 'provider') {
        $pid = (int) ($_POST['p'] ?? 0);
        $data = ['name' => $g('name'), 'phone' => $g('phone'), 'email' => sanitize_email($g('email')), 'vehicle' => $g('vehicle'), 'area' => $g('area'), 'radius' => max(5, (int) $g('radius') ?: 30),
            'services' => array_values(array_intersect(array_map('sanitize_key', (array) ($_POST['services'] ?? [])), array_keys(sa_rs_catalog()))) ?: array_keys(sa_rs_catalog())];
        if ($pid && get_post_type($pid) === 'sa_rs_provider') {
            if ($data['name'] !== '') wp_update_post(['ID' => $pid, 'post_title' => $data['name']]);
            foreach (['phone', 'email', 'vehicle', 'area', 'radius', 'services'] as $k) update_post_meta($pid, '_rsp_' . $k, $data[$k]);
        } elseif ($data['name'] !== '') {
            $pid = sa_rs_create_provider($data);
        }
        $login = sanitize_email($g('wp_user'));
        if ($pid && $login !== '' && ($u = get_user_by('email', $login))) {
            update_post_meta($pid, '_rsp_user', $u->ID);
            if (!user_can($u, 'manage_woocommerce')) {
                $u->add_role('sa_rs_provider');
            }
        }
    } elseif ($do === 'provider_state') {
        $pid = (int) ($_POST['p'] ?? 0);
        $act = $g('act');
        if (get_post_type($pid) === 'sa_rs_provider') {
            if ($act === 'approve') update_post_meta($pid, '_rsp_approved', '1');
            if ($act === 'deactivate') update_post_meta($pid, '_rsp_active', '0');
            if ($act === 'activate') update_post_meta($pid, '_rsp_active', '1');
            if ($act === 'reset') { update_post_meta($pid, '_rsp_token', ''); sa_rs_provider_link($pid); }
            if ($act === 'send' || $act === 'approve') {
                $p = sa_rs_provider_data($pid);
                if (is_email($p['email'])) {
                    wp_mail($p['email'], 'Your Supreme Autoparts responder dashboard', '<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto"><p>Hi ' . esc_html($p['name']) . ',</p><p>Here is your private link to the roadside responder dashboard. Keep it to yourself: anyone with this link can act as you.</p><p><a href="' . esc_url(sa_rs_provider_link($pid)) . '" style="display:inline-block;background:#3665f3;color:#fff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:10px">Open my dashboard</a></p></div>', ['Content-Type: text/html; charset=UTF-8']);
                }
            }
        }
    } elseif ($do === 'settings') {
        update_option('sa_rs_settings', ['customer_choice' => empty($_POST['customer_choice']) ? '0' : '1', 'notify_providers' => empty($_POST['notify_providers']) ? '0' : '1', 'radius' => (string) max(5, (int) $g('radius'))]);
        $faqs = [];
        foreach (preg_split('/\n\s*\n/', str_replace("\r", '', (string) wp_unslash($_POST['faqs'] ?? ''))) as $blk) {
            $l = array_values(array_filter(array_map('trim', explode("\n", $blk))));
            if (count($l) >= 2) {
                $faqs[] = [sanitize_text_field($l[0]), sanitize_textarea_field(implode(' ', array_slice($l, 1)))];
            }
        }
        update_option('sa_rs_faqs', $faqs ?: sa_rs_faq_default());
    }
    if (function_exists('sa_core_audit_log')) {
        sa_core_audit_log('roadside_admin_' . $do, ['request' => $id]);
    }
    wp_safe_redirect($back);
    exit;
});

/* ---------- CSV export ---------- */

add_action('admin_post_sa_rs_export', static function (): void {
    if (!current_user_can('manage_woocommerce') || !wp_verify_nonce((string) ($_GET['_wpnonce'] ?? ''), 'sa_rs_export')) {
        wp_die('Not allowed', 403);
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="roadside-requests-' . wp_date('Ymd') . '.csv"');
    $f = fopen('php://output', 'w');
    fputcsv($f, ['Ref', 'Created (EAT)', 'Type', 'Status', 'Name', 'Phone', 'Email', 'Address', 'Landmark', 'Lat', 'Lng', 'Vehicle', 'Provider', 'Price', 'Paid', 'Rating', 'Feedback']);
    foreach (sa_rs_admin_query([]) as $id) {
        $r = sa_rs_data((int) $id);
        fputcsv($f, [$r['ref'], wp_date('Y-m-d H:i', $r['created']), $r['type'], $r['status'], $r['name'], $r['phone'], $r['email'], $r['address'], $r['landmark'], $r['lat'], $r['lng'],
            trim($r['year'] . ' ' . $r['make'] . ' ' . $r['model'] . ' ' . $r['reg']), $r['provider'] ? get_the_title($r['provider']) : '', $r['quote'] ?: $r['pay_amount'], $r['paid'] ? 'yes' : '', $r['rating'], $r['feedback']]);
    }
    exit;
});

function sa_rs_admin_query(array $f): array
{
    $args = ['post_type' => 'sa_roadside', 'numberposts' => 500, 'fields' => 'ids', 'meta_query' => []];
    if (!empty($f['st']) && isset(sa_rs_statuses()[$f['st']])) $args['meta_query'][] = ['key' => '_rs_status', 'value' => $f['st']];
    if (!empty($f['type'])) $args['meta_query'][] = ['key' => '_rs_type', 'value' => $f['type']];
    if (!empty($f['prov'])) $args['meta_query'][] = ['key' => '_rs_provider', 'value' => (string) (int) $f['prov']];
    if (!empty($f['q'])) {
        $q = $f['q'];
        $args['meta_query'][] = ['relation' => 'OR', ['key' => '_rs_ref', 'value' => $q, 'compare' => 'LIKE'], ['key' => '_rs_phone', 'value' => preg_replace('/\D/', '', $q) ?: $q, 'compare' => 'LIKE'], ['key' => '_rs_name', 'value' => $q, 'compare' => 'LIKE'], ['key' => '_rs_reg', 'value' => $q, 'compare' => 'LIKE']];
    }
    $dq = [];
    if (!empty($f['from'])) $dq['after'] = $f['from'] . ' 00:00:00';
    if (!empty($f['to'])) $dq['before'] = $f['to'] . ' 23:59:59';
    if ($dq) $args['date_query'] = [array_merge($dq, ['inclusive' => true])];
    return get_posts($args);
}

/* ---------- Render ---------- */

function sa_rs_admin_render(): void
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $tab = sanitize_key((string) ($_GET['tab'] ?? 'requests'));
    $rid = (int) ($_GET['r'] ?? 0);
    $tabs = ['requests' => 'Requests', 'providers' => 'Providers', 'reviews' => 'Ratings', 'reports' => 'Reports', 'settings' => 'Settings & FAQ'];
    echo '<div class="wrap sa-ultra sa-rs-admin"><h1>Roadside Rescue</h1><p class="sa-ultra__sub">Requests from <a href="' . esc_url(sa_rs_url()) . '" target="_blank">/roadside/</a>. Every status update emails the customer. Responder dashboard: <a href="' . esc_url(sa_rs_url('provider')) . '" target="_blank">/roadside/provider/</a>.</p><nav class="nav-tab-wrapper">';
    foreach ($tabs as $k => $l) {
        echo '<a class="nav-tab' . ($tab === $k && !$rid ? ' nav-tab-active' : '') . '" href="' . esc_url(sa_rs_admin_url(['tab' => $k])) . '">' . esc_html($l) . '</a>';
    }
    echo '</nav>';
    if ($rid && get_post_type($rid) === 'sa_roadside') {
        sa_rs_admin_detail($rid);
    } elseif ($tab === 'providers') {
        sa_rs_admin_providers();
    } elseif ($tab === 'reviews') {
        sa_rs_admin_reviews();
    } elseif ($tab === 'reports') {
        sa_rs_admin_reports();
    } elseif ($tab === 'settings') {
        sa_rs_admin_settings();
    } else {
        sa_rs_admin_list();
    }
    echo '</div>';
}

function sa_rs_admin_form(string $do, int $id, string $inner, string $btn, string $cls = 'button button-primary'): string
{
    return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="sa-rs-aform"><input type="hidden" name="action" value="sa_rs_admin"><input type="hidden" name="do" value="' . esc_attr($do) . '"><input type="hidden" name="r" value="' . $id . '">'
        . wp_nonce_field('sa_rs_admin', '_wpnonce', true, false) . $inner . ' <button class="' . esc_attr($cls) . '">' . esc_html($btn) . '</button></form>';
}

function sa_rs_admin_list(): void
{
    $f = ['st' => sanitize_key((string) ($_GET['st'] ?? '')), 'type' => sanitize_key((string) ($_GET['type'] ?? '')), 'q' => sanitize_text_field(wp_unslash((string) ($_GET['q'] ?? ''))),
        'from' => sanitize_text_field((string) ($_GET['from'] ?? '')), 'to' => sanitize_text_field((string) ($_GET['to'] ?? '')), 'prov' => (int) ($_GET['prov'] ?? 0)];
    $ids = sa_rs_admin_query($f);
    $all = sa_rs_admin_query([]);
    $count = array_fill_keys(array_keys(sa_rs_statuses()), 0);
    $pins = [];
    foreach ($all as $i) {
        $r = sa_rs_data((int) $i);
        $count[$r['status']]++;
        if ($r['lat'] !== '' && !in_array($r['status'], ['completed', 'cancelled'], true)) {
            $pins[] = ['lat' => (float) $r['lat'], 'lng' => (float) $r['lng'], 'label' => $r['ref'] . ' · ' . (sa_rs_catalog()[$r['type']]['name'] ?? '') . ' · ' . sa_rs_statuses()[$r['status']], 'url' => sa_rs_admin_url(['r' => $i])];
        }
    }
    foreach (get_posts(['post_type' => 'sa_rs_provider', 'numberposts' => 100, 'fields' => 'ids']) as $pid) {
        $p = sa_rs_provider_data((int) $pid);
        if ($p['lat'] !== '' && $p['lts'] > time() - 2 * HOUR_IN_SECONDS) {
            $pins[] = ['lat' => (float) $p['lat'], 'lng' => (float) $p['lng'], 'label' => 'Responder: ' . $p['name'], 'prov' => 1];
        }
    }
    $active = $count['received'] + $count['dispatched'] + $count['en_route'] + $count['arrived'];
    $sum = sa_rs_rating_summary();
    echo '<div class="sa-rs-kpis"><div><strong>' . (int) $count['received'] . '</strong><span>Waiting for a responder</span></div><div><strong>' . (int) $active . '</strong><span>Active</span></div><div><strong>' . (int) $count['completed'] . '</strong><span>Completed</span></div><div><strong>' . ($sum['count'] ? esc_html(number_format($sum['avg'], 1)) . '★' : '—') . '</strong><span>' . (int) $sum['count'] . ' ratings</span></div></div>';
    echo '<h2>Live map</h2><div class="sa-rs-map sa-rs-map--admin" data-rs-map data-mode="admin" data-pins="' . esc_attr(wp_json_encode($pins)) . '"></div>';
    echo '<form method="get" class="sa-rs-filter"><input type="hidden" name="page" value="sa-super-roadside"><select name="st"><option value="">All statuses</option>';
    foreach (sa_rs_statuses() as $k => $l) echo '<option value="' . esc_attr($k) . '"' . selected($f['st'], $k, false) . '>' . esc_html($l . ' (' . $count[$k] . ')') . '</option>';
    echo '</select><select name="type"><option value="">All types</option>';
    foreach (sa_rs_catalog() as $k => $c) echo '<option value="' . esc_attr($k) . '"' . selected($f['type'], $k, false) . '>' . esc_html($c['name']) . '</option>';
    echo '</select><input type="date" name="from" value="' . esc_attr($f['from']) . '"><input type="date" name="to" value="' . esc_attr($f['to']) . '"><input type="search" name="q" value="' . esc_attr($f['q']) . '" placeholder="Ref, phone, name or plate"><button class="button">Filter</button> <a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=sa_rs_export'), 'sa_rs_export')) . '">Export CSV</a></form>';
    echo '<table class="widefat striped sa-rs-atable"><thead><tr><th>Request</th><th>Type</th><th>Customer</th><th>Location</th><th>Status</th><th>Responder</th><th>Submitted</th></tr></thead><tbody>';
    if (!$ids) echo '<tr><td colspan="7">No requests.</td></tr>';
    foreach ($ids as $i) {
        $r = sa_rs_data((int) $i);
        echo '<tr><td><a href="' . esc_url(sa_rs_admin_url(['r' => $i])) . '"><strong>' . esc_html($r['ref']) . '</strong></a></td><td>' . esc_html(sa_rs_catalog()[$r['type']]['name'] ?? $r['type']) . '</td><td>' . esc_html($r['name']) . '<br><a href="tel:' . esc_attr($r['phone']) . '">' . esc_html($r['phone']) . '</a></td><td>' . esc_html(wp_html_excerpt(trim($r['address'] . ' ' . $r['landmark']), 60, '…') ?: ($r['lat'] ? 'Pin' : '—')) . '</td><td><span class="sa-rs-badge sa-rs-badge--' . esc_attr($r['status']) . '">' . esc_html(sa_rs_statuses()[$r['status']]) . '</span></td><td>' . esc_html($r['provider'] ? get_the_title($r['provider']) : '—') . '</td><td>' . esc_html(wp_date('j M, g:i A', $r['created'])) . '</td></tr>';
    }
    echo '</tbody></table>';
}

function sa_rs_admin_detail(int $id): void
{
    $r = sa_rs_data($id);
    $svc = sa_rs_catalog()[$r['type']]['name'] ?? $r['type'];
    echo '<p><a href="' . esc_url(sa_rs_admin_url()) . '">← All requests</a></p><h2>' . esc_html($r['ref'] . ' · ' . $svc) . ' <span class="sa-rs-badge sa-rs-badge--' . esc_attr($r['status']) . '">' . esc_html(sa_rs_statuses()[$r['status']]) . '</span></h2>';
    echo '<div class="sa-rs-adetail"><div>';
    echo '<table class="widefat"><tbody>';
    $rows = ['Customer' => $r['name'], 'Phone' => '<a href="tel:' . esc_attr($r['phone']) . '">' . esc_html($r['phone']) . '</a> · <a target="_blank" href="https://wa.me/' . esc_attr(ltrim(preg_replace('/^0/', '254', (string) $r['whatsapp']), '+')) . '">WhatsApp</a>', 'Email' => esc_html($r['email'] ?: '—'),
        'Location' => esc_html(trim($r['address'] . ($r['landmark'] ? ' · near ' . $r['landmark'] : '')) ?: '—') . ($r['lat'] ? ' · <a target="_blank" href="https://www.google.com/maps?q=' . rawurlencode($r['lat'] . ',' . $r['lng']) . '">Map</a>' : ''),
        'Tow to' => esc_html($r['dest'] ?: '—'), 'Vehicle' => esc_html(trim($r['year'] . ' ' . $r['make'] . ' ' . $r['model'] . ' ' . $r['reg']) ?: '—'), 'When' => esc_html($r['when'] === 'now' ? 'ASAP' : (string) $r['when']),
        'Note' => esc_html($r['note'] ?: '—'), 'Photo' => $r['photo'] ? '<a target="_blank" href="' . esc_url((string) wp_get_attachment_url((int) $r['photo'])) . '"><img src="' . esc_url((string) wp_get_attachment_image_url((int) $r['photo'], 'thumbnail')) . '" alt="" style="max-width:120px;border-radius:8px"></a>' : '—',
        'Price' => esc_html($r['quote'] ?: 'Not agreed yet'), 'Payment' => $r['pay_order'] ? esc_html($r['pay_amount']) . ' · <a href="' . esc_url(admin_url('post.php?post=' . (int) $r['pay_order'] . '&action=edit')) . '">order #' . (int) $r['pay_order'] . '</a> · ' . ($r['paid'] ? '<strong>Paid</strong>' : 'Unpaid') : '—',
        'Country (geo)' => esc_html($r['country'] ?: '—'), 'Customer status link' => '<a target="_blank" href="' . esc_url(sa_rs_status_url($id)) . '">Open</a>', 'Submitted' => esc_html(wp_date('j M Y, g:i A', $r['created'])) . ' EAT'];
    foreach ($rows as $k => $v) echo '<tr><th>' . esc_html($k) . '</th><td>' . $v . '</td></tr>';
    echo '</tbody></table>';
    if ($r['lat'] !== '') {
        $pins = [['lat' => (float) $r['lat'], 'lng' => (float) $r['lng'], 'label' => 'Customer']];
        if ($r['plat'] !== '') $pins[] = ['lat' => (float) $r['plat'], 'lng' => (float) $r['plng'], 'label' => 'Responder', 'prov' => 1];
        echo '<div class="sa-rs-map sa-rs-map--admin" data-rs-map data-mode="admin" data-pins="' . esc_attr(wp_json_encode($pins)) . '"></div>';
    }
    echo '</div><div>';
    // Assignment with nearest suggestions.
    $cands = [];
    foreach (get_posts(['post_type' => 'sa_rs_provider', 'numberposts' => 100, 'fields' => 'ids']) as $pid) {
        $p = sa_rs_provider_data((int) $pid);
        if (!$p['active'] || !$p['approved']) continue;
        $km = ($p['lat'] !== '' && $r['lat'] !== '') ? sa_rs_km((float) $p['lat'], (float) $p['lng'], (float) $r['lat'], (float) $r['lng']) : null;
        $cands[] = ['p' => $p, 'km' => $km, 'fit' => in_array($r['type'], $p['services'], true)];
    }
    usort($cands, static fn($a, $b) => [!$a['fit'], $a['km'] ?? 9999] <=> [!$b['fit'], $b['km'] ?? 9999]);
    $opt = '';
    foreach ($cands as $c) {
        $opt .= '<option value="' . (int) $c['p']['id'] . '"' . selected($r['provider'], $c['p']['id'], false) . '>' . esc_html($c['p']['name'] . ($c['km'] !== null ? ' · ' . number_format($c['km'], 1) . ' km' : '') . ($c['p']['available'] ? '' : ' · offline') . ($c['fit'] ? '' : ' · does not list this service') . ($c['p']['avg'] ? ' · ' . number_format($c['p']['avg'], 1) . '★' : '')) . '</option>';
    }
    echo '<h3>Assign responder</h3>' . sa_rs_admin_form('assign', $id, '<select name="provider">' . $opt . '</select> <input type="number" name="eta" placeholder="ETA min" style="width:90px"><br><input name="text" placeholder="Note to customer (optional)" style="width:100%">', $r['provider'] ? 'Reassign' : 'Assign & email customer');
    $so = '';
    foreach (sa_rs_statuses() as $k => $l) $so .= '<option value="' . esc_attr($k) . '"' . selected($r['status'], $k, false) . '>' . esc_html($l) . '</option>';
    echo '<h3>Update status</h3>' . sa_rs_admin_form('status', $id, '<select name="status">' . $so . '</select> <input type="number" name="eta" placeholder="ETA min" style="width:90px"><br><input name="text" placeholder="Message for the customer (emailed)" style="width:100%">', 'Update & email');
    echo '<h3>Agreed price</h3>' . sa_rs_admin_form('quote', $id, '<input name="quote" value="' . esc_attr((string) $r['quote']) . '" placeholder="e.g. KES 6,000 tow 0–10 km">', 'Save', 'button');
    echo '<h3>Pesapal payment link (optional)</h3>' . sa_rs_admin_form('paylink', $id, '<input name="amount" placeholder="Amount" inputmode="decimal" style="width:120px"> <select name="currency"><option>KES</option><option>USD</option></select>', 'Create & email link', 'button');
    echo '<h3>Message customer</h3>' . sa_rs_admin_form('msg', $id, '<textarea name="text" rows="2" style="width:100%"></textarea>', 'Send', 'button');
    echo '<div class="sa-rs-msgs">';
    foreach ($r['messages'] as $m) echo '<div class="sa-rs-msg sa-rs-msg--' . esc_attr($m['f']) . '"><small>' . esc_html($m['w'] . ' · ' . wp_date('j M g:i A', (int) $m['t'])) . '</small><p>' . esc_html($m['x']) . '</p></div>';
    echo '</div><h3>Internal notes</h3>' . sa_rs_admin_form('note', $id, '<textarea name="text" rows="2" style="width:100%" placeholder="Only staff see this"></textarea>', 'Add note', 'button') . '<ul>';
    foreach (array_reverse($r['notes']) as $n) echo '<li><small>' . esc_html(wp_date('j M g:i A', (int) $n['t']) . ' · ' . $n['w']) . '</small><br>' . esc_html($n['x']) . '</li>';
    echo '</ul><h3>History</h3><ul class="sa-rs-ahist">';
    foreach (array_reverse($r['history']) as $h) echo '<li><time>' . esc_html(wp_date('j M g:i A', (int) $h['t'])) . '</time> <strong>' . esc_html(sa_rs_statuses()[$h['s']] ?? $h['s']) . '</strong> ' . esc_html((string) $h['n']) . ' <em>' . esc_html((string) $h['by']) . '</em></li>';
    echo '</ul>';
    if ($r['rated']) echo '<h3>Rating</h3><p>' . str_repeat('★', (int) $r['rating']) . ' ' . esc_html((string) $r['feedback']) . '</p>';
    echo '<hr>' . sa_rs_admin_form('delete', $id, '', 'Delete request permanently', 'button-link-delete');
    echo '</div></div>';
}

function sa_rs_admin_providers(): void
{
    $ids = get_posts(['post_type' => 'sa_rs_provider', 'numberposts' => 200, 'fields' => 'ids']);
    $svcs = static function (array $sel): string {
        $o = '';
        foreach (sa_rs_catalog() as $s => $c) $o .= '<label style="margin-right:8px"><input type="checkbox" name="services[]" value="' . esc_attr($s) . '"' . checked(in_array($s, $sel, true), true, false) . '> ' . esc_html($c['name']) . '</label>';
        return $o;
    };
    $stateform = static fn(int $pid, string $act, string $label) => '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline"><input type="hidden" name="action" value="sa_rs_admin"><input type="hidden" name="do" value="provider_state"><input type="hidden" name="p" value="' . $pid . '"><input type="hidden" name="act" value="' . esc_attr($act) . '">' . wp_nonce_field('sa_rs_admin', '_wpnonce', true, false) . '<button class="button button-small">' . esc_html($label) . '</button></form> ';
    echo '<table class="widefat striped"><thead><tr><th>Responder</th><th>Contact</th><th>Services</th><th>State</th><th>Last location</th><th>Rating</th><th>Actions</th></tr></thead><tbody>';
    foreach ($ids as $pid) {
        $p = sa_rs_provider_data((int) $pid);
        $state = !$p['approved'] ? 'Application — not approved' : (!$p['active'] ? 'Deactivated' : ($p['available'] ? 'Available' : 'Offline'));
        echo '<tr><td><strong>' . esc_html($p['name']) . '</strong><br><small>' . esc_html($p['area'] . ' ' . $p['vehicle']) . '</small></td><td>' . esc_html($p['phone']) . '<br>' . esc_html($p['email']) . '</td><td>' . esc_html(implode(', ', array_map(static fn($s) => sa_rs_catalog()[$s]['name'] ?? $s, $p['services']))) . '</td><td>' . esc_html($state) . '</td><td>' . ($p['lts'] ? esc_html(human_time_diff($p['lts']) . ' ago') : '—') . '</td><td>' . ($p['count'] ? esc_html(number_format($p['avg'], 1)) . '★ (' . (int) $p['count'] . ')' : '—') . '</td><td>'
            . (!$p['approved'] ? $stateform((int) $pid, 'approve', 'Approve & send link') : '') . ($p['active'] ? $stateform((int) $pid, 'deactivate', 'Deactivate') : $stateform((int) $pid, 'activate', 'Activate'))
            . $stateform((int) $pid, 'send', 'Email dashboard link') . $stateform((int) $pid, 'reset', 'Reset link') . '<a class="button button-small" target="_blank" href="' . esc_url(sa_rs_provider_link((int) $pid)) . '">Open dashboard</a>'
            . '<details><summary>Edit</summary><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="sa_rs_admin"><input type="hidden" name="do" value="provider"><input type="hidden" name="p" value="' . (int) $pid . '">' . wp_nonce_field('sa_rs_admin', '_wpnonce', true, false)
            . '<input name="name" value="' . esc_attr($p['name']) . '"> <input name="phone" value="' . esc_attr($p['phone']) . '"> <input name="email" value="' . esc_attr($p['email']) . '"> <input name="area" value="' . esc_attr($p['area']) . '" placeholder="Area"> <input name="vehicle" value="' . esc_attr($p['vehicle']) . '" placeholder="Vehicle"> <input name="radius" type="number" value="' . (int) $p['radius'] . '" style="width:70px"> km<br>' . $svcs($p['services']) . '<br><input name="wp_user" placeholder="Link WP account by email (optional)"> <button class="button">Save</button></form></details></td></tr>';
        $app = get_post_meta((int) $pid, '_rsp_application', true);
        if (is_array($app) && !empty($app['experience'])) echo '<tr><td colspan="7"><small>Application: ' . esc_html($app['experience']) . '</small></td></tr>';
    }
    echo '</tbody></table><h2>Add responder</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="sa_rs_admin"><input type="hidden" name="do" value="provider">' . wp_nonce_field('sa_rs_admin', '_wpnonce', true, false)
        . '<input name="name" placeholder="Name" required> <input name="phone" placeholder="Phone"> <input name="email" placeholder="Email"> <input name="area" placeholder="Base area"> <input name="vehicle" placeholder="Vehicle"> <input name="radius" type="number" value="30" style="width:70px"> km<br>' . $svcs(array_keys(sa_rs_catalog())) . '<br><input name="wp_user" placeholder="Link WP account by email (optional)"> <button class="button button-primary">Add responder</button></form>';
}

function sa_rs_admin_reviews(): void
{
    $ids = get_posts(['post_type' => 'sa_roadside', 'numberposts' => 200, 'fields' => 'ids', 'meta_key' => '_rs_rated', 'orderby' => 'meta_value_num', 'order' => 'DESC']);
    echo '<p>Real ratings from customers after completed jobs. Ratings cannot be edited; abusive ones can be hidden. Appeals come from responders.</p><table class="widefat striped"><thead><tr><th>Request</th><th>Rating</th><th>Feedback</th><th>Responder</th><th>Appeal</th><th>Visibility</th></tr></thead><tbody>';
    if (!$ids) echo '<tr><td colspan="6">No ratings yet.</td></tr>';
    foreach ($ids as $i) {
        $r = sa_rs_data((int) $i);
        $ap = is_array($r['review_appeal']) ? $r['review_appeal'] : null;
        $btn = static fn(string $act, string $l) => sa_rs_admin_form('review', (int) $i, '<input type="hidden" name="act" value="' . $act . '">', $l, 'button button-small');
        echo '<tr><td><a href="' . esc_url(sa_rs_admin_url(['r' => $i])) . '">' . esc_html($r['ref']) . '</a></td><td>' . str_repeat('★', (int) $r['rating']) . '</td><td>' . esc_html((string) $r['feedback']) . '</td><td>' . esc_html($r['provider'] ? get_the_title($r['provider']) : '—') . '</td><td>' . ($ap ? esc_html($ap['x'] . ' — ' . $ap['state']) . ($ap['state'] === 'open' ? $btn('keep', 'Decline appeal') : '') : '—') . '</td><td>' . ($r['review_hidden'] ? 'Hidden ' . $btn('show', 'Show') : 'Visible ' . $btn('hide', 'Hide')) . '</td></tr>';
    }
    echo '</tbody></table>';
}

function sa_rs_admin_reports(): void
{
    $from = sanitize_text_field((string) ($_GET['from'] ?? wp_date('Y-m-01')));
    $to = sanitize_text_field((string) ($_GET['to'] ?? wp_date('Y-m-d')));
    $ids = sa_rs_admin_query(['from' => $from, 'to' => $to]);
    $byType = $byProv = $bySt = [];
    $resp = [];
    foreach ($ids as $i) {
        $r = sa_rs_data((int) $i);
        $byType[$r['type']] = ($byType[$r['type']] ?? 0) + 1;
        $bySt[$r['status']] = ($bySt[$r['status']] ?? 0) + 1;
        if ($r['provider']) {
            $k = get_the_title($r['provider']);
            $byProv[$k]['jobs'] = ($byProv[$k]['jobs'] ?? 0) + 1;
            $byProv[$k]['done'] = ($byProv[$k]['done'] ?? 0) + ($r['status'] === 'completed' ? 1 : 0);
            if ($r['rated']) { $byProv[$k]['stars'][] = (int) $r['rating']; }
        }
        $t0 = $r['created'];
        foreach ($r['history'] as $h) {
            if ($h['s'] === 'arrived') { $resp[] = ((int) $h['t'] - $t0) / 60; break; }
        }
    }
    $bar = static function (array $d, callable $lab): string {
        $max = max($d ?: [1]);
        $o = '<div class="sa-rs-bars">';
        foreach ($d as $k => $v) $o .= '<div><span>' . esc_html($lab($k)) . '</span><i style="width:' . (int) round(100 * $v / $max) . '%"></i><b>' . (int) $v . '</b></div>';
        return $o . '</div>';
    };
    echo '<form method="get" class="sa-rs-filter"><input type="hidden" name="page" value="sa-super-roadside"><input type="hidden" name="tab" value="reports">From <input type="date" name="from" value="' . esc_attr($from) . '"> to <input type="date" name="to" value="' . esc_attr($to) . '"> <button class="button">Run report</button></form>';
    echo '<div class="sa-rs-kpis"><div><strong>' . count($ids) . '</strong><span>Requests</span></div><div><strong>' . (int) ($bySt['completed'] ?? 0) . '</strong><span>Completed</span></div><div><strong>' . (int) ($bySt['cancelled'] ?? 0) . '</strong><span>Cancelled</span></div><div><strong>' . ($resp ? (int) round(array_sum($resp) / count($resp)) . ' min' : '—') . '</strong><span>Avg time to arrival</span></div></div>';
    echo '<div class="sa-rs-adetail"><div><h3>By problem type</h3>' . $bar($byType, static fn($k) => sa_rs_catalog()[$k]['name'] ?? $k) . '<h3>By status</h3>' . $bar($bySt, static fn($k) => sa_rs_statuses()[$k] ?? $k) . '</div><div><h3>By responder</h3><table class="widefat striped"><thead><tr><th>Responder</th><th>Jobs</th><th>Completed</th><th>Avg rating</th></tr></thead><tbody>';
    foreach ($byProv as $k => $v) echo '<tr><td>' . esc_html($k) . '</td><td>' . (int) $v['jobs'] . '</td><td>' . (int) $v['done'] . '</td><td>' . (!empty($v['stars']) ? esc_html(number_format(array_sum($v['stars']) / count($v['stars']), 1)) : '—') . '</td></tr>';
    echo '</tbody></table></div></div>';
}

function sa_rs_admin_settings(): void
{
    $faq = '';
    foreach (sa_rs_faqs() as $f) $faq .= $f[0] . "\n" . $f[1] . "\n\n";
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="sa_rs_admin"><input type="hidden" name="do" value="settings">' . wp_nonce_field('sa_rs_admin', '_wpnonce', true, false)
        . '<p><label><input type="checkbox" name="customer_choice" value="1"' . checked(sa_rs_setting('customer_choice'), '1', false) . '> Let customers choose between partner responders who offer to help (otherwise the first responder to accept gets the job)</label></p>'
        . '<p><label><input type="checkbox" name="notify_providers" value="1"' . checked(sa_rs_setting('notify_providers'), '1', false) . '> Email available partner responders nearby about new requests</label></p>'
        . '<p><label>Default job radius <input type="number" name="radius" value="' . esc_attr((string) sa_rs_setting('radius')) . '" style="width:80px"> km</label></p>'
        . '<h3>Roadside FAQ</h3><p>One question per block: question on the first line, answer below, blank line between blocks.</p><textarea name="faqs" rows="18" style="width:100%">' . esc_textarea(trim($faq)) . '</textarea><p><button class="button button-primary">Save settings</button></p></form>';
}
