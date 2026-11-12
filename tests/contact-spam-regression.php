<?php
define('ABSPATH', __DIR__);
$filters=[];
function add_filter($name,$callback,...$args) {global $filters;$filters[$name]=$callback;}
function add_action(...$args) {}
function get_transient($key){return false;}
function set_transient(...$args){}
function wp_salt($key){return 'test-salt';}
function wp_unslash($s){return $s;}
require __DIR__.'/../wp-content/mu-plugins/sa-contact-spam.php';
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$normal=['email-1'=>'buyer@example.com','textarea-1'=>'Need Toyota brake pads. Part link https://example.com/photo'];
check(sa_contact_spam_reason($normal,'',1,1)==='', 'normal inquiry with link allowed');
check(sa_contact_spam_reason(['email-1'=>'buyer@example.com','textarea-1'=>'Нужны тормозные колодки Toyota'],'',1,1)==='', 'Russian genuine inquiry allowed');
check(sa_contact_spam_reason($normal,'bot',1,1)==='hidden-field', 'hidden trap quarantines');
check(sa_contact_spam_reason(['email-1'=>'Meepayseess@gmail.com'],'',1,1)==='known-spam-sender', 'observed sender quarantines');
check(sa_contact_spam_reason(['textarea-1'=>'rick-owens-moscow.ru'],'',1,1)==='known-spam-link', 'observed spam link quarantines');
check(sa_contact_spam_reason($normal,'',5,20)==='', 'conservative burst boundary allows');
check(sa_contact_spam_reason($normal,'',6,1)==='repeat-burst', 'email burst quarantines');
check(sa_contact_spam_reason($normal,'',1,21)==='repeat-burst', 'IP burst quarantines');
check(sa_contact_spam_reason(['textarea-1'=>'Roadside help: stranded with puncture'],'',1,1)==='', 'roadside genuine inquiry allowed');

$callback=$filters['forminator_spam_protection'];
check($callback(false,[['name'=>'email-1','value'=>'Meepayseess@gmail.com']],51436,'form')===true,'actual Forminator module slug activates filter');
check($callback(false,[['name'=>'email-1','value'=>'Meepayseess@gmail.com']],51437,'form')===false,'other forms unchanged');
$_POST['sa_contact_website']='filled';
check($callback(false,[],51436,'form')===true,'actual hidden-field filter');
unset($_POST['sa_contact_website']);
check($callback(false,[['name'=>'email-1','value'=>'buyer@example.com']],51436,'form')===false,'actual normal filter allows');
