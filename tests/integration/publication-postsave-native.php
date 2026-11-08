<?php
// Included by the isolated CI fixture only, never a production helper.
if (DB_NAME !== 'catalog_staging_it' || rtrim(home_url(), '/') !== 'http://127.0.0.1:8091') throw new RuntimeException('Isolated fixture required');
require_once $repo . '/scripts/catalog/publication-state.php';
function post_fixture_snapshot(int $id): array {
    $meta=get_post_meta($id);ksort($meta);
    $terms=wp_get_object_terms($id,get_object_taxonomies('product'),['fields'=>'all']);
    foreach($terms as $term)unset($term->count);
    usort($terms,fn($a,$b)=>[$a->taxonomy,$a->term_id]<=>[$b->taxonomy,$b->term_id]);
    return ['post'=>get_post($id,ARRAY_A),'meta'=>$meta,'terms'=>$terms];
}
$fixture = new WC_Product_Simple();$fixture->set_name('Isolated post-save value fixture');$fixture->set_status('draft');$fixture->set_catalog_visibility('hidden');$fixture->set_regular_price('178.40');$fixture->set_manage_stock(true);$fixture->set_stock_quantity(0);$fixture->set_backorders('notify');$fixture->save();$fid=$fixture->get_id();
// Stabilize the known GUID spelling before pinning, never normalize arbitrary values.
wp_update_post(['ID'=>$fid,'post_excerpt'=>'Pinned fixture excerpt']);
$before=post_fixture_snapshot($fid);fixture_ok(count(array_filter($before['terms'],fn($term)=>$term instanceof WP_Term))===count($before['terms']),'postsave fixture has actual native WP_Term objects');
$fixture=wc_get_product($fid);$fixture->set_status('publish');$fixture->set_catalog_visibility('visible');$fixture->save();clean_post_cache($fid);$after=post_fixture_snapshot($fid);
$visibility=[];foreach(['exclude-from-search','exclude-from-catalog']as$slug){$term=get_term_by('slug',$slug,'product_visibility');$visibility[]=(int)$term->term_id;}
$expected=sa_publication_expected_state($before,$after,$visibility,false);
fixture_ok(wp_json_encode($expected)===wp_json_encode($after),'native post-save verification runs fully with WP_Term objects');
$decoded_before=json_decode(wp_json_encode($before),true);$decoded_after=json_decode(wp_json_encode($after),true);
fixture_ok(wp_json_encode(sa_publication_expected_state($decoded_before,$decoded_after,$visibility,false))===wp_json_encode($decoded_after),'postsave verifier also accepts recovered JSON array terms');
$with_marker=$after;$with_marker['meta']['_sm_campaign_created']=['1'];ksort($with_marker['meta']);fixture_ok(wp_json_encode(sa_publication_expected_state($before,$with_marker,$visibility,true))===wp_json_encode($with_marker),'known marker delta checked after native save');
foreach(['guid','post_date','post_content','post_title']as$key){$bad=$after;$bad['post'][$key].='UNEXPECTED';fixture_ok(wp_json_encode(sa_publication_expected_state($before,$bad,$visibility,false))!==wp_json_encode($bad),'postsave rejects unexpected '.$key);}
$bad=$after;$bad['meta']['_price']=['1.00'];fixture_ok(wp_json_encode(sa_publication_expected_state($before,$bad,$visibility,false))!==wp_json_encode($bad),'postsave rejects price drift');
$bad=$after;$bad['terms'][]=(object)['term_id'=>999999,'taxonomy'=>'product_cat'];fixture_ok(wp_json_encode(sa_publication_expected_state($before,$bad,$visibility,false))!==wp_json_encode($bad),'postsave rejects unexpected added term');
