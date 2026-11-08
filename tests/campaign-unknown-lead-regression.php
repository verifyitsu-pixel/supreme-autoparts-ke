<?php
const ABSPATH='fixture/';
class WC_Product { public function __construct(private array $meta=[]){} public function get_meta($k){return $this->meta[$k]??'';} }
function is_product(){return $GLOBALS['context']==='product';} function is_cart(){return $GLOBALS['context']==='cart';} function is_checkout(){return $GLOBALS['context']==='checkout';}
function get_queried_object_id(){return $GLOBALS['id'];} function wc_get_product($id){return $GLOBALS['products'][$id]??false;} function WC(){return $GLOBALS['wc'];} function esc_html($x){return htmlspecialchars($x,ENT_QUOTES);}
// Fixed clock in the isolated loaded test copy, never in production.
$source=file_get_contents(__DIR__.'/../wp-content/plugins/supreme-marketing/inc/class-sm-welcome.php');
$source=str_replace("new DateTimeImmutable('now', \$tz)","new DateTimeImmutable('2026-11-08 12:00:00', \$tz)",$source,$replaced);
if($replaced!==1)throw new RuntimeException('Expected exactly one campaign clock seam');
eval(substr($source,5));
function ensure($b,$m){if(!$b)throw new RuntimeException($m);echo "PASS $m\n";}
$GLOBALS['products']=[1=>new WC_Product(['_sa_source_special_order'=>'yes']),2=>new WC_Product(['_sa_source_orderable_unknown'=>'yes']),3=>new WC_Product(),4=>new WC_Product(['_sa_source_special_order'=>'no'])];
$GLOBALS['wc']=(object)['customer'=>null,'cart'=>null];
function rendered(){ob_start();SM_Welcome::campaign_banner();SM_Welcome::campaign_reminder();return ob_get_clean();}
function inspect($unknown,$label){$s=rendered();ensure(str_contains($s,'30% off US')&&str_contains($s,'No minimum')&&str_contains($s,'Through Nov 30'),'offer preserved '.$label);ensure(str_contains($s,'US free'),'US free preserved '.$label);ensure(str_contains($s,'Kenya courier'),'Kenya rates preserved '.$label);ensure(str_contains($s,'1-3 working days')===!$unknown,'US timing '.$label);ensure(str_contains($s,'1-2 working days')===!$unknown,'Kenya timing '.$label);if($unknown)ensure(str_contains($s,'to be confirmed')||str_contains($s,'unconfirmed availability'),'unknown dates explicit '.$label);}
if(!SM_Welcome::campaign_active())throw new RuntimeException('Fixed campaign clock not active');
$GLOBALS['context']='product';foreach([1=>true,2=>true,3=>false,4=>false,999=>false]as$id=>$unknown){$GLOBALS['id']=$id;inspect($unknown,'product'.$id);}
class CartFixture {public function __construct(private array $items){}public function get_cart(){return $this->items;}}
foreach(['cart','checkout']as$context){$GLOBALS['context']=$context;foreach([[3],[1],[2],[3,2],[]]as$ids){$GLOBALS['wc']->cart=new CartFixture(array_map(fn($id)=>['data'=>$GLOBALS['products'][$id]],$ids));inspect(in_array(1,$ids)||in_array(2,$ids),$context.json_encode($ids));}}
$GLOBALS['context']='other';inspect(false,'unrelated page');
