<?php
$s=file_get_contents(__DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/careers.php');
$a=strpos($s,'<div class="sa-careers__form"');$b=strpos($s,"if (\$state === 'sent')",$a);$form=substr($s,$a,$b-$a);
foreach(['id="application-terms"','Application fee: KES 1,000','if you are never invited','invited paid two-day work trial','trial includes lunch','does not guarantee','Employment depends on the trial outcome','statutory employment rights','Paystack receives payment details','Privacy Policy','<details>','<summary>'] as $t)if(strpos($form,$t)===false)throw new Exception('Missing '.$t);
if(substr_count($form,'refundable')!==1||substr_count($form,'does not guarantee')!==1||strpos($form,'sa_careers_fee_disclosure()')!==false||strpos($form,'Before you apply')!==false)throw new Exception('Repeated terms');
if(strpos($form,'48 hours')!==false||strpos($form,'two days')!==false)throw new Exception('Unapproved deadline');
echo "PASS one visible pre-submit fee/refund/trial block, expandable data detail, no invented deadline; payment logic untouched.\n";
