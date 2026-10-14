<?php
/**
 * Hold request copy + shared order/payment summary + email shell.
 * Tone: calm, specific, never accusatory (payment-processor style).
 */
declare(strict_types=1);
if (!defined('ABSPATH')) {
    exit;
}

/** Per item: [why we need it, exactly how]. */
function sa_hr_item_guide(): array
{
    return [
        'id_front'      => ['Confirms the person placing the order is the payment owner.', 'Photograph the front of your national ID, passport or driving licence on a flat surface. All four corners in view, text sharp, no glare.'],
        'id_back'       => ['Some IDs carry key details on the back.', 'Photograph the back of the same ID the same way.'],
        'selfie_id'     => ['Matches the ID to you, so nobody else can use your details.', 'Hold the ID next to your face in good light. Your face and the ID photo must both be clear.'],
        'card_front'    => ['Shows you hold the card used for this payment.', 'Cover the middle digits with paper so only the first 6 and last 4 show, or just the last 4. Your name and the last 4 digits must be readable.'],
        'card_back'     => ['Confirms the card is signed and in your possession.', 'Cover the 3-digit security code (CVV) completely with paper. Never send us the CVV.'],
        'pay_proof'     => ['Lets us match your payment to this order.', 'Screenshot the M-Pesa message, bank app or statement line showing the amount, date and reference.'],
        'pay_ref'       => ['Lets us find your payment quickly.', 'Type the M-Pesa code or bank reference, e.g. SJK4XXXXXX.'],
        'addr_proof'    => ['Confirms the billing address on the order.', 'A utility bill or bank statement from the last 3 months showing your name and address. You may hide account numbers and amounts.'],
        'billing_phone' => ['Your bank or M-Pesa links this number to the payment.', 'Type the phone number registered to the card or M-Pesa account used.'],
        'contact'       => ['We could not reach you on the details we have.', 'Type a phone number and email you check often.'],
        'address_fix'   => ['The courier could not confirm the delivery address.', 'Enter the full address exactly as the courier should use it.'],
        'landmark'      => ['Helps the rider find you first time.', 'Building name, floor, gate colour, or a nearby shop or stage.'],
        'recipient'     => ['The courier needs someone to hand the parcel to.', 'Name and phone number of the person who will receive it.'],
        'customs_docs'  => ['Your country\'s customs office requires these to release the parcel.', 'Upload the documents customs asked for, such as an import permit or ID. A clear photo or PDF is fine.'],
        'tax_id'        => ['Customs uses this to clear the parcel in your name.', 'Type the tax number customs asked for (e.g. KRA PIN), or "not required".'],
        'remote_fee'    => ['Your address is outside the courier\'s standard area.', 'Choose how you would like us to continue.'],
        'reschedule'    => ['So the courier comes when someone is there.', 'Pick a day someone will be available to receive the parcel.'],
        'redelivery'    => ['The last delivery attempt did not go through.', 'Choose how you would like us to deliver.'],
        'pickup_plan'   => ['We are holding your order at the store.', 'Pick the day you plan to collect it.'],
        'vin'           => ['Confirms the exact part for your vehicle before it ships.', 'The 17-character number on your logbook or the plate at the bottom of the windscreen.'],
        'logbook'       => ['Shows make, model, year and chassis number.', 'Photograph the logbook page with the vehicle details. You may cover the owner\'s ID number.'],
        'vehicle_photo' => ['Helps us confirm the model and trim.', 'A full photo of the front or side of the vehicle.'],
        'old_part'      => ['The part number tells us exactly what fits.', 'Photograph the old part, including any numbers stamped or printed on it.'],
        'part_number'   => ['Lets us match the part exactly.', 'Type the number printed on the old part, if you can read it.'],
        'stock_choice'  => ['An item in your order is temporarily out of stock.', 'Choose what you would like us to do.'],
        'quote_approve' => ['The final price changed and we need your OK before continuing.', 'Review the updated amount in the message above and choose.'],
    ];
}

/**
 * Reason copy: subject, preheader, headline, reason (why paused), verify (what we need to verify), cta, review.
 * Placeholders: {n} order no., {card} "Visa ending 4242" / payment method, {amount} "KES 12,500".
 */
function sa_hr_copy(string $reason): array
{
    $c = [
        'fraud' => ['Quick security check for order #{n}', 'A routine check to protect you and your card. It takes about 2 minutes.', 'Help us confirm it\'s you',
            'To protect you and your card, we run a routine security check on some orders before they ship. Your order is paused until it\'s complete.',
            'that you are the authorised holder of the {card} used for this {amount} payment.', 'Verify my order', 'usually within 1 business day of receiving your details'],
        'payment' => ['Action needed: confirm your payment for order #{n}', 'We need to match your {amount} payment to your order before we continue.', 'Let\'s confirm your payment',
            'We have not yet been able to match your payment to this order, so it\'s paused until we can.',
            'that the {amount} payment by {card} belongs to this order.', 'Confirm my payment', 'usually within 1 business day of receiving your details'],
        'address' => ['Please confirm your delivery address for order #{n}', 'The courier needs a complete address before your parcel can go out.', 'Please confirm your delivery address',
            'The courier could not confirm the delivery address on this order, so it\'s paused before dispatch.',
            'that the delivery address for this order is correct and complete.', 'Confirm my address', 'usually the same or next business day'],
        'customs' => ['Customs documents needed for order #{n}', 'Your parcel is ready. Customs needs a few documents before it can be released.', 'Customs needs a few documents',
            'International parcels must clear customs, and your destination needs a few details from you first.',
            'the documents customs needs to release your parcel to you.', 'Upload my documents', 'usually within 1 business day of receiving your documents'],
        'remote' => ['Your approval needed for delivery of order #{n}', 'Your address is outside the standard delivery area. Choose how to continue.', 'Your address needs a different delivery option',
            'Your delivery address is outside the courier\'s standard area, which carries an extra charge. We won\'t charge anything without your OK.',
            'how you would like us to deliver this order.', 'Choose a delivery option', 'usually the same business day'],
        'delivery' => ['Let\'s rearrange delivery for order #{n}', 'The courier could not complete delivery. Choose a new day or option.', 'Let\'s rearrange your delivery',
            'The courier was not able to complete delivery, so your parcel is safely on hold.',
            'when and where we should deliver this order.', 'Rearrange delivery', 'usually the same or next business day'],
        'pickup' => ['Your order #{n} is waiting for you', 'Your order is ready at our store. Let us know when you\'ll collect it.', 'Your order is ready to collect',
            'Your order is ready at our store and has not been collected yet. We\'re holding it for you.',
            'when you plan to collect this order.', 'Choose a pickup day', 'we\'ll confirm the same business day'],
        'fitment' => ['Quick fitment check for order #{n}', 'A few vehicle details make sure the part fits first time.', 'Let\'s make sure it fits',
            'Before we ship, we double-check that the parts fit your exact vehicle. This avoids returns and delays.',
            'that the parts in this order fit your vehicle.', 'Confirm my vehicle', 'usually within 1 business day of receiving your details'],
        'stock' => ['An item in order #{n} is temporarily out of stock', 'Choose whether to wait, take an alternative or get a refund.', 'An item is temporarily out of stock',
            'One or more items is temporarily out of stock, so we\'ve paused the order rather than ship it incomplete.',
            'how you would like us to handle the unavailable item.', 'Choose an option', 'we\'ll act on your choice the same or next business day'],
        'quote' => ['Your approval needed for order #{n}', 'The final price changed. Review it before we continue.', 'Please review the updated price',
            'The final price for this order is different from the original, and we never continue without your approval.',
            'that you approve the updated price for this order.', 'Review the price', 'we\'ll continue as soon as you reply'],
        'contact' => ['Please confirm your contact details for order #{n}', 'We tried to reach you about your order. Please confirm the best way to contact you.', 'We need to reach you',
            'We tried to reach you about this order and couldn\'t get through, so it\'s paused for now.',
            'the best phone number and email to reach you.', 'Confirm my details', 'usually the same business day'],
        'other' => ['Action needed for order #{n}', 'We need a little information from you to continue with your order.', 'We need a few details',
            'We need a little information from you before we can continue with this order.',
            'the details listed below.', 'Send my details', 'usually within 1 business day of receiving your details'],
    ];
    $x = $c[$reason] ?? $c['other'];
    return array_combine(['subject', 'pre', 'head', 'reason', 'verify', 'cta', 'review'], $x);
}

/** Payment facts (never invented; card = brand + last 4 only). */
function sa_hr_pay(WC_Order $o): array
{
    $cur = $o->get_currency();
    $brand = trim((string) ($o->get_meta('_whop_card_brand') ?: $o->get_meta('_sa_gateway_card_type') ?: $o->get_meta('_card_brand') ?: $o->get_meta('_stripe_card_brand')));
    $last4 = substr(preg_replace('/\D+/', '', (string) ($o->get_meta('_whop_card_last4') ?: $o->get_meta('_sa_gateway_pan_last4') ?: $o->get_meta('_card_last4') ?: $o->get_meta('_stripe_card_last4'))), -4);
    $method = trim(preg_replace('/\s+[–-]\s+\S+\s+\*{2,}\d{4}$/u', '', (string) $o->get_payment_method_title()));
    $card = '';
    if (strlen($last4) === 4) {
        $card = ($brand !== '' ? ucfirst(strtolower($brand)) : 'Card') . ' ending ' . $last4;
    }
    $t = (float) $o->get_total();
    // Always order currency (never geo/WCPBC-equivalent display conversion).
    if (function_exists('wc_price')) {
        $amt = html_entity_decode(wp_strip_all_tags(wc_price($t, ['currency' => $cur])), ENT_QUOTES, 'UTF-8');
    } else {
        $amt = $cur . ' ' . number_format($t, (in_array($cur, ['KES', 'UGX', 'TZS', 'JPY'], true) || floor($t) == $t) ? 0 : 2);
    }
    return [
        'card'   => $card,
        'method' => $method,
        'label'  => $card !== '' ? $card : ($method !== '' ? $method : 'payment method used'),
        'amount' => $amt,
        'cur'    => $cur,
        'date'   => $o->get_date_paid() ? wc_format_datetime($o->get_date_paid(), 'j M Y, g:i a') : '',
        'txn'    => (string) ($o->get_transaction_id() ?: $o->get_meta('_sa_gateway_tx_id')),
    ];
}

/** Mask a street line: keep first 3 characters of each word >3 chars. */
function sa_hr_mask(string $s): string
{
    return trim(preg_replace_callback('/\S{4,}/u', static fn($m) => mb_substr($m[0], 0, 2) . str_repeat('•', min(4, mb_strlen($m[0]) - 2)), $s));
}

function sa_hr_contact(WC_Order $o): array
{
    $cc = function_exists('sa_rcpt_seller_cc_for_order') ? sa_rcpt_seller_cc_for_order($o) : ($o->get_shipping_country() ?: $o->get_billing_country());
    $b = function_exists('sa_geo_business') ? sa_geo_business(strtoupper($cc ?: 'KE')) : ['phone_display' => '+254 714 498 451', 'email' => 'calvin@supremeautoparts.co.ke', 'zone' => 'KE'];
    return ['phone' => $b['phone_display'], 'email' => $b['email'], 'zone' => $b['zone']];
}

function sa_hr_fill(string $s, WC_Order $o, array $pay): string
{
    return strtr($s, ['{n}' => $o->get_order_number(), '{card}' => $pay['label'], '{amount}' => $pay['amount']]);
}

/** Order + payment summary block, inline-styled (email + page). */
function sa_hr_summary_html(WC_Order $o, bool $email = true): string
{
    $build = static function () use ($o, $email): string {
    $F = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
    $nl = static fn(string $s): string => function_exists('sa_rcpt_nolink') ? sa_rcpt_nolink($s) : esc_html($s);
    $pay = sa_hr_pay($o);
    $lab = 'font-family:' . $F . ';font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#6b6b6b;font-weight:600;padding:0 0 6px';
    $h = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e6e6e6;border-radius:8px;border-collapse:separate;margin:18px 0"><tr><td style="padding:16px 18px">';
    $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
        . '<td style="font-family:' . $F . ';font-size:16px;font-weight:700;color:#111">Order ' . $nl('#' . $o->get_order_number()) . '</td>'
        . '<td style="font-family:' . $F . ';font-size:13px;color:#6b6b6b;text-align:right">Placed ' . $nl(wc_format_datetime($o->get_date_created(), 'j M Y')) . '</td></tr></table>';
    if (function_exists('sa_rcpt_order_data')) {
        $d = sa_rcpt_order_data($o);
        $h .= '<div style="margin-top:10px">' . sa_rcpt_items_html($d['items'], 'email', $F) . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:8px;border-collapse:collapse">' . sa_rcpt_totals_html($d['totals'], $F) . '</table>';
    }
    $rows = [['Amount', $pay['amount']]];
    if ($pay['date'] !== '') {
        $rows[] = ['Paid on', $pay['date']];
    }
    if ($pay['method'] !== '') {
        $rows[] = ['Method', $pay['method']];
    }
    if ($pay['card'] !== '') {
        $rows[] = ['Card', $pay['card']];
    }
    if ($pay['txn'] !== '') {
        $rows[] = ['Transaction ID', $pay['txn']];
    }
    $kv = '';
    foreach ($rows as [$k, $v]) {
        $kv .= '<tr><td style="font-family:' . $F . ';font-size:13px;color:#6b6b6b;padding:3px 12px 3px 0;white-space:nowrap;vertical-align:top">' . esc_html($k) . '</td><td style="font-family:' . $F . ';font-size:13px;color:#111;padding:3px 0;word-break:break-all">' . $nl($v) . '</td></tr>';
    }
    $billname = trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name());
    $bill = array_filter([$billname, sa_hr_mask((string) $o->get_billing_address_1()), trim($o->get_billing_city() . ', ' . $o->get_billing_state(), ' ,'), WC()->countries->countries[$o->get_billing_country()] ?? $o->get_billing_country()]);
    $ship = $o->has_shipping_address() ? array_filter([trim($o->get_shipping_first_name() . ' ' . $o->get_shipping_last_name()), $o->get_shipping_address_1(), $o->get_shipping_address_2(), trim($o->get_shipping_city() . ', ' . $o->get_shipping_state() . ' ' . $o->get_shipping_postcode(), ' ,'), WC()->countries->countries[$o->get_shipping_country()] ?? $o->get_shipping_country()]) : [];
    $lines = static fn(array $a): string => implode('<br>', array_map($nl, $a));
    $col = 'display:inline-block;width:100%;max-width:262px;vertical-align:top;padding:14px 0 0';
    $h .= '<div style="border-top:1px solid #e6e6e6;margin-top:12px;font-size:0">'
        . '<div class="sa-hr-col" style="' . $col . '"><div style="' . $lab . '">Payment</div><table role="presentation" cellpadding="0" cellspacing="0" border="0">' . $kv . '</table></div>'
        . '<div class="sa-hr-col" style="' . $col . '"><div style="' . $lab . '">Billing</div><div style="font-family:' . $F . ';font-size:13px;line-height:1.55;color:#111">' . $lines($bill) . '</div>'
        . ($ship ? '<div style="' . $lab . ';padding-top:12px">Ship to</div><div style="font-family:' . $F . ';font-size:13px;line-height:1.55;color:#111">' . $lines($ship) . '</div>' : '')
        . '</div></div>';
    return $h . '</td></tr></table>';
    };
    return function_exists('sa_geo_without_conversion')
        ? sa_geo_without_conversion($build)
        : $build();
}

/** Email shell: white, black, amber accent, auto-link safe, mobile stacking. */
function sa_hr_mail_wrap(string $head, string $inner, string $pre, string $cta = '', string $url = ''): string
{
    $F = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
    $logo = function_exists('sa_theme_logo_url') ? sa_theme_logo_url(true) : home_url('/wp-content/themes/supreme-autoparts/assets/logo-light.jpg');
    $btn = $cta !== '' && $url !== '' ? '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 6px"><tr><td style="background:#0a0a0a;border-radius:6px"><a href="' . esc_url($url) . '" style="display:inline-block;padding:14px 28px;font-family:' . $F . ';font-size:16px;font-weight:700;color:#ffffff;text-decoration:none">' . esc_html($cta) . ' &rarr;</a></td></tr></table>' : '';
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light"><meta name="format-detection" content="telephone=no,address=no,email=no,date=no">'
        . '<title>' . esc_html($head) . '</title><style>a[x-apple-data-detectors]{color:inherit!important;text-decoration:none!important}u+#body a{color:inherit;text-decoration:none}'
        . '@media (max-width:600px){.sa-hr-pad{padding-left:18px!important;padding-right:18px!important}.sa-hr-col{max-width:100%!important;display:block!important}.sa-hide-sm{display:none!important}.sa-show-sm{display:block!important;max-height:none!important}h1.sa-hr-h{font-size:22px!important}}</style></head>'
        . '<body id="body" style="margin:0;padding:0;background:#f4f4f4;-webkit-text-size-adjust:100%">'
        . '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all">' . esc_html($pre) . str_repeat('&#8199;&#65279;&#847; ', 40) . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f4f4"><tr><td align="center" style="padding:24px 10px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background:#ffffff;border:1px solid #e4e4e4;border-radius:8px;border-collapse:separate">'
        . '<tr><td class="sa-hr-pad" style="padding:22px 28px 16px;border-bottom:3px solid #F5A623"><img src="' . esc_url($logo) . '" alt="Supreme Autoparts" width="300" style="display:block;width:300px;max-width:100%;height:auto;border:0"></td></tr>'
        . '<tr><td class="sa-hr-pad" style="padding:26px 28px 28px;font-family:' . $F . ';font-size:15px;line-height:1.6;color:#222">'
        . '<h1 class="sa-hr-h" style="margin:0 0 14px;font-family:' . $F . ';font-size:24px;line-height:1.25;color:#111;font-weight:700">' . esc_html($head) . '</h1>'
        . $inner . ($btn ? '' : '') . '</td></tr></table>'
        . '<div style="max-width:600px;font-family:' . $F . ';font-size:12px;line-height:1.5;color:#8a8a8a;padding:14px 10px">Supreme Autoparts &middot; supremeautoparts.co.ke<br>You\'re receiving this because of an order you placed with us.</div>'
        . '</td></tr></table></body></html>';
}

/** CTA button html (used inside inner so it sits right after the action). */
function sa_hr_btn(string $cta, string $url): string
{
    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 8px"><tr><td style="background:#0a0a0a;border-radius:6px"><a href="' . esc_url($url) . '" style="display:inline-block;padding:14px 28px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:16px;font-weight:700;color:#ffffff;text-decoration:none">' . esc_html($cta) . ' &rarr;</a></td></tr></table>';
}

/** Security, privacy, anti-phishing, help. */
function sa_hr_trust_html(WC_Order $o, bool $uploads, bool $email = true): string
{
    $c = sa_hr_contact($o);
    $nl = static fn(string $s): string => function_exists('sa_rcpt_nolink') ? sa_rcpt_nolink($s) : esc_html($s);
    $box = 'background:#fafafa;border-left:3px solid #F5A623;padding:12px 14px;margin:18px 0;font-size:13px;line-height:1.6;color:#333';
    $h = '<div style="' . $box . '"><strong style="color:#111">Your information is protected</strong><br>'
        . ($uploads ? 'Files are sent over an encrypted connection, stored privately, and viewed only by our verification team. They are deleted 30 days after review and never shared or sold. ' : 'Your details are sent over an encrypted connection and used only for this order. ')
        . 'We will never ask for your full card number, CVV, PIN, M-Pesa PIN or passwords, by email, phone or chat.</div>';
    $h .= '<p style="font-size:13px;line-height:1.6;color:#555;margin:0 0 10px"><strong style="color:#111">Is this really us?</strong> ' . ($email ? 'The button above opens a page on supremeautoparts.co.ke. ' : '') . 'If you\'re unsure, don\'t use the link: go to supremeautoparts.co.ke, open My Account, or contact us directly.</p>';
    $h .= '<p style="font-size:13px;line-height:1.6;color:#555;margin:0"><strong style="color:#111">Questions?</strong> ' . ($email ? 'Reply to this email, or call ' : 'Call ') . $nl($c['phone']) . ' or email ' . $nl($c['email']) . '.</p>';
    return $h;
}
