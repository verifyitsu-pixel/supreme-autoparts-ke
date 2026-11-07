<?php
/** Execute exact source functions in isolation. No geo HTTP, cookies or address writes. */
function load_function($src,$name){$start=strpos($src,'function '.$name.'(');$open=strpos($src,'{',$start);$depth=1;$i=$open+1;for(;$depth;$i++){if($src[$i]==='{')$depth++;elseif($src[$i]==='}')$depth--;}eval(substr($src,$start,$i-$start));}
$source=file_get_contents(__DIR__.'/../wp-content/plugins/sa-geo-currency/sa-geo-currency.php');$detected='KE';function sa_geo_country(){global $detected;return $detected;}load_function($source,'sa_storefront_country');load_function($source,'sa_geo_business');load_function($source,'sa_geo_map_url');
function check($ok,$s){if(!$ok)throw new Exception($s);echo "PASS $s\n";}
foreach(['US'=>'US','KE'=>'KE','GB'=>'KE','NG'=>'KE',''=>'KE'] as $ip=>$expected){$_GET=$_COOKIE=[];$detected=$ip;check(sa_storefront_country()===$expected,'first visit '.$ip.' -> '.$expected);}
foreach(['US','KE'] as $ip){$detected=$ip;foreach(['ke'=>'KE','us'=>'US'] as $choice=>$expected){$_GET=[];$_COOKIE=['sa_storefront_view'=>$choice];check(sa_storefront_country()===$expected,'saved selection beats IP '.$ip.$choice);$_GET=['sa_view'=>$choice];$_COOKIE=['sa_storefront_view'=>$choice==='us'?'ke':'us'];check(sa_storefront_country()===$expected,'explicit switch beats cookie '.$choice);}}
function sa_svc_region(){return 'KE';}function esc_html($s){return htmlspecialchars($s);}function esc_attr($s){return htmlspecialchars($s);}function esc_url($s){return htmlspecialchars($s);}function sa_svc_icon($s){return '';}function sa_svc_map_url(){return 'fixture-garage-map';}define('SA_SVC_GARAGE_ADDRESS','Midax Plaza Nairobi garage');define('SA_SVC_GARAGE_POSTAL','00100');load_function(file_get_contents(__DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/car-services.php'),'sa_svc_address_html');
foreach(['KE'=>'Midax Plaza','US'=>'Portland'] as $cc=>$text){$h=sa_svc_address_html(false,$cc);check(strpos($h,$text)!==false,'contact selected address '.$cc);check(strpos($h,$cc==='KE'?'+254 714 498 451':'+1 (917) 437-5121')!==false,'contact selected phone '.$cc);}
check(strpos(sa_svc_address_html(),'Nairobi garage')!==false,'actual garage directions unchanged');

check(strpos(file_get_contents(__DIR__.'/../wp-content/advanced-cache.php'),"isset(\$_COOKIE['sa_storefront_view']) || isset(\$_GET['sa_view'])")!==false,'manual view bypasses early origin cache');

check(str_contains($source,'function sa_geo_ke_web_swap('),'actual web-contact region guard exists');load_function($source,'sa_storefront_is_kenya');load_function($source,'sa_geo_ke_swap');load_function($source,'sa_geo_ke_web_swap');
foreach(['US','KE'] as $ip){$detected=$ip;foreach(['us','ke'] as $view){$_GET=['sa_view'=>$view];$_COOKIE=[];$in='<a href="tel:+19174375121">+1 917 437 5121</a>';$out=sa_geo_ke_web_swap($in);check($view==='us'?$out===$in:str_contains($out,'+254714498451'),'web contact selected '.$view.' despite IP '.$ip);}}
check(str_contains($source,"!is_admin() && !wp_doing_cron() && sa_geo_is_kenya()"),'email actual-country gate untouched');

$dual = 'call +1 917 437 5121 (US) or +254 714 498 451 (Kenya)';
foreach (['US','KE'] as $ip) { $detected=$ip; foreach (['us','ke'] as $view) { $_GET=['sa_view'=>$view]; $_COOKIE=[];
    check(html_entity_decode(sa_geo_ke_web_swap($dual))===$dual, 'explicit dual contacts unchanged '.$ip.$view);
    check(html_entity_decode(sa_geo_ke_web_swap(sa_geo_ke_web_swap($dual)))===$dual, 'dual contact refresh remains stable '.$ip.$view);
} }
check(sa_geo_ke_swap($dual)!==$dual, 'email legacy helper behavior unchanged');
check(substr_count($source,'is_string($v) ? sa_geo_ke_web_swap($v) : $v')===2,'both web fragment filters preserve explicit labelled contacts');
