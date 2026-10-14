<?php
/**
 * Fitting at checkout: "Book installation" adds the part to the cart with a fitting attached.
 * The customer pays only for the part; the booking fee is KES 0 (waived, part bought from us).
 * Drop-off / pickup, slot, car and pickup address are chosen at checkout. On payment the
 * service booking is created and linked to the fitted order lines (fitting-waiver logic).
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/* ---------- Helpers ---------- */
function sa_fit_cart_items(): array
{
    if (!function_exists('WC') || !WC()->cart) {
        return [];
    }
    return array_filter(WC()->cart->get_cart(), static fn($i) => !empty($i['sa_fit']));
}

/** Main service + extra minutes for the parts sharing one visit. */
function sa_fit_services(array $pids): array
{
    $svcs = [];
    foreach ($pids as $pid) {
        $svcs[] = sa_svc_for_product((int) $pid);
    }
    $svcs = array_values(array_unique($svcs));
    $main = $svcs[0] ?? 'diagnostics';
    $addons = array_slice($svcs, 1);
    $extra = 0;
    foreach ($addons as $a) {
        $extra += (int) (sa_svc_catalog()[$a]['duration'] ?? 0);
    }
    return ['main' => $main, 'addons' => $addons, 'extra' => $extra];
}

function sa_fit_cart_services(): array
{
    return sa_fit_services(array_map(static fn($i) => (int) $i['product_id'], sa_fit_cart_items()));
}

/** Store pickup of the part: +1 day, otherwise delivery +3 days (from today, i.e. paid now). */
function sa_fit_earliest(?bool $pickup = null): string
{
    if ($pickup === null) {
        $pickup = false;
        foreach ((array) (WC()->session ? WC()->session->get('chosen_shipping_methods') : []) as $m) {
            if (str_contains((string) $m, 'pickup')) {
                $pickup = true;
            }
        }
    }
    return (new DateTimeImmutable('now', wp_timezone()))->modify($pickup ? '+1 day' : '+3 days')->format('Y-m-d');
}

/** Labour line: only a price that exists in the service catalogue, otherwise "quoted at the garage". */
function sa_fit_labour_label(string $svc): string
{
    $s = sa_svc_catalog()[$svc] ?? null;
    $p = $s ? sa_svc_price_label($s, 'KE') : '';
    return $p !== '' && str_contains($p, 'KES') ? ($s['name'] . ': ' . $p . ', final quote at the garage') : 'Quoted at the garage';
}

/* ---------- Add to cart with fitting ---------- */
function sa_fit_add_url(int $pid): string
{
    return add_query_arg('sa_fit_add', $pid, home_url('/'));
}

add_action('wp_loaded', static function (): void {
    if (!empty($_GET['sa_fit_add']) && function_exists('WC') && WC()->cart) {
        $pid = absint($_GET['sa_fit_add']);
        $p = wc_get_product($pid);
        if (!$p || !$p->is_purchasable() || !$p->is_in_stock() || $p->is_type('variable')) {
            wp_safe_redirect($p ? $p->get_permalink() : home_url('/'));
            exit;
        }
        $have = false;
        foreach (sa_fit_cart_items() as $i) {
            if ((int) $i['product_id'] === $pid) {
                $have = true;
            }
        }
        if (!$have) {
            WC()->cart->add_to_cart($pid, 1, 0, [], ['sa_fit' => 1]);
        }
        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }
    if (!empty($_GET['sa_fit_remove']) && function_exists('WC') && WC()->cart) {
        $key = sanitize_text_field(wp_unslash((string) $_GET['sa_fit_remove']));
        $c = WC()->cart->get_cart_contents();
        if (isset($c[$key])) {
            unset($c[$key]['sa_fit']);
            WC()->cart->set_cart_contents($c);
            WC()->cart->set_session();
            wc_add_notice('Fitting removed. The part stays in your cart.', 'notice');
        }
        wp_safe_redirect(wp_get_referer() ?: wc_get_cart_url());
        exit;
    }
}, 30);

add_filter('woocommerce_get_cart_item_from_session', static function ($item, $values) {
    if (!empty($values['sa_fit'])) {
        $item['sa_fit'] = 1;
    }
    return $item;
}, 10, 2);

/* Cart / checkout sub-line under the part. */
add_filter('woocommerce_get_item_data', static function (array $data, array $item): array {
    if (empty($item['sa_fit'])) {
        return $data;
    }
    $v = WC()->session ? (array) WC()->session->get('sa_fit_visit') : [];
    $txt = !empty($v['date']) && !empty($v['time'])
        ? ($v['mode'] === 'pickup' ? 'pickup' : 'drop-off') . ', ' . wp_date('D j M', strtotime($v['date'])) . ' ' . $v['time']
        : 'choose drop-off or pickup and a time at checkout';
    $rm = isset($item['key']) ? ' <a class="sa-fit-rm" href="' . esc_url(add_query_arg('sa_fit_remove', $item['key'], home_url('/'))) . '">Remove fitting</a>' : '';
    $data[] = ['key' => 'Fitting booked', 'value' => $txt, 'display' => esc_html($txt) . $rm];
    return $data;
}, 20, 2);

/* ---------- Checkout fields ---------- */
add_action('woocommerce_checkout_after_customer_details', static function (): void {
    $items = sa_fit_cart_items();
    if (!$items) {
        return;
    }
    $s = sa_fit_cart_services();
    $v = WC()->session ? (array) WC()->session->get('sa_fit_visit') : [];
    $uid = get_current_user_id();
    $veh = $uid ? (sa_svc_vehicles($uid)[0] ?? []) : [];
    $g = static fn(string $k, string $d = ''): string => (string) ($v[$k] ?? ($veh[$k] ?? $d));
    $mode = $g('mode', 'dropoff');
    $names = implode(', ', array_map(static fn($i) => $i['data']->get_name(), $items));
    $years = range((int) gmdate('Y') + 1, 1980);
    ?>
    <section class="sa-fitco" data-sa-fitco data-service="<?php echo esc_attr($s['main']); ?>" data-extra="<?php echo (int) $s['extra']; ?>" data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
      data-e1="<?php echo esc_attr(sa_fit_earliest(true)); ?>" data-e3="<?php echo esc_attr(sa_fit_earliest(false)); ?>">
      <h3>Fitting appointment</h3>
      <p class="sa-fitco__lead">Fitting for: <strong><?php echo esc_html($names); ?></strong>. Njiru, Nairobi garage. No booking fee because the part is bought from us.</p>
      <fieldset class="sa-fitco__modes"><legend>Handover</legend>
        <label><input type="radio" name="sa_fit_mode" value="dropoff" <?php checked($mode !== 'pickup'); ?>> Drop off my car at the Njiru garage</label>
        <label><input type="radio" name="sa_fit_mode" value="pickup" <?php checked($mode === 'pickup'); ?>> Pick up my car (Supreme Autoparts vehicle pickup)</label>
      </fieldset>
      <div class="sa-fitco__pk" data-fit-pk <?php echo $mode === 'pickup' ? '' : 'hidden'; ?>>
        <p class="sa-fitco__note">Nairobi area. The pickup fee depends on distance and is confirmed with you on a call before pickup. Nothing is charged for it now.</p>
        <div class="sa-fitco__row">
          <label>Street / road* <input name="sa_fit_pk_street" value="<?php echo esc_attr($g('pk_street')); ?>"></label>
          <label>Building / estate, house no.* <input name="sa_fit_pk_building" value="<?php echo esc_attr($g('pk_building')); ?>"></label>
          <label>Area* <input name="sa_fit_pk_area" value="<?php echo esc_attr($g('pk_area')); ?>" placeholder="e.g. Njiru"></label>
          <label>City / town* <input name="sa_fit_pk_city" value="<?php echo esc_attr($g('pk_city', 'Nairobi')); ?>"></label>
          <label>Contact phone at pickup* <input name="sa_fit_pk_phone" type="tel" value="<?php echo esc_attr($g('pk_phone')); ?>"></label>
          <label>Landmark / directions <input name="sa_fit_pk_landmark" value="<?php echo esc_attr($g('pk_landmark')); ?>"></label>
        </div>
      </div>
      <div class="sa-fitco__row">
        <label>Preferred date* <input type="date" name="sa_fit_date" value="<?php echo esc_attr($g('date')); ?>" data-fit-date></label>
        <label>Time slot* <select name="sa_fit_time" data-fit-time data-cur="<?php echo esc_attr($g('time')); ?>"><option value="">Choose a date first</option></select></label>
      </div>
      <p class="sa-fitco__note" data-fit-earliest></p>
      <div class="sa-fitco__row">
        <label>Make* <input name="sa_fit_make" value="<?php echo esc_attr($g('make')); ?>" placeholder="Toyota"></label>
        <label>Model* <input name="sa_fit_model" value="<?php echo esc_attr($g('model')); ?>" placeholder="Fielder"></label>
        <label>Year <select name="sa_fit_year"><option value="">—</option><?php foreach ($years as $y) : ?><option <?php selected($g('year'), (string) $y); ?>><?php echo (int) $y; ?></option><?php endforeach; ?></select></label>
        <label>Registration <input name="sa_fit_reg" value="<?php echo esc_attr($g('reg')); ?>" placeholder="KDA 123A"></label>
      </div>
      <label class="sa-fitco__terms"><input type="checkbox" name="sa_fit_terms" value="1"> I agree to the <a href="<?php echo esc_url(home_url('/booking-terms/')); ?>" target="_blank">Booking Terms &amp; Policies</a></label>
    </section>
    <?php
});

add_action('wp_enqueue_scripts', static function (): void {
    if (function_exists('is_checkout') && is_checkout() && sa_fit_cart_items()) {
        wp_enqueue_script('sa-fit-checkout', SA_CORE_URL . 'assets/fitting-checkout.js', ['jquery'], SA_CORE_VERSION, true);
    }
});

/* Remember choices in the session (cart sub-line + prefill). */
function sa_fit_read_post(array $src): array
{
    $g = static fn(string $k): string => sanitize_text_field(wp_unslash((string) ($src['sa_fit_' . $k] ?? '')));
    $out = [];
    foreach (['mode', 'date', 'time', 'make', 'model', 'year', 'reg', 'pk_street', 'pk_building', 'pk_area', 'pk_city', 'pk_phone', 'pk_landmark'] as $k) {
        $out[$k] = $g($k);
    }
    $out['mode'] = $out['mode'] === 'pickup' ? 'pickup' : 'dropoff';
    $out['terms'] = !empty($src['sa_fit_terms']) ? 1 : 0;
    return $out;
}
add_action('woocommerce_checkout_update_order_review', static function ($post_data): void {
    parse_str((string) $post_data, $arr);
    if (sa_fit_cart_items() && WC()->session) {
        WC()->session->set('sa_fit_visit', sa_fit_read_post($arr));
    }
});

/* Order review rows. */
add_action('woocommerce_review_order_before_order_total', static function (): void {
    if (!sa_fit_cart_items()) {
        return;
    }
    $s = sa_fit_cart_services();
    echo '<tr class="sa-fit-row"><th>Fitting booking fee</th><td>KES 0 <small>(waived, part bought from us)</small></td></tr>'
        . '<tr class="sa-fit-row"><th>Fitting labour</th><td><small>' . esc_html(sa_fit_labour_label($s['main'])) . '</small></td></tr>';
});

/* Validation. */
add_action('woocommerce_after_checkout_validation', static function ($data, $errors): void {
    if (!sa_fit_cart_items()) {
        return;
    }
    $v = sa_fit_read_post($_POST);
    $s = sa_fit_cart_services();
    if ($v['make'] === '' || $v['model'] === '') {
        $errors->add('sa_fit', 'Fitting: please enter your car make and model.');
    }
    $earliest = sa_fit_earliest();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v['date']) || $v['date'] < $earliest) {
        $errors->add('sa_fit', 'Fitting: please choose a date on or after ' . wp_date('D j M', strtotime($earliest)) . ' (after your part arrives).');
    } elseif (!in_array($v['time'], sa_svc_available_slots($v['date'], 'nairobi', $s['main'], $s['extra']), true)) {
        $errors->add('sa_fit', 'Fitting: that time slot is not available. Please pick another.');
    }
    if ($v['mode'] === 'pickup') {
        foreach (['pk_street' => 'street', 'pk_building' => 'building / house', 'pk_area' => 'area', 'pk_city' => 'city', 'pk_phone' => 'contact phone'] as $k => $l) {
            if ($v[$k] === '') {
                $errors->add('sa_fit', 'Fitting pickup: please enter the ' . $l . '.');
            }
        }
    }
    if (!$v['terms']) {
        $errors->add('sa_fit', 'Fitting: please accept the Booking Terms.');
    }
}, 10, 2);

/* Save on the order + mark lines. */
add_action('woocommerce_checkout_create_order', static function ($order): void {
    if (sa_fit_cart_items()) {
        $v = sa_fit_read_post($_POST);
        $order->update_meta_data('_sa_fit_visit', $v + ['uid' => get_current_user_id()]);
    }
});
add_action('woocommerce_checkout_create_order_line_item', static function ($item, $key, $values): void {
    if (!empty($values['sa_fit'])) {
        $item->add_meta_data('_sa_fit', 1, true);
        $item->add_meta_data('Fitting', 'Booked with this order (no booking fee)', true);
    }
}, 10, 3);

/* ---------- Payment success: create the booking ---------- */
function sa_fit_create_booking($order_id): int
{
    $o = wc_get_order($order_id);
    if (!$o instanceof WC_Order || !in_array($o->get_status(), ['processing', 'completed'], true)) {
        return 0;
    }
    $v = $o->get_meta('_sa_fit_visit');
    if (!is_array($v) || (int) $o->get_meta('_sa_fit_booking')) {
        return (int) $o->get_meta('_sa_fit_booking');
    }
    $lines = [];
    foreach ($o->get_items() as $iid => $it) {
        if ($it->get_meta('_sa_fit')) {
            $lines[(int) $iid] = $it;
        }
    }
    if (!$lines) {
        return 0;
    }
    $o->update_meta_data('_sa_fit_booking', -1); // lock against double hooks
    $o->save();
    $s = sa_fit_services(array_map(static fn($it) => (int) $it->get_product_id(), $lines));
    $cat = sa_svc_catalog();
    $names = implode(', ', array_map(static fn($it) => $it->get_name(), $lines));
    $clash = !in_array($v['time'], sa_svc_available_slots($v['date'], 'nairobi', $s['main'], $s['extra']), true);
    $ref = sa_svc_new_ref();
    $name = trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name());
    $id = wp_insert_post(['post_type' => 'sa_booking', 'post_status' => 'publish', 'post_title' => $ref . ' ' . ($cat[$s['main']]['name'] ?? 'Fitting') . ' — ' . $name], true);
    if (is_wp_error($id)) {
        $o->update_meta_data('_sa_fit_booking', 0);
        $o->add_order_note('Could not create the fitting booking: ' . $id->get_error_message());
        $o->save();
        return 0;
    }
    $pickup = $v['mode'] === 'pickup' ? implode(', ', array_filter([$v['pk_building'], $v['pk_street'], $v['pk_area'], $v['pk_city']])) . ($v['pk_landmark'] !== '' ? ' | Landmark: ' . $v['pk_landmark'] : '') . ' | Pickup phone: ' . $v['pk_phone'] : '';
    $meta = [
        'ref' => $ref, 'service' => $s['main'], 'option' => '', 'date' => $v['date'], 'time' => $v['time'], 'location' => 'nairobi', 'region' => 'KE', 'status' => 'confirmed',
        'name' => $name, 'email' => $o->get_billing_email(), 'phone' => $o->get_billing_phone(), 'make' => $v['make'], 'model' => $v['model'], 'year' => $v['year'], 'reg' => strtoupper($v['reg']),
        'notes' => trim('Install part(s) from order #' . $o->get_order_number() . ': ' . $names . '.' . ($clash ? ' SLOT CLASH: the chosen slot filled up before payment, please call the customer to confirm a time.' : '')),
        'estimate' => sa_fit_labour_label($s['main']) . ($s['addons'] ? ' + ' . implode(', ', array_map(static fn($x) => $cat[$x]['name'] ?? $x, $s['addons'])) : ''),
        'addons' => implode(',', $s['addons']), 'part' => $names, 'mode' => $v['mode'], 'pickup' => $pickup, 'fee' => 'waived', 'fee_kes' => '0',
        'user' => (string) ((int) ($v['uid'] ?? 0) ?: $o->get_customer_id()), 'key' => wp_generate_password(20, false, false), 'from_order' => (string) $o->get_id(),
    ];
    foreach ($meta as $k => $val) {
        update_post_meta((int) $id, '_sa_bk_' . $k, $val);
    }
    $first = array_key_first($lines);
    update_post_meta((int) $id, '_sa_bk_waiver', ['order' => $o->get_id(), 'number' => $o->get_order_number(), 'item' => $first, 'part' => $names, 'reason' => 'Part bought from us (order #' . $o->get_order_number() . ')', 'by' => 'auto', 't' => time()]);
    $map = array_filter((array) ($o->get_meta('_sa_fw_bookings') ?: []));
    foreach (array_keys($lines) as $iid) {
        $map[$iid] = (int) $id;
    }
    $o->update_meta_data('_sa_fw_bookings', $map);
    $o->update_meta_data('_sa_fit_booking', (int) $id);
    $o->add_order_note('Fitting booking ' . $ref . ' created (' . $v['date'] . ' ' . $v['time'] . ', ' . $v['mode'] . '), booking fee waived.' . ($clash ? ' Slot clash: call the customer.' : ''));
    $o->save();
    $uid = (int) $meta['user'];
    if ($uid && function_exists('sa_svc_save_vehicle')) {
        sa_svc_save_vehicle($uid, ['make' => $meta['make'], 'model' => $meta['model'], 'year' => $meta['year'], 'reg' => $meta['reg']]);
    }
    if (function_exists('sa_bk_invoice_email')) {
        sa_bk_invoice_email((int) $id); // Separate booking confirmation email (KES 0 invoice).
    }
    sa_svc_gcal_sync((int) $id);
    return (int) $id;
}
add_action('woocommerce_payment_complete', 'sa_fit_create_booking', 5);
add_action('woocommerce_order_status_processing', 'sa_fit_create_booking', 5);
add_action('woocommerce_order_status_completed', 'sa_fit_create_booking', 5);

/* Failed / cancelled / refunded: release the booking (runs before the waiver revoke). */
function sa_fit_release($order_id): void
{
    $o = wc_get_order($order_id);
    $bid = $o ? (int) $o->get_meta('_sa_fit_booking') : 0;
    if ($bid <= 0 || get_post_type($bid) !== 'sa_booking' || !in_array(get_post_meta($bid, '_sa_bk_status', true), ['pending', 'confirmed'], true)) {
        return;
    }
    update_post_meta($bid, '_sa_bk_status', 'cancelled');
    update_post_meta($bid, '_sa_bk_notes', trim('Released: order #' . $o->get_order_number() . ' ' . $o->get_status() . '. ' . (string) get_post_meta($bid, '_sa_bk_notes', true)));
    $o->add_order_note('Fitting booking ' . get_post_meta($bid, '_sa_bk_ref', true) . ' released (order ' . $o->get_status() . ').');
    $o->save();
    sa_svc_send_emails($bid, 'cancelled');
    sa_svc_gcal_sync($bid);
}
foreach (['cancelled', 'failed', 'refunded'] as $st) {
    add_action('woocommerce_order_status_' . $st, 'sa_fit_release', 10);
}

/* Order email + thank-you: one line pointing to the separate booking email. */
add_action('woocommerce_email_after_order_table', static function ($order, $sent_to_admin = false): void {
    if (!$order instanceof WC_Order || !($bid = (int) $order->get_meta('_sa_fit_booking')) || $bid < 0) {
        return;
    }
    echo '<p style="margin:16px 0;font-size:14px">Fitting booked (' . esc_html((string) get_post_meta($bid, '_sa_bk_ref', true)) . '), see the separate booking email.</p>';
}, 5, 2);
add_action('woocommerce_thankyou', static function ($order_id): void {
    $o = wc_get_order($order_id);
    $bid = $o ? (int) $o->get_meta('_sa_fit_booking') : 0;
    if ($bid > 0) {
        $b = sa_svc_booking_data($bid);
        echo '<div class="sa-fit-block" style="margin:18px 0"><strong>Fitting booked: ' . esc_html($b['ref']) . '</strong><span>' . esc_html(wp_date('D j M Y', strtotime($b['date'])) . ', ' . $b['time'] . ' · ' . ($b['mode'] === 'pickup' ? 'we pick up your car' : 'drop-off at Njiru')) . '. Booking fee KES 0. We have emailed the booking separately.</span>'
            . '<a href="' . esc_url(add_query_arg(['b' => $bid, 'k' => $b['key']], home_url('/book-service/confirmed/'))) . '">View booking</a></div>';
    } elseif ($o && is_array($o->get_meta('_sa_fit_visit'))) {
        echo '<div class="sa-fit-block" style="margin:18px 0"><strong>Fitting requested</strong><span>Your fitting is booked as soon as the payment is confirmed. You will get a separate booking email.</span></div>';
    }
}, 8);

/* Admin order screen: link to the booking. */
add_action('woocommerce_admin_order_data_after_order_details', static function ($o): void {
    $bid = (int) $o->get_meta('_sa_fit_booking');
    if ($bid > 0) {
        echo '<p class="form-field form-field-wide"><strong>Fitting booking:</strong> ' . esc_html((string) get_post_meta($bid, '_sa_bk_ref', true)) . ' — ' . esc_html((string) get_post_meta($bid, '_sa_bk_date', true) . ' ' . get_post_meta($bid, '_sa_bk_time', true)) . ' (<a href="' . esc_url(admin_url('admin.php?page=sa-super-bookings&q=' . rawurlencode((string) get_post_meta($bid, '_sa_bk_ref', true)))) . '">open</a>)</p>';
    }
});
