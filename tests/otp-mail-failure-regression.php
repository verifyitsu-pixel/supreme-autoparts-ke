<?php
/** Stubbed WP only. No network, no real mail, no DB. A failed mail send must not burn the hourly OTP caps; Brevo connect failures retry once. */
define('ABSPATH', 1); define('HOUR_IN_SECONDS', 3600); define('MINUTE_IN_SECONDS', 60);
$T = []; $mail_ok = false; $remote = []; $hooks = [];
function add_action($t, $cb, ...$a) { $GLOBALS['hooks'][$t][] = $cb; }
function get_transient($k) { return $GLOBALS['T'][$k] ?? false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['T'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['T'][$k]); return true; }
function is_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL); }
function __($s, $d = '') { return $s; }
function wp_hash_password($p) { return 'h'; }
class WP_User { public int $ID = 5; public string $user_email = 'fixture@example.invalid'; }
function get_user_by($f, $v) { return new WP_User; }
class WP_Error { public function __construct(public string $code = '', public string $message = '') {} public function get_error_message() { return $this->message; } }
function is_wp_error($v) { return $v instanceof WP_Error; }
function sa_security_customer_user($u) { return true; }
function sa_core_email_login_otp($u, $c) { return $GLOBALS['mail_ok']; }
function check($ok, $label) { if (!$ok) { fwrite(STDERR, "FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
// Real source text of the OTP functions under test.
$src = file_get_contents(__DIR__ . '/../wp-content/plugins/supreme-autoparts-core/includes/guest-account-otp.php');
foreach (['SA_OTP_TTL = 600', 'SA_OTP_SEND_COOLDOWN = 45', 'SA_OTP_HOURLY_CAP = 5', 'SA_OTP_IP_HOURLY_CAP = 12'] as $c) { [$n, $v] = explode(' = ', $c); define($n, (int) $v); }
foreach (['sa_core_otp_email_key', 'sa_core_otp_client_ip', 'sa_core_otp_can_send', 'sa_core_otp_mark_send', 'sa_core_otp_send'] as $fn) {
    if (!preg_match('/^function ' . $fn . '\(.*?^\}/ms', $src, $m)) { fwrite(STDERR, "FAIL extract $fn\n"); exit(1); }
    eval($m[0]);
}
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$em = 'fixture@example.invalid';
$hour = 'sa_otp_hour_' . hash('sha256', $em); $ip = 'sa_otp_ip_' . hash('sha256', '203.0.113.9'); $cool = 'sa_otp_cool_' . hash('sha256', $em);
$mail_ok = false;
for ($i = 0; $i < 8; $i++) { unset($T[$cool]); $r = sa_core_otp_send($em); }
check(is_wp_error($r) && $r->code === 'sa_otp_mail', 'failed sends report the mail error, not a cap lockout');
check(($T[$hour] ?? 0) === 0 && ($T[$ip] ?? 0) === 0, 'failed sends never count toward the hourly email/IP caps');
check(isset($T[$cool]), 'failed send still sets the short cooldown');
$r = sa_core_otp_send($em);
check(is_wp_error($r) && $r->code === 'sa_otp_cooldown', 'cooldown still blocks an immediate repeat');
unset($T[$cool]); $mail_ok = true; $r = sa_core_otp_send($em);
check($r === true && $T[$hour] === 1 && $T[$ip] === 1, 'a delivered send counts once toward each cap');
for ($i = 0; $i < 4; $i++) { unset($T[$cool]); sa_core_otp_send($em); }
unset($T[$cool]); $r = sa_core_otp_send($em);
check(is_wp_error($r) && $r->code === 'sa_otp_cap', 'hourly cap of 5 delivered sends is still enforced');
// Brevo client: one retry on connect failure only.
function sa_brevo_api_key() { return 'k'; }
function wp_json_encode($v) { return json_encode($v); }
function wp_remote_request($u, $a) { $GLOBALS['remote'][] = $u; $q = array_shift($GLOBALS['queue']); return $q; }
function wp_remote_retrieve_response_code($r) { return $r['code']; } function wp_remote_retrieve_body($r) { return $r['body']; }
require __DIR__ . '/../wp-content/plugins/sa-brevo-mail/includes/class-brevo-api.php';
$remote = []; $queue = [new WP_Error('http_request_failed', 'cURL error 7: Failed to connect'), ['code' => 201, 'body' => '{"messageId":"x"}']];
$res = SA_Brevo_API::request('POST', '/smtp/email', ['a' => 1]);
check($res['ok'] === true && count($remote) === 2, 'connect failure (cURL 7) is retried once and succeeds');
$remote = []; $queue = [new WP_Error('http_request_failed', 'cURL error 28: Operation timed out'), ['code' => 201, 'body' => '{}']];
$res = SA_Brevo_API::request('POST', '/smtp/email', ['a' => 1]);
check($res['ok'] === false && count($remote) === 1, 'timeout after connecting (cURL 28) is NOT retried, so no duplicate mail');
$remote = []; $queue = [new WP_Error('e', 'cURL error 7: x'), new WP_Error('e', 'cURL error 7: x'), ['code' => 201, 'body' => '{}']];
$res = SA_Brevo_API::request('POST', '/smtp/email', ['a' => 1]);
check($res['ok'] === false && count($remote) === 2, 'at most one retry');
check(!empty($hooks['http_api_curl']), 'IPv4 resolve hook registered for the Brevo host');
