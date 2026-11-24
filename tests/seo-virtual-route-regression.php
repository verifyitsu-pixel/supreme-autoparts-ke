<?php
// Isolated virtual-route SEO fixtures. No WordPress database, network or order writes.
define('ABSPATH', __DIR__);
function add_action(...$a){} function add_filter(...$a){} function remove_action(...$a){}
function get_query_var($k){return $GLOBALS['q'][$k]??'';}
function home_url($p='/'){return 'https://example.test'.$p;}
function is_admin(){return false;} function is_cart(){return false;} function is_checkout(){return false;}
function is_account_page(){return false;} function is_wc_endpoint_url($v){return false;}
function is_search(){return false;} function is_404(){return false;} function is_page($v){return false;}
function apply_filters($tag,$v){return $v;}
function sa_svc_catalog(){return ['oil-change'=>['name'=>'Oil change']];}
require __DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/seo.php';
function check($ok,$why){if(!$ok)throw new RuntimeException($why);echo "PASS $why\n";}
foreach(['index'=>'services/','book'=>'book-service/','confirmed'=>'book-service/confirmed/','pay'=>'book-service/pay/','manage'=>'manage-booking/','track'=>'track-order/','terms'=>'booking-terms/'] as $view=>$path){
 $GLOBALS['q']=['sa_svc_view'=>$view];check(sa_seo_canonical_url()==='https://example.test/'.$path,'service canonical '.$view);
 check(sa_seo_should_noindex()===!in_array($view,['index','terms'],true),'service privacy '.$view);
}
foreach(['index'=>'roadside/','request'=>'roadside/request/','status'=>'roadside/status/','track'=>'roadside/track/','provider'=>'roadside/provider/','join'=>'roadside/join/','thanks'=>'roadside/thanks/','terms'=>'roadside-terms/'] as $view=>$path){
 $GLOBALS['q']=['sa_rs_view'=>$view];check(sa_seo_canonical_url()==='https://example.test/'.$path,'roadside canonical '.$view);
 check(sa_seo_should_noindex()===!in_array($view,['index','terms','join'],true),'roadside privacy '.$view);
}
$GLOBALS['q']=['sa_svc_view'=>'detail','sa_svc'=>'oil-change'];check(sa_seo_canonical_url()==='https://example.test/services/oil-change/','service detail canonical');
$GLOBALS['q']=['sa_svc_view'=>'detail','sa_svc'=>'missing'];check(sa_seo_virtual_context()===null,'unknown detail not invented');
$GLOBALS['q']=[];check(sa_seo_virtual_context()===null,'ordinary pages untouched');
check(strpos(file_get_contents(__DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/seo.php'),'$desc = $virtual[2]')!==false,'virtual metadata used before homepage fallback');

$_SERVER['REQUEST_URI']='/pricing/?sa_view=ke';check(sa_seo_canonical_url()==='https://example.test/pricing/','pricing trailing slash query-free canonical');check(!sa_seo_should_noindex(),'pricing remains indexable');check(sa_seo_virtual_context()[1]==='Car Service Prices in Nairobi','pricing specific title');
unset($_SERVER['REQUEST_URI']);

$header=file_get_contents(__DIR__.'/../wp-content/themes/supreme-autoparts/header.php');
$front=file_get_contents(__DIR__.'/../wp-content/themes/supreme-autoparts/front-page.php');
check(strpos($header,'Skip to main content')!==false && strpos($header,'m.focus()')!==false,'visible-on-focus skip link targets actual main');
check(strpos($front,'<main id="primary" tabindex="-1">')!==false && strpos($front,"echo '</main>'")!==false,'homepage main landmark encloses content');
