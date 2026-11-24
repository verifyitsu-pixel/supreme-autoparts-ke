<?php
/**
 * Plugin Name: SA AdSense Ownership and Guide Setup
 * Description: Publisher ownership, guide-only opt-in setup and ad disclosures. No automatic ads.
 */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }
function sa_ads_host(): bool {
    return in_array(strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')), ['supremeautoparts.co.ke', 'www.supremeautoparts.co.ke'], true);
}
add_action('wp_head', static function (): void {
    if (is_admin() || !sa_ads_host()) { return; }
    echo '<meta name="google-adsense-account" content="ca-pub-1909332555044016">' . "\n";
}, 2);
add_filter('the_content', static function ($content) {
    if (is_admin() || !sa_ads_host() || !is_page(['privacy-policy', 'cookie-policy'])) { return $content; }
    return $content . '<section id="google-advertising"><h2>Optional Google advertising on Guides</h2><p>We are setting up Google AdSense on our Guides page. Site review is pending and no ad placements are enabled. Shopping, product, quote, booking, cart, checkout and account pages do not receive this AdSense setup.</p><p>Only if you choose Enable Google ad setup on Guides, your browser connects to Google and loads its advertising and consent software. Google receives technical information such as your IP address, browser information and the guide URL. Google may access existing cookies; ad requests stay paused during setup. Declining this choice does not affect browsing, shopping or booking.</p><p>If ads are later enabled, Google and permitted advertising partners may use cookies and similar identifiers to serve and measure ads based on visits to this and other websites. Where required, Google\'s consent message asks for your choices before ad requests. Our configured European consent partner list is limited to Google. You can manage or withdraw your setup choice using Google ad settings on Guides. Withdrawal stops future setup loads; it cannot undo information already sent.</p><p>Read <a href="https://www.google.com/policies/technologies/partner-sites/">how Google uses information from partner sites</a>, <a href="https://policies.google.com/technologies/ads">Google advertising technologies</a> and <a href="https://myadcenter.google.com/">Google advertising choices</a>. You can also block or delete cookies in your browser. No customer names, email addresses, order details or payment information are deliberately included in this AdSense setup.</p></section>';
}, 99);
add_action('wp_footer', static function (): void {
    if (is_admin() || !sa_ads_host() || !is_page('guides') || !empty($_GET) || (function_exists('is_user_logged_in') && is_user_logged_in())) { return; }
    ?>
<section id="sa-ad-settings" aria-label="Google ad settings" style="max-width:960px;margin:24px auto;padding:16px;border:1px solid #cbd5e1;border-radius:8px;color:#172b3a;background:#fff;font:16px/1.6 system-ui,sans-serif">
<h2 style="font-size:20px;margin:0 0 8px">Google ad settings</h2>
<p id="sa-ad-state">Google ad setup is off until you choose. Site review is pending; no ad placements are enabled.</p>
<p>Enabling connects this guide to Google advertising and consent software, which receives technical browser data and may read existing cookies. Ad requests remain paused. Declining does not affect the site. <a href="/privacy-policy/#google-advertising">Advertising privacy details</a>.</p>
<div style="display:flex;flex-wrap:wrap;gap:12px"><button type="button" id="sa-ad-enable" style="padding:10px 16px;border:1px solid #172b3a;background:#fff;color:#172b3a;border-radius:6px;font:inherit;cursor:pointer">Enable Google ad setup</button><button type="button" id="sa-ad-decline" style="padding:10px 16px;border:1px solid #172b3a;background:#fff;color:#172b3a;border-radius:6px;font:inherit;cursor:pointer">Decline / turn off</button></div>
</section>
<script>
(function(){
'use strict';
var key='sa_ads_setup_v1',loaded=false,state=document.getElementById('sa-ad-state');
function read(){try{var x=JSON.parse(localStorage.getItem(key)||'null');return x&&Date.now()-x.at<15552000000?x.choice:'';}catch(e){return '';}}
function store(choice){try{localStorage.setItem(key,JSON.stringify({choice:choice,at:Date.now()}));}catch(e){}}
function enable(){
  if(loaded)return;
  if(location.search||location.hash||location.protocol!=='https:')return;
  if(document.referrer){try{var r=new URL(document.referrer);if(r.search||r.hash||/checkout|cart|my-account|order-pay|order-received|password|token|secret|@|%40/i.test(r.pathname))return;}catch(e){return;}}
  loaded=true;
  window.adsbygoogle=window.adsbygoogle||[];
  window.adsbygoogle.pauseAdRequests=1;
  window.adsbygoogle.requestNonPersonalizedAds=1;
  var s=document.createElement('script');s.async=true;s.crossOrigin='anonymous';s.id='sa-ad-library';s.referrerPolicy='strict-origin-when-cross-origin';s.src='https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-1909332555044016';document.head.appendChild(s);
  state.textContent='Google ad setup is enabled. Ad requests remain paused while site review is pending.';
}
document.getElementById('sa-ad-enable').onclick=function(){store('yes');enable();};
document.getElementById('sa-ad-decline').onclick=function(){store('no');if(window.adsbygoogle)window.adsbygoogle.pauseAdRequests=1;if(loaded){location.reload();return;}state.textContent='Google ad setup is off. No AdSense script is loaded by this guide.';};
if(read()==='yes')enable();else if(read()==='no')state.textContent='Google ad setup is off. You can change your choice here.';
})();
</script>
<?php
}, 40);
