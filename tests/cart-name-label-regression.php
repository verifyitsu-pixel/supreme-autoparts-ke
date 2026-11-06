<?php
const ABSPATH='fixture/';$GLOBALS['checkout']=true;$GLOBALS['filters']=[];
function add_action(...$a){}function add_filter($n,$cb,...$rest){$GLOBALS['filters'][$n]=$cb;}function is_checkout(){return $GLOBALS['checkout'];}
class WC_Product {public $flag=true,$backorder=true;function get_meta($k){return $this->flag?'yes':'';}function is_on_backorder($q){return $this->backorder;}}
require $argv[1]??__DIR__.'/../wp-content/mu-plugins/sa-source-special-order.php';$fn=$GLOBALS['filters']['woocommerce_cart_item_name'];$p=new WC_Product();
function check($truth,$label){if(!$truth)throw new Exception($label);echo "PASS $label\n";}
$out=$fn('Gate product',['data'=>$p],'x');check(str_contains($out,'Special order - available on backorder'),'checkout marker label');check(!str_contains($out,'confirmed'),'no unsupported date assertion');
$p->flag=false;check($fn('Ordinary',['data'=>$p],'x')==='Ordinary','ordinary unchanged');$p->flag=true;$p->backorder=false;check($fn('In stock',['data'=>$p],'x')==='In stock','not backorder unchanged');$p->backorder=true;$GLOBALS['checkout']=false;check(str_contains($fn('Mini cart',['data'=>$p],'x'),'Special order'),'mini cart also covered');check($fn('Missing',[],'x')==='Missing','missing product safe');
