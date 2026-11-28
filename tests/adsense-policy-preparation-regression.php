<?php
// Isolated preparation tests. No WordPress boot, network, account or store writes.
define('ABSPATH', __DIR__);
$actions=[];$filters=[];$page='privacy-policy';$admin=false;
function add_action($name,$fn,...$rest){global $actions;$actions[$name][]=$fn;}
function add_filter($name,$fn,...$rest){global $filters;$filters[$name][]=$fn;}
function is_admin(){global $admin;return $admin;}
function is_page($slugs){global $page;return in_array($page,(array)$slugs,true);}
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$_SERVER['HTTP_HOST']='www.supremeautoparts.co.ke';
require __DIR__.'/../wp-content/mu-plugins/sa-00-analytics-privacy.php';
require __DIR__.'/../wp-content/mu-plugins/sa-adsense-ownership.php';
foreach(['privacy-policy','cookie-policy'] as $page){
 check(sa_tracking_policy_page(),'policy tracking exclusion');
 $out=$filters['the_content'][0]('Original policy');
 check(str_starts_with($out,'Original policy'),'original content retained');
 check(str_contains($out,'advertising is not enabled'),'accurate off state');
 check(str_contains($out,'If Google advertising is enabled later'),'conditional disclosure');
 check(str_contains($out,'https://myadcenter.google.com/'),'opt out link');
 check(!str_contains($out,'review is pending'),'no stale review claim');
 check(!preg_match('~<script|<iframe|adsbygoogle|fundingchoices~i',$out),'no executable setup');
}
foreach(['guides','checkout','cart','my-account','product'] as $page){check(!sa_tracking_policy_page(),'other routes unchanged');check($filters['the_content'][0]('Original')==='Original','no other content injection');}
$page='privacy-policy';$admin=true;check($filters['the_content'][0]('Original')==='Original','admin unchanged');$admin=false;
$_SERVER['HTTP_HOST']='maxoutnutrition.com';check($filters['the_content'][0]('Original')==='Original','other host unchanged');
ob_start();foreach($actions['wp_head']??[] as $fn)$fn();$out=ob_get_clean();check($out==='','other host no ownership');
$_SERVER['HTTP_HOST']='www.supremeautoparts.co.ke';ob_start();foreach($actions['wp_head']??[] as $fn)$fn();$out=ob_get_clean();check(substr_count($out,'google-adsense-account')===1,'one ownership tag');check(!str_contains($out,'<script'),'head nonexecuting');
check(empty($actions['wp_footer']),'no loader hook');
echo "PASS policy routes, off-state disclosure, original content, host/admin guards, ownership-only head and no loader\n";
foreach(['sa-analytics.php','sa-customer-analytics.php','sa-ga4-commerce.php','sa-google-analytics.php','sa-journey-sessions.php','sa-privacy-clarity.php'] as $file){
 $src=file_get_contents(__DIR__.'/../wp-content/mu-plugins/'.$file);
 preg_match("~add_action\\('wp_(?:head|footer)',\\s*static function\\s*\\(\\)\\s*:\\s*void\\s*\\{\\s*(if \\(function_exists\\('sa_tracking_policy_page'\\).*?return; \\})~s",$src,$m);
 check(!empty($m[1]),'early exclusion exists: '.$file);
 $guard=eval('return static function(){'.$m[1].' return "continued";};');
 foreach(['privacy-policy','cookie-policy'] as $page)check($guard()===null,'hook exits: '.$file);
 $page='guides';check($guard()==='continued','hook retains guides: '.$file);
}
echo "PASS all six optional browser hooks exit on disclosure pages before other work\n";
