<?php
/** Isolated: runs the real advanced-cache.php with fixture requests; no WordPress, DB or network. */
ob_start(); // keep CLI output buffered so cache header() calls do not warn
define('WP_CACHE', true);
$tmp = sys_get_temp_dir() . '/sa-ac-test-' . getmypid();
@mkdir($tmp, 0777, true);
define('WP_CONTENT_DIR', $tmp);
function ac_run(array $cookies, string $uri = '/', string $method = 'GET', array $extra = []): bool {
    $_COOKIE = $cookies;
    $_SERVER = array_merge(['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'HTTP_HOST' => 'example.test', 'HTTP_CF_IPCOUNTRY' => 'KE'], $extra);
    $before = ob_get_level();
    include __DIR__ . '/../wp-content/advanced-cache.php';
    $started = ob_get_level() > $before; // cache participation starts an output buffer
    while (ob_get_level() > $before) { ob_end_clean(); }
    return $started;
}
$fail = 0;
function expect(string $name, bool $got, bool $want) { global $fail; if ($got === $want) { echo "PASS $name\n"; } else { echo "FAIL $name\n"; $fail++; } }
expect('plain guest GET participates in cache', ac_run([]), true);
expect('geo cookie only (country bucket) participates', ac_run(['sa_geo_cc' => 'KE']), true);
foreach (['sa_pz', 'sa_couriers_preview', 'sa_rs_pt', 'sm_email', 'woocommerce_items_in_cart', 'woocommerce_cart_hash', 'wp_woocommerce_session_abc', 'wordpress_logged_in_abc', 'comment_author_abc', 'sa_storefront_view', 'sa_merchant_usd'] as $c) {
    expect("cookie $c bypasses cache", ac_run([$c => '1']), false);
}
foreach (['/cart', '/checkout/', '/my-account/orders', '/wp-admin/', '/wp-login.php', '/wp-json/x'] as $path) {
    expect("path $path bypasses cache", ac_run([], $path), false);
}
expect('POST bypasses cache', ac_run([], '/', 'POST'), false);
expect('add-to-cart query bypasses cache', ac_run([], '/?add-to-cart=5'), false);
expect('unknown country (no header, no cookie) still renders through PHP (never served from cache)', ac_run([], '/', 'GET', ['HTTP_CF_IPCOUNTRY' => '']) , true);
array_map('unlink', glob("$tmp/cache/sa-page/*") ?: []);
$out = ob_get_clean(); echo $out;
exit($fail ? 1 : 0);
