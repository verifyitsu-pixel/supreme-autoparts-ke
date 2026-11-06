<?php
// Checkout line titles are clamped to 2 lines, which hid the special-order label. Only lines carrying the label may unclamp.
const ABSPATH='fixture/';$GLOBALS['a']=[];$GLOBALS['co']=true;
function add_action($n,$c,$p=10,$a=1){$GLOBALS['a'][$n]=$c;}function add_filter(...$x){}function is_checkout(){return $GLOBALS['co'];}
require __DIR__.'/../wp-content/mu-plugins/sa-source-special-order.php';
$f=$GLOBALS['a']['wp_head'];
ob_start();$f();$h=ob_get_clean();
if(!str_contains($h,'.sa-co-line__t:has(.backorder_notification)')||!str_contains($h,'-webkit-line-clamp:unset')||!str_contains($h,'overflow:visible'))throw new Exception('scoped unclamp missing');
if(preg_match('/\}[^}]*\{/',trim(substr($h,strpos($h,'>')+1,-8))))throw new Exception('rule must be single, scoped');
echo "PASS checkout unclamp rule scoped to lines carrying the special-order label\n";
$GLOBALS['co']=false;ob_start();$f();if(ob_get_clean()!=='')throw new Exception('leaks outside checkout');echo "PASS nothing emitted outside checkout\n";
$css=file_get_contents(__DIR__.'/../wp-content/themes/supreme-autoparts/assets/css/editorial.css');
if(!str_contains($css,'.sa-co-line__t{display:-webkit-box;-webkit-line-clamp:2;'))throw new Exception('global clamp changed');echo "PASS global 2-line clamp for ordinary items untouched\n";
