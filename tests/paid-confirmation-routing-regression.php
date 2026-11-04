<?php
/** Static safety contracts. Actual Woo CPT/HPOS fixtures live in paid-confirmation-runtime.
 * No WordPress bootstrap, mail, network or order mutations in this test.
 */
$base=__DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/';
$s=file_get_contents($base.'order-emails.php');$n=0;
foreach(["sa_core_order_is_verified_paid", "sa_core_paid_confirmation_sent", "GET_LOCK(%s, 0)", "RELEASE_LOCK(%s)", "sa_core_confirmation_fresh_order", "get_changes()", "'_sa_timeline'", "'WC_Email_Customer_Processing_Order'", "woocommerce_mail_callback", "sa_confirmation_render_state", "PHP_INT_MIN, 3", "_sa_paid_confirmation_pending", "\$accepted === false", "finally { sa_core_confirmation_unlock(\$order); }"] as $token){if(strpos($s,$token)===false)throw new Exception('Missing contract '.$token);$n++;}
$a=strpos($s,"add_filter('woocommerce_email_enabled_customer_processing_order'");$b=strpos($s,'function sa_core_mark_paid_confirmation',$a);$enabled=substr($s,$a,$b-$a);
if(strpos($enabled,'sa_core_confirmation_lock(')!==false)throw new Exception('Getter owns lock');$n++;
$l=file_get_contents($base.'order-lifecycle.php');if(strpos($l,"\$to === 'completed' && function_exists('sa_core_paid_confirmation_sent')")===false)throw new Exception('First completed bypass missing');$n++;
$p=file_get_contents($base.'customer-order-display.php');if(strpos($p,"\$key = 'WC_Email_Customer_Processing_Order'; // Preview the first paid confirmation")===false)throw new Exception('First confirmation preview missing');$n++;
echo "PASS $n paid-confirmation source contracts; actual Woo runtime fixtures are separate.\n";
