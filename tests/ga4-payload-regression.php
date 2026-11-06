<?php
// Local fixtures only. Does not boot WordPress, call a gateway, or write a store.
define('ABSPATH',__DIR__);function add_action(...$args){}function is_admin(){return false;}function is_user_logged_in(){return false;}function current_user_can($cap){return false;}
function wc_get_price_excluding_tax($p){return 12.5;}
require __DIR__.'/../wp-content/mu-plugins/sa-google-analytics.php';
require __DIR__.'/../wp-content/mu-plugins/sa-ga4-commerce.php';
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$product=new class {function get_id(){return 17;}function get_name(){return 'Fixture product';}};
$item=sa_ga4_commerce_item($product,2);check($item['item_id']==='17'&&$item['quantity']===2&&$item['price']===12.5,'product payload');
check(sa_ga4_commerce_item(null)===[],'invalid product');check(sa_ga4_commerce_item($product,0)===[],'invalid quantity');
$oi=new class {function get_quantity(){return 2;}function get_variation_id(){return 17;}function get_product_id(){return 16;}function get_name(){return 'Fixture product';}function get_total(){return 20;}};
$order=new class($oi) {private $item;function __construct($i){$this->item=$i;}function get_items(){return [$this->item];}function get_id(){return 123;}function get_currency(){return 'USD';}function get_total_tax(){return 2;}function get_shipping_total(){return 4;}};
$d=sa_ga4_purchase_payload($order);check($d['value']===20.0&&$d['currency']==='USD'&&$d['tax']===2.0&&$d['shipping']===4.0,'paid payload totals');
check(array_keys($d)===['transaction_id','currency','value','tax','shipping','items'],'identity fields absent');
check(!preg_match('/email|address|phone|customer/i',json_encode($d)),'identity absent');
echo "PASS fixture product/purchase totals, invalid qty/product, no identity fields\n";

$eligible=new class {public $status='processing';public $id=123;public $paid=true;function get_status(){return $this->status;}function get_id(){return $this->id;}function is_paid(){return $this->paid;}};
check(sa_ga4_purchase_eligible($eligible),'processing allowed');$eligible->status='staged';check(!sa_ga4_purchase_eligible($eligible),'staged denied');$eligible->status='pending';check(!sa_ga4_purchase_eligible($eligible),'pending denied');$eligible->status='completed';$eligible->paid=false;check(!sa_ga4_purchase_eligible($eligible),'unpaid denied');$eligible->paid=true;$eligible->id=76318;if(sa_ga4_id()==='G-LCH3Y7NNRY')check(!sa_ga4_purchase_eligible($eligible),'quarantined denied');echo "PASS purchase eligibility guards\n";
