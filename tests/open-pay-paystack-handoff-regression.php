<?php
// Native PHP fixture tests for Whop_Open_Pay::paystack_handoff(). In-memory stores only: no network, no provider, no real order.
define('ABSPATH', 1);
function __($s, $d = '') { return $s; }
function is_user_logged_in() { return false; }
function wc_clear_notices() { $GLOBALS['notices_cleared']++; }
final class FakeWpdb {
    public $options = ''; public $rows = [];
    public function prepare($sql, ...$args) { $i = 0; return preg_replace_callback('/%s/', function () use (&$i, $args) { return "'" . addslashes((string) $args[$i++]) . "'"; }, $sql); }
    private function q($sql) { preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $sql, $m); return array_map('stripslashes', $m[1]); }
    public function query($sql) {
        $a = $this->q($sql);
        if (strpos($sql, 'INSERT IGNORE') === 0) { if (isset($this->rows[$a[0]])) return 0; $this->rows[$a[0]] = $a[1]; return 1; }
        if (strpos($sql, 'UPDATE') === 0) { if (($this->rows[$a[1]] ?? null) !== $a[2]) return 0; $this->rows[$a[1]] = $a[0]; return 1; }
        if (strpos($sql, 'DELETE') === 0) { if (($this->rows[$a[0]] ?? null) === $a[1]) { unset($this->rows[$a[0]]); return 1; } return 0; }
        return 0;
    }
    public function get_var($sql) { $a = $this->q($sql); return $this->rows[$a[0]] ?? null; }
}
$wpdb = new FakeWpdb();
class WC_Order_Item_Fee { function set_name($x) {} function set_total($x) {} function set_tax_status($x) {} }
class WC_Order {
    public $id, $meta = [], $notes = [], $paid = false, $pm = '', $cur = '', $total = 0.0;
    function __construct($id) { $this->id = $id; }
    function get_id() { return $this->id; }
    function add_item($x) {} function set_billing_email($x) {} function set_billing_country($x) {} function set_payment_method_title($x) {}
    function set_currency($x) { $this->cur = $x; } function get_currency() { return $this->cur; }
    function set_payment_method($x) { $this->pm = $x; } function get_payment_method() { return $this->pm; }
    function update_meta_data($k, $v) { $this->meta[$k] = $v; } function get_meta($k) { return $this->meta[$k] ?? ''; }
    function add_order_note($n) { $this->notes[] = $n; }
    function calculate_totals($x) {} function set_total($t) { $this->total = $t; } function get_total() { return $this->total; }
    function save() {} function needs_payment() { return !$this->paid; }
    function get_checkout_payment_url() { return 'https://shop.invalid/checkout/order-pay/' . $this->id . '/'; }
}
$GLOBALS['orders'] = []; $GLOBALS['notices_cleared'] = 0;
function wc_create_order($a) { $id = 9000 + count($GLOBALS['orders']) + 1; return $GLOBALS['orders'][$id] = new WC_Order($id); }
function wc_get_order($id) { return $GLOBALS['orders'][(int) $id] ?? false; }
function wc_get_orders($a) { $v = $a['meta_query'][0]['value']; foreach ($GLOBALS['orders'] as $id => $o) { if ($o->get_meta($a['meta_query'][0]['key']) === $v) return [$id]; } return []; }
final class FakeGateway { public $calls = 0; public $mode = 'ok';
    function process_redirect_payment_option($id) { $this->calls++; if ($this->mode === 'throw') throw new Exception('boom');
        if ($this->mode === 'http') return ['result' => 'success', 'redirect' => 'http://checkout.paystack.com/x'];
        if ($this->mode === 'evil') return ['result' => 'success', 'redirect' => 'https://checkout.paystack.com.evil.invalid/x'];
        if ($this->mode === 'null') return null;
        return ['result' => 'success', 'redirect' => 'https://checkout.paystack.com/abc' . $this->calls]; } }
include __DIR__ . '/../wp-content/plugins/whop-payments/includes/class-whop-open-pay.php';
$n = 0; function ok($c, $m) { global $n; $n++; if (!$c) { fwrite(STDERR, "FAIL: $m\n"); exit(1); } }
$i1 = str_repeat('a1', 16); $i2 = str_repeat('b2', 16); $i3 = str_repeat('c3', 16);
$g = new FakeGateway;
// 1 first submit: one order, one initialize, hosted page
$r = Whop_Open_Pay::paystack_handoff(100.0, 'a@x.invalid', '', 'KES', $i1, $g);
ok($r === ['external', 'https://checkout.paystack.com/abc1'] && count($orders) === 1 && $g->calls === 1, 'first submit');
ok($orders[9001]->get_meta('_sa_op_hosted_url') === 'https://checkout.paystack.com/abc1', 'hosted url stored');
// 2 duplicate click / response-loss retry: same order and same hosted URL, no second order, no second initialize
$r = Whop_Open_Pay::paystack_handoff(100.0, 'a@x.invalid', '', 'KES', $i1, $g);
ok($r === ['external', 'https://checkout.paystack.com/abc1'] && count($orders) === 1 && $g->calls === 1, 'duplicate reuses');
// 3 same intent, different details: rejected, nothing created
$r = Whop_Open_Pay::paystack_handoff(200.0, 'a@x.invalid', '', 'KES', $i1, $g);
ok($r[0] === 'error' && count($orders) === 1 && $g->calls === 1, 'mismatch rejected');
$r = Whop_Open_Pay::paystack_handoff(100.0, 'other@x.invalid', '', 'KES', $i1, $g);
ok($r[0] === 'error' && count($orders) === 1, 'other email with same intent rejected (no cross-session reuse)');
// 4 new intent with same fields: an intentional second payment is a new order
$r = Whop_Open_Pay::paystack_handoff(100.0, 'a@x.invalid', '', 'KES', $i2, $g);
ok($r[0] === 'external' && count($orders) === 2 && $g->calls === 2, 'second intentional payment');
// 5 no intent (JS off): order, no initialize, order-pay page
$c = $g->calls; $r = Whop_Open_Pay::paystack_handoff(50.0, 'a@x.invalid', '', 'KES', '', $g);
ok($r[0] === 'internal' && count($orders) === 3 && $g->calls === $c, 'no intent falls back to order-pay without initialize');
$r = Whop_Open_Pay::paystack_handoff(50.0, 'a@x.invalid', '', 'KES', 'not-hex', $g);
ok($r[0] === 'internal' && $g->calls === $c, 'bad intent format falls back');
// 6 unsafe or failed provider results fall back to the SAME order's order-pay page
foreach (['http', 'evil', 'throw', 'null'] as $mode) {
    $g->mode = $mode; $before = count($orders);
    $r = Whop_Open_Pay::paystack_handoff(10.0, 'a@x.invalid', $mode, 'KES', md5($mode . 'x'), $g);
    ok($r[0] === 'internal' && strpos($r[1], '/order-pay/') !== false && count($orders) === $before + 1, "fallback $mode");
    ok($orders[9000 + count($orders)]->get_meta('_sa_op_hosted_url') === '', "no hosted url stored for $mode");
}
ok($GLOBALS['notices_cleared'] > 0, 'gateway notices cleared');
$g->mode = 'ok';
// 7 fresh in-flight claim: fail closed, no order, no initialize
$fp = hash('sha256', implode('|', ['a@x.invalid', '30.00', 'KES', 'paystack', '']));
$wpdb->rows['sa_op_i_' . substr(hash('sha256', $i3), 0, 40)] = 'p|' . $fp . '|' . time();
$b = count($orders); $c = $g->calls;
$r = Whop_Open_Pay::paystack_handoff(30.0, 'a@x.invalid', '', 'KES', $i3, $g);
ok($r[0] === 'error' && count($orders) === $b && $g->calls === $c, 'in-flight claim fails closed');
// 8 stale claim without an order: reclaimed, exactly one order
$wpdb->rows['sa_op_i_' . substr(hash('sha256', $i3), 0, 40)] = 'p|' . $fp . '|' . (time() - 600);
$r = Whop_Open_Pay::paystack_handoff(30.0, 'a@x.invalid', '', 'KES', $i3, $g);
ok($r[0] === 'external' && count($orders) === $b + 1, 'stale claim reclaimed once');
// 9 stale claim whose order exists by intent: reuse, no new order
$i4 = str_repeat('d4', 16); $fp4 = hash('sha256', implode('|', ['a@x.invalid', '40.00', 'KES', 'paystack', '']));
$o = wc_create_order([]); $o->meta['_sa_open_pay_intent'] = hash('sha256', $i4);
$wpdb->rows['sa_op_i_' . substr(hash('sha256', $i4), 0, 40)] = 'p|' . $fp4 . '|' . (time() - 600);
$b = count($orders); $r = Whop_Open_Pay::paystack_handoff(40.0, 'a@x.invalid', '', 'KES', $i4, $g);
ok($r[0] === 'internal' && count($orders) === $b, 'crash recovery reuses existing order');
// 10 intent whose order is already paid: refuse
$orders[9001]->paid = true; $b = count($orders); $c = $g->calls;
$r = Whop_Open_Pay::paystack_handoff(100.0, 'a@x.invalid', '', 'KES', $i1, $g);
ok($r[0] === 'error' && count($orders) === $b && $g->calls === $c, 'paid order is not reused or re-initialized');
// 11 URL allowlist
foreach (['https://checkout.paystack.com/abc', 'https://checkout.paystack.com/abc?x=1'] as $u) ok(Whop_Open_Pay::is_safe_paystack_url($u), "safe $u");
foreach (['http://checkout.paystack.com/a', 'https://checkout.paystack.com.evil.invalid/a', 'https://evil.invalid/checkout.paystack.com', 'https://user@checkout.paystack.com/a', 'https://checkout.paystack.com:444/a', 'https://sub.checkout.paystack.com/a', "https://checkout.paystack.com/a b", 'https://checkout.paystack.com\\@evil.invalid/', 'javascript:alert(1)', '', 'https://checkout.paystack.com/a#f'] as $u) ok(!Whop_Open_Pay::is_safe_paystack_url($u), "unsafe $u");
ok(!Whop_Open_Pay::is_safe_paystack_url(null), 'null unsafe');
echo "$n open-pay Paystack handoff checks PASS (in-memory fixtures only)\n";
