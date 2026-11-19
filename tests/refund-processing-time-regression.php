<?php
// Isolated rendering checks. No network, database, refunds or messages.
define('ABSPATH', __DIR__);
$hooks=[];$slug='refund-policy';$main=true;$loop=true;
function add_filter($n,$f,$p=10){global $hooks;$hooks[$n][$p]=$f;}
function is_page($s){global $slug;return $slug===$s;}
function is_main_query(){global $main;return $main;}
function in_the_loop(){global $loop;return $loop;}
require __DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/page-content.php';
$f=$hooks['the_content'][20];
$copy='Approved refunds are processed within 1-5 working days to the original payment method, depending on your bank.';
$fresh=sa_core_page_definitions()['refund-policy']['content'];
if(!str_contains($fresh,$copy)||$f($fresh)!==$fresh)throw new RuntimeException('Fresh policy missing approved timeframe or duplicated');
$old='<p>Refunds follow our Returns policy.</p>';
$out=$f($old);
if(!str_contains($out,$copy)||$f($out)!==$out)throw new RuntimeException('Existing policy missing approved timeframe or not idempotent');
foreach(['returns','terms','contact'] as $slug)if($f($old)!==$old)throw new RuntimeException('Unrelated page changed');
$slug='refund-policy';$main=false;if($f($old)!==$old)throw new RuntimeException('Secondary query changed');
$main=true;$loop=false;if($f($old)!==$old)throw new RuntimeException('Outside-loop content changed');
echo "PASS approved 1-5 working days, original payment method and bank dependence on fresh/existing refund policy only; idempotent and query-scoped\n";
