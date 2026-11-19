<?php
// Isolated source/helper/content regression: no network, database or message sends.
$root = dirname(__DIR__);
$src = file_get_contents($root . '/wp-content/plugins/sa-geo-currency/sa-geo-currency.php');
$a = strpos($src, "if (!function_exists('sa_whatsapp_username'))");
$b = strpos($src, "if (!function_exists('sa_whatsapp_icon'))", $a);
eval(substr($src, $a, $b-$a));
if (sa_whatsapp_username() !== '254714498451' || sa_whatsapp_url() !== 'https://wa.me/254714498451') throw new RuntimeException('Wrong destination');
$msg = "Fitment: 2020 Mazda & 4x4?\nPart #123";
if (sa_whatsapp_url($msg) !== 'https://wa.me/254714498451?text=' . rawurlencode($msg)) throw new RuntimeException('Prefill changed');
$hooks=[];
function add_filter($tag,$fn,$priority=10) { global $hooks; $hooks[$tag][$priority]=$fn; }
$a = strpos($src, '/** Correct legacy links in existing saved page bodies');
eval(substr($src, $a));
$f=$hooks['the_content'][99];
$old = '<a href="https://wa.me/' . 'supremeautopart?text=Part%20%26%20123">Chat on WhatsApp</a><p>Policy unchanged</p>';
$want=str_replace('supremeautopart?','254714498451?',$old);
if ($f($old)!==$want || $f($want)!==$want || $f('Unrelated content')!=='Unrelated content') throw new RuntimeException('Stored-page replacement drift');
foreach (['themes/supreme-autoparts','plugins/supreme-autoparts-core','plugins/supreme-marketing','plugins/sa-geo-currency'] as $dir) {
 foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/wp-content/'.$dir)) as $p) {
  if (!$p->isFile() || !in_array($p->getExtension(),['php','js'],true)) continue;
  if (str_contains(file_get_contents($p->getPathname()),'https://wa.me/' . 'supremeautopart')) throw new RuntimeException('Legacy source link: '.$p);
 }
}
echo "PASS confirmed WhatsApp business destination, prefill bytes, stored-page idempotence and deployed source coverage; no policy/message mutation\n";
