<?php
$s=file_get_contents($argv[1] ?? __DIR__."/../wp-content/plugins/supreme-autoparts-core/includes/import-shopify.php");$a=strpos($s,'function sa_core_shopify_is_special_order');$b=strpos($s,'/**',$a);eval(substr($s,$a,$b-$a));
foreach([['SpecialOrder:Y',true],['SpecialOrder:N',false],[' SpecialOrder : Y ',true],[['x','SpecialOrder:Y'],true],[null,false],['notSpecialOrder:Y',false],['SpecialOrder:Yes',false]] as [$v,$want]){if(sa_core_shopify_is_special_order(['tags'=>$v])!==$want)throw new Exception('tag fixture failed');}
echo 'PASS explicit string/array source tags, whitespace/case, negative/non-exact/missing tags'.PHP_EOL;
class WC_Product {public $meta='yes';function get_meta($k){return $this->meta;}function is_on_backorder($q){return true;}}
$GLOBALS['filters']=[];function add_filter($n,$c,$p,$a){$GLOBALS['filters'][$n]=$c;}function wc_get_product($id){return $GLOBALS['fixture_product'];}
define('ABSPATH','fixture/');require __DIR__.'/../wp-content/mu-plugins/sa-source-special-order.php';$GLOBALS['fixture_product']=new WC_Product();
foreach($GLOBALS['filters'] as $name=>$fn){$arg=$name==='woocommerce_get_availability_text'?$GLOBALS['fixture_product']:1;if(!str_contains($fn('unchanged',$arg),'Special order'))throw new Exception('new label');$GLOBALS['fixture_product']->meta='';if($fn('unchanged',$arg)!=='unchanged')throw new Exception('existing label changed');$GLOBALS['fixture_product']->meta='yes';}
echo 'PASS new labeled product/cart; unmarked existing product/cart text unchanged'.PHP_EOL;
// Execute the actual changed stock branch with mocks for new/update/out-of-stock.
$a=strpos($s,'            $special_order =');$b=strpos($s,'        }\n\n        $id = $product->save();',$a);if($b===false)$b=strpos($s,"        }\n\n        \$id = \$product->save();",$a);$branch=substr($s,$a,$b-$a);
class FixtureStock {public $events=[];function __call($n,$args){$this->events[]=[$n,$args];}}
foreach([[false,true,true],[true,true,true],[false,false,true],[false,true,false]] as [$is_update,$available,$special]){$product=new FixtureStock();$variant=['available'=>$available];$item=['tags'=>$special?'SpecialOrder:Y':'SpecialOrder:N'];eval($branch);$events=json_encode($product->events);if(!$is_update&&$available&&$special){if(!str_contains($events,'onbackorder')||!str_contains($events,'_sa_source_special_order'))throw new Exception('new special stock');}else{if(str_contains($events,'_sa_source_special_order')||str_contains($events,'set_backorders'))throw new Exception('existing/normal mutated');}}
echo 'PASS actual stock branch new-special/update/normal/unavailable regressions'.PHP_EOL;
