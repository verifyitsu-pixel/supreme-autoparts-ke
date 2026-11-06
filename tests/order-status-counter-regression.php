<?php
// Unit test (mocks only): which events are counted and under which row name. No DB, no orders.
const ABSPATH='fixture/';$GLOBALS['h']=[];$GLOBALS['q']=[];$GLOBALS['gws']=['paystack'=>1,'whop'=>1,str_repeat('a',130)=>1];
function add_action($n,$c,$p=10,$a=1){$GLOBALS['h'][$n]=$c;}
function sanitize_key($k){return preg_replace('/[^a-z0-9_\-]/','',strtolower($k));}
class GWs{function payment_gateways(){return $GLOBALS['gws'];}}class WCo{function payment_gateways(){return new GWs;}}
function WC(){return new WCo;}
class DB{public $options='wp_options';public $sup=[];function suppress_errors($v=true){$this->sup[]=$v;return false;}
 function prepare($sql,...$a){return [$sql,$a];}function query($p){$GLOBALS['q'][]=$p;return 1;}}
$GLOBALS['wpdb']=new DB;
require __DIR__.'/../wp-content/mu-plugins/obs-order-status-counter.php';
$f=$GLOBALS['h']['woocommerce_order_status_changed'];
class O{function __construct(public $gw){}function get_payment_method(){return $this->gw;}}
function run($gw,$to){$GLOBALS['q']=[];$GLOBALS['h']['woocommerce_order_status_changed'](1,'pending',$to,new O($gw));return $GLOBALS['q']?$GLOBALS['q'][0][1][0]:null;}
function check($ok,$m){if(!$ok)throw new Exception($m);echo "PASS $m\n";}
check(run('paystack','failed')==='obs_osc_paystack_failed','known gateway, failed counted');
check(run('whop','processing')==='obs_osc_whop_processing','known gateway, processing counted');
check(run('paystack','completed')==='obs_osc_paystack_completed','completed counted');
check(run('paystack','on-hold')===null&&run('paystack','cancelled')===null&&run('paystack','pending')===null,'other statuses ignored');
check(run('evil<script>','failed')==='obs_osc_other_failed','unknown gateway -> other');
check(run('','failed')==='obs_osc_other_failed','empty gateway -> other');
check(run(str_repeat('a',130),'failed')==='obs_osc_other_failed','very long gateway id -> other');
$hk=$GLOBALS["h"]["woocommerce_order_status_changed"];$GLOBALS["q"]=[];$hk(1,'a','failed',null);check($GLOBALS['q']===[],'missing order ignored');
$GLOBALS["q"]=[];run('paystack','failed');check(str_contains($GLOBALS['q'][0][0],'ON DUPLICATE KEY UPDATE option_value = option_value + 1')&&!str_contains($GLOBALS['q'][0][0],'order_id'),'atomic single-statement increment, no order data');
check($GLOBALS['wpdb']->sup===array_merge(...array_fill(0,0,[]))||($GLOBALS['wpdb']->sup[0]===true&&end($GLOBALS['wpdb']->sup)===false),'DB errors suppressed during counter write and restored');
