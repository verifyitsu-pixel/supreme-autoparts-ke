<?php
/**
 * Public endpoints: unsubscribe (one-click POST + confirm-page GET), preferences,
 * double opt-in confirmation, open pixel, click redirect.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class SM_Public
{
    public static function init(): void
    {
        add_action('init', [self::class, 'rewrites']);
        add_filter('query_vars', [self::class, 'query_vars']);
        add_action('template_redirect', [self::class, 'route'], 0);
    }

    public static function rewrites(): void
    {
        add_rewrite_rule('^sm/unsubscribe/([^/]+)/([^/]+)/?$', 'index.php?sm_action=unsubscribe&sm_e=$matches[1]&sm_t=$matches[2]', 'top');
        add_rewrite_rule('^sm/preferences/([^/]+)/([^/]+)/?$', 'index.php?sm_action=preferences&sm_e=$matches[1]&sm_t=$matches[2]', 'top');
        add_rewrite_rule('^sm/confirm/([^/]+)/([^/]+)/?$', 'index.php?sm_action=confirm&sm_e=$matches[1]&sm_t=$matches[2]', 'top');
        add_rewrite_rule('^sm/o/([0-9]+)/([^/.]+)\.gif$', 'index.php?sm_action=open&sm_sid=$matches[1]&sm_t=$matches[2]', 'top');
        add_rewrite_rule('^sm/c/([0-9]+)/([0-9]+)/([^/]+)/?$', 'index.php?sm_action=click&sm_lid=$matches[1]&sm_sid=$matches[2]&sm_t=$matches[3]', 'top');
    }

    public static function query_vars(array $vars): array
    {
        $vars[] = 'sm_action';
        $vars[] = 'sm_e';
        $vars[] = 'sm_t';
        $vars[] = 'sm_sid';
        $vars[] = 'sm_lid';
        return $vars;
    }

    public static function route(): void
    {
        $action = get_query_var('sm_action');
        if (!$action) {
            return;
        }
        switch ($action) {
            case 'open':
                self::handle_open();
                break;
            case 'click':
                self::handle_click();
                break;
            case 'unsubscribe':
                self::handle_unsubscribe();
                break;
            case 'preferences':
                self::handle_preferences();
                break;
            case 'confirm':
                self::handle_confirm();
                break;
        }
        exit;
    }

    private static function email_from_request(string $purpose): string
    {
        $email = strtolower(sm_b64d((string) get_query_var('sm_e')));
        $token = (string) get_query_var('sm_t');
        if (!is_email($email) || !sm_verify_token($email, $purpose, $token)) {
            return '';
        }
        return $email;
    }

    private static function page(string $title, string $body_html): void
    {
        status_header(200);
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . esc_html($title) . ' - Supreme Autoparts</title>'
            . '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:#F4F4F5;margin:0;padding:40px 16px;color:#111;}'
            . '.card{max-width:520px;margin:0 auto;background:#fff;border:1px solid #E6E6E6;border-radius:10px;padding:32px;}'
            . 'h1{font-size:20px;margin:0 0 12px;}p{font-size:15px;line-height:1.55;margin:0 0 14px;}'
            . 'a.button,button{display:inline-block;background:#111;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-size:15px;font-weight:600;border:0;cursor:pointer;}'
            . 'a.muted{color:#6B6B6B;font-size:13px;}</style></head><body><div class="card">'
            . '<h1>' . esc_html($title) . '</h1>' . $body_html
            . '<p style="margin-top:20px;"><a class="muted" href="' . esc_url(home_url('/')) . '">Back to supremeautoparts.co.ke</a></p>'
            . '</div></body></html>';
    }

    private static function handle_unsubscribe(): void
    {
        $email = self::email_from_request('unsub');
        if ($email === '') {
            self::page('Link expired', '<p>This unsubscribe link is invalid or has expired. You can manage emails from your account page.</p>');
            return;
        }
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
            SM_Subscribers::unsubscribe($email);
            // RFC 8058 one-click: bare 200 for List-Unsubscribe-Post clients.
            if (isset($_POST['List-Unsubscribe'])) {
                status_header(200);
                return;
            }
            self::page('You are unsubscribed', '<p>' . esc_html($email) . ' has been removed from Supreme Autoparts marketing emails. Order and account emails are unaffected.</p>');
            return;
        }
        // GET shows a confirm button (protects against link scanners unsubscribing people).
        self::page('Unsubscribe?', '<p>Stop marketing emails to <strong>' . esc_html($email) . '</strong>?</p>'
            . '<form method="post" action=""><button type="submit">Yes, unsubscribe me</button></form>');
    }

    private static function handle_preferences(): void
    {
        $email = self::email_from_request('prefs');
        if ($email === '') {
            self::page('Link expired', '<p>This preferences link is invalid or has expired.</p>');
            return;
        }
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
            $do = sanitize_key((string) ($_POST['sm_do'] ?? ''));
            if ($do === 'pause') {
                SM_Subscribers::pause($email, 30);
                self::page('Paused for 30 days', '<p>Marketing emails to ' . esc_html($email) . ' are paused for 30 days.</p>');
                return;
            }
            if ($do === 'resume') {
                SM_Subscribers::activate($email, 'preferences_resume');
                self::page('Resumed', '<p>Marketing emails to ' . esc_html($email) . ' are on again.</p>');
                return;
            }
        }
        $sub = SM_Subscribers::get_by_email($email);
        $status = $sub ? $sub['status'] : 'unknown';
        $paused = $sub && !empty($sub['paused_until']) && strtotime((string) $sub['paused_until']) > sm_ts();
        $body = '<p>Email: <strong>' . esc_html($email) . '</strong><br>Status: ' . esc_html(ucfirst(str_replace('_', ' ', (string) $status))) . ($paused ? ' (paused)' : '') . '</p>';
        $body .= '<form method="post" action="" style="display:inline;margin-right:8px;"><input type="hidden" name="sm_do" value="pause"><button type="submit">Pause for 30 days</button></form>';
        if ($status !== 'active' || $paused) {
            $body .= '<form method="post" action="" style="display:inline;"><input type="hidden" name="sm_do" value="resume"><button type="submit">Resume emails</button></form>';
        }
        $body .= '<p style="margin-top:16px;"><a class="muted" href="' . esc_url(sm_unsubscribe_url($email)) . '">Unsubscribe from all marketing emails</a></p>';
        self::page('Email preferences', $body);
    }

    private static function handle_confirm(): void
    {
        $email = self::email_from_request('confirm');
        if ($email === '') {
            self::page('Link expired', '<p>This confirmation link is invalid or has expired. You can subscribe again from the store footer.</p>');
            return;
        }
        $already = false;
        $sub = SM_Subscribers::get_by_email($email);
        if ($sub && $sub['status'] === 'active') {
            $already = true;
        }
        SM_Subscribers::activate($email, 'double_optin');
        $fresh = SM_Subscribers::get_by_email($email);
        if ($fresh && !$already) {
            SM_Flows::start($fresh, 'welcome', 'once', []);
        }
        self::page('Subscription confirmed', '<p>Thanks - ' . esc_html($email) . ' is confirmed. You will get about one email a week: new parts, restocks and offers. Unsubscribe anytime.</p>');
    }

    private static function handle_open(): void
    {
        global $wpdb;
        $sid = (int) get_query_var('sm_sid');
        $token = (string) get_query_var('sm_t');
        if ($sid > 0 && sm_verify_token((string) $sid, 'open', $token)) {
            $wpdb->query($wpdb->prepare(
                "UPDATE " . SM_DB::table('sends') . "
                 SET open_count = open_count + 1, opened_at = COALESCE(opened_at, %s) WHERE id = %d",
                sm_now(), $sid
            ));
        }
        header('Content-Type: image/gif');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        // 1x1 transparent GIF
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    }

    private static function handle_click(): void
    {
        global $wpdb;
        $lid = (int) get_query_var('sm_lid');
        $sid = (int) get_query_var('sm_sid');
        $token = (string) get_query_var('sm_t');
        $link = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SM_DB::table('links') . ' WHERE id = %d', $lid), ARRAY_A);
        if (!$link || (int) $link['send_id'] !== $sid || !sm_verify_token($lid . '|' . $sid, 'click', $token)) {
            wp_safe_redirect(home_url('/'));
            exit;
        }
        $wpdb->query($wpdb->prepare('UPDATE ' . SM_DB::table('links') . ' SET clicks = clicks + 1 WHERE id = %d', $lid));
        $wpdb->query($wpdb->prepare(
            "UPDATE " . SM_DB::table('sends') . " SET click_count = click_count + 1, clicked_at = COALESCE(clicked_at, %s) WHERE id = %d",
            sm_now(), $sid
        ));
        // Identify this browser for future browse/cart triggers.
        $send = $wpdb->get_row($wpdb->prepare('SELECT email FROM ' . SM_DB::table('sends') . ' WHERE id = %d', $sid), ARRAY_A);
        if ($send && is_email($send['email'])) {
            if (!headers_sent()) {
                setcookie('sm_email', strtolower((string) $send['email']), time() + 180 * DAY_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
            }
        }
        wp_safe_redirect((string) $link['url'], 302);
        exit;
    }
}
