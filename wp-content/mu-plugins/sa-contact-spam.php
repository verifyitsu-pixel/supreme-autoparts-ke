<?php
/** Local-only Contact Us spam quarantine. No remote classification or mail. */
declare(strict_types=1);
if (!defined('ABSPATH')) exit;

function sa_contact_spam_reason(array $fields, string $trap, int $email_count, int $ip_count): string {
    $email = strtolower(trim((string)($fields['email-1'] ?? '')));
    $message = (string)($fields['textarea-1'] ?? $fields['message-1'] ?? '');
    if (trim($trap) !== '') return 'hidden-field';
    // Exact observed abusive sender and promoted domains, not language or all links.
    if ($email === 'meepayseess@gmail.com') return 'known-spam-sender';
    if (preg_match('~(?:rick-owens-(?:boutique|kupit|moscow)\.ru|legicon-pravo\.ru/data/3645-|noto-highschool\.com/img_2529|t\.me/FeedbackFormEU)~i', $message)) return 'known-spam-link';
    if ($email_count > 5 || $ip_count > 20) return 'repeat-burst';
    return '';
}

function sa_contact_spam_counter(string $kind, string $value): int {
    if ($value === '') return 0;
    $key = 'sa_cspam_' . hash_hmac('sha256', $kind . ':' . $value . ':' . (string)floor(time()/600), wp_salt('nonce'));
    $count = (int)get_transient($key) + 1;
    set_transient($key, $count, 660);
    return $count; // Best-effort burst mitigation, not a security/auth limit.
}

add_filter('forminator_render_form_submit_markup', static function($html, $form_id) {
    if ((int)$form_id !== 51436) return $html;
    return $html . '<div aria-hidden="true" style="position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden"><label>Leave this field empty<input type="text" name="sa_contact_website" value="" tabindex="-1" autocomplete="off"></label></div>';
}, 30, 2);

add_filter('forminator_spam_protection', static function($spam, $data, $form_id, $type) {
    if ((int)$form_id !== 51436 || $type !== 'custom-forms') return $spam;
    $fields=[];
    foreach ((array)$data as $row) {
        if (is_array($row) && isset($row['name']) && is_scalar($row['value'] ?? null)) $fields[(string)$row['name']] = (string)$row['value'];
    }
    $trap = isset($_POST['sa_contact_website']) && is_scalar($_POST['sa_contact_website']) ? (string)wp_unslash($_POST['sa_contact_website']) : '';
    $email = strtolower(trim((string)($fields['email-1'] ?? '')));
    // REMOTE_ADDR only, never a client-supplied forwarding header.
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
    $reason = sa_contact_spam_reason($fields, $trap, sa_contact_spam_counter('email', $email), sa_contact_spam_counter('ip', $ip));
    return (bool)$spam || $reason !== '';
}, 30, 4);
