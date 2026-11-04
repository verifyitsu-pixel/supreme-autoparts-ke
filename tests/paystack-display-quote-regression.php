<?php
// Supreme Paystack display/lock integration: mu-plugin + quote helper, mock Woo, no network.
define('ABSPATH',1);
$hooks=[];
function add_filter($tag,$fn,$p=10,$a=1){global $hooks;$hooks[$tag][]=$fn;}
function add_action($tag,$fn,$p=10,$a=1){add_filter($tag,$fn,$p,$a);}
function apply_filters($tag,$v,...$args){global $hooks;foreach($hooks[$tag]??[] as $fn)$v=$fn($v,...$args);return $v;}
function do_action($tag,...$args){global $hooks;foreach($hooks[$tag]??[] as $fn)$fn(...$args);}
function __($s,$d=''){return $s;}
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
class Session { public $d=[]; function set($k,$v){$this->d[$k]=$v;} function get($k){return $this->d[$k]??null;} }
class Cart { public $total=100.0; function get_total($m=''){return $this->total;} }
class WCStub { public $session,$cart; function __construct(){ $this->session=new Session; $this->cart=new Cart; } }
$GLOBALS['wc']=new WCStub;
function WC(){return $GLOBALS['wc'];}
class MOrder { public $currency,$total,$method,$meta=[],$saved=0;
    function __construct($currency,$total,$method='paystack'){$this->currency=$currency;$this->total=$total;$this->method=$method;}
    function get_currency(){return $this->currency;} function get_total(){return $this->total;}
    function get_payment_method(){return $this->method;}
    function get_meta($k){return $this->meta[$k]??'';} function update_meta_data($k,$v){$this->meta[$k]=$v;}
    function save(){$this->saved++;} }
$GLOBALS['orders']=[];
function wc_get_order($id){return $GLOBALS['orders'][$id]??null;}
function is_wc_endpoint_url($e){return ($GLOBALS['endpoint']??'')===$e;}
function get_query_var($k){return $GLOBALS['qv'][$k]??0;}

require __DIR__.'/../wp-content/plugins/woo-paystack/includes/class-sa-paystack-charge-quote.php';
require __DIR__.'/../wp-content/mu-plugins/sa-paystack.php';

// Meta key contract between mu-plugin and gateway helper.
assert(SA_PAYSTACK_DISPLAY_META_KEY===SA_Paystack_Charge_Quote::DISPLAY_META_KEY);

// 1. Checkout description shows the exact charge and rate, and stores the session quote.
$desc=apply_filters('woocommerce_gateway_description','orig','paystack');
assert(strpos($desc,'KES 12,950.00')!==false && strpos($desc,'129.5')!==false);
$sq=WC()->session->get('sa_paystack_quote'); assert(is_array($sq) && $sq['kes']===12950.0 && $sq['rate']===129.5 && $sq['usd']===100.0);

// 2. No rate: honest unavailability, no session quote.
SA_FX_Rates::$rates=[];
WC()->session->set('sa_paystack_quote',null);
$desc=apply_filters('woocommerce_gateway_description','orig','paystack');
assert(strpos($desc,'temporarily unavailable')!==false);
SA_FX_Rates::$rates=['KES'=>129.50];

// 3. Other gateways and descriptions untouched.
assert(apply_filters('woocommerce_gateway_description','orig','pesapal')==='orig');

// 4. Order creation locks the visible quote; order stays USD with untouched totals.
WC()->session->set('sa_paystack_quote',['usd'=>100.0,'rate'=>129.5,'kes'=>12950.0,'at'=>time()]);
$o=new MOrder('USD',100.0);
do_action('woocommerce_checkout_create_order',$o,['payment_method'=>'paystack']);
$d=$o->meta[SA_PAYSTACK_DISPLAY_META_KEY]??null;
assert(is_array($d) && $d['charge_amount']===12950.0 && $d['rate']===129.5 && $d['order_currency']==='USD' && $d['order_total']===100.0);
assert($o->currency==='USD' && $o->total===100.0);

// 5. Other payment methods and KES orders pass through untouched.
$o=new MOrder('USD',100.0,'pesapal'); do_action('woocommerce_checkout_create_order',$o,['payment_method'=>'pesapal']);
assert(!isset($o->meta[SA_PAYSTACK_DISPLAY_META_KEY]));
$o=new MOrder('KES',500.0); do_action('woocommerce_checkout_create_order',$o,['payment_method'=>'paystack']);
assert(!isset($o->meta[SA_PAYSTACK_DISPLAY_META_KEY]));

// 6. Over-2500 orders and stale/mismatched session quotes fail closed.
foreach([['USD',2500.01],] as $c){ $o=new MOrder($c[0],$c[1]);
    try{ do_action('woocommerce_checkout_create_order',$o,['payment_method'=>'paystack']); assert(false,'2500 cap must throw'); }catch(Exception $e){} }
WC()->cart->total=100.0; WC()->session->set('sa_paystack_quote',['usd'=>99.0,'rate'=>129.5,'kes'=>12820.5,'at'=>time()]);
$o=new MOrder('USD',100.0);
try{ do_action('woocommerce_checkout_create_order',$o,['payment_method'=>'paystack']); assert(false,'mismatched quote must throw'); }catch(Exception $e){}

// 7. The locked display quote drives the gateway charge figure exactly.
$o=new MOrder('USD',100.0); WC()->session->set('sa_paystack_quote',['usd'=>100.0,'rate'=>129.5,'kes'=>12950.0,'at'=>time()]);
do_action('woocommerce_checkout_create_order',$o,['payment_method'=>'paystack']);
SA_FX_Rates::$rates=['KES'=>140.0]; // rate moved after display
$q=SA_Paystack_Charge_Quote::lock_for_order($o,'90001_1');
assert($q!==null && $q['charge_amount']===12950.0 && $q['rate']===129.5);
SA_FX_Rates::$rates=['KES'=>129.50];

// 8. Pay-for-order page: USD paystack order sees and locks the exact charge; KES and other methods untouched.
$GLOBALS['endpoint']='order-pay'; $GLOBALS['qv']=['order-pay'=>7];
$GLOBALS['orders'][7]=new MOrder('USD',110.48);
ob_start(); do_action('woocommerce_pay_order_before_payment'); $out=ob_get_clean();
assert(strpos($out,'KES 14,307.16')!==false && strpos($out,'129.5')!==false);
$d=$GLOBALS['orders'][7]->meta[SA_PAYSTACK_DISPLAY_META_KEY]??null;
assert(is_array($d) && $d['charge_amount']===14307.16 && $GLOBALS['orders'][7]->saved===1);
$GLOBALS['orders'][8]=new MOrder('KES',500.0); $GLOBALS['qv']=['order-pay'=>8];
ob_start(); do_action('woocommerce_pay_order_before_payment'); $out=ob_get_clean();
assert($out==='' && !isset($GLOBALS['orders'][8]->meta[SA_PAYSTACK_DISPLAY_META_KEY]));
$GLOBALS['orders'][9]=new MOrder('USD',50.0,'pesapal'); $GLOBALS['qv']=['order-pay'=>9];
ob_start(); do_action('woocommerce_pay_order_before_payment'); $out=ob_get_clean();
assert($out==='');

// 9. Availability: credential/testmode gates, 2500 cap, order-pay method guard.
$GLOBALS['endpoint']=''; WC()->cart->total=100.0;
$g=['paystack'=>(object)['testmode'=>false]];
$out=apply_filters('woocommerce_available_payment_gateways',$g); assert(isset($out['paystack']));
WC()->cart->total=2600.0; $out=apply_filters('woocommerce_available_payment_gateways',$g); assert(!isset($out['paystack']));
WC()->cart->total=100.0;
$GLOBALS['endpoint']='order-pay'; $GLOBALS['qv']=['order-pay'=>9];
$out=apply_filters('woocommerce_available_payment_gateways',$g); assert(!isset($out['paystack'])); // pesapal order
$GLOBALS['qv']=['order-pay'=>7];
$out=apply_filters('woocommerce_available_payment_gateways',$g); assert(isset($out['paystack']));  // paystack USD order now offered
$GLOBALS['endpoint']='';

echo "24 display-quote/lock integration checks PASS (mock Woo, no network)\n";
