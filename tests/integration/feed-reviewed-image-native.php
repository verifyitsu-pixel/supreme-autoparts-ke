<?php
// Only included by the isolated WordPress harness guarded by its database and site URL.
$id=wc_get_product_id_by_sku('FIXTURE-1');$d=wc_get_product($id);$before=[$d->get_data(),get_post_meta($id)];
$old='https://www.supremeautoparts.co.ke/wp-content/uploads/2026/09/b969490e6d89e90af3c6794a027bb7adL.jpg';$new='https://cdn.shopify.com/s/files/1/0643/0779/0001/files/b969490e6d89e90af3c6794a027bb7adL.jpg?v=1789536517&width=1200';$link='https://www.supremeautoparts.co.ke/product/icon-2024-toyota-tacoma-1-25-3in-stage-9-suspension-system-billet/';
fixture_ok(sa_gpf_reviewed_image_url('ICOK53299',$link,$old)===$new,'native exact reviewed hotlink resolver');
$xml='<item><g:id>ICOK53299</g:id><g:link>'.esc_html($link).'</g:link><g:image_link>'.esc_html($old).'</g:image_link></item>';
$corrected=sa_gpf_apply_reviewed_images($xml);$parsed=simplexml_load_string('<rss xmlns:g="http://base.google.com/ns/1.0"><channel>'.$corrected.'</channel></rss>');
fixture_ok($parsed!==false&&(string)$parsed->channel->item->children('g',true)->image_link===$new,'native XML roundtrip query ampersand escaped correctly');
fixture_ok([$d->get_data(),get_post_meta($id)]===$before,'native product data and metadata untouched');
