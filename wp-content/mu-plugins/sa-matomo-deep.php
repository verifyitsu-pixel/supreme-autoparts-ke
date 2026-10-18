<?php
/**
 * Plugin Name: SA Deep Analytics (Matomo deep layer)
 * Description: Turns the self-hosted Matomo for WordPress install into deep, eBay-grade analytics:
 *              ecommerce (built into the Matomo plugin), site search, 404s, heartbeat engagement,
 *              JS errors, user IDs for logged-in customers, IP geolocation (free DB-IP City Lite),
 *              goals, custom dimensions (viewport / connection / timezone), click + scroll + form +
 *              filter + garage event tracking, and a UK/EU cookie-consent banner (region detected via
 *              the existing geo-currency plugin; tracking runs cookieless until consent).
 *              All data stays in the site's own database. No third-party tracker is required.
 * Version: 1.0.0
 * Author: Supreme Autoparts
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const SA_MATOMO_DEEP_VERSION = '1.0.0';

/**
 * Microsoft Clarity project IDs per host (heatmaps + session recordings, free).
 * Empty = Clarity off for that host. Masking is Strict by default in Clarity and the
 * snippet only loads after the consent rules below are applied.
 */
function sa_matomo_deep_clarity_id(): string
{
    $map = [
        'www.supremeautoparts.co.ke' => '',
        'supremeautoparts.co.ke'     => '',
        'maxoutnutrition.com'        => '',
        'www.maxoutnutrition.com'    => '',
    ];
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    return $map[$host] ?? '';
}

/* ----------------------------------------------------------------------- */
/* Provisioning (once per version)                                          */
/* ----------------------------------------------------------------------- */

add_action('init', static function (): void {
    if (get_option('sa_matomo_deep_version') === SA_MATOMO_DEEP_VERSION) {
        return;
    }
    if (!class_exists(\WpMatomo\Settings::class)) {
        return; // Matomo plugin not active yet; retry on next request.
    }

    // 1) Tracking settings (cheap, synchronous). These merge into the same option
    //    the deploy entrypoint manages; apply_tracking_related_changes regenerates
    //    the tracking code with the combined values.
    try {
        $s = new \WpMatomo\Settings();
        $s->apply_tracking_related_changes([
            'track_ecommerce' => true,      // WooCommerce: product views, cart updates, orders + revenue
            'track_search'    => true,      // site search terms (?s=)
            'track_404'       => true,      // broken URLs (helps the indexing cleanup)
            'track_content'   => 'visible', // content impressions when blocks opt in
            'track_heartbeat' => 15,        // accurate time-on-page / engagement
            'track_jserrors'  => true,      // front-end JS error tracking
            'track_user_id'   => 'uid',     // logged-in customers stitched across devices (numeric ID, no PII)
            'cookie_consent'  => 'cookie',  // requireCookieConsent: no tracking cookies until consented
        ]);
    } catch (\Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[sa-matomo-deep] settings: ' . $e->getMessage());
        }
    }

    // 2) Heavy provisioning (GeoIP DB download, goals, custom dimensions) off the
    //    web request path: single cron event, runs within a minute on both hosts.
    if (!wp_next_scheduled('sa_matomo_deep_provision')) {
        wp_schedule_single_event(time() + 30, 'sa_matomo_deep_provision');
    }

    update_option('sa_matomo_deep_version', SA_MATOMO_DEEP_VERSION);
}, 20);

add_action('sa_matomo_deep_provision', static function (): void {
    if (!class_exists(\WpMatomo\Settings::class)) {
        return;
    }

    // Free IP geolocation: DB-IP City Lite (no license key), then GeoIP2 PHP provider.
    // The Matomo plugin also re-runs this monthly via its own matomo_scheduled_geoipdb cron.
    try {
        $settings = new \WpMatomo\Settings();
        $cfg      = new \WpMatomo\Site\Sync\SyncConfig($settings);
        $tasks    = new \WpMatomo\ScheduledTasks($settings, $cfg);
        $tasks->update_geo_ip2_db();
    } catch (\Throwable $e) {
        error_log('[sa-matomo-deep] geoip: ' . $e->getMessage());
    }

    // Goals + custom dimensions through the bundled Matomo API.
    try {
        \WpMatomo\Bootstrap::do_bootstrap();
        $id_site = (int) (new \WpMatomo\Site())->get_current_matomo_site_id();
        if ($id_site <= 0) {
            return;
        }

        \Piwik\Access::doAsSuperUser(static function () use ($id_site): void {
            // Goals on the event categories the front-end tracker fires.
            if (class_exists(\Piwik\Plugins\Goals\API::class)) {
                $goals_api = \Piwik\Plugins\Goals\API::getInstance();
                $existing  = array_map(static function ($g) { return (string) ($g['name'] ?? ''); }, $goals_api->getGoals($id_site));
                $wanted    = [
                    // name, match attribute, pattern, allow multiple conversions per visit
                    ['Contacted support',      'event_category', 'Contact',  true],
                    ['Added to cart / Buy now','event_category', 'Cart',     true],
                    ['Started checkout',       'event_category', 'Checkout', false],
                    ['Submitted a form',       'event_category', 'Form',     true],
                    ['Used shop sort/filter',  'event_category', 'Shop',     true],
                    ['Used garage selector',   'event_category', 'Garage',   true],
                ];
                foreach ($wanted as $g) {
                    $name = (string) $g[0]; $attr = (string) $g[1]; $pattern = (string) $g[2]; $multi = (bool) $g[3];
                    if (in_array($name, $existing, true)) {
                        continue;
                    }
                    try {
                        $goals_api->addGoal($id_site, $name, $attr, $pattern, 'equals', false, 0, $multi);
                    } catch (\Throwable $e) {
                        error_log('[sa-matomo-deep] goal ' . $name . ': ' . $e->getMessage());
                    }
                }
            }

            // Visit-scoped custom dimensions for the extra device detail.
            if (class_exists(\Piwik\Plugins\CustomDimensions\API::class)) {
                $dim_api = \Piwik\Plugins\CustomDimensions\API::getInstance();
                $have    = [];
                foreach ($dim_api->getConfiguredCustomDimensionsHavingScope($id_site, 'visit') as $d) {
                    $have[(string) ($d['name'] ?? '')] = (int) ($d['idcustomdimension'] ?? 0);
                }
                $map = [];
                foreach (['SA Viewport', 'SA Connection', 'SA Timezone'] as $label) {
                    if (isset($have[$label]) && $have[$label] > 0) {
                        $map[$label] = $have[$label];
                        continue;
                    }
                    try {
                        $map[$label] = (int) $dim_api->configureNewCustomDimension($id_site, $label, 'visit', 1, [], true);
                    } catch (\Throwable $e) {
                        error_log('[sa-matomo-deep] dim ' . $label . ': ' . $e->getMessage());
                    }
                }
                update_option('sa_matomo_deep_dims', $map, false);
            }
        });
    } catch (\Throwable $e) {
        error_log('[sa-matomo-deep] api: ' . $e->getMessage());
    }
});

/* ----------------------------------------------------------------------- */
/* Front-end: consent + deep event tracker                                  */
/* ----------------------------------------------------------------------- */

function sa_matomo_deep_should_print(): bool
{
    if (is_admin() || !class_exists(\WpMatomo\Settings::class)) {
        return false;
    }
    // Keep owner/staff browsing out of customer stats (matches sa-analytics).
    if (function_exists('is_user_logged_in') && is_user_logged_in() && current_user_can('manage_options')) {
        return false;
    }
    return true;
}

// Before the Matomo tracking code (footer priority 10): config object only.
add_action('wp_footer', static function (): void {
    if (!sa_matomo_deep_should_print()) {
        return;
    }
    $dims = (array) get_option('sa_matomo_deep_dims', []);
    $cfg  = [
        'geoUrl'    => '/?wc-ajax=sa_geo_resolve',
        'clarityId' => sa_matomo_deep_clarity_id(),
        'dims'      => [
            'viewport'   => (int) ($dims['SA Viewport'] ?? 0),
            'connection' => (int) ($dims['SA Connection'] ?? 0),
            'timezone'   => (int) ($dims['SA Timezone'] ?? 0),
        ],
        'isCheckout' => function_exists('is_checkout') && is_checkout() && !is_wc_endpoint_url('order-received'),
        'isCart'     => function_exists('is_cart') && is_cart(),
        'privacyUrl' => function_exists('get_privacy_policy_url') ? (string) get_privacy_policy_url() : '',
    ];
    echo '<script>window.SA_DEEP=' . wp_json_encode($cfg) . ';' . "\n";
    // Grant cookie consent BEFORE Matomo's trackPageView when the decision is already
    // known from cookies (returning visitor or non-UK/EU region). The async region
    // lookup + banner live in the later script.
    echo <<<'JSEOF'
window._paq=window._paq||[];(function(){var g=function(n){var m=document.cookie.match(new RegExp("(?:^|; )"+n+"=([^;]*)"));return m?decodeURIComponent(m[1]):""};var EU="AT BE BG HR CY CZ DK EE FI FR DE GR HU IS IE IT LV LI LT LU MT NL NO PL PT RO SK SI ES SE GB CH".split(" ");var cc=g("sa_geo_cc").toUpperCase(),d=g("sa_tconsent");if(cc&&EU.indexOf(cc)===-1){window._paq.push(["rememberCookieConsentGiven"]);window.SA_CONSENT_GRANTED=1;}else if(d==="1"){window._paq.push(["rememberCookieConsentGiven"]);window.SA_CONSENT_GRANTED=1;}})();
JSEOF;
    echo "</script>" . "\n";
}, 5);

// After the Matomo tracking code: consent logic + event tracker.
add_action('wp_footer', static function (): void {
    if (!sa_matomo_deep_should_print()) {
        return;
    }
    ?>
<script>
(function () {
  "use strict";
  var _paq = window._paq = window._paq || [];
  var CFG = window.SA_DEEP || {};
  var EU = ["AT","BE","BG","HR","CY","CZ","DK","EE","FI","FR","DE","GR","HU","IS","IE","IT","LV","LI","LT","LU","MT","NL","NO","PL","PT","RO","SK","SI","ES","SE","GB","CH"];

  function gc(n){var m=document.cookie.match(new RegExp("(?:^|; )"+n.replace(/[.$?*|{}()\[\]\\\/+^]/g,"\\$&")+"=([^;]*)"));return m?decodeURIComponent(m[1]):"";}
  function sc(n,v,days){var e=new Date(Date.now()+days*864e5);document.cookie=n+"="+encodeURIComponent(v)+";expires="+e.toUTCString()+";path=/;SameSite=Lax";}
  function ev(cat,act,name,val){try{_paq.push(["trackEvent",cat,act,name||"",val||0]);}catch(e){}}
  function txt(el){return ((el&&el.textContent)||"").replace(/\s+/g," ").trim().slice(0,80);}
  function dim(i,v){if(i>0&&v){_paq.push(["setCustomDimension",i,String(v).slice(0,150)]);}}

  /* ---- Consent (UK/EU banner, everyone else auto-grants cookie consent) ---- */
  var consentDone=false;
  function grantCookies(){
    if(consentDone||window.SA_CONSENT_GRANTED)return;consentDone=true;
    _paq.push(["rememberCookieConsentGiven"]);
    if(CFG.clarityId&&window.clarity){try{window.clarity("consent");}catch(e){}}
  }
  function denyCookies(){ _paq.push(["forgetCookieConsentGiven"]); if(window.clarity){try{window.clarity("consent",false);}catch(e){}} }
  function hideBanner(){var b=document.getElementById("sa-consent");if(b){b.parentNode.removeChild(b);}}
  function showBanner(){
    if(document.getElementById("sa-consent"))return;
    var d=document.createElement("div");
    d.id="sa-consent";
    d.setAttribute("style","position:fixed;left:0;right:0;bottom:0;z-index:999999;background:#101828;color:#fff;padding:14px 16px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:center;font:14px/1.5 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;box-shadow:0 -2px 12px rgba(0,0,0,.35)");
    var t=document.createElement("span");
    t.textContent="We use cookies for analytics and to improve your shopping experience.";
    if(CFG.privacyUrl){var a=document.createElement("a");a.href=CFG.privacyUrl;a.textContent=" Privacy policy";a.setAttribute("style","color:#7dd3fc");t.appendChild(a);}
    var ok=document.createElement("button");ok.type="button";ok.textContent="Accept";
    ok.setAttribute("style","background:#00b0f0;border:0;color:#00111a;font-weight:700;padding:9px 20px;border-radius:8px;cursor:pointer");
    var no=document.createElement("button");no.type="button";no.textContent="Decline";
    no.setAttribute("style","background:transparent;border:1px solid #98a2b3;color:#e4e7ec;padding:9px 20px;border-radius:8px;cursor:pointer");
    ok.onclick=function(){sc("sa_tconsent","1",365);grantCookies();hideBanner();};
    no.onclick=function(){sc("sa_tconsent","0",365);denyCookies();hideBanner();};
    d.appendChild(t);d.appendChild(ok);d.appendChild(no);
    (document.body||document.documentElement).appendChild(d);
  }
  function applyConsent(cc){
    cc=(cc||"").toUpperCase();
    if(EU.indexOf(cc)===-1){grantCookies();return;}
    var decided=gc("sa_tconsent");
    if(decided==="1"){grantCookies();return;}
    if(decided==="0"){denyCookies();return;}
    showBanner();
  }
  try{
    var cc=gc("sa_geo_cc");
    if(/^[A-Za-z]{2}$/.test(cc)){applyConsent(cc);}
    else if(CFG.geoUrl){
      var x=new XMLHttpRequest();
      x.open("GET",CFG.geoUrl+(CFG.geoUrl.indexOf("?")>-1?"&":"?")+"_="+Date.now(),true);
      x.onreadystatechange=function(){if(x.readyState===4&&x.status===200){try{applyConsent(JSON.parse(x.responseText).country||"");}catch(e){}}};
      x.send(null);
    }
  }catch(e){}

  /* ---- Device detail: once per session ---- */
  try{
    if(!window.sessionStorage||!sessionStorage.getItem("sa_dev")){
      var vp=window.innerWidth+"x"+window.innerHeight;
      var scr=window.screen?screen.width+"x"+screen.height:"";
      var conn=(navigator.connection&&(navigator.connection.effectiveType||navigator.connection.type))||"";
      var tz="";try{tz=Intl.DateTimeFormat().resolvedOptions().timeZone||"";}catch(e){}
      if(CFG.dims){dim(CFG.dims.viewport,vp);dim(CFG.dims.connection,conn);dim(CFG.dims.timezone,tz);}
      ev("Device","Viewport",vp);
      if(scr)ev("Device","Screen",scr);
      if(conn)ev("Device","Connection",conn);
      if(tz)ev("Device","Timezone",tz);
      ev("Device","Language",navigator.language||"");
      ev("Device","Touch",("ontouchstart" in window)?"yes":"no");
      if(window.sessionStorage)sessionStorage.setItem("sa_dev","1");
    }
  }catch(e){}

  /* ---- Scroll depth ---- */
  try{
    var marks={25:0,50:0,75:0,100:0};
    var onScroll=function(){
      var h=document.documentElement;
      var max=h.scrollHeight-h.clientHeight;
      if(max<=0)return;
      var p=Math.min(100,Math.round((window.scrollY||h.scrollTop)/max*100));
      for(var m in marks){if(!marks[m]&&p>=+m){marks[m]=1;ev("Engagement","Scroll",m+"%",+m);}}
      if(marks[100])window.removeEventListener("scroll",onScroll);
    };
    window.addEventListener("scroll",onScroll,{passive:true});
  }catch(e){}

  /* ---- Click + form events (delegated) ---- */
  document.addEventListener("click",function(e){
    try{
      var el=e.target&&e.target.closest?e.target.closest("a,button"):null;
      if(!el)return;
      var href=(el.getAttribute&&el.getAttribute("href"))||"";
      var cls=(" " +(el.className&&el.className.baseVal!==undefined?el.className.baseVal:el.className)+" ").toLowerCase();
      var label=txt(el)||href.slice(0,80);
      if(/wa\.me|chat\.whatsapp\.com|api\.whatsapp\.com/.test(href))return ev("Contact","WhatsApp",label);
      if(href.indexOf("tel:")===0)return ev("Contact","Phone",href.slice(4,24));
      if(href.indexOf("mailto:")===0)return ev("Contact","Email",href.slice(7,80));
      if(/instagram\.com/.test(href))return ev("Contact","Instagram",label);
      if(cls.indexOf(" add_to_cart_button ")>-1)return ev("Cart","Add",label||el.getAttribute("data-product_id")||"");
      if(cls.indexOf(" single_add_to_cart_button ")>-1)return ev("Cart","Add",label);
      if(cls.indexOf(" sa-btn--buy ")>-1||cls.indexOf(" buy-now ")>-1)return ev("Cart","Buy now",label);
      if(cls.indexOf(" sa-pf__group--vehicle ")>-1||cls.indexOf(" garage ")>-1||cls.indexOf(" vehicle ")>-1)return ev("Garage","Click",label);
      if(cls.indexOf(" wc-layered-nav ")>-1||el.closest(".widget_layered_nav,.woocommerce-widget-layered-nav"))return ev("Shop","Filter",label);
      if(el.closest("#place_order")||el.id==="place_order")return ev("Checkout","Place order click",label);
    }catch(err){}
  },true);

  document.addEventListener("change",function(e){
    try{
      var el=e.target;
      if(el&&el.name==="orderby"){ev("Shop","Sort",el.value||"");}
    }catch(err){}
  },true);

  var formStarted=[];
  function formName(f){return f.getAttribute("name")||f.id||(f.className&&String(f.className).split(" ")[0])||(f.getAttribute("action")||"").split("?")[0].split("/").filter(Boolean).pop()||"form";}
  function isSearch(f){var a=(f.getAttribute("action")||"")+f.className;return /search|\/\?s=/.test(a);}
  document.addEventListener("submit",function(e){
    try{
      var f=e.target;if(!f||!f.tagName||f.tagName!=="FORM"||isSearch(f))return;
      ev("Form","Submit",formName(f));
    }catch(err){}
  },true);
  document.addEventListener("input",function(e){
    try{
      var f=e.target&&e.target.closest?e.target.closest("form"):null;
      if(!f||isSearch(f))return;
      var n=formName(f);
      if(formStarted.indexOf(n)===-1){formStarted.push(n);ev("Form","Start",n);}
    }catch(err){}
  },true);

  /* ---- Checkout + cart page context ---- */
  if(CFG.isCheckout)ev("Checkout","View",location.pathname);
  if(CFG.isCart)ev("Cart","View",location.pathname);
})();
</script>
<?php
}, 20);

/* ---- Clarity (only when a project ID is configured; async, after consent rules) ---- */
add_action('wp_head', static function (): void {
    $id = sa_matomo_deep_clarity_id();
    if ($id === '' || !sa_matomo_deep_should_print()) {
        return;
    }
    printf(
        '<script async src="https://www.clarity.ms/tag/%s"></script>' . "\n",
        esc_attr($id)
    );
}, 3);
