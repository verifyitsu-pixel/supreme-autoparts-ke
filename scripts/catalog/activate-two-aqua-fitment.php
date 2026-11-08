<?php
// Inert reviewed activation helper. No write on inclusion. Exact two drafts only.
if(!defined('ABSPATH'))exit;
function sa_aqua_activation_protected($p): array {
    return ['sku'=>$p->get_sku(),'source'=>$p->get_meta('_sa_shopify_id'),'status'=>$p->get_status(),'visibility'=>$p->get_catalog_visibility(),'price'=>$p->get_regular_price(),'sale'=>$p->get_sale_price(),'stock'=>$p->get_stock_status(),'managed'=>$p->get_manage_stock(),'quantity'=>$p->get_stock_quantity(),'backorders'=>$p->get_backorders(),'special'=>$p->get_meta('_sa_source_special_order'),'unknown'=>$p->get_meta('_sa_source_orderable_unknown'),'categories'=>$p->get_category_ids(),'thumbnail'=>get_post_thumbnail_id($p->get_id()),'gallery'=>$p->get_gallery_image_ids(),'name'=>$p->get_name(),'description'=>$p->get_description(),'weight'=>$p->get_weight()];
}
function sa_activate_two_aqua_fitment(bool $commit=false): array {
    if(!defined('WP_CLI')||!WP_CLI)throw new RuntimeException('CLI only');
    $exact=['make'=>'Toyota','model'=>'Aqua','chassis'=>'NHP10','engine'=>'1NZ-FXE','drive'=>'FF','from'=>'2011-12','through'=>'2021-07'];
    $rows=[76362=>['7742545920177','CUS949 477 A'],76364=>['7742546018481','CUS949 492 C']];$plans=[];
    // Prepare and validate entire batch before any write.
    foreach($rows as$id=>$identity){$p=wc_get_product($id);if(!$p||!$p->is_type('simple')||$p->get_status()!=='draft'||$p->get_catalog_visibility()!=='hidden'||$p->get_sku()!==$identity[1]||(string)$p->get_meta('_sa_shopify_id')!==$identity[0])throw new RuntimeException('Exact draft identity/state drift');
        $tags=wp_get_object_terms($id,'product_tag',['fields'=>'names']);if(is_wp_error($tags))throw new RuntimeException('Tag read failed');$tags=array_values(array_filter($tags,static fn($t)=>!str_starts_with($t,'fits_')));sort($tags);
        $description=$p->get_description();$short=trim(preg_replace('/\s+/',' ',strip_tags(str_replace('</p>','</p> ',$description))));
        $plans[$id]=['id'=>$id,'source'=>$identity[0],'exact'=>$exact,'tags'=>$tags,'short_description'=>$short,'protected'=>sa_aqua_activation_protected($p),'prior_marker'=>get_post_meta($id,'_sa_exact_maker_fitment_v1',true),'prior_tags'=>wp_get_object_terms($id,'product_tag',['fields'=>'names']),'prior_short'=>$p->get_short_description()];
    }
    $out=['dry_run'=>!$commit,'plans'=>array_values($plans),'verified'=>[],'failed'=>null,'not_attempted'=>[]];if(!$commit)return$out;
    foreach($plans as$id=>$plan){try{
        $p=wc_get_product($id);$nowtags=wp_get_object_terms($id,'product_tag',['fields'=>'names']);if(sa_aqua_activation_protected($p)!==$plan['protected']||get_post_meta($id,'_sa_exact_maker_fitment_v1',true)!==$plan['prior_marker']||$nowtags!==$plan['prior_tags']||$p->get_short_description()!==$plan['prior_short'])throw new RuntimeException('Immediate activation drift');
        update_post_meta($id,'_sa_exact_maker_fitment_v1',$exact);if(get_post_meta($id,'_sa_exact_maker_fitment_v1',true)!==$exact)throw new RuntimeException('Marker write/readback failed');
        $terms=wp_set_object_terms($id,$plan['tags'],'product_tag',false);if(is_wp_error($terms))throw new RuntimeException('Tag write failed');
        $p=wc_get_product($id);$p->set_short_description($plan['short_description']);$saved=$p->save();if((int)$saved!==$id)throw new RuntimeException('Product save failed');
        clean_post_cache($id);$p=wc_get_product($id);$tags=wp_get_object_terms($id,'product_tag',['fields'=>'names']);if(is_wp_error($tags))throw new RuntimeException('Tag verification read failed');sort($tags);
        if(get_post_meta($id,'_sa_exact_maker_fitment_v1',true)!==$exact||$tags!==$plan['tags']||$p->get_short_description()!==$plan['short_description']||sa_aqua_activation_protected($p)!==$plan['protected'])throw new RuntimeException('Activation post-write verification failed');
        $out['verified'][]=$id;
    }catch(Throwable$e){$out['failed']=['id'=>$id,'error'=>$e->getMessage(),'may_have_partial_effects'=>true,'read_state_before_retry'=>true];$out['not_attempted']=array_values(array_diff(array_keys($plans),array_merge($out['verified'],[$id])));break;}}
    return$out;
}
