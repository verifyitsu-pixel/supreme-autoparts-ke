<?php
// Fake WP state/orders only: no network, customers, mail or payment calls.
define('ABSPATH',__DIR__);define('DAY_IN_SECONDS',86400);$hooks=[];$transients=[];$options=[];
function add_action($h,$f,$p=10,$n=1){global $hooks;$hooks[$h][]=$f;}
function wp_salt($s){return 'isolated-test-key';}
function get_transient($k){global $transients;return $transients[$k]??false;}
function set_transient($k,$v,$ttl){global $transients;if($ttl>3660)throw new Exception('unbounded ttl');$transients[$k]=$v;}
function get_option($k,$d=[]){global $options;return $options[$k]??$d;}
function update_option($k,$v,$a){global $options;if($a!==false)throw new Exception('autoload');$options[$k]=$v;}
function sanitize_key($s){return preg_replace('/[^a-z0-9_]/','',strtolower($s));}
function sanitize_text_field($s){return trim(strip_tags($s));}
require __DIR__.'/../wp-content/mu-plugins/sa-order-security-timeline.php';
require __DIR__.'/../wp-content/mu-plugins/sa-local-security.php';
class O {
 public $m=[],$notes=[],$via='checkout',$email='customer@example.com',$id=100;
 function get_meta($k){return $this->m[$k]??'';}function update_meta_data($k,$v){$this->m[$k]=$v;}function save_meta_data(){}
 function add_order_note($s,$customer,$author){if($customer!==0||$author!==false)throw new Exception('public note');$this->notes[]=$s;}
 function get_created_via(){return $this->via;}function get_customer_ip_address(){return '203.0.113.8';}function get_billing_email(){return $this->email;}
 function get_billing_first_name(){return 'Testa';}function get_billing_last_name(){return 'Smith';}function get_billing_country(){return 'KE';}function get_id(){return $this->id;}
}
$count=0;function ck($ok,$s){global $count;if(!$ok)throw new Exception($s);$count++;}
$f=['email'=>'person@mailinator.com','first'=>'test','last'=>'Testa','ip_orders'=>1,'email_orders'=>1];
$i=sa_local_indicators($f);ck(count($i)===2,'known disposable and exact placeholder');ck(!sa_local_high_risk($f),'not high-risk alone');
foreach(['relay.firefox.com','privaterelay.appleid.com','duck.com','simplelogin.com','example.com']as$d){ck(!sa_local_indicators(['email'=>'p@'.$d,'first'=>'Testa','last'=>'Contestabile']),'legitimate name/alias not flagged '.$d);}
ck(!sa_local_high_risk(['ip_orders'=>100,'email_orders'=>1]),'shared NAT alone not high');ck(!sa_local_high_risk(['ip_orders'=>1,'email_orders'=>100]),'email repetition alone not high');
ck(!sa_local_high_risk(['ip_orders'=>10,'email_orders'=>5]),'boundary normal');ck(sa_local_high_risk(['ip_orders'=>11,'email_orders'=>6]),'composite high threshold');
ck(count(sa_local_indicators(['ip_country'=>'US','billing_country'=>'KE']))===1,'country difference indicator only');ck(!sa_local_high_risk(['ip_country'=>'US','billing_country'=>'KE']),'country alone not high');
ck(sa_local_counter('ip','',600)===0,'no shared unknown bucket');ck(sa_local_counter('ip','1.2.3.4',600)===1,'first submission');ck(sa_local_counter('ip','1.2.3.4',600)===2,'second');ck(sa_local_counter('email','1.2.3.4',600)===1,'separate scopes');
foreach(array_keys($transients)as$k)ck(!str_contains($k,'1.2.3.4'),'hashed keys');
$o=new O;sa_local_order_checks($o);ck(count($o->notes)===1,'one passive note');ck(str_contains($o->notes[0],'Assessed:'),'timestamp');ck(str_contains($o->notes[0],'approximate'),'count labelled approximate');ck(str_contains($o->notes[0],'held for license'),'country unavailable labelled');$saved=$transients;sa_local_order_checks($o);ck(count($o->notes)===1&&$saved===$transients,'repeat does not recount');
$o=new O;$saved=$transients;sa_local_order_checks($o,false);ck(str_contains($o->notes[0],'Unavailable for this historical order'),'historical unavailable');ck($saved===$transients,'historical snapshot never counted');
$o=new O;$o->via='admin';sa_local_order_checks($o);ck(!$o->notes,'no admin or import customer assumption');
$o=new O;sa_local_alert($o,'Fake isolated review');sa_local_alert($o,'Fake isolated review');ck(count($o->notes)===1,'alert per-order dedup');ck(count($options['sa_internal_risk_alerts_v1'])===1,'one dashboard record');
$p=new O;$p->id=101;sa_local_alert($p,'Same pair');ck(count($options['sa_internal_risk_alerts_v1'])===1,'pair flooding suppressed');
$transients=[];$options=[];for($n=0;$n<12;$n++){$o=new O;$o->id=200+$n;sa_local_order_checks($o);}ck(count($options['sa_internal_risk_alerts_v1'])===1,'composite threshold produces single group alert');ck(count($o->notes)===2,'high review private note');
$s=file_get_contents(__DIR__.'/../wp-content/mu-plugins/sa-local-security.php');
foreach(['wp_remote','curl_','fetch(','payment_complete(','update_status(','set_total(','set_payment_method(','_sa_timeline','HTTP_X_FORWARDED_FOR','Freemius','wp_mail(','sessionStorage','wp_footer']as$bad)ck(!str_contains($s,$bad),'forbidden '.$bad);
ck(str_contains($s,"current_user_can('manage_woocommerce')"),'admin restricted');ck(str_contains($s,'esc_url(')&&str_contains($s,'esc_html('),'output escaped');ck(str_contains($s,'array_slice($alerts,-20)'),'bounded dashboard list');
foreach(['disposable-domains.php','disposable-allowlist.php','spam-names.php']as$file){$a=include __DIR__.'/../wp-content/mu-plugins/sa-security-data/'.$file;ck(is_array($a),'list type '.$file);}
ck(!file_exists(__DIR__.'/../wp-content/mu-plugins/sa-security-data/IPv4.db'),'unresolved database excluded');
echo "$count local security checks PASS\n";
