<?php
// Paystack is moved first only when present; membership, other order and values are untouched.
function ok($c, $m) { if (!$c) { fwrite(STDERR, "FAIL $m\n"); exit(1); } }
$s = file_get_contents(__DIR__ . '/../wp-content/plugins/supreme-autoparts-core/includes/gateways-retired.php');
ok(preg_match('/function sa_paystack_first_gateways\(.*?\n}\n/s', $s, $m) === 1, 'function present');
eval($m[0]);
ok(strpos($s, "'sa_paystack_first_gateways', 10000") !== false, 'final selection hook');
foreach ([['whop'=>'W','pesapal'=>'P','paystack'=>'S'],['pesapal'=>'P'],['paystack'=>'S'],[]] as $g) {
    ok(sa_paystack_first_gateways($g) === (isset($g['pesapal']) ? ['pesapal'=>'P'] : []), 'Pesapal only, never invent availability');
}
ok(sa_paystack_first_gateways(null)===null,'non-array safe');
echo "PASS Pesapal-only gateway selection\n";
