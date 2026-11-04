<?php
define('ABSPATH',1);function is_user_logged_in(){return false;}function __($s,$d=''){return $s;}
class WC_Order {public $currency,$gateway,$total,$meta=[];function add_item($x){}function set_currency($x){$this->currency=$x;}function update_meta_data($k,$v){$this->meta[$k]=$v;}function set_billing_email($x){}function set_billing_country($x){}function set_payment_method($x){$this->gateway=$x;}function set_payment_method_title($x){}function calculate_totals($x){}function set_total($x){$this->total=$x;}function save(){}function add_order_note(...$a){}}
class WC_Order_Item_Fee{function set_name($x){}function set_total($x){}function set_tax_status($x){}}
function wc_create_order($a){$GLOBALS['created']++;return new WC_Order;}
include __DIR__.'/../wp-content/plugins/whop-payments/includes/class-whop-open-pay.php';
$GLOBALS['created']=0;
$o=Whop_Open_Pay::create_pending_order(110.48,'fixture@example.invalid','',[],'KES','paystack');assert($o->gateway==='paystack' && $o->currency==='KES' && $o->total===110.48);
$o=Whop_Open_Pay::create_pending_order(25,'fixture@example.invalid','',[],'USD');assert($o->gateway==='pesapal' && $o->currency==='USD' && $o->total===25.0);
$n=$GLOBALS['created'];assert(Whop_Open_Pay::create_pending_order(25,'fixture@example.invalid','',[],'USD','paystack')===null);assert($GLOBALS['created']===$n);
assert(Whop_Open_Pay::create_pending_order(25,'fixture@example.invalid','',[],'KES','invalid')===null);assert($GLOBALS['created']===$n);
echo "4 open-pay gateway/currency checks PASS (mock orders only)\n";
