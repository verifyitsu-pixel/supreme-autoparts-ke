<?php
// Static source checks (not runtime proof): Server-Timing stays aggregate-only and on a fixed route whitelist.
function ok($c, $m) { if (!$c) { fwrite(STDERR, "FAIL $m\n"); exit(1); } }
$f = __DIR__ . '/../wp-content/mu-plugins/sa-server-timing.php';
$s = file_get_contents($f);
exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($f) . ' 2>&1', $o, $rc);
ok($rc === 0, 'php -l: ' . implode(' ', $o));
foreach (["'update_order_review'", "'sa_geo_resolve'", "'search'", "'wc_ajax_other'"] as $r) { ok(strpos($s, $r) !== false, "route $r"); }
ok(strpos($s, 'SAVEQUERIES') === false, 'no SAVEQUERIES');
ok(strpos($s, 'REQUEST_URI') === false && strpos($s, '$_COOKIE') === false && strpos($s, '$_POST') === false, 'no uri/cookie/post data read');
ok(strpos($s, 'last_query') === false && strpos($s, '->queries') === false, 'no query text');
ok(strpos($s, 'headers_sent()') !== false, 'only sets header before output');
ok(strpos($s, "header('Server-Timing: '") !== false, 'emits Server-Timing');
ok(strpos($s, 'wc-ajax') !== false && strpos($s, "'s'") !== false, 'route detection');
ok(strpos($s, 'wp_query_sum') !== false && strpos($s, "'posts_pre_query'") !== false, 'wp_query phase timing');
ok(strpos($s, 'sa_st_init') !== false && strpos($s, 'init_p') !== false, 'init priority timing');
echo "Server-Timing static checks PASS (source assertions, not runtime proof)\n";
