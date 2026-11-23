<?php
/**
 * Plugin Name: SA Checkout Session Integrity
 * Description: Fraud-review-only short-lived checkout browser-header continuity assessment. No device probe, new cookie or external disclosure.
 */
declare(strict_types=1);
if(!defined('ABSPATH'))exit;
function sa_checkout_signature(array $server,string $scope): string {
    $parts=[];
    foreach(['HTTP_USER_AGENT','HTTP_ACCEPT_LANGUAGE','HTTP_SEC_CH_UA','HTTP_SEC_CH_UA_MOBILE','HTTP_SEC_CH_UA_PLATFORM'] as $key)$parts[$key]=substr((string)($server[$key]??''),0,512);
    return hash_hmac('sha256',json_encode($parts),wp_salt('auth').'|checkout|'.$scope);
}
function sa_checkout_signature_state(?array $old,string $hash,int $now): array {
    if(!$old||$now-(int)($old['started']??0)>1800||!preg_match('/^[a-f0-9]{64}$/',(string)($old['first']??'')))return ['started'=>$now,'first'=>$hash,'latest'=>$hash,'observations'=>1,'changes'=>0];
    $old['observations']=min(200,(int)$old['observations']+1);
    if(!hash_equals((string)$old['latest'],$hash))$old['changes']=min(200,(int)$old['changes']+1);
    $old['latest']=$hash;return $old;
}
function sa_checkout_signature_observe(): void {
    if(is_admin()||!function_exists('WC')||!WC()->session)return;
    if(!(function_exists('is_checkout')&&is_checkout())||is_order_received_page())return;
    if(function_exists('is_user_logged_in')&&is_user_logged_in()&&current_user_can('manage_options'))return;
    $session=WC()->session;
    $customer=(string)$session->get_customer_id();if($customer===''||$customer==='0')return;
    $scope=hash_hmac('sha256',$customer,wp_salt('auth'));
    $hash=sa_checkout_signature($_SERVER,$scope);
    $old=$session->get('sa_checkout_integrity');
    $session->set('sa_checkout_integrity',sa_checkout_signature_state(is_array($old)?$old:null,$hash,time()));
}
add_action('template_redirect','sa_checkout_signature_observe',30);
function sa_checkout_signature_order($o):void {
    if(!is_object($o)||!method_exists($o,'get_meta')||$o->get_meta('_sa_checkout_integrity')||!function_exists('WC')||!WC()->session)return;
    $session=WC()->session;$old=$session->get('sa_checkout_integrity');
    // Do not fingerprint an admin, webhook, unknown history or a different session after expiry.
    if(!is_array($old)||time()-(int)($old['started']??0)>1800)return;
    $customer=(string)$session->get_customer_id();if($customer===''||$customer==='0')return;
    $scope=hash_hmac('sha256',$customer,wp_salt('auth'));
    $state=sa_checkout_signature_state($old,sa_checkout_signature($_SERVER,$scope),time());
    $state['assessed_at']=gmdate('Y-m-d H:i:s').' UTC';
    $o->update_meta_data('_sa_checkout_integrity',$state);$o->save_meta_data();
    $session->__unset('sa_checkout_integrity');
}
add_action('woocommerce_checkout_order_created','sa_checkout_signature_order',22);
// Store API has no prior classic checkout page observation: do not invent multi-step continuity.
