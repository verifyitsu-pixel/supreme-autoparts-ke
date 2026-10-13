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

function sa_bk_invoice_html(int $id, bool $embed_logo = false): string
{
    $b = sa_svc_booking_data($id);
    $svc = sa_svc_catalog()[$b['service']] ?? ['name' => $b['service']];
    $paid = in_array($b['fee'], ['paid', 'applied'], true);
    $kes = (int) ($b['fee_kes'] ?: sa_svc_setting('fee_kes'));
    $usd = sa_svc_fee_usd();
    $loc = sa_svc_locations()[$b['location']]['label'] ?? $b['location'];
    $pay = add_query_arg(['b' => $id, 'k' => $b['key']], home_url('/book-service/pay/'));
    $logo = $embed_logo && function_exists('sa_core_logo_data_uri') ? sa_core_logo_data_uri() : (function_exists('sa_core_invoice_logo_url') ? sa_core_invoice_logo_url() : '');
    $e = static fn($s) => esc_html((string) $s);
    $badge = $paid
        ? '<span style="display:inline-block;padding:6px 14px;border-radius:999px;background:#e7f7ee;color:#137a3f;font-weight:700;letter-spacing:.08em">PAID</span>'
        : '<span style="display:inline-block;padding:6px 14px;border-radius:999px;background:#fff1e0;color:#b45309;font-weight:700;letter-spacing:.08em">UNPAID</span>';
    $rows = [
        'Booking number' => $b['ref'],
        'Customer' => trim($b['name'] . ' · ' . $b['email'] . ($b['phone'] ? ' · ' . $b['phone'] : '')),
        'Vehicle' => trim($b['year'] . ' ' . $b['make'] . ' ' . $b['model'] . ' ' . $b['reg']),
        'Service' => $svc['name'] . ($b['option'] ? ' — ' . $b['option'] : ''),
        'Appointment' => ($b['date'] ? date_i18n('l j F Y', strtotime($b['date'])) : '') . ($b['time'] ? ' at ' . $b['time'] : '') . ' (' . sa_svc_duration_label($b['service']) . ')',
        'Location' => $loc,
        'Handover' => $b['mode'] === 'pickup' ? 'We pick up from: ' . $b['pickup'] : 'Customer drop-off at the garage',
    ];
    $h = '<div style="font-family:Inter,Arial,sans-serif;max-width:720px;margin:24px auto;background:#fff;color:#1f2430;border:1px solid #e6e8ee;border-radius:14px;padding:28px">';
    $h .= '<table style="width:100%;border-collapse:collapse"><tr><td style="vertical-align:top">'
        . ($logo ? '<img src="' . ($embed_logo ? esc_attr($logo) : esc_url($logo)) . '" width="160" alt="Supreme Autoparts" style="max-height:48px;margin-bottom:8px"><br>' : '')
        . '<strong>Standard Driveworks / Supreme Autoparts</strong><br><span style="color:#5b6475;font-size:13px">Ground Floor, Midax Plaza, Kandundo Rd, Njiru, Nairobi<br>P.O. Box 00516-00100 G.P.O Nairobi<br>calvin@supremeautoparts.co.ke · +254 714 498 451</span></td>'
        . '<td style="vertical-align:top;text-align:right"><div style="font-size:22px;font-weight:800;color:#0b1f3a">' . ($paid ? 'INVOICE / BOOKING CONFIRMATION' : 'INVOICE') . '</div>'
        . '<div style="margin:6px 0;color:#5b6475;font-size:13px">No. ' . $e(sa_bk_invoice_no($b)) . '<br>Issued ' . $e(wp_date('j M Y', (int) $b['created'])) . '</div>' . $badge . '</td></tr></table>';
    $h .= '<table style="width:100%;border-collapse:collapse;margin-top:20px;font-size:14px">';
    foreach ($rows as $k => $v) {
        $h .= '<tr><td style="padding:8px 0;border-bottom:1px solid #eef0f4;color:#5b6475;width:34%">' . $e($k) . '</td><td style="padding:8px 0;border-bottom:1px solid #eef0f4"><strong>' . $e($v) . '</strong></td></tr>';
    }
    $h .= '</table><table style="width:100%;border-collapse:collapse;margin-top:18px;font-size:14px">'
        . '<tr style="background:#f6f7fa"><th style="text-align:left;padding:10px">Item</th><th style="text-align:right;padding:10px">Amount</th></tr>'
        . '<tr><td style="padding:10px;border-bottom:1px solid #eef0f4">Car service booking fee (deducted from your final service bill)</td><td style="padding:10px;text-align:right;border-bottom:1px solid #eef0f4"><strong>KES ' . number_format($kes) . '</strong>' . ($usd !== null ? '<br><small style="color:#5b6475">≈ USD ' . number_format($usd, 2) . '</small>' : '') . '</td></tr>'
        . '<tr><td style="padding:10px;color:#5b6475">Estimated service total (paid at the garage, less the booking fee)</td><td style="padding:10px;text-align:right;color:#5b6475">' . $e($b['estimate'] ?: 'Quote on request') . '</td></tr>'
        . '<tr><td style="padding:12px 10px;font-weight:700">' . ($paid ? 'Booking fee paid' : 'Amount due now') . '</td><td style="padding:12px 10px;text-align:right;font-weight:800;font-size:18px">KES ' . number_format($kes) . '</td></tr></table>';
    if (!$paid && $b['status'] === 'pending') {
        $h .= '<p style="text-align:center;margin:22px 0 8px"><a href="' . esc_url($pay) . '" style="display:inline-block;background:#F5A623;color:#111;text-decoration:none;font-weight:700;padding:12px 26px;border-radius:10px">Pay KES ' . number_format($kes) . ' now</a></p>';
    }
    if ($paid) {
        $h .= '<p style="margin-top:18px;font-size:14px">Your booking is confirmed. Please keep booking number <strong>' . $e($b['ref']) . '</strong> for reference.' . ($b['fee_order'] ? ' Payment order #' . (int) $b['fee_order'] . '.' : '') . '</p>';
    }
    $h .= '<p style="color:#5b6475;font-size:12px;margin-top:18px">The estimate is based on published prices; you approve the final quote before any work starts. Fee refunds follow our <a href="' . esc_url(home_url('/booking-terms/')) . '">Booking Terms</a>.</p></div>';
    return $h;
}

function sa_bk_invoice_email(int $id, string $kind = ''): void
{
    $b = sa_svc_booking_data($id);
    if (!is_email($b['email'])) {
        return;
    }
    $paid = in_array($b['fee'], ['paid', 'applied'], true);
    $kes = number_format((int) ($b['fee_kes'] ?: sa_svc_setting('fee_kes')));
    $pay = add_query_arg(['b' => $id, 'k' => $b['key']], home_url('/book-service/pay/'));
    $release = wp_date('g:i A, j M', (int) $b['created'] + 2 * HOUR_IN_SECONDS) . ' EAT';
    if ($paid) {
        $subject = 'Booking confirmed — paid invoice ' . sa_bk_invoice_no($b) . ' (' . $b['ref'] . ')';
        $lead = 'Thank you, your booking fee is paid and your booking is confirmed. Your paid invoice is below.';
    } elseif ($kind === 'nudge1') {
        $subject = 'Complete your payment to secure booking ' . $b['ref'];
        $lead = 'Your slot is on hold, but the KES ' . $kes . ' booking fee is still unpaid. Pay now to secure it. Unpaid bookings are released at ' . $release . '.';
    } elseif ($kind === 'nudge2') {
        $subject = 'Final reminder: booking ' . $b['ref'] . ' is released at ' . $release;
        $lead = 'This is your last reminder. If the KES ' . $kes . ' booking fee is not paid, your slot will be released at ' . $release . ' and offered to other customers.';
    } else {
        $subject = 'Booking received — pay KES ' . $kes . ' to secure your slot (' . $b['ref'] . ')';
        $lead = 'Thanks for booking. Pay the KES ' . $kes . ' booking fee to secure your slot. It is deducted from your final bill. Unpaid bookings are released after 2 hours (at ' . $release . ').';
    }
    $btn = $paid || $b['status'] !== 'pending' ? '' : '<p style="text-align:center;margin:18px 0"><a href="' . esc_url($pay) . '" style="display:inline-block;background:#F5A623;color:#111;text-decoration:none;font-weight:800;font-size:16px;padding:14px 30px;border-radius:10px">Pay booking fee — KES ' . $kes . '</a></p>';
    $body = '<div style="font-family:Arial,sans-serif;max-width:720px;margin:auto"><p>Hi ' . esc_html($b['name']) . ',</p><p>' . esc_html($lead) . '</p>' . $btn . '</div>' . sa_bk_invoice_html($id)
        . '<p style="font-family:Arial,sans-serif;text-align:center;font-size:13px"><a href="' . esc_url(sa_bk_invoice_url($id)) . '">View or download this invoice online</a></p>';
    $tmp = '';
    $att = [];
    $dir = function_exists('get_temp_dir') ? get_temp_dir() : sys_get_temp_dir() . '/';
    $tmp = $dir . sanitize_file_name(sa_bk_invoice_no($b) . ($paid ? '-PAID' : '') . '.html');
    if (@file_put_contents($tmp, '<!doctype html><meta charset="utf-8"><title>' . esc_html(sa_bk_invoice_no($b)) . '</title><body style="background:#f4f5f8">' . sa_bk_invoice_html($id, true) . '</body>') !== false) {
        $att[] = $tmp;
    }
    wp_mail($b['email'], $subject, $body, ['Content-Type: text/html; charset=UTF-8'], $att);
    if ($tmp !== '' && file_exists($tmp)) {
        @unlink($tmp);
    }
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
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    if (!empty($_GET['dl'])) {
        header('Content-Disposition: attachment; filename="' . sanitize_file_name(sa_bk_invoice_no($b)) . '.html"');
    }
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . esc_html(sa_bk_invoice_no($b)) . '</title>'
        . '<style>@media print{.noprint{display:none}body{background:#fff!important}}</style></head><body style="background:#f4f5f8;margin:0;padding:12px">'
        . '<p class="noprint" style="font-family:Arial,sans-serif;text-align:center"><button onclick="window.print()" style="padding:10px 18px;border-radius:10px;border:0;background:#0b1f3a;color:#fff;font-weight:600;cursor:pointer">Print / Save as PDF</button> '
        . '<a href="' . esc_url(sa_bk_invoice_url($id, true)) . '" style="margin-left:10px">Download</a></p>'
        . sa_bk_invoice_html($id, !empty($_GET['dl'])) . '</body></html>';
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

/* Payment nudges: ~30 min after booking, and a final one ~25 min before the 2-hour release. Runs every 5 minutes. */
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
    $ids = get_posts(['post_type' => 'sa_booking', 'numberposts' => 100, 'fields' => 'ids', 'date_query' => [['after' => '3 hours ago']],
        'meta_query' => [['key' => '_sa_bk_fee', 'value' => 'unpaid'], ['key' => '_sa_bk_status', 'value' => 'pending']]]);
    foreach ($ids as $id) {
        $id = (int) $id;
        $age = time() - (int) get_post_time('U', true, $id);
        if ($age >= 2 * HOUR_IN_SECONDS) {
            // Release on time (the hourly job may lag up to an hour).
            update_post_meta($id, '_sa_bk_status', 'cancelled');
            update_post_meta($id, '_sa_bk_notes', 'Auto-released: booking fee not paid within 2 hours. ' . get_post_meta($id, '_sa_bk_notes', true));
            if (function_exists('sa_svc_gcal_sync')) {
                sa_svc_gcal_sync($id);
            }
            $sent[] = [$id, 'released'];
            continue;
        }
        if ($age >= 95 * MINUTE_IN_SECONDS && !get_post_meta($id, '_sa_bk_invoice_nudge2_sent', true)) {
            sa_bk_invoice_email($id, 'nudge2');
            $sent[] = [$id, 'nudge2'];
        } elseif ($age >= 30 * MINUTE_IN_SECONDS && $age < 95 * MINUTE_IN_SECONDS && !get_post_meta($id, '_sa_bk_invoice_nudge1_sent', true)) {
            sa_bk_invoice_email($id, 'nudge1');
            $sent[] = [$id, 'nudge1'];
        }
    }
    return $sent;
}
add_action('sa_bk_pay_nudges', 'sa_bk_run_pay_nudges');
