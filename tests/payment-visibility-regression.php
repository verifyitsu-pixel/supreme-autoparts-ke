<?php
define('ABSPATH',__DIR__);function add_action(...$a){}function get_stylesheet_directory_uri(){return 'https://store.invalid/theme';}function esc_url($s){return htmlspecialchars($s,ENT_QUOTES);}function esc_attr($s){return htmlspecialchars($s,ENT_QUOTES);}function esc_html($s){return htmlspecialchars($s,ENT_QUOTES);}function home_url($s){return 'https://store.invalid'.$s;}
class GM{function get_available_payment_gateways(){return $GLOBALS['available'];}}class W{function payment_gateways(){return new GM;}}function WC(){return new W;}
require __DIR__.'/../wp-content/mu-plugins/sa-payment-visibility.php';
$GLOBALS['available']=['pesapal'=>true];ob_start();sa_visible_missing_payment_cards();$h=ob_get_clean();if($h!=='')throw new Exception('Unsolicited missing cards restored');echo "PASS prior presentation retained below Whop cap; no unsolicited cards\n";
