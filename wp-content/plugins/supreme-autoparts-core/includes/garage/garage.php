<?php
/**
 * Supreme Workshop — shop management system (Super Admin → Workshop).
 * Customers & vehicles CRM, repair orders + estimates with customer approval, digital vehicle
 * inspections (DVI / pickup & drop-off condition cards), technicians + time clock, bay scheduler,
 * parts usage with stock deduction, Pesapal invoices, service reminders and reports.
 *
 * Integration hooks for other modules:
 *   sa_garage_link_vehicle(array $vehicle, array $customer, string $src_type, int $src_id): int vehicle id
 *   do_action('sa_garage_link_roadside', int $roadside_post_id)  // link a roadside request to a vehicle
 *   do_action('sa_garage_link_booking', int $booking_post_id)
 *   apply_filters('sa_garage_vehicle_for', 0, 'roadside', $id)    // look up the vehicle linked to a source
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const SA_GARAGE_DB_VERSION = '1.0.2';

function sa_g_t(string $name): string
{
    global $wpdb;
    return $wpdb->prefix . 'sa_g_' . $name;
}

add_action('init', static function (): void {
    if (get_option('sa_garage_db') !== SA_GARAGE_DB_VERSION) {
        sa_garage_install();
    }
}, 4);

function sa_garage_install(): void
{
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $c = $wpdb->get_charset_collate();
    $t = 'sa_g_t';
    dbDelta("CREATE TABLE {$t('customers')} (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 user_id bigint(20) unsigned NOT NULL DEFAULT 0,
 name varchar(190) NOT NULL DEFAULT '',
 email varchar(190) NOT NULL DEFAULT '',
 phone varchar(40) NOT NULL DEFAULT '',
 notes text NULL,
 created datetime NOT NULL,
 PRIMARY KEY  (id),
 KEY email (email),
 KEY phone (phone),
 KEY user_id (user_id)
) $c;");
    dbDelta("CREATE TABLE {$t('vehicles')} (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 plate varchar(20) NOT NULL DEFAULT '',
 customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
 make varchar(80) NOT NULL DEFAULT '',
 model varchar(80) NOT NULL DEFAULT '',
 year smallint(5) unsigned NOT NULL DEFAULT 0,
 vin varchar(32) NOT NULL DEFAULT '',
 engine varchar(80) NOT NULL DEFAULT '',
 color varchar(40) NOT NULL DEFAULT '',
 mileage int(10) unsigned NOT NULL DEFAULT 0,
 notes text NULL,
 created datetime NOT NULL,
 updated datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY plate (plate),
 KEY customer_id (customer_id),
 KEY vin (vin)
) $c;");
    dbDelta("CREATE TABLE {$t('mileage')} (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 vehicle_id bigint(20) unsigned NOT NULL,
 km int(10) unsigned NOT NULL,
 source varchar(30) NOT NULL DEFAULT '',
 source_id bigint(20) unsigned NOT NULL DEFAULT 0,
 created datetime NOT NULL,
 PRIMARY KEY  (id),
 KEY vehicle_id (vehicle_id)
) $c;");
    dbDelta("CREATE TABLE {$t('links')} (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 vehicle_id bigint(20) unsigned NOT NULL DEFAULT 0,
 customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
 src_type varchar(20) NOT NULL,
 src_id bigint(20) unsigned NOT NULL,
 created datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY src (src_type,src_id),
 KEY vehicle_id (vehicle_id),
 KEY customer_id (customer_id)
) $c;");
    dbDelta("CREATE TABLE {$t('ro')} (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 ref varchar(24) NOT NULL DEFAULT '',
 status varchar(20) NOT NULL DEFAULT 'estimate',
 customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
 vehicle_id bigint(20) unsigned NOT NULL DEFAULT 0,
 booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
 roadside_id bigint(20) unsigned NOT NULL DEFAULT 0,
 tech_id bigint(20) unsigned NOT NULL DEFAULT 0,
 bay varchar(20) NOT NULL DEFAULT '',
 sched_start datetime NULL,
 sched_end datetime NULL,
 mileage_in int(10) unsigned NOT NULL DEFAULT 0,
 complaint text NULL,
 notes text NULL,
 token varchar(40) NOT NULL DEFAULT '',
 sent_at datetime NULL,
 approved_at datetime NULL,
 order_id bigint(20) unsigned NOT NULL DEFAULT 0,
 paid_at datetime NULL,
 total_kes decimal(12,2) NOT NULL DEFAULT 0,
 created datetime NOT NULL,
 updated datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY ref (ref),
 KEY status (status),
 KEY vehicle_id (vehicle_id),
 KEY customer_id (customer_id),
 KEY tech_id (tech_id)
) $c;");
    dbDelta("CREATE TABLE {$t('ro_items')} (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 ro_id bigint(20) unsigned NOT NULL,
 type varchar(10) NOT NULL DEFAULT 'labour',
 product_id bigint(20) unsigned NOT NULL DEFAULT 0,
 sku varchar(80) NOT NULL DEFAULT '',
 description varchar(255) NOT NULL DEFAULT '',
 qty decimal(10,2) NOT NULL DEFAULT 1,
 hours decimal(6,2) NOT NULL DEFAULT 0,
 unit_kes decimal(12,2) NOT NULL DEFAULT 0,
 approval varchar(10) NOT NULL DEFAULT 'pending',
 tech_id bigint(20) unsigned NOT NULL DEFAULT 0,
 deducted tinyint(1) NOT NULL DEFAULT 0,
 sort int(11) NOT NULL DEFAULT 0,
 created datetime NOT NULL,
 PRIMARY KEY  (id),
 KEY ro_id (ro_id),
 KEY product_id (product_id)
) $c;");
    dbDelta("CREATE TABLE {$t('time')} (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 ro_id bigint(20) unsigned NOT NULL,
 item_id bigint(20) unsigned NOT NULL DEFAULT 0,
 tech_id bigint(20) unsigned NOT NULL,
 started datetime NOT NULL,
 ended datetime NULL,
 PRIMARY KEY  (id),
 KEY ro_id (ro_id),
 KEY tech_id (tech_id)
) $c;");
    dbDelta("CREATE TABLE {$t('dvi')} (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 ref varchar(24) NOT NULL DEFAULT '',
 kind varchar(12) NOT NULL DEFAULT 'pickup',
 ro_id bigint(20) unsigned NOT NULL DEFAULT 0,
 booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
 roadside_id bigint(20) unsigned NOT NULL DEFAULT 0,
 vehicle_id bigint(20) unsigned NOT NULL DEFAULT 0,
 customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
 mechanic_id bigint(20) unsigned NOT NULL DEFAULT 0,
 mileage int(10) unsigned NOT NULL DEFAULT 0,
 fuel tinyint(3) unsigned NOT NULL DEFAULT 0,
 data longtext NULL,
 lat decimal(10,7) NULL,
 lng decimal(10,7) NULL,
 token varchar(40) NOT NULL DEFAULT '',
 pdf varchar(255) NOT NULL DEFAULT '',
 emailed_at datetime NULL,
 created datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY ref (ref),
 KEY vehicle_id (vehicle_id),
 KEY ro_id (ro_id),
 KEY booking_id (booking_id)
) $c;");
    dbDelta("CREATE TABLE {$t('reminders')} (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 vehicle_id bigint(20) unsigned NOT NULL,
 ro_id bigint(20) unsigned NOT NULL DEFAULT 0,
 service varchar(190) NOT NULL DEFAULT '',
 due_date date NULL,
 due_km int(10) unsigned NOT NULL DEFAULT 0,
 status varchar(10) NOT NULL DEFAULT 'open',
 sent_at datetime NULL,
 created datetime NOT NULL,
 PRIMARY KEY  (id),
 KEY vehicle_id (vehicle_id),
 KEY status (status)
) $c;");
    update_option('sa_garage_db', SA_GARAGE_DB_VERSION, false);
    if (!get_role('sa_mechanic')) {
        add_role('sa_mechanic', 'Mechanic / Technician', ['read' => true, 'sa_garage' => true, 'upload_files' => true]);
    }
    foreach (['administrator', 'shop_manager'] as $r) {
        if ($role = get_role($r)) {
            $role->add_cap('sa_garage');
            $role->add_cap('sa_garage_manage');
        }
    }
    flush_rewrite_rules(false);
}

/* ---------- Access ---------- */

function sa_g_can(): bool
{
    return current_user_can('sa_garage') || current_user_can('manage_woocommerce');
}
function sa_g_can_manage(): bool
{
    return current_user_can('sa_garage_manage') || current_user_can('manage_woocommerce');
}

/* Mechanics never see wp-admin (they use the mobile workshop app at /workshop/). */
add_action('admin_init', static function (): void {
    if (wp_doing_ajax() || !is_user_logged_in()) {
        return;
    }
    $u = wp_get_current_user();
    if (in_array('sa_mechanic', (array) $u->roles, true) && !current_user_can('manage_woocommerce') && !str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), 'admin-post.php')) {
        wp_safe_redirect(home_url('/workshop/'));
        exit;
    }
});

/* ---------- Helpers ---------- */

function sa_g_now(): string
{
    return current_time('mysql');
}

function sa_g_plate(string $p): string
{
    return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $p));
}

function sa_g_phone(string $p): string
{
    $d = (string) preg_replace('/\D/', '', $p);
    if (strlen($d) === 10 && $d[0] === '0') {
        $d = '254' . substr($d, 1);
    } elseif (strlen($d) === 9 && in_array($d[0], ['7', '1'], true)) {
        $d = '254' . $d;
    }
    return $d === '' ? '' : '+' . $d;
}

function sa_g_kes($n): string
{
    return 'KES ' . number_format((float) $n, ((float) $n == floor((float) $n)) ? 0 : 2);
}

function sa_g_customer(int $id): ?array
{
    global $wpdb;
    $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . sa_g_t('customers') . ' WHERE id=%d', $id), ARRAY_A);
    return $r ?: null;
}

function sa_g_vehicle(int $id): ?array
{
    global $wpdb;
    $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . sa_g_t('vehicles') . ' WHERE id=%d', $id), ARRAY_A);
    return $r ?: null;
}

function sa_g_vehicle_label(?array $v): string
{
    if (!$v) {
        return '—';
    }
    return trim(($v['year'] ? $v['year'] . ' ' : '') . $v['make'] . ' ' . $v['model']) . ($v['plate'] !== '' && !str_starts_with($v['plate'], 'NOPLATE') ? ' · ' . $v['plate'] : '');
}

/** Find or create a customer by email / phone / user. */
function sa_g_upsert_customer(array $c): int
{
    global $wpdb;
    $T = sa_g_t('customers');
    $email = strtolower(sanitize_email((string) ($c['email'] ?? '')));
    $phone = sa_g_phone((string) ($c['phone'] ?? ''));
    $uid = (int) ($c['user_id'] ?? 0);
    if (!$uid && $email !== '' && ($u = get_user_by('email', $email))) {
        $uid = (int) $u->ID;
    }
    $id = 0;
    if ($email !== '') {
        $id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $T WHERE email=%s ORDER BY id LIMIT 1", $email));
    }
    if (!$id && $phone !== '') {
        $id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $T WHERE phone=%s ORDER BY id LIMIT 1", $phone));
    }
    if (!$id && $uid) {
        $id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $T WHERE user_id=%d ORDER BY id LIMIT 1", $uid));
    }
    $name = sanitize_text_field((string) ($c['name'] ?? ''));
    if ($id) {
        $cur = sa_g_customer($id);
        $upd = [];
        foreach (['name' => $name, 'email' => $email, 'phone' => $phone] as $k => $v) {
            if ($v !== '' && ($cur[$k] ?? '') === '') {
                $upd[$k] = $v;
            }
        }
        if ($uid && !(int) $cur['user_id']) {
            $upd['user_id'] = $uid;
        }
        if ($upd) {
            $wpdb->update($T, $upd, ['id' => $id]);
        }
        return $id;
    }
    if ($email === '' && $phone === '' && !$uid) {
        return 0;
    }
    $wpdb->insert($T, ['user_id' => $uid, 'name' => $name, 'email' => $email, 'phone' => $phone, 'created' => sa_g_now()]);
    return (int) $wpdb->insert_id;
}

/** Find or create a vehicle. Plate is the unique key; with no plate, VIN; else a per-customer placeholder. */
function sa_g_upsert_vehicle(array $v, int $customer_id = 0): int
{
    global $wpdb;
    $T = sa_g_t('vehicles');
    $plate = sa_g_plate((string) ($v['plate'] ?? $v['reg'] ?? ''));
    $vin = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($v['vin'] ?? '')));
    $make = sanitize_text_field((string) ($v['make'] ?? ''));
    $model = sanitize_text_field((string) ($v['model'] ?? ''));
    $year = (int) ($v['year'] ?? 0);
    $year = ($year >= 1950 && $year <= (int) gmdate('Y') + 1) ? $year : 0;
    $id = 0;
    if ($plate !== '') {
        $id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $T WHERE plate=%s", $plate));
    }
    if (!$id && $vin !== '') {
        $id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $T WHERE vin=%s LIMIT 1", $vin));
    }
    if (!$id && $plate === '' && $customer_id && $make !== '') {
        $id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $T WHERE customer_id=%d AND make=%s AND model=%s AND plate LIKE 'NOPLATE%%' LIMIT 1", $customer_id, $make, $model));
    }
    if ($id) {
        $cur = sa_g_vehicle($id);
        $upd = [];
        foreach (['make' => $make, 'model' => $model, 'vin' => $vin, 'engine' => (string) ($v['engine'] ?? ''), 'color' => (string) ($v['color'] ?? '')] as $k => $val) {
            if ($val !== '' && ($cur[$k] ?? '') === '') {
                $upd[$k] = sanitize_text_field($val);
            }
        }
        if ($year && !(int) $cur['year']) {
            $upd['year'] = $year;
        }
        if ($customer_id && !(int) $cur['customer_id']) {
            $upd['customer_id'] = $customer_id;
        }
        if ($plate !== '' && str_starts_with((string) $cur['plate'], 'NOPLATE')) {
            $upd['plate'] = $plate;
        }
        if ($upd) {
            $upd['updated'] = sa_g_now();
            $wpdb->update($T, $upd, ['id' => $id]);
        }
    } else {
        if ($make === '' && $plate === '' && $vin === '') {
            return 0;
        }
        $wpdb->insert($T, ['plate' => $plate !== '' ? $plate : 'NOPLATE-' . strtoupper(wp_generate_password(6, false, false)), 'customer_id' => $customer_id, 'make' => $make, 'model' => $model, 'year' => $year, 'vin' => $vin,
            'engine' => sanitize_text_field((string) ($v['engine'] ?? '')), 'color' => sanitize_text_field((string) ($v['color'] ?? '')), 'created' => sa_g_now(), 'updated' => sa_g_now()]);
        $id = (int) $wpdb->insert_id;
    }
    if ($id && !empty($v['mileage'])) {
        sa_g_add_mileage($id, (int) $v['mileage'], (string) ($v['src_type'] ?? 'manual'), (int) ($v['src_id'] ?? 0));
    }
    return $id;
}

function sa_g_add_mileage(int $vehicle_id, int $km, string $source, int $source_id = 0): void
{
    global $wpdb;
    if ($km <= 0) {
        return;
    }
    $wpdb->insert(sa_g_t('mileage'), ['vehicle_id' => $vehicle_id, 'km' => $km, 'source' => $source, 'source_id' => $source_id, 'created' => sa_g_now()]);
    $wpdb->query($wpdb->prepare('UPDATE ' . sa_g_t('vehicles') . ' SET mileage=GREATEST(mileage,%d), updated=%s WHERE id=%d', $km, sa_g_now(), $vehicle_id));
}

/**
 * Public integration API: link any source (booking, roadside, order, dvi, ro) to a vehicle + customer.
 * @return int vehicle id (0 when there was nothing to link)
 */
function sa_garage_link_vehicle(array $vehicle, array $customer, string $src_type, int $src_id): int
{
    global $wpdb;
    $cid = sa_g_upsert_customer($customer);
    $vid = sa_g_upsert_vehicle($vehicle + ['src_type' => $src_type, 'src_id' => $src_id], $cid);
    if ($src_id && ($cid || $vid)) {
        $wpdb->query($wpdb->prepare('INSERT INTO ' . sa_g_t('links') . ' (vehicle_id,customer_id,src_type,src_id,created) VALUES (%d,%d,%s,%d,%s) ON DUPLICATE KEY UPDATE vehicle_id=IF(VALUES(vehicle_id)>0,VALUES(vehicle_id),vehicle_id), customer_id=IF(VALUES(customer_id)>0,VALUES(customer_id),customer_id)', $vid, $cid, $src_type, $src_id, sa_g_now()));
    }
    do_action('sa_garage_linked', $vid, $cid, $src_type, $src_id);
    return $vid;
}

function sa_g_link_for(string $src_type, int $src_id): array
{
    global $wpdb;
    $r = $wpdb->get_row($wpdb->prepare('SELECT vehicle_id, customer_id FROM ' . sa_g_t('links') . ' WHERE src_type=%s AND src_id=%d', $src_type, $src_id), ARRAY_A);
    return $r ? ['vehicle_id' => (int) $r['vehicle_id'], 'customer_id' => (int) $r['customer_id']] : ['vehicle_id' => 0, 'customer_id' => 0];
}
add_filter('sa_garage_vehicle_for', static fn($v, string $type = '', int $id = 0) => sa_g_link_for($type, $id)['vehicle_id'] ?: $v, 10, 3);

function sa_g_link_booking(int $id): int
{
    if (get_post_type($id) !== 'sa_booking' || !function_exists('sa_svc_booking_data')) {
        return 0;
    }
    $b = sa_svc_booking_data($id);
    return sa_garage_link_vehicle(['plate' => $b['reg'], 'make' => $b['make'], 'model' => $b['model'], 'year' => $b['year']], ['name' => $b['name'], 'email' => $b['email'], 'phone' => $b['phone'], 'user_id' => $b['user']], 'booking', $id);
}

function sa_g_link_roadside(int $id): int
{
    if (get_post_type($id) !== 'sa_roadside' || !function_exists('sa_rs_data')) {
        return 0;
    }
    $r = sa_rs_data($id);
    return sa_garage_link_vehicle(['plate' => (string) $r['reg'], 'make' => (string) $r['make'], 'model' => (string) $r['model'], 'year' => (string) $r['year']], ['name' => (string) $r['name'], 'email' => (string) $r['email'], 'phone' => (string) $r['phone'], 'user_id' => (int) $r['user']], 'roadside', $id);
}
add_action('sa_garage_link_booking', static function ($id): void { sa_g_link_booking((int) $id); });
add_action('sa_garage_link_roadside', static function ($id): void { sa_g_link_roadside((int) $id); });

/* Auto-link new bookings / roadside requests once all their meta is written (end of request). */
$GLOBALS['sa_g_queue'] = [];
add_action('added_post_meta', static function ($mid, $pid, $key): void {
    if ($key === '_sa_bk_ref' || $key === '_rs_ref') {
        $GLOBALS['sa_g_queue'][(int) $pid] = $key === '_sa_bk_ref' ? 'booking' : 'roadside';
    }
}, 10, 3);
add_action('shutdown', static function (): void {
    foreach ((array) ($GLOBALS['sa_g_queue'] ?? []) as $pid => $type) {
        try {
            $type === 'booking' ? sa_g_link_booking((int) $pid) : sa_g_link_roadside((int) $pid);
        } catch (\Throwable $e) {
            error_log('sa_garage link failed: ' . $e->getMessage());
        }
    }
}, 1);

/* Woo orders link to the customer record (parts orders on the customer profile). */
add_action('woocommerce_checkout_order_processed', static function ($oid): void {
    $o = wc_get_order($oid);
    if ($o instanceof WC_Order) {
        $cid = sa_g_upsert_customer(['name' => $o->get_formatted_billing_full_name(), 'email' => $o->get_billing_email(), 'phone' => $o->get_billing_phone(), 'user_id' => $o->get_customer_id()]);
        if ($cid) {
            global $wpdb;
            $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . sa_g_t('links') . ' (vehicle_id,customer_id,src_type,src_id,created) VALUES (0,%d,%s,%d,%s)', $cid, 'order', (int) $oid, sa_g_now()));
        }
    }
}, 30);

/** One-off backfill of existing bookings / roadside requests (batched, idempotent). */
function sa_g_backfill(int $limit = 200): array
{
    global $wpdb;
    $done = ['booking' => 0, 'roadside' => 0];
    $L = sa_g_t('links');
    foreach (['sa_booking' => 'booking', 'sa_roadside' => 'roadside'] as $pt => $type) {
        $ids = $wpdb->get_col($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN $L l ON l.src_type=%s AND l.src_id=p.ID WHERE p.post_type=%s AND l.id IS NULL LIMIT %d", $type, $pt, $limit));
        foreach ($ids as $id) {
            $type === 'booking' ? sa_g_link_booking((int) $id) : sa_g_link_roadside((int) $id);
            $done[$type]++;
        }
    }
    return $done;
}
add_action('init', static function (): void {
    if (get_option('sa_garage_backfilled') !== '1' && get_option('sa_garage_db') === SA_GARAGE_DB_VERSION) {
        $r = sa_g_backfill(300);
        if (array_sum($r) < 300) {
            update_option('sa_garage_backfilled', '1', false);
        }
    }
}, 99);

/** Shared upload helper: store a data-URL JPEG/PNG (camera/canvas) as a media attachment. */
function sa_g_save_dataurl(string $data, string $name, int $parent = 0): int
{
    if (!preg_match('#^data:image/(jpeg|png);base64,#', $data, $m)) {
        return 0;
    }
    $bin = base64_decode(substr($data, strpos($data, ',') + 1), true);
    if ($bin === false || strlen($bin) > 6 * MB_IN_BYTES || @getimagesizefromstring($bin) === false) {
        return 0;
    }
    $ext = $m[1] === 'png' ? 'png' : 'jpg';
    $up = wp_upload_bits(sanitize_file_name($name . '-' . wp_generate_password(6, false, false) . '.' . $ext), null, $bin);
    if (!empty($up['error'])) {
        return 0;
    }
    $aid = wp_insert_attachment(['post_mime_type' => 'image/' . ($ext === 'png' ? 'png' : 'jpeg'), 'post_title' => $name, 'post_status' => 'private'], $up['file'], $parent);
    if ($aid && !is_wp_error($aid)) {
        update_post_meta((int) $aid, '_sa_garage_media', '1');
        return (int) $aid;
    }
    return 0;
}

/* Workshop media stays out of the public media library listing. */
add_filter('ajax_query_attachments_args', static function (array $q): array {
    $q['meta_query'][] = ['key' => '_sa_garage_media', 'compare' => 'NOT EXISTS'];
    return $q;
});

require_once __DIR__ . '/garage-ro.php';
require_once __DIR__ . '/garage-dvi.php';
require_once __DIR__ . '/garage-admin.php';
require_once __DIR__ . '/garage-account.php';
require_once __DIR__ . '/garage-reminders.php';
