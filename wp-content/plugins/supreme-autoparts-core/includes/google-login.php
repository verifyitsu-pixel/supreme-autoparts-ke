<?php
/**
 * Sign in with Google for customers (alongside the emailed login code).
 *
 * A Google-verified email is the same strength as the emailed 6-digit code,
 * so a successful Google sign-in finds or creates the customer account and
 * starts a session - no password involved, staff wp-admin login untouched.
 *
 * Flow: Google Identity Services renders a button on the My Account login
 * form; Google returns an ID token to a JS callback; the token is posted to
 * this site and verified server-side against Google's tokeninfo endpoint.
 *
 * @package Supreme_Autoparts_Core
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Google OAuth 2.0 web client ID for this site.
 * Not a secret: it appears in public page markup on every Google sign-in site.
 * Priority: SA_GOOGLE_CLIENT_ID constant > sa_google_client_id option > built-in.
 */
function sa_google_client_id(): string
{
    if (defined('SA_GOOGLE_CLIENT_ID') && SA_GOOGLE_CLIENT_ID) {
        return (string) SA_GOOGLE_CLIENT_ID;
    }
    $opt = trim((string) get_option('sa_google_client_id', ''));
    if ($opt !== '') {
        return $opt;
    }
    return '503521893815-goqrevpphr27gc140fu3atf70ji131er.apps.googleusercontent.com';
}

/** True when a usable client ID is configured. */
function sa_google_login_enabled(): bool
{
    $id = sa_google_client_id();
    return $id !== '' && substr($id, -strlen('.apps.googleusercontent.com')) === '.apps.googleusercontent.com';
}

/**
 * Render the Google sign-in button inside the login panel.
 * If Google cannot render the button (e.g. script blocked), the block hides
 * itself so the page never shows a broken empty box.
 */
function sa_google_login_button(): void
{
    if (is_user_logged_in() || !sa_google_login_enabled()) {
        return;
    }
    $client_id = sa_google_client_id();
    $action = function_exists('wc_get_page_permalink') ? (string) wc_get_page_permalink('myaccount') : home_url('/my-account/');
    ?>
    <div class="sa-otp-block sa-google-block" data-sa-google>
      <h3 class="sa-otp-block__title"><?php esc_html_e('Sign in with Google', 'supreme-autoparts-core'); ?></h3>
      <p class="sa-otp-block__hint"><?php esc_html_e('Fastest - one tap with your Google account. No code to wait for.', 'supreme-autoparts-core'); ?></p>
      <div class="sa-google-block__btn" data-sa-google-btn>
        <div id="g_id_onload"
             data-client_id="<?php echo esc_attr($client_id); ?>"
             data-callback="saGoogleLoginCb"
             data-auto_prompt="false"
             data-itp_support="true"></div>
        <div class="g_id_signin"
             data-type="standard"
             data-shape="rectangular"
             data-theme="outline"
             data-text="continue_with"
             data-size="large"
             data-width="280"
             data-logo_alignment="left"></div>
      </div>
      <form class="sa-google-form" method="post" action="<?php echo esc_url($action); ?>" data-sa-google-form style="display:none">
        <input type="hidden" name="sa_google_login" value="1" />
        <input type="hidden" name="sa_google_credential" value="" data-sa-google-cred />
        <?php wp_nonce_field('sa_google_login', 'sa_google_nonce'); ?>
      </form>
      <p class="sa-google-block__err" data-sa-google-err style="display:none"><?php esc_html_e('Google sign-in is unavailable right now. Use the emailed login code below.', 'supreme-autoparts-core'); ?></p>
      <div class="sa-google-block__or"><span><?php esc_html_e('or', 'supreme-autoparts-core'); ?></span></div>
    </div>
    <script src="https://accounts.google.com/gsi/client" async defer onerror="saGoogleLoginFail()"></script>
    <script>
    function saGoogleLoginCb(resp) {
      try {
        if (!resp || !resp.credential) { saGoogleLoginFail(); return; }
        var f = document.querySelector('[data-sa-google-form]');
        f.querySelector('[data-sa-google-cred]').value = resp.credential;
        f.submit();
      } catch (e) { saGoogleLoginFail(); }
    }
    function saGoogleLoginFail() {
      var b = document.querySelector('[data-sa-google]');
      if (b) { b.style.display = 'none'; }
    }
    window.addEventListener('load', function () {
      setTimeout(function () {
        var holder = document.querySelector('[data-sa-google-btn]');
        if (holder && !holder.querySelector('iframe')) { saGoogleLoginFail(); }
      }, 4000);
    });
    </script>
    <?php
}

/** Verify a Google ID token against Google's tokeninfo endpoint. */
function sa_google_verify_id_token(string $credential)
{
    $resp = wp_remote_get(
        'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($credential),
        ['timeout' => 10, 'redirection' => 0]
    );
    if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
        return false;
    }
    $claims = json_decode((string) wp_remote_retrieve_body($resp), true);
    if (!is_array($claims)) {
        return false;
    }
    if (($claims['aud'] ?? '') !== sa_google_client_id()) {
        return false;
    }
    if (!in_array(($claims['iss'] ?? ''), ['accounts.google.com', 'https://accounts.google.com'], true)) {
        return false;
    }
    $verified = $claims['email_verified'] ?? '';
    if ($verified !== 'true' && $verified !== true && $verified !== '1') {
        return false;
    }
    if ((int) ($claims['exp'] ?? 0) < time()) {
        return false;
    }
    $email = sanitize_email((string) ($claims['email'] ?? ''));
    if ($email === '' || !is_email($email)) {
        return false;
    }
    return [
        'email'      => $email,
        'given_name' => sanitize_text_field((string) ($claims['given_name'] ?? '')),
        'family_name'=> sanitize_text_field((string) ($claims['family_name'] ?? '')),
    ];
}

/** Per-IP throttle so the endpoint cannot be hammered (30 attempts/hour). */
function sa_google_login_throttled(): bool
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : 'unknown';
    if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_CF_CONNECTING_IP']));
    }
    $key = 'sa_gli_' . md5($ip);
    $n = (int) get_transient($key);
    if ($n >= 30) {
        return true;
    }
    set_transient($key, $n + 1, HOUR_IN_SECONDS);
    return false;
}

/** Handle the posted credential: verify, find or create the customer, log in. */
add_action('wp_loaded', static function (): void {
    if (empty($_POST['sa_google_login'])) {
        return;
    }
    $back = function_exists('wc_get_page_permalink') ? (string) wc_get_page_permalink('myaccount') : home_url('/my-account/');
    $fail = static function (string $code) use ($back): void {
        wp_safe_redirect(add_query_arg('sa_google_error', $code, $back));
        exit;
    };

    if (!sa_google_login_enabled()) {
        $fail('off');
    }
    if (sa_google_login_throttled()) {
        $fail('rate');
    }
    $nonce = isset($_POST['sa_google_nonce']) ? sanitize_text_field(wp_unslash((string) $_POST['sa_google_nonce'])) : '';
    if (!wp_verify_nonce($nonce, 'sa_google_login')) {
        $fail('nonce');
    }
    $credential = isset($_POST['sa_google_credential']) ? (string) wp_unslash($_POST['sa_google_credential']) : '';
    if ($credential === '' || strlen($credential) > 8192) {
        $fail('empty');
    }
    $info = sa_google_verify_id_token($credential);
    if ($info === false) {
        $fail('verify');
    }

    $user = get_user_by('email', $info['email']);
    if (!$user) {
        $email_parts = explode('@', $info['email']);
        $base = sanitize_user((string) ($email_parts[0] ?? ''), true);
        if ($base === '') {
            $base = 'customer';
        }
        $login = $base;
        $i = 2;
        while (username_exists($login)) {
            $login = $base . $i;
            $i++;
        }
        $user_id = wp_insert_user([
            'user_login'   => $login,
            'user_email'   => $info['email'],
            'user_pass'    => wp_generate_password(24, true, true),
            'role'         => 'customer',
            'display_name' => trim($info['given_name'] . ' ' . $info['family_name']) !== '' ? trim($info['given_name'] . ' ' . $info['family_name']) : $base,
            'first_name'   => $info['given_name'],
            'last_name'    => $info['family_name'],
        ]);
        if (is_wp_error($user_id)) {
            $fail('create');
        }
        $user = get_user_by('id', (int) $user_id);
        if (!$user) {
            $fail('create');
        }
    }
    if (function_exists('sa_core_wc_mark_email_verified')) {
        sa_core_wc_mark_email_verified((int) $user->ID);
    }

    wp_clear_auth_cookie();
    wp_set_current_user((int) $user->ID, $user->user_login);
    wp_set_auth_cookie((int) $user->ID, true);
    do_action('wp_login', $user->user_login, $user);

    $redirect = function_exists('wc_get_page_permalink') ? (string) wc_get_page_permalink('myaccount') : home_url('/my-account/');
    wp_safe_redirect($redirect);
    exit;
}, 1);
