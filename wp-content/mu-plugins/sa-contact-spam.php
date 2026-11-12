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
    if ((int)$form_id !== 51436 || $type !== 'form') return $spam;
    $fields=[];
    foreach ((array)$data as $row) {
        if (is_array($row) && isset($row['name']) && is_scalar($row['value'] ?? null)) $fields[(string)$row['name']] = (string)$row['value'];
    }
    $trap = isset($_POST['sa_contact_website']) && is_scalar($_POST['sa_contact_website']) ? (string)wp_unslash($_POST['sa_contact_website']) : '';
    $email = strtolower(trim((string)($fields['email-1'] ?? '')));
    // REMOTE_ADDR only, never a client-supplied forwarding header.
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
    // A private reverse-proxy address is shared infrastructure, not a visitor.
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) $ip = '';
    $reason = sa_contact_spam_reason($fields, $trap, sa_contact_spam_counter('email', $email), sa_contact_spam_counter('ip', $ip));
    return (bool)$spam || $reason !== '';
}, 30, 4);

// Use Forminator's supported mark-as-spam path, without enabling remote Akismet.
add_filter('forminator_form_settings', static function($settings, $model) {
    if ((int)($model->id ?? 0) === 51436) $settings['akismet-protection-behavior'] = 'mark_spam';
    return $settings;
}, 30, 2);

add_action('admin_menu', static function() {
    add_management_page('Contact spam', 'Contact spam', 'manage_options', 'sa-contact-spam', 'sa_contact_spam_review');
});
function sa_contact_spam_review(): void {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $page = max(1, absint($_GET['paged'] ?? 1));
    $entries = $wpdb->get_results($wpdb->prepare("SELECT entry_id,date_created FROM {$wpdb->prefix}frmt_form_entry WHERE form_id=%d AND is_spam=1 ORDER BY entry_id DESC LIMIT 50 OFFSET %d",51436,($page-1)*50));
    echo '<div class="wrap"><h1>Contact spam</h1><p>Suspected spam is kept here without customer acknowledgments or normal inquiry alerts. Read-only review; genuine inquiries remain in Forminator Submissions.</p>';
    foreach ($entries as $entry) {
        echo '<details style="background:#fff;padding:12px;margin:12px 0"><summary>Entry '.esc_html((string)$entry->entry_id).' - '.esc_html($entry->date_created).'</summary><dl>';
        $meta = $wpdb->get_results($wpdb->prepare("SELECT meta_key,meta_value FROM {$wpdb->prefix}frmt_form_entry_meta WHERE entry_id=%d ORDER BY meta_id",$entry->entry_id));
        foreach ($meta as $row) {
            if (!preg_match('/^(name|email|phone|text|textarea|select|radio|checkbox)-/',(string)$row->meta_key)) continue;
            $value=maybe_unserialize($row->meta_value);
            echo '<dt><strong>'.esc_html($row->meta_key).'</strong></dt><dd style="white-space:pre-wrap;overflow-wrap:anywhere">'.esc_html(is_scalar($value)?(string)$value:wp_json_encode($value)).'</dd>';
        }
        echo '</dl></details>';
    }
    if (!$entries) echo '<p>No suspected spam on this page.</p>';
    if ($page>1) echo '<a class="button" href="'.esc_url(add_query_arg(['page'=>'sa-contact-spam','paged'=>$page-1],admin_url('tools.php'))).'">Newer</a> ';
    if (count($entries)===50) echo '<a class="button" href="'.esc_url(add_query_arg(['page'=>'sa-contact-spam','paged'=>$page+1],admin_url('tools.php'))).'">Older</a>';
    echo '</div>';
}
