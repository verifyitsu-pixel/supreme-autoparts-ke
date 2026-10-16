<?php
/**
 * Order lifecycle: custom statuses (shipped, out for delivery, delivered, ready for pickup, awaiting info),
 * branded customer emails per stage, tokenized no-login "Submit more info" form, admin order actions
 * (tracking, request info, out for delivery) and a customer-facing status timeline.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Custom statuses (slug without wc- => label). */
function sa_ol_custom_statuses(): array
{
    return [
        'shipped'          => 'Shipped',
        'out-for-delivery' => 'Out for delivery',
        'delivered'        => 'Delivered',
        'ready-pickup'     => 'Ready for pickup',
        'awaiting-info'    => 'Awaiting info',
    ];
}

add_action('init', static function (): void {
    foreach (sa_ol_custom_statuses() as $slug => $label) {
        register_post_status('wc-' . $slug, [
            'label' => $label, 'public' => false, 'exclude_from_search' => false,
            'show_in_admin_all_list' => true, 'show_in_admin_status_list' => true,
            /* translators: %s: count */
            'label_count' => _n_noop($label . ' <span class="count">(%s)</span>', $label . ' <span class="count">(%s)</span>'),
        ]);
    }
}, 5);

add_filter('wc_order_statuses', static function (array $st): array {
    $out = [];
    foreach ($st as $k => $v) {
        $out[$k] = $v;
        if ($k === 'wc-processing') {
            foreach (sa_ol_custom_statuses() as $slug => $label) {
                $out['wc-' . $slug] = $label;
            }
        }
    }
    return $out;
});
add_filter('woocommerce_order_is_paid_statuses', static fn(array $s): array => array_merge($s, ['shipped', 'out-for-delivery', 'delivered', 'ready-pickup']));
add_filter('woocommerce_reports_order_statuses', static fn($s) => is_array($s) ? array_merge($s, ['shipped', 'out-for-delivery', 'delivered', 'ready-pickup']) : $s);
add_filter('woocommerce_valid_order_statuses_for_payment', static fn(array $s): array => array_merge($s, ['awaiting-info']));
add_filter('woocommerce_order_is_download_permitted', static fn($ok, $o) => $ok || ($o instanceof WC_Order && in_array($o->get_status(), ['shipped', 'out-for-delivery', 'delivered', 'ready-pickup'], true)), 10, 2);

/* Woo's own on-hold / refunded / completed customer emails are replaced by ours (no duplicates).
 * The processing receipt (with the item list) stays Woo's — see order-emails.php. */
foreach (['customer_on_hold_order', 'customer_refunded_order', 'customer_partially_refunded_order', 'customer_completed_order', 'customer_failed_order', 'customer_cancelled_order'] as $sa_ol_id) {
    add_filter('woocommerce_email_enabled_' . $sa_ol_id, '__return_false', 50);
}

/* ---------- Couriers ---------- */

function sa_ol_couriers(): array
{
    return [
        'fargo' => ['label' => 'Fargo Courier', 'url' => 'FARGO'],
        'ups'   => ['label' => 'UPS', 'url' => 'https://www.ups.com/track?loc=en_US&tracknum=%s'],
        'other' => ['label' => 'Other courier', 'url' => ''],
    ];
}

function sa_ol_tracking_url(WC_Order $o): string
{
    $c = (string) $o->get_meta('_sa_courier');
    $n = (string) $o->get_meta('_sa_tracking_no');
    $tpl = sa_ol_couriers()[$c]['url'] ?? '';
    if ($tpl === 'FARGO') {
        return $n !== '' ? add_query_arg('waybill', rawurlencode($n), home_url('/track-order/')) : '';
    }
    return ($tpl !== '' && $n !== '') ? sprintf($tpl, rawurlencode($n)) : '';
}

/* ---------- Timeline ---------- */

function sa_ol_log(WC_Order $o, string $stage, string $note = ''): void
{
    $h = $o->get_meta('_sa_timeline');
    $h = is_array($h) ? $h : [];
    $h[] = ['t' => time(), 's' => $stage, 'n' => $note];
    $o->update_meta_data('_sa_timeline', array_slice($h, -60));
    $o->save_meta_data();
}

function sa_ol_stage_labels(): array
{
    return [
        'placed' => 'Order placed', 'pending' => 'Awaiting payment', 'processing' => 'Processing', 'on-hold' => 'On hold',
        'awaiting-info' => 'More info requested', 'info-received' => 'Info received from you', 'shipped' => 'Shipped',
        'out-for-delivery' => 'Out for delivery', 'delivered' => 'Delivered', 'ready-pickup' => 'Ready for pickup',
        'completed' => 'Completed', 'cancelled' => 'Cancelled', 'refunded' => 'Refunded', 'failed' => 'Payment failed',
    ];
}

function sa_ol_timeline_html(WC_Order $o): string
{
    $h = $o->get_meta('_sa_timeline');
    $h = is_array($h) ? $h : [];
    if (!$h) {
        $h[] = ['t' => $o->get_date_created() ? $o->get_date_created()->getTimestamp() : time(), 's' => 'placed', 'n' => ''];
        if ($o->get_status() !== 'pending') {
            $h[] = ['t' => $o->get_date_modified() ? $o->get_date_modified()->getTimestamp() : time(), 's' => $o->get_status(), 'n' => ''];
        }
    }
    $lab = sa_ol_stage_labels();
    $flow = ['placed', 'processing', 'shipped', 'out-for-delivery', 'delivered'];
    if ((string) $o->get_meta('_sa_pickup') === '1' || $o->has_shipping_method('local_pickup')) {
        $flow = ['placed', 'processing', 'ready-pickup', 'completed'];
    }
    $seen = array_column($h, 's');
    $cur = $o->get_status();
    $html = '<section class="sa-ol" style="margin:0 0 24px"><h2 style="font-size:1.15rem;margin:0 0 12px">Order status: ' . esc_html(wc_get_order_status_name($cur)) . '</h2>';
    if (!in_array($cur, ['cancelled', 'refunded', 'failed'], true)) {
        $html .= '<ol class="sa-ol__steps" style="display:flex;gap:6px;list-style:none;padding:0;margin:0 0 16px;flex-wrap:wrap">';
        $reached = true;
        foreach ($flow as $i => $s) {
            $done = in_array($s, $seen, true) || ($s === 'placed') || ($s === $cur);
            $html .= '<li style="flex:1 1 90px;min-width:90px;padding:8px 10px;border-radius:8px;font-size:.8rem;font-weight:600;border:1px solid ' . ($done ? '#0a0a0a;background:#eef2fe;color:#1f3fb0' : '#e4e4e4;background:#fff;color:#888') . '">' . ($done ? '✓ ' : ($i + 1) . '. ') . esc_html($lab[$s] ?? $s) . '</li>';
        }
        $html .= '</ol>';
    }
    $html .= '<ul style="list-style:none;padding:0;margin:0;border-left:2px solid #e4e4e4">';
    foreach (array_reverse($h) as $e) {
        $html .= '<li style="padding:0 0 12px 14px;position:relative"><span style="position:absolute;left:-6px;top:4px;width:10px;height:10px;border-radius:50%;background:#0a0a0a"></span><strong>' . esc_html($lab[$e['s']] ?? ucfirst((string) $e['s'])) . '</strong> <small style="color:#6b6b6b">' . esc_html(wp_date('j M Y, g:i A', (int) $e['t'])) . ' EAT</small>'
            . ($e['n'] !== '' ? '<div style="color:#444;font-size:.9rem">' . esc_html((string) $e['n']) . '</div>' : '') . '</li>';
    }
    $html .= '</ul>';
    $trk = sa_ol_tracking_url($o);
    if ($o->get_meta('_sa_tracking_no')) {
        $html .= '<p style="margin:8px 0 0"><strong>' . esc_html(sa_ol_couriers()[(string) $o->get_meta('_sa_courier')]['label'] ?? 'Courier') . '</strong> tracking: ' . esc_html((string) $o->get_meta('_sa_tracking_no'))
            . ($trk ? ' · <a href="' . esc_url($trk) . '" target="_blank" rel="noopener">Track parcel</a>' : '') . '</p>';
    }
    if ($cur === 'awaiting-info' || $cur === 'on-hold') {
        $html .= '<p style="margin:12px 0 0"><a class="sa-btn" href="' . esc_url(sa_ol_info_url($o)) . '">Submit more info</a></p>';
    }
    if ($cur === 'failed' || $cur === 'pending') {
        $html .= '<p style="margin:12px 0 0"><a class="sa-btn" href="' . esc_url($o->get_checkout_payment_url()) . '">Pay now</a></p>';
    }
    return $html . '</section>';
}

/* View order (My Account) + Track order shortcode both fire woocommerce_view_order. */
add_action('woocommerce_view_order', static function ($order_id): void {
    $o = wc_get_order($order_id);
    if ($o instanceof WC_Order) {
        echo sa_ol_timeline_html($o); // phpcs:ignore
    }
}, 5);
add_action('woocommerce_thankyou', static function ($order_id): void {
    $o = wc_get_order($order_id);
    if ($o instanceof WC_Order && !did_action('woocommerce_view_order')) {
        echo sa_ol_timeline_html($o); // phpcs:ignore
    }
}, 4);

/* ---------- Email layout (shared by order + workshop emails) ---------- */

function sa_mail_layout(string $heading, string $inner, string $cta_label = '', string $cta_url = '', string $preheader = ''): string
{
    $logo = function_exists('sa_theme_logo_url') ? sa_theme_logo_url(true) : home_url('/wp-content/themes/supreme-autoparts/assets/logo-light.jpg');
    $btn = ($cta_label !== '' && $cta_url !== '') ? '<tr><td style="padding:8px 28px 24px"><a href="' . esc_url($cta_url) . '" style="display:inline-block;background:#0a0a0a;color:#ffffff;text-decoration:none;font-weight:700;font-size:15px;padding:13px 26px;border-radius:6px">' . esc_html($cta_label) . '</a></td></tr>' : '';
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark"><title>' . esc_html($heading) . '</title></head>'
        . '<body style="margin:0;background:#f4f4f4;font-family:Inter,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#333">'
        . '<span style="display:none;max-height:0;overflow:hidden">' . esc_html($preheader) . '</span>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4"><tr><td align="center" style="padding:24px 12px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border:1px solid #e4e4e4;border-radius:8px">'
        . '<tr><td class="sa-email-brand-header" style="padding:20px 28px 14px;border-bottom:1px solid #e4e4e4"><img src="' . esc_url($logo) . '" alt="Supreme Autoparts" width="340" style="display:block;width:340px;max-width:100%;height:auto;border:0"></td></tr>'
        . '<tr><td style="padding:24px 28px 8px"><h1 style="margin:0 0 12px;font-size:21px;line-height:1.3;color:#191919">' . esc_html($heading) . '</h1><div style="font-size:15px;line-height:1.6;color:#333">' . $inner . '</div></td></tr>'
        . $btn . '</table></td></tr></table></body></html>';
}

function sa_ol_items_html(WC_Order $o): string
{
    if (function_exists('sa_rcpt_order_data')) {
        $d = sa_rcpt_order_data($o);
        return '<div style="margin:16px 0">' . sa_rcpt_items_html($d['items'], 'email', SA_RCPT_FONT)
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:8px;border-collapse:collapse">' . sa_rcpt_totals_html($d['totals'], SA_RCPT_FONT) . '</table></div>';
    }
    $rows = '';
    foreach ($o->get_items() as $it) {
        $rows .= '<tr><td style="padding:6px 0;border-bottom:1px solid #eee">' . esc_html($it->get_name()) . ' × ' . (int) $it->get_quantity() . '</td><td style="padding:6px 0;border-bottom:1px solid #eee;text-align:right;white-space:nowrap">' . wp_kses_post($o->get_formatted_line_subtotal($it)) . '</td></tr>';
    }
    return '<table role="presentation" width="100%" style="border-collapse:collapse;font-size:14px;margin:12px 0">' . $rows
        . '<tr><td style="padding:8px 0;font-weight:700">Total</td><td style="padding:8px 0;font-weight:700;text-align:right">' . wp_kses_post($o->get_formatted_order_total()) . '</td></tr></table>';
}

function sa_ol_view_url(WC_Order $o): string
{
    return $o->get_customer_id() ? $o->get_view_order_url() : add_query_arg(['orderid' => $o->get_id(), 'order_email' => $o->get_billing_email()], home_url('/track-order/'));
}

function sa_ol_info_url(WC_Order $o): string
{
    return add_query_arg(['o' => $o->get_id(), 'k' => $o->get_order_key()], home_url('/order-info/'));
}

/** Carrier, tracking number and delivery address as one small block for shipped / out for delivery emails. */
function sa_ol_delivery_block(WC_Order $o): string
{
    $c = (string) $o->get_meta('_sa_courier');
    $no = (string) $o->get_meta('_sa_tracking_no');
    $label = sa_ol_couriers()[$c]['label'] ?? '';
    $lbl = 'font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#6b6b6b;font-weight:600;margin:0 0 4px';
    $rows = '';
    if ($label !== '' && $c !== 'other') {
        $rows .= '<tr><td style="padding:2px 12px 2px 0;color:#6b6b6b;width:38%">Carrier</td><td style="padding:2px 0;font-weight:600">' . esc_html($label) . '</td></tr>';
    } elseif ($label !== '') {
        $rows .= '<tr><td style="padding:2px 12px 2px 0;color:#6b6b6b;width:38%">Carrier</td><td style="padding:2px 0;font-weight:600">Courier</td></tr>';
    }
    if ($no !== '') {
        $rows .= '<tr><td style="padding:2px 12px 2px 0;color:#6b6b6b;width:38%">Tracking number</td><td style="padding:2px 0;font-weight:600;white-space:nowrap">' . esc_html($no) . '</td></tr>';
    }
    $addr = $o->has_shipping_address() ? $o->get_formatted_shipping_address() : $o->get_formatted_billing_address();
    $h = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin:14px 0;font-size:14px;line-height:1.5"><tr><td style="padding:14px 0;border-top:1px solid #e4e4e4;border-bottom:1px solid #e4e4e4">';
    if ($rows !== '') {
        $h .= '<div style="' . $lbl . '">Shipment</div><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin:0 0 12px">' . $rows . '</table>';
    }
    if ($addr !== '') {
        $h .= '<div style="' . $lbl . '">Delivering to</div><div>' . wp_kses_post($addr) . ($o->get_billing_phone() ? '<br>' . esc_html($o->get_billing_phone()) : '') . '</div>';
    }
    return $h . '</td></tr></table>';
}

/** Build subject + html for a stage. */
function sa_ol_email_content(WC_Order $o, string $stage, string $note = ''): array
{
    $n = $o->get_order_number();
    $hi = '<p>' . sa_email_greeting($o) . '</p>';
    $noteh = $note !== '' ? '<p style="background:#f7f7f7;border-left:3px solid #F5A623;padding:10px 12px;margin:12px 0">' . nl2br(esc_html($note)) . '</p>' : '';
    $c = (string) $o->get_meta('_sa_courier');
    $trk_no = (string) $o->get_meta('_sa_tracking_no');
    $trk = sa_ol_tracking_url($o);
    $trkh = $trk_no !== '' ? '<p><strong>Courier:</strong> ' . esc_html(sa_ol_couriers()[$c]['label'] ?? 'Courier') . '<br><strong>Tracking number:</strong> ' . esc_html($trk_no) . ($trk ? '<br><a href="' . esc_url($trk) . '" style="color:#0a0a0a">Track your parcel on the courier site</a>' : '') . '</p>' : '';
    $view = sa_ol_view_url($o);
    // When a structured information request is open, list each document with why we need it and how to send it.
    $hr_items = '';
    $hr_why = '';
    if (function_exists('sa_hr_open') && function_exists('sa_hr_items_email') && ($hr = sa_hr_open($o))) {
        $hr_items = sa_hr_items_email($hr);
        if (function_exists('sa_hr_copy')) {
            $hr_why = (string) (sa_hr_copy((string) ($hr['reason'] ?? 'other'))['reason'] ?? '');
        }
    }
    $m = [
        'processing' => ['We are preparing order #' . $n, 'Your order is being processed', '<p>We have your order and are getting it ready. We will email you again when it ships or is ready for pickup.</p>' . sa_ol_items_html($o), 'View your order', $view],
        'on-hold' => ['Order #' . $n . ' is on hold', 'Your order is on hold', '<p>We have paused order #' . esc_html($n) . ' while we complete a quick check. You do not need to do anything unless we contact you.</p>' . ($noteh ? '<p><strong>Reason</strong></p>' . $noteh : '') . '<p>If you have details that could help, such as photos or documents, you can send them with the button below. No login needed.</p>', 'Send details', sa_ol_info_url($o)],
        'awaiting-info' => ['Information needed for order #' . $n, 'We need information to continue your order', '<p>We cannot continue with order #' . esc_html($n) . ' until we receive the information below.</p><p><strong>What we need</strong></p>' . ($noteh ?: ($hr_items !== '' ? '' : '<p style="background:#f7f7f7;border-left:3px solid #F5A623;padding:10px 12px;margin:12px 0">Details about your order. Reply with the button below and we will confirm what is missing.</p>')) . '<p><strong>Why we need it</strong><br>' . ($hr_why !== '' ? esc_html($hr_why) : 'We use it to confirm the order is correct, for example the right part for your vehicle or your delivery or payment details, so nothing is sent or charged by mistake.') . '</p>' . $hr_items . '<p><strong>How to send it</strong><br>Use the button below to reply and attach photos or documents. No login needed. Your order stays on hold until we hear from you.</p>', 'Send the information', sa_ol_info_url($o)],
        'shipped' => ['Order #' . $n . ' has shipped', 'Your order is on its way', '<p>Your order has been handed to the courier.</p>' . sa_ol_delivery_block($o) . $noteh, 'Track order', $trk ?: $view],
        'out-for-delivery' => ['Order #' . $n . ' is out for delivery', 'Out for delivery today', '<p>Your order is out for delivery today. Please keep your phone close so the rider can reach you.</p>' . sa_ol_delivery_block($o) . $noteh, 'Track order', $trk ?: $view],
        'delivered' => ['Order #' . $n . ' was delivered', 'Your order was delivered', '<p>Your order has been delivered.</p>' . $noteh, 'View your order', $view],
        'ready-pickup' => ['Order #' . $n . ' is ready for pickup', 'Ready for pickup', '<p>Your order is ready to collect at our store:</p><p><strong>Ground Floor, Midax Plaza, Kangundo Rd, Njiru, Nairobi</strong></p><p>Please bring your order number (#' . esc_html($n) . ') and the phone number used at checkout.</p>' . apply_filters('sa_ol_ready_pickup_extra', '', $o) . $noteh, 'Get directions', 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode('Midax Plaza, Kangundo Rd, Njiru, Nairobi')],
        'completed' => ['Order #' . $n . ' is complete', 'Thank you, your order is complete', '<p>Your order is complete. Thank you for shopping with Supreme Autoparts.</p>' . sa_ol_items_html($o) . $noteh, 'View your order', $view],
        'cancelled' => ['Order #' . $n . ' was cancelled', 'Your order was cancelled', '<p>Your order #' . esc_html($n) . ' has been cancelled.</p>' . $noteh . '<p>If you paid, any refund goes back to the original payment method.</p>', 'Shop again', home_url('/shop/')],
        'refunded' => ['Refund for order #' . $n, 'Your refund is on its way', '<p>We have refunded ' . wp_kses_post(wc_price((float) $o->get_total_refunded(), ['currency' => $o->get_currency()])) . ' for order #' . esc_html($n) . '. Depending on your bank or card it can take a few working days to show.</p>' . $noteh, 'View your order', $view],
        'failed' => ['Payment for order #' . $n . ' did not go through', 'Your payment failed', '<p>We could not take payment for order #' . esc_html($n) . '. Nothing was charged. You can try again with the button below.</p>' . sa_ol_items_html($o), 'Pay now', $o->get_checkout_payment_url()],
    ];
    if (!isset($m[$stage])) {
        return [];
    }
    [$subject, $heading, $body, $cta, $url] = $m[$stage];
    return ['subject' => $subject, 'html' => sa_email_add_footer(sa_mail_layout($heading, $hi . $body . '<p style="color:#6b6b6b;font-size:13px">Order #' . esc_html($n) . '</p>', $cta, $url, $subject), (string) ($o->get_billing_country() ?: $o->get_shipping_country()))];
}

function sa_ol_send(WC_Order $o, string $stage, string $note = ''): bool
{
    $to = (string) $o->get_billing_email();
    // Booking-fee and roadside pay-link orders have their own customer emails.
    if (!is_email($to) || $o->get_meta('_sa_rs_for') || $o->get_meta('_sa_booking_fee_for') || ($o->get_meta('_sa_ro_id') && $stage !== 'failed')) {
        return false;
    }
    $c = sa_ol_email_content($o, $stage, $note);
    if (!$c) {
        return false;
    }
    $pdf = in_array($stage, ['processing', 'completed', 'refunded'], true) && function_exists('sa_rcpt_order_pdf') ? sa_rcpt_order_pdf($o) : '';
    $ok = (bool) wp_mail($to, $c['subject'], $c['html'], ['Content-Type: text/html; charset=UTF-8'], $pdf !== '' ? [$pdf] : []);
    if ($pdf !== '') {
        sa_rcpt_cleanup($pdf);
    }
    $o->add_order_note('Customer email sent: ' . (sa_ol_stage_labels()[$stage] ?? $stage) . ($ok ? '' : ' (send failed)'));
    return $ok;
}

/* Status change => timeline + email. Processing uses Woo's receipt; we only log it. */
add_action('woocommerce_order_status_changed', static function ($oid, $from, $to, $order): void {
    if (!$order instanceof WC_Order) {
        return;
    }
    $note = (string) $order->get_meta('_sa_stage_note');
    $order->delete_meta_data('_sa_stage_note');
    sa_ol_log($order, $to, $note);
    if ($to === 'processing') {
        return;
    }
    if ($to === 'failed' && $from !== 'pending' && $from !== 'on-hold') {
        return;
    }
    if ($to === 'cancelled' && $from === 'pending' && !is_admin()) {
        return; // Unpaid order auto-released by stock hold timeout: no "cancelled" email.
    }
    if (in_array($to, ['on-hold', 'awaiting-info', 'shipped', 'out-for-delivery', 'delivered', 'ready-pickup', 'completed', 'cancelled', 'refunded', 'failed'], true)) {
        if ($to === 'on-hold' && $from === 'pending' && !$order->get_meta('_sa_hold_reason') && $note === '') {
            // Gateway-initiated hold (awaiting bank/M-Pesa confirmation): give the standard reason.
            $note = 'We are waiting for your payment to be confirmed. This is usually quick.';
        }
        if (in_array($to, ['on-hold', 'cancelled'], true) && $order->get_meta('_sa_hr_silent')) {
            return; // A structured info-request email goes out instead.
        }
        sa_ol_send($order, $to, $note);
    }
}, 20, 4);

add_action('woocommerce_new_order', static function ($oid): void {
    $o = wc_get_order($oid);
    if ($o instanceof WC_Order && !$o->get_meta('_sa_timeline')) {
        sa_ol_log($o, 'placed');
    }
}, 20);

/* Bulk actions for the new statuses. */
foreach (['bulk_actions-edit-shop_order', 'bulk_actions-woocommerce_page_wc-orders'] as $sa_ol_h) {
    add_filter($sa_ol_h, static function (array $a): array {
        $a['mark_shipped'] = 'Change status to shipped';
        $a['mark_out-for-delivery'] = 'Change status to out for delivery';
        $a['mark_delivered'] = 'Change status to delivered';
        $a['mark_ready-pickup'] = 'Change status to ready for pickup';
        return $a;
    }, 20);
}

/* ---------- Admin order actions (meta box) ---------- */

add_action('add_meta_boxes', static function (): void {
    $screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'shop_order';
    add_meta_box('sa-ol-actions', 'Fulfilment & customer updates', 'sa_ol_metabox', $screen, 'side', 'high');
});

function sa_ol_metabox($post_or_order): void
{
    $o = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID ?? 0);
    if (!$o instanceof WC_Order) {
        return;
    }
    $c = (string) $o->get_meta('_sa_courier');
    echo '<div class="sa-ol-box">';
    wp_nonce_field('sa_ol_admin', 'sa_ol_nonce');
    echo '<p><label><strong>Courier</strong><br><select name="sa_ol_courier" style="width:100%">';
    foreach (sa_ol_couriers() as $k => $v) {
        echo '<option value="' . esc_attr($k) . '"' . selected($c, $k, false) . '>' . esc_html($v['label']) . '</option>';
    }
    echo '</select></label></p><p><label><strong>Tracking number</strong><br><input type="text" name="sa_ol_tracking" value="' . esc_attr((string) $o->get_meta('_sa_tracking_no')) . '" style="width:100%"></label></p>';
    echo '<p><label><strong>Message to customer</strong> (reason / request / note)<br><textarea name="sa_ol_note" rows="3" style="width:100%"></textarea></label></p>';
    echo '<p style="display:flex;flex-wrap:wrap;gap:6px">'
        . '<button class="button button-primary" name="sa_ol_do" value="shipped">Save tracking &amp; mark shipped</button>'
        . '<button class="button" name="sa_ol_do" value="out-for-delivery">Out for delivery</button>'
        . '<button class="button" name="sa_ol_do" value="delivered">Delivered</button>'
        . '<button class="button" name="sa_ol_do" value="ready-pickup">Ready for pickup</button>'
        . '<button class="button" name="sa_ol_do" value="awaiting-info">Request info</button>'
        . '<button class="button" name="sa_ol_do" value="on-hold">Put on hold (reason)</button>'
        . '<button class="button" name="sa_ol_do" value="tracking">Save tracking only</button></p>';
    $info = $o->get_meta('_sa_info_submissions');
    if (is_array($info) && $info) {
        echo '<hr><strong>Customer replies</strong>';
        foreach (array_reverse($info) as $s) {
            echo '<div style="margin:6px 0;padding:6px;background:#f6f7f7;border-radius:4px"><small>' . esc_html(wp_date('j M, g:i A', (int) $s['t'])) . '</small><br>' . nl2br(esc_html((string) $s['msg']));
            foreach ((array) ($s['files'] ?? []) as $f) {
                echo '<br><a href="' . esc_url((string) $f['url']) . '" target="_blank">' . esc_html((string) $f['name']) . '</a>';
            }
            echo '</div>';
        }
    }
    echo '</div>';
}

/** Apply an admin action to an order (also used by tests / Super Admin). */
function sa_ol_admin_action(WC_Order $o, string $do, string $courier = '', string $tracking = '', string $note = ''): void
{
    if ($courier !== '' && isset(sa_ol_couriers()[$courier])) {
        $o->update_meta_data('_sa_courier', $courier);
    }
    if ($tracking !== '') {
        $o->update_meta_data('_sa_tracking_no', $tracking);
    }
    if ($do === 'tracking') {
        $o->save();
        $o->add_order_note('Tracking saved: ' . $tracking);
        return;
    }
    if (!in_array($do, ['shipped', 'out-for-delivery', 'delivered', 'ready-pickup', 'awaiting-info', 'on-hold'], true)) {
        return;
    }
    if ($do === 'on-hold') {
        $o->update_meta_data('_sa_hold_reason', $note);
    }
    if ($note !== '') {
        $o->update_meta_data('_sa_stage_note', $note);
    }
    $o->save();
    if ($o->get_status() === $do) {
        // Same status again (e.g. a second info request): log + email directly.
        $o->delete_meta_data('_sa_stage_note');
        $o->save();
        sa_ol_log($o, $do, $note);
        sa_ol_send($o, $do, $note);
        return;
    }
    $o->update_status($do, 'Set from Fulfilment box.');
}

$sa_ol_save = static function ($order_id): void {
    if (empty($_POST['sa_ol_do']) || !wp_verify_nonce((string) ($_POST['sa_ol_nonce'] ?? ''), 'sa_ol_admin') || !current_user_can('edit_shop_orders')) {
        return;
    }
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $o = wc_get_order((int) $order_id);
    if (!$o instanceof WC_Order) {
        return;
    }
    $u = static fn(string $k): string => sanitize_text_field(wp_unslash((string) ($_POST[$k] ?? '')));
    // Woo's own order-data save (priority 40) throws "Order status is missing." if order_status is absent
    // (that was the admin/mobile-app critical error). Run our action, then hand Woo the resulting status.
    try {
        sa_ol_admin_action($o, sanitize_key((string) $_POST['sa_ol_do']), sanitize_key($u('sa_ol_courier')), $u('sa_ol_tracking'), sanitize_textarea_field(wp_unslash((string) ($_POST['sa_ol_note'] ?? ''))));
    } catch (\Throwable $e) {
        error_log('[sa_ol] admin action failed: ' . $e->getMessage());
    }
    $fresh = wc_get_order((int) $order_id);
    $_POST['order_status'] = 'wc-' . ($fresh instanceof WC_Order ? $fresh->get_status() : $o->get_status());
};
/* Safety net: never let a missing status field fatal the order screen (Woo mobile app webview posts). */
add_action('woocommerce_process_shop_order_meta', static function ($order_id): void {
    if (empty($_POST['order_status'])) {
        $o = wc_get_order((int) $order_id);
        $_POST['order_status'] = 'wc-' . ($o instanceof WC_Order && $o->get_status() ? $o->get_status() : 'pending');
    }
}, 39);
add_action('woocommerce_process_shop_order_meta', $sa_ol_save, 5);

/* ---------- Tokenized "Submit more info" form: /order-info/?o=ID&k=ORDER_KEY ---------- */

add_action('init', static function (): void {
    add_rewrite_rule('^order-info/?$', 'index.php?sa_order_info=1', 'top');
});
add_filter('query_vars', static fn(array $v): array => array_merge($v, ['sa_order_info']));

function sa_ol_info_order(): ?WC_Order
{
    $o = wc_get_order((int) ($_REQUEST['o'] ?? 0));
    if (!$o instanceof WC_Order || !hash_equals((string) $o->get_order_key(), (string) wp_unslash($_REQUEST['k'] ?? ''))) {
        return null;
    }
    return $o;
}

add_action('template_redirect', static function (): void {
    if (!get_query_var('sa_order_info')) {
        return;
    }
    nocache_headers();
    $o = sa_ol_info_order();
    $msg = '';
    if ($o && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!wp_verify_nonce((string) ($_POST['_sa_oi'] ?? ''), 'sa_oi_' . $o->get_id()) || !empty($_POST['sa_hp'])) {
            $msg = 'Your session expired. Please try again.';
        } else {
            $text = sanitize_textarea_field(wp_unslash((string) ($_POST['message'] ?? '')));
            $files = sa_ol_handle_uploads($o);
            if ($text === '' && !$files) {
                $msg = 'Please add a message or a file.';
            } else {
                $list = $o->get_meta('_sa_info_submissions');
                $list = is_array($list) ? $list : [];
                $list[] = ['t' => time(), 'msg' => $text, 'files' => $files];
                $o->update_meta_data('_sa_info_submissions', $list);
                $o->save();
                $o->add_order_note("Customer submitted more info:\n" . $text . ($files ? "\nFiles: " . implode(', ', array_column($files, 'url')) : ''));
                sa_ol_log($o, 'info-received', $text !== '' ? wp_trim_words($text, 20) : count($files) . ' file(s)');
                $admin = (string) get_option('admin_email');
                wp_mail($admin, 'Customer replied on order #' . $o->get_order_number(), sa_mail_layout('Customer sent more info', '<p>' . esc_html($o->get_formatted_billing_full_name()) . ' replied on order #' . esc_html($o->get_order_number()) . ':</p><p style="background:#f7f7f7;padding:10px">' . nl2br(esc_html($text)) . '</p>' . implode('', array_map(static fn($f) => '<p><a href="' . esc_url($f['url']) . '">' . esc_html($f['name']) . '</a></p>', $files)), 'Open order', $o->get_edit_order_url()), ['Content-Type: text/html; charset=UTF-8']);
                wp_safe_redirect(add_query_arg(['o' => $o->get_id(), 'k' => $o->get_order_key(), 'sent' => 1], home_url('/order-info/')));
                exit;
            }
        }
    }
    status_header($o ? 200 : 404);
    get_header();
    echo '<main class="sa-container" style="max-width:640px;margin:32px auto;padding:0 16px">';
    if (!$o) {
        echo '<h1>Link not valid</h1><p>This link has expired or is incorrect. Please use the link in your latest email, or contact us on WhatsApp.</p>';
    } else {
        echo '<h1 style="font-size:1.6rem">Order #' . esc_html($o->get_order_number()) . ': send us more info</h1>';
        $req = '';
        foreach (array_reverse((array) $o->get_meta('_sa_timeline')) as $e) {
            if (in_array($e['s'] ?? '', ['awaiting-info', 'on-hold'], true) && ($e['n'] ?? '') !== '') {
                $req = (string) $e['n'];
                break;
            }
        }
        if ($req !== '') {
            echo '<div style="background:#fff8ec;border:1px solid #F5A623;border-radius:8px;padding:12px 14px;margin:0 0 16px"><strong>What we need:</strong><br>' . nl2br(esc_html($req)) . '</div>';
        }
        if (!empty($_GET['sent'])) {
            echo '<div class="woocommerce-message" role="status">Thanks, we have received your info and will get back to you shortly.</div>';
        }
        if ($msg !== '') {
            echo '<div class="woocommerce-error" role="alert">' . esc_html($msg) . '</div>';
        }
        echo '<form method="post" enctype="multipart/form-data" style="background:#fff;border:1px solid #e4e4e4;border-radius:8px;padding:18px;display:grid;gap:14px">';
        wp_nonce_field('sa_oi_' . $o->get_id(), '_sa_oi');
        echo '<input type="hidden" name="o" value="' . (int) $o->get_id() . '"><input type="hidden" name="k" value="' . esc_attr($o->get_order_key()) . '"><input type="text" name="sa_hp" value="" style="display:none" tabindex="-1" autocomplete="off">'
            . '<label style="display:grid;gap:6px;font-weight:600">Your message<textarea name="message" rows="5" style="width:100%;padding:10px;border:1px solid #ccc;border-radius:6px;font:inherit"></textarea></label>'
            . '<label style="display:grid;gap:6px;font-weight:600">Photos or documents (up to 5, 8 MB each)<input type="file" name="files[]" multiple data-limit="5" data-max="8388608" accept="image/*,application/pdf,.heic,.heif"></label>'
            . '<button class="sa-btn" type="submit" style="justify-self:start">Send to Supreme Autoparts</button></form>';
        echo '<div style="margin-top:24px">' . sa_ol_timeline_html($o) . '</div>';
    }
    echo '</main>';
    get_footer();
    exit;
});

/** @return list<array{name:string,url:string}> */
function sa_ol_handle_uploads(WC_Order $o): array
{
    if (empty($_FILES['files']['name']) || !is_array($_FILES['files']['name'])) {
        return [];
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    $out = [];
    $allowed = ['jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'heic' => 'image/heic', 'heif' => 'image/heif', 'pdf' => 'application/pdf'];
    $sub = static function (array $d) use ($o): array {
        $d['subdir'] = '/sa-order-info/' . $o->get_id();
        $d['path'] = $d['basedir'] . $d['subdir'];
        $d['url'] = $d['baseurl'] . $d['subdir'];
        return $d;
    };
    add_filter('upload_dir', $sub);
    $n = min(5, count($_FILES['files']['name']));
    for ($i = 0; $i < $n; $i++) {
        if ((int) $_FILES['files']['error'][$i] !== UPLOAD_ERR_OK || (int) $_FILES['files']['size'][$i] > 8 * MB_IN_BYTES) {
            continue;
        }
        $f = ['name' => wp_generate_password(8, false) . '-' . sanitize_file_name((string) $_FILES['files']['name'][$i]), 'type' => $_FILES['files']['type'][$i], 'tmp_name' => $_FILES['files']['tmp_name'][$i], 'error' => 0, 'size' => $_FILES['files']['size'][$i]];
        $r = wp_handle_upload($f, ['test_form' => false, 'mimes' => $allowed]);
        if (!empty($r['url'])) {
            $out[] = ['name' => sanitize_file_name((string) $_FILES['files']['name'][$i]), 'url' => (string) $r['url']];
        }
    }
    remove_filter('upload_dir', $sub);
    return $out;
}
