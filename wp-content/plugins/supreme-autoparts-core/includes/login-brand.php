<?php
/** Brand the WordPress login screen with the Supreme Autoparts lockup. */
defined('ABSPATH') || exit;
add_action('login_head', static function (): void {
    $u = esc_url(content_url('/themes/supreme-autoparts/assets/logo-light.png?v=emblem2'));
    echo '<style>#login h1 a,.login h1 a{background-image:url(' . $u . ');background-size:contain;background-position:center;width:320px;max-width:100%;height:50px;margin-bottom:24px}</style>';
});
add_filter('login_headerurl', static fn() => home_url('/'));
add_filter('login_headertext', static fn() => 'Supreme Autoparts');
