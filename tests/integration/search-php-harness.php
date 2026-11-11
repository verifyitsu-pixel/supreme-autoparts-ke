<?php
declare(strict_types=1);
// Isolated harness only. Executes extracted original functions, never WordPress bootstrap.
const HOUR_IN_SECONDS=3600;
const MINUTE_IN_SECONDS=60;
$mode=$argv[1];$count=(int)$argv[2];
$source=file_get_contents(__DIR__.'/../../wp-content/plugins/supreme-autoparts-core/includes/site-forms.php');
if(hash('sha256',$source)!=='b02dc9fac3e46f147987daf533004e95e191c35696f60902c4ad602384c30d63'){throw new Exception('deployed source identity drift');}
function extract_function(string $source,string $name):string{
 if(!preg_match('/^function '.preg_quote($name,'/').'\(.*?^\}/ms',$source,$m)){throw new Exception('missing function '.$name);}
 return $m[0];
}
$names=['sa_sf_suggest','sa_sf_fuzzy_enabled','sa_sf_fuzzy_product_ids','sa_sf_search_tokens','sa_sf_search_index','sa_sf_search_index_build'];
foreach($names as $name){
 $code=extract_function($source,$name);
 if($mode==='candidate'&&$name==='sa_sf_search_index_build'){
  $old=$code;
  $code=str_replace('$page = 0;', '$page = 0; $last_id = 0;', $code,$n);if($n!==1)throw new Exception('page patch mismatch');
  $code=str_replace("ORDER BY p.ID LIMIT \" . (\$page * 500) . ', 500'", "AND p.ID > \" . (int) \$last_id . \" ORDER BY p.ID LIMIT 500\"",$code,$n);if($n!==1)throw new Exception('query patch mismatch');
  $code=str_replace('foreach ((array) $rows as $r) {','foreach ((array) $rows as $r) { $last_id = (int) $r->ID;',$code,$n);if($n!==1)throw new Exception('cursor patch mismatch');
  // No edits to token, scoring, SKU normalization, packing, TTL or cap.
  file_put_contents('/tmp/search-builder-candidate.php',"<?php\n".$code."\n");
 }
 eval($code);
}
$fixtures=[];$titles=['FOX Shocks 2024 Lift Kit','NGK CPR8EAIX-9 Spark Plug','Brake Pads Toyota Hilux','Liqui Moly 5W30 Oil','Steering Wheel','!!!','x',str_repeat('a',65),'Café résumé filter','350 2024 9000','Racing &amp; Safety Helmet'];
for($i=1;$i<=$count;$i++){
 $fixtures[$i]=(object)['ID'=>$i,'post_title'=>$titles[$i%count($titles)].' '.($i%8===0?'':(string)$i),'sku'=>$i%19===0?null:($i===2?'DUPLICATE-FIRST':'SKU-'.str_pad((string)$i,6,'0',STR_PAD_LEFT)),'published'=>$i%10!==0,'type'=>$i%13===0?'revision':'product','scope'=>$i%2?'parts':'gear','visible'=>$i%17!==0];
}
class FakeDB{
 public string $posts='fake_posts';public string $postmeta='fake_meta';public array $queries=[];
 function get_results(string $sql):array{
  global $fixtures;$this->queries[]=$sql;$offset=0;$cursor=0;
  if(preg_match('/LIMIT (\d+), 500/',$sql,$m))$offset=(int)$m[1];
  elseif(preg_match('/AND p.ID > (\d+) ORDER BY p.ID LIMIT 500/',$sql,$m))$cursor=(int)$m[1];
  else throw new Exception('unexpected SQL');
  $rows=[];foreach($fixtures as $r){if($r->type==='product'&&$r->published&&$r->ID>$cursor)$rows[]=$r;}
  return array_slice($rows,$offset,500);
 }
}
$wpdb=new FakeDB();$transients=[];
function get_transient($key){global $transients;return $transients[$key]['value']??false;}
function set_transient($key,$value,$ttl){global $transients;$transients[$key]=['value'=>$value,'ttl'=>$ttl];return true;}
function apply_filters($name,$default){return $default;}
function sanitize_text_field($s){return $s;}
function wp_unslash($s){return $s;}
function sa_sf_intent_plan($q){return null;} // Fixed no-intent boundary, same in both modes.
class WP_Query{public array $posts=[];function __construct(array $args){global $fixtures;foreach($fixtures as $r){if(!$r->published||$r->type!=='product')continue;if(stripos($r->post_title,$args['s'])===false)continue;if(isset($args['tax_query'])&&$r->scope!==$args['tax_query'][0]['terms'])continue;$this->posts[]=$r->ID;if(count($this->posts)===6)break;}}}
function has_term($scope,$taxonomy,$id){global $fixtures;return $fixtures[$id]->scope===$scope;}
class FakeProduct{function __construct(public object $row){}function is_visible(){return $this->row->visible&&$this->row->published&&$this->row->type==='product';}function get_name(){return $this->row->post_title;}}
function wc_get_product($id){global $fixtures;return isset($fixtures[$id])?new FakeProduct($fixtures[$id]):false;}
function get_permalink($id){return 'https://fixture.invalid/product/'.$id;}
class JsonExit extends Exception{function __construct(public array $payload){parent::__construct();}}
function wp_send_json_success($out){throw new JsonExit($out);}
sa_sf_search_index_build();
$packed=$transients['sa_search_index_v2']['value']??null;$ttl=$transients['sa_search_index_v2']['ttl']??null;$decoded=sa_sf_search_index();$results=[];
if($count<=501){
 foreach(['FOX','shok','brak pads','NGK','SKU-000002','SKU-000','2024','helmet','zzzz noresult','ca','!!!']as $q){
  $results['fuzzy:'.$q]=sa_sf_fuzzy_product_ids($q,12);
  foreach(['all','parts','gear']as $scope){$_GET=['q'=>$q,'sa_cat'=>$scope];try{sa_sf_suggest();throw new Exception('no response');}catch(JsonExit $e){$results['suggest:'.$q.':'.$scope]=$e->payload;}}
 }
}
// Cold-cache boundary also executes unchanged original suggestion code.
$transients=[];$_GET=['q'=>'FOX','sa_cat'=>'all'];try{sa_sf_suggest();}catch(JsonExit $e){$results['cold_literal']=$e->payload;}
$expected=[];foreach($fixtures as $r){if($r->published&&$r->type==='product')$expected[]=$r->ID;}$expected=array_slice($expected,0,100000);
$expected=array_values(array_filter($expected,function($id)use($fixtures){$t=sa_sf_search_tokens($fixtures[$id]->post_title);return count(array_filter($t,fn($x)=>strlen($x)<=60))>0;}));
if(array_keys($decoded)!==$expected)throw new Exception('index coverage mismatch');
echo json_encode(['mode'=>$mode,'fixture_count'=>$count,'packed_sha256'=>$packed===null?null:hash('sha256',$packed),'serialized_sha256'=>hash('sha256',serialize($decoded)),'indexed_count'=>count($decoded),'query_count'=>count($wpdb->queries),'ttl'=>$ttl,'results'=>$results],JSON_THROW_ON_ERROR);
