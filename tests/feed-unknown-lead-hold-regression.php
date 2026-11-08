<?php
const ABSPATH='fixture/';function add_action(...$a){}function add_filter(...$a){}
class WC_Product {public function __construct(private string $source){}public function get_meta($k){return $k==='_sa_shopify_id'?$this->source:'';}}
require __DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/google-product-feed.php';
function ok($b,$m){if(!$b)throw new RuntimeException($m);echo "PASS $m\n";}
foreach(['7742545920177','7742546018481']as$id)ok(sa_gpf_unknown_lead_source_hold(new WC_Product($id)),'exact source hold '.$id);
ok(!sa_gpf_unknown_lead_source_hold(new WC_Product('other')),'other sources untouched');ok(!sa_gpf_unknown_lead_source_hold(null),'nonproduct safe');
foreach(['CUS949 477 A','CUS949 492 C','EBCBSD7801']as$sku){foreach(['id','mpn']as$tag){foreach([false,true]as$cdata){$value=$cdata?'<![CDATA['.$sku.']]>':$sku;$item='<item><g:'.$tag.'>'.$value.'</g:'.$tag.'><g:description>test</g:description></item>';ok(sa_gpf_apply_image_holds($item)==='','cached exact '.$tag.' '.$sku.' cdata'.(int)$cdata);}}}
foreach(['CUS949 477 AB','OTHER','CUS949 492 CX']as$sku){$item='<item><g:id>'.$sku.'</g:id><g:description>CUS949 477 A</g:description></item>';ok(sa_gpf_apply_image_holds($item)===$item,'unrelated/description/partial retained '.$sku);}
$source=file_get_contents(__DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/google-product-feed.php');ok(strpos($source,'if (sa_gpf_unknown_lead_source_hold($product))')<strpos($source,"$"."availability = $"."product->is_in_stock()"),'live item hold before availability');ok(substr_count($source,'sa_gpf_apply_image_holds($chunk)')>=1,'prebuilt route uses holds');
