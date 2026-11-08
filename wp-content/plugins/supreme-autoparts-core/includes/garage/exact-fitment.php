<?php
/** Exact maker fitment for explicitly marked additions; never infer missing vehicle facts. */
if(!defined('ABSPATH'))exit;
function sa_exact_fitment_record(int $pid): ?array {
    if(!in_array((string)get_post_meta($pid,'_sa_shopify_id',true),['7742545920177','7742546018481'],true))return null;
    $r=get_post_meta($pid,'_sa_exact_maker_fitment_v1',true);
    return is_array($r)?$r:null;
}
function sa_exact_fitment_matches(array $r,array $v): bool {
    foreach(['make','model','chassis','engine','drive','production_month'] as $k)if(empty($v[$k]))return false;
    foreach(['make','model','chassis','engine','drive'] as $k)if(strcasecmp(trim((string)($r[$k]??'')),trim((string)$v[$k]))!==0)return false;
    $month=(string)$v['production_month'];
    return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$month)===1
        && $month>=(string)($r['from']??'9999-99')&&$month<=(string)($r['through']??'0000-00');
}
function sa_exact_fitment_label(array $r): string {
    return sprintf('Listed fitment: %s %s %s, %s, %s, %s to %s.',(string)$r['make'],(string)$r['model'],(string)$r['chassis'],(string)$r['engine'],(string)$r['drive'],(string)$r['from'],(string)$r['through']);
}
