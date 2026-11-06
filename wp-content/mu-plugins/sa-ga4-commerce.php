<?php
/** Plugin Name: SA GA4 commerce events
 * Consent-gated ecommerce funnel. No customer identity fields or provider calls.
 */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }
function sa_ga4_commerce_item($product, int $qty = 1): array {
    if (!$product || $qty < 1) { return []; }
    return ['item_id'=>(string)$product->get_id(), 'item_name'=>(string)$product->get_name(),
        'price'=>(float)wc_get_price_excluding_tax($product), 'quantity'=>$qty];
}
add_action('woocommerce_add_to_cart', static function($key,$id,$qty,$variation): void {
    if (!function_exists('WC') || !WC()->session || !function_exists('sa_ga4_allowed') || !sa_ga4_allowed()) { return; }
    $p=wc_get_product((int)($variation ?: $id)); $item=sa_ga4_commerce_item($p,(int)$qty);
    if (!$item) { return; }
    $events=WC()->session->get('sa_ga4_commerce_pending',[]);
    if (!is_array($events)) { $events=[]; }
    $events[]=['event'=>'add_to_cart','data'=>['currency'=>get_woocommerce_currency(),'value'=>$item['price']*$item['quantity'],'items'=>[$item]]];
    WC()->session->set('sa_ga4_commerce_pending',array_slice($events,-10));
},10,4);
function sa_ga4_commerce_drain(): array {
    if (!function_exists('WC') || !WC()->session) { return []; }
    $events=WC()->session->get('sa_ga4_commerce_pending',[]);
    WC()->session->set('sa_ga4_commerce_pending',[]);
    return is_array($events)?$events:[];
}
add_action('wc_ajax_sa_ga4_commerce_pending',static function(): void {
    if (!function_exists('sa_ga4_allowed') || !sa_ga4_allowed()) { wp_send_json([]); }
    nocache_headers(); wp_send_json(sa_ga4_commerce_drain());
});
add_action('wp_footer',static function(): void {
    if (!function_exists('sa_ga4_allowed') || !sa_ga4_allowed() || !function_exists('sa_ga4_id') || sa_ga4_id()==='') { return; }
    $events=sa_ga4_commerce_drain();
    if (function_exists('is_product') && is_product()) {
        $item=sa_ga4_commerce_item(wc_get_product(get_queried_object_id()));
        if($item) {$events[]=['event'=>'view_item','data'=>['currency'=>get_woocommerce_currency(),'value'=>$item['price'],'items'=>[$item]]];}
    } elseif (function_exists('WC') && WC()->cart && !WC()->cart->is_empty() && (is_cart() || (is_checkout() && !is_order_received_page()))) {
        $items=[];
        foreach(WC()->cart->get_cart() as $row) {
            $item=sa_ga4_commerce_item($row['data']??null,(int)($row['quantity']??0));
            if($item) {
                if(isset($row['line_total'])) {$item['price']=round((float)$row['line_total']/$item['quantity'],6);}
                $items[]=$item;
            }
        }
        if($items) {$events[]=['event'=>is_cart()?'view_cart':'begin_checkout','data'=>['currency'=>get_woocommerce_currency(),'value'=>round(array_sum(array_map(static fn($i)=>$i['price']*$i['quantity'],$items)),2),'items'=>$items]];}
    }
    echo '<script>window.SA_GA4_COMMERCE='.wp_json_encode($events,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';</script>';
    ?>
<script>
(function(){
'use strict';var pending=window.SA_GA4_COMMERCE||[];
function emit(events){if(window.SA_ANALYTICS_CONSENT!==true)return;window.dataLayer=window.dataLayer||[];events.forEach(function(e){if(['view_item','view_cart','begin_checkout','add_to_cart'].indexOf(e.event)<0)return;window.dataLayer.push((function(){return arguments;})('event',e.event,e.data));});}
function start(){if(window.SA_ANALYTICS_CONSENT!==true)return;emit(pending);pending=[];}
window.addEventListener('sa:analytics-consent-granted',start);start();
if(window.jQuery){window.jQuery(document.body).on('added_to_cart',function(){fetch('/?wc-ajax=sa_ga4_commerce_pending',{credentials:'same-origin',cache:'no-store'}).then(function(r){if(!r.ok)throw Error('unavailable');return r.json();}).then(function(events){if(!Array.isArray(events))return;if(window.SA_ANALYTICS_CONSENT===true)emit(events);else pending=pending.concat(events).slice(-10);}).catch(function(){});});}
})();
</script>
<?php
},40);
