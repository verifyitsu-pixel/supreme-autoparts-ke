<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Normalize a product title into a fingerprint for near-duplicate detection.
 *
 * Strips size/length/mm/cm/id/od/colour tails and keeps the first 7 tokens
 * so "H&R Spring 250mm Red" and "H&R Spring 300mm Blue" collide.
 *
 * @param WC_Product|WP_Post|string|int $source Product, post, title string, or ID.
 */
function sa_product_loop_fingerprint($source): string
{
    $title = '';
    if ($source instanceof WC_Product) {
        $title = (string) $source->get_name();
    } elseif ($source instanceof WP_Post) {
        $title = (string) $source->post_title;
    } elseif (is_numeric($source)) {
        $post = get_post((int) $source);
        $title = $post ? (string) $post->post_title : '';
    } else {
        $title = (string) $source;
    }

    $title = strtolower(wp_strip_all_tags($title));
    $title = preg_replace('/[^a-z0-9]+/', ' ', $title) ?? '';
    $title = trim(preg_replace('/\s+/', ' ', $title) ?? '');
    // Drop trailing size / length / colour / option noise so variants share one key.
    $title = preg_replace(
        '/\b(length|size|colour|color|option|mm|inch|inches|in|cm|id|od)\b.*$/',
        '',
        $title
    ) ?? $title;
    $title = trim($title);
    $parts = array_values(array_filter(explode(' ', $title), static fn ($t) => $t !== ''));
    $parts = array_slice($parts, 0, 7);
    $key = implode(' ', $parts);
    if ($key !== '') {
        return $key;
    }
    if (is_object($source) && method_exists($source, 'get_id')) {
        return 'id:' . (string) $source->get_id();
    }
    if ($source instanceof WP_Post) {
        return 'id:' . (string) $source->ID;
    }
    return 'empty';
}

/**
 * Find a published product ID that already owns this title fingerprint.
 * Excludes $exclude_id. Used by import harden to UPDATE instead of cloning.
 */
function sa_core_find_published_by_fingerprint(string $fingerprint, int $exclude_id = 0): int
{
    $fingerprint = trim($fingerprint);
    if ($fingerprint === '' || $fingerprint === 'empty' || str_starts_with($fingerprint, 'id:')) {
        return 0;
    }

    // Prefer an indexed meta lookup if we already stamped fingerprints.
    $meta_q = get_posts([
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'exclude'        => $exclude_id > 0 ? [$exclude_id] : [],
        'meta_key'       => '_sa_title_fingerprint',
        'meta_value'     => $fingerprint,
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ]);
    if ($meta_q) {
        return (int) $meta_q[0];
    }

    // Fallback: scan recent published titles (bounded) — import path only.
    $batch = get_posts([
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => 400,
        'fields'         => 'ids',
        'exclude'        => $exclude_id > 0 ? [$exclude_id] : [],
        'orderby'        => 'ID',
        'order'          => 'DESC',
        'no_found_rows'  => true,
    ]);
    foreach ($batch as $pid) {
        $pid = (int) $pid;
        $post = get_post($pid);
        if (!$post) {
            continue;
        }
        $fp = sa_product_loop_fingerprint($post);
        if ($fp === $fingerprint) {
            update_post_meta($pid, '_sa_title_fingerprint', $fp);
            return $pid;
        }
    }
    return 0;
}

/**
 * Draft near-duplicate published products, keeping one winner per fingerprint.
 *
 * Prefer: has featured image or _sa_shopify_image_src, then total_sales DESC, then oldest ID.
 *
 * @param array{category?:string,dry_run?:bool,limit?:int} $opts
 * @return array{published_before:int,published_after:int,drafted:int,suspension_before:int,suspension_after:int,samples:array<int,string>,messages:array<int,string>,dry_run:bool}
 */
function sa_core_dedupe_published_products(array $opts = []): array
{
    $dry_run = !empty($opts['dry_run']);
    $category = sanitize_title((string) ($opts['category'] ?? ''));
    $limit = isset($opts['limit']) ? max(0, (int) $opts['limit']) : 0;

    $count_published = static function (string $cat_slug = '') : int {
        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => false,
        ];
        if ($cat_slug !== '') {
            $args['tax_query'] = [[
                'taxonomy'         => 'product_cat',
                'field'            => 'slug',
                'terms'            => [$cat_slug],
                'include_children' => true,
            ]];
        }
        $q = new WP_Query($args);
        return (int) $q->found_posts;
    };

    $published_before = $count_published('');
    $suspension_before = $count_published('suspension');

    $query_args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => $limit > 0 ? $limit : -1,
        'fields'         => 'ids',
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'no_found_rows'  => true,
    ];
    if ($category !== '') {
        $query_args['tax_query'] = [[
            'taxonomy'         => 'product_cat',
            'field'            => 'slug',
            'terms'            => [$category],
            'include_children' => true,
        ]];
    }

    $ids = get_posts($query_args);
    /** @var array<string, list<array{id:int,score:int,sales:int}>> $groups */
    $groups = [];

    foreach ($ids as $pid) {
        $pid = (int) $pid;
        $post = get_post($pid);
        if (!$post) {
            continue;
        }
        $fp = sa_product_loop_fingerprint($post);
        if ($fp === '' || $fp === 'empty') {
            continue;
        }
        update_post_meta($pid, '_sa_title_fingerprint', $fp);

        $has_img = (int) get_post_thumbnail_id($pid) > 0;
        if (!$has_img) {
            $cdn = trim((string) get_post_meta($pid, '_sa_shopify_image_src', true));
            $has_img = $cdn !== '' && str_starts_with($cdn, 'http');
        }
        $sales = (int) get_post_meta($pid, 'total_sales', true);
        $groups[$fp][] = [
            'id'    => $pid,
            'score' => $has_img ? 1 : 0,
            'sales' => $sales,
        ];
    }

    $in_suspension = static function (int $product_id): bool {
        $terms = wp_get_post_terms($product_id, 'product_cat');
        if (!is_array($terms) || is_wp_error($terms)) {
            return false;
        }
        foreach ($terms as $t) {
            if ($t->slug === 'suspension') {
                return true;
            }
            $anc = get_ancestors((int) $t->term_id, 'product_cat');
            foreach ($anc as $aid) {
                $a = get_term((int) $aid, 'product_cat');
                if ($a && !is_wp_error($a) && $a->slug === 'suspension') {
                    return true;
                }
            }
        }
        return false;
    };

    $drafted = 0;
    $suspension_drafted = 0;
    $samples = [];
    $messages = [];

    foreach ($groups as $fp => $members) {
        if (count($members) < 2) {
            if (count($samples) < 12) {
                $samples[] = $fp;
            }
            continue;
        }
        usort($members, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score']; // image first
            }
            if ($a['sales'] !== $b['sales']) {
                return $b['sales'] <=> $a['sales']; // higher sales
            }
            return $a['id'] <=> $b['id']; // oldest ID
        });
        $keep = $members[0]['id'];
        foreach (array_slice($members, 1) as $extra) {
            $extra_id = (int) $extra['id'];
            if (!$dry_run) {
                wp_update_post([
                    'ID'          => $extra_id,
                    'post_status' => 'draft',
                ]);
                update_post_meta($extra_id, '_sa_deduped_into', $keep);
                update_post_meta($extra_id, '_sa_deduped_at', gmdate('c'));
            }
            $drafted++;
            if ($in_suspension($extra_id)) {
                $suspension_drafted++;
            }
            if (count($messages) < 40) {
                $messages[] = sprintf(
                    'draft #%d → keep #%d [%s]',
                    $extra_id,
                    $keep,
                    $fp
                );
            }
        }
        if (count($samples) < 12) {
            $samples[] = $fp;
        }
    }

    // Recount after drafts (or estimate on dry-run).
    if ($dry_run) {
        $published_after = max(0, $published_before - $drafted);
        $suspension_after = max(0, $suspension_before - $suspension_drafted);
    } else {
        $published_after = $count_published('');
        $suspension_after = $count_published('suspension');
    }

    return [
        'published_before'  => $published_before,
        'published_after'   => $published_after,
        'drafted'           => $drafted,
        'suspension_before' => $suspension_before,
        'suspension_after'  => $suspension_after,
        'samples'           => $samples,
        'messages'          => $messages,
        'dry_run'           => $dry_run,
        'groups'            => count($groups),
    ];
}
