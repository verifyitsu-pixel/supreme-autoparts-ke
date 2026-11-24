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
