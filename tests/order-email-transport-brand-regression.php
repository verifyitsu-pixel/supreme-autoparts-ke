<?php
define('ABSPATH', __DIR__);
require __DIR__.'/../wp-content/plugins/sa-brevo-mail/includes/class-brevo-mailer.php';
$template=file_get_contents(__DIR__.'/../wp-content/themes/supreme-autoparts/woocommerce/emails/customer-processing-order.php');
assert(strpos($template,'class="sa-email-brand-header"')!==false);
$fixture='<!doctype html><html><body><table><tr><td class="sa-email-brand-header"><img src="logo-email.png">SHOP GARAGE HELP</td></tr></table></body></html>';
$pass1=SA_Brevo_Mailer::brand_html($fixture);
$pass2=SA_Brevo_Mailer::brand_html($pass1);
assert($pass1===$fixture && $pass2===$fixture);
assert(substr_count($pass2,'<img')===1);
echo "PASS: outgoing wp_mail/API branding passes preserve sole inner header, no duplicate logo (two passes).\n";
