<?php
// Actual isolated native save path used by the next one-product staging helper.
if(DB_NAME!=='catalog_staging_it'||rtrim(home_url(),'/')!=='http://127.0.0.1:8091')throw new RuntimeException('Isolated fixture required');
require_once $repo.'/scripts/catalog/qualified-stage-verifier.php';
$item=fixture_item(91);$item['variants'][0]['grams']=9299;
$item['images']=[['src'=>'https://fixture.invalid/stage91-a.png'],['src'=>'https://fixture.invalid/stage91-b.png'],['src'=>'https://fixture.invalid/stage91-c.png']];
$r=['imported'=>0,'updated'=>0,'skipped'=>0,'errors'=>0,'messages'=>[]];sa_core_import_one_shopify_product($item,$r,true,false,true,true);$id=wc_get_product_id_by_sku('FIXTURE-91');$p=wc_get_product($id);fixture_ok($r['imported']===1&&$r['errors']===0&&$p&&$p->get_status()==='draft'&&$p->get_catalog_visibility()==='hidden','next staging path actual importer creates hidden draft');
fixture_ok((float)$p->get_weight()===20.5,'actual9299g native importer rounds to20.5lb');fixture_ok((int)$p->get_image_id()===0&&$p->get_gallery_image_ids()===[]&&json_decode(get_post_meta($id,'_sa_shopify_image_urls',true),true)===array_column($item['images'],'src'),'actual hotlink-only importer preserves all3 URLs with no attachments');
$excerpt='Two front lower control arms with bushings and ball joints. Availability and delivery timing must be confirmed.';
$guid=str_replace('&','&#038;',home_url('/?post_type=product&p='.$id));$proof=sa_qualified_stage_finish_excerpt($id,$excerpt,$guid);fixture_ok($proof['exact']&&get_post_field('post_excerpt',$id)===$excerpt,'next helper actual post-save verification path executes with native WP_Term objects');
fixture_ok(count(array_filter($proof['after']['terms'],fn($term)=>$term instanceof WP_Term))===count($proof['after']['terms']),'next helper native term objects retained without array indexing');
$a=json_decode(wp_json_encode($proof['before']),true);$b=json_decode(wp_json_encode($proof['after']),true);fixture_ok(wp_json_encode(sa_qualified_stage_excerpt_expected($a,$b,$excerpt,$guid))===wp_json_encode($b),'next helper recovered JSON array snapshots verified');
foreach(['post_title','post_content','post_date','post_status','post_excerpt']as$key){$bad=$proof['after'];$bad['post'][$key].='UNEXPECTED';fixture_ok(wp_json_encode(sa_qualified_stage_excerpt_expected($proof['before'],$bad,$excerpt,$guid))!==wp_json_encode($bad),'next stage rejects unexpected '.$key);}
$bad=$proof['after'];$bad['post']['guid']='https://wrong.invalid/';fixture_ok(wp_json_encode(sa_qualified_stage_excerpt_expected($proof['before'],$bad,$excerpt,$guid))!==wp_json_encode($bad),'next stage rejects arbitrary GUID destination');
$bad=$proof['after'];$bad['meta']['_price']=['9'];fixture_ok(wp_json_encode(sa_qualified_stage_excerpt_expected($proof['before'],$bad,$excerpt,$guid))!==wp_json_encode($bad),'next stage rejects price drift');
$bad=$proof['after'];$bad['terms'][]=(object)['term_id'=>999999,'taxonomy'=>'product_cat'];fixture_ok(wp_json_encode(sa_qualified_stage_excerpt_expected($proof['before'],$bad,$excerpt,$guid))!==wp_json_encode($bad),'next stage rejects taxonomy drift');
// Exercise exact inert one-product helper commit=false in this isolated database.
require_once $repo.'/scripts/catalog/whiteline-stage-proposal.php';
$plan=json_decode(file_get_contents($repo.'/tests/integration/whiteline-stage-plan.json'),true);global $wpdb;
foreach([27=>['Suspension','suspension',204],111=>['Control Arms','control-arms',27],9478=>['Whiteline','whiteline',127],127=>['Brands','brands',0]]as$tid=>$row){
 $old=get_term_by('slug',$row[1],'product_cat');if($old&&(int)$old->term_id!==$tid)wp_delete_term((int)$old->term_id,'product_cat');
 $wpdb->replace($wpdb->terms,['term_id'=>$tid,'name'=>$row[0],'slug'=>$row[1],'term_group'=>0]);$tt=$wpdb->get_var($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id=%d AND taxonomy='product_cat'",$tid));if($tt)$wpdb->update($wpdb->term_taxonomy,['parent'=>$row[2]],['term_taxonomy_id'=>$tt]);else$wpdb->insert($wpdb->term_taxonomy,['term_id'=>$tid,'taxonomy'=>'product_cat','description'=>'','parent'=>$row[2],'count'=>0]);clean_term_cache($tid,'product_cat');
}
$old=get_term_by('name','SpecialOrder:N','product_tag');if($old&&(int)$old->term_id!==275)wp_delete_term((int)$old->term_id,'product_tag');
$wpdb->replace($wpdb->terms,['term_id'=>275,'name'=>'SpecialOrder:N','slug'=>'specialordern','term_group'=>0]);$tt=$wpdb->get_var("SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id=275 AND taxonomy='product_tag'");if(!$tt)$wpdb->insert($wpdb->term_taxonomy,['term_id'=>275,'taxonomy'=>'product_tag','description'=>'','parent'=>0,'count'=>0]);clean_term_cache(275,'product_tag');
foreach([76362,76364,76366,76368]as$pid){$x=wp_insert_post(['import_id'=>$pid,'post_type'=>'product','post_title'=>'Synthetic protected product'.$pid,'post_status'=>'publish']);fixture_ok($x===$pid,'isolated protected id fixture'.$pid);}
update_option('woocommerce_currency','USD');update_option('woocommerce_weight_unit','lbs');add_filter('home_url',fn($url)=>str_replace('http://127.0.0.1:8091','https://www.supremeautoparts.co.ke',$url),999);
wp_cache_flush();$defs=wl_definitions();$count=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}");$dry=wl_stage_execute($plan,'/tmp/fixture-should-not-exist.json',false);
fixture_ok($dry['dry_run']&&$dry['would_stage_source']==='7730383290545'&&!file_exists('/tmp/fixture-should-not-exist.json'),'exact inert perproduct helper commitfalse fully executes without proofwrite');fixture_ok(wl_same($defs,wl_definitions())&&(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}")===$count&&wl_exact_ids()===[],'exact helper commitfalse leaves products/taxonomy unchanged');
$attachments_before=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment'");
$wpdb->query("ALTER TABLE {$wpdb->posts} AUTO_INCREMENT=76370");
try{$committed=wl_stage_execute($plan,'/tmp/fixture-whiteline-proof.json',true);}catch(Throwable $e){echo 'EXACT_HELPER_FAILURE_PROOF '.file_get_contents('/tmp/fixture-whiteline-proof.json').PHP_EOL;throw $e;}$wid=$committed['created']['id'];fixture_ok($wid>0&&$committed['excerpt_proof']['exact']&&$committed['no_local_media']&&get_post_status($wid)==='draft','exact hotlink helper commit path completes in isolated fixture only');fixture_ok((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment'")===$attachments_before,'exact hotlink helper does not create any local attachments');
require_once SA_CORE_DIR.'includes/product-images.php';$GLOBALS['product']=wc_get_product($wid);$html=apply_filters('woocommerce_single_product_image_thumbnail_html','',0);fixture_ok(substr_count($html,'sa-shopify-cdn-photo')===3&&substr_count($html,'cdn.shopify.com')>=3,'actual product gallery filter renders three approved source hotlinks');

$guess=['make'=>'Subaru','model'=>'Forester','chassis'=>'SJ','engine'=>'GUESS','drive'=>'AWD','from'=>'2013-01','through'=>'2018-12'];update_post_meta($wid,'_sa_exact_maker_fitment_v1',$guess);fixture_ok(sa_exact_fitment_record($wid)===null&&sa_garage_product_fits_vehicle($wid,'subaru','forester',2016)===false,'KTA361 guessed marker never restores garage positive');delete_post_meta($wid,'_sa_exact_maker_fitment_v1');fixture_ok(sa_gpf_apply_image_holds('<item><g:id>WHLKTA361</g:id></item><item><g:id>WHLKTA361A</g:id></item>')==='<item><g:id>WHLKTA361A</g:id></item>','KTA361 cached feed hold is exactSKU only');

// Exact publication helper with actual marketing draft-campaign hook, no sender/cron.
$marketing=$repo.'/wp-content/plugins/supreme-marketing/inc/';
foreach(['helpers.php','class-sm-db.php','class-sm-templates.php','class-sm-campaigns.php','class-sm-events.php']as$file)require_once $marketing.$file;
define('SUPM_DB_VERSION','1.4.0');SM_DB::install();add_action('transition_post_status',['SM_Events','on_product_publish'],10,3);
require_once $repo.'/scripts/catalog/whiteline-publication-proposal.php';
$protected=[];foreach([76362,76364,76366,76368]as$x)$protected[$x]=sa_qualified_stage_snapshot($x);
$pubplan=['schema'=>'whiteline76370-publication-v1','id'=>76370,'source_id'=>'7730383290545','sku'=>'WHLKTA361','ean'=>'9319924475032','feed_release'=>false,'before'=>sa_qualified_stage_snapshot(76370),'protected'=>$protected,'definitions_sha256'=>hash('sha256',wp_json_encode(wl_definitions())),'image_urls'=>$committed['hotlink_urls']];
$before=sa_qualified_stage_snapshot(76370);$dry=wl_pub_execute($pubplan,'/tmp/wl-pub-native-proof.json',false);fixture_ok($dry['dry_run']&&wl_same($before,sa_qualified_stage_snapshot(76370))&&!file_exists('/tmp/wl-pub-native-proof.json'),'exact publication dryrun no writes');
try{$pub=wl_pub_execute($pubplan,'/tmp/wl-pub-native-proof.json',true);}catch(Throwable $e){echo 'PUB_FAILURE_PROOF '.file_get_contents('/tmp/wl-pub-native-proof.json').PHP_EOL;throw $e;}
fixture_ok($pub['exact_delta']&&$pub['campaign_expected']&&$pub['queue_count']===0,'exact publication full native commit verifies real draft campaign and zero queue');fixture_ok($pub['gtin']==='9319924475032'&&get_post_meta(76370,'_global_unique_id',true)==='9319924475032','native Woo globaluniqueid setter persists verified EAN');
WC()->structured_data->generate_product_data(wc_get_product(76370));$schema=WC()->structured_data->get_data();fixture_ok(str_contains(wp_json_encode($schema),'9319924475032'),'actual Woo generated schema carries verified EAN');
fixture_ok($pub['feed_hold']&&$pub['feed_bytes']===0&&$pub['cached_hold']===''&&$pub['garage']===false&&$pub['no_local_media'],'publication keeps source feed and garage holds, hotlinkonly');

$unexpected=$pub['after'];$unexpected['meta']['_global_unique_id']=['9319924475033'];fixture_ok(!wl_same($pub['expected'],$unexpected),'exact publication expectedstate rejects wrong EAN');
foreach(['guid','post_date','post_content','post_title','post_excerpt']as$key){$unexpected=$pub['after'];$unexpected['post'][$key].='UNEXPECTED';fixture_ok(!wl_same($pub['expected'],$unexpected),'exact publication expectedstate rejects '.$key);}
