<?php
// Actual pure delivery functions, no WordPress writes or real customer/order.
define('ABSPATH',__DIR__);function add_action(...$x){}function add_filter(...$x){}
require __DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/fulfilment.php';
function check($v,$why){if(!$v)throw new RuntimeException($why);echo "PASS $why\n";}
function at($s,$z='Africa/Nairobi'){return new DateTimeImmutable($s,new DateTimeZone($z));}
check(sa_ff_delivery_origin('KE',at('2026-11-06 14:59:59'))->format('Y-m-d')==='2026-11-06','Friday before3 origin Friday');
check(sa_ff_delivery_origin('KE',at('2026-11-06 15:00:00'))->format('Y-m-d')==='2026-11-09','Friday cutoff origin Monday');
check(str_contains(sa_ff_delivery_estimate('KE',0,at('2026-11-06 14:59:59')),'Mon 9 – Tue 10 Nov'),'KE Friday before cutoff arrival MonTue');
check(str_contains(sa_ff_delivery_estimate('KE',0,at('2026-11-06 15:00:00')),'Tue 10 – Wed 11 Nov'),'KE Friday after cutoff arrival TueWed');
check(str_contains(sa_ff_delivery_estimate('US',0,at('2026-11-06 15:01:00','Etc/GMT+5')),'Tue 10 – Thu 12 Nov'),'US after cutoff1to3 fromnextweekday');
check(sa_ff_delivery_origin('KE',at('2026-11-07 10:00:00'))===null,'Saturday no unconfirmed dateorigin');
check(sa_ff_delivery_origin('KE',at('2026-11-08 10:00:00'))===null,'Sunday no unconfirmed dateorigin');
check(!preg_match('/Mon [0-9]|Tue [0-9]|Wed [0-9]|Thu [0-9]|Fri [0-9]/',sa_ff_delivery_estimate('KE',0,at('2026-11-07 10:00:00'))),'weekend no calendardate promise');
check(sa_ff_delivery_clock('US',at('2026-11-06 23:00:00'))->format('H:i T')==='15:00 -05','US usesliteralEST notKenya clock');
check(sa_ff_delivery_clock('US',at('2026-07-06 22:00:00'))->format('H:i T')==='14:00 -05','literalEST staysfixedinsummer DSTchoiceunresolved');
check(sa_ff_delivery_context_key('KE',at('2026-11-06 14:59:59'))!==sa_ff_delivery_context_key('KE',at('2026-11-06 15:00:00')),'ratecache key changesatcutoff');
check(str_contains(sa_ff_delivery_estimate('KE',2,at('2026-11-05 12:00:00')),'Tue 10 – Wed 11 Nov'),'known2daylead retained withoutSaturdaycount');
check(sa_ff_delivery_estimate('GB',0,at('2026-11-05 12:00:00'))==='','unsupportedcountry notinvented');
