<?php
/**
 * Footer “Contact us” form — emails store via wp_mail / Brevo.
 *
 * @package Supreme_Autoparts
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Destination for footer contact submissions.
 */
function sa_footer_contact_to(): string
{
    if (function_exists('sa_core_store_email')) {
        $email = sa_core_store_email();
        if (is_email($email)) {
            return $email;
        }
    }
    return 'calvin@supremeautoparts.co.ke';
}

/**
 * Client IP for rate limiting (best-effort).
 */
function sa_footer_contact_client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return preg_replace('/[^0-9a-fA-F:.]/', '', $ip) ?: '0.0.0.0';
}

/**
 * Safe redirect target after submit (referer or home).
 */
function sa_footer_contact_redirect_base(): string
{
    $ref = wp_get_referer();
    if (is_string($ref) && $ref !== '') {
        return $ref;
    }
    $redirect = isset($_POST['sa_contact_redirect'])
        ? esc_url_raw(wp_unslash((string) $_POST['sa_contact_redirect']))
        : '';
    if ($redirect !== '') {
        return $redirect;
    }
    return home_url('/');
}

/**
 * admin-post handler for footer contact form.
 */
function sa_handle_footer_contact(): void
{
    $base = sa_footer_contact_redirect_base();

    $fail = static function (string $code = 'error') use ($base): void {
        $url = remove_query_arg(['sa_contact'], $base);
        $url = add_query_arg('sa_contact', $code, $url);
        wp_safe_redirect($url . '#sa-footer-contact');
        exit;
    };

    if (!isset($_POST['sa_footer_contact_nonce'])
        || !wp_verify_nonce(
            sanitize_text_field(wp_unslash((string) $_POST['sa_footer_contact_nonce'])),
            'sa_footer_contact'
        )
    ) {
        $fail('error');
    }

    // Honeypot — bots fill this; humans leave empty.
    $honey = isset($_POST['sa_company']) ? trim((string) wp_unslash($_POST['sa_company'])) : '';
    if ($honey !== '') {
        // Pretend success so bots do not retry.
        $url = remove_query_arg(['sa_contact'], $base);
        $url = add_query_arg('sa_contact', 'sent', $url);
        wp_safe_redirect($url . '#sa-footer-contact');
        exit;
    }

    $ip = sa_footer_contact_client_ip();
    $rate_key = 'sa_footer_contact_' . md5($ip);
    if (get_transient($rate_key)) {
        $fail('rate');
    }

    $name    = isset($_POST['sa_name']) ? sanitize_text_field(wp_unslash((string) $_POST['sa_name'])) : '';
    $email   = isset($_POST['sa_email']) ? sanitize_email(wp_unslash((string) $_POST['sa_email'])) : '';
    $phone   = isset($_POST['sa_phone']) ? sanitize_text_field(wp_unslash((string) $_POST['sa_phone'])) : '';
    $message = isset($_POST['sa_message']) ? sanitize_textarea_field(wp_unslash((string) $_POST['sa_message'])) : '';
    $page_url = isset($_POST['sa_contact_redirect'])
        ? esc_url_raw(wp_unslash((string) $_POST['sa_contact_redirect']))
        : '';

    if ($name === '' || !is_email($email) || $message === '') {
        $fail('error');
    }

    // Soft length caps.
    if (strlen($name) > 120 || strlen($phone) > 40 || strlen($message) > 5000) {
        $fail('error');
    }

    $to = sa_footer_contact_to();
    $subject = sprintf(
        /* translators: %s: sender name */
        __('Website contact — %s', 'supreme-autoparts'),
        $name
    );

    $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
    $now = wp_date('Y-m-d H:i:s T', null, $tz);

    $lines = [
        'New website contact form submission',
        '',
        'Name: ' . $name,
        'Email: ' . $email,
        'Phone: ' . ($phone !== '' ? $phone : '—'),
        '',
        'Message:',
        $message,
        '',
        'Page: ' . ($page_url !== '' ? $page_url : '—'),
        'Time: ' . $now,
        'IP: ' . $ip,
    ];
    $body = implode("\n", $lines);

    $headers = [
        'Content-Type: text/plain; charset=UTF-8',
        'From: Supreme Autoparts <' . $to . '>',
        'Reply-To: ' . $name . ' <' . $email . '>',
    ];

    $sent = wp_mail($to, $subject, $body, $headers);

    // Rate-limit after attempt (even on failure) to reduce abuse.
    set_transient($rate_key, 1, MINUTE_IN_SECONDS);

    if (!$sent) {
        $fail('error');
    }

    $url = remove_query_arg(['sa_contact'], $base);
    $url = add_query_arg('sa_contact', 'sent', $url);
    wp_safe_redirect($url . '#sa-footer-contact');
    exit;
}

add_action('admin_post_sa_footer_contact', 'sa_handle_footer_contact');
add_action('admin_post_nopriv_sa_footer_contact', 'sa_handle_footer_contact');

/**
 * Flash notice markup for footer contact result query arg.
 */
function sa_footer_contact_notice_html(): string
{
    if (empty($_GET['sa_contact'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return '';
    }
    $status = sanitize_key(wp_unslash((string) $_GET['sa_contact'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

    $map = [
        'sent'  => [
            'class' => 'sa-footer-contact__notice--ok',
            'text'  => __('Thanks — your message was sent. We will get back to you soon.', 'supreme-autoparts'),
        ],
        'error' => [
            'class' => 'sa-footer-contact__notice--err',
            'text'  => __('Sorry, we could not send your message. Please try again or email calvin@supremeautoparts.co.ke.', 'supreme-autoparts'),
        ],
        'rate'  => [
            'class' => 'sa-footer-contact__notice--err',
            'text'  => __('Please wait a moment before sending another message.', 'supreme-autoparts'),
        ],
    ];

    if (!isset($map[$status])) {
        return '';
    }

    return sprintf(
        '<div class="sa-footer-contact__notice %1$s" role="status">%2$s</div>',
        esc_attr($map[$status]['class']),
        esc_html($map[$status]['text'])
    );
}
