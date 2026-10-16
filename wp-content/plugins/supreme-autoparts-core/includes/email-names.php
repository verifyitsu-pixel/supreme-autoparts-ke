<?php
/**
 * Customer first-name greetings for every customer email.
 * Source order: order billing first -> shipping first -> account first_name -> form name (first word).
 * No usable name: "Hello,". Never uses an email handle, username or "there".
 */
defined('ABSPATH') || exit;

/** Clean one candidate name to a first name, or '' if unusable. */
function sa_email_clean_first(string $raw, string $email = ''): string
{
    $raw = trim(wp_strip_all_tags(html_entity_decode($raw, ENT_QUOTES, 'UTF-8')));
    if ($raw === '' || str_contains($raw, '@')) {
        return '';
    }
    $first = (string) preg_split('/[\s,]+/u', $raw)[0];
    $first = trim($first, " .-_'\"");
    if ($first === '' || preg_match('/\d/', $first) || !preg_match('/\p{L}/u', $first) || mb_strlen($first) > 40) {
        return '';
    }
    if (in_array(mb_strtolower($first), ['there', 'customer', 'guest', 'user', 'n/a', 'na', 'none'], true)) {
        return '';
    }
    $local = $email !== '' && str_contains($email, '@') ? mb_strtolower(strstr($email, '@', true)) : '';
    if ($local !== '' && mb_strtolower($first) === $local && preg_match('/[._\-+]/', $local)) {
        return '';
    }
    if ($first === mb_strtoupper($first) || $first === mb_strtolower($first)) {
        $first = mb_convert_case(mb_strtolower($first), MB_CASE_TITLE, 'UTF-8');
    }
    return $first;
}

/**
 * Raw (unescaped) first name from any mix of sources, in priority order.
 * Each source: WC_Order, WP_User, user id (int), or a plain name string.
 */
function sa_email_first_name(...$sources): string
{
    foreach ($sources as $s) {
        $cands = [];
        $email = '';
        if ($s instanceof WC_Order) {
            $email = (string) $s->get_billing_email();
            $cands = [$s->get_billing_first_name(), $s->get_shipping_first_name()];
            if ($s->get_customer_id()) {
                $u = get_userdata($s->get_customer_id());
                if ($u) {
                    $cands[] = (string) $u->first_name;
                }
            }
        } elseif ($s instanceof WP_User) {
            $email = (string) $s->user_email;
            $cands = [(string) $s->first_name, (string) get_user_meta($s->ID, 'billing_first_name', true), (string) get_user_meta($s->ID, 'shipping_first_name', true)];
        } elseif (is_int($s) && $s > 0) {
            $u = get_userdata($s);
            if ($u) {
                return sa_email_first_name($u);
            }
        } elseif (is_string($s)) {
            $cands = [$s];
        }
        foreach ($cands as $c) {
            $f = sa_email_clean_first((string) $c, $email);
            if ($f !== '') {
                return $f;
            }
        }
    }
    return '';
}

/** Plain-text greeting line: "Hi Ramón," or "Hello,". */
function sa_email_greeting_text(...$sources): string
{
    $f = sa_email_first_name(...$sources);
    return $f !== '' ? 'Hi ' . $f . ',' : 'Hello,';
}

/** HTML-escaped greeting text (no <p>). */
function sa_email_greeting(...$sources): string
{
    return esc_html(sa_email_greeting_text(...$sources));
}

/* ---- WooCommerce core emails (processing, completed, on-hold, refunded, invoice, note, new account, reset password, etc). ---- */
$GLOBALS['sa_email_current'] = null;
add_action('woocommerce_email_header', static function ($heading = '', $email = null): void {
    if ($email instanceof WC_Email) {
        $GLOBALS['sa_email_current'] = $email;
    }
}, 1, 2);
add_filter('woocommerce_mail_content', static function ($content) {
    $email = $GLOBALS['sa_email_current'] ?? null;
    if (!is_string($content) || !$email instanceof WC_Email || $email->is_customer_email() === false) {
        return $content;
    }
    $obj = $email->object;
    $srcs = [];
    if ($obj instanceof WC_Order) {
        $srcs[] = $obj;
    } elseif ($obj instanceof WP_User) {
        $srcs[] = $obj;
    } elseif (!empty($email->user_id)) {
        $srcs[] = (int) $email->user_id;
    }
    $g = sa_email_greeting(...$srcs);
    if (str_contains($content, '<p')) {
        $content = preg_replace('/(<p[^>]*>)\s*Hi\b[^<]{0,80}?,\s*(<\/p>)/u', '$1' . addcslashes($g, '\\$') . '$2', $content, 1);
    } else {
        $content = preg_replace('/^Hi\b[^\n]{0,80}?,\s*$/mu', addcslashes(html_entity_decode($g, ENT_QUOTES, 'UTF-8'), '\\$'), $content, 1);
    }
    return $content;
}, 5);

/** Best name source for a car-service booking: linked order, then account, else the form name (passed separately). */
function sa_bk_greet_src(array $b)
{
    $oid = (int) ($b['fee_order'] ?? 0);
    if (!$oid && !empty($b['id'])) {
        $w = get_post_meta((int) $b['id'], '_sa_bk_waiver', true);
        $oid = is_array($w) ? (int) ($w['order'] ?? $w['order_id'] ?? 0) : 0;
    }
    $o = $oid && function_exists('wc_get_order') ? wc_get_order($oid) : null;
    if ($o instanceof WC_Order) {
        return $o;
    }
    return !empty($b['user']) ? (int) $b['user'] : '';
}
