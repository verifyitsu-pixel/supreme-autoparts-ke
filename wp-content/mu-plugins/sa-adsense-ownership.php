<?php
/**
 * Plugin Name: SA AdSense Ownership
 * Description: Non-executing publisher ownership tag. Does not load ads or tracking.
 */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }
add_action('wp_head', static function (): void {
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if (is_admin() || !in_array($host, ['supremeautoparts.co.ke', 'www.supremeautoparts.co.ke'], true)) { return; }
    echo '<meta name="google-adsense-account" content="ca-pub-1909332555044016">' . "\n";
}, 2);

/** Preparation only: no advertising or consent libraries are loaded here. */
add_filter('the_content', static function ($content) {
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if (is_admin() || !in_array($host, ['supremeautoparts.co.ke', 'www.supremeautoparts.co.ke'], true) || !is_page(['privacy-policy', 'cookie-policy'])) { return $content; }
    return $content . '<section id="google-advertising"><h2>Planned Google advertising</h2><p>Google AdSense advertising is not enabled on this site. We are preparing an optional advertising setup for Guides. Shopping, product, quote, booking, cart, checkout and account pages are excluded from that planned setup.</p><p>If Google advertising is enabled later, third-party vendors, including Google, may use cookies and similar identifiers to serve and measure ads based on your visits to this site and other websites. Google advertising cookies may let Google and permitted partners personalise ads. Before any activation, we will provide the applicable consent controls and identify the partners used. Declining optional advertising will not affect shopping or booking.</p><p>You can manage personalised Google advertising through <a href="https://myadcenter.google.com/">Google advertising choices</a>. You can also block or delete cookies in your browser. Read <a href="https://policies.google.com/technologies/partner-sites">how Google uses information from partner sites</a> and <a href="https://policies.google.com/technologies/ads">Google advertising technologies</a>.</p></section>';
}, 99);
