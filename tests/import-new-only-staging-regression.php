<?php
declare(strict_types=1);
const ABSPATH='fixture/';const WP_CLI=true;const OBJECT='OBJECT';
$GLOBALS['match']=0;$GLOBALS['saved']=[];$GLOBALS['instance']=null;
function sanitize_title($s){return strtolower(str_replace(' ','-',(string)$s));}
function wp_strip_all_tags($s){return strip_tags((string)$s);}
function wp_trim_words($s,$n){return $s;}
function sa_core_import_meta_lookup(...$a){return $GLOBALS['match'];}
function get_page_by_path(...$a){return null;}
function wc_get_product_id_by_sku($s){return 0;}
class WC_Product_Simple{
 public $data=[];
 function __construct(){$GLOBALS['instance']=$this;}
 function __call($n,$a){if(str_starts_with($n,'set_'))$this->data[substr($n,4)]=$a[0];return null;}
 function is_type($t){return $t==='simple';}
 function get_weight(){return '';}
 function save(){$GLOBALS['saved'][]=$this->data;throw new RuntimeException('fixture_stop_before_any_persistence');}
}
require __DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/import-shopify.php';
function ok($b,$m){if(!$b)throw new RuntimeException($m);echo "PASS $m\n";}
function run_fixture($stage,$match=0,$dry=false){$GLOBALS['match']=$match;$GLOBALS['saved']=[];$r=['imported'=>0,'updated'=>0,'skipped'=>0,'errors'=>0,'messages'=>[]];$item=['id'=>'101','handle'=>'brace','title'=>'Exact brace','body_html'=>'Named item only','tags'=>'SpecialOrder:Y','variants'=>[['sku'=>'EXACT','price'=>'126.00','available'=>true]]];sa_core_import_one_shopify_product($item,$r,true,$dry,true,$stage);return $r;}
$r=run_fixture(true);if(!$GLOBALS['saved'])echo json_encode($r);$s=$GLOBALS['saved'][0]??[];ok(($s['status']??'')==='draft','staging first save uses draft');ok(($s['catalog_visibility']??'')==='hidden','staging hidden before first save');ok(($s['stock_status']??'')==='onbackorder','special order preserved');ok(($s['regular_price']??'')==='126','source USD price preserved');
$r=run_fixture(true,44);ok($GLOBALS['saved']===[],'existing match has no save');ok($r['errors']===1,'existing match fails closed');
run_fixture(false);$s=$GLOBALS['saved'][0]??[];ok(($s['status']??'')==='publish','ordinary import status unchanged');ok(($s['catalog_visibility']??'')==='visible','ordinary visibility unchanged');
$r=run_fixture(true,0,true);ok($GLOBALS['saved']===[]&&$r['imported']===1,'staging dryrun no save');
// This fixture intentionally stops at the first save. It is not WP/DB, hooks, images or terms proof.
