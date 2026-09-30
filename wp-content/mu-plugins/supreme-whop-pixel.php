<?php
/**
 * Plugin Name: Supreme Whop Pixel
 * Description: Injects the Whop attribution pixel (biz_9VJcCdK7G30L63) on every front-end page via wp_head.
 * Version: 1.0.0
 * Author: Supreme Autoparts
 *
 * Snippet source: https://docs.whop.com/developer/ads/pixel
 * Dashboard: https://whop.com/dashboard/biz_9VJcCdK7G30L63/pixel
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Company / account ID for Whop Ads attribution.
 */
const SA_WHOP_PIXEL_BIZ_ID = 'biz_9VJcCdK7G30L63';

/**
 * Print Whop pixel in <head> on public pages only.
 */
add_action('wp_head', static function (): void {
    if (is_admin()) {
        return;
    }

    // Skip REST / AJAX / cron / feeds — front HTML only.
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return;
    }
    if (defined('DOING_AJAX') && DOING_AJAX) {
        return;
    }
    if (defined('DOING_CRON') && DOING_CRON) {
        return;
    }
    if (is_feed()) {
        return;
    }

    $biz = SA_WHOP_PIXEL_BIZ_ID;
    // Official Whop pixel bootstrap + setScope + page track (docs.whop.com/developer/ads/pixel).
    echo <<<HTML
<!-- Whop Pixel (biz={$biz}) -->
<script>
!function(w,d,s,u,n,a,b){if(w[n])return;a=w[n]={q:[],t:+new Date,s:[],o:u,track:function(){a.q.push([+new Date].concat([].slice.call(arguments)))},setScope:function(){a.s=[].slice.call(arguments).filter(function(x){return typeof x==="string"});a.q.push([+new Date,"setScope"].concat(a.s))},scope:function(){var c=[].slice.call(arguments);return{track:function(){a.q.push([+new Date].concat([].slice.call(arguments)).concat([{__scope:c}]))}}}};b=d.createElement(s);b.async=1;b.src=u+"/s.js";d.getElementsByTagName(s)[0].parentNode.insertBefore(b,d.getElementsByTagName(s)[0])}(window,document,"script","https://t.whop.tw","whop");
whop.setScope("{$biz}");
whop.track("page");
</script>

HTML;
}, 5);
