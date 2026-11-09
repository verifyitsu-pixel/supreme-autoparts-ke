<?php
const ABSPATH='fixture/';
function esc_html_e($s,$d=''){echo htmlspecialchars($s,ENT_QUOTES);}
function esc_html__($s,$d=''){return htmlspecialchars($s,ENT_QUOTES);}
function esc_url($s){return htmlspecialchars($s,ENT_QUOTES);}
function home_url($p){return 'https://www.supremeautoparts.co.ke'.$p;}
function sa_storefront_country(){return $GLOBALS['country'];}
function ok($b,$s){if(!$b)throw new Exception($s);echo "PASS $s\n";}
foreach(['KE','US','GB'] as $country){
 $GLOBALS['country']=$country;$sa_is_ke=$country==='KE';ob_start();require __DIR__.'/../wp-content/themes/supreme-autoparts/template-parts/home-trust.php';$html=ob_get_clean();
 ok(substr_count($html,'<article class="sa-trust-card">')===6,'six benefits '.$country);
 ok(substr_count($html,'<svg ')===6&&substr_count($html,'aria-hidden="true" focusable="false"')===6,'decorative SVG icons '.$country);
 ok(str_contains($html,'Fees and exclusions apply.')&&str_contains($html,'/returns/'),'qualified returns and policy '.$country);
 ok(!str_contains($html,'Pesapal')&&!str_contains($html,'guarantee')&&!str_contains($html,'51,000'),'no stale processor or blanket claims '.$country);
 ok(str_contains($html,'https://wa.me/supremeautopart'),'approved WhatsApp route '.$country);
 ok(str_contains($html,'Car &amp; motorcycle parts'),'motorcycle visibility '.$country);
 ok(str_contains($html,'sa-ed-why'),'original section anchor '.$country);
 if($country==='KE')ok(str_contains($html,'Delivery across Kenya')&&str_contains($html,'Courier rates shown')&&str_contains($html,'M-Pesa &amp; card')&&!str_contains($html,'Free US'),'Kenya rules');
 elseif($country==='US')ok(str_contains($html,'Free US shipping')&&str_contains($html,'No minimum order.')&&!str_contains($html,'M-Pesa'),'US rules');
 else ok(str_contains($html,'International delivery')&&!str_contains($html,'Free US')&&!str_contains($html,'M-Pesa'),'other regions no free shipping promise');
 if(getenv('SA_TRUST_RENDER_DIR'))file_put_contents(getenv('SA_TRUST_RENDER_DIR').'/'.$country.'.html',$html);
}
$s=file_get_contents(__DIR__.'/../wp-content/themes/supreme-autoparts/front-page.php');ok(substr_count($s,"include get_template_directory() . '/template-parts/home-trust.php'")===1,'one exact section include');
$css=file_get_contents(__DIR__.'/../wp-content/themes/supreme-autoparts/assets/css/editorial.css');ok(str_contains($css,'grid-template-columns:repeat(3,minmax(0,1fr))')&&str_contains($css,'grid-template-columns:repeat(2,minmax(0,1fr))'),'desktop and phone grids');
