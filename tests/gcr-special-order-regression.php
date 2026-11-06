<?php
/** Stubbed WP/Woo only. No network, orders or surveys. Special-order orders without a verified lead time get no GCR opt-in. */
define('ABSPATH', 1); define('DAY_IN_SECONDS', 86400);
$GLOBALS['meta'] = []; $GLOBALS['order'] = null; $GLOBALS['hooks'] = [];
function get_post_meta($id, $k, $single = false) { return $GLOBALS['meta'][$id][$k] ?? ''; }
function add_action($t, $cb, $p = 10, $n = 1) { $GLOBALS['hooks'][$t] = $cb; }
function wc_get_order($id) { return $GLOBALS['order']; }
function is_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL); }
function wp_json_encode($v) { return json_encode($v); }
class WC_Product { function __construct(public int $id, public int $parent = 0) {} function get_id() { return $this->id; } function get_parent_id() { return $this->parent; } }
class FixtureItem { function __construct(public $p) {} function get_product() { return $this->p; } }
class WC_Order { public $items = [];
  function get_items() { return $this->items; } function is_paid() { return true; } function get_billing_email() { return 'fixture@example.invalid'; }
  function get_shipping_country() { return 'KE'; } function get_billing_country() { return 'KE'; } function get_order_number() { return '1'; }
  function get_date_paid() { return null; } function get_date_created() { return null; } }
function check($ok, $label) { if (!$ok) { fwrite(STDERR, "FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
require __DIR__ . '/../wp-content/plugins/supreme-autoparts-core/includes/google-customer-reviews.php';
function render(array $products): string { $o = new WC_Order; foreach ($products as $p) { $o->items[] = new FixtureItem($p); } $GLOBALS['order'] = $o; ob_start(); ($GLOBALS['hooks']['woocommerce_thankyou'])(1); return ob_get_clean(); }
$normal = new WC_Product(10); $flag = new WC_Product(20); $known = new WC_Product(30); $var = new WC_Product(41, 40);
$GLOBALS['meta'][20]['_sa_source_special_order'] = 'yes';
$GLOBALS['meta'][30]['_sa_source_special_order'] = 'yes'; $GLOBALS['meta'][30]['_sa_stock_lead_days'] = '3';
$GLOBALS['meta'][40]['_sa_source_special_order'] = 'yes';
check(str_contains(render([$normal]), 'estimated_delivery_date'), 'ordinary order keeps the survey opt-in');
check(render([$flag]) === '', 'flagged unknown-lead order: no opt-in, no invented date');
check(render([$normal, $flag]) === '', 'mixed order containing a flagged item: no opt-in');
check(str_contains(render([$known]), 'estimated_delivery_date'), 'special-order item with a known lead time keeps the opt-in');
check(render([$var]) === '', 'variation resolves to its flagged parent');
check(str_contains(render([$normal, $known]), 'estimated_delivery_date'), 'normal plus known-lead order unchanged');
