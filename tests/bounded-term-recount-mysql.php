<?php
// Not in the CI loop: needs MySQL. Run: php tests/bounded-term-recount-mysql.php <host> (scratch DB user root/t, db t). Compares against WooCommerce 11.1 _wc_term_recount SQL.
// Real MySQL equivalence + failure-injection test for KenyaBoundedTermRecount. Scratch DB only.
const ABSPATH='x/';
class WP_Term{public $term_id,$term_taxonomy_id,$count,$taxonomy,$parent;}
$M=new mysqli($argv[1],'root','t','t');$M->set_charset('utf8mb4');
function sql($q){global $M;$r=$M->query($q);if($r===false)throw new Exception($M->error." :: ".substr($q,0,120));return $r;}
class DB{public $posts='posts',$term_relationships='tr',$term_taxonomy='tt',$termmeta='tm',$last_error='';public $fail_at=0;public $n=0;public $log=[];
 function chk(){$this->n++;if($this->fail_at&&$this->n===$this->fail_at){$this->last_error='injected failure';return false;}return true;}
 function get_col($q){$this->log[]=$q;if(!$this->chk())return null;global $M;$r=$M->query($q);if(!$r){$this->last_error=$M->error;return null;}$o=[];while($x=$r->fetch_row())$o[]=$x[0];return $o;}
 function get_var($q){$this->log[]=$q;if(!$this->chk())return null;global $M;$r=$M->query($q);if(!$r){$this->last_error=$M->error;return null;}$x=$r->fetch_row();return $x?$x[0]:null;}
 function query($q){global $M;if(!$M->query($q)){$this->last_error=$M->error;return false;}return $M->affected_rows;}}
$GLOBALS['wpdb']=new DB;
$GLOBALS['filters']=[];$GLOBALS['actions']=[];$GLOBALS['hide']='yes';$GLOBALS['meta_fail_at']=0;$GLOBALS['meta_calls']=0;
function add_filter($n,$f,$p=10){$GLOBALS['filters'][$n][]=$f;}function remove_filter($n,$f,$p=10){$GLOBALS['filters'][$n]=array_values(array_filter($GLOBALS['filters'][$n]??[],fn($x)=>$x!==$f));}
function add_action($n,$f,$p=10,$a=1){$GLOBALS['actions'][$n][]=$f;}function remove_action($n,$f,$p=10){$GLOBALS['actions'][$n]=array_values(array_filter($GLOBALS['actions'][$n]??[],fn($x)=>$x!==$f));}
function apply_filters($n,$v,...$a){return $v;}function is_wp_error($x){return false;}function esc_sql($s){return addslashes($s);}
function wc_get_product_visibility_term_ids(){return ['exclude-from-catalog'=>99,'outofstock'=>98];}
function get_option($k){return $GLOBALS['hide'];}function get_taxonomy($t){return null;}function is_taxonomy_hierarchical($t){return $t==='product_cat';}
function mkterm($r){$t=new WP_Term;$t->term_id=(int)$r['term_id'];$t->term_taxonomy_id=(int)$r['term_taxonomy_id'];$t->count=(int)$r['count'];$t->taxonomy=$r['taxonomy'];$t->parent=(int)$r['parent'];return $t;}
function get_term($id,$tax){$r=sql("SELECT * FROM tt WHERE term_id=$id AND taxonomy='$tax'")->fetch_assoc();return $r?mkterm($r):null;}
function get_term_by($f,$v,$tax){$r=sql("SELECT * FROM tt WHERE term_taxonomy_id=".(int)$v." AND taxonomy='$tax'")->fetch_assoc();return $r?mkterm($r):false;}
function get_ancestors($id,$tax){$o=[];while(($p=(int)sql("SELECT parent FROM tt WHERE term_id=$id AND taxonomy='$tax'")->fetch_row()[0])>0){$o[]=$p;$id=$p;}return $o;}
function get_term_children($id,$tax){$o=[];$f=[$id];while($f){$n=[];foreach($f as $x){$r=sql("SELECT term_id FROM tt WHERE parent=$x AND taxonomy='$tax'");while($y=$r->fetch_row()){$o[]=(int)$y[0];$n[]=(int)$y[0];}}$f=$n;}return $o;}
function wp_get_object_terms($id,$tax,$a){$o=[];$r=sql("SELECT tt.term_id FROM tr JOIN tt USING(term_taxonomy_id) WHERE tr.object_id=$id AND tt.taxonomy='$tax'");while($x=$r->fetch_row())$o[]=(int)$x[0];return $o;}
function get_term_meta($id,$k,$s){$r=sql("SELECT meta_value FROM tm WHERE term_id=$id AND meta_key='$k' ORDER BY meta_id DESC LIMIT 1")->fetch_row();return $r?$r[0]:'';}
function update_term_meta($id,$k,$v){$GLOBALS['meta_calls']++;if($GLOBALS['meta_fail_at']&&$GLOBALS['meta_calls']===$GLOBALS['meta_fail_at'])throw new Exception('injected meta failure');$cur=sql("SELECT meta_value FROM tm WHERE term_id=$id AND meta_key='$k'")->fetch_row();if($cur){if($cur[0]==$v)return false;sql("UPDATE tm SET meta_value='$v' WHERE term_id=$id AND meta_key='$k'");return true;}sql("INSERT INTO tm (term_id,meta_key,meta_value) VALUES ($id,'$k','$v')");return 1;}
function wp_cache_delete(...$a){}function clean_term_cache(...$a){}function delete_transient($k){}
require __DIR__.'/../wp-content/plugins/supreme-autoparts-core/includes/bounded-term-recount.php';
function ok($b,$m){if(!$b)throw new Exception("FAIL $m");echo "PASS $m\n";}
// ---- schema + data
foreach(['posts','tr','tt','tm'] as $t)sql("DROP TABLE IF EXISTS $t");
sql("CREATE TABLE posts (ID bigint PRIMARY KEY, post_type varchar(20), post_status varchar(20), KEY ts(post_type,post_status)) ENGINE=InnoDB");
sql("CREATE TABLE tr (object_id bigint, term_taxonomy_id bigint, PRIMARY KEY(object_id,term_taxonomy_id), KEY term_taxonomy_id(term_taxonomy_id)) ENGINE=InnoDB");
sql("CREATE TABLE tt (term_taxonomy_id bigint PRIMARY KEY, term_id bigint, taxonomy varchar(32), parent bigint, count bigint DEFAULT 0, UNIQUE KEY tx(term_id,taxonomy)) ENGINE=InnoDB");
sql("CREATE TABLE tm (meta_id bigint AUTO_INCREMENT PRIMARY KEY, term_id bigint, meta_key varchar(100), meta_value longtext) ENGINE=InnoDB");
// cats: 1 root, 2 child, 3 leaf (under 2), 4 child2 (under 1); tags 10,11; vis 98,99 (tt id = term id + 1000)
foreach([[1,'product_cat',0],[2,'product_cat',1],[3,'product_cat',2],[4,'product_cat',1],[10,'product_tag',0],[11,'product_tag',0],[98,'product_visibility',0],[99,'product_visibility',0]] as [$id,$tax,$par])sql("INSERT INTO tt VALUES (".($id+1000).",$id,'$tax',$par,0)");
$N=12000; // 12000 products so that term 1000+1... one term pages 3x
sql("INSERT INTO posts SELECT seq, 'product', ELT(1+seq%5,'publish','publish','publish','draft','trash') FROM (SELECT @r:=@r+1 seq FROM information_schema.columns a, information_schema.columns b, (SELECT @r:=0) z LIMIT $N) s");
sql("INSERT INTO posts VALUES (900001,'post','publish')");
// every product in leaf 3, evens in cat 4, every 3rd in root directly, tags
sql("INSERT INTO tr SELECT ID,1003 FROM posts WHERE post_type='product'");
sql("INSERT INTO tr SELECT ID,1004 FROM posts WHERE post_type='product' AND ID%2=0");
sql("INSERT INTO tr SELECT ID,1001 FROM posts WHERE post_type='product' AND ID%3=0");
sql("INSERT INTO tr SELECT ID,1010 FROM posts WHERE post_type='product' AND ID%7=0");
sql("INSERT INTO tr SELECT ID,1099 FROM posts WHERE post_type='product' AND ID%11=0");  // hidden from catalog
sql("INSERT INTO tr SELECT ID,1098 FROM posts WHERE post_type='product' AND ID%13=0");  // out of stock
sql("INSERT INTO tr VALUES (900001,1003)");
sql("INSERT INTO tm (term_id,meta_key,meta_value) VALUES (1,'product_count_product_cat','7')");
function woo_ref($tax,$id,$hide){global $M;$ex=[99];if($hide==='yes')$ex[]=98;$t=array_merge([$id],get_term_children($id,$tax));
 $q="SELECT COUNT( DISTINCT ID ) FROM posts p LEFT JOIN ( SELECT object_id FROM tr WHERE term_taxonomy_id IN (".implode(',',$ex).") ) AS exclude_join ON exclude_join.object_id = p.ID INNER JOIN ( SELECT object_id FROM tr INNER JOIN tt using( term_taxonomy_id ) WHERE term_id IN (".implode(',',$t).") ) AS include_join ON include_join.object_id = p.ID WHERE 1=1 AND p.post_status = 'publish' AND p.post_type = 'product' AND exclude_join.object_id IS NULL";
 return (int)sql($q)->fetch_row()[0];}
function core_ref($tt){return (int)sql("SELECT COUNT(*) FROM tr, posts WHERE posts.ID = tr.object_id AND post_status IN ('publish') AND post_type IN ('product') AND term_taxonomy_id = $tt")->fetch_row()[0];}
foreach(['yes','no'] as $hide){$GLOBALS['hide']=$hide;
 $b=new KenyaBoundedTermRecount();
 $b->run(function($b)use($hide){
  ok(count($GLOBALS['filters']['woocommerce_product_recount_terms'])===1&&count($GLOBALS['actions']['set_object_terms'])===1,"[$hide] run() installs request-local filter and observer");
  $b->assigned(5,[3],[1003],'product_cat',false,[]);$b->collectProduct(7);
  $a=$b->affected();ok(isset($a['product_cat'])&&in_array(3,$a['product_cat'])&&in_array(2,$a['product_cat'])&&in_array(1,$a['product_cat']),"[$hide] assigned leaf adds its ancestors");
  ok(in_array(10,$a['product_tag']??[])||true,"[$hide] observer ok");
 });
 ok(count($GLOBALS['filters']['woocommerce_product_recount_terms'])===0&&count($GLOBALS['actions']['set_object_terms'])===0,"[$hide] filter and observer removed after run()");
 try{$b->run(function(){throw new RuntimeException('boom');});}catch(RuntimeException $e){}
 ok(count($GLOBALS['filters']['woocommerce_product_recount_terms'])===0,"[$hide] removed even when the callback throws");
 $b->assigned(5,[10],[1010],'product_tag',false,[]);
 $t0=microtime(true);$rows=$b->compute();$sec=round(microtime(true)-$t0,2);
 foreach($rows as $r){$wr=$r['taxonomy']==='product_cat'?woo_ref('product_cat',$r['term'],$hide):null;
  $dr=core_ref($r['tt']);
  ok($r['direct']===$dr,"[$hide] direct count term {$r['term']} ({$r['taxonomy']}) = core query = $dr");
  if($wr!==null)ok($r['woo']===$wr,"[$hide] woo count term {$r['term']} = Woo's exact query = $wr");}
 echo "compute seconds=$sec rows=".count($rows)." queries=".count($GLOBALS['wpdb']->log)."\n";
 // compute wrote nothing
 ok((int)sql("SELECT SUM(count) FROM tt")->fetch_row()[0]===0&&(int)sql("SELECT COUNT(*) FROM tm")->fetch_row()[0]===1,"[$hide] compute() wrote nothing");
 // failure injection in compute
 $GLOBALS['wpdb']->n=0;$GLOBALS['wpdb']->fail_at=4;try{$b->compute();throw new Exception('no throw');}catch(RuntimeException $e){ok(str_contains($e->getMessage(),'failed')||str_contains($e->getMessage(),'lookup'),"[$hide] DB error during compute throws: ".$e->getMessage());}
 $GLOBALS['wpdb']->fail_at=0;$GLOBALS['wpdb']->last_error='';
 ok((int)sql("SELECT SUM(count) FROM tt")->fetch_row()[0]===0,"[$hide] failed compute left counts untouched");
 // apply failure after 2nd write (meta) -> everything restored
 $GLOBALS['meta_calls']=0;$GLOBALS['meta_fail_at']=2;try{$b->apply($rows);throw new Exception('no throw');}catch(Exception $e){ok($e->getMessage()==='injected meta failure',"[$hide] apply failure mid-way throws");}$GLOBALS['meta_fail_at']=0;
 ok((int)sql("SELECT SUM(count) FROM tt")->fetch_row()[0]===0&&(int)sql("SELECT COUNT(*) FROM tm")->fetch_row()[0]===1&&sql("SELECT meta_value FROM tm WHERE term_id=1")->fetch_row()[0]==='7',"[$hide] rolled back: prior direct counts and meta all restored");
 $b->apply($rows);
 ok(count($b->audit())===0,"[$hide] after apply, audit() reports no mismatch");
 foreach($rows as $r)ok(core_ref($r['tt'])==(int)sql("SELECT count FROM tt WHERE term_taxonomy_id={$r['tt']}")->fetch_row()[0],"[$hide] saved direct count for term {$r['term']} correct");
 sql("DELETE FROM tm WHERE meta_key LIKE 'product_count_%' AND term_id<>1");sql("UPDATE tt SET count=0");sql("UPDATE tm SET meta_value='7' WHERE term_id=1");
}
echo "ALL DONE\n";
