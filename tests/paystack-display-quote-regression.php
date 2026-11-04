<?php
// Supreme Paystack display/lock integration: mu-plugin + quote helper, mock Woo, no network.
define('ABSPATH',1);
$AC=0; function ck($c,$m=''){ $GLOBALS['AC']++; if(!$c) throw new Exception('CHECK FAILED: '.$m); }
$hooks=[];
function add_filter($tag,$fn,$p=10,$a=1){global $hooks;$hooks[$tag][]=$fn;}
function add_action($tag,$fn,$p=10,$a=1){add_filter($tag,$fn,$p,$a);}
function apply_filters($tag,$v,...$args){if($tag==='sa_paystack_native_usd_enabled')return $GLOBALS['native_usd']??false;global $hooks;foreach($hooks[$tag]??[] as $fn)$v=$fn($v,...$args);return $v;}
function do_action($tag,...$args){global $hooks;foreach($hooks[$tag]??[] as $fn)$fn(...$args);}
function __($s,$d=''){return $s;}
function wp_salt($s){return 'test-only-signing-key';}
function esc_html($s){return $s;}
function esc_url($s){return $s;}
function esc_attr($s){return $s;}
function plugins_url($p=''){return 'https://example.invalid/'.$p;}
function wc_price($a,$o=[]){return 'KES '.number_format($a,2);}
function absint($v){return (int)$v;}
function current_user_can($c){return true;}
class SA_FX_Rates { public static $rates=['KES'=>129.50];
    public static function rate_for($c){return self::$rates[$c]??null;}
    public static function decimals_for($c){return 2;} }
class Session { public $d=[]; function get_customer_id(){return 'test-session';} function set($k,$v){$this->d[$k]=$v;} function get($k){return $this->d[$k]??null;} }
class Cart { public $total=100.0; function get_total($m=''){return $this->total;} }
class WCStub { public $session,$cart; function __construct(){ $this->session=new Session; $this->cart=new Cart; } }
$GLOBALS['wc']=new WCStub;
function WC(){return $GLOBALS['wc'];}
class MOrder { public $currency,$total,$method,$meta=[],$saved=0,$id; static $seq=0;
    function __construct($currency,$total,$method='paystack'){$this->id=++self::$seq;$this->currency=$currency;$this->total=$total;$this->method=$method;}
    function get_id(){return $this->id;} function get_currency(){return $this->currency;} function get_total(){return $this->total;}
    function get_payment_method(){return $this->method;}
    function get_meta($k){return $this->meta[$k]??'';} function update_meta_data($k,$v){$this->meta[$k]=$v;}
    function save(){$this->saved++;} }
$GLOBALS['orders']=[];
function wc_get_order($id){return $GLOBALS['orders'][$id]??null;}
function get_woocommerce_currency(){return 'USD';}
function is_wc_endpoint_url($e){return ($GLOBALS['endpoint']??'')===$e;}
function get_query_var($k){return $GLOBALS['qv'][$k]??0;}

require __DIR__.'/../wp-content/plugins/woo-paystack/includes/class-sa-paystack-charge-quote.php';
require __DIR__.'/../wp-content/mu-plugins/sa-paystack.php';


function setq($q){ WC()->session->set('sa_paystack_quote',$q); $_POST['sa_paystack_quote_token']=is_array($q) ? SA_Paystack_Charge_Quote::token($q,SA_Paystack_Charge_Quote::checkout_scope()) : ''; }

// Meta key contract between mu-plugin and gateway helper.
ck(SA_PAYSTACK_DISPLAY_META_KEY===SA_Paystack_Charge_Quote::DISPLAY_META_KEY);

// 1. Checkout description shows the exact charge and rate, and stores the session quote.
$desc=apply_filters('woocommerce_gateway_description','orig','paystack');
ck(strpos($desc,'KES 12,950.00')!==false && strpos($desc,'129.5')!==false);
$sq=WC()->session->get('sa_paystack_quote'); ck(is_array($sq) && $sq['kes']===12950.0 && $sq['rate']===129.5 && $sq['usd']===100.0);

// 2. No rate: honest unavailability, no session quote.
SA_FX_Rates::$rates=[];
setq(null);
$desc=apply_filters('woocommerce_gateway_description','orig','paystack');
ck(strpos($desc,'temporarily unavailable')!==false);
SA_FX_Rates::$rates=['KES'=>129.50];

// 3. Other gateways and descriptions untouched.
ck(apply_filters('woocommerce_gateway_description','orig','pesapal')==='orig');

// 4. Order creation locks the visible quote; order stays USD with untouched totals.
setq(['usd'=>100.0,'rate'=>129.5,'kes'=>12950.0,'at'=>time()]);
$o=new MOrder('USD',100.0);
do_action('woocommerce_checkout_create_order',$o,['payment_method'=>'paystack']);
$d=$o->meta[SA_PAYSTACK_DISPLAY_META_KEY]??null;
ck(is_array($d) && $d['charge_amount']===12950.0 && $d['rate']===129.5 && $d['order_currency']==='USD' && $d['order_total']===100.0);
ck($o->currency==='USD' && $o->total===100.0);

// 5. Other payment methods and KES orders pass through untouched.
$o=new MOrder('USD',100.0,'pesapal'); do_action('woocommerce_checkout_create_order',$o,['payment_method'=>'pesapal']);
ck(!isset($o->meta[SA_PAYSTACK_DISPLAY_META_KEY]));
$o=new MOrder('KES',500.0); do_action('woocommerce_checkout_create_order',$o,['payment_method'=>'paystack']);
ck(!isset($o->meta[SA_PAYSTACK_DISPLAY_META_KEY]));

// 6. Over-2500 orders and stale/mismatched session quotes fail closed.
foreach([['USD',2500.01],] as $c){ $o=new MOrder($c[0],$c[1]);
    try{ do_action('woocommerce_checkout_create_order',$o,['payment_method'=>'paystack']); ck(false,'2500 cap must throw'); }catch(Exception $e){} }
WC()->cart->total=100.0; setq(['usd'=>99.0,'rate'=>129.5,'kes'=>12820.5,'at'=>time()]);
$o=new MOrder('USD',100.0);
try{ do_action('woocommerce_checkout_create_order',$o,['payment_method'=>'paystack']); ck(false,'mismatched quote must throw'); }catch(Exception $e){}

// 7. The locked display quote drives the gateway charge figure exactly.
$o=new MOrder('USD',100.0); setq(['usd'=>100.0,'rate'=>129.5,'kes'=>12950.0,'at'=>time()]);
do_action('woocommerce_checkout_create_order',$o,['payment_method'=>'paystack']);
SA_FX_Rates::$rates=['KES'=>140.0]; // rate moved after display
$q=SA_Paystack_Charge_Quote::lock_for_order($o,'90001_1');
ck($q!==null && $q['charge_amount']===12950.0 && $q['rate']===129.5);
SA_FX_Rates::$rates=['KES'=>129.50];

// 8. Pay-for-order page: USD paystack order sees and locks the exact charge; KES and other methods untouched.
$GLOBALS['endpoint']='order-pay'; $GLOBALS['qv']=['order-pay'=>7];
$GLOBALS['orders'][7]=new MOrder('USD',110.48);
ob_start(); do_action('woocommerce_pay_order_before_payment'); $out=ob_get_clean();
ck(strpos($out,'KES 14,307.16')!==false && strpos($out,'129.5')!==false);
$d=$GLOBALS['orders'][7]->meta[SA_PAYSTACK_DISPLAY_META_KEY]??null;
ck(is_array($d) && $d['charge_amount']===14307.16 && $GLOBALS['orders'][7]->saved===1);
$GLOBALS['orders'][8]=new MOrder('KES',500.0); $GLOBALS['qv']=['order-pay'=>8];
ob_start(); do_action('woocommerce_pay_order_before_payment'); $out=ob_get_clean();
ck($out==='' && !isset($GLOBALS['orders'][8]->meta[SA_PAYSTACK_DISPLAY_META_KEY]));
$GLOBALS['orders'][9]=new MOrder('USD',50.0,'pesapal'); $GLOBALS['qv']=['order-pay'=>9];
ob_start(); do_action('woocommerce_pay_order_before_payment'); $out=ob_get_clean();
ck($out==='');

// 9. Availability: credential/testmode gates, 2500 cap, order-pay method guard.
$GLOBALS['endpoint']=''; WC()->cart->total=100.0;
$g=['paystack'=>(object)['testmode'=>false]];
$out=apply_filters('woocommerce_available_payment_gateways',$g); ck(isset($out['paystack']));
WC()->cart->total=2600.0; $out=apply_filters('woocommerce_available_payment_gateways',$g); ck(!isset($out['paystack']));
WC()->cart->total=100.0;
$GLOBALS['endpoint']='order-pay'; $GLOBALS['qv']=['order-pay'=>9];
$out=apply_filters('woocommerce_available_payment_gateways',$g); ck(!isset($out['paystack'])); // pesapal order
$GLOBALS['qv']=['order-pay'=>7];
$out=apply_filters('woocommerce_available_payment_gateways',$g); ck(isset($out['paystack']));  // paystack USD order now offered
$GLOBALS['endpoint']='';

// 10. Order-pay guards combine: rate, testmode, currency support and the order-aware cap all still apply.
$GLOBALS['endpoint']='order-pay';
$g=['paystack'=>(object)['testmode'=>false]];
$GLOBALS['orders'][10]=new MOrder('USD',2600.0); $GLOBALS['qv']=['order-pay'=>10];
$out=apply_filters('woocommerce_available_payment_gateways',$g); ck(!isset($out['paystack'])); // order over the USD 2500 cap
$GLOBALS['orders'][11]=new MOrder('EUR',100.0); $GLOBALS['qv']=['order-pay'=>11];
$out=apply_filters('woocommerce_available_payment_gateways',$g); ck(!isset($out['paystack'])); // unsupported currency fails closed
$GLOBALS['orders'][12]=new MOrder('KES',500.0); $GLOBALS['qv']=['order-pay'=>12];
$out=apply_filters('woocommerce_available_payment_gateways',$g); ck(isset($out['paystack']));  // KES same-currency order within cap
$GLOBALS['orders'][13]=new MOrder('KES',400000.0); $GLOBALS['qv']=['order-pay'=>13];
$out=apply_filters('woocommerce_available_payment_gateways',$g); ck(!isset($out['paystack'])); // KES equivalent over the cap
SA_FX_Rates::$rates=[]; $GLOBALS['qv']=['order-pay'=>7];
$out=apply_filters('woocommerce_available_payment_gateways',$g); ck(!isset($out['paystack'])); // rate guard still applies on order-pay
SA_FX_Rates::$rates=['KES'=>129.50];
$g2=['paystack'=>(object)['testmode'=>true]];
$out=apply_filters('woocommerce_available_payment_gateways',$g2); ck(isset($out['paystack'])); // admin sees testmode (mock can manage)

// 11. KES order on order-pay: no conversion notice, description untouched, never multiplied by the rate.
$GLOBALS['qv']=['order-pay'=>12];
ob_start(); do_action('woocommerce_pay_order_before_payment'); $out=ob_get_clean();
ck($out==='');
$desc=apply_filters('woocommerce_gateway_description','orig','paystack'); ck(strpos($desc,'no currency conversion applies')!==false);
ck(!isset($GLOBALS['orders'][12]->meta[SA_PAYSTACK_DISPLAY_META_KEY]));

// 12. EUR order on order-pay: honest unavailability, no quote, no USD wording.
$GLOBALS['qv']=['order-pay'=>11];
ob_start(); do_action('woocommerce_pay_order_before_payment'); $out=ob_get_clean();
ck(strpos($out,'unavailable for this order')!==false && strpos($out,'US dollars')===false);
$desc=apply_filters('woocommerce_gateway_description','orig','paystack');
ck(strpos($desc,'unavailable for this order')!==false);
ck(!isset($GLOBALS['orders'][11]->meta[SA_PAYSTACK_DISPLAY_META_KEY]));

// 13. Two tabs, one figure: the first displayed quote is reused; a later rate move shows and charges the same figure.
$GLOBALS['orders'][14]=new MOrder('USD',100.0); $GLOBALS['qv']=['order-pay'=>14];
ob_start(); do_action('woocommerce_pay_order_before_payment'); $out1=ob_get_clean();
ck(strpos($out1,'KES 12,950.00')!==false);
SA_FX_Rates::$rates=['KES'=>200.0]; // rate moves before the second tab renders
ob_start(); do_action('woocommerce_pay_order_before_payment'); $out2=ob_get_clean();
ck($out2===$out1); // same figure, not the new rate
$desc=apply_filters('woocommerce_gateway_description','orig','paystack');
ck(strpos($desc,'KES 12,950.00')!==false && strpos($desc,'129.5')!==false); // description reuses the locked quote
$_POST['sa_paystack_quote_token']=$GLOBALS['sa_paystack_rendered_token'];
$q=SA_Paystack_Charge_Quote::lock_for_order($GLOBALS['orders'][14],'90014_1');
ck($q!==null && $q['charge_amount']===12950.0 && $q['rate']===129.5); // charge equals the displayed figure
SA_FX_Rates::$rates=['KES'=>129.50];

// 14. Order edited after display: the page re-quotes and re-displays the new figure before any charge.
$GLOBALS['orders'][14]->total=120.0;
ob_start(); do_action('woocommerce_pay_order_before_payment'); $out3=ob_get_clean();
ck(strpos($out3,'KES 15,540.00')!==false);
apply_filters('woocommerce_gateway_description','orig','paystack'); // 120 x 129.5 shown fresh
$_POST['sa_paystack_quote_token']=$GLOBALS['sa_paystack_rendered_token'];
$q=SA_Paystack_Charge_Quote::lock_for_order($GLOBALS['orders'][14],'90014_2');
ck($q!==null && $q['charge_amount']===15540.0); // only the re-displayed figure can be charged

// 15. No fresh display quote: converted payment fails closed even when a rate exists.
$GLOBALS['orders'][15]=new MOrder('USD',75.0);
ck(SA_Paystack_Charge_Quote::lock_for_order($GLOBALS['orders'][15],'90015_1')===null);

$GLOBALS['endpoint']='';
echo $AC." display-quote/lock integration checks PASS (mock Woo, no network)\n";
