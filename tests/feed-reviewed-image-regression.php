<?php
const ABSPATH='fixture/';function add_action(...$a){}function add_filter(...$a){}
require __DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/google-product-feed.php';
function ok($b,$m){if(!$b)throw new RuntimeException($m);echo "PASS $m\n";}
$old='https://www.supremeautoparts.co.ke/wp-content/uploads/2026/09/b969490e6d89e90af3c6794a027bb7adL.jpg';$new='https://cdn.shopify.com/s/files/1/0643/0779/0001/files/b969490e6d89e90af3c6794a027bb7adL.jpg?v=1789536517&width=1200';$link='https://www.supremeautoparts.co.ke/product/icon-2024-toyota-tacoma-1-25-3in-stage-9-suspension-system-billet/';
foreach([$link,$link.'?sa_market=ke'] as $url){
 ok(sa_gpf_reviewed_image_url('ICOK53299',$url,$old)===$new,'exact verified SKU page and broken image replaced');
 $item='<item><g:id>ICOK53299</g:id><g:link>'.sa_gpf_xml($url).'</g:link><g:image_link>'.sa_gpf_xml($old).'</g:image_link><g:price>7383.73 USD</g:price><g:availability>in_stock</g:availability></item>';
 $expected=str_replace(sa_gpf_xml($old),sa_gpf_xml($new),$item);
 ok(sa_gpf_apply_reviewed_images($item)===$expected,'cached correction exact image only preserves price and stock');
 ok(sa_gpf_apply_reviewed_images($expected)===$expected,'idempotent corrected chunk');
 foreach(['ICOK53299S','OTHER','ICOK532990'] as $sku)ok(sa_gpf_apply_reviewed_images(str_replace('ICOK53299</g:id>',$sku.'</g:id>',$item))===str_replace('ICOK53299</g:id>',$sku.'</g:id>',$item),'nearby SKU untouched '.$sku);
 ok(sa_gpf_apply_reviewed_images(str_replace('<g:id>ICOK53299</g:id>','<g:id>OTHER</g:id><g:description>ICOK53299</g:description>',$item))===str_replace('<g:id>ICOK53299</g:id>','<g:id>OTHER</g:id><g:description>ICOK53299</g:description>',$item),'description cannot match identity');
 ok(sa_gpf_apply_reviewed_images('<item><g:id>SPC67416</g:id></item>'.$item)===('<item><g:id>SPC67416</g:id></item>'.$expected),'unrelated record unchanged');
 ok(sa_gpf_apply_reviewed_images(sa_gpf_apply_image_holds('<item><g:id>SPC67416</g:id></item>'.$item))===$expected,'existing exact unknown lead holds retained');
}
foreach([['ICOK53299S',$link,$old],['ICOK53299',$link.'?other=1',$old],['ICOK53299',$link,$new],['ICOK53299',$link,'https://other.invalid/image.jpg']] as $v)ok(sa_gpf_reviewed_image_url(...$v)===$v[2],'other SKU link or image unchanged');
foreach(['','<item><g:id>ICOK53299</g:id></item>','<item><g:id>ICOK53299</g:mpn></item>'] as $v)ok(sa_gpf_apply_reviewed_images($v)===$v,'empty/malformed unchanged');
$s=file_get_contents(__DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/google-product-feed.php');ok(str_contains($s,'$image = sa_gpf_reviewed_image_url($sku, $link, sa_gpf_image_url($product));'),'live render shares exact reviewed resolver');
