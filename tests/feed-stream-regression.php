<?php
// Run with -d memory_limit=48M. A 65MB feed must complete without retaining chunks.
define('ABSPATH', __DIR__);
function add_action(...$a) {} function add_filter(...$a) {}
function get_option($k, $default = null) { return $k === 'sa_gpf_chunks_ready' ? 65 : $default; }
function get_query_var($k) { return getenv('SA_TEST_FEED') ?: 'google'; }
function wc_get_product() {} function status_header($code) {}
function add_query_arg($k, $v, $url) { return $url . '?' . $k . '=' . $v; }
class TestFeedDB {
    public $options = 'wp_options';
    public function prepare($query, $name) { return $name; }
    public function get_var($name) {
        return '<item><g:id>' . $name . '</g:id><g:link>https://www.supremeautoparts.co.ke/product/test/</g:link><g:price>83.95 USD</g:price><g:description>' . str_repeat('x', 1000000) . '</g:description></item>' . "\n";
    }
}
$wpdb = new TestFeedDB();
require dirname(__DIR__) . '/wp-content/plugins/supreme-autoparts-core/includes/google-product-feed.php';
sa_gpf_render();
