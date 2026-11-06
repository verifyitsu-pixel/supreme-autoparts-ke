<?php
// Isolated fixture, never boot WordPress or write a database.
define('ABSPATH','/fixture/'); define('ARRAY_A','ARRAY_A');
class wpdb {
 public $posts='posts',$postmeta='postmeta',$last_error=''; public $products=[],$meta=[],$attachments=[],$fail=false;
 function prepare($q,...$v){foreach($v as $x){$q=preg_replace('/%[sd]/',is_int($x)?(string)$x:"'".$x."'",$q,1);}return $q;}
 function get_results($q,$mode){if($this->fail){$this->last_error='fixture error';return null;} $this->last_error='';
 if(str_contains($q,'SELECT ID, post_date')){ $a=$this->products; if(preg_match('/ID < (\d+)/',$q,$m))$a=array_values(array_filter($a,fn($p)=>$p['ID']<(int)$m[1]));return array_slice($a,0,250);}
 if(str_contains($q,'post_id IN (')){preg_match('/post_id IN \(([^)]+)\)/',$q,$m);$ids=array_map('intval',explode(',',$m[1]));return array_values(array_filter($this->meta,fn($r)=>in_array($r['post_id'],$ids,true)));}
 if(str_contains($q,'SELECT m.meta_id')){preg_match('/meta_id > (\d+)/',$q,$m);preg_match('/LIMIT (\d+)/',$q,$l);return array_slice(array_values(array_filter($this->attachments,fn($r)=>$r['meta_id']>(int)$m[1])),0,(int)$l[1]);}
 throw new Exception('Unexpected query'); }
}
function sa_core_image_url_identity_key(string $url):string{return strtolower(explode('?',$url)[0]);}
$s=file_get_contents($argv[1] ?? __DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/import-shopify.php');$a=strpos($s,'function sa_core_bootstrap_image_claim_registry(): void');$b=strpos($s,'function sa_core_is_image_url_claimed_by_other',$a);eval(substr($s,$a,$b-$a));
function &sa_core_image_claim_registry():array {if(!isset($GLOBALS['sa_core_image_claim_registry']))$GLOBALS['sa_core_image_claim_registry']=[];return $GLOBALS['sa_core_image_claim_registry'];}
$wpdb=new wpdb();$expected=[];$mid=1;
for($id=501;$id>=1;$id--){$wpdb->products[]=['ID'=>$id,'post_date'=>'2026-11-01 00:00:00'];$values=['_sa_shopify_image_src'=>'https://image/'.$id.'.jpg?x=1','_sa_shopify_image_urls'=>json_encode(['https://image/shared.jpg','https://image/'.$id.'-gallery.jpg']), '_sa_web_fallback_image_url'=>$id===1?'https://image/web.jpg':''];foreach($values as $k=>$v){$wpdb->meta[]=['meta_id'=>$mid++,'post_id'=>$id,'meta_key'=>$k,'meta_value'=>$v];$urls=$k==='_sa_shopify_image_urls'?json_decode($v,true):[$v];foreach($urls as $u)if($u!=='')$expected[sa_core_image_url_identity_key($u)]=$id;}}
// First duplicate meta wins, shared product URL final owner stays oldest ID.
$wpdb->meta[]=['meta_id'=>$mid++,'post_id'=>501,'meta_key'=>'_sa_shopify_image_src','meta_value'=>'https://image/ignored.jpg'];
for($i=1;$i<=501;$i++){$wpdb->attachments[]=['meta_id'=>$i,'meta_value'=>'https://image/attachment-'.$i.'.jpg','post_parent'=>$i===501?0:$i];if($i!==501)$expected['https://image/attachment-'.$i.'.jpg']=$i;}
$wpdb->attachments[]=['meta_id'=>502,'meta_value'=>'https://image/shared.jpg?x=2','post_parent'=>99];$expected['https://image/shared.jpg']=99;
sa_core_bootstrap_image_claim_registry();$actual=sa_core_image_claim_registry();ksort($actual);ksort($expected);if($actual!==$expected)throw new Exception('Registry differs');if(isset($actual['https://image/ignored.jpg']))throw new Exception('Duplicate metadata not first-wins');
echo 'PASS 501 products, 3 product pages, attachment pages, galleries, shared collision ownership, query identity, first meta, orphan attachment, no omissions: '.count($actual).' claims'.PHP_EOL;
unset($GLOBALS['sa_core_image_claim_registry'],$GLOBALS['sa_core_image_claim_registry_bootstrapped']);$wpdb->fail=true;try{sa_core_bootstrap_image_claim_registry();throw new Exception('Expected fail closed');}catch(RuntimeException $e){echo 'PASS database error fails closed'.PHP_EOL;}
