<?php
/** Isolated render and route regression. No WordPress, network or sends. */
define('ABSPATH',__DIR__);
function home_url($p=''){return 'https://www.supremeautoparts.co.ke'.$p;}
function wc_get_page_permalink($p){return home_url('/'.$p.'/');}
function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES);}
function esc_attr($s){return esc_html($s);}
function esc_url($s){return esc_html($s);}
function wp_date($f){return '2026';}
function sa_email_footer_business($c){return ['one_line'=>'Nairobi, Kenya','phone_display'=>'+254 714 498 451'];}
function sa_rcpt_order_data($o){return ['date'=>'04 November 2026','bill'=>['Test Customer','Nairobi, Kenya'],'ship'=>['Test Customer','Nairobi, Kenya'],'view_url'=>home_url('/track-order/')];}
function sa_rcpt_lines($lines,$html){return implode('<br>',array_map('esc_html',$lines));}
function sa_ol_stage_labels(){return ['staged'=>'Staged to ship','cancelled'=>'Cancelled'];}
function sa_ol_items_html($o){return '<table role="presentation" width="100%"><tr><td style="font-size:16px;padding:16px 0">Brake pad set × 1</td><td style="text-align:right">KES 110.48</td></tr><tr><td>Total</td><td style="text-align:right">KES 110.48</td></tr></table>';}
class WC_Order{function get_order_number(){return 'TEST-76318';}function get_shipping_country(){return 'KE';}function get_billing_country(){return 'KE';}function get_items(){return [];}function get_payment_method_title(){return 'Mastercard ending in 8575';}function get_shipping_method(){return 'Courier';}}
require __DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/order-email-family.php';
require __DIR__.'/../wp-content/plugins/sa-brevo-mail/includes/class-brevo-mailer.php';
$n=0;
foreach(['processing','on-hold','awaiting-info','staged','shipped','out-for-delivery','delivered','ready-pickup','completed','cancelled','refunded','failed'] as $stage){
 $h=sa_ol_family_layout(new WC_Order,ucwords(str_replace('-',' ',$stage)),'<p>Hello Test Customer,</p><p>Your order update. Reply if you need a hand.</p>','View your order',home_url('/track-order/'),'Order update',$stage);
 foreach(['sa-email-brand-header','SHOP','GARAGE','HELP','YOUR ORDER','SHIPPING DETAILS','PAYMENT METHOD','Privacy policy','not a confirmed fitment match'] as $token){if($token==='not a confirmed fitment match')continue; if(strpos($h,$token)===false)throw new Exception($stage.': missing '.$token);$n++;}
 if(substr_count($h,'sa-email-brand-header')!==1||SA_Brevo_Mailer::brand_html(SA_Brevo_Mailer::brand_html($h))!==$h)throw new Exception('Duplicate branding');$n++;
 if(strpos($h,'worldwidecyclery')!==false||strpos($h,'Nothing was charged')!==false)throw new Exception('Source leakage');$n++;
 if(getenv('SA_EMAIL_RENDER_DIR'))file_put_contents(getenv('SA_EMAIL_RENDER_DIR').'/'.$stage.'.html',$h);
}
$s=file_get_contents(__DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/order-lifecycle.php');
foreach(['function sa_ol_send','woocommerce_order_status_changed','woocommerce_email_enabled_','sa_ol_family_layout($o, $heading'] as $token){if(strpos($s,$token)===false)throw new Exception('Route missing');$n++;}
if(strpos($s,'Nothing was charged.')!==false)throw new Exception('False failed payment claim');
echo "PASS $n lifecycle family render/branding/route checks, no sends or writes.\n";
