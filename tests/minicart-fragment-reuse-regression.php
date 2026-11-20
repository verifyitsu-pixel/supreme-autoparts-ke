<?php
declare(strict_types=1);
// Isolated theme-fragment contract test. No WordPress database, network, orders or payments.
$path=$argv[1]??dirname(__DIR__).'/wp-content/themes/supreme-autoparts/inc/woocommerce.php';
$source=file_get_contents($path);$start=strpos($source,"add_filter('woocommerce_add_to_cart_fragments',");$end=strpos($source,"\n});",$start);
if($start===false||$end===false){throw new RuntimeException('Fragment filter not found');}
$GLOBALS['filter']=null;$GLOBALS['cart_count']=0;$GLOBALS['cart_available']=true;$GLOBALS['render_calls']=0;
function add_filter($tag,$callback){if($tag!=='woocommerce_add_to_cart_fragments'){throw new RuntimeException('Unexpected hook');}$GLOBALS['filter']=$callback;}
function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function WC(){return (object)['cart'=>$GLOBALS['cart_available']?new class{function get_cart_contents_count(){return $GLOBALS['cart_count'];}}:null];}
function woocommerce_mini_cart(){++$GLOBALS['render_calls'];throw new RuntimeException('Duplicate mini-cart rendering');}
function check(bool $ok,string $label):void{if(!$ok){throw new RuntimeException($label);}echo 'PASS '.$label."\n";}
eval(substr($source,$start,$end+4-$start));$filter=$GLOBALS['filter'];check(is_callable($filter),'expected filter registered');
foreach([['empty',0,'<div class="widget_shopping_cart_content"><p>Your cart is empty.</p></div>'],['USD one item',1,'<div class="widget_shopping_cart_content"><p>Spring <span>1 × $1.28</span></p></div>'],['KES two quantity',2,'<div class="widget_shopping_cart_content"><p>Spring <span>2 × KES 166</span></p></div>']]as[$label,$n,$html]){
 $GLOBALS['cart_count']=$n;$in=['div.widget_shopping_cart_content'=>$html,'other.selector'=>'<b>Unrelated fragment</b>'];$out=$filter($in);
 check($out['div.widget_shopping_cart_content']===$html,$label.': supplied mini-cart bytes/currency/quantity preserved');
 check($out['span.sa-cart-count']==='<span class="sa-cart-count" data-sa-cart-count>'.$n.'</span>',$label.': custom count matches');
 check($out['other.selector']===$in['other.selector'],$label.': unrelated fragment preserved');
}
$GLOBALS['cart_available']=false;$out=$filter(['div.widget_shopping_cart_content'=>'existing']);check($out['span.sa-cart-count']==='<span class="sa-cart-count" data-sa-cart-count>0</span>','missing cart retains zero-count behavior');
check($GLOBALS['render_calls']===0,'no duplicate mini-cart render');
