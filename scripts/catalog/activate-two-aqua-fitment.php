<?php
// Inert reviewed activation helper. No write on inclusion. Exact two drafts only.
if(!defined('ABSPATH'))exit;
function sa_activate_two_aqua_fitment(bool $commit=false): array {
    $exact=['make'=>'Toyota','model'=>'Aqua','chassis'=>'NHP10','engine'=>'1NZ-FXE','drive'=>'FF','from'=>'2011-12','through'=>'2021-07'];
    $rows=[76362=>['7742545920177','CUS949 477 A'],76364=>['7742546018481','CUS949 492 C']];
    foreach($rows as$id=>$identity){$p=wc_get_product($id);if(!$p||$p->get_status()!=='draft'||$p->get_catalog_visibility()!=='hidden'||$p->get_sku()!==$identity[1]||(string)$p->get_meta('_sa_shopify_id')!==$identity[0])throw new RuntimeException('Exact draft identity/state drift');}
    $out=['dry_run'=>!$commit,'items'=>[]];
    foreach($rows as$id=>$identity){$p=wc_get_product($id);$tags=wp_get_object_terms($id,'product_tag',['fields'=>'names']);if(is_wp_error($tags))throw new RuntimeException('Tag read failed');$tags=array_values(array_filter($tags,static fn($t)=>!str_starts_with($t,'fits_')));$description=$p->get_description();$short=trim(preg_replace('/\\s+/',' ',strip_tags(str_replace('</p>','</p> ',$description))));
        if($commit){update_post_meta($id,'_sa_exact_maker_fitment_v1',$exact);wp_set_object_terms($id,$tags,'product_tag',false);$p=wc_get_product($id);$p->set_short_description($short);$p->save();}
        $out['items'][]=['id'=>$id,'source'=>$identity[0],'exact'=>$exact,'tags'=>$tags,'short_description'=>$short];
    }
    return $out;
}
