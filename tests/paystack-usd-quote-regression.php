<?php
// Supreme USD->KES server-locked charge quote regression checks (mock orders only, no network).
define('ABSPATH',1);
function apply_filters($tag,$value){return $value;}
function __($s,$d=''){return $s;}
class SA_FX_Rates { public static $rates=['KES'=>129.50];
    public static function rate_for($c){return self::$rates[$c]??null;}
    public static function decimals_for($c){return 2;} }
class QOrder { public $currency,$total,$meta=[];
    function __construct($currency,$total){$this->currency=$currency;$this->total=$total;}
    function get_currency(){return $this->currency;}
    function get_total(){return $this->total;}
    function get_meta($k){return $this->meta[$k]??'';}
    function update_meta_data($k,$v){$this->meta[$k]=$v;} }
require __DIR__.'/../wp-content/plugins/woo-paystack/includes/class-sa-paystack-charge-quote.php';
use SA_Paystack_Charge_Quote as Q;

// 1. KES order: charge equals order figures, rate 1, no conversion quote.
$o=new QOrder('KES',110.48); $q=Q::lock_for_order($o,'76318_1');
assert($q['charge_currency']==='KES' && $q['charge_amount']===110.48 && $q['rate']===1.0 && $q['order_currency']==='KES');
assert(Q::matches_transaction($o,'76318_1',11048,'KES')===true);
assert(Q::matches_transaction($o,'76318_1',11048,'USD')===false);
assert(Q::matches_transaction($o,'76318_1',11049,'KES')===false);
$e=Q::expected_charge($o,'76318_1'); assert($e['quote']===null && $e['currency']==='KES' && $e['amount']===110.48);

// 2. USD order: exact locked KES quote at 129.50, minor units integer.
$o=new QOrder('USD',100.00); $q=Q::lock_for_order($o,'80001_2');
assert($q['order_currency']==='USD' && $q['charge_currency']==='KES' && $q['charge_amount']===12950.00 && $q['rate']===129.50);
assert(Q::minor($q['charge_amount'])===1295000);
assert(Q::matches_transaction($o,'80001_2',1295000,'KES')===true);
assert(Q::matches_transaction($o,'80001_2',1295000,'kes')===true);
assert(Q::matches_transaction($o,'80001_2',10000,'USD')===false);
assert(Q::matches_transaction($o,'80001_2',1295001,'KES')===false);
assert(Q::matches_transaction($o,'80001_2',1294999,'KES')===false);
$e=Q::expected_charge($o,'80001_2'); assert($e['quote']!==null && $e['currency']==='KES' && $e['amount']===12950.00);

// 3. Rounding follows settlement-currency decimals.
$o=new QOrder('USD',9.53); SA_FX_Rates::$rates=['KES'=>129.505]; $q=Q::build_quote($o,'80002_1');
assert($q['charge_amount']===1234.18); SA_FX_Rates::$rates=['KES'=>129.50];

// 4. No rate: fail closed, never relabel.
SA_FX_Rates::$rates=[]; $o=new QOrder('USD',25.00);
assert(Q::build_quote($o,'80003_1')===null); assert(Q::lock_for_order($o,'80003_1')===null);
assert(Q::matches_transaction($o,'80003_1',3238,'KES')===false);   // no quote => KES cannot match a USD order
assert(Q::matches_transaction($o,'80003_1',2500,'USD')===true);    // order's own figures still match exactly
SA_FX_Rates::$rates=['KES'=>129.50];

// 5. Order edited after locking: quote no longer describes it, fall back and reject converted figures.
$o=new QOrder('USD',100.00); Q::lock_for_order($o,'80004_1');
$o->total=120.00;
assert(Q::matches_transaction($o,'80004_1',1295000,'KES')===false);
assert(Q::matches_transaction($o,'80004_1',12000,'USD')===true);

// 6. Unknown reference never matches converted figures.
$o=new QOrder('USD',100.00); Q::lock_for_order($o,'80005_1');
assert(Q::matches_transaction($o,'99999_9',1295000,'KES')===false);

// 7. Per-reference isolation and pruning to the most recent quotes.
$o=new QOrder('USD',10.00);
for($i=1;$i<=7;$i++){ Q::lock_for_order($o,'80006_'.$i); }
$kept=$o->meta[SA_Paystack_Charge_Quote::META_KEY]; assert(count($kept)===Q::MAX_KEPT);
assert(isset($kept['80006_7']) && isset($kept['80006_3']) && !isset($kept['80006_1']) && !isset($kept['80006_2']));

// 8. Gateway receipt capture accepts the locked quote (extracted method, same style as card-display test).
$source=file_get_contents(__DIR__.'/../wp-content/plugins/woo-paystack/includes/class-wc-gateway-paystack.php');
$a=strpos($source,'    public function store_receipt_payment_details');$b=strpos($source,"\n\t/**\n\t * Save Customer Card Details.",$a);
eval('class RGateway {'.substr($source,$a,$b-$a).'}');
function response($changes=[]){return (object)['data'=>(object)array_replace(['status'=>'success','reference'=>'80007_1','amount'=>1295000,'currency'=>'KES','channel'=>'card','authorization'=>(object)['last4'=>'8575','brand'=>'visa']],$changes)];}
$o=new QOrder('USD',100.00); $o->update_meta_data('_paystack_txn_ref','80007_1'); Q::lock_for_order($o,'80007_1');
$g=new RGateway; $g->store_receipt_payment_details($o,response());
assert(($o->meta['_sa_paystack_card_brand']??'')==='Visa' && ($o->meta['_sa_paystack_card_last4']??'')==='8575');
// Same USD order without a locked quote must reject the KES figures.
$o=new QOrder('USD',100.00); $o->update_meta_data('_paystack_txn_ref','80007_1');
$g->store_receipt_payment_details($o,response());
assert(!isset($o->meta['_sa_paystack_card_last4']));
// Converted order still rejects wrong amount/currency/channel.
foreach([['amount'=>1295001],['currency'=>'USD'],['channel'=>'mobile_money']] as $bad){
    $o=new QOrder('USD',100.00); $o->update_meta_data('_paystack_txn_ref','80007_1'); Q::lock_for_order($o,'80007_1');
    $g->store_receipt_payment_details($o,response($bad)); assert(!isset($o->meta['_sa_paystack_card_last4'])); }

// 9. Fail-closed transaction verifier accepts only the locked quote for the reference.
$a=strpos($source,"\tprivate function get_paystack_transaction(");$b=strpos($source,"\n\t/**\n\t * Get Paystack payment icon URL.",$a);
eval('class VGateway { public $testmode=false; public $secret_key="sk_test_fixture"; '.str_replace('private function get_paystack_transaction','public function get_paystack_transaction',substr($source,$a,$b-$a)).'}');
$GLOBALS['verify_order']=null; $GLOBALS['verify_body']=null;
function wc_get_order($id){return $GLOBALS['verify_order'];}
function is_wp_error($r){return false;}
function wp_remote_get($url,$args=[]){return ['ok'=>true];}
function wp_remote_retrieve_response_code($r){return 200;}
function wp_remote_retrieve_body($r){return $GLOBALS['verify_body'];}
$v=new VGateway;
$o=new QOrder('USD',100.00); $o->update_meta_data('_paystack_txn_ref','80008_1'); $o->update_meta_data('payment_method','paystack');
Q::lock_for_order($o,'80008_1');
$o->gateway='paystack'; $GLOBALS['verify_order']=new class($o) { private $o; function __construct($o){$this->o=$o;}
    function get_payment_method(){return 'paystack';} function get_meta($k){return $this->o->get_meta($k);}
    function get_currency(){return $this->o->get_currency();} function get_total(){return $this->o->get_total();} };
$GLOBALS['verify_body']=json_encode(['status'=>true,'data'=>['status'=>'success','reference'=>'80008_1','currency'=>'KES','amount'=>1295000,'domain'=>'live']]);
assert($v->get_paystack_transaction('80008_1')!==false);   // locked quote matches
$GLOBALS['verify_body']=json_encode(['status'=>true,'data'=>['status'=>'success','reference'=>'80008_1','currency'=>'KES','amount'=>1295001,'domain'=>'live']]);
assert($v->get_paystack_transaction('80008_1')===false);   // tampered amount
$GLOBALS['verify_body']=json_encode(['status'=>true,'data'=>['status'=>'success','reference'=>'80008_1','currency'=>'USD','amount'=>10000,'domain'=>'live']]);
assert($v->get_paystack_transaction('80008_1')===false);   // quote locked to KES, USD no longer matches
$GLOBALS['verify_body']=json_encode(['status'=>true,'data'=>['status'=>'success','reference'=>'80008_1','currency'=>'KES','amount'=>1295000,'domain'=>'test']]);
assert($v->get_paystack_transaction('80008_1')===false);   // wrong domain
// Same-currency order behaves as before.
$o2=new QOrder('KES',110.48); $o2->update_meta_data('_paystack_txn_ref','76318_1');
$GLOBALS['verify_order']=new class($o2) { private $o; function __construct($o){$this->o=$o;}
    function get_payment_method(){return 'paystack';} function get_meta($k){return $this->o->get_meta($k);}
    function get_currency(){return $this->o->get_currency();} function get_total(){return $this->o->get_total();} };
$GLOBALS['verify_body']=json_encode(['status'=>true,'data'=>['status'=>'success','reference'=>'76318_1','currency'=>'KES','amount'=>11048,'domain'=>'live']]);
assert($v->get_paystack_transaction('76318_1')!==false);
$GLOBALS['verify_body']=json_encode(['status'=>true,'data'=>['status'=>'success','reference'=>'76318_1','currency'=>'KES','amount'=>11049,'domain'=>'live']]);
assert($v->get_paystack_transaction('76318_1')===false);


// 10. Customer-visible display quote is reused at init even after the rate table moves (drift fix).
$o=new QOrder('USD',100.00);
$o->update_meta_data(Q::DISPLAY_META_KEY,['order_currency'=>'USD','order_total'=>100.00,'charge_currency'=>'KES','charge_amount'=>12950.00,'rate'=>129.50,'quoted_at'=>time()]);
SA_FX_Rates::$rates=['KES'=>200.00];
$q=Q::lock_for_order($o,'80010_1');
assert($q!==null && $q['rate']===129.50 && $q['charge_amount']===12950.00);
assert(Q::matches_transaction($o,'80010_1',1295000,'KES')===true);

// 11. Fresh display quote whose stored charge disagrees with its own rate: fail closed.
$o=new QOrder('USD',100.00);
$o->update_meta_data(Q::DISPLAY_META_KEY,['order_currency'=>'USD','order_total'=>100.00,'charge_currency'=>'KES','charge_amount'=>12900.00,'rate'=>129.50,'quoted_at'=>time()]);
assert(Q::lock_for_order($o,'80011_1')===null);

// 12. Stale display quote with a moved rate: stop and re-display instead of silently charging the new figure.
$o=new QOrder('USD',100.00);
$o->update_meta_data(Q::DISPLAY_META_KEY,['order_currency'=>'USD','order_total'=>100.00,'charge_currency'=>'KES','charge_amount'=>12950.00,'rate'=>129.50,'quoted_at'=>time()-3600]);
assert(Q::lock_for_order($o,'80012_1')===null);

// 13. Stale display quote but unchanged rate: same figure, proceed.
SA_FX_Rates::$rates=['KES'=>129.50];
$o=new QOrder('USD',100.00);
$o->update_meta_data(Q::DISPLAY_META_KEY,['order_currency'=>'USD','order_total'=>100.00,'charge_currency'=>'KES','charge_amount'=>12950.00,'rate'=>129.50,'quoted_at'=>time()-3600]);
$q=Q::lock_for_order($o,'80013_1'); assert($q!==null && $q['charge_amount']===12950.00);

// 14. Display quote for an older total (order edited): fresh quote for the new total proceeds.
SA_FX_Rates::$rates=['KES'=>200.00];
$o=new QOrder('USD',120.00);
$o->update_meta_data(Q::DISPLAY_META_KEY,['order_currency'=>'USD','order_total'=>100.00,'charge_currency'=>'KES','charge_amount'=>12950.00,'rate'=>129.50,'quoted_at'=>time()]);
$q=Q::lock_for_order($o,'80014_1'); assert($q!==null && $q['charge_amount']===24000.00 && $q['rate']===200.00);
SA_FX_Rates::$rates=['KES'=>129.50];

echo "43 USD->KES locked-quote checks PASS (mock orders only, no network)\n";
