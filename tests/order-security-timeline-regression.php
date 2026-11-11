<?php
// Isolated fake orders; no WP, network, database, real customers or payments.
define('ABSPATH',__DIR__);$hooks=[];
function add_action($h,$fn,$p=10,$n=1){global $hooks;$hooks[$h][]=$fn;}
function sanitize_key($s){return preg_replace('/[^a-z0-9_]/','',strtolower($s));}
function sanitize_text_field($s){return trim(preg_replace('/\s+/',' ',strip_tags($s)));}
function do_action(...$args){}
require __DIR__.'/../wp-content/mu-plugins/sa-order-security-timeline.php';
class WC_Geolocation {static function geolocate_ip($ip,$fallback,$api){if($fallback||$api)throw new Exception('External lookup forbidden');return ['country'=>'KE'];}}
class O {public $m=[],$notes=[],$ip='203.0.113.9',$ua='Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) Version/18 Safari/604.1 Mobile/15',$via='sa_open_pay';function get_meta($k){return $this->m[$k]??'';}function get_customer_ip_address(){return $this->ip;}function get_customer_user_agent(){return $this->ua;}function get_created_via(){return $this->via;}function get_date_created(){return new DateTimeImmutable('2026-11-11T09:00:00Z');}function update_meta_data($k,$v){$this->m[$k]=$v;}function save_meta_data(){} function add_order_note($s,$customer,$added){if($customer!==0||$added!==false)throw new Exception('Not private');$this->notes[]=$s;}}
$count=0;function check($ok,$label){global $count;if(!$ok)throw new Exception($label);$count++;}
$o=new O;sa_risk_checkout_note($o);$n=$o->notes[0];check(str_contains($n,'Mobile / Safari / iOS'),'iPhone correctly classified');check(str_contains($n,'203.0.113.9'),'saved shopper IP');check(str_contains($n,'derived): KE'),'derived local location');check(str_contains($n,'Order created: 2026-11-11 09:00:00 UTC'),'saved time not callback time');sa_risk_checkout_note($o);check(count($o->notes)===1,'idempotent');
$o=new O;$o->via='sa_uk_pdf_import';sa_risk_checkout_note($o);check(!$o->notes,'no import/admin as shopper');
$o=new O;$o->ip='bad';$o->ua='';$_SERVER['REMOTE_ADDR']='1.2.3.4';$_SERVER['HTTP_USER_AGENT']='Webhook';sa_risk_checkout_note($o);check(!$o->notes,'never substitutes callback IP/UA');
$o=new O;sa_risk_provider_note($o,'Paystack',['status'=>'success','ip'=>'198.51.100.1','country'=>'us','authorization_code'=>'SECRET','cvv'=>'123','pan'=>'4111111111111111']);$n=$o->notes[0];check(str_contains($n,'transaction IP (not card IP)'),'accurate processor IP label');check(str_contains($n,'issuing country (not cardholder location): US'),'issuer distinction');foreach(['SECRET','123','4111111111111111']as $s)check(!str_contains($n,$s),'no secret '.$s);sa_risk_provider_note($o,'Paystack',['status'=>'success','ip'=>'198.51.100.1','country'=>'us']);check(count($o->notes)===1,'provider dedup');sa_risk_provider_note($o,'Pesapal',['status'=>'FAILED','method'=>'MasterCard']);sa_risk_provider_note($o,'Pesapal',['status'=>'COMPLETED','method'=>'MasterCard']);check(count($o->notes)===3,'failed and completed history kept');
check(str_contains(sa_risk_device('Mozilla iPad Mac OS Safari'),'Tablet / Safari / iOS'),'iPad not macOS');check(str_contains(sa_risk_device('Mozilla Android Chrome Mobile'),'Android'),'Android');check(str_contains(sa_risk_device('Mozilla Chrome Edg'),'Edge'),'Edge not Chrome');
$s=file_get_contents(__DIR__.'/../wp-content/mu-plugins/sa-order-security-timeline.php');foreach(['wp_remote','fetch(','payment_complete(','update_status(','set_total(','set_payment_method(','HTTP_X_FORWARDED_FOR','canvas','WebGL'] as $forbidden)check(!str_contains($s,$forbidden),'no '.$forbidden);
echo "$count security timeline checks PASS\n";
