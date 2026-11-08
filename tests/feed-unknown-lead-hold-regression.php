<?php
const ABSPATH='fixture/';function add_action(...$a){}function add_filter(...$a){}
class WC_Product {public function __construct(private string $source){}public function get_meta($k){return $k==='_sa_shopify_id'?$this->source:'';}}
require __DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/google-product-feed.php';
function ok($b,$m){if(!$b)throw new RuntimeException($m);echo "PASS $m\n";}
foreach(['7742545920177','7742546018481','7742546215089','7678281253041']as$id)ok(sa_gpf_unknown_lead_source_hold(new WC_Product($id)),'exact source hold '.$id);
ok(!sa_gpf_unknown_lead_source_hold(new WC_Product('other')),'other sources untouched');ok(!sa_gpf_unknown_lead_source_hold(null),'nonproduct safe');
foreach(['CUS949 477 A','CUS949 492 C','CUS949 540 A','EBCBSD7801','SPC67416']as$sku){foreach(['id','mpn']as$tag){foreach([false,true]as$cdata){$value=$cdata?'<![CDATA['.$sku.']]>':$sku;$item='<item><g:'.$tag.'>'.$value.'</g:'.$tag.'><g:description>test</g:description></item>';ok(sa_gpf_apply_image_holds($item)==='','cached exact '.$tag.' '.$sku.' cdata'.(int)$cdata);}}}
foreach(['CUS949 477 AB','OTHER','CUS949 492 CX','CUS949 540 AB','SPC67416A','SPC674160']as$sku){$item='<item><g:id>'.$sku.'</g:id><g:description>CUS949 477 A</g:description></item>';ok(sa_gpf_apply_image_holds($item)===$item,'unrelated/description/partial retained '.$sku);}
$source=file_get_contents(__DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/google-product-feed.php');$hold=strpos($source,'if (sa_gpf_unknown_lead_source_hold($product))');$availability=strpos($source,"$"."availability = $"."product->is_in_stock()");ok($hold!==false&&$availability!==false&&$hold<$availability,'live item hold present before availability');ok(substr_count($source,'sa_gpf_apply_image_holds($chunk)')>=1,'prebuilt route uses holds');

$held='<item><g:id>CUS949 477 A</g:id></item>';$retained='<item><g:id>UNRELATED</g:id><g:description>CUS949 477 A</g:description></item>';
ok(sa_gpf_apply_image_holds($held.$retained.'<item><g:mpn>CUS949 492 C</g:mpn></item>')===$retained,'mixed chunk only exact held items removed');
$malformed='<item><g:id>CUS949 477 A</g:mpn></item>';ok(sa_gpf_apply_image_holds($malformed)===$malformed,'mismatched tag not treated as exact identity');
// Guarded real entry call must return before any other product method is needed.
function wc_get_product($id){return new WC_Product([76362=>'7742545920177',76364=>'7742546018481',99999=>'7742546215089',99998=>'7678281253041'][$id]);}
foreach([76362,76364,99999,99998]as$id){ob_start();sa_gpf_render_item($id);ok(ob_get_clean()==='','actual live item entry excludes '.$id);}
