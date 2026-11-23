<?php
/**
 * Plugin Name: SA Private Order Journey
 * Description: Read-only unification of saved checkout facts and exact consented checkout-session history. No additional device collection.
 */
declare(strict_types=1);
if(!defined('ABSPATH'))exit;
function sa_order_journey_facts($o): array {
    $ip=function_exists('sa_risk_ip')?sa_risk_ip($o->get_customer_ip_address()):'';
    $ua=(string)$o->get_customer_user_agent();
    $public=$ip!==''&&filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE);
    $automation=$ua===''?'Unavailable':(preg_match('/bot|crawler|spider|headless|selenium|curl|python|wget/i',$ua)?'Automation-like token present in saved user-agent. Can be spoofed; not proof of fraud.':'No configured automation-like token in saved user-agent. Does not prove a human.');
    $integrity=$o->get_meta('_sa_checkout_integrity');$integrity=is_array($integrity)?$integrity:[];
    return [
        'Checkout IP (saved by WooCommerce)'=>$ip?:'Unavailable. Not reconstructed from later requests.',
        'IP format assessment'=>$ip===''?'No valid saved IP':($public?'Valid public IP syntax. Ownership and upstream proxy chain unverified.':'Private/reserved IP syntax. Not a verified public shopper address.'),
        'Browser automation assessment'=>$automation,
        'Device / browser / OS'=>function_exists('sa_risk_device')?sa_risk_device($ua):'Unavailable',
        'User-agent (self-reported)'=>$ua?:'Unavailable',
        'Timezone'=>'Not collected. Billing country and IP country do not establish timezone.',
        'Hardware ID / IMEI'=>'Unavailable to this website. Not inferred from user-agent.',
        'Checkout-scoped browser signature'=>isset($integrity['first'])?$integrity['first'].' (session-salted header hash, not hardware or persistent identity)':'No checkout integrity observation was captured for this order.',
        'Checkout browser continuity'=>isset($integrity['observations'])?$integrity['observations'].' request observations; '.$integrity['changes'].' header-configuration changes. Changes can be legitimate; no change is not fraud clearance. Assessed '.($integrity['assessed_at']??''):'Unavailable for this historical or unsupported checkout flow.',
        'Proxy / VPN / Tor assessment'=>'See the dated private Predax IP-reputation note, if present. Applies to the saved checkout IP, not a hidden real IP, hardware or identity.',
        'Other security assessment'=>'See dated local security and verified processor response notes below. Country differences, shared IPs and device reports are not fraud verdicts.',
    ];
}
add_action('add_meta_boxes',static function():void {
    foreach(['shop_order','woocommerce_page_wc-orders'] as $screen)add_meta_box('sa-order-journey','Private checkout journey and security facts','sa_order_journey_box',$screen,'normal','default');
});
function sa_order_journey_box($object): void {
    if(!current_user_can('manage_woocommerce'))return;
    $o=is_object($object)&&method_exists($object,'get_meta')?$object:wc_get_order((int)($object->ID??0));if(!$o)return;
    echo '<p>Read-only saved facts. No automatic block, payment action or identity finding.</p><table class="widefat striped"><tbody>';
    foreach(sa_order_journey_facts($o) as $k=>$v)echo '<tr><th style="width:220px">'.esc_html($k).'</th><td style="overflow-wrap:anywhere">'.esc_html((string)$v).'</td></tr>';
    echo '</tbody></table>';
    $v=sa_journey_id($o->get_meta('_sa_journey_visitor'));$s=sa_journey_id($o->get_meta('_sa_journey_session'));
    if(!$v||!$s){echo '<p>No checkout browser/session link was captured for this order. Historical session, timezone and fingerprint details are not inferred from IP or email. Saved checkout and security notes remain available.</p>';return;}
    $created=$o->get_date_created();if(!$created)return;
    global $wpdb;
    $rows=(array)$wpdb->get_results($wpdb->prepare('SELECT ts,visitor,session,event,path,source,country,returning,device,network_ip,forwarding FROM '.sa_journey_table().' WHERE visitor=%s AND session=%s AND ts<=%s ORDER BY id DESC LIMIT 100',$v,$s,gmdate('Y-m-d H:i:s',$created->getTimestamp())),ARRAY_A);
    echo '<h3>Exact checkout-linked session</h3><p>Matched using the browser and session IDs captured at checkout, not by shared IP. Only activity at or before order creation is shown. Consent and event retention limits apply; this is not proof of payment.</p>';
    if(!$rows){echo '<p>No retained events for this exact checkout session. They may have expired or collection was unavailable.</p>';return;}
    $times=array_map(static fn($r)=>strtotime($r['ts'].' UTC'),$rows);$span=max($times)-min($times);
    $counts=array_count_values(array_column($rows,'event'));$summary=[];foreach($counts as $event=>$n)$summary[]=$event.': '.$n;
    echo '<p><strong>Retained session assessment:</strong> '.esc_html(implode('; ',$summary)).'. Observed span: '.esc_html((string)$span).' seconds across '.esc_html((string)count(array_unique(array_column($rows,'path')))).' page paths. This is observed timing, not a risk score; missing events or capped history can shorten it.</p>';
    sa_journey_render_rows($rows);
}
add_filter('the_content',static function($content){
    if(!function_exists('is_page')||!is_page(['privacy-policy','cookie-policy']))return $content;
    return $content.'<section><h2>Checkout security and private order journeys</h2><p>For fraud prevention and order review, we keep the checkout IP address and browser report supplied with an order, dated local security indicators, and available verified payment-provider security responses in private order records. The existing IP-reputation service, Predax, receives only the saved checkout IP and API authentication, not customer names, email, addresses or card details. Its proxy, VPN, Tor or datacenter signals are estimates, not findings of fraud or identity. Existing order records and security notes follow order-record retention, not the shorter visitor-event window.</p><p>Where analytics collection is allowed under your preferences, random browser and session IDs can link on-site events to the order submitted in that same checkout session. Private events include minimised page paths, source host, approximate country, browser-reported device class and server-observed network IP. Query strings and order keys are excluded. Event history is limited to 30 days and the latest 100,000 events, pruned daily; returning-browser cookies can last 180 days. Historical details are not reconstructed or merged merely because IP addresses match.</p><p>We do not collect hardware identifiers such as IMEI, reveal a hidden pre-proxy IP, or use a persistent device-derived browser fingerprint. For checkout integrity review only, we compare a session-salted hash of browser headers already sent with checkout requests. Working state is only used within a 30-minute window and otherwise expires with the existing WooCommerce session; a completed order retains its assessment with its private security records. It is not reused for advertising, analytics, cross-session identification or restoring cleared cookies. Browser changes are review signals, not automatic blocks. We assess valid/public IP syntax, obvious automation-like browser-report tokens and retained checkout-session event timing locally for private review; absence of a signal is not a safety clearance. These private facts do not trigger an automatic payment block or customer message. Contact us using the privacy contact above to ask about access, correction, deletion or objection, subject to records we need to keep for order, security or legal reasons.</p></section>';
});
