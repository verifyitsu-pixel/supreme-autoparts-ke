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
    $logo = function_exists('sa_theme_logo_url') ? sa_theme_logo_url(true) : home_url('/wp-content/themes/supreme-autoparts/assets/logo-light.jpg');
    $link = 'color:#555555;text-decoration:underline;';
    return '<!--sa-email-footer--><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;border-collapse:collapse" data-sa-email-region="' . ($business['whatsapp'] === '254714498451' ? 'KE' : 'US') . '">'
        . '<tr><td style="padding:20px 28px 28px;color:#737373;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.7;text-align:center">'
        . '<div style="border-top:1px solid #dedede;padding-top:20px">'
        . '<p style="margin:0 0 12px"><img src="' . esc_url($logo) . '" alt="Supreme Autoparts" width="180" style="display:block;width:180px;max-width:100%;height:auto;margin:0 auto;border:0;opacity:0.65"></p>'
        . '<p style="margin:0 0 10px">' . esc_html($business['one_line']) . '</p>'
        . '<p style="margin:0"><a href="' . esc_url(home_url('/privacy-policy/')) . '" style="' . $link . '">Privacy</a> &nbsp;&middot;&nbsp; '
        . '<a href="' . esc_url(home_url('/terms/')) . '" style="' . $link . '">Terms</a> &nbsp;&middot;&nbsp; '
        . '<a href="' . esc_url(home_url('/returns/')) . '" style="' . $link . '">Returns</a></p>'
        . '</div></td></tr></table><!--/sa-email-footer-->';
}

function sa_email_help_html(string $country = ''): string
{
    $business = sa_email_footer_business($country);
    return '<!--sa-email-help--><div style="margin:12px 0 4px;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.65;color:#666666">'
        . 'Questions about your order or account?<br>'
        . '<a href="mailto:calvin@supremeautoparts.co.ke" style="color:#333333;text-decoration:underline">Email us</a> or '
        . '<a href="https://wa.me/' . esc_attr($business['whatsapp']) . '" style="color:#333333;text-decoration:underline">chat on WhatsApp</a>.'
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
function sa_email_add_footer(string $message, string $country = ''): string
{
    if ($message === '' || strpos($message, '<!--sa-email-footer-->') !== false) {
        return $message;
    }
    // Plain mail converted to HTML already contains the text footer.
    if (strpos($message, 'Questions about your order or account?') !== false
        && strpos($message, 'Privacy:') !== false) {
        return $message;
    }
    $help = sa_email_help_html($country);
    if (strpos($message, '<!--sa-email-help-->') === false) {
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

function sa_email_add_text_footer(string $message, string $country = ''): string
{
    if ($message === '' || strpos($message, 'Questions about your order or account?') !== false) {
        return $message;
    }
    $business = sa_email_footer_business($country);
    return rtrim($message) . "\n\nQuestions about your order or account?\n"
        . "Email: calvin@supremeautoparts.co.ke\nWhatsApp: " . $business['phone_display'] . " (https://wa.me/" . $business['whatsapp'] . ")\n\n--\n"
        . sa_email_footer_text($country);
}

add_action('woocommerce_email_footer', static function ($email = null): void {
    $order = $email instanceof WC_Email ? $email->object : null;
    $country = $order instanceof WC_Order ? (string) ($order->get_billing_country() ?: $order->get_shipping_country()) : '';
    echo sa_email_help_html($country); // Escaped by the shared renderer.
}, 5);

add_filter('woocommerce_mail_content', static function ($content) {
    $email = $GLOBALS['sa_email_current'] ?? null;
    $order = $email instanceof WC_Email ? $email->object : null;
    $country = $order instanceof WC_Order ? (string) ($order->get_billing_country() ?: $order->get_shipping_country()) : '';
    if (!is_string($content)) {
        return $content;
    }
    return preg_match('/<(?:html|body|table|div|p)\b/i', $content)
        ? sa_email_add_footer($content, $country) : sa_email_add_text_footer($content, $country);
}, 90);

add_filter('wp_mail', static function (array $args): array {
    $message = (string) ($args['message'] ?? '');
    if (preg_match('/<(?:html|body|table|div|p)\b/i', $message)) {
        $args['message'] = sa_email_add_footer($message);
    } elseif (!(function_exists('sa_brevo_is_configured') && sa_brevo_is_configured())) {
        $args['message'] = sa_email_add_text_footer($message);
    }
    return $args;
}, 50);

// The shared wp_mail footer replaces WooCommerce's default boilerplate only.
add_filter('woocommerce_email_footer_text', static fn ($text = ''): string => '', 100);
add_filter('woocommerce_email_styles', static function (string $css): string {
    return $css . "\n#template_footer, #template_footer td, #credit { display:none !important; height:0 !important; max-height:0 !important; overflow:hidden !important; mso-hide:all; padding:0 !important; margin:0 !important; font-size:0 !important; line-height:0 !important; }\n";
}, 100);
