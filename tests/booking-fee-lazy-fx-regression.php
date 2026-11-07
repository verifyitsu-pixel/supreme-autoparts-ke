<?php
// The before_calculate_totals callback must not do the FX-backed booking fee lookup for carts without a booking line.
function ok($c, $m) { if (!$c) { fwrite(STDERR, "FAIL $m\n"); exit(1); } }
$s = file_get_contents(__DIR__ . '/../wp-content/plugins/supreme-autoparts-core/includes/car-services.php');
$a = strpos($s, "add_action('woocommerce_before_calculate_totals'"); ok($a !== false, 'hook present');
$b = strpos($s, '}, 20);', $a); $body = substr($s, $a, $b - $a);
$loop = strpos($body, 'foreach'); $guard = strpos($body, "empty(\$item['sa_booking'])"); $fee = strpos($body, 'sa_svc_fee_usd()');
ok($loop !== false && $guard !== false && $fee !== false, 'parts present');
ok($loop < $guard && $guard < $fee, 'fee lookup only after a booking line is found');
ok(substr_count($body, 'sa_svc_fee_usd()') === 1, 'single lazy lookup');
ok(strpos($body, 'set_price($usd)') !== false, 'booking price still set');
// behavioural check with stubs: no booking line => lookup never called
$calls = 0; function sa_svc_fee_usd() { global $calls; $calls++; return 12.5; }
class P { public $p = null; function set_price($v) { $this->p = $v; } }
class Cart { public $items; function __construct($i) { $this->items = $i; } function get_cart() { return $this->items; } }
$hooks = []; function add_action($n, $f, $p = 10) { global $hooks; $hooks[$n][] = $f; }
$code = substr($s, $a, $b - $a + strlen('}, 20);'));
eval($code);
$plain = new Cart([['data' => new P]]); $hooks['woocommerce_before_calculate_totals'][0]($plain);
ok($calls === 0, 'no FX lookup for a normal cart');
$bp = new P; $bk = new Cart([['sa_booking' => 5, 'data' => $bp], ['sa_booking' => 6, 'data' => new P]]); $hooks['woocommerce_before_calculate_totals'][0]($bk);
ok($calls === 1 && $bp->p === 12.5, 'one lookup, booking priced');
echo "booking-fee lazy FX checks PASS\n";
