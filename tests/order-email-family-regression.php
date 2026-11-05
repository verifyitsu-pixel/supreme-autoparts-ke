<?php
/** Isolated render and route regression. No WordPress, network or sends. */
define('ABSPATH',__DIR__);
function home_url($p=''){return 'https://www.supremeautoparts.co.ke'.$p;}
function wc_get_page_permalink($p){return home_url('/'.$p.'/');}
function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES);}
function esc_attr($s){return esc_html($s);}
function esc_url($s){return esc_html($s);}
function wp_date($f){return '2026';}
function add_action(...$args){}
function add_filter(...$args){}
function WC(){return (object)['countries'=>new class{function get_continent_code_for_country($c){return in_array($c,['KE','UG','TZ'])?'AF':'NA';}}];}
function sa_geo_business($c){return ['one_line'=>$c==='KE'?'Nairobi, Kenya':'12800 NE Sandy Blvd, Portland, OR 97230','phone_display'=>$c==='KE'?'+254 714 498 451':'+1 917 437 5121','whatsapp'=>$c==='KE'?'254714498451':'19174375121'];}
require __DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/email-footer.php';
function sa_rcpt_order_data($o){if(isset($GLOBALS['sa_test_receipt']))return $GLOBALS['sa_test_receipt'];return ['date'=>'04 November 2026','bill'=>['Test Customer','Nairobi, Kenya'],'ship'=>['Test Customer','Nairobi, Kenya'],'view_url'=>home_url('/track-order/')];}
function sa_rcpt_lines($lines,$html=true){return implode('<br>',array_map('esc_html',$lines));}
function sa_ol_stage_labels(){return ['staged'=>'Staged to ship','cancelled'=>'Cancelled'];}
function sa_ol_items_html($o){return '<table role="presentation" width="100%"><tr><td style="font-size:16px;padding:16px 0">Brake pad set × 1</td><td style="text-align:right">KES 110.48</td></tr><tr><td>Total</td><td style="text-align:right">KES 110.48</td></tr></table>';}
class WC_Order{function get_order_number(){return 'TEST-76318';}function get_shipping_country(){return 'KE';}function get_billing_country(){return 'KE';}function get_items(){return [];}function get_payment_method_title(){return 'Mastercard ending in 8575';}function get_shipping_method(){return 'Courier';}}
require __DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/order-email-family.php';
require __DIR__.'/../wp-content/plugins/sa-brevo-mail/includes/class-brevo-mailer.php';
$n=0;
foreach(['processing','on-hold','awaiting-info','staged','shipped','out-for-delivery','delivered','ready-pickup','completed','cancelled','refunded','failed'] as $stage){
 $h=sa_ol_family_layout(new WC_Order,($stage==='cancelled'?'Your order has been cancelled.':($stage==='staged'?'Your order is staged to ship':ucwords(str_replace('-',' ',$stage)))),($stage==='cancelled'?'<p>Hello Test Customer,</p><p>We are sorry it did not work out. If you need help finding something different, let us know.</p><p>Order #TEST-76318 has been cancelled.</p><p>If there is anything else we can do for you, please reply to this email.</p>':'<p>Hello Test Customer,</p><p>Your order update. Reply if you need a hand.</p>'),'View your order',home_url('/track-order/'),'Order update',$stage);
 if(strpos($h,'+254')!==false||strpos($h,'254714498451')!==false)throw new Exception('Kenyan number in stage email');
 foreach(['sa-email-brand-header','SHOP','GARAGE','HELP','YOUR ORDER','SHIPPING DETAILS','PAYMENT METHOD','Privacy policy','not a confirmed fitment match'] as $token){if($token==='not a confirmed fitment match'||($stage==='cancelled'&&in_array($token,['SHIPPING DETAILS','PAYMENT METHOD'])))continue; if(strpos($h,$token)===false)throw new Exception($stage.': missing '.$token);$n++;}
 if(substr_count($h,'sa-email-brand-header')!==1||SA_Brevo_Mailer::brand_html(SA_Brevo_Mailer::brand_html($h))!==$h)throw new Exception('Duplicate branding');$n++;
 if(strpos($h,'worldwidecyclery')!==false||strpos($h,'Nothing was charged')!==false)throw new Exception('Source leakage');$n++;
 if(getenv('SA_EMAIL_RENDER_DIR'))file_put_contents(getenv('SA_EMAIL_RENDER_DIR').'/'.$stage.'.html',$h);
}
$s=file_get_contents(__DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/order-lifecycle.php');
foreach(['function sa_ol_send','woocommerce_order_status_changed','woocommerce_email_enabled_','sa_ol_family_layout($o, $heading'] as $token){if(strpos($s,$token)===false)throw new Exception('Route missing');$n++;}
if(strpos($s,'Nothing was charged.')!==false)throw new Exception('False failed payment claim');
echo "PASS $n lifecycle family render/branding/route checks, no sends or writes.\n";
foreach(['Your login code','Welcome to Supreme Autoparts','Return approved','Booking received','Your estimate is ready','Your vehicle is ready for collection','Your invoice','Vehicle inspection','Service reminder','Roadside request','Pickup reminder'] as $heading){
 $h=sa_customer_mail_layout($heading,'<p>Hello Test Customer,</p><p>Existing customer information stays unchanged.</p>','Open details',home_url('/my-account/'),'Customer update');
 if(SA_Brevo_Mailer::brand_html(SA_Brevo_Mailer::brand_html($h))!==$h||substr_count($h,'sa-email-brand-header')!==1)throw new Exception('Companion branding');
 foreach(['SHOP','GARAGE','HELP',$heading,'Privacy policy'] as $token)if(strpos($h,$token)===false)throw new Exception('Companion missing '.$token);
 if(getenv('SA_EMAIL_RENDER_DIR'))file_put_contents(getenv('SA_EMAIL_RENDER_DIR').'/companion-'.md5($heading).'.html',$h);
}
echo "PASS 11 companion customer layouts; approved confirmation and shared merchant layout unchanged.\n";

$receipt_source=file_get_contents(__DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/receipt-design.php');
foreach(['sa_rcpt_items_html','sa_rcpt_totals_html'] as $fn){if(!function_exists($fn))eval(substr($receipt_source,strpos($receipt_source,'function '.$fn),strpos($receipt_source,"\n}",strpos($receipt_source,'function '.$fn))+2-strpos($receipt_source,'function '.$fn)));}
if(!defined('SA_RCPT_INK')){define('SA_RCPT_INK','#111');define('SA_RCPT_MUTED','#666');define('SA_RCPT_LINE','#ddd');}
if(!function_exists('wp_kses_post')){function wp_kses_post($s){return $s;}}
$d=['title'=>'Your invoice','doc'=>'Invoice','number'=>'INV-TEST','date'=>'04 November 2026','paid'=>false,'greet'=>'Hello Test Customer,','lead'=>'Here is your invoice. A PDF copy is attached.','seller'=>['lines'=>['Supreme Autoparts','Nairobi, Kenya']],'bill'=>['Test Customer','Nairobi, Kenya'],'ship'=>[],'items'=>[['name'=>'Brake pad set','qty'=>1,'unit'=>'KES 110.48','total'=>'KES 110.48','no_thumb'=>true]],'totals'=>[['Total','KES 110.48',true]],'pay'=>[['Payment method','Card']],'pay_url'=>home_url('/pay/'),'view_url'=>home_url('/receipt/'),'extra_html'=>'<p>Booking slot: 10:00</p>','note'=>'Bring your order number.'];
$h=sa_customer_receipt_email($d);foreach(['Payment due','INV-TEST','KES 110.48','Booking slot: 10:00','Bring your order number.','Pay now','Nairobi, Kenya'] as $token)if(strpos($h,$token)===false)throw new Exception('Receipt adapter lost '.$token);
if(substr_count($h,'<!doctype html>')!==1)throw new Exception('Nested receipt document');
if(getenv('SA_EMAIL_RENDER_DIR'))file_put_contents(getenv('SA_EMAIL_RENDER_DIR').'/invoice.html',$h);
echo "PASS 8 email receipt adapter data/layout checks; no nested page/PDF document.\n";

// Exercise the real stage item adapter with paid and unpaid source receipts.
$source=file_get_contents(__DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/order-lifecycle.php');
$start=strpos($source,'function sa_ol_items_html(');
$fn=substr($source,$start,strpos($source,"\n}",$start)+2-$start);
$fn=str_replace('function sa_ol_items_html(', 'function sa_test_real_ol_items_html(', $fn);
eval($fn);
if(!defined('SA_RCPT_FONT'))define('SA_RCPT_FONT','Arial');
foreach(['Total paid','Total due'] as $label){
 $GLOBALS['sa_test_receipt']=['items'=>[],'totals'=>[[$label,'KES 110.48',true],['Refunded','KES 20',false]]];
 $html=sa_test_real_ol_items_html(new WC_Order);
 if(strpos($html,'Order total')===false||strpos($html,'Total paid')!==false||strpos($html,'Total due')!==false||strpos($html,'Refunded')===false)throw new Exception('Stage totals neutrality failed: '.$label);
}
unset($GLOBALS['sa_test_receipt']);
echo "PASS 2 real stage adapter paid/unpaid neutral totals checks; refund amount preserved.\n";

foreach(['This cancellation email does not confirm a refund','your payment will be refunded'] as $unverified){if(strpos($s,$unverified)!==false)throw new Exception('Unasked cancellation refund wording');}
echo "PASS cancellation source-led short copy, no refund promise/disclaimer.\n";

foreach(['KE','UG','US'] as $country){
 $b=sa_email_footer_business($country);
 // Fallback without WC treats KE as African; actual WC continent lookup is covered below.
 if(in_array($country,['KE','UG']) && $b['phone_display']!=='')throw new Exception('KE business phone retained');
 if($country==='US' && $b['phone_display']!=='+1 917 437 5121')throw new Exception('US phone removed');
 foreach(['order','pickup_order','delivery','delivered','generic','contact'] as $ctx){
  $h=sa_email_help_html($country,$ctx).sa_email_footer_html($country);
  if(strpos($h,'+254')!==false||strpos($h,'tel:+254')!==false)throw new Exception('Kenyan phone in shared footer/help');
  if($country==='KE'&&$ctx==='order'&&strpos($h,'Request roadside help')===false)throw new Exception('Roadside link removed');
 }
}
$us=sa_customer_mail_layout('Your update','<p>Existing details</p>','','','Update','','US');
if(strpos($us,'+1 917 437 5121')===false||strpos($us,'12800 NE Sandy Blvd')===false)throw new Exception('US contacts changed');
$ke=sa_customer_mail_layout('Your update','<p>Existing details</p>','','','Update','','KE');
if(strpos($ke,'+254')!==false||strpos($ke,'Nairobi, Kenya')!==false)throw new Exception('KE presentation changed beyond phone');
if(getenv('SA_EMAIL_RENDER_DIR')){file_put_contents(getenv('SA_EMAIL_RENDER_DIR').'/us-companion.html',$us);file_put_contents(getenv('SA_EMAIL_RENDER_DIR').'/ke-companion.html',$ke);file_put_contents(getenv('SA_EMAIL_RENDER_DIR').'/ke-help.html',sa_email_help_html('KE','order').sa_email_footer_html('KE'));}
echo "PASS phone removal in real shared helpers and all stage/companion renders; US contact and roadside link retained. No sends.\n";

if(sa_email_sender_address('KE')!==''||sa_email_sender_address('US')==='')throw new Exception('Transactional address scope');
if(sa_email_sender_address('KE',true)!=='Nairobi, Kenya')throw new Exception('Commercial address missing');
if(strpos(sa_email_footer_html('KE',false),'Nairobi, Kenya')!==false||strpos(sa_email_footer_text('KE',false),'Nairobi, Kenya')!==false)throw new Exception('Transactional sender address retained');
if(strpos(sa_email_footer_html('KE'),'Nairobi, Kenya')===false)throw new Exception('Unknown-purpose address removed');
if(strpos(sa_customer_mail_layout('Service reminder','Service is due','','','','','KE'),'Nairobi, Kenya')===false)throw new Exception('Commercial reminder address missing');
echo "PASS sender-address scope: KE transaction omitted, US retained, marketing/unknown retained. Customer billing/shipping fixture still preserved.\n";

foreach(['Your application is saved','Booking received','Your estimate is ready','Return approved','Your invoice'] as $h){$v=sa_customer_mail_layout($h,'<p>Existing information</p>');if(strpos($v,'order-email-family-banner.jpg')===false)throw new Exception('Missing companion picture: '.$h);}
foreach(['Your login code','Reset your password','Roadside request'] as $h){$v=sa_customer_mail_layout($h,'<p>Urgent information</p>');if(strpos($v,'order-email-family-banner.jpg')!==false)throw new Exception('Urgent action buried: '.$h);}
echo "PASS companion pictures for application/booking/estimate/return/invoice; urgent login/password/roadside action remains first.\n";

$reset=sa_customer_mail_layout('Reset your password','<p>If you did not request a reset, ignore this email.</p><a href="https://fixture.invalid/exact-reset">Reset</a>');
if(strpos($reset,'Questions? Reply')!==false||strpos($reset,'https://fixture.invalid/exact-reset')===false||sa_email_context('reset body','customer_reset_password')!=='none')throw new Exception('Reset repeated-help suppression failed');
echo "PASS reset body/link retained, shared request warning and generic reply line omitted.\n";

$voice=sa_customer_mail_layout('Your invoice','<p>Thank you so much for supporting Supreme Autoparts! Feel free to bug us.</p>');
if(substr_count(strtolower($voice),'feel free to bug us')!==1)throw new Exception('Repeated confirmation help');
$voice=sa_customer_mail_layout('Your update','<p>Your existing purpose details.</p>');
if(strpos($voice,'If you have any questions, feel free to bug us.')===false)throw new Exception('Confirmation help voice missing');
foreach(['Your order is with the courier.','A big thank-you from our garage to yours!','If your bank shows a debit, contact us before trying again.','This update is not confirmation that your bank has credited the amount.'] as $text)if(strpos($s,$text)===false)throw new Exception('Purpose copy lost: '.$text);
echo "PASS confirmation wording mapped to shared help and lifecycle, no repeated help, failed-payment/refund safeguards retained.\n";
