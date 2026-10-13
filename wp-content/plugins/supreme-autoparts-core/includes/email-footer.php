<?php
/**
 * Branded footer appended to every HTML email the site sends (bookings, invoices, reminders,
 * WooCommerce order mails, OTP, password). Plain-text mails get a short text version.
 * No social icons: the site has no social accounts linked.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

function sa_email_footer_html(): string
{
    $u = static fn(string $p): string => esc_url(home_url($p));
    $map = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode('Midax Plaza, Kandundo Rd, Njiru, Nairobi');
    $a = 'color:#F5A623;text-decoration:none';
    $l = 'color:#cfd6e4;text-decoration:none';
    $links = static function (array $items) use ($l): string {
        $o = [];
        foreach ($items as $t => $h) {
            $o[] = '<a href="' . esc_url($h) . '" style="' . $l . '">' . esc_html($t) . '</a>';
        }
        return implode(' &middot; ', $o);
    };
    return '<!--sa-email-footer--><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;margin:24px auto 0;background:#0b1f3a;border-radius:12px;font-family:Arial,Helvetica,sans-serif;color:#cfd6e4;font-size:13px;line-height:1.55"><tr><td style="padding:22px 22px 18px">'
        . '<div style="font-size:15px;font-weight:700;color:#fff;margin-bottom:6px">Standard Driveworks t/a Supreme Autoparts</div>'
        . '<div>Ground Floor, Midax Plaza, Kandundo Rd, Njiru, Nairobi<br>P.O. Box 00516-00100 G.P.O Nairobi &middot; <a href="' . esc_url($map) . '" style="' . $a . '">Map</a></div>'
        . '<div style="margin-top:8px">Phone / WhatsApp: <a href="tel:+254714498451" style="' . $a . '">+254 714 498 451</a> &middot; <a href="https://wa.me/254714498451" style="' . $a . '">WhatsApp</a><br>US: <a href="tel:+19174375121" style="' . $a . '">+1 917 437 5121</a> &middot; <a href="https://wa.me/19174375121" style="' . $a . '">WhatsApp</a><br>Email: <a href="mailto:calvin@supremeautoparts.co.ke" style="' . $a . '">calvin@supremeautoparts.co.ke</a></div>'
        . '<div style="margin-top:8px">Hours: Mon&ndash;Fri 8:00&ndash;17:00 &middot; Sat 8:00&ndash;13:00 (EAT)</div>'
        . '<div style="margin-top:12px;padding-top:12px;border-top:1px solid rgba(255,255,255,.12)">' . $links(['Shop Parts' => home_url('/shop/'), 'Book a Service' => home_url('/book-service/'), 'Manage booking' => home_url('/manage-booking/'), 'My Account' => home_url('/my-account/'), 'Track order' => home_url('/track-order/')]) . '</div>'
        . '<div style="margin-top:6px">' . $links(['Booking Terms' => home_url('/booking-terms/'), 'Refund policy' => home_url('/refund-policy/'), 'Shipping' => home_url('/shipping-policy/'), 'Privacy' => home_url('/privacy-policy/')]) . '</div>'
        . '<div style="margin-top:12px;font-size:12px;color:#9aa6bd">You are receiving this email because you placed an order, made a booking or requested a sign-in at <a href="' . $u('/') . '" style="' . $l . '">supremeautoparts.co.ke</a>.<br>&copy; ' . esc_html(wp_date('Y')) . ' Standard Driveworks t/a Supreme Autoparts. All rights reserved.</div>'
        . '</td></tr></table>';
}

function sa_email_footer_text(): string
{
    return "\n\n--\nStandard Driveworks t/a Supreme Autoparts\nGround Floor, Midax Plaza, Kandundo Rd, Njiru, Nairobi · P.O. Box 00516-00100 G.P.O Nairobi\n+254 714 498 451 · US +1 917 437 5121 · calvin@supremeautoparts.co.ke\nMon–Fri 8:00–17:00 · Sat 8:00–13:00 (EAT)\n" . home_url('/') . "\nYou are receiving this because of an order, booking or sign-in at supremeautoparts.co.ke.\n© " . wp_date('Y') . " Standard Driveworks t/a Supreme Autoparts";
}

add_filter('wp_mail', static function (array $args): array {
    $msg = (string) ($args['message'] ?? '');
    if ($msg === '' || strpos($msg, 'sa-email-footer') !== false) {
        return $args;
    }
    $to = is_array($args['to']) ? implode(',', $args['to']) : (string) $args['to'];
    $admin = strtolower((string) get_option('admin_email'));
    $subject = (string) ($args['subject'] ?? '');
    // Internal admin notifications ("[Bookings] …", "New order") still get it; harmless.
    $headers = is_array($args['headers']) ? implode("\n", $args['headers']) : (string) $args['headers'];
    $is_html = stripos($headers, 'text/html') !== false || preg_match('/<(html|body|div|table|p)\b/i', $msg);
    if (!$is_html && apply_filters('wp_mail_content_type', 'text/plain') === 'text/html') {
        $is_html = true;
    }
    unset($to, $admin, $subject);
    if ($is_html) {
        $f = sa_email_footer_html();
        $args['message'] = stripos($msg, '</body>') !== false ? preg_replace('#</body>#i', $f . '</body>', $msg, 1) : $msg . $f;
    } else {
        $args['message'] = $msg . sa_email_footer_text();
    }
    return $args;
}, 50);
