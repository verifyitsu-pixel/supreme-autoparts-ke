<?php
/**
 * One receipt / invoice design used everywhere: order receipt emails, invoice page + print,
 * PDF attachments (dompdf), booking invoices and /pay receipts.
 * Editorial look: white, black text, amber accent, table-based inline CSS (email-safe).
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const SA_RCPT_INK = '#111111';
const SA_RCPT_MUTED = '#6B6B6B';
const SA_RCPT_LINE = '#E6E6E6';
const SA_RCPT_AMBER = '#F5A623';
const SA_RCPT_FONT = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

/** Break Gmail / iOS / Outlook data detectors on addresses, phones and emails (keeps the text readable). */
function sa_rcpt_nolink(string $s, bool $on = true): string
{
    $h = esc_html($s);
    if (!$on) {
        return $h;
    }
    // Zero-width non-joiner after @ . and every digit stops pattern matching without changing what people see.
    $h = preg_replace('/([@.0-9])/u', '$1&zwnj;', $h);
    return '<span style="color:inherit;text-decoration:none">' . $h . '</span>';
}

/** Lines (plain text) → nolinked html joined with <br>. */
function sa_rcpt_lines(array $lines, bool $nolink = true): string
{
    $out = [];
    foreach ($lines as $l) {
        $l = trim(wp_strip_all_tags((string) $l));
        if ($l !== '') {
            $out[] = sa_rcpt_nolink($l, $nolink);
        }
    }
    return implode('<br>', $out);
}

/** Seller / ships-from block by fulfilment country (KE → Nairobi, else Portland). */
function sa_rcpt_seller(string $cc): array
{
    $cc = strtoupper($cc ?: 'KE');
    if (function_exists('sa_geo_business')) {
        $b = sa_geo_business($cc);
    } else {
        $b = ['street' => 'Ground Floor, Midax Plaza, Kangundo Rd, Njiru', 'city' => 'Nairobi', 'region' => '', 'postal' => '', 'country_name' => 'Kenya', 'phone_display' => '+254 714 498 451', 'email' => 'calvin@supremeautoparts.co.ke', 'zone' => 'KE'];
    }
    $city = $b['zone'] === 'KE' ? $b['city'] . ', ' . $b['country_name'] : $b['city'] . ', ' . $b['region'] . ' ' . $b['postal'];
    return [
        'zone'  => $b['zone'],
        'lines' => ['Supreme Autoparts', $b['street'], $city, $b['phone_display'], $b['email']],
        'paper' => $b['zone'] === 'KE' ? 'A4' : 'letter',
    ];
}

/** Logo src for a mode. PDF + offline use a data URI; email/page use the absolute URL. */
function sa_rcpt_logo(string $mode, bool $dark = false): string
{
    $file = $dark ? 'logo-email-dark.png' : 'logo-email.png';
    if ($mode === 'pdf') {
        $f = get_template_directory() . '/assets/logo-light.png';
        if (is_readable($f)) {
            return 'data:image/png;base64,' . base64_encode((string) file_get_contents($f));
        }
    }
    return 'https://www.supremeautoparts.co.ke/wp-content/themes/supreme-autoparts/assets/' . $file . '?v=r1';
}

/** Fetch a remote image as a data URI for dompdf (short timeout, cached); '' on failure. */
function sa_rcpt_img_data(string $url): string
{
    if ($url === '' || strpos($url, 'data:') === 0) {
        return $url;
    }
    $ck = 'sa_rcpt_img_' . md5($url);
    $c = get_transient($ck);
    if (is_string($c)) {
        return $c;
    }
    $data = '';
    $r = wp_remote_get($url, ['timeout' => 6, 'redirection' => 3]);
    if (!is_wp_error($r) && (int) wp_remote_retrieve_response_code($r) === 200) {
        $body = (string) wp_remote_retrieve_body($r);
        $type = strtolower((string) wp_remote_retrieve_header($r, 'content-type'));
        $im = function_exists('imagecreatefromstring') ? @imagecreatefromstring($body) : false;
        if ($im) {
            // Re-encode as JPEG on white: dompdf handles it everywhere (webp/avif/CMYK safe).
            $w = imagesx($im);
            $h = imagesy($im);
            $s = min(1.0, 240 / max($w, $h));
            $nw = max(1, (int) round($w * $s));
            $nh = max(1, (int) round($h * $s));
            $dst = imagecreatetruecolor($nw, $nh);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
            ob_start();
            imagejpeg($dst, null, 85);
            $data = 'data:image/jpeg;base64,' . base64_encode((string) ob_get_clean());
        } elseif ($body !== '' && (strpos($type, 'jpeg') !== false || strpos($type, 'png') !== false)) {
            $data = 'data:' . $type . ';base64,' . base64_encode($body);
        }
    }
    set_transient($ck, $data, $data === '' ? HOUR_IN_SECONDS : WEEK_IN_SECONDS);
    return $data;
}


/**
 * Country code that decides the "Ships from" seller address + paper size.
 * Prefer order shipping country; store pickup / fitting / virtual → Kenya garage;
 * else billing country; else visitor geo.
 *
 * @param WC_Order $order
 */
function sa_rcpt_seller_cc_for_order($order): string
{
    // Garage / local fulfilment always ships-from Nairobi (pickup, fitting, workshop RO).
    $pickup = (string) $order->get_meta('_sa_pickup') === '1'
        || (method_exists($order, 'has_shipping_method') && $order->has_shipping_method('local_pickup'))
        || (string) $order->get_meta('_sa_pickup_date') !== '';
    $fitting = $order->get_meta('_sa_fit_visit') || (int) $order->get_meta('_sa_fit_booking') > 0
        || (string) $order->get_meta('_sa_ro_id') !== '' || (string) $order->get_meta('_sa_g_ro') !== '';
    if ($pickup || $fitting) {
        return 'KE';
    }

    $ship = '';
    if (method_exists($order, 'has_shipping_address') && $order->has_shipping_address()) {
        $ship = strtoupper(trim((string) $order->get_shipping_country()));
    }
    if ($ship !== '') {
        return $ship;
    }

    // No shipping address (virtual / open-pay / booking fee): billing country, else Kenya store, else visitor geo.
    $bill = strtoupper(trim((string) $order->get_billing_country()));
    if ($bill !== '') {
        // Pure virtual with no ship-to still uses the store that serves that billing country.
        // Store-pickup location is Kenya when billing is KE or unknown.
        return $bill;
    }

    if (function_exists('sa_geo_country')) {
        $geo = strtoupper(trim((string) sa_geo_country()));
        if ($geo !== '') {
            return $geo;
        }
    }
    return 'KE';
}

/**
 * Normalised document data from a Woo order.
 *
 * @param WC_Order $order
 */
function sa_rcpt_order_data($order): array
{
    $build = static function () use ($order): array {
    $cur = $order->get_currency();
    $paid = $order->is_paid() || $order->has_status(['processing', 'completed']);
    $num = (string) $order->get_order_number();
    $is_ro = (string) $order->get_meta('_sa_ro_id') !== '' || (string) $order->get_meta('_sa_g_ro') !== '';
    $items = [];
    foreach ($order->get_items() as $item) {
        $p = is_callable([$item, 'get_product']) ? $item->get_product() : null;
        $qty = max(1, (int) $item->get_quantity());
        $thumb = ($p instanceof WC_Product && function_exists('sa_core_get_product_email_image_url')) ? (string) sa_core_get_product_email_image_url($p, 160) : '';
        $meta = [];
        if ($p instanceof WC_Product && $p->get_sku() !== '') {
            $meta[] = 'Part # ' . $p->get_sku();
        }
        foreach ($item->get_formatted_meta_data('_', true) as $m) {
            $v = trim(wp_strip_all_tags((string) $m->display_value));
            if ($v !== '' && mb_strlen($v) < 80) {
                $meta[] = wp_strip_all_tags((string) $m->display_key) . ': ' . $v;
            }
        }
        $items[] = [
            'thumb' => $thumb,
            'name'  => $item->get_name(),
            'meta'  => $meta,
            'qty'   => (string) $qty,
            'unit'  => wc_price((float) $item->get_subtotal() / $qty, ['currency' => $cur]),
            'total' => wc_price((float) $item->get_subtotal(), ['currency' => $cur]),
        ];
    }
    $totals = [['Subtotal', wc_price((float) $order->get_subtotal(), ['currency' => $cur]), false]];
    if ((float) $order->get_total_discount() > 0) {
        $codes = $order->get_coupon_codes();
        $totals[] = ['Discount' . ($codes ? ' (' . strtoupper(implode(', ', $codes)) . ')' : ''), '−' . wc_price((float) $order->get_total_discount(), ['currency' => $cur]), false];
    }
    $sm = (string) $order->get_shipping_method();
    if ($sm !== '' || (float) $order->get_shipping_total() > 0) {
        $st = (float) $order->get_shipping_total();
        $totals[] = ['Shipping' . ($sm !== '' ? ' · ' . $sm : ''), $st > 0 ? wc_price($st, ['currency' => $cur]) : 'Free', false];
    }
    foreach ($order->get_fees() as $fee) {
        $totals[] = [$fee->get_name(), wc_price((float) $fee->get_total(), ['currency' => $cur]), false];
    }
    foreach ($order->get_tax_totals() as $tax) {
        $totals[] = [$tax->label, $tax->formatted_amount, false];
    }
    $totals[] = [$paid ? 'Total paid' : 'Total due', wc_price((float) $order->get_total(), ['currency' => $cur]) . ($cur === 'KES' ? '' : ' <span style="font-weight:400;color:' . SA_RCPT_MUTED . ';font-size:12px">' . esc_html($cur) . '</span>'), true];
    if ((float) $order->get_total_refunded() > 0) {
        $totals[] = ['Refunded', '−' . wc_price((float) $order->get_total_refunded(), ['currency' => $cur]), false];
    }

    $pay = [];
    $pay[] = ['Status', $paid ? 'Paid' : wc_get_order_status_name($order->get_status())];
    if ($order->get_payment_method_title()) {
        $pay[] = ['Payment method', $order->get_payment_method_title()];
    }
    if ($order->get_date_paid()) {
        $pay[] = ['Paid on', wc_format_datetime($order->get_date_paid(), 'j M Y, g:i a')];
    }
    if ($order->get_transaction_id()) {
        $pay[] = ['Transaction ID', $order->get_transaction_id()];
    }

    $bill = array_filter([
        trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
        $order->get_billing_company(),
        $order->get_billing_address_1(), $order->get_billing_address_2(),
        trim($order->get_billing_city() . ', ' . $order->get_billing_state() . ' ' . $order->get_billing_postcode(), ' ,'),
        (WC()->countries->countries[$order->get_billing_country()] ?? $order->get_billing_country()),
        $order->get_billing_email(), $order->get_billing_phone(),
    ]);
    $ship = [];
    if ($order->has_shipping_address()) {
        $ship = array_filter([
            trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()),
            $order->get_shipping_company(),
            $order->get_shipping_address_1(), $order->get_shipping_address_2(),
            trim($order->get_shipping_city() . ', ' . $order->get_shipping_state() . ' ' . $order->get_shipping_postcode(), ' ,'),
            (WC()->countries->countries[$order->get_shipping_country()] ?? $order->get_shipping_country()),
        ]);
    }

    $doc = $paid ? 'Receipt' : 'Invoice';
    return [
        'doc'      => $doc,
        'title'    => $is_ro ? 'Workshop ' . strtolower($doc) : $doc,
        'number'   => '#' . $num,
        'file_no'  => $num,
        'date'     => wc_format_datetime($order->get_date_created(), 'j M Y'),
        'paid'     => $paid,
        'seller'     => sa_rcpt_seller(sa_rcpt_seller_cc_for_order($order)),
        'seller_lbl' => 'Ships from',
        'bill'       => $bill,
        'ship'       => $ship,
        'ship_lbl'   => 'Ship to',
        'items'    => $items,
        'totals'   => $totals,
        'pay'      => $pay,
        'note'     => (string) $order->get_customer_note(),
        'view_url' => function_exists('sa_core_invoice_url') ? sa_core_invoice_url((int) $order->get_id()) : '',
        'pay_url'  => $paid ? '' : $order->get_checkout_payment_url(),
        'greet'    => function_exists('sa_email_greeting') ? sa_email_greeting($order) : 'Hello,',
        'lead'     => $paid ? 'Thanks for your order. Your receipt is below, and a PDF copy is attached.' : 'Here is the invoice for your order. A PDF copy is attached.',
    ];
    };
    return function_exists('sa_geo_without_conversion')
        ? sa_geo_without_conversion($build)
        : $build();
}

/** Totals rows html. */
function sa_rcpt_totals_html(array $totals, string $font): string
{
    $h = '';
    foreach ($totals as [$label, $value, $bold]) {
        $b = $bold ? 'font-weight:700;font-size:17px;color:' . SA_RCPT_INK . ';border-top:2px solid ' . SA_RCPT_INK . ';padding-top:12px;' : 'font-size:14px;color:#333333;padding-top:6px;';
        $h .= '<tr><td style="font-family:' . $font . ';' . $b . 'padding-bottom:6px;text-align:left">' . esc_html($label) . '</td>'
            . '<td style="font-family:' . $font . ';' . $b . 'padding-bottom:6px;text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums">' . wp_kses_post($value) . '</td></tr>';
    }
    return $h;
}

/** Line items table html. */
function sa_rcpt_items_html(array $items, string $mode, string $font, string $item_lbl = 'Item'): string
{
    $pdf = $mode === 'pdf';
    $th = 'font-family:' . $font . ';font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:' . SA_RCPT_MUTED . ';font-weight:600;padding:0 0 10px;border-bottom:1px solid ' . SA_RCPT_INK . ';';
    $h = '<table role="presentation" class="sa-items" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;width:100%">'
        . '<thead><tr><th colspan="2" style="' . $th . 'text-align:left">' . esc_html($item_lbl) . '</th>'
        . '<th class="sa-hide-sm" style="' . $th . 'text-align:right;width:44px">Qty</th>'
        . '<th class="sa-hide-sm" style="' . $th . 'text-align:right;width:96px">Unit price</th>'
        . '<th style="' . $th . 'text-align:right;width:96px">Amount</th></tr></thead><tbody>';
    foreach ($items as $it) {
        $td = 'font-family:' . $font . ';padding:14px 0;border-bottom:1px solid ' . SA_RCPT_LINE . ';vertical-align:top;';
        $src = (string) ($it['thumb'] ?? '');
        if ($pdf && $src !== '') {
            $src = sa_rcpt_img_data($src);
        }
        $img = $src !== ''
            ? '<img src="' . ($pdf ? esc_attr($src) : esc_url($src)) . '" width="56" height="56" alt=""' . ($mode === 'page' ? ' onerror="this.style.visibility=\'hidden\'"' : '') . ' style="display:block;width:56px;height:56px;object-fit:contain;border:1px solid ' . SA_RCPT_LINE . ';background:#ffffff">'
            : '<div style="width:56px;height:56px;border:1px solid ' . SA_RCPT_LINE . ';background:#F7F7F7"></div>';
        $meta = '';
        foreach ((array) ($it['meta'] ?? []) as $m) {
            $meta .= '<div style="font-size:12px;color:' . SA_RCPT_MUTED . ';line-height:1.45;margin-top:2px">' . esc_html($m) . '</div>';
        }
        $sm = '<div class="sa-show-sm" style="display:none;mso-hide:all;max-height:0;overflow:hidden;font-size:12px;color:' . SA_RCPT_MUTED . ';margin-top:4px">' . esc_html($it['qty']) . ' × ' . wp_kses_post($it['unit']) . '</div>';
        $h .= '<tr class="sa-row">'
            . '<td style="' . $td . 'width:68px;padding-right:12px">' . $img . '</td>'
            . '<td style="' . $td . 'font-size:14px;line-height:1.45;color:' . SA_RCPT_INK . '"><div style="font-weight:600">' . esc_html($it['name']) . '</div>' . $meta . ($pdf ? '' : $sm) . '</td>'
            . '<td class="sa-hide-sm" style="' . $td . 'font-size:14px;text-align:right;font-variant-numeric:tabular-nums;color:#333333">' . esc_html($it['qty']) . '</td>'
            . '<td class="sa-hide-sm" style="' . $td . 'font-size:14px;text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;color:#333333">' . wp_kses_post($it['unit']) . '</td>'
            . '<td style="' . $td . 'font-size:14px;text-align:right;white-space:nowrap;font-weight:600;font-variant-numeric:tabular-nums;color:' . SA_RCPT_INK . '">' . wp_kses_post($it['total']) . '</td></tr>';
    }
    return $h . '</tbody></table>';
}

/**
 * Render the full document.
 * $mode: 'email' (inline, responsive, auto-link safe), 'page' (screen + print toolbar), 'pdf' (dompdf).
 */
function sa_rcpt_render(array $d, string $mode = 'page', array $toolbar = []): string
{
    $pdf = $mode === 'pdf';
    $email = $mode === 'email';
    $nl = !$pdf;
    $font = $pdf ? "'DejaVu Sans',Helvetica,Arial,sans-serif" : SA_RCPT_FONT;
    $lbl = 'font-family:' . $font . ';font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:' . SA_RCPT_MUTED . ';font-weight:600;margin:0 0 6px';
    $txt = 'font-family:' . $font . ';font-size:14px;line-height:1.55;color:' . SA_RCPT_INK;
    $pad = $pdf ? '0' : '32px';
    $logo = sa_rcpt_logo($mode);
    $logo_dark = sa_rcpt_logo($mode, true);
    $status = $d['paid']
        ? '<span style="font-family:' . $font . ';display:inline-block;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:' . SA_RCPT_INK . ';border:1px solid ' . SA_RCPT_INK . ';padding:3px 8px">Paid</span>'
        : '<span class="sa-due" style="font-family:' . $font . ';display:inline-block;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:' . SA_RCPT_INK . ';background:' . SA_RCPT_AMBER . ';padding:4px 9px">Payment due</span>';

    $css = '';
    if (!$pdf) {
        $css = '<style>'
            . ':root{color-scheme:light dark;supported-color-schemes:light dark}'
            . 'a[x-apple-data-detectors],.sa-doc a[href^="tel"],.sa-doc a[href^="mailto"]{color:inherit!important;text-decoration:none!important;font-size:inherit!important;font-family:inherit!important;font-weight:inherit!important;line-height:inherit!important}'
            . 'u + #body a{color:inherit;text-decoration:none}'
            . '.sa-dark-logo{display:none!important;mso-hide:all}'
            . '@media only screen and (max-width:620px){'
            . '.sa-pad{padding-left:18px!important;padding-right:18px!important}'
            . '.sa-col{display:block!important;width:100%!important;text-align:left!important;padding:0 0 18px!important}'
            . '.sa-hide-sm{display:none!important}'
            . '.sa-show-sm{display:block!important;max-height:none!important;overflow:visible!important}'
            . '.sa-totals{width:100%!important}'
            . '.sa-logo{width:220px!important}'
            . '}'
            . '@media (prefers-color-scheme:dark){'
            . '.sa-bg{background:#121212!important}.sa-doc{background:#1B1B1B!important}.sa-doc,.sa-doc td,.sa-doc div,.sa-doc th{color:#EDEDED!important;border-color:#3A3A3A!important}.sa-doc span,.sa-doc a{color:#EDEDED!important;border-color:#EDEDED!important}.sa-doc .sa-due{color:#111111!important}'
            . '.sa-light-logo{display:none!important}.sa-dark-logo{display:block!important}'
            . '}'
            . '[data-ogsb] .sa-doc{background:#1B1B1B!important}[data-ogsc] .sa-light-logo{display:none!important}[data-ogsc] .sa-dark-logo{display:block!important}'
            . '@page{margin:14mm}'
            . '@media print{body,.sa-bg{background:#ffffff!important}.sa-noprint{display:none!important}.sa-doc{max-width:none!important;border:0!important}'
            . '.sa-pad{padding-left:0!important;padding-right:0!important}.sa-row,.sa-keep{page-break-inside:avoid;break-inside:avoid}thead{display:table-header-group}'
            . '.sa-doc,.sa-doc td,.sa-doc div,.sa-doc th{color:#111111!important}.sa-light-logo{display:block!important}.sa-dark-logo{display:none!important}a{text-decoration:none!important;color:#111111!important}}'
            . '</style>';
    } else {
        $css = '<style>@page{margin:16mm 14mm}body{margin:0;font-family:"DejaVu Sans",sans-serif}.sa-row{page-break-inside:avoid}</style>';
    }

    $title = esc_html($d['title'] . ' ' . $d['number'] . ' · Supreme Autoparts');
    $h = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="format-detection" content="telephone=no,address=no,email=no,date=no"><meta name="x-apple-disable-message-reformatting">'
        . '<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark"><meta name="robots" content="noindex">'
        . '<title>' . $title . '</title>' . $css . '</head>'
        . '<body id="body" class="sa-bg" style="margin:0;padding:0;background:' . ($pdf ? '#ffffff' : '#F4F4F2') . ';-webkit-text-size-adjust:100%;text-size-adjust:100%">';

    if ($email) {
        $h .= '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all">' . esc_html($d['doc'] . ' ' . $d['number'] . ' from Supreme Autoparts') . '</div>';
    }
    if ($mode === 'page' && $toolbar) {
        $btn = 'font-family:' . $font . ';display:inline-block;font-size:14px;font-weight:600;text-decoration:none;padding:10px 16px;margin:0 6px 6px 0;border:1px solid ' . SA_RCPT_INK . ';cursor:pointer;';
        $h .= '<div class="sa-noprint" style="max-width:720px;margin:0 auto;padding:16px 16px 0">';
        foreach ($toolbar as $t) {
            $style = $btn . (!empty($t['primary']) ? 'background:' . SA_RCPT_INK . ';color:#ffffff' : 'background:#ffffff;color:' . SA_RCPT_INK);
            $h .= !empty($t['print'])
                ? '<button type="button" onclick="window.print()" style="' . $style . '">' . esc_html($t['label']) . '</button>'
                : '<a href="' . esc_url($t['url']) . '" style="' . $style . '">' . esc_html($t['label']) . '</a>';
        }
        $h .= '</div>';
    }

    $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="sa-bg" style="background:' . ($pdf ? '#ffffff' : '#F4F4F2') . '"><tr><td align="center" style="padding:' . ($pdf ? '0' : '20px 10px') . '">'
        . '<table role="presentation" class="sa-doc" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:' . ($pdf ? 'none' : '680px') . ';background:#ffffff;' . ($pdf ? '' : 'border:1px solid ' . SA_RCPT_LINE . ';') . '">';

    // Amber rule + header.
    $h .= '<tr><td style="height:4px;line-height:4px;font-size:0;background:' . SA_RCPT_AMBER . '">&nbsp;</td></tr>';
    $h .= '<tr><td class="sa-pad" style="padding:28px ' . $pad . ' 22px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
        . '<td class="sa-col" style="vertical-align:middle;width:55%">'
        . '<img class="sa-logo sa-light-logo" src="' . ($pdf ? esc_attr($logo) : esc_url($logo)) . '" width="280" alt="Supreme Autoparts" style="display:block;width:280px;max-width:100%;height:auto;border:0">'
        . ($pdf ? '' : '<!--[if !mso]><!--><img class="sa-logo sa-dark-logo" src="' . esc_url($logo_dark) . '" width="280" alt="" style="display:none;width:280px;max-width:100%;height:auto;border:0"><!--<![endif]-->')
        . '</td><td class="sa-col" style="vertical-align:middle;text-align:right;width:45%">'
        . '<div style="font-family:' . $font . ';font-size:26px;line-height:1.1;font-weight:700;color:' . SA_RCPT_INK . ';letter-spacing:-.01em">' . esc_html($d['title']) . '</div>'
        . '<div style="font-family:' . $font . ';font-size:14px;color:' . SA_RCPT_MUTED . ';margin-top:6px;font-variant-numeric:tabular-nums">' . esc_html($d['number']) . ' &nbsp;·&nbsp; ' . esc_html($d['date']) . '</div>'
        . '<div style="margin-top:10px">' . $status . '</div>'
        . '</td></tr></table></td></tr>';

    if ($email && !empty($d['greet'])) {
        $h .= '<tr><td class="sa-pad" style="padding:0 ' . $pad . ' 22px;' . $txt . '"><div style="font-size:16px;font-weight:600;margin-bottom:4px">' . $d['greet'] . '</div>'
            . '<div style="color:#333333">' . esc_html((string) $d['lead']) . '</div></td></tr>';
    }

    // Parties.
    $cols = [[$d['seller_lbl'] ?? 'Ships from', sa_rcpt_lines($d['seller']['lines'], $nl)], [$d['bill_lbl'] ?? 'Bill to', sa_rcpt_lines($d['bill'], $nl)]];
    if (!empty($d['ship'])) {
        $cols[] = [$d['ship_lbl'] ?? 'Ship to', sa_rcpt_lines($d['ship'], $nl)];
    }
    $w = (int) floor(100 / count($cols));
    $h .= '<tr><td class="sa-pad" style="padding:22px ' . $pad . ' 6px;border-top:1px solid ' . SA_RCPT_LINE . '"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="sa-keep"><tr>';
    foreach ($cols as $i => [$label, $body]) {
        $h .= '<td class="sa-col" style="vertical-align:top;width:' . $w . '%;padding:0 ' . ($i < count($cols) - 1 ? '16px' : '0') . ' 16px 0"><div style="' . $lbl . '">' . esc_html($label) . '</div><div style="' . $txt . '">' . $body . '</div></td>';
    }
    $h .= '</tr></table></td></tr>';

    // Items + totals.
    $h .= '<tr><td class="sa-pad" style="padding:14px ' . $pad . ' 8px">' . sa_rcpt_items_html($d['items'], $mode, $font, (string) ($d['item_lbl'] ?? 'Item')) . '</td></tr>';
    $h .= '<tr><td class="sa-pad" style="padding:8px ' . $pad . ' 20px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td class="sa-hide-sm" style="width:40%">&nbsp;</td><td>'
        . '<table role="presentation" class="sa-totals sa-keep" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse">' . sa_rcpt_totals_html($d['totals'], $font) . '</table></td></tr></table></td></tr>';

    // Payment details.
    if (!empty($d['pay'])) {
        $h .= '<tr><td class="sa-pad sa-keep" style="padding:4px ' . $pad . ' 22px"><div style="' . $lbl . '">Payment</div><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse">';
        foreach ($d['pay'] as [$k, $v]) {
            $h .= '<tr><td style="font-family:' . $font . ';font-size:13px;color:' . SA_RCPT_MUTED . ';padding:3px 12px 3px 0;width:38%;vertical-align:top">' . esc_html($k) . '</td><td style="font-family:' . $font . ';font-size:13px;color:' . SA_RCPT_INK . ';padding:3px 0;word-break:break-all">' . sa_rcpt_nolink((string) $v, $nl) . '</td></tr>';
        }
        $h .= '</table></td></tr>';
    }
    if (!empty($d['extra_html'])) {
        $h .= '<tr><td class="sa-pad" style="padding:0 ' . $pad . ' 20px;' . $txt . '">' . $d['extra_html'] . '</td></tr>';
    }
    if (!empty($d['note'])) {
        $h .= '<tr><td class="sa-pad sa-keep" style="padding:0 ' . $pad . ' 20px"><div style="' . $lbl . '">Note</div><div style="' . $txt . '">' . nl2br(esc_html($d['note'])) . '</div></td></tr>';
    }
    if (!$pdf && !empty($d['pay_url'])) {
        $h .= '<tr><td class="sa-pad sa-noprint" style="padding:0 ' . $pad . ' 24px"><a href="' . esc_url($d['pay_url']) . '" style="font-family:' . $font . ';display:inline-block;background:' . SA_RCPT_INK . ';color:#ffffff;text-decoration:none;font-weight:700;font-size:15px;padding:13px 24px">Pay now</a></td></tr>';
    }

    // Footer.
    $foot = $email
        ? 'Thank you for choosing Supreme Autoparts. Please quote ' . $d['number'] . ' if you contact us.'
        : 'Thank you for choosing Supreme Autoparts. Questions about this ' . strtolower($d['doc']) . '? Reply to this email or write to ' . 'calvin@supremeautoparts.co.ke' . ' and quote ' . $d['number'] . '.';
    $h .= '<tr><td class="sa-pad" style="padding:18px ' . $pad . ' 26px;border-top:1px solid ' . SA_RCPT_LINE . ';font-family:' . $font . ';font-size:12px;line-height:1.6;color:' . SA_RCPT_MUTED . '">'
        . sa_rcpt_nolink($foot, $nl)
        . ($email && !empty($d['view_url']) ? '<br><a href="' . esc_url($d['view_url']) . '" style="color:' . SA_RCPT_INK . ';text-decoration:underline">View or print online</a>' : '')
        . '<br>www.supremeautoparts.co.ke</td></tr>';

    $h .= '</table></td></tr></table></body></html>';
    if ($pdf) {
        // Tighter print density so typical orders fit one A4 / Letter page.
        $h = strtr($h, ['font-size:14px' => 'font-size:11.5px', 'line-height:1.55' => 'line-height:1.4', 'line-height:1.45' => 'line-height:1.35', 'padding:14px 0' => 'padding:9px 0', 'font-size:26px' => 'font-size:24px', 'padding:22px 0 6px' => 'padding:16px 0 2px', 'padding:28px 0 22px' => 'padding:22px 0 18px']);
    }
    return $h;
}

/* ---------------- PDF ---------------- */

function sa_rcpt_dompdf_ready(): bool
{
    if (class_exists('\\Dompdf\\Dompdf')) {
        return true;
    }
    foreach ([
        '/usr/src/sa-lib/dompdf/autoload.inc.php',
        SA_CORE_DIR . 'lib/dompdf/autoload.inc.php',
        '/tmp/dompdf/autoload.inc.php',
    ] as $f) {
        if (is_readable($f)) {
            require_once $f;
            return class_exists('\\Dompdf\\Dompdf');
        }
    }
    return false;
}

/** Render data → PDF bytes ('' when dompdf is missing or fails). */
function sa_rcpt_pdf_bytes(array $d): string
{
    if (!sa_rcpt_dompdf_ready()) {
        return '';
    }
    try {
        $tmp = (function_exists('get_temp_dir') ? get_temp_dir() : sys_get_temp_dir() . '/') . 'sa-dompdf';
        if (!is_dir($tmp)) {
            @mkdir($tmp, 0775, true);
        }
        $opt = new \Dompdf\Options();
        $opt->set('isRemoteEnabled', false); // images are pre-fetched as data URIs
        $opt->set('defaultFont', 'DejaVu Sans');
        $opt->set('tempDir', $tmp);
        $opt->set('isHtml5ParserEnabled', true);
        $pdf = new \Dompdf\Dompdf($opt);
        $pdf->loadHtml(sa_rcpt_render($d, 'pdf'), 'UTF-8');
        $pdf->setPaper($d['seller']['paper'] ?? 'A4', 'portrait');
        $pdf->render();
        $canvas = $pdf->getCanvas();
        $fm = $pdf->getFontMetrics();
        $font = $fm->getFont('DejaVu Sans');
        $label = 'Supreme Autoparts · ' . $d['title'] . ' ' . $d['number'] . ' · Page {PAGE_NUM} of {PAGE_COUNT}';
        $canvas->page_text(40, $canvas->get_height() - 28, $label, $font, 7, [0.42, 0.42, 0.42]);
        return (string) $pdf->output();
    } catch (\Throwable $e) {
        error_log('[sa-receipt] PDF failed: ' . $e->getMessage());
        return '';
    }
}

function sa_rcpt_pdf_name(array $d): string
{
    return 'Supreme-Autoparts-' . str_replace(' ', '-', ucwords($d['title'])) . '-' . preg_replace('/[^A-Za-z0-9-]/', '', (string) ($d['file_no'] ?? $d['number'])) . '.pdf';
}

/** Write a PDF to a unique temp file; returns path or ''. Caller deletes it. */
function sa_rcpt_pdf_tempfile(array $d): string
{
    $bytes = sa_rcpt_pdf_bytes($d);
    if ($bytes === '') {
        return '';
    }
    $dir = trailingslashit(function_exists('get_temp_dir') ? get_temp_dir() : sys_get_temp_dir()) . 'sa-rcpt-' . wp_generate_password(10, false);
    if (!@mkdir($dir, 0700, true)) {
        return '';
    }
    $f = $dir . '/' . sa_rcpt_pdf_name($d);
    return @file_put_contents($f, $bytes) !== false ? $f : '';
}

function sa_rcpt_cleanup(string $f): void
{
    if ($f !== '' && is_file($f)) {
        @unlink($f);
        @rmdir(dirname($f));
    }
}

/**
 * PDF for a Woo order → temp path. Used by every email that carries an order receipt/invoice.
 *
 * @param WC_Order $order
 */
function sa_rcpt_order_pdf($order): string
{
    return $order instanceof WC_Order ? sa_rcpt_pdf_tempfile(sa_rcpt_order_data($order)) : '';
}

/* Attach the PDF receipt/invoice to WooCommerce customer order emails. */
$GLOBALS['sa_rcpt_tmp'] = [];
add_filter('woocommerce_email_attachments', static function ($att, $email_id, $object = null) {
    $want = ['customer_processing_order', 'customer_completed_order', 'customer_invoice', 'customer_on_hold_order', 'customer_refunded_order', 'customer_partially_refunded_order'];
    if (!is_array($att) || !in_array($email_id, $want, true) || !$object instanceof WC_Order) {
        return $att;
    }
    $f = sa_rcpt_order_pdf($object);
    if ($f !== '') {
        $att[] = $f;
        $GLOBALS['sa_rcpt_tmp'][] = $f;
    }
    return $att;
}, 20, 3);
add_action('wp_mail_succeeded', static function (): void {
    foreach ($GLOBALS['sa_rcpt_tmp'] as $f) {
        sa_rcpt_cleanup($f);
    }
    $GLOBALS['sa_rcpt_tmp'] = [];
});
add_action('wp_mail_failed', static function (): void {
    foreach ($GLOBALS['sa_rcpt_tmp'] as $f) {
        sa_rcpt_cleanup($f);
    }
    $GLOBALS['sa_rcpt_tmp'] = [];
});
add_action('shutdown', static function (): void {
    foreach ($GLOBALS['sa_rcpt_tmp'] ?? [] as $f) {
        sa_rcpt_cleanup($f);
    }
});

/* ?sa_invoice=ID&key=..&format=pdf → download the PDF (access is checked in invoices.php before this runs). */
function sa_rcpt_send_pdf_download(array $d): void
{
    $bytes = sa_rcpt_pdf_bytes($d);
    if ($bytes === '') {
        return;
    }
    nocache_headers();
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . (!empty($_GET['inline']) ? 'inline' : 'attachment') . '; filename="' . sa_rcpt_pdf_name($d) . '"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes; // phpcs:ignore
    exit;
}

