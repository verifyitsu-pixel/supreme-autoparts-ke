<?php
// Isolated virtual-route SEO fixtures. No WordPress database, network or order writes.
define('ABSPATH', __DIR__);
function add_action($tag,$cb,...$a){$GLOBALS['actions'][$tag][]=$cb;} function add_filter(...$a){} function remove_action(...$a){}
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
check(strpos($header,'Skip to main content')!==false && strpos($header,'m.focus({preventScroll:true})')!==false,'visible-on-focus skip link targets actual main');
check(strpos($front,'<main id="primary" tabindex="-1">')!==false && strpos($front,"echo '</main>'")!==false,'homepage main landmark encloses content');

$urls=sa_seo_public_virtual_sitemap_urls();
check(count($urls)===7,'sitemap six public routes plus actual catalog service');
check(count(array_unique(array_column($urls,'loc')))===count($urls),'sitemap no duplicate URLs');
foreach($urls as $entry){
 check(array_keys($entry)===['loc'],'sitemap no invented modified dates');
 check(strpos($entry['loc'],'?')===false && substr($entry['loc'],-1)==='/','sitemap canonical trailing slash no query');
 check(!preg_match('~book-service/|manage-booking/|track-order/|roadside/(request|status|track|provider|thanks)/~',$entry['loc']),'sitemap excludes private flows');
}
abstract class WP_Sitemaps_Provider {
 protected $name=''; protected $object_type='';
 abstract public function get_url_list($page_num,$object_subtype='');
 abstract public function get_max_num_pages($object_subtype='');
}
$registry=new class {public $providers=[];function add_provider($name,$p){$this->providers[$name]=$p;}};
$server=(object)['registry'=>$registry];
foreach($GLOBALS['actions']['wp_sitemaps_init'] as $cb){$cb($server);}
check(isset($registry->providers['supreme']),'native provider registered');
$provider=$registry->providers['supreme'];
check($provider->get_url_list(1)===$urls,'native provider returns allowlisted URLs');
check($provider->get_url_list(2)===[] && $provider->get_url_list(0)===[],'native provider rejects other pages');
check($provider->get_url_list(1,'private')===[] && $provider->get_max_num_pages('private')===0,'native provider rejects subtypes');
check($provider->get_max_num_pages()===1,'native provider single bounded sitemap');

$consent=file_get_contents(__DIR__.'/../wp-content/plugins/supreme-marketing/inc/class-sm-consent.php');
check(strpos($consent,'aria-label="Email address for parts and offers"')!==false,'footer email has accessible name beyond placeholder');

$footer=substr($consent,strpos($consent,'function sm_footer_subscribe_form'));
check(strpos($footer,'color:#171717;')!==false && strpos($footer,'color:#595959;')!==false && strpos($footer,'color:#F4F4F5;')===false,'footer subscribe text contrasts with white footer');

$roadside=file_get_contents(__DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/roadside.php');
check(strpos($roadside,'name="when_at" aria-label="Preferred date and time"')!==false,'roadside schedule field has a distinct accessible name');

$roadsideCss=file_get_contents(__DIR__.'/../wp-content/plugins/supreme-autoparts-core/assets/roadside.css');
check(strpos($roadsideCss,'fieldset{min-width:0;')!==false && strpos($roadsideCss,'.sa-rs-schedule input[type=datetime-local]{width:100%;min-width:0;max-width:100%}')!==false,'roadside form fields can shrink within mobile card');
check(strpos($roadside,'required><span>I accept the')!==false && strpos($roadside,'call.</span></label>')!==false,'roadside consent remains one wrapping text block');

check(strpos($roadsideCss,'@media(max-width:599px){.sa-rs-callbar{flex-direction:column}')!==false && strpos($roadsideCss,'.sa-rs{padding-bottom:144px}')!==false,'roadside contact bar stacks with matching page clearance on narrow phones');
