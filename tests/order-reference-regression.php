<?php
/** Isolated fixtures only: no WordPress boot, orders, network, mail or payments. */
define('ABSPATH', __DIR__);
$GLOBALS['hooks'] = [];
function add_action($name,$callback,$priority=10,$args=1){$GLOBALS['hooks'][$name]=$callback;}
function add_filter($name,$callback,$priority=10,$args=1){$GLOBALS['hooks'][$name]=$callback;}
function check($ok,$label){if(!$ok){throw new Exception($label);} echo "PASS $label\n";}
class ReferenceOrder {public $meta=[]; public $saves=0; function get_id(){return 76336;} function get_meta($k){return $this->meta[$k]??'';} function update_meta_data($k,$v){$this->meta[$k]=$v;} function save_meta_data(){$this->saves++;}}
require __DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/order-references.php';
$legacy=new ReferenceOrder; $display=$GLOBALS['hooks']['woocommerce_order_number'];
check($display('76336',$legacy)==='76336','historical order unchanged');
$o=new ReferenceOrder; $GLOBALS['hooks']['woocommerce_new_order'](76336,$o);
check($display('76336',$o)==='Supreme-76336','new order exact mixed-case reference');
$GLOBALS['hooks']['woocommerce_new_order'](76336,$o);
check($o->saves===1,'creation hook idempotent');
$o->meta['_sa_order_reference']='wrong';check($display('76336',$o)==='76336','invalid reference fails closed');
$o->meta['_sa_order_reference']='Supreme-76336';
function wc_get_order($id){return $GLOBALS['fixture'];}
$GLOBALS['fixture']=$o;
$src=file_get_contents(__DIR__.'/../wp-content/plugins/woo-paystack/includes/class-wc-gateway-paystack.php');
$a=strpos($src,'    public function supreme_attempt_reference');
// Source includes tabs, not literal backslash-t.
$b=strpos($src,"\tpublic function get_custom_fields",$a);
eval('class ReferenceGateway {'.substr($src,$a,$b-$a).'}');
$g=new ReferenceGateway;$r=$g->supreme_attempt_reference($o);
check((bool)preg_match('/^Supreme-76336-[a-f0-9]{16}$/D',$r),'Paystack valid branded unique attempt');
check($r!==$g->supreme_attempt_reference($o),'Paystack retry uniqueness');
check($g->supreme_reference_order_id($r)===76336,'new callback parsing');
check($g->supreme_reference_order_id('76336_1793920000')===76336,'legacy callback parsing');
foreach(['Supreme-0-0123456789abcdef','Supreme-76336-abc','76336_1junk','Supreme-76336-0123456789abcdef/','x76336_1','76336_1\n'] as $bad){check($g->supreme_reference_order_id($bad)===0,'malformed callback rejected');}
check($g->supreme_attempt_reference($legacy)!==$r,'legacy generation retained');
check(strpos($src,"'display_name' => 'Order reference'")!==false,'Paystack dashboard field present');
check(strpos($src,"\$order->get_meta('_paystack_txn_ref') !== \$paystack_txn_ref")!==false,'exact stored attempt validation retained');
$pp=file_get_contents(__DIR__.'/../wp-content/plugins/pesapal-woocommerce/thebunchke_pesapal_woocommerce.php');
check(substr_count($pp,"(\$order->get_meta('_sa_order_reference') !== '') ? \$order->get_order_number()")===2,'Pesapal request and STK aligned');
$hard=file_get_contents(__DIR__.'/../wp-content/mu-plugins/supreme-pesapal-hardening.php');
check(strpos($hard,"return (string) \$order->get_order_number();")!==false,'Pesapal validation aligned');
$whop=file_get_contents(__DIR__.'/../wp-content/plugins/whop-payments/includes/class-whop-api-client.php');
check(strpos($whop,"\$metadata['order_reference'] = \$order_reference;")!==false,'Whop metadata mapped');
check(strpos($whop,"'order_id'     => \$order_id")!==false,'Whop numeric reconciliation retained');
echo "Order reference fixtures complete, no external effects.\n";

// Exercise the real verification boundary with stubbed responses, never HTTP.
$a=strpos($src,'private function get_paystack_transaction'); $b=strpos($src,"\n\t/**",$a);
$verify=str_replace('private function get_paystack_transaction','public function get_paystack_transaction',substr($src,$a,$b-$a));
eval('class VerifyReferenceGateway extends ReferenceGateway { public $secret_key="fixture"; public $testmode=false;'.$verify.'}');
function wp_remote_get($url,$args){$GLOBALS['verify_url']=$url;return $GLOBALS['verify_response'];}
function is_wp_error($r){return false;}
function wp_remote_retrieve_response_code($r){return $r['code'];}
function wp_remote_retrieve_body($r){return json_encode($r['body']);}
class VerifyReferenceOrder extends ReferenceOrder { function get_payment_method(){return 'paystack';} function get_currency(){return 'KES';} function get_total(){return 1000;} }
$v=new VerifyReferenceGateway;$GLOBALS['fixture']=new VerifyReferenceOrder;
foreach(['76336_1793920000','Supreme-76336-0123456789abcdef'] as $ref){
 $GLOBALS['fixture']->meta['_paystack_txn_ref']=$ref;
 $good=['status'=>true,'data'=>['status'=>'success','reference'=>$ref,'currency'=>'KES','amount'=>100000,'domain'=>'live']];
 $GLOBALS['verify_response']=['code'=>200,'body'=>$good];
 check($v->get_paystack_transaction($ref)!==false,'real verification accepts exact legacy/new match');
 foreach([['reference'=>'76337_1'],['currency'=>'USD'],['amount'=>1],['domain'=>'test'],['status'=>'failed']] as $bad){
  $changed=$good;$changed['data']=array_replace($changed['data'],$bad);$GLOBALS['verify_response']=['code'=>200,'body'=>$changed];
  check($v->get_paystack_transaction($ref)===false,'real verification rejects mismatch');
 }
 $GLOBALS['verify_response']=['code'=>200,'body'=>$good];$GLOBALS['fixture']->meta['_paystack_txn_ref']='other';
 check($v->get_paystack_transaction($ref)===false,'real verification rejects wrong stored attempt');
}
function wp_strip_all_tags($v){return strip_tags($v);}
require __DIR__.'/../wp-content/plugins/whop-payments/includes/class-whop-api-client.php';
check(Whop_Api_Client::default_order_plan_title('Supreme-76336')==='Supreme-76336','Whop exact mixed-case title');
check(Whop_Api_Client::default_order_plan_title('76336')==='SA Order #76336','Whop historical title unchanged');
// Execute actual Paystack metadata builder with custom metadata disabled (current config).
$a=strpos($src,"\tpublic function get_custom_fields(");$b=strpos($src,"\n\t/**",$a);
eval('class ReferenceMetadataGateway {public $custom_metadata=false;'.substr($src,$a,$b-$a).'}');
$GLOBALS['fixture']=$o;$fields=(new ReferenceMetadataGateway)->get_custom_fields(76336);
check($fields[0]===['display_name'=>'Order reference','variable_name'=>'order_reference','value'=>'Supreme-76336'],'real Paystack metadata emits exact reference with optional customer metadata off');
$GLOBALS['fixture']=$legacy;$fields=(new ReferenceMetadataGateway)->get_custom_fields(76336);
check(count($fields)===1 && $fields[0]['variable_name']==='plugin','historical Paystack metadata unchanged');
$track=$GLOBALS['hooks']['woocommerce_shortcode_order_tracking_order_id'];$GLOBALS['fixture']=$o;
check($track('Supreme-76336')===76336,'tracking accepts exact stored branded reference');
check($track('76336')==='76336','tracking preserves numeric historical ID');
$GLOBALS['fixture']=$legacy;check($track('Supreme-76336')===0,'tracking does not alias historical order');
