<?php
/** Stubbed WP only. No network, no WP boot. Proves the warmer sends a cookie that lets advanced-cache store the render. */
define('ABSPATH', 1); define('MINUTE_IN_SECONDS', 60);
$GLOBALS['reqs'] = []; $GLOBALS['tr'] = [];
function add_filter(...$a) {} function add_action(...$a) {}
function taxonomy_exists($t) { return true; } function get_terms($a) { return ['parts', 'oils']; } function is_wp_error($v) { return false; }
function home_url($p = '') { return 'https://store.invalid' . $p; }
function get_transient($k) { return $GLOBALS['tr'][$k] ?? false; } function set_transient($k, $v, $t) { $GLOBALS['tr'][$k] = $v; return true; } function delete_transient($k) { unset($GLOBALS['tr'][$k]); return true; }
function wp_remote_get($u, $a) { $GLOBALS['reqs'][] = [$u, $a]; return []; }
function check($ok, $label) { if (!$ok) { fwrite(STDERR, "FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
require __DIR__ . '/../wp-content/mu-plugins/sa-page-cache-warm.php';
sa_page_cache_warm_run();
check(count($reqs) === 8, 'KE and US each warm home, shop and 2 categories');
$cc = []; $okc = true; $only = true;
foreach ($reqs as [$u, $a]) {
    $cc[$a['headers']['X-Country-Code']] = true;
    $ck = (string) ($a['headers']['Cookie'] ?? '');
    $okc = $okc && (bool) preg_match('/^sm_wid=[A-Za-z0-9]{16,64}$/', $ck);
    $only = $only && strpos($ck, ';') === false;
}
check(array_keys($cc) === ['KE', 'US'], 'only KE and US buckets are warmed');
check($okc, 'warm requests carry a valid sm_wid so no Set-Cookie blocks cache storage');
check($only, 'no other cookie (cart, session, personalisation) is sent');
check(!isset($GLOBALS['tr']['sa_page_cache_warm_lock']), 'lock released');
