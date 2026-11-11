<?php
/**
 * Plugin Name: Supreme private order security timeline
 * Description: Passive, private order notes from saved checkout facts and verified provider responses. No scoring, blocking, external API, fingerprint or payment action.
 */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }

function sa_risk_text($v, int $limit = 160): string {
    return substr(sanitize_text_field(is_scalar($v) ? (string) $v : ''), 0, $limit);
}
function sa_risk_ip($v): string {
    return filter_var((string) $v, FILTER_VALIDATE_IP) ? (string) $v : '';
}
function sa_risk_device(string $ua): string {
    if ($ua === '') { return 'Unavailable'; }
    $type = preg_match('/iPad|Tablet/i', $ua) ? 'Tablet' : (preg_match('/Mobile|Android|iPhone/i', $ua) ? 'Mobile' : 'Desktop or unknown');
    $os = 'Unknown OS';
    foreach (['iPhone|iPad'=>'iOS/iPadOS','Android'=>'Android','Windows'=>'Windows','Macintosh|Mac OS'=>'macOS','Linux'=>'Linux'] as $p=>$name) {
        if (preg_match('/'.$p.'/i', $ua)) { $os=$name; break; }
    }
    $browser='Unknown browser';
    foreach (['Edg|EdgiOS'=>'Edge','OPR|OPiOS'=>'Opera','SamsungBrowser'=>'Samsung Internet','FxiOS|Firefox'=>'Firefox','CriOS|Chrome'=>'Chrome','Safari'=>'Safari'] as $p=>$name) {
        if (preg_match('/'.$p.'/i',$ua)) { $browser=$name; break; }
    }
    return "$type / $browser / $os (derived from user-agent, not verified hardware)";
}
/** Idempotent private note; never a customer note or customer-email hook. */
function sa_risk_note($order, string $kind, string $text): void {
    $key='_sa_security_note_'.sanitize_key($kind);
    $hash=hash('sha256',$text);
    if ($order->get_meta($key)===$hash) { return; }
    $order->add_order_note($text, 0, false);
    $order->update_meta_data($key,$hash);
    $order->save_meta_data();
}
/** Saved order facts only. Never use the IP/device of a webhook, admin or CLI caller. */
function sa_risk_checkout_note($order): void {
    if (!is_object($order) || !method_exists($order,'get_customer_ip_address')) { return; }
    if (!in_array($order->get_created_via(), ['checkout','store-api','sa_open_pay'], true)) { return; }
    if ($order->get_meta('_sa_security_checkout_v1') === '1') { return; }
    $ip=sa_risk_ip($order->get_customer_ip_address());
    $ua=sa_risk_text($order->get_customer_user_agent(),512);
    if ($ip==='' && $ua==='') { return; }
    $created=$order->get_date_created();
    $when=$created ? gmdate('Y-m-d H:i:s',$created->getTimestamp()).' UTC' : 'Unavailable';
    $country='';
    // Existing Woo local geolocation only. Explicitly disable both external fallbacks.
    if ($ip!=='' && class_exists('WC_Geolocation')) {
        $geo=WC_Geolocation::geolocate_ip($ip,false,false);
        $cc=strtoupper((string)($geo['country']??''));
        if (preg_match('/^[A-Z]{2}$/D',$cc)) { $country=$cc; }
    }
    $text="Security review | Saved checkout data\nOrder created: $when\nShopper IP recorded by checkout: ".($ip?:'Unavailable')."\nIP country (approximate, derived): ".($country?:'Unavailable locally')."\nDevice: ".sa_risk_device($ua)."\nBrowser user-agent (self-reported): ".($ua?:'Unavailable')."\nHardware ID / IMEI: Not available to this checkout.\nSeparate card IP / cardholder location: Not available from checkout.\nThese signals are not proof of identity, precise location or fraud. No automatic hold or payment action.";
    sa_risk_note($order,'checkout',$text);
    $order->update_meta_data('_sa_security_checkout_v1','1');
    $order->save_meta_data();
}
/** Allowlist only. The caller has already verified the response belongs to this order. */
function sa_risk_provider_note($order, string $provider, array $data): void {
    $lines=['Payment security | '.$provider.' verified response'];
    $labels=['status'=>'Processor status','channel'=>'Payment channel','ip'=>'Processor-reported transaction IP (not card IP)','country'=>'Card issuing country (not cardholder location)','method'=>'Payment method'];
    foreach ($labels as $key=>$label) {
        $value=$data[$key]??'';
        if ($key==='ip') { $value=sa_risk_ip($value); }
        elseif ($key==='country') { $value=preg_match('/^[A-Z]{2}$/D',strtoupper((string)$value)) ? strtoupper((string)$value) : ''; }
        else { $value=sa_risk_text($value,48); }
        if ($value!=='') { $lines[]=$label.': '.$value; }
    }
    $lines[]='AVS / CVC result, 3DS result, risk score, separate card IP: Not supplied to this timeline.';
    $lines[]='This is a response event, not a settlement or fraud verdict. Earlier attempts remain in the order history.';
    sa_risk_note($order,'provider_'.sanitize_key($provider).'_'.sanitize_key((string)($data['status']??'')),implode("\n",$lines));
}
// Queue creation until shutdown: custom-payment code sets the saved shopper fields after creating the order.
add_action('woocommerce_new_order',static function($id): void {
    add_action('shutdown',static function() use($id): void {
        try { $o=wc_get_order((int)$id); if($o) { sa_risk_checkout_note($o); } } catch(Throwable $e) { error_log('[sa-security] checkout note unavailable'); }
    });
},90);
foreach (['woocommerce_checkout_order_created','woocommerce_store_api_checkout_order_processed'] as $hook) {
    add_action($hook,static function($order): void {
        try { sa_risk_checkout_note($order); } catch(Throwable $e) { error_log('[sa-security] checkout note unavailable'); }
    },90);
}
add_action('sa_security_verified_provider',static function($order,$provider,$data): void {
    try { sa_risk_provider_note($order,$provider,$data); } catch(Throwable $e) { error_log('[sa-security] provider note unavailable'); }
},10,3);

// Display existing private security notes as short events in Woo's original note chronology.
// Detail stays collapsed and retrievable. No new events, customer view or persisted-note rewrite.
add_action('admin_enqueue_scripts',static function():void {
    if(!current_user_can('manage_woocommerce')){return;}
    $screen=get_current_screen();
    if(!$screen || !in_array($screen->id,['woocommerce_page_wc-orders','shop_order'],true)){return;}
    wp_enqueue_script('sa-security-timeline',plugins_url('sa-security-timeline.js',__FILE__),[],(string)filemtime(__DIR__.'/sa-security-timeline.js'),true);
});
add_action('admin_head',static function():void {
    if(!current_user_can('manage_woocommerce')){return;}
    $screen=get_current_screen();if(!$screen || !in_array($screen->id,['woocommerce_page_wc-orders','shop_order'],true)){return;}
    echo '<style>.sa-security-event-summary{display:block;font-weight:500;line-height:1.5}.sa-security-event-details{margin-top:6px}.sa-security-event-details>summary{cursor:pointer;color:#3858a8;font-size:12px}.sa-security-event-body{white-space:pre-wrap;overflow-wrap:anywhere;margin-top:8px;line-height:1.6;font-size:12px}</style>';
});
