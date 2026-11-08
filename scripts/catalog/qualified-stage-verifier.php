<?php
// Inert strict post-save verifier shared by isolated fixtures and reviewed one-product helpers.
declare(strict_types=1);
function sa_qualified_stage_snapshot(int $id): array {
    $post=get_post($id,ARRAY_A);if(!$post)throw new RuntimeException('Missing product snapshot');
    $meta=get_post_meta($id);ksort($meta);$terms=wp_get_object_terms($id,get_object_taxonomies('product'),['fields'=>'all']);if(is_wp_error($terms))throw new RuntimeException('Term read failed');
    foreach($terms as $term)unset($term->count);
    usort($terms,fn($a,$b)=>[$a->taxonomy,$a->term_id]<=>[$b->taxonomy,$b->term_id]);
    return ['post'=>$post,'meta'=>$meta,'terms'=>$terms];
}
function sa_qualified_stage_excerpt_expected(array $before,array $after,string $excerpt,string $guid): array {
    $expected=$before;$expected['post']['post_excerpt']=$excerpt;$expected['post']['guid']=$guid;
    foreach(['post_modified','post_modified_gmt']as$key){if(!isset($after['post'][$key]))throw new RuntimeException('Missing modified time');$expected['post'][$key]=$after['post'][$key];}
    return $expected;
}
function sa_qualified_stage_finish_excerpt(int $id,string $excerpt,string $expected_guid): array {
    $before=sa_qualified_stage_snapshot($id);
    $r=wp_update_post(wp_slash(['ID'=>$id,'post_excerpt'=>$excerpt]),true);if(is_wp_error($r))throw new RuntimeException('Excerpt save failed');clean_post_cache($id);
    $after=sa_qualified_stage_snapshot($id);$expected=sa_qualified_stage_excerpt_expected($before,$after,$excerpt,$expected_guid);
    if(wp_json_encode($expected)!==wp_json_encode($after))throw new RuntimeException('Unexpected exact post-save excerpt state; preserve proof and do not retry');
    return ['before'=>$before,'after'=>$after,'expected'=>$expected,'exact'=>true];
}
