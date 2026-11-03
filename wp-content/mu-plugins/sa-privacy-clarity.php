<?php
/**
 * Plugin Name: SA Privacy and Clarity
 * Description: Existing Microsoft Clarity recording and regional consent, independent of Matomo.
 */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }
function sa_privacy_clarity_id(): string {
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    return in_array($host, ['www.supremeautoparts.co.ke', 'supremeautoparts.co.ke'], true) ? 'yzuhlpytbt' : '';
}
function sa_privacy_should_print(): bool {
    return !is_admin() && !(function_exists('is_user_logged_in') && is_user_logged_in() && current_user_can('manage_options'));
}
add_action('wp_head', static function (): void {
    if (!sa_privacy_should_print() || sa_privacy_clarity_id() === '') { return; }
    printf('<script>(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);})(window,document,"clarity","script","%s");</script>', esc_attr(sa_privacy_clarity_id()));
}, 3);
add_action('wp_footer', static function (): void {
    if (!sa_privacy_should_print()) { return; }
    $cfg = ['geoUrl' => '/?wc-ajax=sa_geo_resolve', 'clarityId' => sa_privacy_clarity_id(), 'privacyUrl' => function_exists('get_privacy_policy_url') ? (string) get_privacy_policy_url() : ''];
    echo '<script>window.SA_PRIVACY=' . wp_json_encode($cfg) . ';</script>';
    ?>
<script>
(function () {
  "use strict";
  var CFG = window.SA_PRIVACY || {};
  var EU = "AT BE BG HR CY CZ DK EE FI FR DE GR HU IS IE IT LV LI LT LU MT NL NO PL PT RO SK SI ES SE GB CH".split(" ");
  function gc(n){var m=document.cookie.match(new RegExp("(?:^|; )"+n.replace(/[.$?*|{}()\[\]\\\/+^]/g,"\\$&")+"=([^;]*)"));return m?decodeURIComponent(m[1]):"";}
  function sc(n,v,days){var e=new Date(Date.now()+days*864e5);document.cookie=n+"="+encodeURIComponent(v)+";expires="+e.toUTCString()+";path=/;SameSite=Lax";}

  /* ---- Consent (UK/EU banner, everyone else auto-grants cookie consent) ---- */
  var consentDone=false;
  if(window.SA_CONSENT_GRANTED&&CFG.clarityId&&window.clarity){try{window.clarity("consent");}catch(e){}}
  function grantCookies(){
    if(consentDone||window.SA_CONSENT_GRANTED)return;consentDone=true;
    window.SA_ANALYTICS_CONSENT=true;window.dispatchEvent(new Event("sa:analytics-consent-granted"));
    if(CFG.clarityId&&window.clarity){try{window.clarity("consent");}catch(e){}}
  }
  function denyCookies(){ window.SA_ANALYTICS_CONSENT=false;window.dispatchEvent(new Event("sa:analytics-consent-denied"));if(window.clarity){try{window.clarity("consent",false);}catch(e){}} }
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
      x.onreadystatechange=function(){if(x.readyState===4&&x.status===200){try{var rc=(JSON.parse(x.responseText).country||"").toUpperCase();if(/^[A-Z]{2}$/.test(rc)){sc("sa_geo_cc",rc,30);}applyConsent(rc);}catch(e){}}};
      x.send(null);
    }
  }catch(e){}

})();
</script>
<?php
}, 20);
