<?php
const ABSPATH='fixture/';
class WC_Product { public function __construct(private array $meta=[]){} public function get_meta($k){return $this->meta[$k]??'';} }
function is_order_received_page(){return $GLOBALS['context']==='received';} function is_product(){return $GLOBALS['context']==='product';} function is_cart(){return $GLOBALS['context']==='cart';} function is_checkout(){return $GLOBALS['context']==='checkout';}
function get_queried_object_id(){return $GLOBALS['id'];} function wc_get_product($id){return $GLOBALS['products'][$id]??false;} function WC(){return $GLOBALS['wc'];} function esc_html($x){return htmlspecialchars($x,ENT_QUOTES);}
// Fixed clock in the isolated loaded test copy, never in production.
$source=file_get_contents(__DIR__.'/../wp-content/plugins/supreme-marketing/inc/class-sm-welcome.php');
$source=str_replace("new DateTimeImmutable('now', \$tz)","new DateTimeImmutable('2026-11-08 12:00:00', \$tz)",$source,$replaced);
if($replaced!==1)throw new RuntimeException('Expected exactly one campaign clock seam');
eval(substr($source,5));
function ensure($b,$m){if(!$b)throw new RuntimeException($m);echo "PASS $m\n";}
$GLOBALS['products']=[1=>new WC_Product(['_sa_source_special_order'=>'yes']),2=>new WC_Product(['_sa_source_orderable_unknown'=>'yes']),3=>new WC_Product(),4=>new WC_Product(['_sa_source_special_order'=>'no'])];
$GLOBALS['wc']=(object)['customer'=>null,'cart'=>null];
function rendered(){ob_start();SM_Welcome::campaign_banner();$banner=ob_get_clean();ob_start();SM_Welcome::campaign_reminder();$reminder=ob_get_clean();$GLOBALS['surfaces']=[$banner,$reminder];return $banner.$reminder;}
function inspect($unknown,$label){$s=rendered();ensure(str_contains($s,'30% off US')&&str_contains($s,'No minimum')&&str_contains($s,'Through Nov 30'),'offer preserved '.$label);ensure(str_contains($s,'US free'),'US free preserved '.$label);ensure(str_contains($s,'Kenya courier'),'Kenya rates preserved '.$label);ensure(str_contains($s,'1-3 working days')===!$unknown,'US timing '.$label);ensure(str_contains($s,'1-2 working days')===!$unknown,'Kenya timing '.$label);foreach($GLOBALS['surfaces']as$n=>$surface){ensure(str_contains($surface,'1-3 working days')===!$unknown,'individual surface US timing '.$label.'/'.$n);ensure(str_contains($surface,'1-2 working days')===!$unknown,'individual surface KE timing '.$label.'/'.$n);}if($unknown)ensure(str_contains($s,'to be confirmed')||str_contains($s,'unconfirmed availability'),'unknown dates explicit '.$label);}
if(!SM_Welcome::campaign_active())throw new RuntimeException('Fixed campaign clock not active');
$GLOBALS['context']='product';foreach([1=>true,2=>true,3=>false,4=>false,999=>false]as$id=>$unknown){$GLOBALS['id']=$id;inspect($unknown,'product'.$id);}
class CartFixture {public function __construct(private array $items){}public function get_cart(){return $this->items;}public function get_shipping_total(){return 0;}public function needs_shipping(){return true;}public function show_shipping(){return false;}public function get_coupons(){return [];}public function get_fees(){return [];}}
foreach(['cart','checkout']as$context){$GLOBALS['context']=$context;foreach([[3],[1],[2],[3,2],[]]as$ids){$GLOBALS['wc']->cart=new CartFixture(array_map(fn($id)=>['data'=>$GLOBALS['products'][$id]],$ids));inspect(in_array(1,$ids)||in_array(2,$ids),$context.json_encode($ids));}}
$GLOBALS['context']='other';inspect(false,'unrelated page');

$GLOBALS['context']='received';$GLOBALS['wc']->cart=new CartFixture([]);inspect(true,'empty-cart order received');

function esc_html_e($x,...$a){echo esc_html($x);}function esc_attr_e($x,...$a){echo esc_html($x);}function esc_url($x){return $x;}
function do_action(...$a){}function wc_coupons_enabled(){return false;}function wc_cart_totals_subtotal_html(){}function wc_cart_totals_order_total_html(){}function wc_tax_enabled(){return false;}function wc_get_checkout_url(){return '/checkout/';}function sa_ship_is_us(){return $GLOBALS['us'];}
class CustomerFixture {function has_calculated_shipping(){return false;}}
$GLOBALS['context']='cart';$GLOBALS['wc']->customer=new CustomerFixture();
foreach([true,false]as$us){$GLOBALS['us']=$us;foreach([[3],[1],[2],[3,2],[]]as$ids){$GLOBALS['wc']->cart=new CartFixture(array_map(fn($id)=>['data'=>$GLOBALS['products'][$id]],$ids));ob_start();include __DIR__.'/../wp-content/themes/supreme-autoparts/woocommerce/cart/cart-totals.php';$html=ob_get_clean();$unknown=in_array(1,$ids)||in_array(2,$ids);ensure(str_contains($html,$us?'1-3 working days':'1-2 working days')===!$unknown,'actual cart totals timing '.json_encode([$us,$ids]));ensure(str_contains($html,$us?'Free US shipping':'Kenya courier delivery'),'cart totals shipping terms retained');if($unknown)ensure(str_contains($html,'confirmed after your order is checked'),'cart totals unknown explicit');}}
