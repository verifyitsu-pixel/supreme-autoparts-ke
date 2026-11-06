<?php
// Mock-only lifecycle and fail-closed checks for KenyaBoundedTermRecount (no DB). Real-SQL equivalence: bounded-term-recount-mysql.php.
declare(strict_types=1);
const ABSPATH='x/';
class WP_Term{public $term_id=5,$term_taxonomy_id=105;}
class FDB{public $posts='p',$term_relationships='tr',$term_taxonomy='tt',$termmeta='tm',$last_error='';public $fail='';public $q=[];
 function get_col($s){$this->q[]=$s;if($this->fail&&str_contains($s,$this->fail)){$this->last_error='x';return null;}
  if(str_contains($s,'FROM tt'))return ['105'];if(str_contains($s,'FROM tr FORCE'))return ['1','2','3'];if(str_contains($s,'FROM p '))return ['1','2'];return [];}
 function get_var($s){$this->q[]=$s;return str_contains($s,'SELECT count')?'0':null;}
 function query($s){$this->q[]=$s;return 1;}}
$GLOBALS['wpdb']=new FDB;$GLOBALS['F']=[];$GLOBALS['A']=[];
function add_filter($n,$f,$p=10){$GLOBALS['F'][$n][]=$f;}function remove_filter($n,$f,$p=10){$GLOBALS['F'][$n]=array_values(array_filter($GLOBALS['F'][$n]??[],fn($x)=>$x!==$f));}
function add_action($n,$f,$p=10,$a=1){$GLOBALS['A'][$n][]=$f;}function remove_action($n,$f,$p=10){$GLOBALS['A'][$n]=array_values(array_filter($GLOBALS['A'][$n]??[],fn($x)=>$x!==$f));}
function apply_filters($n,$v,...$a){return $v;}function is_wp_error($x){return false;}function esc_sql($s){return $s;}
function wc_get_product_visibility_term_ids(){return ['exclude-from-catalog'=>99];}function get_option($k){return 'no';}function get_taxonomy($t){return null;}
function is_taxonomy_hierarchical($t){return false;}function get_ancestors($i,$t){return [];}function get_term($i,$t){return new WP_Term;}function get_term_children($i,$t){return [];}
function get_term_by($f,$v,$t){return new WP_Term;}function wp_get_object_terms($i,$t,$a){return [5];}function get_term_meta($i,$k,$s){return '';}
function update_term_meta(...$a){return 1;}function wp_cache_delete(...$a){}function clean_term_cache(...$a){}function delete_transient($k){}
require __DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/bounded-term-recount.php';
function ok($b,$m){if(!$b)throw new Exception("FAIL $m");echo "PASS $m\n";}
function throws(callable $f,$m,$needle=''){try{$f();}catch(RuntimeException $e){if($needle===''||str_contains($e->getMessage(),$needle)){echo "PASS $m\n";return;}}throw new Exception("no throw: $m");}
$b=new KenyaBoundedTermRecount;
$b->run(function($b){ok(count($GLOBALS['F']['woocommerce_product_recount_terms'])===1&&count($GLOBALS['A']['set_object_terms'])===1,'run installs request-local filter and observer');
 ok(($GLOBALS['F']['woocommerce_product_recount_terms'][0])()===false,'recount filter returns false');$b->assigned(1,[5],[105],'product_cat',false,[]);$b->assigned(1,[5],[105],'category',false,[]);
 ok($b->affected()===['product_cat'=>[5]],'observer records only product taxonomies');});
ok(!$GLOBALS['F']['woocommerce_product_recount_terms']&&!$GLOBALS['A']['set_object_terms'],'filter and observer removed after run');
try{$b->run(function(){throw new RuntimeException('boom');});}catch(RuntimeException $e){}
ok(!$GLOBALS['F']['woocommerce_product_recount_terms'],'removed even when the callback throws');
$rows=$b->compute();ok(count($rows)===1&&$rows[0]['direct']===2&&$rows[0]['woo']===2,'compute returns computed counts');
ok(!preg_grep('/^(UPDATE|INSERT|START|COMMIT)/',$GLOBALS['wpdb']->q),'compute issues no writes');
foreach(['FORCE INDEX(term_taxonomy_id)'=>'Term relationship read failed','FROM p '=>'Product status read failed'] as $frag=>$msg){$GLOBALS['wpdb']->fail=$frag;throws(fn()=>$b->compute(),"DB error ($frag) fails closed",$msg);$GLOBALS['wpdb']->fail='';}
throws(fn()=>$b->compute(-1.0),'time cap fails closed','time cap');
$GLOBALS['wpdb']->q=[];throws(fn()=>$b->apply([['taxonomy'=>'category','term'=>1,'tt'=>1,'direct'=>1,'woo'=>1]]),'invalid row refused before any write','Invalid');
ok(!$GLOBALS['wpdb']->q,'refused apply touched no table');
$GLOBALS['wpdb']->q=[];throws(fn()=>$b->apply($rows),'apply readback mismatch (fake DB reads 0) rolls back','readback');
ok(in_array('START TRANSACTION',$GLOBALS['wpdb']->q,true)&&in_array('ROLLBACK',$GLOBALS['wpdb']->q,true)&&!in_array('COMMIT',$GLOBALS['wpdb']->q,true),'transaction rolled back, never committed');
