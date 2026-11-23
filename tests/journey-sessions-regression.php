<?php
// Isolated fixture, never boots WordPress or writes a store.
define('ABSPATH',__DIR__); function add_action(...$a){} function add_submenu_page(...$a){}
require __DIR__.'/../wp-content/mu-plugins/sa-00-analytics-privacy.php';
require __DIR__.'/../wp-content/mu-plugins/sa-journey-sessions.php';
function check($v,$why){if(!$v)throw new RuntimeException($why);}
check(sa_tracking_path('/checkout/order-received/123/?key=wc_order_secret&email=a@b.com')==='/checkout/order-received/','order key removed');
check(sa_tracking_path('/product/widget/?utm_source=email&token=secret')==='/product/widget/','queries removed');
check(sa_tracking_path('/my-account/reset-password/alice/')==='/[private]','credential path removed');
check(sa_tracking_path('/contact/a%40b.com')==='/[private]','email path removed');
check(sa_tracking_referrer('https://a:b@example.com/order/123?key=secret#x')==='https://example.com/','origin only');
check(sa_journey_id(str_repeat('a',32))===str_repeat('a',32),'valid random id');
check(sa_journey_id('bad')==='' && sa_journey_id([])==='','untrusted id denied');
$order=new class {public $m=[];function update_meta_data($k,$v){$this->m[$k]=$v;}function get_meta($k){return $this->m[$k]??'';}function save_meta_data(){}};
$_COOKIE=['sa_jv'=>str_repeat('b',32),'sa_js'=>str_repeat('c',32)];sa_journey_order_meta($order);
check(count($order->m)===2,'private order link');
$_COOKIE=['sa_tconsent'=>'0','sa_jv'=>str_repeat('b',32),'sa_js'=>str_repeat('c',32)];$other=clone $order;$other->m=[];sa_journey_order_meta($other);check(!$other->m,'denied order linkage');
echo "PASS URL/referrer minimization, identifier validation, private order correlation and deny guard\n";
$net=sa_journey_network(['REMOTE_ADDR'=>'8.8.8.8','HTTP_X_FORWARDED_FOR'=>'1.2.3.4, 5.6.7.8']);
check($net===['ip'=>'8.8.8.8','forwarding'=>1],'never treat forwarded claim as verified peer');
check(sa_journey_network(['REMOTE_ADDR'=>'127.0.0.1'])['ip']==='','private peer not published as shopper IP');
check(sa_journey_network(['REMOTE_ADDR'=>'not an IP'])['ip']==='','invalid peer rejected');
check(sa_journey_network(['REMOTE_ADDR'=>'2001:4860:4860::8888'])['forwarding']===0,'IPv6 accepted without inventing proxy status');
require __DIR__.'/../wp-content/mu-plugins/sa-order-security-timeline.php';
check(str_contains(sa_journey_device('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17 Mobile Safari/604.1'),'iOS/iPadOS'),'coarse iPhone class');
check(str_contains(sa_journey_device('Mozilla/5.0 (Linux; Android 14) Chrome/120 Mobile Safari/537.36'),'Android'),'coarse Android class');
check(str_contains(sa_journey_device('Mozilla/5.0 (Windows NT 10.0) Chrome/120 Edg/120'),'Edge'),'browser precedence');
echo "PASS peer provenance, forwarded-header spoof rejection, coarse device classes\n";

function add_filter(...$a){}
require __DIR__.'/../wp-content/mu-plugins/sa-order-journey.php';
$fixture=new class {function get_customer_ip_address(){return '8.8.8.8';}function get_customer_user_agent(){return 'HeadlessChrome/120';}function get_meta($k){return '';}};
$f=sa_order_journey_facts($fixture);
check(str_contains($f['IP format assessment'],'Valid public'),'syntax assessed without asserting identity');
check(str_contains($f['Browser automation assessment'],'present'),'saved browser token assessed');
check(str_contains($f['Timezone'],'Not collected')&&str_contains($f['Checkout-scoped browser signature'],'No checkout'),'unavailable additions honest');
$code=file_get_contents(__DIR__.'/../wp-content/mu-plugins/sa-order-journey.php');
check(str_contains($code,'visitor=%s AND session=%s AND ts<=%s'),'exact checkout IDs and pre-order cutoff');
check(!str_contains($code,'wp_remote_')&&!str_contains($code,'<script>'),'read-only local assessment, no external/device probes');
echo "PASS unified order facts, local assessments, exact-session cutoff, no new collection\n";

function wp_salt($v){return 'isolated-test-salt';}
require __DIR__.'/../wp-content/mu-plugins/sa-checkout-signature.php';
$a=sa_checkout_signature(['HTTP_USER_AGENT'=>'browser','REMOTE_ADDR'=>'8.8.8.8'],'one');
check($a===sa_checkout_signature(['HTTP_USER_AGENT'=>'browser','REMOTE_ADDR'=>'1.1.1.1'],'one'),'IP not a fingerprint component');
check($a!==sa_checkout_signature(['HTTP_USER_AGENT'=>'browser'],'two'),'signature cannot link different checkout sessions');
$state=sa_checkout_signature_state(null,$a,1000);$state=sa_checkout_signature_state($state,$a,1020);
check($state['observations']===2&&$state['changes']===0,'same checkout header continuity');
$state=sa_checkout_signature_state($state,str_repeat('b',64),1030);check($state['changes']===1,'changed configuration assessed');
check(sa_checkout_signature_state($state,$a,3000)['observations']===1,'30-minute state window resets');
echo "PASS checkout-only session scope, noIP component, continuity and expiry\n";
