<?php
declare(strict_types=1);
define('ABSPATH', __DIR__);
define('SA_CORE_DIR', __DIR__ . '/../wp-content/plugins/supreme-autoparts-core/');
$hooks=[];
function add_action($name,$callback,$priority=10,$args=1){global $hooks;$hooks[$name][$priority][]=$callback;}
function add_filter(...$args){}
function sanitize_text_field($s){return trim(strip_tags((string)$s));}
function wp_unslash($s){return is_array($s)?array_map('wp_unslash',$s):stripslashes((string)$s);}
function wp_verify_nonce($nonce,$action){return $nonce==='valid'&&$action==='update-order-review';}
function sa_fargo_county_name($state){return ['KE30'=>'Nairobi','KE22'=>'Kiambu'][$state]??'';}
function sa_fargo_norm($s){return strtolower(trim($s));}
class TestSession{public array $data=[];function get($k){return $this->data[$k]??null;}function set($k,$v){$this->data[$k]=$v;}}
$wc=(object)['session'=>new TestSession()];
function WC(){global $wc;return $wc;}
require SA_CORE_DIR.'includes/courier-shipping.php';
function check($v,$reason){if(!$v)throw new RuntimeException($reason);}
$station=null;foreach(sa_station_records('g4s') as $st){if(($st['county']??'')==='Nairobi'){$station=$st;break;}}
check($station!==null,'actual Nairobi station found');
$data=['shipping_method'=>['sa_g4s:14_pickup'],'billing_country'=>'KE','billing_state'=>'KE30','billing_city'=>$station['town'],'sa_pickup_station'=>$station['id']];
$hook=$hooks['woocommerce_checkout_update_order_review'][25][0];
$run=static function($d,$nonce='valid')use($hook){$_POST=['security'=>$nonce];$hook(http_build_query($d));};
$run($data);$saved=WC()->session->get('sa_courier_station_choice');check($saved['id']===$station['id'],'valid station saved');check($saved['signature']==='g4s|KE|KE30|'.$station['town'],'exact signature');
$empty=$data;$empty['sa_pickup_station']='';$empty['sa_station_pending']='1';$run($empty);$run($empty);check(WC()->session->get('sa_courier_station_choice')===$saved,'two initial reloads preserve eligible pending selection');
$invalid=$data;$invalid['sa_pickup_station']='invented';$run($invalid,'invalid');check(WC()->session->get('sa_courier_station_choice')===$saved,'bad nonce cannot write');
$run($invalid);check(WC()->session->get('sa_courier_station_choice')===null,'invented station clears');
$run($data);$ordinary=$empty;unset($ordinary['sa_station_pending']);$run($ordinary);check(WC()->session->get('sa_courier_station_choice')===null,'ordinary blank without pending clears');
$run($data);$door=$empty;$door['shipping_method']=['sa_g4s:14_door'];$run($door);check(WC()->session->get('sa_courier_station_choice')===null,'door delivery pending cannot restore');
$run($data);WC()->session->set('sa_courier_station_choice',['id'=>'removed-station','signature'=>$saved['signature']]);$run($empty);check(WC()->session->get('sa_courier_station_choice')===null,'pending cannot retain no-longer-eligible station');
$run($data);$empty['sa_station_pending']='0';$run($empty);check(WC()->session->get('sa_courier_station_choice')===null,'explicit clear clears');
$run($data);$changed=$data;$changed['billing_country']='US';$run($changed);check(WC()->session->get('sa_courier_station_choice')===null,'US clears');
$run($data);$changed=$data;$changed['shipping_method']=['sa_speedaf:15_pickup'];$run($changed);check(WC()->session->get('sa_courier_station_choice')===null,'wrong carrier clears');
$run($data);$changed=$empty;$changed['sa_station_pending']='1';$changed['billing_city']='Different town';$run($changed);check(WC()->session->get('sa_courier_station_choice')===null,'changed destination blank does not restore');
$diff=$data;$diff['billing_country']='US';$diff['ship_to_different_address']='1';$diff['shipping_country']='KE';$diff['shipping_state']='KE30';$diff['shipping_city']=$station['town'];$run($diff);check(WC()->session->get('sa_courier_station_choice')['id']===$station['id'],'uses separate shipping destination');
echo "PASS native PHP station session: valid set, two pending reloads, nonce reject, invalid/explicitclear/carrier/country/destination clear, separate shipping\n";
