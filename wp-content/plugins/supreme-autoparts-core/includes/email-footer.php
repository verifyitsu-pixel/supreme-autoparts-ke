<?php
/** Shared, compact footer for store email, including plain-text messages. */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Country comes from the order when available, otherwise the site's geo lookup. */
function sa_email_footer_business(string $country = ''): array
{
    $country = strtoupper($country !== '' ? $country : (function_exists('sa_geo_country') ? sa_geo_country() : 'US'));
    $africa = function_exists('WC') && WC()->countries
        ? WC()->countries->get_continent_code_for_country($country) === 'AF'
        : $country === 'KE';
    return function_exists('sa_geo_business') ? sa_geo_business($africa ? 'KE' : 'US') : [
        'one_line' => $africa ? 'Ground Floor, Midax Plaza, Kangundo Rd, Njiru, Nairobi, Kenya' : '12800 NE Sandy Blvd, Portland, OR 97230',
        'phone_display' => $africa ? '+254 714 498 451' : '+1 917 437 5121',
        'whatsapp' => $africa ? '254714498451' : '19174375121',
    ];
}

function sa_email_footer_html(string $country = ''): string
{
    $business = sa_email_footer_business($country);
    $logo = home_url('/wp-content/themes/supreme-autoparts/assets/logo-email.png');
    $link = 'color:#555555;text-decoration:underline;';
    return '<!--sa-email-footer--><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;border-collapse:collapse" data-sa-email-region="' . ($business['whatsapp'] === '254714498451' ? 'KE' : 'US') . '">'
        . '<tr><td style="padding:20px 28px 28px;color:#737373;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.7;text-align:center">'
        . '<div style="border-top:1px solid #dedede;padding-top:20px">'
        . '<p style="margin:0 0 12px;font-size:13px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#111111;line-height:28px"><img src="' . esc_url($logo) . '" alt="Supreme Autoparts" width="180" height="28" border="0" style="display:block;margin:0 auto;width:180px;height:28px;max-width:100%;border:0;outline:none;text-decoration:none;font-size:13px;font-weight:700;color:#111111"></p>'
        . '<p style="margin:0 0 10px">' . esc_html($business['one_line']) . '</p>'
        . '<p style="margin:0"><a href="' . esc_url(home_url('/privacy-policy/')) . '" style="' . $link . '">Privacy</a> &nbsp;&middot;&nbsp; '
        . '<a href="' . esc_url(home_url('/terms/')) . '" style="' . $link . '">Terms</a> &nbsp;&middot;&nbsp; '
        . '<a href="' . esc_url(home_url('/returns/')) . '" style="' . $link . '">Returns</a></p>'
        . '</div></td></tr></table><!--/sa-email-footer-->';
}

/** What kind of email this is, so the help line fits its purpose. 'none' = the email already has its own help text. */
function sa_email_context(string $message, string $woo_id = '', bool $customer = true): string
{
    if (!$customer) {
        return 'none';
    }
    $has = static fn(string $needle): bool => stripos($message, $needle) !== false;
    if ($has('sa-otp-mail') || $has('Never share the code') || $has('Is this really us?') || $has('Need us now?') || $has('Need help? calvin@')) {
        return 'none';
    }
    $by_id = [
        'customer_processing_order' => 'order', 'customer_on_hold_order' => 'order', 'customer_invoice' => 'order', 'customer_note' => 'order',
        'customer_completed_order' => 'delivered', 'customer_refunded_order' => 'refund', 'customer_partially_refunded_order' => 'refund',
        'customer_failed_order' => 'failed', 'customer_reset_password' => 'account', 'customer_new_account' => 'account',
    ];
    if ($woo_id !== '' && isset($by_id[$woo_id])) {
        return ($by_id[$woo_id] === 'order' && $has('Store pickup')) ? 'pickup_order' : $by_id[$woo_id];
    }
    if ($has('Your order is complete') || $has('order is complete')) {
        return 'delivered';
    }
    if ($has('has been refunded') || $has('was refunded')) {
        return 'refund';
    }
    if ($has('has been cancelled')) {
        return 'cancelled';
    }
    if ($has('Your reference is')) {
        return 'contact';
    }
    if ($has('Booking number') || $has('Car Service') || $has('booking fee')) {
        return 'booking';
    }
    if ($has('was cancelled') || $has('Your order was cancelled')) {
        return 'cancelled';
    }
    if ($has('Your refund is on its way') || $has('Refund for order')) {
        return 'refund';
    }
    if ($has('Your payment failed') || $has('did not go through')) {
        return 'failed';
    }
    if ($has('ready for pickup') || $has('ready to collect')) {
        return 'pickup';
    }
    if ($has('out for delivery') || $has('has shipped') || $has('ready for pickup') || $has('handed to the courier')) {
        return 'delivery';
    }
    if ($has('was delivered') || $has('has been delivered')) {
        return 'delivered';
    }
    if ($has('reset') && $has('password')) {
        return 'account';
    }
    if ($has('More info needed') || $has('on hold')) {
        return 'none';
    }
    if ($has('order')) {
        return $has('Store pickup') ? 'pickup_order' : 'order';
    }
    return 'generic';
}

function sa_email_help_lead(string $ctx): string
{
    $lines = [
        'order'     => 'Need to change the address or an item? Message us with your order number before it ships.',
        'delivered' => 'Something not right with a part? Send us a photo and your order number and we will sort it out.',
        'pickup_order' => 'Need to change the pickup time or an item? Message us with your order number before it is ready.',
        'pickup'    => 'Cannot collect it when expected? Message us with your order number and we will keep it for you.',
        'delivery'  => 'Problem with the delivery? Message us with your order number and the phone number you gave at checkout.',
        'cancelled' => 'Cancelled by mistake? Message us with the order number and we will help you order again.',
        'refund'    => 'Refund not showing yet? Send us the order number and we will trace it.',
        'failed'    => 'Payment keeps failing? Message us and we will help you finish the order.',
        'account'   => 'Did not ask for this? Ignore this email. If you are worried about your account, message us.',
        'booking'   => 'Need to change your booking? Use the manage link above or message us with your booking number.',
        'contact'   => 'Forgot to mention something? Just reply to this email and we will add it to your request.',
        'generic'   => 'Questions? We are happy to help.',
    ];
    return $lines[$ctx] ?? $lines['generic'];
}

function sa_email_help_html(string $country = '', string $ctx = 'generic'): string
{
    if ($ctx === 'none') {
        return '<!--sa-email-help--><!--/sa-email-help-->';
    }
    $business = sa_email_footer_business($country);
    $reply = $ctx === 'contact';
    // Roadside rescue is a Nairobi service: mention it only in African-region help blocks on order emails.
    $roadside = '';
    if ($business['whatsapp'] === '254714498451' && in_array($ctx, ['order', 'pickup_order', 'pickup', 'delivery', 'delivered'], true)) {
        $roadside = '<div style="margin-top:10px;padding-top:10px;border-top:1px solid #e4e4e0">Car trouble? <a href="' . esc_url(home_url('/roadside/')) . '" style="color:#333333;text-decoration:underline">Request roadside help</a> or call <a href="tel:+254714498451" style="color:#333333;text-decoration:underline;white-space:nowrap">+254 714 498 451</a>.</div>';
    }
    return '<!--sa-email-help--><div style="margin:20px 0 4px;padding:16px 0 0;border-top:1px solid #e4e4e0;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.65;color:#555555">'
        . '<div>' . esc_html(sa_email_help_lead($ctx))
        . ($reply ? '' : '<br><a href="mailto:calvin@supremeautoparts.co.ke" style="color:#333333;text-decoration:underline">Email us</a> or '
            . '<a href="https://wa.me/' . esc_attr($business['whatsapp']) . '" style="color:#333333;text-decoration:underline">chat on WhatsApp</a>.')
        . '</div>' . $roadside
        . '</div><!--/sa-email-help-->';
}

function sa_email_footer_text(string $country = ''): string
{
    $business = sa_email_footer_business($country);
    return "Supreme Autoparts\n" . $business['one_line'] . "\n\n"
        . 'Privacy: ' . home_url('/privacy-policy/') . "\n"
        . 'Terms: ' . home_url('/terms/') . "\n"
        . 'Returns: ' . home_url('/returns/');
}

/** Remove only our own block, never unsubscribe text or provider footers. */
function sa_email_strip_custom_footer(string $html): string
{
    if (strpos($html, 'sa-email-footer') === false) {
        return $html;
    }
    $html = (string) preg_replace('#<!--sa-email-footer-->.*?<!--/sa-email-footer-->#is', '', $html);
    return (string) preg_replace('#<!--sa-email-footer-->.*?</table>#is', '', $html);
}

/** Idempotent across WooCommerce, wp_mail and direct transactional API sends. */
function sa_email_add_footer(string $message, string $country = '', string $woo_id = '', bool $customer = true): string
{
    if ($message === '' || strpos($message, '<!--sa-email-footer-->') !== false) {
        return $message;
    }
    // Plain mail converted to HTML already contains the text footer.
    if (strpos($message, 'Email: calvin@supremeautoparts.co.ke') !== false
        && strpos($message, 'Privacy:') !== false) {
        return $message;
    }
    $ctx = sa_email_context($message, $woo_id, $customer);
    $help = sa_email_help_html($country, $ctx);
    if ($ctx !== 'none' && strpos($message, '<!--sa-email-help-->') === false) {
        // Shared order/booking cards: insert a row below the action button.
        preg_match_all('#(?:</tbody>\s*)?</table>\s*</td>\s*</tr>\s*(?:</tbody>\s*)?</table>#i', $message, $ends, PREG_OFFSET_CAPTURE);
        $card_end = !empty($ends[0]) ? $ends[0][count($ends[0]) - 1][1] : false;
        if (strpos($message, 'sa-email-brand-header') !== false && $card_end !== false) {
            $message = substr_replace($message, '<tr><td style="padding:0 28px 24px">' . $help . '</td></tr>', $card_end, 0);
        } elseif (strpos($message, 'sa-otp-mail') !== false && ($end = strripos($message, '</div>')) !== false) {
            $message = substr_replace($message, $help, $end, 0);
        } elseif (($end = strripos($message, '</body>')) !== false) {
            $message = substr_replace($message, $help, $end, 0);
        } else {
            $message .= $help;
        }
    }
    $footer = sa_email_footer_html($country);
    $pos = strripos($message, '</body>');
    return $pos === false ? $message . $footer : substr_replace($message, $footer, $pos, 0);
}

function sa_email_add_text_footer(string $message, string $country = '', string $woo_id = '', bool $customer = true): string
{
    if ($message === '' || strpos($message, 'Email: calvin@supremeautoparts.co.ke') !== false) {
        return $message;
    }
    $business = sa_email_footer_business($country);
    $ctx = sa_email_context($message, $woo_id, $customer);
    $help = $ctx === 'none' ? '' : "\n\n" . sa_email_help_lead($ctx) . "\n"
        . ($ctx === 'contact' ? '' : "Email: calvin@supremeautoparts.co.ke\nWhatsApp: " . $business['phone_display'] . " (https://wa.me/" . $business['whatsapp'] . ")");
    if ($ctx === 'contact') {
        $help .= "Email: calvin@supremeautoparts.co.ke";
    }
    return rtrim($message) . $help . "\n\n--\n" . sa_email_footer_text($country);
}

add_action('woocommerce_email_footer', static function ($email = null): void {
    $order = $email instanceof WC_Email ? $email->object : null;
    $country = $order instanceof WC_Order ? (string) ($order->get_billing_country() ?: $order->get_shipping_country()) : '';
    $customer = !($email instanceof WC_Email) || !method_exists($email, 'is_customer_email') || $email->is_customer_email();
    $GLOBALS['sa_email_ctx'] = sa_email_context((string) ($GLOBALS['sa_email_body_hint'] ?? ''), $email instanceof WC_Email ? (string) $email->id : '', $customer);
    echo sa_email_help_html($country, $GLOBALS['sa_email_ctx']); // Escaped by the shared renderer.
}, 5);

add_filter('woocommerce_mail_content', static function ($content) {
    $email = $GLOBALS['sa_email_current'] ?? null;
    $order = $email instanceof WC_Email ? $email->object : null;
    $country = $order instanceof WC_Order ? (string) ($order->get_billing_country() ?: $order->get_shipping_country()) : '';
    if (!is_string($content)) {
        return $content;
    }
    $woo_id = $email instanceof WC_Email ? (string) $email->id : '';
    $customer = !($email instanceof WC_Email) || !method_exists($email, 'is_customer_email') || $email->is_customer_email();
    return preg_match('/<(?:html|body|table|div|p)\b/i', $content)
        ? sa_email_add_footer($content, $country, $woo_id, $customer) : sa_email_add_text_footer($content, $country, $woo_id, $customer);
}, 90);

add_filter('wp_mail', static function (array $args): array {
    $message = (string) ($args['message'] ?? '');
    $customer = strpos(ltrim((string) ($args['subject'] ?? '')), '[') !== 0; // "[Bookings] ...", "[Roadside] ..." go to the team.
    if (preg_match('/<(?:html|body|table|div|p)\b/i', $message)) {
        $args['message'] = sa_email_add_footer($message, '', '', $customer);
    } elseif (!(function_exists('sa_brevo_is_configured') && sa_brevo_is_configured())) {
        $args['message'] = sa_email_add_text_footer($message, '', '', $customer);
    }
    return $args;
}, 50);

// The shared wp_mail footer replaces WooCommerce's default boilerplate only.
add_filter('woocommerce_email_footer_text', static fn ($text = ''): string => '', 100);
add_filter('woocommerce_email_styles', static function (string $css): string {
    return $css . "\n#template_footer, #template_footer td, #credit { display:none !important; height:0 !important; max-height:0 !important; overflow:hidden !important; mso-hide:all; padding:0 !important; margin:0 !important; font-size:0 !important; line-height:0 !important; }\n"
        // Phone width: drop the qty and unit price columns, show "qty x price" under the item name instead.
        . "body, table, td, p, div { -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%; }\n"
        . "@media only screen and (max-width:620px){ .sa-hide-sm { display:none !important; } .sa-show-sm { display:block !important; max-height:none !important; overflow:visible !important; } .sa-col { display:block !important; width:100% !important; padding:0 0 14px !important; } }\n";
}, 100);

/** WooCommerce's stock "If you need any help with your order, please contact us at ..." sentence repeats the shared help line. */
add_action('init', static function (): void {
    foreach (['customer_invoice', 'customer_processing_order', 'customer_completed_order', 'customer_on_hold_order', 'customer_refunded_order', 'customer_partially_refunded_order', 'customer_failed_order', 'customer_cancelled_order', 'customer_note', 'customer_shipped_order', 'customer_delivered_order'] as $id) {
        add_filter('woocommerce_email_additional_content_' . $id, static function ($text = ''): string {
            $text = (string) $text;
            return stripos($text, 'please contact us at') !== false ? '' : $text;
        }, 100);
    }
});

/** Remember which WooCommerce email is being built so the help line follows its type, not just its wording. */
add_action('woocommerce_email_header', static function ($heading = '', $email = null): void {
    if ($email instanceof WC_Email) {
        $GLOBALS['sa_email_current'] = $email;
    }
}, 1, 2);
add_action('woocommerce_email_before_order_table', static function ($order = null, $sent_to_admin = false, $plain_text = false, $email = null): void {
    if ($email instanceof WC_Email) {
        $GLOBALS['sa_email_current'] = $email;
    }
}, 1, 4);
