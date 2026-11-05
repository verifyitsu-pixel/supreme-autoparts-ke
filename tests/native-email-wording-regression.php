<?php
/** Copy-only native overrides: hooks, security/link expressions and custom content stay intact. */
$root=__DIR__.'/../wp-content/themes/supreme-autoparts/woocommerce/emails/';$n=0;
foreach(['customer-on-hold-order.php','customer-failed-order.php','customer-note.php','customer-reset-password.php'] as $f){
 $s=file_get_contents($root.$f);
 foreach(['woocommerce_email_header','woocommerce_email_footer','$additional_content','wp_kses_post( wpautop( wptexturize( $additional_content ) ) )'] as $token){if(strpos($s,$token)===false)throw new Exception($f.' lost '.$token);$n++;}
 if($f!=='customer-reset-password.php')foreach(['woocommerce_email_order_details','woocommerce_email_order_meta','woocommerce_email_customer_details'] as $token){if(strpos($s,$token)===false)throw new Exception($f.' lost '.$token);$n++;}
}
$s=file_get_contents($root.'customer-reset-password.php');
foreach(["'key' => \$reset_key", "'id' => \$user_id", "'login' => rawurlencode( \$user_login )", "wc_get_endpoint_url( 'lost-password', '', wc_get_page_permalink( 'myaccount' ) )", 'ignore this email'] as $token){if(strpos($s,$token)===false)throw new Exception('Reset lost '.$token);$n++;}
$s=file_get_contents($root.'customer-note.php');if(strpos($s,'wc_wptexturize_order_note( $customer_note )')===false)throw new Exception('Customer note processing changed');$n++;
$s=file_get_contents($root.'customer-failed-order.php');if(strpos($s,'If your bank shows a debit, contact us before trying again.')===false)throw new Exception('Failed debit caution');$n++;
echo "PASS $n native-purpose copy/security/hook contracts. Runtime renders tested separately, no send.\n";
