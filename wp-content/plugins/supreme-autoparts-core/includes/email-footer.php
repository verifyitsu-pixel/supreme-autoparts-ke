<?php
/**
 * Branded footer appended to every HTML email the site sends (bookings, invoices, reminders,
 * WooCommerce order mails, OTP, password). Plain-text mails get a short text version.
 *
 * Light, adaptive design: transparent/white base, subtle grey type, thin divider, accent links.
 * Injects color-scheme meta + prefers-color-scheme dark styles for Apple Mail / iOS Mail.
 * Avoids solid dark blocks so Gmail forced dark mode keeps readable contrast.
 * No social icons: the site has no social accounts linked.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Absolute HTTPS URL for a theme asset under assets/. */
function sa_email_asset_url(string $file): string
{
    return 'https://www.supremeautoparts.co.ke/wp-content/themes/supreme-autoparts/assets/' . ltrim($file, '/');
}

/**
 * Inline <style> + dual-logo CSS for clients that honour prefers-color-scheme (Apple Mail, iOS).
 */
function sa_email_footer_styles(): string
{
    return '<style type="text/css">'
        . ':root{color-scheme:light dark;supported-color-schemes:light dark;}'
        . '.sa-ef-logo-dark{display:none;max-height:0;overflow:hidden;mso-hide:all;}'
        . '@media (prefers-color-scheme:dark){'
        . '.sa-ef,.sa-ef td{background-color:transparent!important;background:transparent!important;color:#d4d4d8!important;}'
        . '.sa-ef-title{color:#f4f4f5!important;}'
        . '.sa-ef-body{color:#d4d4d8!important;}'
        . '.sa-ef-muted{color:#a1a1aa!important;}'
        . '.sa-ef-rule{border-top-color:#3f3f46!important;}'
        . '.sa-ef a,.sa-ef-link{color:#F5A623!important;}'
        . '.sa-ef-logo-light{display:none!important;max-height:0!important;overflow:hidden!important;mso-hide:all;}'
        . '.sa-ef-logo-dark{display:block!important;max-height:none!important;overflow:visible!important;}'
        . '.sa-ef-logo-dark img{display:block!important;}'
        . '}'
        . '</style>';
}

/**
 * Meta tags for color-scheme awareness (injected into <head> when present).
 */
function sa_email_color_scheme_meta(): string
{
    return '<meta name="color-scheme" content="light dark">'
        . '<meta name="supported-color-schemes" content="light dark">';
}

function sa_email_footer_html(): string
{
    $u = static fn(string $p): string => esc_url(home_url($p));
    $map = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode('Midax Plaza, Kandundo Rd, Njiru, Nairobi');
    // Accent dark enough on white (~amber-700); brightens via CSS in dark mode.
    $a = 'color:#B45309;text-decoration:none;font-weight:600';
    $l = 'color:#B45309;text-decoration:none;font-weight:600';
    $links = static function (array $items) use ($l): string {
        $o = [];
        foreach ($items as $t => $h) {
            $o[] = '<a class="sa-ef-link" href="' . esc_url($h) . '" style="' . $l . '">' . esc_html($t) . '</a>';
        }
        return implode(' &middot; ', $o);
    };

    $logo_light = esc_url(sa_email_asset_url('logo-email.png'));
    $logo_dark = esc_url(sa_email_asset_url('logo-email-dark.png'));

    $logo_block = '<div class="sa-ef-logo-light" style="margin:0 0 12px">'
        . '<img src="' . $logo_light . '" width="120" height="68" alt="Supreme Autoparts" '
        . 'style="display:block;border:0;outline:none;background:transparent;background-color:transparent;max-width:120px;height:auto">'
        . '</div>'
        // Hidden on light; shown under prefers-color-scheme: dark (Apple Mail / iOS).
        . '<!--[if !mso]><!--><div class="sa-ef-logo-dark" style="display:none;margin:0 0 12px;max-height:0;overflow:hidden">'
        . '<img src="' . $logo_dark . '" width="120" height="68" alt="Supreme Autoparts" '
        . 'style="display:block;border:0;outline:none;background:transparent;background-color:transparent;max-width:120px;height:auto">'
        . '</div><!--<![endif]-->';

    return '<!--sa-email-footer-->'
        . sa_email_footer_styles()
        . '<table role="presentation" class="sa-ef" width="100%" cellpadding="0" cellspacing="0" border="0" '
        . 'style="max-width:600px;margin:0 auto;background:transparent;background-color:transparent;'
        . 'font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;'
        . 'color:#6b7280;font-size:13px;line-height:1.6">'
        . '<tr><td class="sa-ef-rule" style="padding:20px 8px 8px;border-top:1px solid #e5e7eb;background:transparent;background-color:transparent">'
        . $logo_block
        . '<div class="sa-ef-title" style="font-size:14px;font-weight:700;color:#374151;margin:0 0 6px">'
        . 'Standard Driveworks t/a Supreme Autoparts</div>'
        . '<div class="sa-ef-body" style="color:#6b7280">'
        . 'Ground Floor, Midax Plaza, Kandundo Rd, Njiru, Nairobi<br>'
        . 'P.O. Box 00516-00100 G.P.O Nairobi &middot; <a class="sa-ef-link" href="' . esc_url($map) . '" style="' . $a . '">Map</a>'
        . '</div>'
        . '<div class="sa-ef-body" style="margin-top:8px;color:#6b7280">'
        . 'Phone / WhatsApp: <a class="sa-ef-link" href="tel:+254714498451" style="' . $a . '">+254 714 498 451</a>'
        . ' &middot; <a class="sa-ef-link" href="https://wa.me/254714498451" style="' . $a . '">WhatsApp</a><br>'
        . 'US: <a class="sa-ef-link" href="tel:+19174375121" style="' . $a . '">+1 917 437 5121</a>'
        . ' &middot; <a class="sa-ef-link" href="https://wa.me/19174375121" style="' . $a . '">WhatsApp</a><br>'
        . 'Email: <a class="sa-ef-link" href="mailto:calvin@supremeautoparts.co.ke" style="' . $a . '">calvin@supremeautoparts.co.ke</a>'
        . '</div>'
        . '<div class="sa-ef-body" style="margin-top:8px;color:#6b7280">Hours: Mon&ndash;Fri 8:00&ndash;17:00 &middot; Sat 8:00&ndash;13:00 (EAT)</div>'
        . '<div class="sa-ef-rule" style="margin-top:14px;padding-top:12px;border-top:1px solid #e5e7eb">'
        . $links([
            'Shop Parts' => home_url('/shop/'),
            'Book a Service' => home_url('/book-service/'),
            'Manage booking' => home_url('/manage-booking/'),
            'My Account' => home_url('/my-account/'),
            'Track order' => home_url('/track-order/'),
        ])
        . '</div>'
        . '<div style="margin-top:6px">'
        . $links([
            'Booking Terms' => home_url('/booking-terms/'),
            'Refund policy' => home_url('/refund-policy/'),
            'Shipping' => home_url('/shipping-policy/'),
            'Privacy' => home_url('/privacy-policy/'),
        ])
        . '</div>'
        . '<div class="sa-ef-muted" style="margin-top:14px;font-size:12px;color:#9ca3af">'
        . 'You are receiving this email because you placed an order, made a booking or requested a sign-in at '
        . '<a class="sa-ef-link" href="' . $u('/') . '" style="' . $l . '">supremeautoparts.co.ke</a>.<br>'
        . '&copy; ' . esc_html(wp_date('Y')) . ' Standard Driveworks t/a Supreme Autoparts. All rights reserved.'
        . '</div>'
        . '</td></tr></table>';
}

function sa_email_footer_text(): string
{
    return "\n\n--\nStandard Driveworks t/a Supreme Autoparts\nGround Floor, Midax Plaza, Kandundo Rd, Njiru, Nairobi · P.O. Box 00516-00100 G.P.O Nairobi\n+254 714 498 451 · US +1 917 437 5121 · calvin@supremeautoparts.co.ke\nMon–Fri 8:00–17:00 · Sat 8:00–13:00 (EAT)\n" . home_url('/') . "\nYou are receiving this because of an order, booking or sign-in at supremeautoparts.co.ke.\n© " . wp_date('Y') . " Standard Driveworks t/a Supreme Autoparts";
}

/**
 * Ensure HTML emails declare light/dark color-scheme support in <head>.
 */
function sa_email_inject_color_scheme_meta(string $html): string
{
    if ($html === '' || stripos($html, 'name="color-scheme"') !== false || stripos($html, "name='color-scheme'") !== false) {
        return $html;
    }
    $meta = sa_email_color_scheme_meta();
    if (preg_match('/<head\b[^>]*>/i', $html)) {
        return (string) preg_replace('/(<head\b[^>]*>)/i', '$1' . $meta, $html, 1);
    }
    // No <head>: prepend a minimal head so capable clients still see the meta.
    if (preg_match('/<html\b[^>]*>/i', $html)) {
        return (string) preg_replace('/(<html\b[^>]*>)/i', '$1<head>' . $meta . '</head>', $html, 1);
    }
    return '<head>' . $meta . '</head>' . $html;
}

add_filter('wp_mail', static function (array $args): array {
    $msg = (string) ($args['message'] ?? '');
    if ($msg === '' || strpos($msg, 'sa-email-footer') !== false) {
        return $args;
    }
    $headers = is_array($args['headers']) ? implode("\n", $args['headers']) : (string) $args['headers'];
    $is_html = stripos($headers, 'text/html') !== false || preg_match('/<(html|body|div|table|p)\b/i', $msg);
    if (!$is_html && apply_filters('wp_mail_content_type', 'text/plain') === 'text/html') {
        $is_html = true;
    }
    if ($is_html) {
        $msg = sa_email_inject_color_scheme_meta($msg);
        $f = sa_email_footer_html();
        $args['message'] = stripos($msg, '</body>') !== false
            ? (string) preg_replace('#</body>#i', $f . '</body>', $msg, 1)
            : $msg . $f;
    } else {
        $args['message'] = $msg . sa_email_footer_text();
    }
    return $args;
}, 50);

// WooCommerce template styles: declare color-scheme + keep footer links readable.
add_filter('woocommerce_email_styles', static function (string $css): string {
    $css .= "\n:root{color-scheme:light dark;supported-color-schemes:light dark;}\n";
    $css .= ".sa-ef a,.sa-ef-link{color:#B45309;}\n";
    $css .= "@media (prefers-color-scheme:dark){.sa-ef,.sa-ef td{background:transparent!important;color:#d4d4d8!important;}"
        . ".sa-ef-title{color:#f4f4f5!important;}.sa-ef-muted{color:#a1a1aa!important;}"
        . ".sa-ef a,.sa-ef-link{color:#F5A623!important;}.sa-ef-rule{border-top-color:#3f3f46!important;}"
        . ".sa-ef-logo-light{display:none!important;}.sa-ef-logo-dark{display:block!important;max-height:none!important;overflow:visible!important;}}\n";
    return $css;
}, 20);
