<?php
/**
 * Plugin Name: SA Private Journey Sessions
 * Description: Consent-controlled on-site browser/session correlation, source and ecommerce timeline. No replay or fingerprinting.
 * Version: 1.0.0
 */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }
function sa_journey_id($id): string { return is_string($id) && preg_match('/^[a-f0-9]{32}$/D', $id) ? $id : ''; }
function sa_journey_table(): string { global $wpdb; return $wpdb->prefix . 'sa_journey_events'; }
add_action('admin_init', static function(): void {
    if (get_option('sa_journey_schema') === '1') { return; }
    global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = sa_journey_table(); $charset = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        ts datetime NOT NULL,
        event_key char(64) NOT NULL,
        visitor char(32) NOT NULL,
        session char(32) NOT NULL,
        event varchar(24) NOT NULL,
        path varchar(300) NOT NULL,
        source varchar(190) NOT NULL DEFAULT '',
        country char(2) NOT NULL DEFAULT '',
        returning tinyint(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (id), UNIQUE KEY event_key (event_key), KEY visitor_ts (visitor,ts), KEY session_ts (session,ts), KEY ts (ts)
    ) {$charset};");
    update_option('sa_journey_schema', '1', false);
    if (!wp_next_scheduled('sa_journey_prune')) { wp_schedule_event(time()+3600, 'daily', 'sa_journey_prune'); }
});
add_action('sa_journey_prune', static function(): void {
    global $wpdb; $table=sa_journey_table();
    $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE ts < %s", gmdate('Y-m-d H:i:s',time()-30*DAY_IN_SECONDS)));
    // Bound disk use even if traffic grows unexpectedly. Preserve latest 100,000 rows.
    $cut=$wpdb->get_var("SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET 100000");
    if($cut) { $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id <= %d",(int)$cut)); }
});
add_action('wc_ajax_sa_journey', static function(): void {
    nocache_headers();
    $origin=(string)($_SERVER['HTTP_ORIGIN']??'');
    $host=(string)parse_url(home_url(),PHP_URL_HOST);
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST' || $origin==='' || parse_url($origin,PHP_URL_HOST)!==$host || (is_user_logged_in() && current_user_can('manage_options'))) { wp_send_json(['accepted'=>false],403); }
    if (($_COOKIE['sa_tconsent']??'')==='0') { wp_send_json(['accepted'=>false],403); }
    $cc=strtoupper((string)($_SERVER['HTTP_CF_IPCOUNTRY']??''));
    if (!preg_match('/^[A-Z]{2}$/D',$cc) || in_array($cc,['XX','T1'],true)) { $cc=strtoupper((string)($_COOKIE['sa_geo_cc']??'')); }
    if (!preg_match('/^[A-Z]{2}$/D',$cc) || in_array($cc,['XX','T1'],true)) { $cc=''; }
    if (in_array($cc,explode(' ','AT BE BG HR CY CZ DK EE FI FR DE GR HU IS IE IT LV LI LT LU MT NL NO PL PT RO SK SI ES SE GB CH'),true) && ($_COOKIE['sa_tconsent']??'')!=='1') { wp_send_json(['accepted'=>false],403); }
    $v=sa_journey_id($_COOKIE['sa_jv']??''); $s=sa_journey_id($_COOKIE['sa_js']??'');
    $event=sanitize_key((string)($_POST['event']??''));
    if (!$v || !$s || !in_array($event,['page_view','view_item','add_to_cart','view_cart','begin_checkout','purchase'],true)) { wp_send_json(['accepted'=>false],400); }
    // Client events are behavioral observations, never a payment or revenue source of truth.
    global $wpdb; $table=sa_journey_table();
    $event_id=(string)($_POST['event_id']??'');
    if(!preg_match('/^[a-f0-9]{32}_[a-z_]{1,24}_[0-9]{1,6}$/D',$event_id)) { wp_send_json(['accepted'=>false],400); }
    $dedupe=hash('sha256',$s.'|'.$event_id);
    if($wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE event_key=%s",$dedupe))) { wp_send_json(['accepted'=>true,'duplicate'=>true]); }
    $n=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE session=%s AND ts >= %s",$s,gmdate('Y-m-d H:i:s',time()-1800)));
    if($n>=200) { wp_send_json(['accepted'=>false],429); }
    $ok=$wpdb->insert($table,[
        'event_key'=>$dedupe,'ts'=>gmdate('Y-m-d H:i:s'),'visitor'=>$v,'session'=>$s,'event'=>$event,
        'path'=>sa_tracking_path(substr((string)wp_unslash($_POST['path']??'/'),0,1000)),
        'source'=>substr((string)parse_url(sa_tracking_referrer((string)wp_unslash($_POST['referrer']??'')),PHP_URL_HOST),0,190),
        'country'=>$cc,'returning'=>(($_COOKIE['sa_jreturn']??'')==='1'?1:0),
    ],['%s','%s','%s','%s','%s','%s','%s','%s','%d']);
    wp_send_json(['accepted'=>(bool)$ok],$ok?200:503);
});
function sa_journey_order_meta($order): void {
    if (!is_object($order) || !method_exists($order,'update_meta_data')) { return; }
    if(($_COOKIE['sa_tconsent']??'')==='0')return;
    foreach(['sa_jv'=>'_sa_journey_visitor','sa_js'=>'_sa_journey_session'] as $cookie=>$meta) {
        $id=sa_journey_id($_COOKIE[$cookie]??''); if($id && !$order->get_meta($meta)) { $order->update_meta_data($meta,$id); }
    }
    $order->save_meta_data();
}
add_action('woocommerce_checkout_order_created','sa_journey_order_meta',21);
add_action('wp_footer',static function(): void {
    if (!function_exists('sa_ga4_allowed') || !sa_ga4_allowed()) { return; }
    ?>
<script>
(function(){
'use strict';var started=false,visitor='',session='',page='',last=0;
function cookie(n){try{var m=document.cookie.match(new RegExp('(?:^|; )'+n+'=([^;]*)'));return m?decodeURIComponent(m[1]):'';}catch(e){return '';}}
function put(n,v,days){document.cookie=n+'='+encodeURIComponent(v)+';path=/;SameSite=Lax;Secure;max-age='+Math.floor(days*86400);}
function id(){if(!window.crypto||!window.crypto.getRandomValues)return '';var a=new Uint8Array(16);window.crypto.getRandomValues(a);return Array.from(a,function(v){return v.toString(16).padStart(2,'0');}).join('');}
function valid(v){return /^[a-f0-9]{32}$/.test(v);}
function clean(p){p=p.replace(/\/(order-received|order-pay|view-order|lost-password|reset-password)\/[^/]+\/?/gi,'/$1/');return /@|%40|token|password|secret|nonce|wc_order_/i.test(p)?'/[private]':p.slice(0,300);}
function ensure(){var now=Date.now(),old=cookie('sa_jv');visitor=valid(old)?old:id();if(!visitor)return false;var prev=Number(cookie('sa_jseen')),s=cookie('sa_js');if(!valid(s)||!prev||now-prev>1800000){s=id();put('sa_jreturn',valid(old)?'1':'0',180);}session=s;var source=cookie('sa_jsource');try{var rh=document.referrer?new URL(document.referrer).origin:'';if(rh&&rh!==location.origin){source=rh;put('sa_jsource',source,180);}}catch(e){}put('sa_jv',visitor,180);put('sa_js',session,1/48);put('sa_jseen',String(now),180);last=now;return valid(session);}
function emit(name){if(window.SA_ANALYTICS_CONSENT!==true||!started||!ensure())return;var ref=cookie('sa_jsource');var data=new URLSearchParams({event:name,path:clean(location.pathname),referrer:ref,event_id:page+'_'+name+'_'+(++emit.n)});fetch('/?wc-ajax=sa_journey',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:data.toString(),keepalive:true}).catch(function(){});}
emit.n=0;
function start(){if(started||window.SA_ANALYTICS_CONSENT!==true)return;if(cookie('sa_tconsent')==='0')return;if(!ensure())return;started=true;page=id();window.SA_JOURNEY={event:emit};emit('page_view');(window.SA_JOURNEY_PENDING||[]).forEach(emit);window.SA_JOURNEY_PENDING=[];}
window.addEventListener('sa:analytics-consent-granted',start);
window.addEventListener('sa:analytics-consent-denied',function(){['sa_jv','sa_js','sa_jseen','sa_jreturn','sa_jsource'].forEach(function(n){put(n,'',-1);});started=false;window.SA_JOURNEY_PENDING=[];});
window.addEventListener('sa:commerce-event',function(e){if(!e.detail||['view_item','add_to_cart','view_cart','begin_checkout','purchase'].indexOf(e.detail.event)<0)return;if(started)emit(e.detail.event);else {window.SA_JOURNEY_PENDING=window.SA_JOURNEY_PENDING||[];window.SA_JOURNEY_PENDING.push(e.detail.event);}});
start();
})();
</script>
<?php
},25);
add_action('admin_menu', static function(): void {
    add_submenu_page('sa-analytics','Journey Sessions','Journey Sessions','manage_options','sa-journeys','sa_journey_admin');
});
function sa_journey_rows(array $visitors=[]): array {
    global $wpdb; $table=sa_journey_table();
    $where='';$args=[];
    if($visitors) { $visitors=array_values(array_filter(array_map('sa_journey_id',$visitors)));if(!$visitors)return[];$where=' WHERE visitor IN ('.implode(',',array_fill(0,count($visitors),'%s')).')';$args=$visitors; }
    $sql="SELECT ts,visitor,session,event,path,source,country,returning FROM {$table}{$where} ORDER BY id DESC LIMIT 100";
    return (array)$wpdb->get_results($args?$wpdb->prepare($sql,$args):$sql,ARRAY_A);
}
function sa_journey_render_rows(array $rows): void {
    echo '<table class="widefat striped"><thead><tr><th>UTC</th><th>Browser / session</th><th>Event</th><th>Page</th><th>Source host</th><th>Approx. country</th><th>Returning browser</th></tr></thead><tbody>';
    foreach($rows as $r){echo '<tr>';foreach([$r['ts'],substr($r['visitor'],0,10).' / '.substr($r['session'],0,10),$r['event'],sa_tracking_path($r['path']),$r['source'],$r['country']?:'Unknown',$r['returning']?'Yes':'No'] as $v){echo '<td>'.esc_html((string)$v).'</td>';}echo '</tr>';}
    if(!$rows)echo '<tr><td colspan="7">No consented sessions captured yet.</td></tr>';
    echo '</tbody></table>';
}
function sa_journey_admin(): void {
    if(!current_user_can('manage_options'))return;
    echo '<div class="wrap"><h1>Journey Sessions</h1><p>Private first-party browser IDs, 30-minute inactivity sessions, 180-day returning-browser cookie. Up to 30 days / 100,000 latest events, pruned daily. No replay, fingerprinting, precise location, form text or payment details. Browser events are not proof of payment. A cleared cookie or another device starts a different browser.</p>';
    sa_journey_render_rows(sa_journey_rows());echo '</div>';
}
