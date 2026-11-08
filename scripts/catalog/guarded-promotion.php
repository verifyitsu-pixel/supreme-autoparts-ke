<?php
// Private candidate. Inclusion defines helpers only. No automatic writes or authorization.
declare(strict_types=1);
if (!defined('ABSPATH')) { throw new RuntimeException('WordPress required'); }

function sa_promotion_snapshot(int $id): array {
    $p=wc_get_product($id);
    if (!$p) { throw new RuntimeException('Missing product'); }
    $post=get_post($id,ARRAY_A);
    $meta=get_post_meta($id); ksort($meta);
    $terms=[];
    foreach(get_object_taxonomies('product') as $tax) {
        $ids=wp_get_object_terms($id,$tax,['fields'=>'ids']);
        if(is_wp_error($ids)) {throw new RuntimeException('Cannot read taxonomy');}
        sort($ids); $terms[$tax]=$ids;
    }
    ksort($terms);
    $media=[];
    foreach(array_values(array_unique(array_merge([(int)get_post_thumbnail_id($id)],$p->get_gallery_image_ids()))) as $aid) {
        if($aid<=0)continue;
        $file=get_attached_file($aid);
        if(!$file||!is_file($file)||!is_readable($file))throw new RuntimeException('Unreadable attachment');
        $am=get_post_meta($aid);ksort($am);
        $media[(string)$aid]=['post'=>get_post($aid,ARRAY_A),'meta'=>$am,'sha256'=>hash_file('sha256',$file)];
    }
    ksort($media);
    return ['post'=>$post,'meta'=>$meta,'terms'=>$terms,'media'=>$media];
}
function sa_promotion_digest(array $snapshot): string {
    return hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
}
function sa_promotion_validate(array $row): array {
    foreach(['id','source_id','sku','snapshot_sha256','evidence_sha256'] as $key)if(!isset($row[$key])||$row[$key]==='')throw new RuntimeException('Missing manifest field: '.$key);
    foreach(['snapshot_sha256','evidence_sha256'] as $key)if(!preg_match('/^[a-f0-9]{64}$/D',(string)$row[$key]))throw new RuntimeException('Invalid manifest hash');
    $id=(int)$row['id'];$p=wc_get_product($id);
    if(!$p||!$p->is_type('simple')||$p->get_status()!=='draft'||$p->get_catalog_visibility()!=='hidden')throw new RuntimeException('Not a hidden simple draft');
    if((string)$p->get_sku()!==(string)$row['sku']||(string)get_post_meta($id,'_sa_shopify_id',true)!==(string)$row['source_id'])throw new RuntimeException('Identity drift');
    if(!has_post_thumbnail($id)||get_post_meta($id,'_sa_catalog_excluded',true))throw new RuntimeException('Missing real image or catalog exclusion');
    if((float)$p->get_regular_price()<=0)throw new RuntimeException('Missing price');
    if(get_post_meta($id,'_sa_source_special_order',true)==='yes'||get_post_meta($id,'_sa_source_orderable_unknown',true)==='yes') {
        if(!$p->get_manage_stock()||$p->get_stock_quantity()!==0||$p->get_backorders()!=='notify'||$p->get_stock_status()!=='onbackorder')throw new RuntimeException('Special-order stock drift');
    }
    $snapshot=sa_promotion_snapshot($id);
    if(!hash_equals((string)$row['snapshot_sha256'],sa_promotion_digest($snapshot)))throw new RuntimeException('Snapshot drift');
    return $snapshot;
}
/** Ignore only intentional publication fields; every other state change is an error. */
function sa_promotion_stable_state(array $s): array {
    // WP sanitizes GUID ampersands on update; compare equivalent entity spelling, not ignore GUID.
    $s['post']['guid']=html_entity_decode((string)$s['post']['guid'],ENT_QUOTES|ENT_HTML5,'UTF-8');
    foreach(['post_status','post_modified','post_modified_gmt','post_date','post_date_gmt'] as $k)unset($s['post'][$k]);
    unset($s['meta']['_edit_lock']);
    foreach(['exclude-from-catalog','exclude-from-search'] as $slug) {
        $term=get_term_by('slug',$slug,'product_visibility');
        if($term&&!is_wp_error($term))$s['terms']['product_visibility']=array_values(array_diff($s['terms']['product_visibility']??[],[(int)$term->term_id]));
    }
    return $s;
}
/** Operator must independently verify authority and evidence. Hashes do not grant permission. */
function sa_guarded_promote(array $manifest,bool $commit=false): array {
    if(!defined('WP_CLI')||!WP_CLI)throw new RuntimeException('CLI required');
    if(($manifest['schema']??null)!=='supreme-reviewed-promotion-v1'||empty($manifest['items'])||count($manifest['items'])>2)throw new RuntimeException('Bounded reviewed manifest required');
    $seen=[];
    // Validate whole batch before even one write. No discovery or inferred products.
    foreach($manifest['items'] as $row) {
        $id=(int)($row['id']??0);
        if($id<=0||isset($seen[$id]))throw new RuntimeException('Invalid or repeated product');
        $seen[$id]=true;sa_promotion_validate($row);
    }
    $out=['dry_run'=>!$commit,'published'=>[],'requested'=>array_keys($seen),'would_publish'=>$commit?[]:array_keys($seen),'not_attempted'=>[],'failed'=>null];
    if(!$commit)return $out;
    // No automatic rollback or retry after partial success. Caller gets exact partial state.
    foreach($manifest['items'] as $row) {
        try {
            $before=sa_promotion_validate($row);
            $p=wc_get_product((int)$row['id']);$p->set_catalog_visibility('visible');$p->set_status('publish');$p->save();
            clean_post_cache((int)$row['id']);$fresh=wc_get_product((int)$row['id']);
            if($fresh->get_status()!=='publish'||$fresh->get_catalog_visibility()!=='visible')throw new RuntimeException('Post-write verification failed');
            if(sa_promotion_stable_state($before)!==sa_promotion_stable_state(sa_promotion_snapshot((int)$row['id'])))throw new RuntimeException('Unexpected post-write state drift');
            $out['published'][]=(int)$row['id'];
        } catch(Throwable $e) {
            $out['failed']=['id'=>(int)$row['id'],'error'=>$e->getMessage(),'read_current_state_before_retry'=>true,'may_have_published'=>get_post_status((int)$row['id'])==='publish'];
            $out['not_attempted']=array_values(array_diff(array_keys($seen),array_merge($out['published'],[(int)$row['id']])));break;
        }
    }
    return $out;
}
