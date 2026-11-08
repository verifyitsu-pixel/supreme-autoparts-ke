<?php
const ABSPATH='fixture/';function get_post_meta(...$a){return [];}
require __DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/garage/exact-fitment.php';
$r=['make'=>'Toyota','model'=>'Aqua','chassis'=>'NHP10','engine'=>'1NZ-FXE','drive'=>'FF','from'=>'2011-12','through'=>'2021-07'];$v=$r;$v['production_month']='2016-06';
function check_exact($b,$m){if(!$b)throw new RuntimeException($m);echo "PASS $m\n";}
check_exact(sa_exact_fitment_matches($r,$v),'fully evidenced exact matching vehicle');
foreach(['make','model','chassis','engine','drive','production_month']as$k){$x=$v;unset($x[$k]);check_exact(!sa_exact_fitment_matches($r,$x),'missing '.$k.' no positive');}
foreach(['model'=>'Fielder','chassis'=>'NHP20','engine'=>'1NZ-FE','drive'=>'4WD','production_month'=>'2011-11']as$k=>$bad){$x=$v;$x[$k]=$bad;check_exact(!sa_exact_fitment_matches($r,$x),'mismatched '.$k.' no positive');}
foreach(['2011-12'=>true,'2021-07'=>true,'2021-08'=>false,'2011-01'=>false,'2021-99'=>false]as$m=>$want){$x=$v;$x['production_month']=$m;check_exact(sa_exact_fitment_matches($r,$x)===$want,'month boundary '.$m);}
check_exact(!sa_exact_fitment_matches($r,['make'=>'Toyota','model'=>'Aqua','year'=>2016]),'generic Aqua garage insufficient');
check_exact(str_contains(sa_exact_fitment_label($r),'2011-12 to 2021-07'),'label retains month boundaries');
