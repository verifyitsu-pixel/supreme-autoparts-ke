<?php
/**
 * Customer profile avatars + time-of-day name greeting.
 *
 * - Customers upload/change/remove a profile photo from My Account
 *   (login-gated, nonce-checked, image type and size validated server-side).
 * - Fallback everywhere is an initials circle.
 * - The avatar shows in the header account link, on My Account, and anywhere
 *   WordPress asks for the user's avatar (get_avatar filter).
 * - Logged-in customers are greeted by first name with a time-of-day word
 *   ("Good morning/afternoon/evening"). The server picks the word from the
 *   site timezone; a tiny script corrects it to the visitor's own local hour.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** First name for greetings, '' when nothing usable. */
function sa_avatar_first_name(int $user_id): string
{
    $u = get_userdata($user_id);
    if (!$u) {
        return '';
    }
    if (function_exists('sa_email_first_name')) {
        return sa_email_first_name($u);
    }
    $first = trim((string) $u->first_name);
    return $first !== '' ? $first : trim((string) get_user_meta($user_id, 'billing_first_name', true));
}

/** Attachment id of the customer's avatar, 0 when none. */
function sa_avatar_attachment_id(int $user_id): int
{
    return (int) get_user_meta($user_id, 'sa_avatar_id', true);
}

/** Avatar image URL, '' when none. */
function sa_avatar_url(int $user_id): string
{
    $aid = sa_avatar_attachment_id($user_id);
    if (!$aid) {
        return '';
    }
    $url = wp_get_attachment_url($aid);
    return is_string($url) ? $url : '';
}

/** Initials (up to 2 letters) for the fallback chip. */
function sa_avatar_initials(int $user_id): string
{
    $u = get_userdata($user_id);
    if (!$u) {
        return '?';
    }
    $first = sa_avatar_first_name($user_id);
    $last = trim((string) $u->last_name);
    $a = $first !== '' ? mb_substr($first, 0, 1) : '';
    $b = $last !== '' ? mb_substr($last, 0, 1) : '';
    if ($a === '' && $b === '') {
        $a = mb_substr((string) $u->user_email, 0, 1);
    }
    return mb_strtoupper($a . $b);
}

/**
 * Avatar HTML: the photo when set, otherwise an initials circle. $size is px (square).
 */
function sa_avatar_html(int $user_id, int $size = 40): string
{
    $size = max(16, min(256, $size));
    $url = sa_avatar_url($user_id);
    if ($url !== '') {
        return '<span class="sa-avatar" style="width:' . $size . 'px;height:' . $size . 'px">'
            . '<img src="' . esc_url($url) . '" alt="" width="' . $size . '" height="' . $size . '" loading="lazy" decoding="async">'
            . '</span>';
    }
    return '<span class="sa-avatar sa-avatar--initials" style="width:' . $size . 'px;height:' . $size . 'px;font-size:' . (int) round($size * 0.4) . 'px" aria-hidden="true">'
        . esc_html(sa_avatar_initials($user_id))
        . '</span>';
}

/** Time-of-day greeting word for an hour (0-23). */
function sa_avatar_greeting_word(int $hour): string
{
    if ($hour >= 5 && $hour < 12) {
        return __('Good morning', 'supreme-autoparts-core');
    }
    if ($hour >= 12 && $hour < 17) {
        return __('Good afternoon', 'supreme-autoparts-core');
    }
    return __('Good evening', 'supreme-autoparts-core');
}

/**
 * Greeting HTML ("Good evening, James"). The server picks the word from the site
 * timezone; customer-avatar.js swaps it to the visitor's own local hour.
 */
function sa_avatar_greeting_html(int $user_id): string
{
    $word = sa_avatar_greeting_word((int) wp_date('G'));
    $name = sa_avatar_first_name($user_id);
    $out = '<span class="sa-greet-word" data-sa-greet-word>' . esc_html($word) . '</span>';
    if ($name !== '') {
        $out .= ', <span class="sa-greet-name">' . esc_html($name) . '</span>';
    }
    return $out;
}

/* Assets (only needed while a customer is logged in). */
add_action('wp_enqueue_scripts', static function (): void {
    if (!is_user_logged_in()) {
        return;
    }
    wp_enqueue_style('sa-avatar', SA_CORE_URL . 'assets/customer-avatar.css', [], SA_CORE_VERSION);
    wp_enqueue_script('sa-avatar', SA_CORE_URL . 'assets/customer-avatar.js', [], SA_CORE_VERSION, true);
});

/* Upload / change / remove. POST-only, login-gated (no nopriv hook), nonce-checked. */
add_action('admin_post_sa_avatar', static function (): void {
    $uid = get_current_user_id();
    if (!$uid || !wp_verify_nonce((string) ($_POST['_wpnonce'] ?? ''), 'sa_avatar')) {
        wp_die('Not allowed', 403);
    }
    $back = function_exists('wc_get_page_permalink') ? (string) wc_get_page_permalink('myaccount') : home_url('/my-account/');
    $redir = static function (string $code) use ($back): void {
        wp_safe_redirect(add_query_arg('sa-avatar', $code, $back));
        exit;
    };
    $do = sanitize_key((string) ($_POST['do'] ?? 'set'));
    $old = sa_avatar_attachment_id($uid);

    if ($do === 'remove') {
        if ($old && get_post_meta($old, '_sa_avatar_media', true) === '1') {
            wp_delete_attachment($old, true);
        }
        delete_user_meta($uid, 'sa_avatar_id');
        $redir('removed');
    }

    if (empty($_FILES['avatar']) || !is_array($_FILES['avatar'])) {
        $redir('missing');
    }
    $f = $_FILES['avatar'];
    if ((int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
        $redir('fail');
    }
    if ((int) $f['size'] > 5 * MB_IN_BYTES) {
        $redir('size');
    }
    $info = @getimagesize((string) $f['tmp_name']);
    if (!$info || !in_array((string) $info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
        $redir('type');
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    $up = wp_handle_upload($f, ['test_form' => false, 'mimes' => ['jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp']]);
    if (!is_array($up) || !empty($up['error'])) {
        $redir('fail');
    }
    $aid = wp_insert_attachment([
        'post_mime_type' => (string) $info['mime'],
        'post_title'     => 'customer-avatar-' . $uid,
        'post_status'    => 'private',
    ], (string) $up['file']);
    if (!$aid || is_wp_error($aid)) {
        $redir('fail');
    }
    update_post_meta((int) $aid, '_sa_avatar_media', '1');
    update_user_meta($uid, 'sa_avatar_id', (int) $aid);
    if ($old && $old !== (int) $aid && get_post_meta($old, '_sa_avatar_media', true) === '1') {
        wp_delete_attachment($old, true);
    }
    $redir('added');
});


/* Upload feedback message from the redirect query arg, '' when none. */
function sa_avatar_feedback_html(): string
{
    $code = sanitize_key((string) ($_GET['sa-avatar'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
    $messages = [
        'added'   => ['success', __('Profile photo updated.', 'supreme-autoparts-core')],
        'removed' => ['success', __('Profile photo removed.', 'supreme-autoparts-core')],
        'type'    => ['error', __('Please choose a JPG, PNG or WebP image.', 'supreme-autoparts-core')],
        'size'    => ['error', __('Please choose an image up to 5 MB.', 'supreme-autoparts-core')],
        'fail'    => ['error', __('The upload failed. Please try again.', 'supreme-autoparts-core')],
        'missing' => ['error', __('Please choose a photo first.', 'supreme-autoparts-core')],
    ];
    if ($code === '' || !isset($messages[$code])) {
        return '';
    }
    [$kind, $text] = $messages[$code];
    return '<p class="sa-greet__msg sa-greet__msg--' . esc_attr($kind) . '">' . esc_html($text) . '</p>';
}

/* Add/change + remove photo forms (post to the login-gated admin-post handler).
   The file input is picked up by the site's standard file-drop.js dropzone;
   data-max keeps its size hint in line with the server-side 5 MB cap. */
function sa_avatar_controls_html(int $user_id): string
{
    $has_photo = sa_avatar_attachment_id($user_id) > 0;
    $out = '<div class="sa-greet__actions">';
    $out .= '<form class="sa-greet__form sa-greet__form--upload" method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
    $out .= '<input type="hidden" name="action" value="sa_avatar"><input type="hidden" name="do" value="set">';
    $out .= wp_nonce_field('sa_avatar', '_wpnonce', true, false);
    $out .= '<span class="sa-greet__uploadlabel">' . esc_html($has_photo ? __('Change photo', 'supreme-autoparts-core') : __('Add a photo', 'supreme-autoparts-core')) . '</span>';
    $out .= '<input type="file" id="sa-avatar-file" name="avatar" accept="image/jpeg,image/png,image/webp" data-max="5242880">';
    $out .= '<button type="submit" class="sa-greet__save">' . esc_html__('Save photo', 'supreme-autoparts-core') . '</button>';
    $out .= '</form>';
    if ($has_photo) {
        $out .= '<form class="sa-greet__form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'' . esc_js(__('Remove your profile photo?', 'supreme-autoparts-core')) . '\');">';
        $out .= '<input type="hidden" name="action" value="sa_avatar"><input type="hidden" name="do" value="remove">';
        $out .= wp_nonce_field('sa_avatar', '_wpnonce', true, false);
        $out .= '<button type="submit" class="sa-greet__remove">' . esc_html__('Remove photo', 'supreme-autoparts-core') . '</button></form>';
    }
    $out .= '</div>';
    return $out;
}

/* Greeting card above every My Account page. */
add_action('woocommerce_before_my_account', static function (): void {
    $uid = get_current_user_id();
    if (!$uid) {
        return;
    }
    echo '<div class="sa-greet">';
    echo sa_avatar_html($uid, 72); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo '<div class="sa-greet__body">';
    echo '<p class="sa-greet__hello">' . sa_avatar_greeting_html($uid) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo sa_avatar_feedback_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo sa_avatar_controls_html($uid); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo '</div></div>';
});

/* Everywhere WordPress shows this user's avatar, use their photo. */
add_filter('get_avatar', static function ($avatar, $id_or_email, $size = 96) {
    $uid = 0;
    if (is_numeric($id_or_email)) {
        $uid = (int) $id_or_email;
    } elseif ($id_or_email instanceof WP_User) {
        $uid = (int) $id_or_email->ID;
    } elseif ($id_or_email instanceof WP_Post) {
        $uid = (int) $id_or_email->post_author;
    } elseif (is_object($id_or_email) && !empty($id_or_email->user_id)) {
        $uid = (int) $id_or_email->user_id;
    } elseif (is_string($id_or_email) && is_email($id_or_email)) {
        $u = get_user_by('email', $id_or_email);
        $uid = $u ? (int) $u->ID : 0;
    }
    if (!$uid || !sa_avatar_attachment_id($uid)) {
        return $avatar;
    }
    return sa_avatar_html($uid, (int) $size);
}, 10, 3);
