<?php
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
define('SA_CORE_DIR', __DIR__ . '/../wp-content/plugins/supreme-autoparts-core/');
function add_action(...$args): void {}
function add_filter(...$args): void {}
function sanitize_text_field($v): string { return trim(strip_tags((string) $v)); }
function wp_unslash($v) { return $v; }
function sa_fargo_norm(string $v): string { return strtolower(trim($v)); }
function sa_fargo_county_name(string $v): string { return ['KE13'=>'Kiambu','KE30'=>'Nairobi'][$v] ?? ''; }
require SA_CORE_DIR . 'includes/courier-shipping.php';
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$stations=sa_station_records('g4s');
$station=current(array_filter($stations, static fn($s)=>$s['county']==='Kiambu'));
check(is_array($station),'Kiambu source record exists');
$_POST=['shipping_method'=>['sa_g4s_pickup:1'],'billing_country'=>'KE','billing_state'=>'KE13','billing_city'=>$station['town'],'sa_pickup_station'=>$station['id']];
check(sa_selected_station()['id']===$station['id'],'valid explicit station accepted');
$_POST['billing_city']='Another town in Kiambu';check(sa_selected_station()['id']===$station['id'],'explicit other-town station in same county accepted');
$_POST['sa_pickup_station']='';check(sa_selected_station()===null,'blank rejected');
$_POST['sa_pickup_station']='g4s:invented';check(sa_selected_station()===null,'forged ID rejected');
$_POST['sa_pickup_station']=$station['id'];$_POST['shipping_method']=['sa_speedaf_pickup:1'];check(sa_selected_station()===null,'other carrier ID rejected');
$_POST['shipping_method']=['sa_g4s_pickup:1'];$_POST['billing_state']='KE30';$_POST['billing_city']='Nairobi';check(sa_selected_station()===null,'wrong destination rejected');
$_POST['ship_to_different_address']='1';$_POST['shipping_country']='KE';$_POST['shipping_state']='KE13';$_POST['shipping_city']=$station['town'];check(sa_selected_station()['id']===$station['id'],'actual shipping destination used');
$_POST['shipping_country']='US';check(sa_selected_station()===null,'non Kenya rejected');
$_POST['shipping_country']='KE';$_POST['shipping_method']=['sa_g4s_door:1'];check(sa_selected_station()===null,'door not station pickup');
echo "PASS valid/blank/forged/wrong-carrier/wrong-destination/different-shipping/non-Kenya/door station validation\n";
