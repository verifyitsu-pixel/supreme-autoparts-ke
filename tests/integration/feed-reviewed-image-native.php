<?php
// Only included by the isolated WordPress harness guarded by its database and site URL.
$id=wc_get_product_id_by_sku('FIXTURE-1');$d=wc_get_product($id);$before=[$d->get_data(),get_post_meta($id)];
$old='https://www.supremeautoparts.co.ke/wp-content/uploads/2026/09/b969490e6d89e90af3c6794a027bb7adL.jpg';$new='https://cdn.shopify.com/s/files/1/0643/0779/0001/files/b969490e6d89e90af3c6794a027bb7adL.jpg?v=1789536517&width=1200';$link='https://www.supremeautoparts.co.ke/product/icon-2024-toyota-tacoma-1-25-3in-stage-9-suspension-system-billet/';
fixture_ok(sa_gpf_reviewed_image_url('ICOK53299',$link,$old)===$new,'native exact reviewed hotlink resolver');
$xml='<item><g:id>ICOK53299</g:id><g:link>'.esc_html($link).'</g:link><g:image_link>'.esc_html($old).'</g:image_link></item>';
$corrected=sa_gpf_apply_reviewed_images($xml);$parsed=simplexml_load_string('<rss xmlns:g="http://base.google.com/ns/1.0"><channel>'.$corrected.'</channel></rss>');
fixture_ok($parsed!==false&&(string)$parsed->channel->item->children('g',true)->image_link===$new,'native XML roundtrip query ampersand escaped correctly');
fixture_ok([$d->get_data(),get_post_meta($id)]===$before,'native product data and metadata untouched');

// Synthetic isolated native kit. Production source evidence is never overwritten.
$kit=new WC_Product_Simple();$kit->set_name('Bilstein B8 5162 exact fixture');$kit->set_sku('BIL46-276827');$kit->set_regular_price('968.00');$kit->set_catalog_visibility('visible');$kit->set_status('publish');$kit->set_stock_status('outofstock');$kitid=$kit->save();
$source='https://cdn.shopify.com/s/files/1/0643/0779/0001/products/1867041792ea567cd6ae666049036e04_459d7740-d13b-4f5d-9bb0-97cadd2c6899.jpg?v=1707567107';$raw=wp_json_encode([$source,'https://test.invalid/promo.jpg']);update_post_meta($kitid,'_sa_shopify_image_urls',wp_slash($raw));
$photo='https://cart.bilsteinus.com/media/catalog/product/a/p/apa_46-276827_1_20261103121034.jpg';$url='https://www.supremeautoparts.co.ke/product/bilstein-b8-5162-series-17-18-ford-f-250-f-350-front-monotube-suspension-leveling-kit-for-2in-lift/';
add_filter('post_type_link',static fn($link,$post)=>$post->ID===$kitid?$url:$link,10,2);
fixture_ok(sa_core_get_stored_shopify_image_urls($kitid)===[$photo],'native exact supplier promotional panel replaced by verified maker kit photo');fixture_ok(get_post_meta($kitid,'_sa_shopify_image_urls',true)===$raw,'supplier evidence unchanged');
$kit=wc_get_product($kitid);$before=[$kit->get_data(),get_post_meta($kitid)];$GLOBALS['product']=$kit;$gallery=apply_filters('woocommerce_single_product_image_thumbnail_html','',0);fixture_ok(str_contains($gallery,$photo)&&substr_count($gallery,'sa-shopify-cdn-photo')===1&&!str_contains($gallery,'promo.jpg'),'native actual gallery has only exact maker photo');
ob_start();sa_gpf_render_item($kitid);$feed=ob_get_clean();fixture_ok(str_contains($feed,'<g:image_link>'.$photo.'</g:image_link>')&&str_contains($feed,'<g:price>968.00 USD</g:price>')&&str_contains($feed,'<g:availability>out_of_stock</g:availability>'),'native real feed entry preserves kit price and stock with clean image');
$markup=sa_gpf_enrich_product_schema(['image'=>['https://test.invalid/promo.jpg']],$kit);fixture_ok($markup['image']===[$photo],'native schema clean image matches gallery and feed');fixture_ok([$kit->get_data(),get_post_meta($kitid)]===$before,'rendering changes no native product data or metadata');
update_post_meta($kitid,'_sa_shopify_image_urls',wp_json_encode(['https://test.invalid/different.jpg']));fixture_ok(sa_core_get_stored_shopify_image_urls($kitid)===['https://test.invalid/different.jpg'],'exact SKU different supplier photo not globally filtered');
