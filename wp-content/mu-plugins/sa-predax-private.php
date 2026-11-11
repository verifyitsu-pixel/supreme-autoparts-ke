<?php
/**
 * Plugin Name: Supreme private Predax IP screening
 * Description: Async checkout-IP-only screening. Private review notes, never checkout enforcement.
 * License: GPL-2.0-or-later
 */
declare(strict_types=1);
if(!defined('ABSPATH')){exit;}

function sa_predax_public_ip(string $ip):string {
    return filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)?$ip:'';
}
/** Atomic conservative reservation. Failed or duplicate calls still consume a local slot. */
function sa_predax_slot(string $bucket,int $limit):bool {
    global $wpdb;
    $name='sa_predax_budget_'.$bucket;
    add_option($name,'0','','no');
    $n=$wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value=CAST(option_value AS UNSIGNED)+1 WHERE option_name=%s AND CAST(option_value AS UNSIGNED)<%d",$name,$limit));
    wp_cache_delete($name,'options');
    return $n===1;
}
function sa_predax_budget():bool {
    // UTC buckets match service quota reset. Below permanent Free limits, not promo limits.
    return sa_predax_slot(gmdate('Ym'),4000) && sa_predax_slot(gmdate('Ymd'),800);
}
function sa_predax_parse(array $data):?array {
    $c=$data['classification']??null;
    if(!is_array($c)||!isset($c['risk_score'])||!is_numeric($c['risk_score'])){return null;}
    $score=(float)$c['risk_score'];if($score<0||$score>100){return null;}
    $out=['score'=>$score,'level'=>$score>=80?'high':($score>=50?'medium':($score>=20?'low':'minimal')),'country'=>'','flags'=>[]];
    foreach(['is_proxy'=>'proxy','is_vpn'=>'VPN','is_tor'=>'Tor','is_datacenter'=>'datacenter','is_crawler'=>'crawler']as$k=>$label){
        if(($c[$k]??false)===true){$out['flags'][]=$label;}
    }
    $cc=strtoupper((string)($data['location']['country_code']??''));
    if(preg_match('/^[A-Z]{2}$/D',$cc)){$out['country']=$cc;}
    return $out;
}
function sa_predax_note($order,?array $result,string $unavailable='',bool $cached=false):void {
    if($order->get_meta('_sa_predax_done_v1')==='1'){return;}
    $text="IP reputation | Predax\nAssessed: ".gmdate('Y-m-d H:i:s')." UTC\n";
    if($result){
        $text.='Risk: '.$result['level'].' ('.$result['score']."/100, provider score, not a fraud verdict).\nSignals: ".($result['flags']?implode(', ',$result['flags']):'No listed network flag returned').".\nApproximate IP country: ".($result['country']?:'Unavailable').".\nSource: ".($cached?'Local cache of a Predax result, at most one hour old.':'Predax API response for the saved checkout IP.')."\nVPN/datacenter use can be legitimate. No precise/cardholder location or identity finding.";
    }else{$text.='Result: Unavailable ('.$unavailable."). No risk conclusion inferred.";}
    $text.="\nPermitted payload: saved checkout IP only, plus API authentication. No name, email, address, order ID, amount, payment detail, timezone or fingerprint. No community telemetry.\nNotify only: no block, hold, status change, document request or customer message.";
    sa_risk_note($order,'predax_ip',$text);
    $order->update_meta_data('_sa_predax_done_v1','1');$order->save_meta_data();
    if($result && $result['level']==='high'){
        sa_local_alert($order,'Predax returned a high IP-reputation score of '.$result['score'].'/100. This is a network-risk signal, not proof of customer fraud. Review details privately.');
    }
}
function sa_predax_run(int $id):void {
    $order=wc_get_order($id);
    if(!$order || !in_array($order->get_created_via(),['checkout','store-api','sa_open_pay'],true) || $order->get_meta('_sa_predax_done_v1')==='1'){return;}
    $lock='sa_predax_lock_'.$id;
    if(!add_option($lock,time(),'','no')){return;}
    try{
        $key=(string)get_option('sa_predax_key','');
        if($key===''){sa_predax_note($order,null,'screening not configured');return;}
        $ip=sa_predax_public_ip((string)$order->get_customer_ip_address());
        if($ip===''){sa_predax_note($order,null,'no valid public checkout IP');return;}
        $cache='sa_predax_ip_'.hash_hmac('sha256',$ip,wp_salt('auth'));
        $result=get_transient($cache);
        if(is_array($result)){sa_predax_note($order,$result,'',true);return;}
        if(get_transient('sa_predax_paused')){sa_predax_note($order,null,'provider rate or quota pause');return;}
        if(!sa_predax_budget()){sa_predax_note($order,null,'local free-quota safety cap');return;}
        $response=wp_remote_post('https://predax.io/api/v1/check/ip',[
            'timeout'=>5,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>65536,
            'user-agent'=>'SupremeSecuritySetup/1.0',
            'headers'=>['X-API-Key'=>$key,'Content-Type'=>'application/json','Accept'=>'application/json'],
            'body'=>wp_json_encode(['ip'=>$ip]),
        ]);
        if(is_wp_error($response)){sa_predax_note($order,null,'provider request failed');return;}
        $code=(int)wp_remote_retrieve_response_code($response);
        if(in_array($code,[401,402,403,429],true)){
            set_transient('sa_predax_paused',1,$code===429?3600:DAY_IN_SECONDS);
            sa_predax_note($order,null,'provider access, rate or quota limit');return;
        }
        if($code!==200){sa_predax_note($order,null,'provider response unavailable');return;}
        $data=json_decode((string)wp_remote_retrieve_body($response),true);
        $result=is_array($data)?sa_predax_parse($data):null;
        if(!$result){sa_predax_note($order,null,'unrecognized provider result');return;}
        set_transient($cache,$result,HOUR_IN_SECONDS);
        sa_predax_note($order,$result);
    }finally{delete_option($lock);}
}
add_action('sa_predax_order_screen','sa_predax_run');
add_action('woocommerce_new_order',static function($id):void {
    // Existing order fields are completed later in this request. Never screen imports/admin or backfill.
    add_action('shutdown',static function()use($id):void {
        $o=wc_get_order((int)$id);
        if(!$o || !in_array($o->get_created_via(),['checkout','store-api','sa_open_pay'],true) || $o->get_meta('_sa_predax_queued_v1')==='1'){return;}
        if(!get_option('sa_predax_key','')){return;}
        $o->update_meta_data('_sa_predax_queued_v1','1');$o->save_meta_data();
        if(function_exists('as_enqueue_async_action')){as_enqueue_async_action('sa_predax_order_screen',[(int)$id],'sa-private-security');}
        else{wp_schedule_single_event(time()+5,'sa_predax_order_screen',[(int)$id]);}
    });
},96);
// Remove old quota/abandoned-lock metadata during normal admin use, not customer checkout.
add_action('admin_init',static function():void {
    if(!current_user_can('manage_woocommerce')||get_transient('sa_predax_cleanup')){return;}
    set_transient('sa_predax_cleanup',1,DAY_IN_SECONDS);
    global $wpdb;
    $old_day=gmdate('Ymd',time()-3*DAY_IN_SECONDS);$old_month=gmdate('Ym',time()-62*DAY_IN_SECONDS);
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND ((CHAR_LENGTH(option_name)=25 AND SUBSTRING(option_name,18)<%s) OR (CHAR_LENGTH(option_name)=23 AND SUBSTRING(option_name,18)<%s))",$wpdb->esc_like('sa_predax_budget_').'%', $old_day,$old_month));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED)<%d",$wpdb->esc_like('sa_predax_lock_').'%',time()-DAY_IN_SECONDS));
});
