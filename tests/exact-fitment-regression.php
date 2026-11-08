<?php
const ABSPATH='fixture/';function get_post_meta($id,$key,...$a){return $GLOBALS['exact_fixture'][$id][$key]??'';}
require __DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/garage/exact-fitment.php';
$r=['make'=>'Toyota','model'=>'Aqua','chassis'=>'NHP10','engine'=>'1NZ-FXE','drive'=>'FF','from'=>'2011-12','through'=>'2021-07'];$v=$r;$v['production_month']='2016-06';
function check_exact($b,$m){if(!$b)throw new RuntimeException($m);echo "PASS $m\n";}
check_exact(sa_exact_fitment_matches($r,$v),'fully evidenced exact matching vehicle');
foreach(['make','model','chassis','engine','drive','production_month']as$k){$x=$v;unset($x[$k]);check_exact(!sa_exact_fitment_matches($r,$x),'missing '.$k.' no positive');}
foreach(['model'=>'Fielder','chassis'=>'NHP20','engine'=>'1NZ-FE','drive'=>'4WD','production_month'=>'2011-11']as$k=>$bad){$x=$v;$x[$k]=$bad;check_exact(!sa_exact_fitment_matches($r,$x),'mismatched '.$k.' no positive');}
foreach(['2011-12'=>true,'2021-07'=>true,'2021-08'=>false,'2011-01'=>false,'2021-99'=>false]as$m=>$want){$x=$v;$x['production_month']=$m;check_exact(sa_exact_fitment_matches($r,$x)===$want,'month boundary '.$m);}
check_exact(!sa_exact_fitment_matches($r,['make'=>'Toyota','model'=>'Aqua','year'=>2016]),'generic Aqua garage insufficient');
check_exact(str_contains(sa_exact_fitment_label($r),'2011-12 to 2021-07'),'label retains month boundaries');

foreach([null,'bad',[],array_diff_key($r,['engine'=>true]),array_replace($r,['from'=>'2011']),array_replace($r,['through'=>'2021-99']),array_replace($r,['from'=>'2022-01'])]as$bad)check_exact(!sa_exact_fitment_valid($bad),'malformed marker schema refused');
$x=$v;$x['make']='Nissan';check_exact(!sa_exact_fitment_matches($r,$x),'wrong make no positive');
$GLOBALS['exact_fixture']=[1=>['_sa_shopify_id'=>'7742545920177'],2=>['_sa_shopify_id'=>'other','_sa_exact_maker_fitment_v1'=>$r]];
check_exact(sa_exact_fitment_target(1)&&sa_exact_fitment_record(1)===null,'allowlisted missing marker remains target but invalid');check_exact(!sa_exact_fitment_target(2)&&sa_exact_fitment_record(2)===null,'unallowlisted marker cannot activate');

// The newly reviewed strut source is narrower than the Aqua model's lifespan.
$strut=array_replace($r,['through'=>'2017-05']);
$GLOBALS['exact_fixture'][3]=['_sa_shopify_id'=>'7742546215089','_sa_exact_maker_fitment_v1'=>$strut];
check_exact(sa_exact_fitment_target(3)&&sa_exact_fitment_record(3)===$strut,'strut exact source and bounded record');
foreach(['2011-11'=>false,'2011-12'=>true,'2017-05'=>true,'2017-06'=>false,'2021-07'=>false]as$m=>$want){$x=$v;$x['production_month']=$m;check_exact(sa_exact_fitment_matches($strut,$x)===$want,'strut month boundary '.$m);}
foreach([array_replace($strut,['through'=>'2021-07']),array_replace($strut,['from'=>'2011-11']),array_replace($strut,['engine'=>'1NZ-FE']),array_replace($strut,['model'=>'Fielder'])]as$bad){$GLOBALS['exact_fixture'][3]['_sa_exact_maker_fitment_v1']=$bad;check_exact(sa_exact_fitment_record(3)===null,'strut marker cannot widen/misstate application');}
unset($GLOBALS['exact_fixture'][3]['_sa_exact_maker_fitment_v1']);check_exact(sa_exact_fitment_target(3)&&sa_exact_fitment_record(3)===null,'strut missing marker fails closed');
check_exact(str_contains(sa_exact_fitment_label($strut),'2011-12 to 2017-05'),'strut label cutoff retained');

// SPC maker gives CX-5 model/year only. Even a schema-valid guessed record is not authority.
$GLOBALS['exact_fixture'][4]=['_sa_shopify_id'=>'7678281253041'];
check_exact(sa_exact_fitment_target(4)&&sa_exact_fitment_record(4)===null,'SPC missing marker targets fail-closed path');
foreach([$r,array_replace($r,['make'=>'Mazda','model'=>'CX-5','chassis'=>'KE','engine'=>'invented','drive'=>'FF','from'=>'2013-01','through'=>'2025-12']),'bad',[]]as$guess){$GLOBALS['exact_fixture'][4]['_sa_exact_maker_fitment_v1']=$guess;check_exact(sa_exact_fitment_record(4)===null,'SPC refuses any guessed exact marker');}
