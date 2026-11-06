<?php
/** Real provider-verification method, isolated response fixtures; no network, WP database, emails or charge. */
function wc_get_order($id){return $id===42?$GLOBALS['fee_order']:false;}
function wp_remote_get($url,$args){$GLOBALS['requested'][]=$url;return $GLOBALS['response'];}
function is_wp_error($x){return false;}function wp_remote_retrieve_response_code($x){return $x['code'];}function wp_remote_retrieve_body($x){return $x['body'];}
class Fee{function get_payment_method(){return 'paystack';}function get_meta($k){return '42_123456';}function get_currency(){return 'KES';}function get_total(){return 1000;}}
$GLOBALS['fee_order']=new Fee;
function sa_method($s,$name){$a=strpos($s,'public function '.$name.'(');$b=strpos($s,"\n    }\n",$a)+7;return substr($s,$a,$b-$a);}$s=file_get_contents(__DIR__.'/../wp-content/plugins/woo-paystack/includes/class-wc-gateway-paystack.php');$a=strpos($s,'private function get_paystack_transaction(');$b=strpos($s,"\n    }",$a)+6;$method=substr($s,$a,$b-$a);$method=str_replace('private function','public function',$method);eval('class GatewayFixture {public $secret_key="NOT_A_SECRET_FIXTURE";public $testmode=true;'.sa_method($s,'supreme_reference_order_id').$method.'}');
$g=new GatewayFixture;$base=['status'=>true,'data'=>['status'=>'success','reference'=>'42_123456','currency'=>'KES','amount'=>100000,'domain'=>'test']];
function check($name,$payload,$ok,$ref='42_123456',$code=200){global $g;$GLOBALS['response']=['code'=>$code,'body'=>json_encode($payload)];if((bool)$g->get_paystack_transaction($ref)!==$ok)throw new Exception($name);echo "PASS $name\n";}
check('exact KES1000 verified fee accepted',$base,true);
foreach(['amount'=>99999,'currency'=>'USD','reference'=>'99_123456','status'=>'failed','domain'=>'live'] as $k=>$v){$p=$base;$p['data'][$k]=$v;check('reject mismatch '.$k,$p,false);}
$p=$base;$p['status']=false;check('reject API status false',$p,false);check('reject HTTP failure',$base,false,'42_123456',500);check('reject malformed reference',$base,false,'attacker');check('reject wrong application order',$base,false,'99_123456');echo "PASS real provider verifier checked without any outbound provider request\n";
