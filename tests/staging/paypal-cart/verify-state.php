<?php
if (!defined('SA_SYNTHETIC_STAGING') || SA_SYNTHETIC_STAGING !== true) throw new RuntimeException('Not staging');
$n=0;$check=static function($ok,$message)use(&$n){if(!$ok)throw new RuntimeException($message);$n++;echo "PASS $message\n";};
$p=wc_get_product((int)get_option('sa_synthetic_fixture_product_id'));
$check($p && $p->get_sku()==='SYNTHETIC-PAYPAL-001','only synthetic product');
$check($p->get_stock_quantity()===10,'synthetic stock unchanged after denied checkout');
$check(count(wc_get_orders(['limit'=>2]))===0,'no orders created with empty keys');
$check(get_option('woocommerce_currency')==='USD','fixture currency USD');
$check(get_option('woocommerce-ppcp-data-common')['client_id']==='' && get_option('woocommerce-ppcp-data-common')['client_secret']==='','sandbox keys empty');
$check(get_option('woocommerce-ppcp-data-common')['sandbox_merchant']===true,'sandbox environment forced');
$check(get_option('woocommerce_ppcp-gateway_settings')['enabled']==='no','PayPal gateway disabled with empty keys');
$check(is_wp_error(wp_remote_post('https://api-m.paypal.com/v1/oauth2/token')),'live PayPal HTTP denied');
$check(is_wp_error(wp_remote_post('https://api-m.sandbox.paypal.com/v1/oauth2/token')),'sandbox HTTP denied while keys empty');
$check(wp_mail('fixture@example.invalid','Fictional staging check','Do not send')===false,'all outgoing mail suppressed');
$check(get_option('woocommerce_pesapal_settings')['enabled']==='no','no staging Pesapal gateway');
$check(!in_array('supreme-autoparts-core/supreme-autoparts-core.php',(array)get_option('active_plugins'),true),'production core/fulfilment not activated');
$check(DISABLE_WP_CRON===true,'cron disabled');
$check(WP_ENVIRONMENT_TYPE==='staging','explicit staging environment');
echo "PASS $n native staging isolation checks\n";
