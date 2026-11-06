<?php
require __DIR__.'/payment-visibility-regression.php';
class LimitCart{public $total=2500;function get_total($ctx){return $this->total;}}
// Read the production boundary function verbatim, without loading unrelated hooks.
$s=file_get_contents(__DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/gateways-retired.php');preg_match('/    function sa_whop_over_cap\(\): bool \{.*?\n    \}/s',$s,$m);$fn=str_replace("WC()->cart","\$GLOBALS['limit_cart']",$m[0]);eval($fn);$GLOBALS['limit_cart']=new LimitCart;
foreach([[2499.99,false],[2500,false],[2500.01,true],[3000,true]] as [$amount,$want]){$GLOBALS['limit_cart']->total=$amount;if(sa_whop_over_cap()!==$want)throw new Exception('Boundary '.$amount);$GLOBALS['available']=['pesapal'=>true,'paystack'=>true];ob_start();sa_visible_missing_payment_cards();$h=ob_get_clean();if((strpos($h,'background:#eee')!==false)!==$want)throw new Exception('Grey '.$amount);if($want&&strpos($h,'Above the USD 2,500 Whop limit')===false)throw new Exception('Reason');}
$s=file_get_contents(__DIR__.'/../wp-content/themes/supreme-autoparts/woocommerce/checkout/payment.php');if(strpos($s,'sa_visible_missing_payment_cards($id);')===false)throw new Exception('AJAX fragment');
$s=file_get_contents(__DIR__.'/../wp-content/plugins/whop-payments/includes/class-wc-gateway-whop.php');$g=strpos($s,'$amount > 2500');$c=strpos($s,'$client = new Whop_Api_Client(');if($g===false||$c===false||$g>$c)throw new Exception('Guard late');
// Execute the exact gateway amount guard before any provider client creation.
function __($s,$domain){return $s;}function wc_add_notice($msg,$type){$GLOBALS['limit_notice']=$msg;}
$src=file_get_contents(__DIR__.'/../wp-content/plugins/whop-payments/includes/class-wc-gateway-whop.php');preg_match('/        if \(!is_finite\(\$amount\) \|\| \$amount > 2500\) \{.*?\n        \}/s',$src,$guard);
if(empty($guard[0]))throw new Exception('Missing executable guard');
$probe=eval('return function($amount){'.$guard[0].';return ["result"=>"continue"];};');
if($probe(2500)['result']!=='continue'||$probe(2500.01)['result']!=='failure'||$probe(INF)['result']!=='failure')throw new Exception('Gateway guard drift');
echo "PASS 2499.99 / exact2500 /2500.01 /3000 grey-state and warning; fragment/serverguard contracts\n";
$GLOBALS['limit_cart']->total=2500.01;ob_start();sa_visible_missing_payment_cards();$h=ob_get_clean();file_put_contents('/tmp/limit100.html','<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font:16px Arial;margin:20px}</style>'.str_replace('https://store.invalid/theme','file:///tmp/paystack-work/supreme/wp-content/themes/supreme-autoparts',$h));
