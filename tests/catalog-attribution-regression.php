<?php
// Isolated runtime tests. Optional MySQL is runner-local only, never the production host.
define('ABSPATH', __DIR__ . '/');
define('SA_CATALOG_TRACE_TEST', true);
require __DIR__ . '/../wp-content/mu-plugins/sa-catalog-attribution.php';
$passed = 0;
function check($condition, string $name): void { global $passed; if (!$condition) { fwrite(STDERR, "FAIL: $name\n"); exit(1); } $passed++; echo "PASS: $name\n"; }
$GLOBALS['hooks'] = [];
function add_filter($tag, $callback, $priority = 10) { $GLOBALS['hooks'][$tag][] = $callback; }
function add_action($tag, $callback, $priority = 10) { $GLOBALS['hooks'][$tag][] = $callback; }
$GLOBALS['route_mode'] = 'other';
function wp_doing_ajax() { return $GLOBALS['route_mode'] === 'ajax'; }
function is_admin() { return $GLOBALS['route_mode'] === 'admin'; }
function is_account_page() { return $GLOBALS['route_mode'] === 'account'; }
function is_cart() { return $GLOBALS['route_mode'] === 'cart'; }
function is_checkout() { return $GLOBALS['route_mode'] === 'checkout'; }
function is_front_page() { return $GLOBALS['route_mode'] === 'home'; }
function is_shop() { return $GLOBALS['route_mode'] === 'shop'; }
class WP_Term {}
function is_product_taxonomy() { return $GLOBALS['route_mode'] === 'taxonomy'; }
function get_queried_object() { return new WP_Term(); }
function get_term_link($term) { return 'https://example.invalid/product-category/brakes/'; }
function is_product() { return in_array($GLOBALS['route_mode'], ['product','draft-product'], true); }
function get_queried_object_id() { return 56; }
function get_post_status($id) { return $GLOBALS['route_mode'] === 'draft-product' ? 'draft' : 'publish'; }
function is_search() { return $GLOBALS['route_mode'] === 'search'; }
function home_url($path) { return 'https://example.invalid' . $path; }
function wc_get_page_id($name) { return 42; }
function get_permalink($id) { return $id === 56 ? 'https://example.invalid/product/test-part/' : 'https://example.invalid/shop/'; }
$d = sys_get_temp_dir() . '/sa-catalog-trace-test-' . getmypid();
mkdir($d, 0700);
function control(string $dir, array $data): void { file_put_contents($dir . '/control.json', json_encode($data)); chmod($dir . '/control.json', 0600); clearstatcache(); }
check(sa_cat_trace_config($d, time()) === null, 'default off with no control');
sa_cat_trace_boot($d); check($GLOBALS['hooks'] === [], 'default off registers no query hook');
$n = time(); $c = ['start' => $n - 1, 'end' => $n + 100];
control($d, $c); check(sa_cat_trace_config($d, $n) === $c, 'valid private bounded control');
check(sa_cat_trace_config($d, $c['end']) === null, 'hard end is exclusive');
check(sa_cat_trace_config($d, $c['start'] - 1) === null, 'future start disabled');
control($d, ['start' => $n, 'end' => $n + 10801]); check(sa_cat_trace_config($d, $n) === null, 'reject beyond three hours');
control($d, ['start' => (string) $n, 'end' => $n + 10]); check(sa_cat_trace_config($d, $n) === null, 'reject noninteger control');
control($d, $c); chmod($d . '/control.json', 0644); clearstatcache(); check(sa_cat_trace_config($d, $n) === null, 'reject readable control');
control($d, $c); chmod($d, 0755); clearstatcache(); check(sa_cat_trace_config($d, $n) === null, 'reject nonprivate directory');
chmod($d, 0700); clearstatcache();
$fixture = trim(file_get_contents(__DIR__ . '/catalog-attribution-normalized-fixture.txt'));
$target = '8fa7fe3455d866c7988a8a89fc09a07be809b0415e7a8a027c88f90aca5a140f';
check(sa_cat_trace_fingerprint($fixture) === $target, 'known redacted slow-log shape matches fingerprint');
check(sa_cat_trace_fingerprint(rtrim($fixture, ';')) === $target, 'optional slow-log semicolon ignored');
check(sa_cat_trace_fingerprint("SELECT 1 WHERE x='private literal' AND y=42;") === sa_cat_trace_fingerprint("SELECT 9 WHERE x='another value' AND y=66;"), 'quoted and numeric literals removed');
check(sa_cat_trace_fingerprint('SELECT 1') !== $target, 'unrelated SQL not matched');
$_GET = ['s' => 'private search@example.invalid', 'filter_brand' => 'secret-brand-value', 'token' => 'secret-token'];
$_SERVER['REQUEST_URI'] = '/account/reset/secret-token?s=private';
$GLOBALS['route_mode'] = 'shop'; $route = sa_cat_trace_route();
check($route['public_path'] === '/shop/', 'path from canonical public object, not request URI');
check($route['filter_keys_only'] === ['filter_brand','s'], 'only fixed allowlisted key names');
check(strpos(json_encode($route), 'secret') === false && strpos(json_encode($route), 'private search') === false, 'no query parameter values or raw URI');
foreach (['home' => '/', 'taxonomy' => '/product-category/brakes/', 'product' => '/product/test-part/'] as $mode => $path) {
 $GLOBALS['route_mode'] = $mode; check(sa_cat_trace_route()['public_path'] === $path, $mode . ' canonical public path');
}
foreach (['account','cart','checkout','admin','ajax','search','other','draft-product'] as $mode) {
 $GLOBALS['route_mode'] = $mode; $route = sa_cat_trace_route();
 check(!isset($route['public_path']), $mode . ' route has no private or guessed path');
}
check(sa_cat_trace_append($d, $c, ['schema' => 1, 'route' => ['class' => 'shop']], $n), 'private bounded append succeeds');
check((fileperms($d . '/events.jsonl') & 0777) === 0600, 'record file private');
check(!sa_cat_trace_append($d, $c, ['schema' => 1], $c['end']), 'no writes at deadline');
$h = fopen($d . '/events.jsonl', 'c'); flock($h, LOCK_EX);
check(!sa_cat_trace_append($d, $c, ['schema' => 1], $n), 'contention skips without waiting'); flock($h, LOCK_UN); fclose($h);
file_put_contents($d . '/events.jsonl', str_repeat('x', 1048576));
check(!sa_cat_trace_append($d, $c, ['schema' => 1], $n), 'one MiB cap enforced');
unlink($d . '/events.jsonl'); symlink($d . '/control.json', $d . '/events.jsonl');
check(!sa_cat_trace_append($d, $c, ['schema' => 1], $n), 'symlink event destination rejected'); unlink($d . '/events.jsonl');
$GLOBALS['hooks'] = []; sa_cat_trace_boot($d);
check(count($GLOBALS['hooks']['query']) === 1 && count($GLOBALS['hooks']['shutdown']) === 1, 'enabled hooks registered');
$cb = $GLOBALS['hooks']['query'][0];
check($cb('SELECT 1') === 'SELECT 1', 'unrelated SQL byte unchanged');
check($cb($fixture) === $fixture, 'matching SQL byte unchanged even without mysqli');
if (getenv('SA_TRACE_TEST_MYSQL') === '1') {
 $db = new mysqli('127.0.0.1', 'root', 'root');
 $GLOBALS['wpdb'] = new class($db) {
  protected $dbh;
  function __construct($db) { $this->dbh = $db; }
  function __get($name) { return $this->$name; }
  function __isset($name) { return isset($this->$name); }
 };
 for ($i = 0; $i < 20; $i++) { check($cb($fixture) === $fixture, 'matching existing mysqli SQL unchanged ' . $i); }
 $GLOBALS['route_mode'] = 'shop';
 $GLOBALS['hooks']['shutdown'][0]();
 $record = json_decode(file_get_contents($d . '/events.jsonl'), true);
 check(count($record['queries']) === 16, 'per-request event cap sixteen');
 check($record['queries'][0]['mysql_thread_id'] === mysqli_thread_id($db), 'uses existing MySQL connection id');
 check($record['queries'][0]['fingerprint'] === $target && !isset($record['queries'][0]['sql']), 'fingerprint only, no SQL stored');
 check(strpos(json_encode($record), 'secret-token') === false, 'final emitted record excludes private URI');
 $db->close(); unlink($d . '/events.jsonl');
}
unlink($d . '/control.json'); rmdir($d);
echo "$passed assertions PASS\n";
