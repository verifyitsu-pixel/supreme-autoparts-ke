<?php
define('ABSPATH',__DIR__);$meta=[];$actions=[];
function add_filter(...$a){}function add_action($hook,$cb,...$a){$GLOBALS['actions'][$hook][]=$cb;}
function get_post_meta($id,$key,$single){return $GLOBALS['meta'][$key]??'';}
function wp_get_attachment_image_url($id,$size){return $id===12?'https://www.supremeautoparts.co.ke/poster.jpg':'';}
function esc_url($s){return htmlspecialchars($s,ENT_QUOTES);}function esc_html($s){return htmlspecialchars($s,ENT_QUOTES);}function esc_attr($s){return htmlspecialchars($s,ENT_QUOTES);}
class WC_Product {function get_id(){return 1;}function get_sku(){return 'HCK029';}}
require __DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/product-images.php';
function check($x,$why){if(!$x)throw new Exception($why);echo "PASS $why\n";}
$product=new WC_Product;check(sa_core_gallery_video_config($product)===[],'no meta leaves products unchanged');
$meta=['_sa_gallery_video_url'=>'https://www.youtube.com/watch?v=x_PvR4tyqiA','_sa_gallery_video_poster_id'=>12,'_sa_gallery_video_label'=>'Reference <script>alert(1)</script>'];
$c=sa_core_gallery_video_config($product);check(str_contains($c['embed'],'youtube-nocookie.com/embed/x_PvR4tyqiA'),'exact privacy embed');
ob_start();foreach($actions['woocommerce_product_thumbnails'] as $cb)$cb();$html=ob_get_clean();
check(str_contains($html,'data-thumb=')&&str_contains($html,'Play '),'gallery slide has thumbnail and accessible play');
check(!str_contains($html,'<iframe')&&!str_contains($html,'<script>'),'no iframe/script before click; caption escaped');
check(str_contains($html,'&lt;script&gt;'),'untrusted caption escaped');
foreach(['javascript:alert(1)','https://evil.test/watch?v=x_PvR4tyqiA','https://www.youtube.com/watch?v=x_PvR4tyqiA&x=1',"https://www.youtube.com/watch?v=x_PvR4tyqiA\n"] as $bad){$meta['_sa_gallery_video_url']=$bad;check(sa_core_gallery_video_config($product)===[],'reject malformed or nonapproved video URL');}
$meta['_sa_gallery_video_url']='https://www.youtube.com/watch?v=x_PvR4tyqiA';$meta['_sa_gallery_video_poster_id']=0;check(sa_core_gallery_video_config($product)===[],'missing poster creates no slide');
check(sa_core_gallery_video_config(null)===[],'no product safe');
