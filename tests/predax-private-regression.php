<?php
// Isolated network/DB/order fakes. No real API, customers, mail, statuses or payments.
define('ABSPATH',__DIR__);define('DAY_IN_SECONDS',86400);define('HOUR_IN_SECONDS',3600);
$hooks=[];$opts=['sa_predax_key'=>'FAKE_SECRET'];$trans=[];$notes=[];$orders=[];$calls=[];$alerts=[];$response=['code'=>200,'body'=>''];
function add_action($h,$f,$p=10,$n=1){global $hooks;$hooks[$h][]=$f;}
function add_option($k,$v,$d='',$a='no'){global $opts;if(isset($opts[$k]))return false;$opts[$k]=$v;return true;}
function get_option($k,$d=''){global $opts;return $opts[$k]??$d;}
function delete_option($k){global $opts;unset($opts[$k]);}
function wp_cache_delete(...$a){}
function get_transient($k){global $trans;return $trans[$k]??false;}
function set_transient($k,$v,$ttl){global $trans;$trans[$k]=$v;}
function wp_salt($s){return 'fake-test-salt';}
function wp_json_encode($a){return json_encode($a);}
function wp_remote_post($url,$args){global $calls,$response;$calls[]=[$url,$args];return $response;}
function is_wp_error($r){return $r==='WP_ERROR';}
function wp_remote_retrieve_response_code($r){return $r['code'];}
function wp_remote_retrieve_body($r){return $r['body'];}
function wc_get_order($id){global $orders;return $orders[$id]??null;}
function sa_risk_note($o,$kind,$text){global $notes;$notes[$o->id][]=$text;}
function sa_local_alert($o,$text){global $alerts;$alerts[]=$o->id;}
class DB {public $options='fake_options';function prepare($sql,...$a){return [$sql,$a];}function query($p){global $opts;[$sql,$a]=$p;if(str_starts_with($sql,'UPDATE')){[$key,$max]=$a;if((int)$opts[$key]>=$max)return 0;$opts[$key]++;return 1;}return 0;}}
$wpdb=new DB;
class O{public $id,$meta=[],$via='checkout',$ip='8.8.8.8';function __construct($id){$this->id=$id;}function get_meta($k){return $this->meta[$k]??'';}function update_meta_data($k,$v){$this->meta[$k]=$v;}function save_meta_data(){}function get_created_via(){return $this->via;}function get_customer_ip_address(){return $this->ip;}}
require __DIR__.'/../wp-content/mu-plugins/sa-predax-private.php';
$n=0;function ck($ok,$label){global $n;if(!$ok)throw new Exception($label);$n++;}
function resetstate(){global $opts,$trans,$notes,$orders,$calls,$alerts,$response;$opts=['sa_predax_key'=>'FAKE_SECRET'];$trans=[];$notes=[];$orders=[];$calls=[];$alerts=[];$response=['code'=>200,'body'=>json_encode(['classification'=>['risk_score'=>0,'is_datacenter'=>true],'location'=>['country_code'=>'US']])];}
foreach(['','garbage','127.0.0.1','192.168.1.2','10.0.0.1','::1']as$ip)ck(sa_predax_public_ip($ip)==='','invalid/private '.$ip);
ck(sa_predax_public_ip('8.8.8.8')==='8.8.8.8','public IPv4');ck(sa_predax_public_ip('2606:4700:4700::1111')!=='','public IPv6');
foreach([[],['classification'=>[]],['classification'=>['risk_score'=>-1]],['classification'=>['risk_score'=>101]],['classification'=>['risk_score'=>'bad']]]as$f)ck(sa_predax_parse($f)===null,'malformed score unavailable');
$r=sa_predax_parse(['classification'=>['risk_score'=>80,'is_vpn'=>true,'is_tor'=>'true','secret'=>'TOKEN'],'location'=>['country_code'=>'<script>']]);ck($r['level']==='high'&&$r['flags']===['VPN']&&$r['country']==='','strict allowlist');ck(!str_contains(json_encode($r),'TOKEN'),'no raw provider dump');
foreach([[19,'minimal'],[20,'low'],[49,'low'],[50,'medium'],[79,'medium'],[80,'high']]as[$v,$l])ck(sa_predax_parse(['classification'=>['risk_score'=>$v]])['level']===$l,'score boundary '.$v);
resetstate();$orders[1]=new O(1);sa_predax_run(1);ck(count($calls)===1,'one public IP lookup');[$url,$args]=$calls[0];ck($url==='https://predax.io/api/v1/check/ip','fixed HTTPS endpoint');ck(json_decode($args['body'],true)===['ip'=>'8.8.8.8'],'IP-only payload');ck($args['redirection']===0&&$args['sslverify']===true&&$args['timeout']===5,'bounded verified TLS no redirects');ck($args['headers']['X-API-Key']==='FAKE_SECRET','secret in auth header only');ck(!str_contains($notes[1][0],'FAKE_SECRET'),'secret not in note');ck(str_contains($notes[1][0],'not a fraud verdict'),'not a verdict');ck(!$alerts,'minimal no alert');sa_predax_run(1);ck(count($calls)===1&&count($notes[1])===1,'idempotent');
$orders[2]=new O(2);sa_predax_run(2);ck(count($calls)===1&&str_contains($notes[2][0],'Local cache'),'shared IP cache no extra call');
resetstate();$orders[1]=new O(1);$orders[1]->via='admin';sa_predax_run(1);ck(!$calls&&!$notes,'no admin/import');
resetstate();$orders[1]=new O(1);$orders[1]->ip='127.0.0.1';sa_predax_run(1);ck(!$calls&&str_contains($notes[1][0],'Unavailable'),'unknown IP fails open');
resetstate();$orders[1]=new O(1);$opts['sa_predax_key']='';sa_predax_run(1);ck(!$calls&&str_contains($notes[1][0],'not configured'),'missing key no call');
foreach([401,402,403,429,500]as$code){resetstate();$orders[1]=new O(1);$response=['code'=>$code,'body'=>'SECRET_RESPONSE'];sa_predax_run(1);ck(str_contains($notes[1][0],'Unavailable')&&!str_contains($notes[1][0],'SECRET_RESPONSE'),'HTTP unavailable '.$code);ck(!$alerts,'no failed-call high verdict '.$code);if($code!==500)ck(isset($trans['sa_predax_paused']),'pause on rate/auth/quota '.$code);}
resetstate();$orders[1]=new O(1);$response='WP_ERROR';sa_predax_run(1);ck(str_contains($notes[1][0],'request failed'),'timeout fails open');
resetstate();$orders[1]=new O(1);$response=['code'=>200,'body'=>'not json'];sa_predax_run(1);ck(str_contains($notes[1][0],'unrecognized'),'bad JSON unavailable');
resetstate();$orders[1]=new O(1);$response=['code'=>200,'body'=>json_encode(['classification'=>['risk_score'=>90,'is_proxy'=>true]])];sa_predax_run(1);ck($alerts===[1],'high private alert');
resetstate();$orders[1]=new O(1);$opts['sa_predax_budget_'.gmdate('Ym')]=4000;sa_predax_run(1);ck(!$calls&&str_contains($notes[1][0],'safety cap'),'monthly hard cap');
resetstate();$orders[1]=new O(1);$opts['sa_predax_budget_'.gmdate('Ymd')]=800;sa_predax_run(1);ck(!$calls,'daily hard cap');
resetstate();$orders[1]=new O(1);$opts['sa_predax_lock_1']=time();sa_predax_run(1);ck(!$calls,'per-order overlap lock');
$s=file_get_contents(__DIR__.'/../wp-content/mu-plugins/sa-predax-private.php');foreach(['get_billing_email','get_billing_first_name','get_billing_address','get_total(','payment_complete(','update_status(','set_status(','wp_mail(','sa_hr_create','fingerprint\'=>','timezone\'=>','telemetry/event']as$bad)ck(!str_contains($s,$bad),'forbidden '.$bad);
ck(str_contains($s,'as_enqueue_async_action'),'async not checkout API call');ck(str_contains($s,'redirection\'=>0'),'no credential redirect');ck(str_contains($s,'finally{delete_option($lock);}'),'lock cleanup');
echo "$n Predax private-screening checks PASS\n";
