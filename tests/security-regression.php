<?php
define('ABSPATH', __DIR__); define('MINUTE_IN_SECONDS',60);
$hooks=[];$db=[];
function add_filter($tag,$fn,$priority=10,$args=1){global $hooks;$hooks[$tag][$priority][]=$fn;}
function add_action($tag,$fn,$priority=10,$args=1){add_filter($tag,$fn,$priority,$args);}
function get_transient($key){return get_option('_transient_'.$key,0);}
function get_option($key,$default=false){global $db;return $db[$key]??$default;}
function add_option($key,$value,$unused='',$autoload=false){global $db;if(isset($db[$key]))return false;$db[$key]=$value;return true;}
function wp_cache_delete(...$args){}
function get_user_by($field,$value){global $users;foreach($users as $u){if(($field==='email'&&$u->user_email===$value)||($field==='login'&&$u->user_login===$value))return $u;}return false;}
class WP_User{public function __construct(public int $ID, public array $roles, public array $caps=[],public string $user_email='admin@test.invalid',public string $user_login='admin') {}}
class WP_Error {public function __construct(public string $code,public string $message){}}
function user_can($u,$cap){return in_array($cap,$u->caps,true);}
class DB {public $options='wp_options';public function prepare($sql,$name){return $name;}public function query($name){global $db;if(!isset($db[$name]))return 0;$db[$name]=(int)$db[$name]+1;return 1;}}
$wpdb=new DB;
require $argv[1];
function check($condition,$label){if(!$condition){fwrite(STDERR,"FAIL $label\n");exit(1);}echo "PASS $label\n";}
$_SERVER['REMOTE_ADDR']='203.0.113.7';$_SERVER['HTTP_CF_CONNECTING_IP']='192.0.2.4';
check(sa_security_network()==='203.0.113.7','untrusted CF header ignored');
$staff=new WP_User(1,['administrator'],['manage_options']);$users=[$staff];
check(!sa_security_customer_user($staff),'staff excluded from customer token login');
check(sa_security_customer_user(new WP_User(2,['customer'])),'customer email-code and Google path permitted');
check(!sa_security_customer_user(new WP_User(3,['editor'],['edit_posts'])),'editor excluded from customer token login');
for($i=0;$i<10;$i++){sa_security_count('password-account','user:1',900,true);}
check(sa_security_password_gate($staff,'admin','correct') instanceof WP_Error,'valid password cannot bypass lockout');
check(sa_security_password_gate($staff,'admin@test.invalid','correct') instanceof WP_Error,'email and username share account budget');
check(sa_security_password_gate(null,'other','password')===null,'different account remains available below network cap');
for($i=0;$i<5;$i++)check(sa_security_otp_allowed('customer@test.invalid',['hash'=>'hash1']),'OTP budget permits attempt '.($i+1));
check(!sa_security_otp_allowed('customer@test.invalid',['hash'=>'hash1']),'sixth same-code attempt blocked');
check(sa_security_otp_allowed('customer@test.invalid',['hash'=>'hash2']),'new code remains usable below account cap');
check(isset($hooks['authenticate'][5],$hooks['authenticate'][99]),'early and late auth hooks installed');
check(sa_security_consume_otp('customer@test.invalid',['hash'=>'single']),'first verified code consumption succeeds');
check(!sa_security_consume_otp('customer@test.invalid',['hash'=>'single']),'concurrent or replayed code consumption blocked');
echo "All authentication guard checks passed\n";
