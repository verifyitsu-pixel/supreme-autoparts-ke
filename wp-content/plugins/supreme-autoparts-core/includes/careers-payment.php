<?php
/** Careers application fee. Application/CV stays in Forminator; Paystack receives a fee order only. */
declare(strict_types=1);
defined('ABSPATH') || exit;
function sa_careers_fee_disclosure(): string {
    return 'Application fee: KES 1,000. After saving your application, continue to Paystack checkout. Payment does not guarantee an interview or employment.';
}
function sa_careers_fee_order(string $source, int $entry_id, string $name, string $email) {
    if (!in_array($source,['forminator','native'],true) || $entry_id < 1 || !is_email($email) || !function_exists('wc_create_order')) return new WP_Error('unavailable','Your application is saved, but payment is unavailable. Please contact us before applying again.');
    $key='sa_career_fee_'.$source.'_'.$entry_id;
    $existing=(int)get_option($key,0);
    if($existing){$o=wc_get_order($existing);return $o && $o->get_meta('_sa_careers_source')===$source && (int)$o->get_meta('_sa_careers_entry')===$entry_id ? $o : new WP_Error('binding','Application payment reference needs review.');}
    if(!add_option($key.'_lock',time(),'','no')) return new WP_Error('busy','Your application is saved. Payment is being prepared; please wait before trying again.');
    try {
        $o=wc_create_order(['status'=>'pending','customer_id'=>0,'created_via'=>'sa_careers_fee']);
        if(is_wp_error($o))return $o;
        $item=new WC_Order_Item_Fee();$item->set_name('Job application fee');$item->set_total(1000);$item->set_tax_status('none');$o->add_item($item);
        $o->set_currency('KES');$o->set_billing_email($email);$o->set_billing_first_name($name);$o->set_billing_country('KE');$o->set_payment_method('paystack');$o->set_payment_method_title('Paystack (KES)');
        $o->update_meta_data('_sa_careers_source',$source);$o->update_meta_data('_sa_careers_entry',$entry_id);$o->update_meta_data('_sa_careers_fee_disclosure',sa_careers_fee_disclosure());$o->update_meta_data('_sa_native_currency','KES');
        $o->calculate_totals(false);$o->set_total(1000);$o->save();update_option($key,$o->get_id(),false);return $o;
    } catch(Throwable $e){return new WP_Error('payment','Your application is saved, but payment could not be prepared. Please contact us before applying again.');}
    finally{delete_option($key.'_lock');}
}
// Installed Forminator 1.44.2 does not include ordinary entry IDs in success responses.
// Capture only the server-created entry from this request, never a client-posted ID.
add_action('forminator_custom_form_submit_before_set_fields',static function($entry,$form_id,$fields){
    if(function_exists('sa_ff_id') && (int)$form_id===sa_ff_id('careers') && (int)$entry->entry_id>0 && empty($entry->is_spam))$GLOBALS['sa_careers_saved_entry']=(int)$entry->entry_id;
},10,3);
function sa_careers_form_response(array $response,int $form_id): array {
    if(!function_exists('sa_ff_id') || $form_id!==sa_ff_id('careers') || empty($response['success']) || empty($GLOBALS['sa_careers_saved_entry']) || !class_exists('Forminator_Form_Entry_Model'))return $response;
    $e=new Forminator_Form_Entry_Model();$e->get((int)$GLOBALS['sa_careers_saved_entry']);
    if((int)$e->form_id!==$form_id || !empty($e->is_spam) || in_array((string)($e->status??'active'),['spam','draft','abandoned'],true))return $response;
    $o=sa_careers_fee_order('forminator',(int)$e->entry_id,sanitize_text_field((string)$e->get_meta('name-1','')),sanitize_email((string)$e->get_meta('email-1','')));
    if(is_wp_error($o)){$response['success']=false;$response['message']=$o->get_error_message();unset($response['url'],$response['redirect']);return $response;}
    $response['url']=$o->get_checkout_payment_url();$response['redirect']=true;$response['newtab']='sametab';$response['behav']='behaviour-redirect';$response['message']='Application saved. Continue to Paystack to pay the KES 1,000 application fee.';return $response;
}
add_filter('forminator_form_ajax_submit_response','sa_careers_form_response',30,2);
add_filter('forminator_form_submit_response','sa_careers_form_response',30,2);
add_filter('woocommerce_available_payment_gateways',static function($gateways){
    if(!function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('order-pay'))return $gateways;
    $o=wc_get_order(absint(get_query_var('order-pay')));if(!$o || !$o->get_meta('_sa_careers_source'))return $gateways;
    return isset($gateways['paystack']) ? ['paystack'=>$gateways['paystack']] : [];
},999);
add_action('woocommerce_payment_complete',static function($id){
    $o=wc_get_order($id);if(!$o || !$o->is_paid() || !$o->get_meta('_sa_careers_source') || $o->get_currency()!=='KES' || (float)$o->get_total()!==1000.0)return;
    $o->update_meta_data('_sa_careers_fee_paid_at',gmdate('c'));$o->save();
});

// Fixed-fee orders never use the catalogue/cart FX quote in gateway copy.
add_filter('woocommerce_gateway_description',static function($description,$id){
    if($id!=='paystack' || !function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('order-pay'))return $description;
    $o=wc_get_order(absint(get_query_var('order-pay')));return $o && $o->get_meta('_sa_careers_source') ? 'Pay the KES 1,000 application fee securely with Paystack. Payment does not guarantee an interview or employment.' : $description;
},999,2);

add_action('woocommerce_admin_order_data_after_order_details',static function($o){
    if(!$o->get_meta('_sa_careers_source'))return;
    echo '<p><strong>Careers fee application:</strong> '.esc_html($o->get_meta('_sa_careers_source').' #'.$o->get_meta('_sa_careers_entry')).'<br>Payment: '.esc_html($o->get_meta('_sa_careers_fee_paid_at') ? 'Verified paid '.$o->get_meta('_sa_careers_fee_paid_at') : 'Not verified paid').'</p>';
});

function sa_careers_fee_email_content($o,string $stage): array {
    $copy=[
        'on-hold'=>['Application fee payment pending','We are waiting for your application fee payment to be confirmed. This is not a payment receipt.'],
        'failed'=>['Application fee payment not confirmed','We do not have a successful payment confirmation. If your bank shows a debit, contact us before trying again.'],
        'cancelled'=>['Application fee payment cancelled','This application fee payment order has been cancelled.'],
        'refunded'=>['Application fee refund update','A refund is recorded on your payment order. This does not confirm that your bank has credited the money.'],
        'completed'=>['Application fee payment received','Your KES 1,000 application fee payment is recorded as complete. Payment does not guarantee an interview or employment.'],
    ];
    if(!isset($copy[$stage]))return [];
    [$heading,$body]=$copy[$stage];$subject=$heading.' #'.$o->get_order_number();
    $url=$stage==='failed'?$o->get_checkout_payment_url():$o->get_checkout_order_received_url();
    return ['subject'=>$subject,'html'=>sa_customer_mail_layout($heading,'<p>'.sa_email_greeting($o).'</p><p>'.esc_html($body).'</p><p>Reference: '.esc_html($o->get_order_number()).'</p>', $stage==='failed'?'Continue to payment':'View payment order',$url,$subject,'','KE')];
}
add_filter('woocommerce_email_subject_customer_processing_order',static function($subject,$order){return $order && $order->get_meta('_sa_careers_source') ? 'Application fee payment received #'.$order->get_order_number() : $subject;},999,2);

// Keep Woo order-key/nonce/payment processing intact; only the fee presentation is distinct.
add_filter('wc_get_template',static function($template,$name,$args){
    if($name!=='checkout/form-pay.php' || empty($args['order']) || !$args['order']->get_meta('_sa_careers_source'))return $template;
    return SA_CORE_DIR.'templates/careers-form-pay.php';
},30,3);
