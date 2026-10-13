<?php
/** Vehicle history (shared) + My Account → My Vehicles. */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** @return list<array{t:int,type:string,title:string,sub:string,url:string,admin_url:string}> */
function sa_g_vehicle_history(int $vid, bool $for_customer = false): array
{
    global $wpdb;
    $out = [];
    $links = $wpdb->get_results($wpdb->prepare('SELECT src_type, src_id FROM ' . sa_g_t('links') . ' WHERE vehicle_id=%d', $vid), ARRAY_A) ?: [];
    foreach ($links as $l) {
        $id = (int) $l['src_id'];
        if ($l['src_type'] === 'booking' && get_post_type($id) === 'sa_booking') {
            $b = sa_svc_booking_data($id);
            $svc = sa_svc_catalog()[$b['service']]['name'] ?? $b['service'];
            $out[] = ['t' => strtotime($b['date'] . ' ' . $b['time']) ?: (int) $b['created'], 'type' => 'Service booking', 'title' => $svc, 'sub' => $b['ref'] . ' · ' . ucfirst(str_replace('_', ' ', $b['status'])),
                'url' => add_query_arg(['b' => $id, 'k' => $b['key']], home_url('/manage-booking/')), 'admin_url' => admin_url('admin.php?page=sa-super-bookings')];
        } elseif ($l['src_type'] === 'roadside' && get_post_type($id) === 'sa_roadside') {
            $r = sa_rs_data($id);
            $out[] = ['t' => (int) $r['created'], 'type' => 'Roadside', 'title' => sa_rs_catalog()[$r['type']]['name'] ?? $r['type'], 'sub' => $r['ref'] . ' · ' . (sa_rs_statuses()[$r['status']] ?? $r['status']),
                'url' => sa_rs_status_url($id), 'admin_url' => admin_url('admin.php?page=sa-super-roadside')];
        } elseif ($l['src_type'] === 'order' && ($o = wc_get_order($id))) {
            $out[] = ['t' => $o->get_date_created() ? $o->get_date_created()->getTimestamp() : 0, 'type' => $o->get_meta('_sa_ro_id') ? 'Invoice' : 'Parts order', 'title' => 'Order #' . $o->get_order_number() . ' · ' . wp_strip_all_tags(wc_price((float) $o->get_total(), ['currency' => $o->get_currency()])),
                'sub' => wc_get_order_status_name($o->get_status()), 'url' => $o->get_customer_id() ? $o->get_view_order_url() : add_query_arg(['orderid' => $id, 'order_email' => $o->get_billing_email()], home_url('/track-order/')), 'admin_url' => $o->get_edit_order_url()];
        }
    }
    foreach ($wpdb->get_results($wpdb->prepare('SELECT * FROM ' . sa_g_t('ro') . ' WHERE vehicle_id=%d', $vid), ARRAY_A) ?: [] as $r) {
        $desc = implode(', ', array_slice(array_column(array_filter(sa_g_ro_items((int) $r['id']), static fn($i) => $i['approval'] !== 'declined'), 'description'), 0, 3));
        $out[] = ['t' => strtotime($r['created']), 'type' => 'Repair order', 'title' => $desc ?: ($r['complaint'] ?: 'Repair order'), 'sub' => $r['ref'] . ' · ' . sa_g_ro_statuses()[$r['status']] . ' · ' . sa_g_kes($r['total_kes']) . ($r['mileage_in'] ? ' · ' . number_format((int) $r['mileage_in']) . ' km' : ''),
            'url' => sa_g_estimate_url($r), 'admin_url' => admin_url('admin.php?page=sa-super-workshop&view=ro&id=' . (int) $r['id'])];
    }
    foreach (sa_g_dvis_for(['vehicle_id' => $vid]) as $d) {
        $out[] = ['t' => strtotime($d['created']), 'type' => 'Inspection', 'title' => sa_g_dvi_kinds()[$d['kind']], 'sub' => $d['ref'] . ($d['mileage'] ? ' · ' . number_format((int) $d['mileage']) . ' km' : ''), 'url' => sa_g_dvi_report_url($d), 'admin_url' => sa_g_dvi_report_url($d)];
    }
    usort($out, static fn($a, $b) => $b['t'] <=> $a['t']);
    return $out;
}

function sa_g_customer_ids_for_user(int $uid): array
{
    global $wpdb;
    $u = get_userdata($uid);
    if (!$u) {
        return [];
    }
    return array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . sa_g_t('customers') . ' WHERE user_id=%d OR email=%s', $uid, strtolower($u->user_email))) ?: []);
}

add_action('init', static function (): void {
    add_rewrite_endpoint('my-vehicles', EP_ROOT | EP_PAGES);
}, 12);
add_filter('woocommerce_get_query_vars', static function (array $v): array {
    $v['my-vehicles'] = 'my-vehicles';
    return $v;
});
add_filter('woocommerce_account_menu_items', static function (array $items): array {
    $out = [];
    foreach ($items as $k => $l) {
        if ($k === 'customer-logout' && !isset($out['my-vehicles'])) {
            $out['my-vehicles'] = 'My Vehicles';
        }
        $out[$k] = $l;
        if ($k === 'orders') {
            $out['my-vehicles'] = 'My Vehicles';
        }
    }
    if (!isset($out['my-vehicles'])) {
        $out['my-vehicles'] = 'My Vehicles';
    }
    return $out;
}, 40);

add_action('woocommerce_account_my-vehicles_endpoint', static function (): void {
    global $wpdb;
    wp_enqueue_style('sa-garage', SA_CORE_URL . 'assets/garage.css', [], SA_CORE_VERSION);
    $uid = get_current_user_id();
    $cids = sa_g_customer_ids_for_user($uid);
    // Bring vehicles saved on the profile (booking form) into the garage records.
    if (function_exists('sa_svc_vehicles') && $uid) {
        $u = wp_get_current_user();
        foreach (sa_svc_vehicles($uid) as $v) {
            if (!empty($v['make'])) {
                sa_garage_link_vehicle(['plate' => $v['reg'] ?? '', 'make' => $v['make'], 'model' => $v['model'] ?? '', 'year' => $v['year'] ?? ''], ['name' => $u->display_name, 'email' => $u->user_email, 'user_id' => $uid], 'profile', 0);
            }
        }
        $cids = sa_g_customer_ids_for_user($uid);
    }
    $veh = [];
    if ($cids) {
        $in = implode(',', array_map('intval', $cids));
        $veh = $wpdb->get_results('SELECT DISTINCT v.* FROM ' . sa_g_t('vehicles') . ' v LEFT JOIN ' . sa_g_t('links') . " l ON l.vehicle_id=v.id WHERE v.customer_id IN ($in) OR l.customer_id IN ($in) ORDER BY v.updated DESC", ARRAY_A) ?: [];
    }
    echo '<div class="sa-g-acct"><div class="sa-g-app__head"><div><h2 style="margin:0">My Vehicles</h2><p class="sa-g-muted" style="margin:4px 0 0">Your cars, their service history, inspections and invoices in one place.</p></div><a class="sa-btn" href="' . esc_url(home_url('/book-service/')) . '">Book a service</a></div>';
    if (!$veh) {
        echo '<div class="sa-g-veh__card"><p>No vehicles yet. When you book a service, request roadside help or bring your car in, it will appear here with its full history.</p></div></div>';
        return;
    }
    echo '<div class="sa-g-veh">';
    foreach ($veh as $v) {
        $hist = sa_g_vehicle_history((int) $v['id'], true);
        $rem = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . sa_g_t('reminders') . " WHERE vehicle_id=%d AND status='open' ORDER BY due_date LIMIT 1", (int) $v['id']), ARRAY_A);
        echo '<article class="sa-g-veh__card"><div class="sa-g-veh__top"><div><h3 style="margin:0 0 6px">' . esc_html(trim(($v['year'] ?: '') . ' ' . $v['make'] . ' ' . $v['model'])) . '</h3>'
            . (!str_starts_with($v['plate'], 'NOPLATE') ? '<span class="sa-g-plate">' . esc_html($v['plate']) . '</span>' : '') . '</div>'
            . '<a class="sa-btn sa-btn--outline" href="' . esc_url(add_query_arg(['make' => $v['make'], 'model' => $v['model'], 'year' => $v['year'] ?: ''], home_url('/book-service/'))) . '">Book for this car</a></div>';
        echo '<div class="sa-g-veh__stats">' . ($v['mileage'] ? '<span>Last mileage: <strong>' . esc_html(number_format((int) $v['mileage'])) . ' km</strong></span>' : '') . ($v['vin'] ? '<span>VIN: ' . esc_html($v['vin']) . '</span>' : '') . '<span>' . count($hist) . ' record(s)</span>'
            . ($rem ? '<span>Next due: <strong>' . esc_html($rem['service']) . '</strong>' . ($rem['due_date'] ? ' by ' . esc_html(mysql2date('j M Y', $rem['due_date'])) : '') . ((int) $rem['due_km'] ? ' or ' . esc_html(number_format((int) $rem['due_km'])) . ' km' : '') . '</span>' : '') . '</div>';
        if ($hist) {
            echo '<ul class="sa-g-hist">';
            foreach (array_slice($hist, 0, 20) as $h) {
                echo '<li><a href="' . esc_url($h['url']) . '"><strong>' . esc_html($h['type']) . ':</strong> ' . esc_html($h['title']) . '</a><br><small>' . esc_html(wp_date('j M Y', $h['t']) . ' · ' . $h['sub']) . '</small></li>';
            }
            echo '</ul>';
        }
        echo '</article>';
    }
    echo '</div></div>';
});
