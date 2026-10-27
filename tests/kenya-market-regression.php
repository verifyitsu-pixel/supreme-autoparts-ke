<?php
define('ABSPATH',__DIR__);define('HOUR_IN_SECONDS',3600);define('DAY_IN_SECONDS',86400);$feed='google';
function add_action(...$a){} function add_filter(...$a){} function get_query_var($k){global $feed;return $k==='feed'?$feed:null;}
function add_query_arg($k,$v,$u){$parts=parse_url($u);$q=[];parse_str($parts['query']??'',$q);$q[$k]=$v;return ($parts['scheme']??'https').'://'.$parts['host'].($parts['path']??'/').'?'.http_build_query($q);}
function get_transient($k){return ['USD'=>1,'rates'=>['USD'=>1,'KES'=>129.87]];}
function is_ssl(){return true;}
function is_admin(){return false;} function wp_doing_ajax(){return false;}function wp_doing_cron(){return false;}function doing_action($x){return false;}function did_action($x){return false;}
function sa_geo_currency_enabled(){return true;}function sa_geo_checkout_currency(){return 'USD';}
class WC_Product {function get_sku(){return 'WETTS0833';}function get_id(){return 1;}function get_meta(...$x){return '';}}
function get_post_meta(...$a){return '';} function wp_get_post_terms(...$a){return [];}function is_wp_error($v){return false;}
function get_the_terms(...$a){return [];}
require dirname(__DIR__) . '/wp-content/plugins/sa-geo-currency/includes/class-sa-geo-detector.php';
require dirname(__DIR__) . '/wp-content/plugins/sa-geo-currency/includes/class-sa-fx-rates.php';
require dirname(__DIR__) . '/wp-content/plugins/sa-geo-currency/includes/class-sa-geo-display.php';
require dirname(__DIR__) . '/wp-content/plugins/supreme-autoparts-core/includes/google-product-feed.php';
function check($v,$name){if(!$v){throw new Exception($name);}echo "PASS $name\n";}
$_SERVER['HTTP_CF_IPCOUNTRY']='KE';
$rp=new ReflectionProperty('SA_Geo_Detector','resolved');$rp->setAccessible(true);$rp->setValue(null,['country'=>'KE','currency'=>'KES']);
$_GET['sa_market']='ke';check(SA_Geo_Detector::display_currency()==='USD','Kenyan visitor pinned to USD on Merchant landing');
unset($_GET['sa_market']);unset($_COOKIE['sa_merchant_usd']);$rp->setValue(null,['country'=>'US','currency'=>'USD']);
$_SERVER['HTTP_CF_IPCOUNTRY']='US';check(SA_Geo_Detector::display_currency()==='USD','ordinary US currency unchanged');
$_GET['sa_market']='ke';check(SA_Geo_Detector::display_currency()==='USD','Kenya landing USD matches payment despite visitor IP');check(SA_Geo_Detector::country_code()==='US','market does not forge country or shipping region');
$in='<item><g:link>https://www.supremeautoparts.co.ke/product/test/?a=1&amp;b=2</g:link><g:price>83.95 USD</g:price></item>';
check(sa_gpf_market_links($in)===$in,'US feed bytes unchanged');$feed='google-kes';$out=sa_gpf_market_links($in);check(str_contains($out,'sa_market=ke')&&str_contains($out,'a=1&amp;b=2&amp;'),'Kenya cached links pin and XML escapes');check(substr_count(sa_gpf_market_links($out),'sa_market=ke')===1,'pin idempotent');
$offer=['@type'=>'Offer','price'=>'83.95','priceCurrency'=>'USD','url'=>'https://www.supremeautoparts.co.ke/product/test/','priceSpecification'=>[['@type'=>'UnitPriceSpecification','price'=>'83.95','priceCurrency'=>'USD']]];
$m=['offers'=>[$offer]];$got=sa_gpf_enrich_product_schema($m,new WC_Product);
check($got['offers']===$m['offers'],'Kenya schema stays stored USD like payment');
check(sa_gpf_format_price(83.95)==='83.95 USD','Kenya feed price USD matches checkout');
unset($_GET['sa_market']);check(SA_Geo_Detector::display_currency()==='USD','USD persists to checkout without query');
unset($_GET['sa_market']);unset($_COOKIE['sa_merchant_usd']);$us=sa_gpf_enrich_product_schema($m,new WC_Product);check($us['offers']===$m['offers'],'ordinary US schema unchanged');
$_GET['sa_market']=['ke'];check(SA_Geo_Detector::display_currency()==='USD','invalid array parameter ignored safely');
