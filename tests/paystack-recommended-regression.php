<?php
$s=file_get_contents(__DIR__.'/../wp-content/plugins/whop-payments/includes/class-whop-open-pay.php');
$a=strpos($s,'<fieldset');$b=strpos($s,'</fieldset>',$a);$form=substr($s,$a,$b-$a);
if(substr_count($form,'name="gateway"')!==1||!str_contains($form,'value="pesapal" checked')||str_contains($form,'paystack')||str_contains($form,'whop.svg'))throw new Exception('Provider choice drift');
if(!str_contains($s,"if (\$gateway !== 'pesapal')"))throw new Exception('Stale form guard missing');
echo "PASS Pesapal only and checked in both currencies; stale hidden-method submits rejected\n";
const ABSPATH='fixture/';
$GLOBALS['signed']=true;$GLOBALS['past']=false;$GLOBALS['norm_past']=false;$GLOBALS['identity']=false;
function is_user_logged_in(){return $GLOBALS['signed'];}function wp_get_current_user(){return (object)['ID'=>7,'user_email'=>'new@example.invalid'];}function get_current_user_id(){return 7;}
function WC(){return $GLOBALS['wc'];}function wc_get_order_statuses(){return ['wc-processing'=>'Processing','wc-cancelled'=>'Cancelled'];}function wc_get_customer_order_count($id){return $GLOBALS['past']?1:0;}function wc_get_orders($args){return $GLOBALS['past']?[1]:[];}
class SM_DB{static function table($t){return $t;}}
class DBFixture{function prepare($q,...$args){return $q;}function get_var($q){return $GLOBALS['norm_past'];}}
$GLOBALS['wpdb']=new DBFixture;
class CustomerFixture{public $email='new@example.invalid',$country='KE';function get_billing_email(){return $this->email;}function get_shipping_state(){return '';}function __call($n,$a){return str_ends_with($n,'country')?$this->country:(str_ends_with($n,'phone')?'':'Address');}}
class CartFixture{function needs_shipping(){return true;}}
$GLOBALS['wc']=(object)['customer'=>new CustomerFixture,'cart'=>new CartFixture];
$s=file_get_contents(__DIR__.'/../wp-content/plugins/supreme-marketing/inc/class-sm-welcome.php');$s=str_replace("new DateTimeImmutable('now', \$tz)","new DateTimeImmutable('2026-11-18 12:00:00', \$tz)",$s);eval(substr($s,5));
function ck($b,$m){if(!$b)throw new Exception($m);echo "PASS $m\n";}
ck(SM_Welcome::november_percent()===20,'new signed-in customer gets 20 total');
$GLOBALS['past']=true;ck(SM_Welcome::november_percent()===15,'returning customer gets 15');$GLOBALS['past']=false;
$GLOBALS['norm_past']=true;ck(SM_Welcome::november_percent()===15,'email alias of previous customer gets 15');$GLOBALS['norm_past']=false;
$GLOBALS['signed']=false;ck(SM_Welcome::november_percent()===15,'guest without verified welcome identity gets 15');$GLOBALS['signed']=true;
$GLOBALS['wc']->customer->email='different@example.invalid';ck(SM_Welcome::november_percent()===15,'different billing identity cannot gain welcome discount');$GLOBALS['wc']->customer->email='new@example.invalid';
$GLOBALS['wc']->customer->country='GB';ck(SM_Welcome::november_percent()===15,'noneligible destination no welcome uplift');$GLOBALS['wc']->customer->country='KE';
$c=SM_Welcome::november_coupon(null,SM_Welcome::NOVEMBER_CODE,null);ck($c['amount']===20&&$c['individual_use']===true,'single 20 percent coupon avoids sequential 19.25 percent');
ck(100*(1-$c['amount']/100)===80.0,'100 subtotal leaves 80 not 80.75');
ck(SM_Welcome::DISCOUNT===5,'post-campaign personal welcome remains 5');
ck(str_contains($s,'2026-12-01 00:00:00'),'November expiry retained');
