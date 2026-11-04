<?php
define('ABSPATH',1);$GLOBALS['hooks']=[];$GLOBALS['view']='KE';
function add_action($name,$fn,$priority=10){$GLOBALS['hooks'][$name]=$fn;}
function sa_storefront_country(){return $GLOBALS['view'];}
class SA_FX_Rates {static function rate_for($c){return 130;}}
function is_admin(){return false;}function wp_doing_ajax(){return false;}function is_checkout(){return false;}function is_cart(){return false;}function is_account_page(){return false;}function sa_core_is_catalog_product_query($q){return true;}
class Q {function is_main_query(){return true;}}
require __DIR__.'/../wp-content/mu-plugins/sa-catalog-display-currency.php';
assert(sa_catalog_display_currency()==='KES');assert(sa_catalog_price_from_usd(10)===1300.0);assert(sa_catalog_price_to_usd(1300)===10.0);
$_GET=['min_price'=>'1300','max_price'=>'2600'];$GLOBALS['hooks']['pre_get_posts'](new Q);assert((float)$_GET['min_price']===10.0);assert((float)$_GET['max_price']===20.0);assert($GLOBALS['sa_catalog_local_prices']['min_price']==='1300');$GLOBALS['hooks']['pre_get_posts'](new Q);assert((float)$_GET['min_price']===10.0);
$GLOBALS['view']='US';assert(sa_catalog_display_currency()==='USD');assert(sa_catalog_price_from_usd(10)===10.0);assert(sa_catalog_price_to_usd(1300)===1300.0);
echo "PASS KES/USD display math, local filter preservation, stored-USD query bounds, no double conversion\n";
