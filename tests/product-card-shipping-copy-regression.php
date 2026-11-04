<?php
// Exercise the actual helper without booting WordPress or sending traffic.
function __($s,$domain){return $s;}
class WC_Product {public $stock=true; public $backorder=false; function is_in_stock(){return $this->stock;} function is_on_backorder($qty){return $this->backorder;}}
$s=file_get_contents(__DIR__.'/../wp-content/themes/supreme-autoparts/inc/woocommerce.php');
$start=strpos($s,'function sa_theme_product_card_meta(');$end=strpos($s,"\n}",$start)+2;
eval(substr($s,$start,$end-$start));
$p=new WC_Product;assert(sa_theme_product_card_meta($p)==='In stock · Ships across Kenya and the US');
$p->backorder=true;assert(sa_theme_product_card_meta($p)==='Available on backorder · Ships across Kenya and the US');
$p->stock=false;assert(sa_theme_product_card_meta($p)==='Out of stock');
echo "PASS stock/backorder shipping reach and out-of-stock preservation\n";
