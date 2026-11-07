<?php
// Static source checks (not browser proof): the Recommended label is only on the Paystack rows, and Paystack stays the
// KES default on /pay/ while USD keeps it disabled (Paystack is KES-only) and Pesapal remains the USD default.
function ok($c, $m) { if (!$c) { fwrite(STDERR, "FAIL $m\n"); exit(1); } }
$pm = file_get_contents(__DIR__ . '/../wp-content/themes/supreme-autoparts/woocommerce/checkout/payment-method.php');
ok(substr_count($pm, 'sa-recommended-badge') === 1, 'one checkout badge');
ok(strpos($pm, "<?php if ('paystack' === \$gateway->id) : ?><span class=\"sa-recommended-badge\">Recommended</span><?php endif; ?>") !== false, 'badge only for paystack');
$op = file_get_contents(__DIR__ . '/../wp-content/plugins/whop-payments/includes/class-whop-open-pay.php');
ok(substr_count($op, 'Recommended</span>') === 1, 'one /pay/ badge');
$kes = strpos($op, "<?php if (\$cur === 'KES') : ?>"); ok($kes !== false, 'kes branch');
$else = strpos($op, '<?php else : ?>', $kes); $rec = strpos($op, 'Recommended</span>');
ok($rec > $kes && $rec < $else, 'badge only in enabled KES branch');
ok(strpos($op, 'name="gateway" value="paystack" checked') !== false, 'paystack checked for KES');
ok(strpos($op, "value=\"pesapal\" <?php checked(\$cur !== 'KES'); ?>") !== false, 'pesapal default for USD');
$gw = file_get_contents(__DIR__ . '/../wp-content/plugins/supreme-autoparts-core/includes/gateways-retired.php');
ok(strpos($gw, "isset(\$available['paystack']) ? 'paystack'") !== false, 'checkout default paystack when available');
ok(strpos($gw, "wp_doing_ajax() || isset(\$_GET['wc-ajax'])") !== false, 'ajax refresh keeps customer choice');
echo "8 Paystack recommended/default static checks PASS (source assertions, not browser proof)\n";
