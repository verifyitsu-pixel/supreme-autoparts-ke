<?php
// A failed live FX fetch is remembered, so later calls (same request or the next 5 minutes) do not wait on the network again.
define('ABSPATH', 1); define('HOUR_IN_SECONDS', 3600); define('MINUTE_IN_SECONDS', 60);
$store = []; $calls = 0;
function get_transient($k) { global $store; return $store[$k] ?? false; }
function set_transient($k, $v, $t) { global $store; $store[$k] = $v; return true; }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function wp_remote_get($u, $a = []) { global $calls; $calls++; return 'ERR'; }
function is_wp_error($v) { return $v === 'ERR'; }
function wp_remote_retrieve_response_code($r) { return 0; }
function check($v, $n) { if (!$v) { fwrite(STDERR, "FAIL $n\n"); exit(1); } echo "PASS $n\n"; }
require dirname(__DIR__) . '/wp-content/plugins/sa-geo-currency/includes/class-sa-fx-rates.php';
check(SA_FX_Rates::rate_for('KES') === null, 'first failing lookup returns null');
$first = $calls; check($first === 2, 'first failure tried both providers once');
check(SA_FX_Rates::rate_for('KES') === null && SA_FX_Rates::convert_usd(10.0, 'KES') === null, 'later lookups still null');
check($calls === $first, 'no further network calls in the same request');
check(!empty($store['sa_fx_rates_fetch_failed_v1']), 'failure remembered for later requests');
check(SA_FX_Rates::rate_for('USD') === 1.0, 'USD unaffected');
echo "FX failure-cache checks PASS\n";
