<?php
/**
 * Plugin Name: SA Google Analytics
 * Description: Consent-gated GA4 page views and paid WooCommerce purchases, without customer identity fields.
 */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }
function sa_ga4_id(): string {
    $id = (string) (getenv('SA_GA4_MEASUREMENT_ID') ?: 'G-LCH3Y7NNRY');
    return preg_match('/^G-[A-Z0-9]+$/', $id) ? $id : '';
}
function sa_ga4_allowed(): bool {
    return !is_admin() && !(is_user_logged_in() && current_user_can('manage_options'));
}
function sa_ga4_purchase_payload($order): array {
    $items = [];
    foreach ($order->get_items() as $item) {
        $quantity = (int) $item->get_quantity();
        if ($quantity < 1) { continue; }
        $items[] = ['item_id' => (string) ($item->get_variation_id() ?: $item->get_product_id()), 'item_name' => (string) $item->get_name(), 'price' => round((float) $item->get_total() / $quantity, 6), 'quantity' => $quantity];
    }
    return ['transaction_id' => 'SA-' . (string) $order->get_id(), 'currency' => (string) $order->get_currency(), 'value' => round(array_sum(array_map(static fn($i) => $i['price'] * $i['quantity'], $items)), 2), 'tax' => (float) $order->get_total_tax(), 'shipping' => (float) $order->get_shipping_total(), 'items' => $items];
}
add_action('woocommerce_thankyou', static function ($order_id): void {
    if (!sa_ga4_allowed() || sa_ga4_id() === '' || !function_exists('wc_get_order')) { return; }
    $order = wc_get_order($order_id);
    if (!$order || !$order->is_paid()) { return; }
    $key = isset($_GET['key']) && is_string($_GET['key']) ? wc_clean(wp_unslash($_GET['key'])) : '';
    $owns = is_user_logged_in() && (int) $order->get_customer_id() > 0 && (int) $order->get_customer_id() === get_current_user_id();
    if (!$owns && ($key === '' || !hash_equals((string) $order->get_order_key(), $key))) { return; }
    echo '<script>window.SA_GA4_PURCHASE=' . wp_json_encode(sa_ga4_purchase_payload($order), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
}, 30);
add_action('wp_footer', static function (): void {
    $id = sa_ga4_id();
    if ($id === '' || !sa_ga4_allowed()) { return; }
    echo '<script>window.SA_GA4_ID=' . wp_json_encode($id) . ';</script>';
    ?>
<script>
(function(){
  "use strict";
  var started=false, id=window.SA_GA4_ID;
  function cleanLocation(){
    var p=location.pathname.replace(/\/(order-received|order-pay)\/[^/]+\/?/g,"/$1/");
    return location.origin+p;
  }
  function start(){
    if(window.SA_ANALYTICS_CONSENT!==true)return;
    window["ga-disable-"+id]=false;
    if(started)return;
    started=true;
    window.dataLayer=window.dataLayer||[];
    function gtag(){window.dataLayer.push(arguments);}
    gtag("consent","default",{analytics_storage:"granted",ad_storage:"denied",ad_user_data:"denied",ad_personalization:"denied"});
    var ref="";try{ref=document.referrer?new URL(document.referrer).origin:"";}catch(e){}
    gtag("set",{page_location:cleanLocation(),page_referrer:ref,allow_google_signals:false,allow_ad_personalization_signals:false});
    gtag("js",new Date());
    gtag("config",id,{send_page_view:false,allow_google_signals:false,allow_ad_personalization_signals:false});
    gtag("event","page_view",{page_location:cleanLocation(),page_referrer:ref,page_title:"Supreme Autoparts"});
    var purchase=window.SA_GA4_PURCHASE;
    if(purchase&&purchase.items&&purchase.items.length){
      var seen=false,key="sa_ga4_purchase_"+purchase.transaction_id;
      try{seen=localStorage.getItem(key)==="1";}catch(e){}
      if(!seen){gtag("event","purchase",purchase);try{localStorage.setItem(key,"1");}catch(e){}}
    }
    var s=document.createElement("script");s.async=true;s.src="https://www.googletagmanager.com/gtag/js?id="+encodeURIComponent(id);document.head.appendChild(s);
    window.addEventListener("sa:analytics-consent-denied",function(){window["ga-disable-"+id]=true;});
  }
  window.addEventListener("sa:analytics-consent-granted",start);
  start();
})();
</script>
<?php
}, 30);
