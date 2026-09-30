<?php
/**
 * Plugin Name: Supreme Meta Pixel
 * Description: Injects the Meta (Facebook) Pixel (1455607103130157) sitewide — script in wp_head, noscript after body open.
 * Version: 1.0.0
 * Author: Supreme Autoparts
 *
 * Pixel ID: 1455607103130157
 * Events Manager: https://business.facebook.com/events_manager2
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Meta / Facebook Pixel ID.
 */
const SA_META_PIXEL_ID = '1455607103130157';

/**
 * True when we should inject the pixel (front HTML only).
 */
function sa_meta_pixel_should_inject(): bool
{
    if (is_admin()) {
        return false;
    }
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return false;
    }
    if (defined('DOING_AJAX') && DOING_AJAX) {
        return false;
    }
    if (defined('DOING_CRON') && DOING_CRON) {
        return false;
    }
    if (function_exists('is_feed') && is_feed()) {
        return false;
    }

    return true;
}

/**
 * Meta Pixel JS bootstrap in <head>.
 */
add_action('wp_head', static function (): void {
    if (!sa_meta_pixel_should_inject()) {
        return;
    }

    $id = SA_META_PIXEL_ID;
    echo <<<HTML
<!-- Meta Pixel Code -->
<script>
!function(f,b,e,v,n,t,s)
{if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};
if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];
s.parentNode.insertBefore(t,s)}(window, document,'script',
'https://connect.facebook.net/en_US/fbevents.js');
fbq('init', '{$id}');
fbq('track', 'PageView');
</script>
<!-- End Meta Pixel Code -->

HTML;
}, 5);

/**
 * Meta Pixel <noscript> fallback immediately after <body>.
 */
add_action('wp_body_open', static function (): void {
    if (!sa_meta_pixel_should_inject()) {
        return;
    }

    $id = SA_META_PIXEL_ID;
    echo <<<HTML
<!-- Meta Pixel noscript -->
<noscript><img height="1" width="1" style="display:none"
src="https://www.facebook.com/tr?id={$id}&ev=PageView&noscript=1"
/></noscript>

HTML;
}, 5);
