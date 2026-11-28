<?php
// Isolated content correction. No network, database or store writes.
define('ABSPATH', __DIR__);
$hooks=[];$slug='fitment-guide';$main=true;$loop=true;
function add_filter($n,$f,$p=10){global $hooks;$hooks[$n][$p]=$f;}
function is_page($s){global $slug;return $slug===$s;}
function is_main_query(){global $main;return $main;}
function in_the_loop(){global $loop;return $loop;}
require __DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/page-content.php';
$f=$hooks['the_content'][21];
$fresh=sa_core_page_definitions()['fitment-guide']['content'];
if(str_contains($fresh,'#1 return')||!str_contains($fresh,'Check fitment before you order.')||$f($fresh)!==$fresh)throw new RuntimeException('Fresh guide unsupported or modified twice');
$old='<p>Wrong fitment is the #1 return driver. Spend two minutes here before you click buy</p><h2>Match these fields</h2>';
$out=$f($old);
if(str_contains($out,'#1 return')||!str_contains($out,'Check fitment before you order. Use this guide before you click buy')||!str_contains($out,'<h2>Match these fields</h2>')||$f($out)!==$out)throw new RuntimeException('Stored correction or idempotence');
foreach(['guides','returns','product','checkout','cart'] as $slug)if($f($old)!==$old)throw new RuntimeException('Unrelated route changed');
$slug='fitment-guide';$main=false;if($f($old)!==$old)throw new RuntimeException('Secondary query changed');
$main=true;$loop=false;if($f($old)!==$old)throw new RuntimeException('Outside loop changed');
echo "PASS fresh/stored fitment guide removes unsupported ranking, retains remaining content, idempotent, main-loop and route guards\n";
