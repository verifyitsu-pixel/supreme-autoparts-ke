<?php
define('ABSPATH',1);
function is_checkout(){return $GLOBALS['checkout']??false;}
function is_wc_endpoint_url($e){return ($GLOBALS['endpoint']??'')===$e;}
require __DIR__.'/../wp-content/plugins/sa-geo-currency/includes/class-sa-geo-display.php';
function ck($x){if(!$x)throw new Exception('payment display check failed');}
$GLOBALS['checkout']=true;ck(SA_Geo_Display::payment_surface());ck(!SA_Geo_Display::should_convert());ck(strpos(SA_Geo_Display::note_html(),'order currency')!==false);
$GLOBALS['checkout']=false;$GLOBALS['endpoint']='order-pay';ck(!SA_Geo_Display::should_convert());
$GLOBALS['endpoint']='';foreach(['update_order_review','checkout'] as $a){$_GET['wc-ajax']=$a;ck(!SA_Geo_Display::should_convert());}
$_GET['wc-ajax']='get_refreshed_fragments';ck(!SA_Geo_Display::payment_surface());unset($_GET['wc-ajax']);ck(!SA_Geo_Display::payment_surface());
echo "8 payment-surface display checks PASS\n";
