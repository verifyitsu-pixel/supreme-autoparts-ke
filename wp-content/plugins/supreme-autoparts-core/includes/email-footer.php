<?php
/**
 * Email footer — disabled.
 *
 * Previously appended a branded block (logo, address, phones, links, notice) to every
 * HTML/plain email via wp_mail. The store now ships emails with the logo header only
 * and no custom footer. WooCommerce default footer text is forced empty so templates
 * do not print leftover option junk.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** @deprecated Returns empty — custom email footer removed. */
function sa_email_footer_html(): string
{
    return '';
}

/** @deprecated Returns empty — custom email footer removed. */
function sa_email_footer_text(): string
{
    return '';
}

/**
 * Strip any previously injected custom footer marker block from HTML (idempotent).
 */
function sa_email_strip_custom_footer(string $html): string
{
    if ($html === '' || strpos($html, 'sa-email-footer') === false) {
        return $html;
    }
    // Remove our style + table block (and optional trailing whitespace).
    $html = (string) preg_replace(
        '#<!--sa-email-footer-->.*?(?:</table>|(?=</body>))#is',
        '',
        $html
    );
    return $html;
}

// Never append a custom footer. Still strip a stale one if a caller already embedded it.
add_filter('wp_mail', static function (array $args): array {
    $msg = (string) ($args['message'] ?? '');
    if ($msg === '') {
        return $args;
    }
    $args['message'] = sa_email_strip_custom_footer($msg);
    return $args;
}, 50);

// WooCommerce email footer text (shown in email-footer.php templates) — keep blank.
add_filter('woocommerce_email_footer_text', static fn ($text = ''): string => '', 100);

// Hide the WC #template_footer chrome if the template still renders an empty cell.
add_filter('woocommerce_email_styles', static function (string $css): string {
    $css .= "\n#template_footer, #template_footer td, #credit { display:none !important; height:0 !important; max-height:0 !important; overflow:hidden !important; mso-hide:all; padding:0 !important; margin:0 !important; font-size:0 !important; line-height:0 !important; }\n";
    return $css;
}, 100);

// Clear persisted footer option on boot (once per core version).
add_action('init', static function (): void {
    if (!function_exists('update_option')) {
        return;
    }
    $flag = 'sa_email_footer_cleared_' . (defined('SA_CORE_VERSION') ? SA_CORE_VERSION : '1');
    if (get_option($flag) === '1') {
        return;
    }
    update_option('woocommerce_email_footer_text', '', false);
    update_option($flag, '1', false);
}, 20);
