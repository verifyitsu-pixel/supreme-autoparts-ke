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
