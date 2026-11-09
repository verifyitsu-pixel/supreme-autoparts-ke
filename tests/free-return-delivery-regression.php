<?php
// No WordPress database, customer changes, refunds, payment or network.
define('ABSPATH', __DIR__);
$hooks = []; $slug = 'returns';
function add_action(...$x) {}
function add_filter($n, $c, $p = 10, $a = 1) { global $hooks; $hooks[$n][$p][] = $c; }
function is_main_query() { return true; }
function in_the_loop() { return true; }
function is_page($slugs) { global $slug; return in_array($slug, (array) $slugs, true); }
function apply_content($c) { global $hooks; $h = $hooks['the_content']; ksort($h); foreach ($h as $cs) foreach ($cs as $f) $c = $f($c); return $c; }
require __DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/page-content.php';
require __DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/returns.php';
foreach (['returns', 'refund-policy'] as $slug) {
    $c = apply_content(sa_core_page_definitions()[$slug]['content']);
    if (!str_contains($c, 'Return shipping is free for approved returns.')) throw new RuntimeException('Missing free return delivery');
    if (str_contains($c, 'Customers are responsible for return shipping')) throw new RuntimeException('Legacy return delivery charge');
    if (!str_contains($c, '15 days')) throw new RuntimeException('Return window changed');
    if (!str_contains($c, '20%')) throw new RuntimeException('Unapproved restocking change');
    if (str_contains($c, 'prepaid label') || str_contains($c, 'within 7')) throw new RuntimeException('Invented fulfillment/refund promise');
    if (apply_content($c) !== $c) throw new RuntimeException('Not idempotent');
}
$slug = 'returns';
$old = '<ul><li>Customers are responsible for return shipping costs and fees unless otherwise stated. Original outbound shipping is typically non-refundable.</li></ul>';
if (!str_contains(apply_content($old), 'Return shipping is free')) throw new RuntimeException('Existing stored page not corrected');
$slug = 'terms'; if (apply_content($old) !== $old) throw new RuntimeException('Unrelated page changed');
echo "PASS free approved return delivery on fresh/existing policy pages; restocking/window untouched; no label/refund promise\n";
