<?php
// Isolated fixture: visible buying help and matching category entity graph.
if (DB_NAME !== 'catalog_staging_it') throw new RuntimeException('Isolated DB required');
require_once SA_CORE_DIR . 'includes/seo.php';
$cat=wp_insert_term('Camber Kits','product_cat',['slug'=>'camber-kits','description'=>'Compare camber-kit pack contents and vehicle fitment.']);
if(is_wp_error($cat))$cat=['term_id'=>(int)get_term_by('slug','camber-kits','product_cat')->term_id];
$ctid=(int)$cat['term_id'];wp_set_object_terms($fid,[$ctid],'product_cat');
$guideid=wp_insert_post(['post_type'=>'page','post_name'=>'fitment-guide','post_title'=>'Fitment guide','post_status'=>'publish','post_content'=>'Fixture buying checks']);
$GLOBALS['wp_query']=new WP_Query(['post_type'=>'product','product_cat'=>'camber-kits']);$GLOBALS['wp_the_query']=$GLOBALS['wp_query'];
$term=get_term($ctid,'product_cat');$url=get_term_link($term);$entity=sa_seo_collection_entity(['title'=>'not substituted'], $url,home_url('/#website'),home_url('/#organization'));
fixture_ok($entity['@type']==='CollectionPage'&&$entity['name']==='Camber Kits'&&$entity['url']===$url,'category entity is actual existing archive, not new keyword URL');
fixture_ok($entity['isPartOf']['@id']===home_url('/#website')&&$entity['publisher']['@id']===home_url('/#organization'),'category entity links to same website and organization');
fixture_ok($entity['description']===$term->description&&!isset($entity['offers'],$entity['aggregateRating']),'category schema uses actual visible description and no invented offers/reviews');
ob_start();sa_seo_camber_buying_help();$help=ob_get_clean();fixture_ok(str_contains($help,'Choosing a camber kit')&&str_contains($help,'one arm, a pair')&&str_contains($help,esc_url(get_permalink($guideid))),'visible intent help links to actual published fitment guide');
$GLOBALS['wp_query']->set('paged',2);$entity=sa_seo_collection_entity([], $url.'page/2/',home_url('/#website'),home_url('/#organization'));fixture_ok(!isset($entity['description'])&&$entity['url']===$url.'page/2/','page2 entity keeps supplied selfcanonical without invisible firstpage description');ob_start();sa_seo_camber_buying_help();fixture_ok(ob_get_clean()==='','buying help not repeated on pagination');
$GLOBALS['wp_query']=new WP_Query(['post_type'=>'product','s'=>'fixture']);$GLOBALS['wp_the_query']=$GLOBALS['wp_query'];fixture_ok(sa_seo_collection_entity([],home_url('/?s=fixture'),'', '')===[],'no category entity on search/noindex page');ob_start();sa_seo_camber_buying_help();fixture_ok(ob_get_clean()==='','no camber copy on search');
