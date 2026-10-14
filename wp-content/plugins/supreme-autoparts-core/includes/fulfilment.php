<?php
/**
 * Fulfilment: fitted-at-garage package, estimated Fargo delivery dates, store pickup slots.
 * Delivery dates are ESTIMATES (see docs/DELIVERY-ESTIMATES.md for sources and assumptions).
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/* =========================================================
 * Working days (Fargo: no Sunday / Kenyan public holiday deliveries assumed)
 * ======================================================= */
function sa_ff_holidays(): array
{
    return ['2026-10-10', '2026-10-20', '2026-12-12', '2026-12-25', '2026-12-26', '2027-01-01', '2027-03-26', '2027-03-29', '2027-05-01', '2027-06-01', '2027-10-10', '2027-10-20', '2027-12-12', '2027-12-25', '2027-12-26'];
}
function sa_ff_is_workday(DateTimeImmutable $d): bool
{
    return (int) $d->format('w') !== 0 && !in_array($d->format('Y-m-d'), sa_ff_holidays(), true);
}
function sa_ff_add_workdays(DateTimeImmutable $d, int $n): DateTimeImmutable
{
    while ($n > 0) {
        $d = $d->modify('+1 day');
        if (sa_ff_is_workday($d)) {
            $n--;
        }
    }
    return $d;
}
function sa_ff_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', wp_timezone());
}
/** Hand-over to Fargo: today if a working day before 14:00 EAT, else the next working day. */
function sa_ff_dispatch_day(int $lead = 0): DateTimeImmutable
{
    $n = sa_ff_now();
    $d = $n->setTime(0, 0);
    if (!sa_ff_is_workday($d) || (int) $n->format('G') >= 14) {
        $d = sa_ff_add_workdays($d, 1);
    }
    return $lead > 0 ? sa_ff_add_workdays($d, $lead) : $d;
}
/** Stock lead time in working days: only when the product has one set (meta _sa_stock_lead_days) and is on backorder / out of stock. */
function sa_ff_lead_days($product): int
{
    if (!$product instanceof WC_Product || $product->is_in_stock() && !$product->is_on_backorder()) {
        return 0;
    }
    $id = $product->get_parent_id() ?: $product->get_id();
    return max(0, (int) get_post_meta($id, '_sa_stock_lead_days', true));
}
function sa_ff_contents_lead(array $contents): int
{
    $l = 0;
    foreach ($contents as $i) {
        $l = max($l, sa_ff_lead_days($i['data'] ?? null));
    }
    return $l;
}

/* =========================================================
 * Fargo estimate tiers
 * ======================================================= */
/** @return array{0:int,1:int} working days after dispatch */
function sa_ff_fargo_tier(string $state, string $city): array
{
    $d = function_exists('sa_fargo_destination') ? sa_fargo_destination($state, $city) : null;
    if (!$d) {
        return [3, 5];
    }
    $card = sa_fargo_card();
    $remote = array_merge((array) ($card['remote_150_towns'] ?? []), (array) ($card['starred_towns'] ?? []));
    if ($d['nearest_for'] !== '' || in_array($d['town'], $remote, true)) {
        return [3, 5];
    }
    if ($d['county'] === 'Nairobi') {
        return [1, 2];
    }
    return [1, 3];
}
function sa_ff_range_label(DateTimeImmutable $a, DateTimeImmutable $b): string
{
    if ($a->format('Y-m-d') === $b->format('Y-m-d')) {
        return $a->format('D j M');
    }
    return $a->format('M') === $b->format('M') ? $a->format('D j') . ' – ' . $b->format('D j M') : $a->format('D j M') . ' – ' . $b->format('D j M');
}
function sa_ff_fargo_eta(string $state, string $city, int $lead = 0): string
{
    [$lo, $hi] = sa_ff_fargo_tier($state, $city);
    $dp = sa_ff_dispatch_day($lead);
    return 'Estimated arrival ' . sa_ff_range_label(sa_ff_add_workdays($dp, $lo), sa_ff_add_workdays($dp, $hi));
}

/* =========================================================
 * Store pickup slots (shop hours from the booking settings: Mon–Fri 08–17, Sat 08–13, Sun closed)
 * ======================================================= */
function sa_pk_capacity(): int
{
    return max(1, (int) get_option('sa_pickup_slot_capacity', 3));
}
function sa_pk_hours(DateTimeImmutable $d): array
{
    $w = (int) $d->format('w');
    if ($w === 0 || in_array($d->format('Y-m-d'), sa_ff_holidays(), true)) {
        return [];
    }
    return [8, $w === 6 ? 13 : 17];
}
/** Earliest ready time: 2 h after payment, rounded up to the hour, plus any stock lead time. */
function sa_pk_ready_from(int $lead = 0): DateTimeImmutable
{
    $t = sa_ff_now()->modify('+2 hours');
    $t = $t->setTime((int) $t->format('G') + ((int) $t->format('i') > 0 ? 1 : 0), 0);
    if ($lead > 0) {
        $t = sa_ff_add_workdays($t->setTime(8, 0), $lead);
    }
    return $t;
}
function sa_pk_taken(string $date): array
{
    if (!function_exists('wc_get_orders')) {
        return [];
    }
    $ids = wc_get_orders(['limit' => 200, 'return' => 'ids', 'status' => ['pending', 'processing', 'on-hold', 'ready-pickup'], 'meta_key' => '_sa_pickup_date', 'meta_value' => $date]);
    $c = [];
    foreach ($ids as $id) {
        $o = wc_get_order($id);
        if ($o && ($o->get_status() !== 'pending' || $o->get_date_created() && $o->get_date_created()->getTimestamp() > time() - HOUR_IN_SECONDS)) {
            $t = (string) $o->get_meta('_sa_pickup_time');
            $c[$t] = ($c[$t] ?? 0) + 1;
        }
    }
    return $c;
}
/** @return array<int,array{date:string,time:string,label:string,full:bool}> */
function sa_pk_slots(int $lead = 0, int $days = 6): array
{
    $from = sa_pk_ready_from($lead);
    $out = [];
    $d = $from->setTime(0, 0);
    for ($i = 0, $open = 0; $i < 14 && $open < $days; $i++, $d = $d->modify('+1 day')) {
        $h = sa_pk_hours($d);
        if (!$h) {
            continue;
        }
        $taken = sa_pk_taken($d->format('Y-m-d'));
        $any = false;
        for ($x = $h[0]; $x < $h[1]; $x++) {
            $start = $d->setTime($x, 0);
            if ($start < $from) {
                continue;
            }
            $t = sprintf('%02d:00', $x);
            $out[] = ['date' => $d->format('Y-m-d'), 'time' => $t, 'label' => $d->format('D j M') . ', ' . $t . '–' . sprintf('%02d:00', $x + 1), 'full' => ($taken[$t] ?? 0) >= sa_pk_capacity()];
            $any = true;
        }
        if ($any) {
            $open++;
        }
    }
    return $out;
}
function sa_pk_first_label(int $lead = 0): string
{
    foreach (sa_pk_slots($lead, 2) as $s) {
        if (!$s['full']) {
            return $s['label'];
        }
    }
    return '';
}
function sa_pk_address(): string
{
    return 'Ground Floor, Midax Plaza, Kangundo Rd, Njiru, Nairobi';
}
function sa_pk_map(): string
{
    return function_exists('sa_svc_map_url') ? sa_svc_map_url() : 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode('Midax Plaza, Kangundo Rd, Njiru, Nairobi');
}
function sa_pk_slot_text(WC_Order $o): string
{
    $d = (string) $o->get_meta('_sa_pickup_date');
    $t = (string) $o->get_meta('_sa_pickup_time');
    if ($d === '' || $t === '') {
        return '';
    }
    $dt = new DateTimeImmutable($d . ' ' . $t, wp_timezone());
    return $dt->format('D j M') . ', ' . $t . '–' . $dt->modify('+1 hour')->format('H:i');
}

/* =========================================================
 * Cart packages: fitted lines are taken out of the shipping package (no Fargo weight/fee).
 * One package only: WooCommerce drops Local pickup when a cart has several packages.
 * ======================================================= */
function sa_ff_is_fitted(array $item): bool
{
    return !empty($item['sa_fit']);
}
add_filter('woocommerce_cart_shipping_packages', static function (array $packages): array {
    $out = [];
    foreach ($packages as $p) {
        $ship = array_filter($p['contents'], static fn($i) => !sa_ff_is_fitted($i));
        if (count($ship) === count($p['contents'])) {
            $out[] = $p;
        } elseif ($ship) {
            $out[] = array_merge($p, ['contents' => $ship, 'contents_cost' => array_sum(array_map(static fn($i) => (float) ($i['line_total'] ?? 0), $ship)), 'sa_has_fitted' => 1]);
        }
    }
    return array_values($out);
}, 50);

/* Review row listing the fitted items. */
function sa_ff_fitted_row(): void
{
    if (!function_exists('WC') || !WC()->cart) {
        return;
    }
    $n = [];
    foreach (WC()->cart->get_cart() as $i) {
        if (sa_ff_is_fitted($i)) {
            $n[] = $i['data']->get_name() . ' ×' . (int) $i['quantity'];
        }
    }
    if ($n) {
        echo '<tr class="sa-ff-line"><td colspan="2"><div class="sa-ff-line__in"><span class="sa-ff-line__k">Fitted at our garage</span><span class="sa-ff-line__v">No shipping</span></div><small>' . esc_html(implode(', ', $n)) . '. Kept at our garage and fitted there.</small></td></tr>';
    }
}
add_action('woocommerce_review_order_after_shipping', 'sa_ff_fitted_row', 5);
add_action('woocommerce_cart_totals_after_shipping', 'sa_ff_fitted_row', 5);
add_action('woocommerce_review_order_before_order_total', static function (): void {
    if (function_exists('WC') && WC()->cart && !WC()->cart->needs_shipping()) {
        sa_ff_fitted_row(); // all fitted: no shipping rows are printed
    }
}, 1);

add_filter('woocommerce_package_rates', static function ($rates, $package) {
    $cc = strtoupper((string) ($package['destination']['country'] ?? ''));
    if ($cc !== 'KE') {
        return $rates;
    }
    $lead = sa_ff_contents_lead((array) ($package['contents'] ?? []));
    foreach ($rates as $rate) {
        if (!is_object($rate) || !method_exists($rate, 'set_label')) {
            continue;
        }
        $mid = $rate->get_method_id();
        if ($mid === 'sa_fargo' && !str_contains($rate->get_label(), 'Estimated')) {
            $rate->set_label($rate->get_label() . ' · ' . sa_ff_fargo_eta((string) ($package['destination']['state'] ?? ''), (string) ($package['destination']['city'] ?? ''), $lead));
        } elseif ($mid === 'local_pickup' && !str_contains($rate->get_label(), 'Estimated')) {
            $f = sa_pk_first_label($lead);
            if ($f !== '') {
                $rate->set_label($rate->get_label() . ' · Estimated ready from ' . $f);
            }
        }
    }
    return $rates;
}, 70, 2);

/* All items fitted → no shipping needed. */
add_filter('woocommerce_cart_needs_shipping', static function ($needs) {
    if (!$needs || !function_exists('WC') || !WC()->cart) {
        return $needs;
    }
    foreach (WC()->cart->get_cart() as $i) {
        if (!sa_ff_is_fitted($i) && $i['data'] instanceof WC_Product && $i['data']->needs_shipping()) {
            return true;
        }
    }
    return false;
}, 20);

/* =========================================================
 * Checkout: pickup slot picker (inside the order review, re-rendered on update)
 * ======================================================= */
function sa_pk_chosen(): bool
{
    if (!function_exists('WC') || !WC()->session || !WC()->cart || !WC()->cart->needs_shipping()) {
        return false;
    }
    foreach ((array) WC()->session->get('chosen_shipping_methods') as $m) {
        if (str_starts_with((string) $m, 'local_pickup')) {
            return true;
        }
    }
    return false;
}
function sa_pk_cart_lead(): int
{
    $c = array_filter(WC()->cart->get_cart(), static fn($i) => !sa_ff_is_fitted($i));
    return sa_ff_contents_lead($c);
}
add_action('woocommerce_checkout_update_order_review', static function ($post): void {
    parse_str((string) $post, $a);
    if (WC()->session && isset($a['sa_pickup_slot'])) {
        WC()->session->set('sa_pickup_slot', sanitize_text_field((string) $a['sa_pickup_slot']));
    }
});
add_action('woocommerce_review_order_after_shipping', static function (): void {
    if (!sa_pk_chosen()) {
        return;
    }
    $cur = WC()->session ? (string) WC()->session->get('sa_pickup_slot') : '';
    $slots = sa_pk_slots(sa_pk_cart_lead());
    echo '<tr class="sa-pk-row"><td colspan="2"><div class="sa-pk"><strong>Pickup time</strong>'
        . '<span class="sa-pk__addr">' . esc_html(sa_pk_address()) . ' · <a href="' . esc_url(sa_pk_map()) . '" target="_blank" rel="noopener">Map</a></span>'
        . '<label>Choose a 1-hour pickup window <select name="sa_pickup_slot" class="sa-pk__sel" onchange="jQuery(document.body).trigger(\'update_checkout\')"><option value="">Choose a time</option>';
    $day = '';
    foreach ($slots as $s) {
        if ($s['date'] !== $day) {
            echo ($day !== '' ? '</optgroup>' : '') . '<optgroup label="' . esc_attr(wp_date('l j M', strtotime($s['date']))) . '">';
            $day = $s['date'];
        }
        $v = $s['date'] . '|' . $s['time'];
        echo '<option value="' . esc_attr($v) . '"' . selected($cur, $v, false) . disabled($s['full'], true, false) . '>' . esc_html($s['label'] . ($s['full'] ? ' (full)' : '')) . '</option>';
    }
    echo ($day !== '' ? '</optgroup>' : '') . '</select></label>';
    if ($cur !== '' && str_contains($cur, '|')) {
        [$d, $t] = explode('|', $cur);
        echo '<span class="sa-pk__ready">Estimated ready by ' . esc_html(wp_date('D j M', strtotime($d)) . ', ' . $t) . '. We email you when it is ready.</span>';
    }
    echo '<small>Shop hours: Mon–Fri 08:00–17:00, Sat 08:00–13:00, closed Sundays and public holidays.</small></div></td></tr>';
});
add_action('woocommerce_after_checkout_validation', static function ($data, $errors): void {
    if (!sa_pk_chosen()) {
        return;
    }
    $v = sanitize_text_field(wp_unslash((string) ($_POST['sa_pickup_slot'] ?? '')));
    $ok = false;
    foreach (sa_pk_slots(sa_pk_cart_lead()) as $s) {
        if ($s['date'] . '|' . $s['time'] === $v) {
            $ok = !$s['full'];
            if ($s['full']) {
                $errors->add('sa_pk', 'That pickup window just filled up. Please choose another time.');
            }
        }
    }
    if (!$ok && !$errors->get_error_message('sa_pk')) {
        $errors->add('sa_pk', 'Please choose a pickup time for Store pickup.');
    }
}, 10, 2);
add_action('woocommerce_checkout_create_order', static function ($order): void {
    if (sa_pk_chosen()) {
        $v = sanitize_text_field(wp_unslash((string) ($_POST['sa_pickup_slot'] ?? '')));
        if (str_contains($v, '|')) {
            [$d, $t] = explode('|', $v, 2);
            $order->update_meta_data('_sa_pickup_date', $d);
            $order->update_meta_data('_sa_pickup_time', $t);
        }
    }
    // Delivery estimate snapshot for emails / thank-you.
    foreach ($order->get_shipping_methods() as $sm) {
        if (preg_match('/Estimated (arrival|ready from) [^·]+$/', (string) $sm->get_name(), $m)) {
            $order->update_meta_data('_sa_eta', trim($m[0]));
        }
    }
}, 20);
add_action('woocommerce_checkout_create_order_shipping_item', static function ($item, $pkg_key, $package): void {
    if (!empty($package['sa_has_fitted'])) {
        $item->add_meta_data('Note', 'Fitted items are not in this shipment (fitted at our garage)', true);
    }
}, 10, 3);

/* =========================================================
 * Show on thank-you / My Account / emails / admin
 * ======================================================= */
function sa_ff_order_block(WC_Order $o, bool $email = false): string
{
    $rows = [];
    $pk = sa_pk_slot_text($o);
    if ($pk !== '') {
        $rows[] = '<strong>Store pickup:</strong> ' . esc_html($pk) . ' at ' . esc_html(sa_pk_address()) . ' (<a href="' . esc_url(sa_pk_map()) . '">map</a>). We email you when it is ready.';
    }
    $eta = (string) $o->get_meta('_sa_eta');
    if ($eta !== '' && $pk === '') {
        $rows[] = '<strong>Delivery:</strong> ' . esc_html($eta) . ' (estimate, not a guaranteed date).';
    }
    $fit = [];
    foreach ($o->get_items() as $it) {
        if ($it->get_meta('_sa_fit')) {
            $fit[] = $it->get_name();
        }
    }
    if ($fit) {
        $rows[] = '<strong>Fitted at our garage (not shipped):</strong> ' . esc_html(implode(', ', $fit)) . '.';
    }
    if (!$rows) {
        return '';
    }
    return $email ? '<div style="margin:16px 0;font-size:14px;line-height:1.6">' . implode('<br>', $rows) . '</div>'
        : '<div class="sa-ff-block">' . implode('<br>', $rows) . '</div>';
}
add_action('woocommerce_order_details_after_order_table', static function ($o): void {
    if ($o instanceof WC_Order) {
        echo sa_ff_order_block($o); // phpcs:ignore
    }
}, 5);
add_action('woocommerce_email_after_order_table', static function ($o, $admin = false): void {
    if ($o instanceof WC_Order) {
        echo sa_ff_order_block($o, true); // phpcs:ignore
    }
}, 4, 2);
add_action('woocommerce_admin_order_data_after_shipping_address', static function ($o): void {
    $b = $o instanceof WC_Order ? sa_ff_order_block($o) : '';
    if ($b !== '') {
        echo '<div style="clear:both;padding-top:8px">' . $b . '</div>'; // phpcs:ignore
    }
});
/* Ready-for-pickup email: add the slot. */
add_filter('sa_ol_ready_pickup_extra', static function (string $h, WC_Order $o): string {
    $s = sa_pk_slot_text($o);
    return $s !== '' ? $h . '<p>Your pickup window: <strong>' . esc_html($s) . '</strong>.</p>' : $h;
}, 10, 2);

/* Reminder ~2 hours before the pickup window. */
add_action('init', static function (): void {
    if (!wp_next_scheduled('sa_pk_reminders')) {
        wp_schedule_event(time() + 300, 'hourly', 'sa_pk_reminders');
    }
});
add_action('sa_pk_reminders', static function (): void {
    $today = sa_ff_now()->format('Y-m-d');
    $ids = wc_get_orders(['limit' => 100, 'return' => 'ids', 'status' => ['processing', 'ready-pickup'], 'meta_key' => '_sa_pickup_date', 'meta_value' => $today]);
    foreach ($ids as $id) {
        $o = wc_get_order($id);
        if (!$o || $o->get_meta('_sa_pickup_reminded')) {
            continue;
        }
        $start = new DateTimeImmutable($today . ' ' . $o->get_meta('_sa_pickup_time'), wp_timezone());
        $mins = ($start->getTimestamp() - time()) / 60;
        if ($mins > 150 || $mins < 0) {
            continue;
        }
        $inner = '<p>Reminder: your pickup window for order #' . esc_html($o->get_order_number()) . ' is <strong>' . esc_html(sa_pk_slot_text($o)) . '</strong>.</p><p>' . esc_html(sa_pk_address()) . '</p><p>Please bring your order number and the phone number used at checkout.</p>';
        $html = function_exists('sa_mail_layout') ? sa_mail_layout('Pickup reminder', $inner, 'Get directions', sa_pk_map()) : $inner;
        wp_mail($o->get_billing_email(), 'Reminder: pick up order #' . $o->get_order_number() . ' today', $html, ['Content-Type: text/html; charset=UTF-8']);
        $o->update_meta_data('_sa_pickup_reminded', time());
        $o->add_order_note('Pickup reminder sent.');
        $o->save();
    }
});

/* =========================================================
 * Product page estimate (county remembered from the customer, default Nairobi)
 * ======================================================= */
add_action('woocommerce_single_product_summary', static function (): void {
    global $product;
    if (!$product instanceof WC_Product || !$product->needs_shipping() || !function_exists('WC') || !WC()->customer) {
        return;
    }
    $c = WC()->customer;
    $country = $c->get_shipping_country() ?: $c->get_billing_country();
    if ($country === '' && class_exists('WC_Geolocation')) {
        $country = (string) (WC_Geolocation::geolocate_ip()['country'] ?? '');
    }
    if ($country !== 'KE') {
        return;
    }
    $state = $c->get_shipping_state() ?: $c->get_billing_state() ?: 'KE30';
    $city = $c->get_shipping_city() ?: $c->get_billing_city() ?: 'Nairobi';
    $county = function_exists('sa_fargo_county_name') ? (sa_fargo_county_name($state) ?: 'Nairobi') : 'Nairobi';
    $lead = sa_ff_lead_days($product);
    $pk = sa_pk_first_label($lead);
    echo '<div class="sa-ff-pdp"><div><strong>Delivery to ' . esc_html($county) . '</strong><span>Fargo Courier: ' . esc_html(sa_ff_fargo_eta($state, $city, $lead)) . '</span>'
        . ($pk !== '' ? '<span>Store pickup: estimated ready from ' . esc_html($pk) . '</span>' : '') . '<small>Estimates, not guaranteed. Change your county at checkout.</small></div></div>';
}, 34);
