<?php
// Isolated metadata callback only. No database, requests or customer effects.
define('ABSPATH', __DIR__);
$hooks = [];
function add_filter($name, $callback, ...$args) { $GLOBALS['hooks'][$name][] = $callback; }
function add_action(...$args) {}
function get_post_field($field, $id) { return $GLOBALS['slugs'][$id] ?? ''; }
require __DIR__ . '/../wp-content/plugins/supreme-autoparts-core/includes/geo-content.php';
$callbacks = $hooks['get_post_metadata'];
function metadata($value, $id, $key, $single) {
    global $callbacks;
    foreach ($callbacks as $callback) { $value = $callback($value, $id, $key, $single); }
    return $value;
}
function check($value, $label) {
    if (!$value) { throw new RuntimeException($label); }
    echo "PASS $label\n";
}
$slugs = [11 => 'returns', 12 => 'refund-policy', 13 => 'shipping-policy', 14 => 'returns-example'];
$expected = 'Unused products may be returned within 15 days through My Account > Returns. See the policy for conditions and vehicle-service booking terms.';
foreach ([11, 12] as $id) {
    check(metadata(null, $id, '_sa_seo_description', true) === $expected, 'policy single description ' . $id);
    check(metadata(null, $id, '_sa_seo_description', false) === [$expected], 'policy list description ' . $id);
    check(metadata('keep', $id, '_sa_seo_title', true) === 'keep', 'title unchanged ' . $id);
    check(metadata('keep', $id, 'other', true) === 'keep', 'other metadata unchanged ' . $id);
}
foreach ([13, 14, 999] as $id) {
    check(metadata(null, $id, '_sa_seo_description', true) === null, 'unrelated fallback retained ' . $id);
    check(metadata(['keep'], $id, '_sa_seo_description', false) === ['keep'], 'unrelated existing value retained ' . $id);
}
