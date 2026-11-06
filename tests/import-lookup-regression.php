<?php
// Importer existence lookups must fail closed (never "no match" on a DB error) and the bulk registry must be exact.
declare(strict_types=1);
const ABSPATH='fixture/';const ARRAY_A='ARRAY_A';
class WPDBStub{public $last_error='';public $postmeta='pm';public $rows=[];public $fail=false;public $queries=[];
 function prepare($s,...$a){$this->queries[]=[$s,$a];return $s;}
 function get_results($q,$m){if($this->fail){$this->last_error='Query execution was interrupted';return null;}return array_shift($this->rows)??[];}}
$GLOBALS['wpdb']=new WPDBStub;
$GLOBALS['posts']=[];$GLOBALS['gp']=[];$GLOBALS['gp_err']=false;
function get_posts($a){$GLOBALS['gp'][]=$a;if(($GLOBALS['gp_err_at']??0)===count($GLOBALS['gp'])){$GLOBALS['wpdb']->last_error='timeout';return [];}if($GLOBALS['gp_err']){$GLOBALS['wpdb']->last_error='timeout';return [];}return array_shift($GLOBALS['gpret'])??[];}
function get_post($id){return $GLOBALS['posts'][$id]??null;}
function update_post_meta(...$a){}
require __DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/product-fingerprint.php';
require __DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/import-lookup-registry.php';
function ok($b,$m){if(!$b)throw new Exception($m);echo "PASS $m\n";}
function throws(callable $f,$m){try{$f();}catch(RuntimeException $e){echo "PASS $m\n";return;}throw new Exception("no throw: $m");}
// 1) plain mode
$GLOBALS['gpret']=[[77]];ok(sa_core_import_meta_lookup('_sa_shopify_id','5','any')===77,'plain: match returns first ID');
$GLOBALS['gpret']=[[]];ok(sa_core_import_meta_lookup('_sa_shopify_id','5','any')===0,'plain: clean empty result is no match');
$GLOBALS['gp_err']=true;throws(fn()=>sa_core_import_meta_lookup('_sa_shopify_id','5','any'),'plain: DB error throws, not "no match"');
throws(fn()=>sa_core_find_published_by_fingerprint('some-fp'),'fingerprint finder: DB error on meta lookup throws');
$GLOBALS['gp_err']=false;
$GLOBALS['wpdb']->last_error='';$GLOBALS['gp']=[];$GLOBALS['gpret']=[[],[]];$GLOBALS['gp_err_at']=2;throws(fn()=>sa_core_find_published_by_fingerprint('some-fp'),'fingerprint finder: DB error on the recent-title scan throws');$GLOBALS['gp_err_at']=0;$GLOBALS['wpdb']->last_error='';
$GLOBALS['gp']=[];$GLOBALS['gpret']=[[],[]];ok(sa_core_find_published_by_fingerprint('some-fp')===0,'fingerprint finder: clean miss returns 0');
$GLOBALS['gp']=[];$GLOBALS['gpret']=[[]];
// 2) registry mode
$db=$GLOBALS['wpdb'];
$db->rows=[[['meta_id'=>1,'post_id'=>33,'match_token'=>'m0'],['meta_id'=>2,'post_id'=>40,'match_token'=>'m0,m1'],['meta_id'=>3,'post_id'=>9,'match_token'=>null],['meta_id'=>4,'post_id'=>50,'match_token'=>'m2']]];
$r=new KenyaImportLookup([['_sa_shopify_id','ABC'],['_sa_shopify_id','abc '],['_sa_title_fingerprint','absent']]);
$r->scan($db);
$sql=$db->queries[0][0];ok(substr_count($sql,'IF(meta_key=')===3&&str_contains($sql,'FORCE INDEX(PRIMARY)')&&str_contains($sql,'LIMIT 5000'),'registry: one bounded PK-page query, one IF per wanted pair');
ok($r->lookup('_sa_shopify_id','ABC')===[33,40]&&$r->lookup('_sa_shopify_id','abc ')===[40],'registry: DB-collation matches kept per wanted value');
ok($r->lookup('_sa_title_fingerprint','absent')===[50],'registry: token routed to its own key/value');
throws(fn()=>$r->lookup('_sa_shopify_id','unknown'),'registry: unknown value throws');
throws(fn()=>$r->lookup('_sa_shopify_handle','x'),'registry: unknown key throws');
$GLOBALS['sa_core_import_lookup_registry']=$r;
$GLOBALS['posts']=[33=>(object)['post_type'=>'product','post_status'=>'trash'],40=>(object)['post_type'=>'product','post_status'=>'draft'],50=>(object)['post_type'=>'post','post_status'=>'publish']];
ok(sa_core_import_meta_lookup('_sa_shopify_id','ABC','any')===40,'registry: trashed skipped, lowest live ID wins (any)');
ok(sa_core_import_meta_lookup('_sa_shopify_id','ABC','publish')===0,'registry: publish-only ignores draft');
ok(sa_core_import_meta_lookup('_sa_shopify_id','ABC','any',40)===0,'registry: exclude ID honoured');
ok(sa_core_import_meta_lookup('_sa_title_fingerprint','absent','publish')===0,'registry: non-product match ignored');
throws(fn()=>sa_core_import_meta_lookup('_sa_shopify_id','never-scanned','any'),'registry mode: unscanned value throws instead of no match');
unset($GLOBALS['sa_core_import_lookup_registry']);
// 3) failures
$db->fail=true;$r2=new KenyaImportLookup([['_sa_shopify_id','1']]);throws(fn()=>$r2->scan($db),'registry: DB error on scan throws');$db->fail=false;
throws(fn()=>$r2->lookup('_sa_shopify_id','1'),'registry: lookup before successful scan throws');
throws(fn()=>$r2->scan($db,-1.0),'registry: time cap throws');
throws(fn()=>new KenyaImportLookup([]),'registry: empty pairs rejected');
throws(fn()=>new KenyaImportLookup(array_map(fn($i)=>['_sku',(string)$i],range(1,151))),'registry: more than 150 pairs rejected');
$r3=new KenyaImportLookup([['_sku','a']]);$x=['id'=>1,'handle'=>'h-1','variants'=>[['sku'=>'ABC']]];$r3->claimItem($x);$y=['id'=>2,'handle'=>'h-2','variants'=>[['sku'=>'abc ']]];
throws(fn()=>$r3->claimItem($y),'in-batch: repeated identity (case/space) refused');
throws(fn()=>(new KenyaImportLookup([['_sku','a']]))->claimItem(['id'=>3,'handle'=>'h','variants'=>[['sku'=>'ÀBC']]]),'in-batch: unsupported characters refused');
