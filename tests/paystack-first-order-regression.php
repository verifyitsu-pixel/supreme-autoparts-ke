<?php
// Paystack is moved first only when present; membership, other order and values are untouched.
function ok($c, $m) { if (!$c) { fwrite(STDERR, "FAIL $m\n"); exit(1); } }
$s = file_get_contents(__DIR__ . '/../wp-content/plugins/supreme-autoparts-core/includes/gateways-retired.php');
ok(preg_match('/function sa_paystack_first_gateways\(.*?\n}\n/s', $s, $m) === 1, 'function present');
eval($m[0]);
ok(strpos($s, "add_filter('woocommerce_available_payment_gateways', 'sa_paystack_first_gateways', 1000);") !== false, 'hooked after the careers filter (999)');
$g = ['whop' => 'W', 'pesapal' => 'P', 'paystack' => 'S'];
$o = sa_paystack_first_gateways($g);
ok(array_keys($o) === ['paystack', 'whop', 'pesapal'], 'paystack first, others keep order');
ok($o['whop'] === 'W' && $o['pesapal'] === 'P' && $o['paystack'] === 'S' && count($o) === 3, 'same members and values');
$n = ['whop' => 'W', 'pesapal' => 'P'];
ok(sa_paystack_first_gateways($n) === $n, 'no paystack: unchanged (not added)');
$a = ['paystack' => 'S', 'pesapal' => 'P'];
ok(sa_paystack_first_gateways($a) === $a, 'already first: unchanged');
ok(sa_paystack_first_gateways(null) === null && sa_paystack_first_gateways([]) === [], 'non-array/empty safe');
echo "Paystack-first ordering checks PASS (stubbed, not browser proof)\n";
