<?php
define('ABSPATH',1);require __DIR__.'/../wp-content/plugins/whop-payments/includes/class-whop-express-quote.php';
$s=['currency'=>'USD','total_minor'=>12345,'cart_hash'=>'servercart','stock_valid'=>true,'requires_shipping'=>true,'shipping_option'=>'courier:station1','shipping_quoted'=>true];
$q=Whop_Express_Quote::freeze($s,1000);$a=['currency'=>'USD','total_minor'=>12345,'terms_accepted'=>true,'email'=>'test@example.invalid','shipping'=>['name'=>'Test','address1'=>'Test Road','city'=>'Nairobi','country'=>'KE'],'shipping_option'=>'courier:station1','token'=>'ctok_fixture'];
Whop_Express_Quote::validate($q,$s,$a,1001);$checks=1;
function rejects(callable $f):void {global $checks;try{$f();throw new Exception('Expected refusal');}catch(InvalidArgumentException $e){$checks++;}}
foreach(['currency'=>'KES','total_minor'=>0,'cart_hash'=>'','stock_valid'=>false,'shipping_quoted'=>false,'shipping_option'=>''] as $k=>$v){$bad=$s;$bad[$k]=$v;rejects(fn()=>Whop_Express_Quote::freeze($bad,1000));}
foreach(['currency'=>'KES','total_minor'=>12346,'cart_hash'=>'changed','stock_valid'=>false,'shipping_option'=>'other'] as $k=>$v){$bad=$s;$bad[$k]=$v;rejects(fn()=>Whop_Express_Quote::validate($q,$bad,$a,1001));}
foreach(['currency'=>'KES','total_minor'=>12344,'terms_accepted'=>false,'email'=>'','shipping'=>[],'shipping_option'=>'other','token'=>'bad'] as $k=>$v){$bad=$a;$bad[$k]=$v;rejects(fn()=>Whop_Express_Quote::validate($q,$s,$bad,1001));}
rejects(fn()=>Whop_Express_Quote::validate($q,$s,$a,1301));rejects(fn()=>Whop_Express_Quote::validate($q,$s,$a,999));
echo "$checks private quote safety checks PASS (no live endpoints or charge)\n";
