<?php
// Actual priority-ordered shipping callbacks, isolated from WordPress/DB/customers.
define('ABSPATH', __DIR__); $hooks=[]; $couriers=true; $zone='KE';
function add_filter($n,$c,$p=10,$a=1){global $hooks;$hooks[$n][$p][]=$c;} function add_action(...$x){}
function apply_filters($n,$value,...$args){global $hooks;$h=$hooks[$n]??[];ksort($h);foreach($h as $cs)foreach($cs as $c)$value=$c($value,...$args);return $value;}
function get_current_user_id(){return 0;} function sa_couriers_enabled(){global $couriers;return $couriers;} function sa_storefront_country(){global $zone;return $zone;}
function get_option($k,$d=null){return $d;}function wc_price($n,$args=[]){return '$'.$n;}function wp_strip_all_tags($s){return strip_tags($s);}function esc_html($s){return $s;}
class WC_Shipping_Rate {function __construct(public $id,public $label,public $cost,public $taxes=[],public $method_id=''){}function get_method_id(){return $this->method_id;}function set_label($s){$this->label=$s;}function get_cost(){return $this->cost;}function set_cost($c){$this->cost=$c;}function set_taxes($t){$this->taxes=$t;}}
function check($v,$s){if(!$v)throw new Exception($s);echo "PASS $s\n";}
require __DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/shipping.php';
require __DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/geo-content.php';
// Actual marketing callback too, with a throwaway customer fixture only.
class DeliveryCustomer {function __call($name,$args){return $name==='get_shipping_country'?'US':($name==='get_shipping_state'?'CA':'fixture');}}
class DeliveryCart {function needs_shipping(){return true;}}
function WC(){static $w=null;if($w===null)$w=(object)['customer'=>new DeliveryCustomer,'cart'=>new DeliveryCart,'session'=>null];return $w;}
require __DIR__.'/../wp-content/plugins/supreme-marketing/inc/class-sm-welcome.php';
SM_Welcome::init();
// Belt-and-braces: even a legacy campaign free rate cannot leak into Kenya.
add_filter('woocommerce_package_rates',function($rates,$p){if($p['destination']['country']==='KE')$rates=['legacy_free'=>new WC_Shipping_Rate('legacy_free','Old campaign free',0,[],'sm_first_order_free')]+$rates;return $rates;},100,2);
$base=['fargo'=>new WC_Shipping_Rate('fargo','Fargo',7.31,[],'sa_fargo'),'g4s'=>new WC_Shipping_Rate('g4s','G4S',8.14,[],'sa_g4s'),'speedaf'=>new WC_Shipping_Rate('speedaf','Speedaf',6.22,[],'sa_speedaf'),'pickup'=>new WC_Shipping_Rate('pickup','Pickup',0,[],'local_pickup'),'flat'=>new WC_Shipping_Rate('flat','Old $8',8,[],'flat_rate')];
foreach(['KE30','KE28'] as $state){$got=apply_filters('woocommerce_package_rates',$base,['destination'=>['country'=>'KE','state'=>$state]]);check(array_keys($got)===['fargo','g4s','speedaf','pickup'],'Kenya '.$state.' retains only calculated couriers and pickup');check($got['fargo']===$base['fargo']&&$got['g4s']->cost===8.14&&$got['speedaf']->cost===6.22,'courier math/objects untouched '.$state);}
$couriers=false;$got=apply_filters('woocommerce_package_rates',$base,['destination'=>['country'=>'KE','state'=>'KE30']]);check(array_keys($got)===['fargo','pickup'],'disabled extra couriers remain disabled');$couriers=true;
foreach(['CA','AK','HI'] as $state){$got=apply_filters('woocommerce_package_rates',$base,['destination'=>['country'=>'US','state'=>$state]]);check(count($got)===1&&isset($got['sa_us_free'])&&$got['sa_us_free']->cost===0,'US '.$state.' free without minimum or legacy paid options');check(apply_filters('woocommerce_shipping_chosen_method','flat',$got,null)==='sa_us_free','US chosen rate is free');}
$row=['intl'=>new WC_Shipping_Rate('intl','International',25,[],'flat_rate')];check(apply_filters('woocommerce_package_rates',$row,['destination'=>['country'=>'GB']])===$row,'other country unchanged');
$packages=apply_filters('woocommerce_cart_shipping_packages',[['destination'=>['country'=>'US']]]);check(isset($packages[0]['sa_delivery_policy']),'cache invalidated by package policy version without DB changes');
foreach(['KE','US','GB'] as $z){$zone=$z;$html=sa_gc_shipping_html();if($z==='US')check(str_contains($html,'Free shipping on USA orders')&&!preg_match('/199|Standard Shipping|Priority Shipping/',$html),'US policy no threshold or charge');elseif($z==='KE')check(str_contains($html,'parcel weight')&&!preg_match('/199|\$8|\$15|Free delivery/',$html),'Kenya policy calculated');else check(str_contains($html,'$25'),'international policy unchanged');}
$marketing=file_get_contents(__DIR__.'/../wp-content/plugins/supreme-marketing/inc/class-sm-welcome.php');check(str_contains($marketing,"!== 'US' || !self::offer_destination_allowed('US')"),'November free-rate callback is US only');check(!str_contains($marketing,'All US &amp; Kenya orders · No minimum'),'banner no longer promises free Kenya shipping');check(str_contains($marketing,"'amount'=>30")&&str_contains($marketing,"2026-12-01 00:00:00"),'November discount and expiry unchanged');
