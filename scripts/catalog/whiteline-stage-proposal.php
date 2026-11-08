<?php
// Inert one-source staging proposal. Inclusion alone performs no writes. No execution authority.
declare(strict_types=1);
function wl_assert(bool $pass,string $why):void {if(!$pass)throw new RuntimeException($why);}
function wl_same($a,$b):bool {return wp_json_encode($a)===wp_json_encode($b);}
function wl_definitions():array {global $wpdb;$r=$wpdb->get_results("SELECT t.term_id,t.name,t.slug,t.term_group,tt.term_taxonomy_id,tt.taxonomy,tt.description,tt.parent FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id ORDER BY tt.term_taxonomy_id",ARRAY_A);wl_assert(!$wpdb->last_error,'Definition query failed');return $r;}
function wl_exact_ids():array {global $wpdb;$r=$wpdb->get_col("SELECT DISTINCT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id=p.ID WHERE p.post_type IN('product','product_variation') AND ((m.meta_key='_sa_shopify_id' AND m.meta_value='7730383290545') OR (m.meta_key='_sku' AND REPLACE(REPLACE(UPPER(m.meta_value),' ',''),'-','') LIKE '%KTA361%')) ORDER BY p.ID");wl_assert(!$wpdb->last_error,'Identity query failed');return array_map('intval',$r);}
function wl_stage_preflight(array $plan):array {
    wl_assert(defined('WP_CLI')&&WP_CLI,'CLI only');wl_assert(($plan['schema']??'')==='whiteline-kta361-private-qualification-v1','Wrong plan');
    wl_assert(home_url()==='https://www.supremeautoparts.co.ke'&&get_option('woocommerce_currency')==='USD'&&get_option('woocommerce_weight_unit')==='lbs','Runtime drift');
    $item=$plan['item'];wl_assert($item['id']===7730383290545&&$item['vendor']==='Whiteline'&&$item['product_type']==='Control Arms'&&$item['tags']===['SpecialOrder:N']&&count($item['variants'])===1&&$item['variants'][0]['sku']==='WHLKTA361'&&$item['variants'][0]['price']==='358.09'&&$item['variants'][0]['available']===true&&$item['variants'][0]['grams']===9299&&count($item['images'])===3,'Payload drift');
    foreach([27=>['suspension',204],111=>['control-arms',27],9478=>['whiteline',127],127=>['brands',0]]as$id=>$want){$term=get_term($id,'product_cat');wl_assert($term&&!is_wp_error($term)&&$term->slug===$want[0]&&(int)$term->parent===$want[1],'Term registry drift');}
    $tag=get_term_by('name','SpecialOrder:N','product_tag');wl_assert($tag&&(int)$tag->term_id===275,'Existing source tag drift');wl_assert(sa_core_map_product_type_parent_slug('Control Arms')==='suspension'&&wl_exact_ids()===[],'Mapping/identity drift');
    $protected=[];foreach([76362,76364,76366,76368]as$id)$protected[$id]=sa_qualified_stage_snapshot($id);
    return ['definitions'=>wl_definitions(),'protected'=>$protected];
}
function wl_stage_execute(array $plan,string $proof_path,bool $commit=false):array {
    $pre=wl_stage_preflight($plan);if(!$commit)return ['dry_run'=>true,'would_stage_source'=>'7730383290545','pre'=>$pre];
    wl_assert(!file_exists($proof_path),'Existing proof: inspect, never retry');$out=['attempted'=>true,'pre'=>$pre,'created'=>null];file_put_contents($proof_path,wp_json_encode($out));
    $r=['imported'=>0,'updated'=>0,'skipped'=>0,'errors'=>0,'messages'=>[]];sa_core_import_one_shopify_product($plan['item'],$r,true,false,true,true);$ids=wl_exact_ids();$id=count($ids)===1?$ids[0]:0;$out['created']=['id'=>$id,'result'=>$r,'identities'=>$ids];file_put_contents($proof_path,wp_json_encode($out));wl_assert($r['imported']===1&&$r['updated']===0&&$r['errors']===0&&$id>0,'Inspect partial import; never retry');
    $p=wc_get_product($id);$cats=$p->get_category_ids();sort($cats);wl_assert($p->is_type('simple')&&$p->get_status()==='draft'&&$p->get_catalog_visibility()==='hidden'&&$p->get_sku()==='WHLKTA361'&&$p->get_regular_price()==='358.09'&&$p->get_sale_price()===''&&(float)$p->get_weight()===20.5&&$p->get_manage_stock()&&$p->get_stock_quantity()===0&&$p->get_backorders()==='notify'&&$p->get_stock_status()==='onbackorder'&&$p->get_meta('_sa_source_orderable_unknown')==='yes'&&$p->get_meta('_sa_source_special_order')===''&&$cats===[27,111,9478],'Initial product drift');
    $urls=array_column($plan['item']['images'],'src');wl_assert((int)$p->get_image_id()===0&&$p->get_gallery_image_ids()===[]&&get_post_meta($id,'_sa_shopify_image_src',true)===$urls[0]&&json_decode(get_post_meta($id,'_sa_shopify_image_urls',true),true)===$urls,'Hotlink-only image state drift');
    $out['before_excerpt']=sa_qualified_stage_snapshot($id);file_put_contents($proof_path,wp_json_encode($out));
    // This exact save+verification function is exercised by the isolated native fixture.
    $guid=str_replace('&','&#038;','https://www.supremeautoparts.co.ke/?post_type=product&p='.$id);
    $out['excerpt_proof']=sa_qualified_stage_finish_excerpt($id,$plan['full_short_description'],$guid);file_put_contents($proof_path,wp_json_encode($out));
    $fresh=wc_get_product($id);ob_start();sa_gpf_render_item($id);$xml=ob_get_clean();$protected=[];foreach([76362,76364,76366,76368]as$pid)$protected[$pid]=sa_qualified_stage_snapshot($pid);
    $out+=['protected_equal'=>wl_same($pre['protected'],$protected),'definitions_equal'=>wl_same($pre['definitions'],wl_definitions()),'record'=>sa_exact_fitment_record($id),'garage_positive'=>sa_garage_product_fits_vehicle($id,'subaru','forester',2016),'feed_bytes'=>strlen($xml),'feed_hold'=>sa_gpf_unknown_lead_source_hold($fresh),'campaign_marker'=>get_post_meta($id,'_sm_campaign_created',true),'url'=>get_preview_post_link($id)];
    $out['hotlink_urls']=json_decode(get_post_meta($id,'_sa_shopify_image_urls',true),true);$out['no_local_media']=(int)$fresh->get_image_id()===0&&$fresh->get_gallery_image_ids()===[];file_put_contents($proof_path,wp_json_encode($out));
    wl_assert($out['protected_equal']&&$out['definitions_equal']&&$out['no_local_media']&&$out['record']===null&&$out['garage_positive']===false&&$out['feed_bytes']===0&&$out['feed_hold']&&$out['campaign_marker']===''&&wl_exact_ids()===[$id],'Poststage proof failed; preserve partial state, no retry');return $out;
}
