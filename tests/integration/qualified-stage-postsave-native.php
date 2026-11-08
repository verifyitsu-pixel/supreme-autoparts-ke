<?php
// Actual isolated native save path used by the next one-product staging helper.
if(DB_NAME!=='catalog_staging_it'||rtrim(home_url(),'/')!=='http://127.0.0.1:8091')throw new RuntimeException('Isolated fixture required');
require_once $repo.'/scripts/catalog/qualified-stage-verifier.php';
$item=fixture_item(91);
// Use fresh image URLs, not claims owned by the earlier fixture product.
$item['images']=[['src'=>'https://fixture.invalid/stage91-a.png'],['src'=>'https://fixture.invalid/stage91-b.png'],['src'=>'https://fixture.invalid/stage91-c.png']];
add_filter('pre_http_request',function($pre,$args,$url)use($png){if(!str_contains($url,'/stage91-'))return $pre;if(!empty($args['stream'])&&!empty($args['filename']))file_put_contents($args['filename'],$png);return ['headers'=>['content-type'=>'image/png'],'body'=>!empty($args['stream'])?'':$png,'response'=>['code'=>200,'message'=>'OK'],'cookies'=>[],'filename'=>$args['filename']??null];},PHP_INT_MAX,3);
$r=['imported'=>0,'updated'=>0,'skipped'=>0,'errors'=>0,'messages'=>[]];sa_core_import_one_shopify_product($item,$r,false,false,true,true);$id=wc_get_product_id_by_sku('FIXTURE-91');$p=wc_get_product($id);fixture_ok($r['imported']===1&&$r['errors']===0&&$p&&$p->get_status()==='draft'&&$p->get_catalog_visibility()==='hidden','next staging path actual importer creates hidden draft');
$images=array_merge([$p->get_image_id()],$p->get_gallery_image_ids());fixture_ok(count($images)===3,'next staging path preserves all3 synthetic gallery attachments');foreach($images as$aid)fixture_ok(hash_file('sha256',get_attached_file($aid))===hash('sha256',$png),'next staging path actual attachment bytes pinned');
$excerpt='Two front lower control arms with bushings and ball joints. Availability and delivery timing must be confirmed.';
$guid=str_replace('&','&#038;',home_url('/?post_type=product&p='.$id));$proof=sa_qualified_stage_finish_excerpt($id,$excerpt,$guid);fixture_ok($proof['exact']&&get_post_field('post_excerpt',$id)===$excerpt,'next helper actual post-save verification path executes with native WP_Term objects');
fixture_ok(count(array_filter($proof['after']['terms'],fn($term)=>$term instanceof WP_Term))===count($proof['after']['terms']),'next helper native term objects retained without array indexing');
$a=json_decode(wp_json_encode($proof['before']),true);$b=json_decode(wp_json_encode($proof['after']),true);fixture_ok(wp_json_encode(sa_qualified_stage_excerpt_expected($a,$b,$excerpt,$guid))===wp_json_encode($b),'next helper recovered JSON array snapshots verified');
foreach(['post_title','post_content','post_date']as$key){$bad=$proof['after'];$bad['post'][$key].='UNEXPECTED';fixture_ok(wp_json_encode(sa_qualified_stage_excerpt_expected($proof['before'],$bad,$excerpt,$guid))!==wp_json_encode($bad),'next stage rejects unexpected '.$key);}
$bad=$proof['after'];$bad['post']['guid']='https://wrong.invalid/';fixture_ok(wp_json_encode(sa_qualified_stage_excerpt_expected($proof['before'],$bad,$excerpt,$guid))!==wp_json_encode($bad),'next stage rejects arbitrary GUID destination');
$bad=$proof['after'];$bad['meta']['_price']=['9'];fixture_ok(wp_json_encode(sa_qualified_stage_excerpt_expected($proof['before'],$bad,$excerpt,$guid))!==wp_json_encode($bad),'next stage rejects price drift');
$bad=$proof['after'];$bad['terms'][]=(object)['term_id'=>999999,'taxonomy'=>'product_cat'];fixture_ok(wp_json_encode(sa_qualified_stage_excerpt_expected($proof['before'],$bad,$excerpt,$guid))!==wp_json_encode($bad),'next stage rejects taxonomy drift');
