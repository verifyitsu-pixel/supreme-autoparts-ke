<?php
// Native PHP fixture tests for Whop_Open_Pay::paystack_handoff(): state machine, ownership and fail-closed paths.
// In-memory stores with failure injection; no network, no provider, no real order. Real-MySQL atomicity is in
// open-pay-claim-mysql-regression.php.
define('ABSPATH', 1);
function __($s, $d = '') { return $s; }
function is_user_logged_in() { return false; }
function wc_clear_notices() { $GLOBALS['notices_cleared']++; }
final class FakeWpdb {
    public $options = 'wp_options'; public $rows = [];
    public $drop_insert_ack = false, $drop_update_ack = false, $fail_update = false, $null_reads = false, $fail_insert = false;
    public function prepare($sql, ...$args) { $i = 0; return preg_replace_callback('/%[sd]/', function ($m) use (&$i, $args) { $v = $args[$i++]; return $m[0] === '%d' ? (string) (int) $v : "'" . addslashes((string) $v) . "'"; }, $sql); }
    private function q($sql) { preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $sql, $m); return array_map('stripslashes', $m[1]); }
    public function query($sql) {
        $a = $this->q($sql);
        if (strpos($sql, 'INSERT IGNORE') === 0) {
            if ($this->fail_insert) return false;
            if (isset($this->rows[$a[0]])) return 0;
            $this->rows[$a[0]] = $a[1];
            if ($this->drop_insert_ack) { $this->drop_insert_ack = false; return false; }
            return 1;
        }
        if (strpos($sql, 'UPDATE') === 0) {
            if ($this->fail_update) return false;
            if (($this->rows[$a[1]] ?? null) !== $a[2]) return 0;
            $this->rows[$a[1]] = $a[0];
            if ($this->drop_update_ack) { $this->drop_update_ack = false; return false; }
            return 1;
        }
        if (strpos($sql, 'DELETE') === 0 && strpos($sql, 'LIKE') !== false) {
            preg_match('/< (\d+) LIMIT/', $sql, $m); $cut = (int) $m[1]; $n = 0;
            foreach ($this->rows as $k => $v) { if (strpos($k, 'sa_op_i_') === 0) { $p = explode('|', $v); if ((int) ($p[3] ?? 0) < $cut) { unset($this->rows[$k]); $n++; } } }
            return $n;
        }
        return 0;
    }
    public function get_var($sql) { if ($this->null_reads) return null; $a = $this->q($sql); return $this->rows[$a[0]] ?? null; }
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
$GLOBALS['orders'] = []; $GLOBALS['notices_cleared'] = 0; $GLOBALS['create_throws'] = false;
function wc_create_order($a) { if ($GLOBALS['create_throws']) throw new Exception('db down'); $id = 9000 + count($GLOBALS['orders']) + 1; return $GLOBALS['orders'][$id] = new WC_Order($id); }
function wc_get_order($id) { return $GLOBALS['orders'][(int) $id] ?? false; }
final class FakeGateway { public $calls = 0; public $mode = 'ok'; public $hook = null;
    function process_redirect_payment_option($id) { $this->calls++; if ($this->hook) { ($this->hook)(); }
        if ($this->mode === 'throw') throw new Exception('boom');
        if ($this->mode === 'http') return ['result' => 'success', 'redirect' => 'http://checkout.paystack.com/x'];
        if ($this->mode === 'evil') return ['result' => 'success', 'redirect' => 'https://checkout.paystack.com.evil.invalid/x'];
        if ($this->mode === 'null') return null;
        return ['result' => 'success', 'redirect' => 'https://checkout.paystack.com/abc' . $this->calls]; } }
include __DIR__ . '/../wp-content/plugins/whop-payments/includes/class-whop-open-pay.php';
$n = 0; function ok($c, $m) { global $n; $n++; if (!$c) { fwrite(STDERR, "FAIL: $m\n"); exit(1); } }
function intent($age = 0) { return sprintf('%08x', time() - $age) . bin2hex(random_bytes(12)); }
function fp($email, $amt, $note = '') { return hash('sha256', implode('|', [strtolower($email), number_format($amt, 2, '.', ''), 'KES', 'paystack', $note])); }
function ckey($i) { return 'sa_op_i_' . substr(hash('sha256', $i), 0, 40); }
function H($amt, $email, $i, $g, $note = '') { return Whop_Open_Pay::paystack_handoff($amt, $email, $note, 'KES', $i, $g); }
function state($i) { global $wpdb; return explode('|', $wpdb->rows[ckey($i)] ?? '')[0]; }
$g = new FakeGateway;

// A. happy path, duplicate, mismatch, second intentional payment
$i1 = intent();
$r = H(100.0, 'a@x.invalid', $i1, $g);
ok($r === ['external', 'https://checkout.paystack.com/abc1'] && count($orders) === 1 && $g->calls === 1 && state($i1) === 'r', 'first submit reaches ready');
$r = H(100.0, 'a@x.invalid', $i1, $g);
ok($r === ['external', 'https://checkout.paystack.com/abc1'] && count($orders) === 1 && $g->calls === 1, 'duplicate reuses hosted URL');
ok(H(200.0, 'a@x.invalid', $i1, $g)[0] === 'error' && H(100.0, 'o@x.invalid', $i1, $g)[0] === 'error' && count($orders) === 1 && $g->calls === 1, 'mismatch and other email rejected');
$r = H(100.0, 'a@x.invalid', intent(), $g);
ok($r[0] === 'external' && count($orders) === 2 && $g->calls === 2, 'new intent is a new intentional payment');

// B. invalid, malformed, stale or future intents never create anything
$c = $g->calls; $b = count($orders);
foreach (['', 'not-hex', strtoupper(intent()), substr(intent(), 0, 31), intent(90000), sprintf('%08x', time() + 4000) . bin2hex(random_bytes(12))] as $bad) {
    $r = H(50.0, 'a@x.invalid', $bad, $g);
    ok($r[0] === 'error' && count($orders) === $b && $g->calls === $c, 'bad/stale/future intent fails closed: ' . $bad);
}

// C. concurrent retry while A is inside the provider call: no order-pay link, no second initialize, no second order
$i2 = intent(); $seen = null; $b = count($orders);
$g->hook = function () use ($i2, &$seen, $g) { $seen = H(70.0, 'a@x.invalid', $i2, $g); };
$r = H(70.0, 'a@x.invalid', $i2, $g);
$g->hook = null;
ok($seen[0] === 'error' && count($orders) === $b + 1, 'retry during initialize fails closed (not order-pay)');
ok($r[0] === 'external' && state($i2) === 'r', 'original request still completes');
$calls_after = $g->calls;

// D. stalled/crashed owner: claim stays p (and o, i), never reclaimed however old; no second order or initialize
foreach (['p', 'o', 'i'] as $st) {
    $id = intent(); $b = count($orders); $c = $g->calls;
    $wpdb->rows[ckey($id)] = "$st|" . fp('a@x.invalid', 30.0) . '|9001|' . (time() - 7200) . '|deadbeefdeadbeef';
    $r = H(30.0, 'a@x.invalid', $id, $g);
    ok($r[0] === 'error' && count($orders) === $b && $g->calls === $c && explode('|', $wpdb->rows[ckey($id)])[0] === $st, "old $st claim fails closed and is kept");
}
// E. kill after the claim, before the order exists: nothing is created by anyone else
$id = intent(); $wpdb->rows[ckey($id)] = 'p|' . fp('a@x.invalid', 31.0) . '|0|' . (time() - 9000) . '|aaaaaaaaaaaaaaaa';
$b = count($orders); ok(H(31.0, 'a@x.invalid', $id, $g)[0] === 'error' && count($orders) === $b, 'claim without order never reclaimed');

// F. order creation throws: claim stays (ambiguous), no initialize
$id = intent(); $GLOBALS['create_throws'] = false;
$b = count($orders); $c = $g->calls;
$wpdb->fail_insert = false;
$id2 = intent();
function create_fail($v) { $GLOBALS['create_throws'] = $v; }
// create_pending_order catches Throwable and returns null
create_fail(true); $r = H(80.0, 'a@x.invalid', $id2, $g); create_fail(false);
ok($r[0] === 'error' && count($orders) === $b && $g->calls === $c && state($id2) === 'p', 'creation failure keeps claim, no initialize');
ok(H(80.0, 'a@x.invalid', $id2, $g)[0] === 'error' && count($orders) === $b, 'retry of an ambiguous creation does not create a second order');

// G. INSERT acknowledgement lost (row written, driver reports failure): we still own it, exactly one order
$wpdb->drop_insert_ack = true; $id3 = intent(); $b = count($orders);
$r = H(90.0, 'a@x.invalid', $id3, $g);
ok($r[0] === 'external' && count($orders) === $b + 1 && state($id3) === 'r', 'insert ack loss resolved by read-back');
// H. UPDATE acknowledgement lost on a transition: read-back proves it
$wpdb->drop_update_ack = true; $id4 = intent(); $b = count($orders); $c = $g->calls;
$r = H(91.0, 'a@x.invalid', $id4, $g);
ok($r[0] === 'external' && count($orders) === $b + 1 && $g->calls === $c + 1, 'update ack loss resolved by read-back, one initialize');
// I. UPDATE fails outright (set_claim false): no initialize
$wpdb->fail_update = true; $id5 = intent(); $b = count($orders); $c = $g->calls;
$r = H(92.0, 'a@x.invalid', $id5, $g); $wpdb->fail_update = false;
ok($r[0] === 'error' && $g->calls === $c && count($orders) === $b + 1 && state($id5) === 'p', 'failed CAS blocks initialize; claim kept');
ok(H(92.0, 'a@x.invalid', $id5, $g)[0] === 'error' && count($orders) === $b + 1 && $g->calls === $c, 'later retry fails closed');
// J. reads fail (null): never treated as "not found"
$id6 = intent(); $wpdb->rows[ckey($id6)] = 'p|' . fp('a@x.invalid', 5.0) . '|0|' . time() . '|aaaaaaaaaaaaaaaa'; $wpdb->null_reads = true;
$b = count($orders); $r = H(5.0, 'a@x.invalid', $id6, $g); $wpdb->null_reads = false;
ok($r[0] === 'error' && count($orders) === $b, 'unreadable claim fails closed');
// K. provider response lost before the URL save (state i stays): retries fail closed
$id7 = intent(); $b = count($orders); $c = $g->calls;
$g->hook = function () { throw new Error('process killed'); };
try { H(11.0, 'a@x.invalid', $id7, $g); } catch (Error $e) {}
$g->hook = null;
ok(state($id7) === 'i', 'crash inside provider call leaves state i');
ok(H(11.0, 'a@x.invalid', $id7, $g)[0] === 'error' && count($orders) === $b + 1 && $g->calls === $c + 1, 'retry after crash: no second order, no second initialize');
// L. unsafe or failed provider results are uncertain (state i): fail closed, never the order-pay page, no second initialize
foreach (['http', 'evil', 'throw', 'null'] as $mode) {
    $g->mode = $mode; $b = count($orders); $id = intent(); $c = $g->calls;
    $r = H(10.0, 'a@x.invalid', $id, $g, $mode);
    ok($r[0] === 'error' && count($orders) === $b + 1 && state($id) === 'i' && $g->calls === $c + 1, "uncertain $mode fails closed");
    ok($orders[9000 + count($orders)]->get_meta('_sa_op_hosted_url') === '', "no hosted url for $mode");
    $r2 = H(10.0, 'a@x.invalid', $id, $g, $mode);
    ok($r2[0] === 'error' && $g->calls === $c + 1 && count($orders) === $b + 1, "retry after $mode: no second initialize or order");
}
// L2. pre-initialize ineligibility (state u) routes to the same order's order-pay page only
$id = intent(); $b = count($orders); $c = $g->calls;
$r = H(10.0, 'a@x.invalid', $id, new stdClass);
ok($r[0] === 'internal' && strpos($r[1], '/order-pay/') !== false && state($id) === 'u' && count($orders) === $b + 1, 'ineligible gateway before initialize -> same order order-pay');
ok(H(10.0, 'a@x.invalid', $id, $g)[1] === $r[1] && $g->calls === $c && count($orders) === $b + 1, 'state u retry goes to the same order, no initialize');
ok($GLOBALS['notices_cleared'] > 0, 'gateway notices cleared');
$g->mode = 'ok';
// M. paid or cancelled order behind a ready claim is refused
$orders[9001]->paid = true; $c = $g->calls; $b = count($orders);
ok(H(100.0, 'a@x.invalid', $i1, $g)[0] === 'error' && $g->calls === $c && count($orders) === $b, 'paid order not reused');
// N. retention: claims older than 48 h are purged on the next claim, and their intents are already refused as stale
$old = 'sa_op_i_' . str_repeat('0', 40); $wpdb->rows[$old] = 'r|' . fp('a@x.invalid', 1.0) . '|9001|' . (time() - 200000) . '|aaaaaaaaaaaaaaaa';
$fresh = 'sa_op_i_' . str_repeat('1', 40); $wpdb->rows[$fresh] = 'r|' . fp('a@x.invalid', 1.0) . '|9001|' . (time() - 100) . '|aaaaaaaaaaaaaaaa';
H(12.0, 'a@x.invalid', intent(), $g);
ok(!isset($wpdb->rows[$old]) && isset($wpdb->rows[$fresh]), 'old claims purged, recent ones kept');
ok(H(1.0, 'a@x.invalid', intent(90000), $g)[0] === 'error', 'a purged intent age is refused');
// O. URL allowlist
foreach (['https://checkout.paystack.com/abc', 'https://checkout.paystack.com/abc?x=1'] as $u) ok(Whop_Open_Pay::is_safe_paystack_url($u), "safe $u");
foreach (['http://checkout.paystack.com/a', 'https://checkout.paystack.com.evil.invalid/a', 'https://evil.invalid/checkout.paystack.com', 'https://user@checkout.paystack.com/a', 'https://checkout.paystack.com:444/a', 'https://sub.checkout.paystack.com/a', "https://checkout.paystack.com/a b", 'https://checkout.paystack.com\\@evil.invalid/', 'javascript:alert(1)', '', 'https://checkout.paystack.com/a#f'] as $u) ok(!Whop_Open_Pay::is_safe_paystack_url($u), "unsafe $u");
ok(!Whop_Open_Pay::is_safe_paystack_url(null), 'null unsafe');
echo "$n open-pay Paystack handoff state-machine checks PASS (in-memory fixtures with failure injection)\n";
