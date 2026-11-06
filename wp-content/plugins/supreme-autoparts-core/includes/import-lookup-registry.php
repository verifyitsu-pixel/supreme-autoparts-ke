<?php
declare(strict_types=1);
/**
 * Bulk-import lookup registry (CLI only; not loaded by the plugin).
 *
 * Answers the importer's exact-meta lookups for one import run from a single bounded scan of
 * postmeta (primary-key pages), so no per-item unindexed meta query runs. The database compares
 * every wanted value with its own column collation, so case, accent and trailing-space equality
 * is exactly what the old query used. Failure never reads as "no match": any read error, time
 * cap, unknown key/value or repeated in-batch identity throws and the importer creates nothing.
 *
 * Use: $r = new KenyaImportLookup($pairs); $r->scan($wpdb);
 *      foreach ($items as $x) { $r->claimItem($x); }
 *      $GLOBALS['sa_core_import_lookup_registry'] = $r;
 */
if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('CLI only');
}

final class KenyaImportLookup
{
    public const MAX_PAIRS = 150;
    private const PAGE = 5000;

    /** @var array<string,array<string,true>> */
    private array $wanted = [];
    /** @var array<string,array<string,int[]>> */
    private array $matches = [];
    /** @var array<string,array<string,true>> */
    private array $seen = [];
    private bool $scanned = false;

    /** @param array<int,array{0:string,1:string}> $pairs [meta_key, meta_value] */
    public function __construct(array $pairs)
    {
        foreach ($pairs as [$k, $v]) {
            $this->wanted[(string) $k][(string) $v] = true;
        }
        $n = 0;
        foreach ($this->wanted as $values) {
            $n += count($values);
        }
        if ($n === 0 || $n > self::MAX_PAIRS) {
            throw new RuntimeException('Registry wanted pairs out of bounds (1-' . self::MAX_PAIRS . ')');
        }
    }

    /** @param object $db wpdb-like: prepare(), get_results(), postmeta, last_error */
    public function scan($db, float $budget = 90.0): void
    {
        $exprs = [];
        $args = [];
        $tokenMap = [];
        $i = 0;
        foreach ($this->wanted as $k => $values) {
            foreach (array_keys($values) as $v) {
                $token = 'm' . $i++;
                $tokenMap[$token] = [(string) $k, (string) $v];
                $exprs[] = 'IF(meta_key=%s AND meta_value=%s,%s,NULL)';
                array_push($args, (string) $k, (string) $v, $token);
            }
        }
        $sql = "SELECT meta_id,post_id,CONCAT_WS(','," . implode(',', $exprs) . ") AS match_token FROM {$db->postmeta} FORCE INDEX(PRIMARY) WHERE meta_id>%d ORDER BY meta_id LIMIT " . self::PAGE;
        $last = 0;
        $t = microtime(true);
        $out = [];
        do {
            $db->last_error = '';
            $rows = $db->get_results($db->prepare($sql, ...array_merge($args, [$last])), ARRAY_A);
            if ($db->last_error !== '' || !is_array($rows)) {
                throw new RuntimeException('Registry read failed; no import permitted');
            }
            foreach ($rows as $r) {
                $last = (int) $r['meta_id'];
                if ($r['match_token'] === null || $r['match_token'] === '') {
                    continue;
                }
                foreach (explode(',', (string) $r['match_token']) as $token) {
                    if (!isset($tokenMap[$token])) {
                        throw new RuntimeException('Registry returned an unknown token');
                    }
                    [$k, $v] = $tokenMap[$token];
                    $out[$k][$v][] = (int) $r['post_id'];
                }
            }
            if (microtime(true) - $t > $budget) {
                throw new RuntimeException('Registry time cap; no import permitted');
            }
        } while (count($rows) === self::PAGE);
        $this->matches = $out;
        $this->scanned = true;
    }

    /** @return int[] post IDs, or [0] when the key/value was scanned and is absent */
    public function lookup(string $key, string $value): array
    {
        if (!$this->scanned || !isset($this->wanted[$key]) || !array_key_exists($value, $this->wanted[$key])) {
            throw new RuntimeException('Unknown lookup; no import permitted');
        }
        return array_values(array_unique($this->matches[$key][$value] ?? [0]));
    }

    /** Refuse repeated identities inside one run (the snapshot cannot see rows created in-run). */
    public function claimItem(array $x): void
    {
        $ids = [['_sa_shopify_id', (string) ($x['id'] ?? '')], ['_sa_shopify_handle', (string) ($x['handle'] ?? '')], ['_sku', (string) ($x['variants'][0]['sku'] ?? '')]];
        foreach ($ids as [$k, $v]) {
            if ($v === '') {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9 _.-]+$/D', $v)) {
                throw new RuntimeException('Unsupported in-batch identity; no import permitted');
            }
            $n = strtolower(rtrim($v));
            if (isset($this->seen[$k][$n])) {
                throw new RuntimeException('Repeated in-batch identity; no import permitted');
            }
            $this->seen[$k][$n] = true;
        }
    }
}
