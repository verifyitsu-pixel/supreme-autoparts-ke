<?php
/**
 * Plugin Name: Supreme local security signals
 * License: GPL-2.0-or-later
 * Description: Local, notify-only checks. No external screening or checkout enforcement.
 */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }

function sa_local_indicators(array $facts): array {
    static $domains=null,$allow=null,$names=null;
    if($domains===null) {
        $base=__DIR__.'/sa-security-data/';
        $domains=include $base.'disposable-domains.php';
        $allow=include $base.'disposable-allowlist.php';
        $names=include $base.'spam-names.php';
    }
    $out=[];$email=strtolower(trim((string)($facts['email']??'')));
    $domain=strrchr($email,'@');$domain=$domain===false?'':substr($domain,1);
    if($domain!=='' && !isset($allow[$domain]) && isset($domains[$domain])) { $out[]='Disposable email domain matches the bundled Checkout Guard list'; }
    foreach(['first','last']as$key) {
        $name=strtolower(trim(preg_replace('/\s+/u',' ',(string)($facts[$key]??''))));
        if($name!=='' && isset($names[$name])) { $out[]='Billing name matches a bundled placeholder pattern';break; }
    }
    // Country differences alone are normal for travel, proxies and cross-border shopping.
    if(!empty($facts['ip_country']) && !empty($facts['billing_country']) && $facts['ip_country']!==$facts['billing_country']) { $out[]='Approximate IP country differs from billing country (not proof of fraud)'; }
    if((int)($facts['ip_orders']??0)>10) { $out[]='More than 10 order submissions from the same saved IP in 10 minutes'; }
    if((int)($facts['email_orders']??0)>5) { $out[]='More than 5 order submissions for the same email in one hour'; }
    return $out;
}
function sa_local_high_risk(array $facts): bool {
    // Conservative notification policy. No single name, country or disposable-domain match triggers high risk.
    return ((int)($facts['ip_orders']??0)>10 && (int)($facts['email_orders']??0)>5);
}
function sa_local_counter(string $kind,string $value,int $window): int {
    if($value==='') { return 0; }
    $bucket=(int)floor(time()/$window);
    $key='sa_local_'.hash_hmac('sha256',$kind.'|'.$value.'|'.$bucket,wp_salt('auth'));
    $n=(int)get_transient($key)+1;
    set_transient($key,min($n,100),$window+60);
    return min($n,100); // Approximate fixed-window counter, not forensic transaction velocity.
}
function sa_local_alert($order,string $reason): void {
    if($order->get_meta('_sa_internal_risk_alert_v1')==='1') { return; }
    sa_risk_note($order,'internal_alert',"Internal security alert | Review indicators\n".$reason."\nNotification only. Not a fraud finding. Payment, order status and customer messages are unchanged.");
    $order->update_meta_data('_sa_internal_risk_alert_v1','1');$order->save_meta_data();
    $alerts=(array)get_option('sa_internal_risk_alerts_v1',[]);
    $alerts=array_filter($alerts,static fn($a)=>is_array($a) && (int)($a['at']??0)>time()-30*DAY_IN_SECONDS);
    $group=hash_hmac('sha256',strtolower($order->get_billing_email()).'|'.$order->get_customer_ip_address(),wp_salt('auth'));
    $key='sa_local_alert_'.$group;
    if(get_transient($key)){return;}
    set_transient($key,1,600); // At most one dashboard entry per IP/email pair per ten minutes.
    $alerts[]=['id'=>(int)$order->get_id(),'at'=>time()];
    update_option('sa_internal_risk_alerts_v1',array_slice($alerts,-20),false);
}
function sa_local_order_checks($order): void {
    if(!in_array($order->get_created_via(),['checkout','store-api','sa_open_pay'],true) || $order->get_meta('_sa_local_checks_v1')==='1') { return; }
    $ip=sa_risk_ip($order->get_customer_ip_address());
    $cc=''; // Third-party country database held pending explicit data provenance/license.
    $f=['email'=>$order->get_billing_email(),'first'=>$order->get_billing_first_name(),'last'=>$order->get_billing_last_name(),'billing_country'=>$order->get_billing_country(),'ip_country'=>$cc,
        'ip_orders'=>sa_local_counter('ip',$ip,600),'email_orders'=>sa_local_counter('email',strtolower($order->get_billing_email()),3600)];
    $indicators=sa_local_indicators($f);
    $text="Local security checks | Notify only\nAssessed: ".gmdate('Y-m-d H:i:s')." UTC\nOffline IP country: ".($cc?:'Unavailable: additional country database held for license/provenance review').".\nCheckout Guard 2.1.1 local lists: disposable domain and exact placeholder-name checks.\nOrder submissions: ".$f['ip_orders']." per saved IP / 10-minute bucket; ".$f['email_orders']." per email / hourly bucket (approximate, starts after deployment).\nIndicators: ".($indicators?implode('; ',$indicators):'No configured indicator matched').".\nNo external reputation, VPN, hardware identity or card-IP check was performed. No automatic block or hold.";
    sa_risk_note($order,'local_checks',$text);
    $order->update_meta_data('_sa_local_checks_v1','1');$order->save_meta_data();
    if(sa_local_high_risk($f)) { sa_local_alert($order,'Repeated order-submission thresholds matched for both IP and email. Review the private order timeline.'); }
}
add_action('woocommerce_new_order',static function($id): void {
    add_action('shutdown',static function()use($id):void {
        try{$o=wc_get_order((int)$id);if($o){sa_local_order_checks($o);}}catch(Throwable $e){error_log('[sa-local-security] order signals unavailable');}
    });
},95);
add_action('admin_notices',static function():void {
    if(!current_user_can('manage_woocommerce')) { return; }
    $alerts=(array)get_option('sa_internal_risk_alerts_v1',[]);$links=[];
    foreach(array_reverse($alerts)as$a) {
        if(!is_array($a)||(int)($a['at']??0)<time()-30*DAY_IN_SECONDS){continue;}
        $o=wc_get_order((int)($a['id']??0));if(!$o){continue;}
        $links[]='<a href="'.esc_url($o->get_edit_order_url()).'">Order '.esc_html((string)$o->get_order_number()).'</a>';
        if(count($links)>=5){break;}
    }
    if($links){echo '<div class="notice notice-warning"><p><strong>Private security review:</strong> '.implode(', ',$links).'. Indicators only; no payment or order action was taken.</p></div>';}
});
