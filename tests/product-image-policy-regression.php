<?php
/** No network, database or writes. Known-source image hold contract. */
define('ABSPATH',__DIR__);function add_filter(...$a){$GLOBALS['filters'][$a[0]][]=$a[1];}function add_action(...$a){}function get_post_meta($id,$key,$single){if($key==='_sku')return $id===65500?'EBCBSD7801':'OTHER';if($key==='_sa_shopify_image_urls')return '["https://cdn.shopify.com/other.jpg"]';return '';}
class WC_Product{private $id;function __construct($id){$this->id=$id;}function get_id(){return $this->id;}function get_sku(){return get_post_meta($this->id,'_sku',true);}function get_image_id(){return 0;}}
require __DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/product-images.php';
$n=0;function check($x){global $n;$n++;if(!$x)throw new Exception('Image policy contract '.$n);}
$p=new WC_Product(65500);check(sa_core_product_image_on_hold($p));check(sa_core_get_stored_shopify_image_urls(65500)===[]);check(sa_core_get_product_email_image_url($p)==='');
foreach($GLOBALS['filters']['woocommerce_product_get_image'] as $cb)check($cb('original',$p,'thumbnail',[],false,false)==='');
$GLOBALS['product']=$p;check($GLOBALS['filters']['woocommerce_single_product_image_thumbnail_html'][0]('gallery',0)==='');
check(!sa_core_product_image_on_hold(new WC_Product(1)));check(sa_core_get_stored_shopify_image_urls(1)===['https://cdn.shopify.com/other.jpg']);
$s=file_get_contents(__DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/google-product-feed.php');check(strpos($s,"sa_core_product_image_on_hold(\$product)) return ''")!==false);check(strpos($s,'Merchant Center requires image_link; never substitute a placeholder')!==false);
echo "PASS $n known-image hold email/product/gallery/feed contracts; unrelated URLs preserved.\n";
require __DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/google-product-feed.php';
$bad='<item><g:id>EBCBSD7801</g:id><g:image_link>bad</g:image_link></item>';$good='<item><g:id>OTHER</g:id><g:description>EBCBSD7801 mentioned only</g:description></item>';check(sa_gpf_apply_image_holds($good.$bad)===$good);check(sa_gpf_apply_image_holds('<item><g:id>ALT</g:id><g:mpn><![CDATA[EBCBSD7801]]></g:mpn></item>')==='');echo "PASS cached-feed exact-SKU removal; unrelated text preserved\n";
