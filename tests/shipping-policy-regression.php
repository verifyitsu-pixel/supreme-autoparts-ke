<?php
// Actual priority-ordered shipping callbacks, isolated from WordPress/DB/customers.
define('ABSPATH', __DIR__); $hooks=[]; $couriers=true; $zone='KE';
function add_filter($n,$c,$p=10,$a=1){global $hooks;$hooks[$n][$p][]=$c;} function add_action(...$x){}
function apply_filters($n,$value,...$args){global $hooks;$h=$hooks[$n]??[];ksort($h);foreach($h as $cs)foreach($cs as $c)$value=$c($value,...$args);return $value;}
function sa_ff_contents_unknown_special_order($contents){return !empty($contents['fixture_special_order']);} function get_current_user_id(){return 0;} function sa_couriers_enabled(){global $couriers;return $couriers;} function sa_storefront_country(){global $zone;return $zone;}
function get_option($k,$d=null){return $d;}function wc_price($n,$args=[]){return '$'.$n;}function wp_strip_all_tags($s){return strip_tags($s);}function esc_html($s){return $s;}
class WC_Shipping_Rate {function __construct(public $id,public $label,public $cost,public $taxes=[],public $method_id=''){}function get_method_id(){return $this->method_id;}function set_label($s){$this->label=$s;}function get_label(){return $this->label;}function get_cost(){return $this->cost;}function set_cost($c){$this->cost=$c;}function set_taxes($t){$this->taxes=$t;}}
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
foreach(['KE13','KE28'] as $state){$got=apply_filters('woocommerce_package_rates',$base,['destination'=>['country'=>'KE','state'=>$state]]);check(array_keys($got)===['fargo','g4s','speedaf','pickup'],'Kenya '.$state.' retains only calculated couriers and pickup');check($got['fargo']===$base['fargo']&&$got['g4s']->cost===8.14&&$got['speedaf']->cost===6.22,'courier math/objects untouched '.$state);}
$couriers=false;$got=apply_filters('woocommerce_package_rates',$base,['destination'=>['country'=>'KE','state'=>'KE28']]);check(array_keys($got)===['fargo','pickup'],'disabled extra couriers remain disabled');$couriers=true;
foreach(['CA','AK','HI'] as $state){$got=apply_filters('woocommerce_package_rates',$base,['destination'=>['country'=>'US','state'=>$state]]);check(count($got)===1&&isset($got['sa_us_free'])&&$got['sa_us_free']->cost===0,'US '.$state.' free without minimum or legacy paid options');check(apply_filters('woocommerce_shipping_chosen_method','flat',$got,null)==='sa_us_free','US chosen rate is free');}
$row=['intl'=>new WC_Shipping_Rate('intl','International',25,[],'flat_rate')];check(apply_filters('woocommerce_package_rates',$row,['destination'=>['country'=>'GB']])===$row,'other country unchanged');
$packages=apply_filters('woocommerce_cart_shipping_packages',[['destination'=>['country'=>'US']]]);check(isset($packages[0]['sa_delivery_policy']),'cache invalidated by package policy version without DB changes');
foreach(['KE','US','GB'] as $z){$zone=$z;$html=sa_gc_shipping_html();if($z==='US')check(str_contains($html,'Free shipping on USA orders')&&!preg_match('/199|Standard Shipping|Priority Shipping/',$html),'US policy no threshold or charge');elseif($z==='KE')check(str_contains($html,'parcel weight')&&str_contains($html,'no weight limit')&&!preg_match('/199|\$8|\$15/',$html),'Kenya policy calculated');else check(str_contains($html,'$25'),'international policy unchanged');}
$marketing=file_get_contents(__DIR__.'/../wp-content/plugins/supreme-marketing/inc/class-sm-welcome.php');check(str_contains($marketing,"!== 'US' || !self::offer_destination_allowed('US')"),'November free-rate callback is US only');check(!str_contains($marketing,'All US &amp; Kenya orders · No minimum'),'banner no longer promises free Kenya shipping');check(str_contains($marketing,"'amount'=>self::november_percent()")&&str_contains($marketing,"2026-12-01 00:00:00"),'November discount and expiry unchanged');

$special=apply_filters('woocommerce_package_rates',$base,['destination'=>['country'=>'US'],'contents'=>['fixture_special_order'=>true]]);check($special['sa_us_free']->cost===0&&str_contains($special['sa_us_free']->label,'Special order: dates not confirmed'),'US free rate preserves unknown special-order dates');

$normal=apply_filters('woocommerce_package_rates',$base,['destination'=>['country'=>'US'],'contents'=>[]]);check(str_contains($normal['sa_us_free']->label,'1-3 working days from order, excluding weekends'),'US owner-confirmed total window');
$ke=apply_filters('woocommerce_package_rates',$base,['destination'=>['country'=>'KE','state'=>'KE28'],'contents'=>[]]);check(str_contains($ke['fargo']->label,'1-2 working days from order, excluding weekends'),'Kenya owner-confirmed total window');

// Local delivery has no weight requirement. Outside the named area keeps courier rates.
class WeightProduct {
    function __construct(public $weight, public $physical=true){}
    function get_weight(){return $this->weight;}
    function needs_shipping(){return $this->physical;}
}
function wc_get_weight($weight,$to){return $weight;}
function delivery_package($weight,$quantity=1,$state='KE30',$city=''){
    return ['destination'=>['country'=>'KE','state'=>$state,'city'=>$city], 'contents'=>[['data'=>new WeightProduct($weight),'quantity'=>$quantity]]];
}
$covered = [
    'KE13'=>['Kiambu','Thika','Ruiru','Juja','Kikuyu','Limuru','Karuri','Banana','Banana Hill','Ruaka','Ndenderu','Muchatha','Kabete','Uthiru','Kinoo'],
    'KE10'=>['Ngong','Ongata Rongai','Rongai','Kiserian','Kitengela'],
    'KE22'=>['Athi River','Mavoko','Syokimau','Mlolongo','Joska','Kamulu'],
    'KE30'=>['','Nairobi','Karen','Ruai'],
];
foreach($covered as $state=>$cities) foreach($cities as $city) foreach([0.5,10,100,1000,'',0,-1,'unknown'] as $kg){
    $got=apply_filters('woocommerce_package_rates',$base,delivery_package($kg,5,$state,$city));
    check(array_keys($got)===['sa_nairobi_free','pickup'] && $got['sa_nairobi_free']->cost===0,'free no-weight-limit '.$state.' '.$city.' weight='.var_export($kg,true));
    check(apply_filters('woocommerce_shipping_chosen_method','fargo',$got,null)==='sa_nairobi_free','local free method selected');
}
foreach([['KE13','Gatundu'],['KE13','Lari'],['KE10','Namanga'],['KE10','Kajiado'],['KE22','Machakos'],['KE22','Kangundo'],['KE28','Rongai'],['KE13','Thika Road'],['KE13','Not Thika'],['KE10','Ngong Hills Lodge'],['KE22',''],['','Thika'],['KE28','Thika'],['KE13','Ngong']] as [$state,$city]){
    foreach([1,100,''] as $kg){$got=apply_filters('woocommerce_package_rates',$base,delivery_package($kg,1,$state,$city));check(array_keys($got)===['fargo','g4s','speedaf','pickup']&&$got['fargo']===$base['fargo'],'outside boundary retains courier objects '.$state.' '.$city);}
}
foreach([['KIAMBU COUNTY','  tHiKa '],['Kajiado','Ongata-Rongai'],['Machakos County','Athi  River'],['NAIROBI COUNTY','']] as [$state,$city]){check(sa_local_free_delivery_area(['country'=>'KE','state'=>$state,'city'=>$city]),'normalized county/town '.$state.' '.$city);}
check(!sa_local_free_delivery_area(['country'=>'US','state'=>'KE13','city'=>'Thika']),'county name never grants another country free local delivery');
$split=apply_filters('woocommerce_cart_shipping_packages',[delivery_package(600),delivery_package(600)]);
foreach($split as $part){check(isset(apply_filters('woocommerce_package_rates',$base,$part)['sa_nairobi_free']),'split/heavy order local delivery remains free');}
$special=delivery_package('');$special['contents']['fixture_special_order']=true;
$got=apply_filters('woocommerce_package_rates',$base,$special);check(str_contains($got['sa_nairobi_free']->label,'dates not confirmed'),'local free rate preserves special-order unconfirmed dates');
$couriers=false;
$got=apply_filters('woocommerce_package_rates',$base,delivery_package('',1,'KE13','Thika'));check(isset($got['sa_nairobi_free']),'local free independent of extra courier toggle');
$got=apply_filters('woocommerce_package_rates',$base,delivery_package(100,1,'KE13','Gatundu'));check(array_keys($got)===['fargo','pickup'],'outside extra couriers toggle unchanged');
echo "Local no-weight delivery regression completed\n";
