<?php
remove_filter('woocommerce_email_enabled_customer_processing_order','__return_false',10);
$GLOBALS['calls']=0;add_filter('pre_wp_mail',static function(){++$GLOBALS['calls'];return true;},PHP_INT_MAX);
add_filter('query',static function($q){return stripos($q,'SELECT GET_LOCK(')===0||stripos($q,'SELECT RELEASE_LOCK(')===0?'SELECT 1':$q;});
$deny=static function(){return false;};
foreach(['unchanged','status','paiddate','recipient','billing','shipping','currency','total','line','fee','tax','shippingmethod'] as $kind){
 add_filter('woocommerce_email_enabled_customer_processing_order',$deny,999);
 $o=wc_create_order();$o->set_billing_email('synthetic-fullstate@example.invalid');$o->add_product(wc_get_product(10),1);$o->calculate_totals();$o->set_date_paid(time());$o->set_status('processing');$o->save();
 remove_filter('woocommerce_email_enabled_customer_processing_order',$deny,999);
 $stale=wc_get_order($o->get_id());sa_core_confirmation_snapshot($stale); // prime all item arrays
 $fresh=wc_get_order($o->get_id());
 switch($kind){
 case 'status':$fresh->set_status('cancelled');break;
 case 'paiddate':$fresh->set_date_paid(time()-100);break;
 case 'recipient':$fresh->set_billing_email('new@example.invalid');break;
 case 'billing':$fresh->set_billing_address_1('changed');break;
 case 'shipping':$fresh->set_shipping_address_1('changed');break;
 case 'currency':$fresh->set_currency('KES');break;
 case 'total':$fresh->set_total(101);break;
 case 'line':foreach($fresh->get_items() as $i){$i->set_name('changed');$i->save();}break;
 case 'fee':$i=new WC_Order_Item_Fee();$i->set_name('Fee');$i->set_total(1);$fresh->add_item($i);break;
 case 'tax':$i=new WC_Order_Item_Tax();$i->set_label('ChangedTax');$i->set_tax_total(1);$fresh->add_item($i);break;
 case 'shippingmethod':$i=new WC_Order_Item_Shipping();$i->set_method_title('Changed courier');$i->set_total(1);$fresh->add_item($i);break;
 }
 // Avoid automatic send from changed save.
 add_filter('woocommerce_email_enabled_customer_processing_order',$deny,999);$fresh->save();remove_filter('woocommerce_email_enabled_customer_processing_order',$deny,999);
 $GLOBALS['calls']=0;$r=sa_core_ensure_customer_order_email($stale);$expected=$kind==='unchanged';echo json_encode(['case'=>$kind,'result'=>$r,'transport_calls'=>$GLOBALS['calls'],'pass'=>$r===$expected && $GLOBALS['calls']===($expected?1:0)])."\n";
}
