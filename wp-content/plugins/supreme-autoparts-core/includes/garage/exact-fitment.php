<?php
/** Exact maker fitment for explicitly marked additions; never infer missing vehicle facts. */
if(!defined('ABSPATH'))exit;
function sa_exact_fitment_target(int $pid): bool {
    return in_array((string)get_post_meta($pid,'_sa_shopify_id',true),['7742545920177','7742546018481','7742546215089','7678281253041','7730383290545','9027744071857', '9027744104625'],true);
}
function sa_exact_fitment_valid($r): bool {
    if(!is_array($r))return false;
    foreach(['make','model','chassis','engine','drive','from','through']as$k)if(!isset($r[$k])||!is_string($r[$k])||trim($r[$k])==='')return false;
    foreach(['from','through']as$k)if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$r[$k]))return false;
    return $r['from']<=$r['through'];
}
function sa_exact_fitment_record(int $pid): ?array {
    if(!sa_exact_fitment_target($pid))return null;
    // These SPC/Whiteline additions lack complete engine/drive/month evidence.
    // Never allow guessed marker fields or legacy tags to create a garage fit claim.
    if(in_array((string)get_post_meta($pid,'_sa_shopify_id',true),['7678281253041','7730383290545','9027744071857', '9027744104625'],true))return null;
    $r=get_post_meta($pid,'_sa_exact_maker_fitment_v1',true);
    if(!sa_exact_fitment_valid($r))return null;
    // 949 540 A: JA maker notes exclude June 2017 onward. Generic model
    // production dates must never widen this exact source's application.
    if((string)get_post_meta($pid,'_sa_shopify_id',true)==='7742546215089'){
        $required=['make'=>'Toyota','model'=>'Aqua','chassis'=>'NHP10','engine'=>'1NZ-FXE','drive'=>'FF','from'=>'2011-12','through'=>'2017-05'];
        foreach($required as$k=>$v)if($r[$k]!==$v)return null;
    }
    return $r;
}
function sa_exact_fitment_matches(array $r,array $v): bool {
    if(!sa_exact_fitment_valid($r))return false;
    foreach(['make','model','chassis','engine','drive','production_month'] as $k)if(empty($v[$k]))return false;
    foreach(['make','model','chassis','engine','drive'] as $k)if(strcasecmp(trim((string)($r[$k]??'')),trim((string)$v[$k]))!==0)return false;
    $month=(string)$v['production_month'];
    return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$month)===1
        && $month>=(string)($r['from']??'9999-99')&&$month<=(string)($r['through']??'0000-00');
}
function sa_exact_fitment_label(array $r): string {
    if(!sa_exact_fitment_valid($r))return '';
    return sprintf('Listed fitment: %s %s %s, %s, %s, %s to %s.',(string)$r['make'],(string)$r['model'],(string)$r['chassis'],(string)$r['engine'],(string)$r['drive'],(string)$r['from'],(string)$r['through']);
}
