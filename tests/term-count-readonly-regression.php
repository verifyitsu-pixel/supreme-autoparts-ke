<?php
const ABSPATH = 'fixture/';
class WP_Term {
 public $term_id; public $taxonomy; public $count;
 function __construct($id, $taxonomy, $count) { $this->term_id=$id; $this->taxonomy=$taxonomy; $this->count=$count; }
}
$GLOBALS['admin']=false;$GLOBALS['ajax']=false;$GLOBALS['meta']=[];$GLOBALS['reads']=[];$GLOBALS['primes']=[];$GLOBALS['hooks']=[];$GLOBALS['registered']=[];
function add_action($h,$cb,$p=10){$GLOBALS['hooks'][$h]=$cb;}
function add_filter(...$a){$GLOBALS['registered'][]=$a;}
function remove_filter(...$a){$GLOBALS['removed'][]=$a;}
function has_filter(...$a){return 10;}
function wc_change_term_counts(){}
function is_admin(){return $GLOBALS['admin'];}
function wp_doing_ajax(){return $GLOBALS['ajax'];}
function apply_filters($h,$v){return $GLOBALS['valid']??$v;}
function update_meta_cache($type,$ids){$GLOBALS['primes'][]=[$type,$ids];}
function get_term_meta($id,$key,$single){$GLOBALS['reads'][]=[$id,$key,$single];return $GLOBALS['meta'][$id][$key]??'';}
function absint($v){return abs((int)$v);}
function set_transient(...$a){throw new Exception('No transient writes allowed');}
function update_option(...$a){throw new Exception('No option writes allowed');}
function update_term_meta(...$a){throw new Exception('No term writes allowed');}
function ok($b,$m){if(!$b)throw new Exception($m);echo "PASS $m\n";}
require __DIR__.'/../wp-content/mu-plugins/sa-term-count-readonly.php';
$GLOBALS['hooks']['plugins_loaded']();ok($GLOBALS['removed'][0]===['get_terms','wc_change_term_counts',10],'replace only WooCommerce count filter');ok($GLOBALS['registered'][0]===['get_terms','sa_term_counts_from_metadata',10,2],'same filter priority and arity');
$GLOBALS['meta']=[1=>['product_count_product_cat'=>'42'],2=>['product_count_product_tag'=>'-7'],3=>['product_count_product_brand'=>'0']];
$terms=[new WP_Term(1,'product_cat',99),new WP_Term(2,'product_tag',99),new WP_Term(3,'product_brand',99),new WP_Term(4,'product_cat',99),new WP_Term(5,'category',99),'slug',17];
$out=sa_term_counts_from_metadata($terms,['product_cat','product_tag','product_brand','category']);
ok(array_map(fn($t)=>is_object($t)?$t->count:$t,$out)===[42,7,0,0,99,'slug',17],'correct counts, missing metadata zero, non-product terms and scalar results unchanged');
ok($GLOBALS['primes']===[['term',[1,2,3,4]]],'prime only relevant term metadata in one batch');
foreach (['admin','ajax'] as $flag){$GLOBALS[$flag]=true;$t=new WP_Term(1,'product_cat',91);$n=count($GLOBALS['reads']);sa_term_counts_from_metadata([$t],'product_cat');ok($t->count===91&&count($GLOBALS['reads'])===$n,$flag.' unchanged without reads');$GLOBALS[$flag]=false;}
$t=new WP_Term(1,'product_cat',91);$n=count($GLOBALS['reads']);sa_term_counts_from_metadata([$t],'category');ok($t->count===91&&count($GLOBALS['reads'])===$n,'irrelevant taxonomy unchanged');
$GLOBALS['valid']=['product_brand'];$t=new WP_Term(1,'product_cat',91);sa_term_counts_from_metadata([$t],'product_cat');ok($t->count===91,'existing WooCommerce taxonomy filter respected');
unset($GLOBALS['valid']);$GLOBALS['meta'][1]['product_count_product_cat']='43';$t=new WP_Term(1,'product_cat',91);sa_term_counts_from_metadata([$t],'product_cat');ok($t->count===43,'fresh authoritative count rather than stale whole-site transient');
ok(sa_term_counts_from_metadata([],['product_cat'])===[],'empty result unchanged');
echo "No transient, option, term, product or customer writes.\n";
