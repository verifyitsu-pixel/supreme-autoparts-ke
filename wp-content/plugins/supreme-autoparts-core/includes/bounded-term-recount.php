<?php
declare(strict_types=1);
/**
 * Bounded, two-phase term recount for bulk imports (CLI only; not loaded by the plugin).
 *
 * WooCommerce recounts every assigned category/tag, with all their children, on each product save,
 * through one unindexed query. On a large catalog those queries time out. This helper:
 *  - turns that recount off for the current request only (filter + observer, always removed by run()),
 *  - records the terms and ancestors that the import touches,
 *  - computes every affected count first with index-only paged reads (no writes),
 *  - applies all counts in one transaction, reads them back, and rolls everything back on any failure.
 * Counts match WooCommerce's own _wc_term_recount() and core's _update_post_term_count():
 *  term_taxonomy.count = published products directly in the term;
 *  product_count_<taxonomy> term meta = published, visible products in the term and its children.
 * Nothing is persisted that could leave recounting off, and prior counts survive any failure.
 */
if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('CLI only');
}

final class KenyaBoundedTermRecount
{
    private const TAXES = ['product_cat', 'product_tag'];
    private const PAGE = 5000;
    private const CHUNK = 1000;
    private const MAX_IDS = 400000;

    /** @var array<string,array<int,true>> */
    private array $terms = [];
    private \Closure $skip;
    private bool $on = false;
    /** @var array<int,bool> product ID => published product */
    private array $pub = [];
    /** @var array<int,bool> product ID => hidden from catalog (or out of stock when hidden) */
    private array $hidden = [];

    public function __construct()
    {
        $this->skip = static fn() => false;
    }

    public function start(): void
    {
        if ($this->on) {
            return;
        }
        add_filter('woocommerce_product_recount_terms', $this->skip, PHP_INT_MAX);
        add_action('set_object_terms', [$this, 'assigned'], PHP_INT_MAX, 6);
        $this->on = true;
    }

    public function stop(): void
    {
        remove_filter('woocommerce_product_recount_terms', $this->skip, PHP_INT_MAX);
        remove_action('set_object_terms', [$this, 'assigned'], PHP_INT_MAX);
        $this->on = false;
    }

    /** Runs $fn with the recount off and guarantees it is turned back on, whatever happens. */
    public function run(callable $fn)
    {
        $this->start();
        try {
            return $fn($this);
        } finally {
            $this->stop();
        }
    }

    public function collectProduct(int $id): void
    {
        foreach (self::TAXES as $tax) {
            $ids = wp_get_object_terms($id, $tax, ['fields' => 'ids']);
            if (is_wp_error($ids)) {
                throw new RuntimeException('Could not read product terms');
            }
            foreach ($ids as $term) {
                $this->add($tax, (int) $term);
            }
        }
    }

    private function add(string $tax, int $id): void
    {
        if (!in_array($tax, self::TAXES, true) || $id <= 0) {
            return;
        }
        $this->terms[$tax][$id] = true;
        foreach (get_ancestors($id, $tax) as $a) {
            $this->terms[$tax][(int) $a] = true;
        }
    }

    /** set_object_terms observer: record new and old terms plus their ancestors. */
    public function assigned($object_id, $terms, $tt_ids, $tax, $append, $old_tt_ids): void
    {
        if (!in_array($tax, self::TAXES, true)) {
            return;
        }
        foreach (array_unique(array_merge((array) $tt_ids, (array) $old_tt_ids)) as $tt) {
            $t = get_term_by('term_taxonomy_id', (int) $tt, $tax);
            if ($t instanceof WP_Term) {
                $this->add($tax, (int) $t->term_id);
            }
        }
    }

    /** @return array<string,int[]> */
    public function affected(): array
    {
        $o = [];
        foreach ($this->terms as $tax => $ids) {
            $o[$tax] = array_keys($ids);
        }
        return $o;
    }

    private function fail(string $m): never
    {
        throw new RuntimeException($m);
    }

    private function q(): object
    {
        global $wpdb;
        return $wpdb;
    }

    /** @return int[] object IDs related to one term_taxonomy_id, by index-only primary-ordered pages */
    private function objectIds(int $tt, float $deadline): array
    {
        $db = $this->q();
        $out = [];
        $last = 0;
        do {
            $db->last_error = '';
            $rows = $db->get_col("SELECT object_id FROM {$db->term_relationships} FORCE INDEX(term_taxonomy_id) WHERE term_taxonomy_id={$tt} AND object_id>{$last} ORDER BY object_id LIMIT " . self::PAGE);
            if ($db->last_error !== '' || !is_array($rows)) {
                $this->fail('Term relationship read failed; no counts were changed');
            }
            foreach ($rows as $r) {
                $out[] = $last = (int) $r;
            }
            if (microtime(true) > $deadline) {
                $this->fail('Recount time cap; no counts were changed');
            }
        } while (count($rows) === self::PAGE);
        return $out;
    }

    /** Loads published/hidden flags for product IDs not yet seen. */
    private function classify(array $ids, string $excl, float $deadline): void
    {
        $db = $this->q();
        $todo = [];
        foreach ($ids as $id) {
            if (!isset($this->pub[$id])) {
                $todo[] = $id;
            }
        }
        foreach (array_chunk($todo, self::CHUNK) as $chunk) {
            $list = implode(',', $chunk);
            $db->last_error = '';
            $pub = $db->get_col("SELECT ID FROM {$db->posts} WHERE ID IN ({$list}) AND post_type='product' AND post_status='publish'");
            if ($db->last_error !== '' || !is_array($pub)) {
                $this->fail('Product status read failed; no counts were changed');
            }
            $pubSet = array_flip(array_map('intval', $pub));
            $hid = [];
            if ($excl !== '' && $pubSet) {
                $plist = implode(',', array_keys($pubSet));
                $db->last_error = '';
                $h = $db->get_col("SELECT DISTINCT object_id FROM {$db->term_relationships} WHERE object_id IN ({$plist}) AND term_taxonomy_id IN ({$excl})");
                if ($db->last_error !== '' || !is_array($h)) {
                    $this->fail('Product visibility read failed; no counts were changed');
                }
                $hid = array_flip(array_map('intval', $h));
            }
            foreach ($chunk as $id) {
                $this->pub[$id] = isset($pubSet[$id]);
                $this->hidden[$id] = isset($hid[$id]);
            }
            if (microtime(true) > $deadline) {
                $this->fail('Recount time cap; no counts were changed');
            }
        }
    }

    /**
     * Phase 1: compute every affected count. Writes nothing.
     * @return array<int,array<string,mixed>>
     */
    public function compute(float $budget = 150.0): array
    {
        $db = $this->q();
        // Product status and visibility are read fresh on every compute, never reused from an earlier one.
        $this->pub = [];
        $this->hidden = [];
        $deadline = microtime(true) + $budget;
        $vis = wc_get_product_visibility_term_ids();
        $ex = [(int) ($vis['exclude-from-catalog'] ?? 0)];
        if (get_option('woocommerce_hide_out_of_stock_items') === 'yes') {
            $ex[] = (int) ($vis['outofstock'] ?? 0);
        }
        $excl = implode(',', array_filter($ex));
        $rows = [];
        $memo = [];
        $total = 0;
        foreach ($this->terms as $tax => $ids) {
            foreach (array_keys($ids) as $id) {
                $term = get_term($id, $tax);
                if (!$term instanceof WP_Term) {
                    $this->fail('Affected term missing');
                }
                $tt = (int) $term->term_taxonomy_id;
                $kids = is_taxonomy_hierarchical($tax) ? get_term_children($id, $tax) : [];
                if (is_wp_error($kids)) {
                    $this->fail('Term hierarchy failed');
                }
                $termIds = array_map('intval', array_merge([$id], $kids));
                $db->last_error = '';
                $tts = $db->get_col("SELECT term_taxonomy_id FROM {$db->term_taxonomy} WHERE taxonomy='" . esc_sql($tax) . "' AND term_id IN (" . implode(',', $termIds) . ')');
                if ($db->last_error !== '' || !is_array($tts) || !in_array((string) $tt, array_map('strval', $tts), true)) {
                    $this->fail('Term taxonomy lookup failed');
                }
                $union = [];
                foreach (array_map('intval', $tts) as $x) {
                    if (!isset($memo[$x])) {
                        $memo[$x] = $this->objectIds($x, $deadline);
                        $total += count($memo[$x]);
                        if ($total > self::MAX_IDS) {
                            $this->fail('Recount too large for the bounded method; no counts were changed');
                        }
                        $this->classify($memo[$x], $excl, $deadline);
                    }
                    foreach ($memo[$x] as $o) {
                        $union[$o] = true;
                    }
                }
                $direct = 0;
                foreach ($memo[$tt] as $o) {
                    if ($this->pub[$o]) {
                        $direct++;
                    }
                }
                $woo = 0;
                foreach (array_keys($union) as $o) {
                    if ($this->pub[$o] && !$this->hidden[$o]) {
                        $woo++;
                    }
                }
                $woo = (int) apply_filters('woocommerce_term_recount_product_count', $woo, (int) $id, get_taxonomy($tax));
                $db->last_error = '';
                $prior = $db->get_var("SELECT count FROM {$db->term_taxonomy} WHERE term_taxonomy_id={$tt}");
                if ($db->last_error !== '' || $prior === null) {
                    $this->fail('Prior count read failed');
                }
                $rows[] = ['taxonomy' => $tax, 'term' => (int) $id, 'tt' => $tt, 'direct' => $direct, 'woo' => $woo,
                    'prior_direct' => (int) $prior, 'prior_woo' => get_term_meta($id, 'product_count_' . $tax, true)];
            }
        }
        return $rows;
    }

    /** Terms whose saved counts differ from the computed ones. Writes nothing. */
    public function audit(float $budget = 150.0): array
    {
        $bad = [];
        foreach ($this->compute($budget) as $r) {
            if ($r['direct'] !== $r['prior_direct'] || (string) $r['woo'] !== (string) $r['prior_woo']) {
                $bad[] = $r;
            }
        }
        return $bad;
    }

    /**
     * Phase 2: write all computed counts in one transaction, verify them from the database, commit.
     * Any failure rolls every count back to its prior value and throws.
     */
    public function apply(array $rows): void
    {
        $db = $this->q();
        foreach ($rows as $r) {
            if (!isset($r['taxonomy'], $r['term'], $r['tt'], $r['direct'], $r['woo']) || !in_array($r['taxonomy'], self::TAXES, true)
                || $r['direct'] < 0 || $r['woo'] < 0) {
                $this->fail('Invalid computed count row; nothing written');
            }
        }
        if ($db->query('START TRANSACTION') === false) {
            $this->fail('Could not start transaction; nothing written');
        }
        try {
            foreach ($rows as $r) {
                $db->last_error = '';
                if ($db->query("UPDATE {$db->term_taxonomy} SET count=" . (int) $r['direct'] . ' WHERE term_taxonomy_id=' . (int) $r['tt']) === false || $db->last_error !== '') {
                    $this->fail('Direct count save failed');
                }
                // update_term_meta() returns false when the value is unchanged, so success is judged by the readback below.
                update_term_meta((int) $r['term'], 'product_count_' . $r['taxonomy'], (int) $r['woo']);
            }
            foreach ($rows as $r) {
                $d = $db->get_var('SELECT count FROM ' . $db->term_taxonomy . ' WHERE term_taxonomy_id=' . (int) $r['tt']);
                $m = $db->get_var('SELECT meta_value FROM ' . $db->termmeta . ' WHERE term_id=' . (int) $r['term'] . " AND meta_key='product_count_" . $r['taxonomy'] . "' ORDER BY meta_id DESC LIMIT 1");
                if ((int) $d !== (int) $r['direct'] || $m === null || (int) $m !== (int) $r['woo']) {
                    $this->fail('Count readback failed');
                }
            }
            if ($db->query('COMMIT') === false) {
                $this->fail('Commit failed');
            }
        } catch (\Throwable $e) {
            $db->query('ROLLBACK');
            foreach ($rows as $r) {
                wp_cache_delete((int) $r['term'], 'term_meta');
                clean_term_cache((int) $r['term'], $r['taxonomy']);
            }
            throw $e;
        }
        foreach ($rows as $r) {
            wp_cache_delete((int) $r['term'], 'term_meta');
            clean_term_cache((int) $r['term'], $r['taxonomy']);
        }
        delete_transient('wc_term_counts');
    }
}
