<?php
// Actual delivery-date helper, no WordPress/DB/network. Pickup calendar must stay separate.
$s=file_get_contents(__DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/fulfilment.php');
function check($v,$n){if(!$v)throw new Exception($n);echo "PASS $n\n";}
foreach(['sa_ff_delivery_add_workdays','sa_ff_range_label','sa_ff_delivery_clock','sa_ff_delivery_origin','sa_ff_delivery_estimate','sa_ff_fargo_eta'] as $name){$start=strpos($s,'function '.$name.'(');check($start!==false,'actual helper exists '.$name);$end=strpos($s,"\n}",$start)+2;eval(substr($s,$start,$end-$start));}
function sa_ff_now(){return new DateTimeImmutable('2026-11-06 15:00:00',new DateTimeZone('Africa/Nairobi'));}
$f=new DateTimeImmutable('2026-11-06');check($f->format('N')==='5','fixture Friday calculated');check(sa_ff_delivery_add_workdays($f,1)->format('Y-m-d')==='2026-11-09','Friday plus one excludes Saturday and Sunday');check(sa_ff_delivery_add_workdays($f,2)->format('Y-m-d')==='2026-11-10','Friday plus two is Tuesday');
check(str_contains($s,"return sa_ff_delivery_estimate('KE', \$lead);"),'Fargo ETA delegates to the cutoff-aware estimate for every region, so old regional tiers do not override the owner total');
$at=static fn($t)=>new DateTimeImmutable($t,new DateTimeZone('Africa/Nairobi'));
check(str_contains(sa_ff_delivery_estimate('KE',0,$at('2026-11-06 14:00:00')),'Mon 9 – Tue 10 Nov'),'Kenya Friday before the cutoff starts at order');
check(str_contains(sa_ff_delivery_estimate('KE',3,$at('2026-11-05 12:00:00')),'Wed 11 – Thu 12 Nov'),'known product availability lead time is preserved');
check(str_contains($s,"return (int) \$d->format('w') !== 0"),'separate pickup/shop calendar unchanged');
