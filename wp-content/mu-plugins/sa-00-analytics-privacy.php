<?php
/** Plugin Name: SA Analytics URL Privacy
 * Description: Shared URL minimization for new analytics and historical dashboard output.
 */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }
function sa_tracking_path(string $url): string {
    $path = (string) parse_url($url, PHP_URL_PATH);
    $path = preg_replace('~/(order-received|order-pay|view-order|lost-password|reset-password)/[^/]+/?~i', '/$1/', $path);
    if (preg_match('~@|%40|(?:token|password|secret|nonce|wc_order_)~i', $path)) { return '/[private]'; }
    return substr($path ?: '/', 0, 300);
}
function sa_tracking_referrer(string $url): string {
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    return preg_match('/^[a-z0-9.-]+$/', $host) ? 'https://' . $host . '/' : '';
}

function sa_tracking_text(string $text): string {
    $text = preg_replace_callback('~https?://[^\s|]+~i', static function($m) { return sa_tracking_referrer($m[0]) . ltrim(sa_tracking_path($m[0]), '/'); }, $text);
    $text = preg_replace('~\?[^\s|]*~', '', $text);
    return preg_replace('~[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}~i', '[private]', $text);
}
