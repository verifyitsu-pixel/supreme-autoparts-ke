<?php
// Pure fixtures. No WordPress, network, orders, payment or email.
define('ABSPATH', __DIR__);
require __DIR__.'/../wp-content/plugins/whop-payments/includes/class-whop-webhook.php';
require __DIR__.'/../wp-content/plugins/whop-payments/includes/class-whop-api-client.php';
class FixtureWhopOrder {
 public function get_id(){return 42;} public function get_order_key(){return 'fixture-key';}
 public function get_payment_method(){return 'whop';} public function get_currency(){return 'USD';}
 public function get_total(){return '110.48';}
 public function get_meta($k){return ['_whop_checkout_id'=>'ch_fixture','_whop_plan_id'=>'plan_fixture'][$k]??'';}
}
$o=new FixtureWhopOrder;
$m=['order_id'=>'42','order_key'=>'fixture-key'];
$d=['id'=>'pay_fixture','status'=>'paid','substatus'=>'succeeded','paid_at'=>'2026-11-04T00:00:00Z','checkout_configuration_id'=>'ch_fixture','plan'=>['id'=>'plan_fixture'],'currency'=>'usd','total'=>'110.48','refunded_amount'=>0];
function check($label,$ok){if(!$ok)throw new Exception('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
check('exact creator total',Whop_Webhook::payment_validation_error($d,$m,$o)==='');
foreach (['total'=>'110.47','currency'=>'kes','id'=>'','status'=>'pending','substatus'=>'failed','paid_at'=>'','checkout_configuration_id'=>'ch_other','plan'=>['id'=>'plan_other'],'refunded_amount'=>1,'auto_refunded'=>true] as $key=>$value) {
 $bad=$d;$bad[$key]=$value;check('reject '.$key,Whop_Webhook::payment_validation_error($bad,$m,$o)!=='');
}
foreach ([null,[],['amount'=>11048,'currency'=>'usd'],'NaN','110.481',0,-1,'110.49','1e309'] as $value){$bad=$d;$bad['total']=$value;check('reject ambiguous amount '.json_encode($value),Whop_Webhook::payment_validation_error($bad,$m,$o)!=='');}
$bad=$d;unset($bad['total']);$bad['subtotal']='110.48';check('no subtotal fallback',Whop_Webhook::payment_validation_error($bad,$m,$o)!=='');
$bad=$m;$bad['order_key']='wrong';check('order key',Whop_Webhook::payment_validation_error($d,$bad,$o)!=='');
$bad=$m;$bad['order_id']='43';check('order id',Whop_Webhook::payment_validation_error($d,$bad,$o)!=='');
$raw='fixture';$ts=(string)time();$id='msg_fixture';$secret='fixture-secret';$sig='v1,'.base64_encode(hash_hmac('sha256',"$id.$ts.$raw",$secret,true));
check('valid signature',Whop_Api_Client::verify_webhook_signature($raw,$id,$ts,$sig,$secret));
check('tamper rejected',!Whop_Api_Client::verify_webhook_signature($raw.'x',$id,$ts,$sig,$secret));
check('stale rejected',!Whop_Api_Client::verify_webhook_signature($raw,$id,(string)(time()-301),$sig,$secret));
check('missing secret rejected',!Whop_Api_Client::verify_webhook_signature($raw,$id,$ts,$sig,''));
$source=file_get_contents(__DIR__.'/../wp-content/plugins/whop-payments/includes/class-whop-webhook.php');
check('marker after completion',strpos($source,'set_transient($seen_key, 1, WEEK_IN_SECONDS);',strpos($source,'$order->payment_complete($txn);'))!==false);
check('atomic order lock',strpos($source,'SELECT GET_LOCK(%s, 0)')!==false && strpos($source,'SELECT RELEASE_LOCK(%s)')!==false);
