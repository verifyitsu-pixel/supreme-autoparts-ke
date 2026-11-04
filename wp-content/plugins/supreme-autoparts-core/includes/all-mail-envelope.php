<?php
/** Private all-mail wiring draft. No send/recipient/subject/attachment changes. */
function sa_allmail_family_envelope(array $args): array {
    $body=(string)($args['message']??'');
    if($body==='' || stripos($body,'sa-email-brand-header')!==false || stripos($body,'<!doctype')!==false || stripos($body,'<html')!==false)return $args;
    // Preserve explicit plain-text messages; their text is not silently converted here.
    if(!preg_match('/<(?:p|div|table|h[1-6])\b/i',$body))return $args;
    $subject=(string)($args['subject']??'Supreme Autoparts update');
    $args['message']=sa_customer_mail_layout($subject,$body,'','','','','');return $args;
}
add_filter('wp_mail','sa_allmail_family_envelope',40);
// Capture purpose-specific heading from the immediately preceding installed subject hook.
add_filter('forminator_custom_form_mail_admin_subject',static function($subject,$form,$data,$entry,$mailer){
    $key=is_object($mailer)?spl_object_id($mailer):0;
    $GLOBALS['sa_private_forminator_mail_subject'][$key]=(string)$subject;
    return $subject;
},40,5);
// Installed Forminator 1.44.2 message hook: notification recipients/subject/attachments untouched.
add_filter('forminator_custom_form_mail_admin_message',static function($message,$form,$data,$entry,$mailer){
    if(!is_string($message) || stripos($message,'sa-email-brand-header')!==false || stripos($message,'<html')!==false)return $message;
    $key=is_object($mailer)?spl_object_id($mailer):0;
    $subject=(string)($GLOBALS['sa_private_forminator_mail_subject'][$key]??'Supreme Autoparts update');
    unset($GLOBALS['sa_private_forminator_mail_subject'][$key]);
    // Careers recruitment is based in Nairobi, not the recipient request's browse region.
    if(function_exists('sa_ff_id')&&is_object($form)&&(int)($form->id??0)===sa_ff_id('careers'))return sa_customer_mail_layout($subject,$message,'','','','','KE');
    $args=sa_allmail_family_envelope(['subject'=>$subject,'message'=>$message]);return $args['message'];
},40,5);
