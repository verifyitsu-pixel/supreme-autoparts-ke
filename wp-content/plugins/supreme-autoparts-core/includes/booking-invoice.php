<?php
/**
 * Booking invoices: generated when a booking is created (UNPAID + Pay now link),
 * re-issued as PAID when the booking-fee order completes (acts as confirmation).
 * Viewable / downloadable from My Bookings, the booking page and Super Admin → Bookings.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

function sa_bk_invoice_no(array $b): string
{
    return 'INV-' . ($b['ref'] !== '' ? $b['ref'] : (string) $b['id']);
}

function sa_bk_invoice_url(int $id, bool $download = false): string
{
    $b = sa_svc_booking_data($id);
    $args = ['sa_bk_invoice' => $id, 'k' => $b['key']];
    if ($download) {
        $args['dl'] = 1;
    }
    return add_query_arg($args, home_url('/'));
}

/** Shared receipt-design data for a booking. */
/** Fee text in the customer's currency: US bookings see USD (what they are charged), Kenya bookings see KES. */
function sa_bk_fee_text(array $b, int $kes): string
{
    $is_us = (($b['region'] ?? '') === 'US') || (($b['location'] ?? '') === 'usa');
    if ($is_us && function_exists('sa_svc_fee_usd')) {
        $usd = sa_svc_fee_usd();
        $base = (int) sa_svc_setting('fee_kes');
        if ($usd !== null && $base > 0) {
            return 'US$' . number_format($usd * ($kes / $base), 2);
        }
    }
    return 'KES ' . number_format($kes);
}

function sa_bk_rcpt_data(int $id): array
{
    $b = sa_svc_booking_data($id);
    $svc = sa_svc_catalog()[$b['service']] ?? ['name' => $b['service']];
    $waived = $b['fee'] === 'waived';
    $paid = $waived || in_array($b['fee'], ['paid', 'applied'], true);
    $kes = $waived ? 0 : (int) ($b['fee_kes'] ?: sa_svc_setting('fee_kes'));
    $wlabel = $waived && function_exists('sa_fw_label') ? (string) preg_replace('/^waived \((.*)\)$/', '$1', (string) sa_fw_label($id)) : '';
    $loc = sa_svc_locations()[$b['location']]['label'] ?? $b['location'];
    $fmt = static fn(int $v): string => sa_bk_fee_text($b, $v);
    $meta = array_values(array_filter([
        'Booking ' . $b['ref'],
        trim($b['year'] . ' ' . $b['make'] . ' ' . $b['model'] . ' ' . $b['reg']),
        ($b['date'] ? date_i18n('D j M Y', strtotime($b['date'])) : '') . ($b['time'] ? ', ' . $b['time'] : '') . ' (' . sa_svc_duration_label($b['service']) . ')',
        $loc,
        $b['mode'] === 'pickup' ? 'We collect from: ' . $b['pickup'] : 'Drop-off at the garage',
    ]));
    $items = [[
        'thumb' => '', 'no_thumb' => true, 'name' => $svc['name'] . ($b['option'] ? ' · ' . $b['option'] : '') . ' booking fee',
        'meta' => array_merge($meta, [$waived ? 'Fee waived: ' . $wlabel : 'Deducted from your final service bill']),
        'qty' => '1', 'unit' => $fmt($kes), 'total' => $fmt($kes),
    ]];
    $totals = [['Booking fee', $fmt($kes), false]];
    $totals[] = ['Estimated service total (paid at the garage)', esc_html($b['estimate'] ?: 'Quote on request'), false];
    $totals[] = [$paid ? 'Paid now' : 'Due now', $fmt($kes), true];
    $pay = [['Status', $waived ? 'No fee, confirmed' : ($paid ? 'Paid' : 'Unpaid')]];
    if ($b['fee_order']) {
        $pay[] = ['Payment reference', '#' . (int) $b['fee_order']];
    }
    return [
        'doc' => $paid ? 'Receipt' : 'Invoice', 'title' => $paid ? 'Booking receipt' : 'Booking invoice',
        'number' => sa_bk_invoice_no($b), 'file_no' => sa_bk_invoice_no($b),
        'date' => wp_date('j M Y', (int) $b['created']), 'paid' => $paid,
        'seller' => function_exists('sa_rcpt_seller') ? sa_rcpt_seller('KE') : [],
        'bill' => array_filter([$b['name'], $b['email'], $b['phone']]), 'ship' => [], 'seller_lbl' => 'Provided by', 'bill_lbl' => 'Customer', 'item_lbl' => 'Service',
        'items' => $items, 'totals' => $totals, 'pay' => $pay, 'note' => '',
        'extra_html' => '<div style="font-size:12px;color:#6B6B6B">This is an estimate; you approve the final quote before any work starts. Fee refunds follow our <a href="' . esc_url(home_url('/booking-terms/')) . '" style="color:#111111">Booking Terms</a>.</div>',
        'view_url' => sa_bk_invoice_url($id),
        'pay_url' => (!$paid && $b['status'] === 'pending' && $kes > 0) ? add_query_arg(['b' => $id, 'k' => $b['key']], home_url('/book-service/pay/')) : '',
        'greet' => '', 'lead' => '',
    ];
}

function sa_bk_invoice_html(int $id, bool $embed_logo = false): string
{
    return sa_rcpt_render(sa_bk_rcpt_data($id), $embed_logo ? 'pdf' : 'page', $embed_logo ? [] : [
        ['label' => 'Print', 'print' => true, 'primary' => true],
        ['label' => 'Download PDF', 'url' => sa_bk_invoice_url($id, true)],
    ]);
}

function sa_bk_invoice_email(int $id, string $kind = ''): void
{
    $b = sa_svc_booking_data($id);
    if (!is_email($b['email'])) {
        return;
    }
    $waived = $b['fee'] === 'waived';
    $paid = $waived || in_array($b['fee'], ['paid', 'applied'], true);
    $kes = number_format((int) ($b['fee_kes'] ?: sa_svc_setting('fee_kes')));
    $feelbl = sa_bk_fee_text($b, (int) ($b['fee_kes'] ?: sa_svc_setting('fee_kes')));
    $pay = add_query_arg(['b' => $id, 'k' => $b['key']], home_url('/book-service/pay/'));
    $release = wp_date('g:i A, j M', (int) $b['created'] + 3 * HOUR_IN_SECONDS) . ' EAT';
    if ($waived) {
        $subject = 'Booking confirmed — no booking fee (' . $b['ref'] . ')';
        $lead = 'Your booking is confirmed. Booking fee: ' . sa_fw_label($id) . '. There is nothing to pay now; labour is quoted as normal before any work starts.';
    } elseif ($kind === 'revoked') {
        $subject = 'Booking fee now due for ' . $b['ref'];
        $w = function_exists('sa_fw_waiver') ? sa_fw_waiver($id) : [];
        $lead = 'The booking fee waiver on this booking has ended because ' . ($w['revoked_reason'] ?? 'the linked order changed') . '. To keep your appointment, please pay the ' . $feelbl . ' booking fee within 24 hours. It is deducted from your final bill.';
    } elseif ($paid) {
        $subject = 'Booking confirmed — paid invoice ' . sa_bk_invoice_no($b) . ' (' . $b['ref'] . ')';
        $lead = 'Thank you, your booking fee is paid and your booking is confirmed. Your paid invoice is below.';
    } elseif ($kind === 'nudge1') {
        $subject = 'Complete your payment to secure booking ' . $b['ref'];
        $lead = 'Your slot is on hold, but the ' . $feelbl . ' booking fee is still unpaid. Pay now to secure it. Unpaid bookings are released at ' . $release . '.';
    } elseif ($kind === 'nudge2') {
        $subject = 'Final reminder: booking ' . $b['ref'] . ' is released at ' . $release;
        $lead = 'This is your last reminder. If the ' . $feelbl . ' booking fee is not paid, your slot will be released at ' . $release . ' and offered to other customers.';
    } else {
        $subject = 'Booking received — pay ' . $feelbl . ' to secure your slot (' . $b['ref'] . ')';
        $lead = 'Thanks for booking. Pay the ' . $feelbl . ' booking fee to secure your slot. It is deducted from your final bill. Unpaid bookings are released after 3 hours (at ' . $release . ').';
    }
    $btn = $paid || $b['status'] !== 'pending' ? '' : '<p style="text-align:center;margin:18px 0"><a href="' . esc_url($pay) . '" style="display:inline-block;background:#F5A623;color:#111;text-decoration:none;font-weight:800;font-size:16px;padding:14px 30px;border-radius:10px">Pay booking fee — ' . $feelbl . '</a></p>';
    if ($waived) {
        $mng = add_query_arg(['b' => $id, 'k' => $b['key']], home_url('/book-service/confirmed/'));
        $btn .= '<p style="font-size:14px">Fitting job: ' . esc_html(($pt = (string) get_post_meta($id, '_sa_bk_part', true)) !== '' ? $pt : (sa_svc_catalog()[$b['service']]['name'] ?? $b['service'])) . '<br>'
            . ($b['mode'] === 'pickup' ? 'We pick up your car from: ' . esc_html($b['pickup']) . '. The pickup fee is confirmed with you on a call.' : 'Drop-off at our Njiru garage, Midax Plaza, Kangundo Rd.') . '</p>'
            . '<p style="font-size:14px"><a href="' . esc_url($mng) . '">Manage booking</a> · <a href="' . esc_url(add_query_arg(['service' => $b['service'], 'reschedule' => $id, 'rk' => $b['key']], home_url('/book-service/'))) . '">Reschedule</a> · <a href="' . esc_url($mng) . '#cancel">Cancel</a></p>';
    }
    $d = sa_bk_rcpt_data($id);
    $d['greet'] = sa_email_greeting(sa_bk_greet_src($b), (string) $b['name']);
    $d['lead'] = $lead;
    $d['pay_url'] = '';
    $d['extra_html'] = ($btn !== '' ? str_replace('border-radius:10px', 'border-radius:0', $btn) : '') . $d['extra_html'];
    $body = sa_rcpt_render($d, 'email');
    $pdf = sa_rcpt_pdf_tempfile($d);
    wp_mail($b['email'], $subject, $body, ['Content-Type: text/html; charset=UTF-8'], $pdf !== '' ? [$pdf] : []);
    sa_rcpt_cleanup($pdf);
    update_post_meta($id, '_sa_bk_invoice_' . ($paid ? 'paid' : ($kind !== '' ? $kind : 'unpaid')) . '_sent', time());
}

/* Route: /?sa_bk_invoice=ID&k=KEY[&dl=1] — owner key, booking user, or shop manager. */
add_action('template_redirect', static function (): void {
    if (empty($_GET['sa_bk_invoice'])) {
        return;
    }
    $id = (int) $_GET['sa_bk_invoice'];
    if (get_post_type($id) !== 'sa_booking') {
        wp_die('Invoice not found', 404);
    }
    $b = sa_svc_booking_data($id);
    $k = sanitize_text_field(wp_unslash((string) ($_GET['k'] ?? '')));
    $ok = current_user_can('manage_woocommerce') || ($b['user'] && $b['user'] === get_current_user_id()) || ($b['key'] !== '' && hash_equals($b['key'], $k));
    if (!$ok) {
        wp_die('Not allowed', 403);
    }
    if (!empty($_GET['dl'])) {
        sa_rcpt_send_pdf_download(sa_bk_rcpt_data($id));
    }
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    echo sa_bk_invoice_html($id); // phpcs:ignore
    exit;
});

/* Issue the PAID invoice once the booking-fee order completes (runs after sa_svc_order_paid). */
function sa_bk_invoice_on_paid(int $order_id): void
{
    $o = wc_get_order($order_id);
    if (!$o) {
        return;
    }
    foreach ($o->get_items() as $it) {
        $bid = (int) $it->get_meta('_sa_booking');
        if ($bid && get_post_type($bid) === 'sa_booking' && get_post_meta($bid, '_sa_bk_fee', true) === 'paid' && !get_post_meta($bid, '_sa_bk_invoice_paid_sent', true)) {
            sa_bk_invoice_email($bid);
        }
    }
}
add_action('woocommerce_payment_complete', 'sa_bk_invoice_on_paid', 20);
add_action('woocommerce_order_status_processing', 'sa_bk_invoice_on_paid', 20);
add_action('woocommerce_order_status_completed', 'sa_bk_invoice_on_paid', 20);

/* Payment nudges: ~30 min after booking, and a final one ~25 min before the 3-hour release. Runs every 5 minutes. */
add_filter('cron_schedules', static function (array $s): array {
    $s['sa_five_min'] = ['interval' => 300, 'display' => 'Every 5 minutes'];
    return $s;
});
add_action('init', static function (): void {
    if (!wp_next_scheduled('sa_bk_pay_nudges')) {
        wp_schedule_event(time() + 120, 'sa_five_min', 'sa_bk_pay_nudges');
    }
});
function sa_bk_run_pay_nudges(): array
{
    $sent = [];
    $ids = get_posts(['post_type' => 'sa_booking', 'numberposts' => 100, 'fields' => 'ids', 'date_query' => [['after' => '4 hours ago']],
        'meta_query' => [['key' => '_sa_bk_fee', 'value' => 'unpaid'], ['key' => '_sa_bk_status', 'value' => 'pending']]]);
    foreach ($ids as $id) {
        $id = (int) $id;
        $age = time() - (int) get_post_time('U', true, $id);
        if ($age >= 3 * HOUR_IN_SECONDS) {
            // Release on time (the hourly job may lag up to an hour).
            update_post_meta($id, '_sa_bk_status', 'cancelled');
            update_post_meta($id, '_sa_bk_notes', 'Auto-released: booking fee not paid within 3 hours. ' . get_post_meta($id, '_sa_bk_notes', true));
            if (function_exists('sa_svc_gcal_sync')) {
                sa_svc_gcal_sync($id);
            }
            $sent[] = [$id, 'released'];
            continue;
        }
        if ($age >= 155 * MINUTE_IN_SECONDS && !get_post_meta($id, '_sa_bk_invoice_nudge2_sent', true)) {
            sa_bk_invoice_email($id, 'nudge2');
            $sent[] = [$id, 'nudge2'];
        } elseif ($age >= 30 * MINUTE_IN_SECONDS && $age < 155 * MINUTE_IN_SECONDS && !get_post_meta($id, '_sa_bk_invoice_nudge1_sent', true)) {
            sa_bk_invoice_email($id, 'nudge1');
            $sent[] = [$id, 'nudge1'];
        }
    }
    return $sent;
}
add_action('sa_bk_pay_nudges', 'sa_bk_run_pay_nudges');


