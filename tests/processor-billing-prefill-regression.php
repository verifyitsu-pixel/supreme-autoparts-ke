<?php
// Execute the actual source mapping with a synthetic order, no provider I/O.
$s=file_get_contents(__DIR__.'/../wp-content/plugins/pesapal-woocommerce/thebunchke_pesapal_woocommerce.php');
class BillingOrder{function get_billing_address_1(){return '  123 Main Street  ';}function get_billing_address_2(){return 'Apartment B, 2nd Floor';}}
$order=new BillingOrder;$request=new stdClass;
foreach(['billing_address_1','billing_address_2'] as $f){if(!preg_match('/\$request->'.$f.' = trim\(\(string\) \$order->get_'.$f.'\(\)\);/',$s,$m))throw new Exception('Mapping absent '.$f);eval($m[0]);}
if($request->billing_address_1!=='123 Main Street'||$request->billing_address_2!=='Apartment B, 2nd Floor')throw new Exception('Address damaged');
$h=file_get_contents(__DIR__.'/../wp-content/plugins/pesapal-woocommerce/lib/pesapalV30Helper.php');foreach(['line_1'=>'billing_address_1','line_2'=>'billing_address_2','email_address'=>'billing_email','phone_number'=>'billing_phone','country_code'=>'billing_country','first_name'=>'billing_first_name','last_name'=>'billing_last_name','city'=>'billing_city'] as $field=>$source){if(strpos($h,'$postData["billing_address"]["'.$field.'"] = $request->'.$source)===false)throw new Exception('Payload mapping '.$field);}
echo "PASS Pesapal actual address preserves street/spaces/unit and 8 documented billing payload mappings. No HTTP/order writes.\n";
