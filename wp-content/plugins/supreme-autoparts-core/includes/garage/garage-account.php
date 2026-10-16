<?php
/** Vehicle history (shared) + My Account → My Garage (customer vehicle database). */
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

/**
 * All garage vehicles visible to a wp user (their own + any linked through bookings,
 * roadside requests or inspections), newest first.
 *
 * @return list<array{id:int,make:string,model:string,year:string,reg:string,vin:string,engine:string,color:string,mileage:int}>
 */
function sa_garage_user_vehicles(int $uid): array
{
    global $wpdb;
    $cids = sa_g_customer_ids_for_user($uid);
    if (!$cids) {
        return [];
    }
    $in = implode(',', array_map('intval', $cids));
    $rows = $wpdb->get_results('SELECT DISTINCT v.* FROM ' . sa_g_t('vehicles') . ' v LEFT JOIN ' . sa_g_t('links') . " l ON l.vehicle_id=v.id WHERE v.customer_id IN ($in) OR l.customer_id IN ($in) ORDER BY v.updated DESC", ARRAY_A) ?: [];
    $out = [];
    $seen = [];
    foreach ($rows as $v) {
        // Distinct garage rows can still describe the same car when a customer has
        // several CRM records (account + guest bookings): skip repeats so the garage
        // page, booking pickers and recommendations never list one vehicle twice.
        $reg = str_starts_with((string) $v['plate'], 'NOPLATE') ? '' : (string) $v['plate'];
        $k = strtolower(trim((string) $v['make'])) . '|' . strtolower(trim((string) $v['model'])) . '|' . trim((string) $v['year']) . '|' . strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $reg));
        if (isset($seen[$k])) {
            continue;
        }
        $seen[$k] = true;
        $out[] = [
            'id' => (int) $v['id'],
            'make' => (string) $v['make'],
            'model' => (string) $v['model'],
            'year' => $v['year'] ? (string) $v['year'] : '',
            'reg' => $reg,
            'vin' => (string) $v['vin'],
            'engine' => (string) $v['engine'],
            'color' => (string) $v['color'],
            'mileage' => (int) $v['mileage'],
        ];
    }
    return $out;
}

/** The customer's default ("active") vehicle: explicit choice, else newest. */
function sa_garage_user_default_vehicle(int $uid): ?array
{
    $veh = sa_garage_user_vehicles($uid);
    if (!$veh) {
        return null;
    }
    $def = (int) get_user_meta($uid, 'sa_garage_default_vid', true);
    foreach ($veh as $v) {
        if ($v['id'] === $def) {
            return $v;
        }
    }
    return $veh[0];
}

/** Personalisation-shape vehicle (make/model/year as slugs) for the recommendations engine. */
function sa_garage_vehicle_pz(int $uid): array
{
    $v = sa_garage_user_default_vehicle($uid);
    if (!$v || $v['make'] === '') {
        return ['mk' => '', 'mo' => '', 'y' => ''];
    }
    $mk = sanitize_title($v['make']);
    return ['mk' => $mk, 'mo' => $v['model'] !== '' ? $mk . '/' . sanitize_title($v['model']) : '', 'y' => preg_match('/^(19|20)\d{2}$/', $v['year']) ? $v['year'] : ''];
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
            $out['my-vehicles'] = 'My Garage';
        }
        $out[$k] = $l;
        if ($k === 'orders') {
            $out['my-vehicles'] = 'My Garage';
        }
    }
    if (!isset($out['my-vehicles'])) {
        $out['my-vehicles'] = 'My Garage';
    }
    return $out;
}, 40);

/* ---------- Add / edit / remove / default (My Garage CRUD) ---------- */

/** Read and sanitise a vehicle form submission. */
function sa_garage_form_vehicle(): array
{
    $p = static fn(string $k): string => sanitize_text_field(wp_unslash((string) ($_POST[$k] ?? '')));
    $year = (int) $p('year');
    return [
        'make' => $p('make'),
        'model' => $p('model'),
        'year' => ($year >= 1950 && $year <= (int) gmdate('Y') + 1) ? $year : 0,
        'plate' => sa_g_plate($p('reg')),
        'vin' => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $p('vin'))),
        'engine' => $p('engine'),
        'color' => $p('color'),
        'mileage' => max(0, (int) $p('mileage')),
    ];
}

function sa_garage_account_url(string $msg = ''): string
{
    $u = wc_get_account_endpoint_url('my-vehicles');
    return $msg !== '' ? add_query_arg('sa-g', $msg, $u) : $u;
}

/** True when the vehicle is visible to (therefore manageable by) this user. */
function sa_garage_user_owns_vehicle(int $uid, int $vid): bool
{
    foreach (sa_garage_user_vehicles($uid) as $v) {
        if ($v['id'] === $vid) {
            return true;
        }
    }
    return false;
}

add_action('admin_post_sa_garage_vehicle', static function (): void {
    $uid = get_current_user_id();
    if (!$uid || !wp_verify_nonce((string) ($_POST['_wpnonce'] ?? ''), 'sa_garage_vehicle')) {
        wp_die('Not allowed', 403);
    }
    global $wpdb;
    $u = wp_get_current_user();
    $cid = sa_g_upsert_customer(['name' => $u->display_name, 'email' => $u->user_email, 'user_id' => $uid]);
    $do = sanitize_key((string) ($_POST['do'] ?? 'save'));
    $vid = (int) ($_POST['vid'] ?? 0);
    $msg = 'saved';

    if ($do === 'remove' && $vid) {
        if (!sa_garage_user_owns_vehicle($uid, $vid)) {
            wp_safe_redirect(sa_garage_account_url('notfound'));
            exit;
        }
        // Remove this customer's photos of the vehicle from their garage.
        foreach (sa_garage_vehicle_photos($uid, $vid) as $paid) {
            if (get_post_meta($paid, '_sa_garage_media', true) === '1') {
                wp_delete_attachment($paid, true);
            }
        }
        delete_user_meta($uid, 'sa_garage_photos_' . $vid);
        $cids = sa_g_customer_ids_for_user($uid);
        $in = implode(',', array_map('intval', $cids));
        // Detach from this customer: drop their links; delete the row only when nothing else references it.
        $wpdb->query("DELETE FROM " . sa_g_t('links') . " WHERE vehicle_id=" . (int) $vid . " AND customer_id IN ($in)");
        $v = sa_g_vehicle($vid);
        if ($v && (int) $v['customer_id'] > 0 && in_array((int) $v['customer_id'], $cids ?: [0], true)) {
            $wpdb->update(sa_g_t('vehicles'), ['customer_id' => 0, 'updated' => sa_g_now()], ['id' => $vid]);
        }
        $used = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . sa_g_t('links') . ' WHERE vehicle_id=%d', $vid))
            + (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . sa_g_t('ro') . ' WHERE vehicle_id=%d', $vid))
            + (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . sa_g_t('dvi') . ' WHERE vehicle_id=%d', $vid));
        if (!$used) {
            $wpdb->delete(sa_g_t('reminders'), ['vehicle_id' => $vid]);
            $wpdb->delete(sa_g_t('mileage'), ['vehicle_id' => $vid]);
            $wpdb->delete(sa_g_t('vehicles'), ['id' => $vid]);
        }
        if ((int) get_user_meta($uid, 'sa_garage_default_vid', true) === $vid) {
            delete_user_meta($uid, 'sa_garage_default_vid');
        }
        wp_safe_redirect(sa_garage_account_url('removed'));
        exit;
    }

    if ($do === 'default' && $vid) {
        if (sa_garage_user_owns_vehicle($uid, $vid)) {
            update_user_meta($uid, 'sa_garage_default_vid', $vid);
            $msg = 'default';
        } else {
            $msg = 'notfound';
        }
        wp_safe_redirect(sa_garage_account_url($msg));
        exit;
    }

    // add / update
    $f = sa_garage_form_vehicle();
    if ($f['make'] === '' || $f['model'] === '') {
        wp_safe_redirect(sa_garage_account_url('missing'));
        exit;
    }
    if ($vid) {
        if (!sa_garage_user_owns_vehicle($uid, $vid)) {
            wp_safe_redirect(sa_garage_account_url('notfound'));
            exit;
        }
        $upd = ['make' => $f['make'], 'model' => $f['model'], 'engine' => $f['engine'], 'color' => $f['color'], 'vin' => $f['vin'], 'updated' => sa_g_now()];
        if ($f['year']) {
            $upd['year'] = $f['year'];
        }
        if ($f['plate'] !== '') {
            $dupe = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . sa_g_t('vehicles') . ' WHERE plate=%s AND id<>%d', $f['plate'], $vid));
            if ($dupe) {
                wp_safe_redirect(sa_garage_account_url('dupeplate'));
                exit;
            }
            $upd['plate'] = $f['plate'];
        }
        $wpdb->update(sa_g_t('vehicles'), $upd, ['id' => $vid]);
        if ($f['mileage']) {
            sa_g_add_mileage($vid, $f['mileage'], 'customer', 0);
        }
        $msg = 'updated';
    } else {
        $before = $f['plate'] !== '' ? (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . sa_g_t('vehicles') . ' WHERE plate=%s', $f['plate'])) : 0;
        if ($before && !sa_garage_user_owns_vehicle($uid, $before)) {
            // Plate already belongs to another customer's record; do not hijack it.
            wp_safe_redirect(sa_garage_account_url('dupeplate'));
            exit;
        }
        if ($before) {
            // Same plate already sits in this customer's garage; nothing to add.
            wp_safe_redirect(sa_garage_account_url('exists'));
            exit;
        }
        $vid = sa_garage_link_vehicle($f + ['src_type' => 'profile', 'src_id' => 0], ['name' => $u->display_name, 'email' => $u->user_email, 'user_id' => $uid], 'profile', 0);
        if (!$vid) {
            wp_safe_redirect(sa_garage_account_url('missing'));
            exit;
        }
        // Make sure the vehicle is attached to this customer's garage record.
        if ($cid) {
            $wpdb->update(sa_g_t('vehicles'), ['customer_id' => $cid, 'updated' => sa_g_now()], ['id' => $vid]);
        }
        if (!get_user_meta($uid, 'sa_garage_default_vid', true)) {
            update_user_meta($uid, 'sa_garage_default_vid', $vid);
        }
        $msg = 'added';
    }
    // Keep the booking/roadside "saved vehicles" list in sync.
    if (function_exists('sa_svc_save_vehicle')) {
        sa_svc_save_vehicle($uid, ['make' => $f['make'], 'model' => $f['model'], 'year' => $f['year'] ? (string) $f['year'] : '', 'reg' => $f['plate']]);
    }
    wp_safe_redirect(sa_garage_account_url($msg));
    exit;
});

/* ---------- Vehicle photos (up to 3 per vehicle, customer uploads) ---------- */

/** Attachment IDs of this customer's photos for one garage vehicle. */
function sa_garage_vehicle_photos(int $uid, int $vid): array
{
    $ids = get_user_meta($uid, 'sa_garage_photos_' . $vid, true);
    if (!is_array($ids)) {
        return [];
    }
    return array_values(array_filter(array_map('intval', $ids)));
}

add_action('admin_post_sa_garage_photo', static function (): void {
    $uid = get_current_user_id();
    if (!$uid || !wp_verify_nonce((string) ($_POST['_wpnonce'] ?? ''), 'sa_garage_photo')) {
        wp_die('Not allowed', 403);
    }
    $vid = (int) ($_POST['vid'] ?? 0);
    if (!$vid || !sa_garage_user_owns_vehicle($uid, $vid)) {
        wp_safe_redirect(sa_garage_account_url('notfound'));
        exit;
    }
    $do = sanitize_key((string) ($_POST['do'] ?? 'add'));
    $photos = sa_garage_vehicle_photos($uid, $vid);

    if ($do === 'remove') {
        $aid = (int) ($_POST['aid'] ?? 0);
        if ($aid && in_array($aid, $photos, true)) {
            if (get_post_meta($aid, '_sa_garage_media', true) === '1') {
                wp_delete_attachment($aid, true);
            }
            update_user_meta($uid, 'sa_garage_photos_' . $vid, array_values(array_diff($photos, [$aid])));
            wp_safe_redirect(sa_garage_account_url('photo_removed'));
        } else {
            wp_safe_redirect(sa_garage_account_url('notfound'));
        }
        exit;
    }

    // Add: the 3-photo cap is enforced here, never only in the UI.
    if (count($photos) >= 3) {
        wp_safe_redirect(sa_garage_account_url('photo_limit'));
        exit;
    }
    $aid = 0;
    $data = (string) wp_unslash((string) ($_POST['photo_data'] ?? ''));
    if ($data !== '') {
        // Usual path: the page compresses the picked photo to a JPEG data URL first.
        if (!preg_match('#^data:image/(jpeg|png);base64,#', $data)) {
            wp_safe_redirect(sa_garage_account_url('photo_type'));
            exit;
        }
        $aid = sa_g_save_dataurl($data, 'garage-vehicle-' . $vid);
        if (!$aid) {
            wp_safe_redirect(sa_garage_account_url('photo_fail'));
            exit;
        }
    } elseif (!empty($_FILES['photo']) && is_array($_FILES['photo']) && (int) ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        // No-JS fallback: validate the raw upload ourselves (real image, type, size).
        $f = $_FILES['photo'];
        if ((int) $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
            wp_safe_redirect(sa_garage_account_url('photo_fail'));
            exit;
        }
        if ((int) $f['size'] > 8 * MB_IN_BYTES) {
            wp_safe_redirect(sa_garage_account_url('photo_size'));
            exit;
        }
        $info = @getimagesize((string) $f['tmp_name']);
        if (!$info || !in_array((string) $info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
            wp_safe_redirect(sa_garage_account_url('photo_type'));
            exit;
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $up = wp_handle_upload($f, ['test_form' => false, 'mimes' => ['jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp']]);
        if (!is_array($up) || !empty($up['error'])) {
            wp_safe_redirect(sa_garage_account_url('photo_fail'));
            exit;
        }
        $aid = wp_insert_attachment(['post_mime_type' => (string) $info['mime'], 'post_title' => 'garage-vehicle-' . $vid, 'post_status' => 'private'], (string) $up['file']);
        if (!$aid || is_wp_error($aid)) {
            wp_safe_redirect(sa_garage_account_url('photo_fail'));
            exit;
        }
        update_post_meta((int) $aid, '_sa_garage_media', '1');
        $aid = (int) $aid;
    } else {
        wp_safe_redirect(sa_garage_account_url('photo_missing'));
        exit;
    }
    $photos[] = $aid;
    update_user_meta($uid, 'sa_garage_photos_' . $vid, $photos);
    wp_safe_redirect(sa_garage_account_url('photo_added'));
    exit;
});

/** Small reusable vehicle form (add + edit). */
function sa_garage_vehicle_form(array $v = []): void
{
    static $printed = false;
    $vdb = function_exists('sa_svc_vehicle_db') ? sa_svc_vehicle_db() : [];
    $edit = !empty($v['id']);
    ?>
    <form class="sa-g-vform" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <input type="hidden" name="action" value="sa_garage_vehicle">
      <input type="hidden" name="do" value="save">
      <?php if ($edit) : ?><input type="hidden" name="vid" value="<?php echo (int) $v['id']; ?>"><?php endif; ?>
      <?php wp_nonce_field('sa_garage_vehicle'); ?>
      <div class="sa-g-vform__grid">
        <label class="sa-g-field">Make* <input name="make" required list="sa-g-makes" placeholder="Toyota" value="<?php echo esc_attr($v['make'] ?? ''); ?>" data-sa-g-make autocomplete="off"></label>
        <label class="sa-g-field">Model* <input name="model" required list="sa-g-models-<?php echo $edit ? (int) $v['id'] : 'new'; ?>" placeholder="Fielder" value="<?php echo esc_attr($v['model'] ?? ''); ?>" data-sa-g-model autocomplete="off"></label>
        <label class="sa-g-field">Year <select name="year"><option value="">Year</option><?php for ($y = (int) gmdate('Y') + 1; $y >= 1985; $y--) : ?><option <?php selected((string) ($v['year'] ?? ''), (string) $y); ?>><?php echo (int) $y; ?></option><?php endfor; ?></select></label>
        <label class="sa-g-field">Number plate <input name="reg" placeholder="KDA 123A" value="<?php echo esc_attr($v['reg'] ?? ''); ?>"></label>
        <label class="sa-g-field">Engine <input name="engine" placeholder="e.g. 1500cc, 1NZ-FE" value="<?php echo esc_attr($v['engine'] ?? ''); ?>"></label>
        <label class="sa-g-field">Colour <input name="color" placeholder="e.g. Silver" value="<?php echo esc_attr($v['color'] ?? ''); ?>"></label>
        <label class="sa-g-field">Current mileage (km) <input name="mileage" type="number" min="0" inputmode="numeric" placeholder="e.g. 85000" value="<?php echo esc_attr($v['mileage'] ?? ''); ?>"></label>
        <label class="sa-g-field">VIN <input name="vin" placeholder="Optional" value="<?php echo esc_attr($v['vin'] ?? ''); ?>"></label>
      </div>
      <button class="sa-btn" type="submit"><?php echo $edit ? 'Save changes' : 'Add vehicle'; ?></button>
    </form>
    <?php if (!$printed && $vdb) : $printed = true; ?>
      <datalist id="sa-g-makes"><?php foreach (array_keys($vdb) as $mk) : ?><option value="<?php echo esc_attr($mk); ?>"><?php endforeach; ?></datalist>
      <script type="application/json" data-sa-g-vdb><?php echo wp_json_encode($vdb); ?></script>
    <?php endif; ?>
    <datalist id="sa-g-models-<?php echo $edit ? (int) $v['id'] : 'new'; ?>"></datalist>
    <?php
}

add_action('wp_footer', static function (): void {
    if (!is_account_page()) {
        return;
    }
    ?>
    <script>(function(){var db={};try{db=JSON.parse(document.querySelector('[data-sa-g-vdb]').textContent)||{};}catch(e){}
    function fill(f){var mk=f.querySelector('[data-sa-g-make]');var m=f.querySelector('[data-sa-g-model]');if(!mk||!m||!m.list)return;var dl=m.list;dl.innerHTML='';(db[mk.value]||[]).forEach(function(mod){var o=document.createElement('option');o.value=mod;dl.appendChild(o);});}
    document.addEventListener('input',function(e){var el=e.target;if(!el||!el.hasAttribute('data-sa-g-make'))return;var f=el.closest('form');if(f)fill(f);});
    document.querySelectorAll('form.sa-g-vform').forEach(fill);})();</script>
    <?php
}, 30);

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
    $vehicles = sa_garage_user_vehicles($uid);
    $default_id = (int) get_user_meta($uid, 'sa_garage_default_vid', true);
    $default_in = false;
    foreach ($vehicles as $v) {
        if ($v['id'] === $default_id) {
            $default_in = true;
            break;
        }
    }
    if (!$default_in) {
        $default_id = $vehicles ? $vehicles[0]['id'] : 0;
    }

    $msgs = [
        'added' => 'Vehicle added to your garage.',
        'updated' => 'Vehicle updated.',
        'removed' => 'Vehicle removed from your garage.',
        'default' => 'Default vehicle updated. We will use it for parts fitment, recommendations and bookings.',
        'missing' => 'Please enter at least the make and model.',
        'dupeplate' => 'That number plate is already on another garage record. If it is yours, contact us and we will move it.',
        'exists' => 'That vehicle is already in your garage.',
        'notfound' => 'We could not find that vehicle on your account.',
        'photo_added' => 'Photo added to your vehicle.',
        'photo_removed' => 'Photo removed.',
        'photo_limit' => 'You can keep up to 3 photos per vehicle. Remove one to add another.',
        'photo_type' => 'Please choose a JPG, PNG or WebP image.',
        'photo_size' => 'That photo is too large - please choose one under 8 MB.',
        'photo_missing' => 'Please choose a photo first.',
        'photo_fail' => 'That photo could not be saved. Please try a different image.',
    ];
    $msg = sanitize_key((string) ($_GET['sa-g'] ?? ''));

    echo '<div class="sa-g-acct"><div class="sa-g-app__head"><div><h2 style="margin:0">My Garage</h2><p class="sa-g-muted" style="margin:4px 0 0">Your vehicles in one place. We use your default vehicle to check parts fitment, recommend the right products and prefill service and roadside bookings.</p></div><a class="sa-btn" href="' . esc_url(home_url('/book-service/')) . '">Book a service</a></div>';
    if (isset($msgs[$msg])) {
        echo '<p class="sa-g-notebox">' . esc_html($msgs[$msg]) . '</p>';
    }

    echo '<details class="sa-g-veh__card sa-g-add"><summary><strong>+ Add a vehicle</strong> <span class="sa-g-muted">make, model, year, plate - it takes seconds</span></summary>';
    sa_garage_vehicle_form();
    echo '</details>';

    if (!$vehicles) {
        echo '<div class="sa-g-veh__card"><p>No vehicles saved yet. Add your car above, and it will also appear here automatically when you book a service, request roadside help or bring the car in.</p></div></div>';
        return;
    }
    echo '<div class="sa-g-veh">';
    foreach ($vehicles as $v) {
        $vid = (int) $v['id'];
        $hist = sa_g_vehicle_history($vid, true);
        $rem = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . sa_g_t('reminders') . " WHERE vehicle_id=%d AND status='open' ORDER BY due_date LIMIT 1", $vid), ARRAY_A);
        $shop = add_query_arg(array_filter(['sa_make' => $v['make'], 'sa_model' => $v['model'], 'sa_year' => $v['year']]), home_url('/shop/'));
        echo '<article class="sa-g-veh__card"><div class="sa-g-veh__top"><div><h3 style="margin:0 0 6px">' . esc_html(trim(($v['year'] ?: '') . ' ' . $v['make'] . ' ' . $v['model'])) . '</h3>'
            . ($v['reg'] !== '' ? '<span class="sa-g-plate">' . esc_html($v['reg']) . '</span>' : '')
            . ($vid === $default_id ? ' <span class="sa-g-default">Default</span>' : '') . '</div>'
            . '<div class="sa-g-veh__cta"><a class="sa-btn sa-btn--outline" href="' . esc_url(add_query_arg(['make' => $v['make'], 'model' => $v['model'], 'year' => $v['year'] ?: ''], home_url('/book-service/'))) . '">Book for this car</a>'
            . '<a class="sa-btn sa-btn--outline" href="' . esc_url($shop) . '">Parts that fit</a></div></div>';
        echo '<div class="sa-g-veh__stats">' . ($v['mileage'] ? '<span>Last mileage: <strong>' . esc_html(number_format((int) $v['mileage'])) . ' km</strong></span>' : '')
            . ($v['engine'] ? '<span>Engine: ' . esc_html($v['engine']) . '</span>' : '')
            . ($v['color'] ? '<span>Colour: ' . esc_html($v['color']) . '</span>' : '')
            . ($v['vin'] ? '<span>VIN: ' . esc_html($v['vin']) . '</span>' : '')
            . '<span>' . count($hist) . ' record(s)</span>'
            . ($rem ? '<span>Next due: <strong>' . esc_html($rem['service']) . '</strong>' . ($rem['due_date'] ? ' by ' . esc_html(mysql2date('j M Y', $rem['due_date'])) : '') . ((int) $rem['due_km'] ? ' or ' . esc_html(number_format((int) $rem['due_km'])) . ' km' : '') . '</span>' : '') . '</div>';
        $photos = sa_garage_vehicle_photos($uid, $vid);
        $left = 3 - count($photos);
        echo '<div class="sa-g-vehphotos"><div class="sa-g-vehphotos__head"><strong>Photos</strong><span class="sa-g-muted">' . count($photos) . ' of 3</span></div>';
        if ($photos) {
            echo '<div class="sa-g-thumbs">';
            foreach ($photos as $aid) {
                $url = wp_get_attachment_image_url($aid, 'medium');
                if (!$url) {
                    continue;
                }
                $full = wp_get_attachment_image_url($aid, 'large') ?: $url;
                echo '<div class="sa-g-thumb"><a href="' . esc_url($full) . '" target="_blank" rel="noopener"><img src="' . esc_url($url) . '" alt="' . esc_attr(trim(($v['year'] ? $v['year'] . ' ' : '') . $v['make'] . ' ' . $v['model'])) . '" loading="lazy"></a>'
                    . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Remove this photo?\');"><input type="hidden" name="action" value="sa_garage_photo"><input type="hidden" name="do" value="remove"><input type="hidden" name="vid" value="' . $vid . '"><input type="hidden" name="aid" value="' . (int) $aid . '">' . wp_nonce_field('sa_garage_photo', '_wpnonce', true, false) . '<button type="submit" aria-label="Remove photo">&times;</button></form></div>';
            }
            echo '</div>';
        }
        if ($left > 0) {
            echo '<form class="sa-g-vehphoto-add" method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" data-sa-g-vehphoto>'
                . '<input type="hidden" name="action" value="sa_garage_photo"><input type="hidden" name="do" value="add"><input type="hidden" name="vid" value="' . $vid . '">' . wp_nonce_field('sa_garage_photo', '_wpnonce', true, false)
                . '<input type="hidden" name="photo_data" value="" data-photo-data>'
                . '<label class="sa-btn sa-btn--outline sa-g-photo-btn">&#128247; Choose photo<input type="file" name="photo" accept="image/*" data-photo-file hidden></label>'
                . '<span class="sa-g-vehphoto-preview" data-photo-preview></span>'
                . '<button class="sa-btn" type="submit">Add photo</button>'
                . '<span class="sa-g-muted">JPG, PNG or WebP, up to 8 MB. ' . (int) $left . ' of 3 left.</span></form>';
        } else {
            echo '<p class="sa-g-muted" style="margin:6px 0 0">All 3 photo slots used. Remove a photo to add another.</p>';
        }
        echo '</div>';
        echo '<div class="sa-g-veh__actions">'
            . '<details class="sa-g-edit"><summary>Edit</summary>';
        sa_garage_vehicle_form($v);
        echo '</details>';
        if ($vid !== $default_id) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="sa_garage_vehicle"><input type="hidden" name="do" value="default"><input type="hidden" name="vid" value="' . $vid . '">' . wp_nonce_field('sa_garage_vehicle', '_wpnonce', true, false) . '<button class="sa-g-link" type="submit">Set as default</button></form>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Remove this vehicle from your garage? Its service history stays on our records.\');"><input type="hidden" name="action" value="sa_garage_vehicle"><input type="hidden" name="do" value="remove"><input type="hidden" name="vid" value="' . $vid . '">' . wp_nonce_field('sa_garage_vehicle', '_wpnonce', true, false) . '<button class="sa-g-link sa-g-link--danger" type="submit">Remove</button></form>';
        echo '</div>';
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
    ?>
    <script>(function(){
    document.querySelectorAll('form[data-sa-g-vehphoto]').forEach(function(form){
        var file=form.querySelector('[data-photo-file]'),data=form.querySelector('[data-photo-data]'),prev=form.querySelector('[data-photo-preview]');
        if(!file||!data){return;}
        file.addEventListener('change',function(){
            var f=file.files&&file.files[0];
            if(!f){return;}
            if(!/^image\//.test(f.type)){alert('Please choose a JPG, PNG or WebP image.');file.value='';return;}
            if(f.size>8*1024*1024){alert('That photo is too large - please choose one under 8 MB.');file.value='';return;}
            var img=new Image(),url=URL.createObjectURL(f);
            img.onload=function(){
                var s=Math.min(1,1400/Math.max(img.width,img.height)),c=document.createElement('canvas');
                c.width=Math.round(img.width*s);c.height=Math.round(img.height*s);
                c.getContext('2d').drawImage(img,0,0,c.width,c.height);
                var d=c.toDataURL('image/jpeg',0.8);
                data.value=d;file.value='';
                if(prev){prev.innerHTML='<img src="'+d+'" alt="Photo to add">';}
                URL.revokeObjectURL(url);
            };
            img.onerror=function(){URL.revokeObjectURL(url);/* Leave the raw file in place; the server validates it. */};
            img.src=url;
        });
    });})();</script>
    <?php
});

/* ---------- Fitment badge on product pages ("Fits your car") ---------- */

/** The visitor's vehicle for fit checks: garage default for logged-in users, else the picked browse vehicle. */
function sa_garage_viewer_vehicle(): array
{
    if (is_user_logged_in()) {
        $v = sa_garage_user_default_vehicle(get_current_user_id());
        if ($v && $v['make'] !== '') {
            return ['make' => $v['make'], 'model' => $v['model'], 'year' => $v['year'], 'label' => trim(($v['year'] ? $v['year'] . ' ' : '') . $v['make'] . ' ' . $v['model']), 'mine' => true];
        }
    }
    $raw = json_decode(wp_unslash((string) ($_COOKIE['sa_pz'] ?? '')), true);
    if (is_array($raw) && !empty($raw['mk'])) {
        $mk = ucwords(str_replace('-', ' ', (string) $raw['mk']));
        $mo = '';
        if (!empty($raw['mo']) && str_contains((string) $raw['mo'], '/')) {
            $mo = ucwords(str_replace('-', ' ', (string) substr((string) $raw['mo'], strpos((string) $raw['mo'], '/') + 1)));
        }
        $y = preg_match('/^(19|20)\d{2}$/', (string) ($raw['y'] ?? '')) ? (string) $raw['y'] : '';
        return ['make' => $mk, 'model' => $mo, 'year' => $y, 'label' => trim(($y ? $y . ' ' : '') . $mk . ' ' . $mo), 'mine' => false];
    }
    return [];
}

/**
 * Year-accurate fit check for one product against a vehicle.
 * Returns true when the product's listed fitment covers the vehicle, false when it
 * does not, and null when the product carries no fitment data at all.
 * Mirrors the product-page fit badge rules below; the "Fits your vehicle"
 * recommendation row (smart-rank.php) filters candidates through this so its claim
 * never outruns the badge.
 *
 * @param string $mk_slug sanitize_title() make slug, e.g. 'toyota'.
 * @param string $mo_slug sanitize_title() bare model slug, e.g. 'corolla' ('' = make-level check).
 */
function sa_garage_product_fits_vehicle(int $pid, string $mk_slug, string $mo_slug, int $year): ?bool
{
    if (!function_exists('sa_fx_vehicles_for') || !function_exists('sa_fx_vehicle_vocab')) {
        return null;
    }
    $name = get_post_field('post_title', $pid);
    if (!is_string($name) || $name === '') {
        return null;
    }
    $tags = wp_get_post_terms($pid, 'product_tag', ['fields' => 'names']);
    $entries = sa_fx_vehicles_for($name, is_array($tags) ? $tags : [], sa_fx_vehicle_vocab());
    if (!$entries) {
        return null;
    }
    foreach ($entries as $e) {
        if (sanitize_title((string) $e['make']) !== $mk_slug) {
            continue;
        }
        if ($mo_slug !== '' && (string) ($e['model'] ?? '') !== '' && sanitize_title((string) $e['model']) !== $mo_slug) {
            continue;
        }
        $years = array_map('intval', (array) ($e['years'] ?? []));
        if ($years === [] || $year <= 0 || in_array($year, $years, true)) {
            return true;
        }
    }
    return false;
}

add_action('woocommerce_single_product_summary', static function (): void {
    global $product;
    if (!$product instanceof WC_Product || !function_exists('sa_fx_vehicles_for')) {
        return;
    }
    $veh = sa_garage_viewer_vehicle();
    if (!$veh) {
        return;
    }
    $pid = $product->get_id();
    $tags = wp_get_post_terms($pid, 'product_tag', ['fields' => 'names']);
    $tags = is_array($tags) ? $tags : [];
    $authoritative = false;
    foreach ($tags as $t) {
        if (strpos(html_entity_decode((string) $t, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'fits_') === 0) {
            $authoritative = true;
            break;
        }
    }
    $entries = sa_fx_vehicles_for((string) $product->get_name(), $tags, sa_fx_vehicle_vocab());
    if (!$entries) {
        return; // No fitment data on this part: say nothing rather than guess.
    }
    $mk = sanitize_title(sa_fx_make_label($veh['make']));
    $mo = sanitize_title($veh['model']);
    $yr = (int) $veh['year'];
    $fit = false;
    $same_make = false;
    $year_miss = false;
    foreach ($entries as $e) {
        if (sanitize_title($e['make']) !== $mk) {
            continue;
        }
        $same_make = true;
        if ($e['model'] !== '' && $mo !== '' && sanitize_title($e['model']) !== $mo) {
            continue;
        }
        if ($e['years'] && $yr) {
            if (in_array($yr, array_map('intval', $e['years']), true)) {
                $fit = true;
                break;
            }
            $year_miss = true;
            continue;
        }
        $fit = true;
        break;
    }
    $mine = $veh['mine'];
    if ($fit) {
        echo '<p class="sa-fit sa-fit--yes">✓ Fits ' . esc_html($mine ? 'your ' : 'the ') . esc_html($veh['label']) . '</p>';
    } elseif ($year_miss) {
        // Same make and model but the listed years do not cover theirs - warn even when the
        // fitment data came from the product title, because the mismatch is explicit.
        echo '<p class="sa-fit sa-fit--no">✗ Listed for a different year range than ' . esc_html($mine ? 'your ' : 'the ') . esc_html($veh['label']) . ' - <a href="' . esc_url(home_url('/contact/')) . '">ask us to confirm</a></p>';
    } elseif ($authoritative && $same_make) {
        echo '<p class="sa-fit sa-fit--no">✗ Not listed for ' . esc_html($mine ? 'your ' : 'the ') . esc_html($veh['label']) . ' - <a href="' . esc_url(home_url('/contact/')) . '">ask us to confirm</a></p>';
    }
}, 26);


