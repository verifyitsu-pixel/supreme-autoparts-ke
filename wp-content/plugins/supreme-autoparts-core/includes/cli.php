<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

/**
 * WP-CLI commands: wp supreme seed-pages | seed-categories | import-sample | import-ndjson
 */
class SA_Core_CLI_Command
{
    /**
     * Seed static pages and Woo page assignments.
     *
     * ## EXAMPLES
     *     wp supreme seed-pages
     */
    public function seed_pages(): void
    {
        sa_core_seed_pages();
        WP_CLI::success('Pages seeded.');
    }

    /**
     * Seed product categories matching homepage IA.
     *
     * ## EXAMPLES
     *     wp supreme seed-categories
     */
    public function seed_categories(): void
    {
        sa_core_seed_categories();
        WP_CLI::success('Categories seeded.');
    }

    /**
     * Import bundled sample Shopify JSON products.
     *
     * ## OPTIONS
     * [--file=<path>]
     * : Path to sample-products.json
     * [--limit=<n>]
     * : Max products
     * [--offset=<n>]
     * : Skip first N
     * [--skip-images]
     * : Skip image sideload (CDN URLs still stored)
     * [--require-images]
     * : Skip products with no real http(s) image src
     *
     * ## EXAMPLES
     *     wp supreme import-sample
     *     wp supreme import-sample --require-images --limit=40
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function import_sample(array $args, array $assoc_args): void
    {
        $file = $assoc_args['file'] ?? SA_CORE_DIR . 'data/sample-products.json';
        require_once SA_CORE_DIR . 'includes/import-shopify.php';
        $result = sa_core_import_shopify_products_file($file, [
            'limit'          => isset($assoc_args['limit']) ? (int) $assoc_args['limit'] : 0,
            'offset'         => isset($assoc_args['offset']) ? (int) $assoc_args['offset'] : 0,
            'skip_images'    => isset($assoc_args['skip-images']),
            'require_images' => isset($assoc_args['require-images']),
            'category'       => (string) ($assoc_args['category'] ?? ''),
            'dry_run'        => isset($assoc_args['dry-run']),
            'mapping'        => (string) ($assoc_args['mapping'] ?? ''),
        ]);
        WP_CLI::success(sprintf(
            'Imported %d, updated %d (%d skipped, %d filtered, %d errors)%s.',
            $result['imported'],
            $result['updated'] ?? 0,
            $result['skipped'],
            $result['filtered'] ?? 0,
            $result['errors'],
            !empty($result['dry_run']) ? ' [dry-run]' : ''
        ));
    }

    /**
     * Stream-import Shopify NDJSON catalog (idempotent by handle/SKU/shopify id).
     *
     * ## OPTIONS
     * [--file=<path>]
     * : Path to products.ndjson (default: ABSPATH/data/scrape/products.ndjson)
     * [--limit=<n>]
     * : Max matching products this run (0 = all remaining from offset)
     * [--offset=<n>]
     * : Skip first N NDJSON lines (before category filter)
     * [--category=<slug>]
     * : Only import products mapping to this IA parent (e.g. brakes, suspension)
     * [--dry-run]
     * : Count/match only — do not write products
     * [--mapping=<path>]
     * : JSON file mapping product_type → parent category slug
     * [--skip-images]
     * : Skip binary sideload (still stores Shopify CDN URL meta for display)
     * [--require-images]
     * : Only import products that have at least one real http(s) image
     *
     * ## EXAMPLES
     *     wp supreme import-ndjson --category=brakes --limit=50 --require-images --dry-run
     *     wp supreme import-ndjson --file=.../batch-with-images-400.ndjson --category=brakes --require-images
     *     wp supreme import-ndjson --limit=500 --require-images
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function import_ndjson(array $args, array $assoc_args): void
    {
        $default = trailingslashit(ABSPATH) . 'data/scrape/products.ndjson';
        $file = $assoc_args['file'] ?? $default;
        $category = (string) ($assoc_args['category'] ?? getenv('SUPREME_IMPORT_CATEGORY') ?: '');
        $mapping = (string) ($assoc_args['mapping'] ?? getenv('SUPREME_IMPORT_MAPPING') ?: '');
        require_once SA_CORE_DIR . 'includes/import-shopify.php';
        $result = sa_core_import_shopify_products_file($file, [
            'limit'          => isset($assoc_args['limit']) ? (int) $assoc_args['limit'] : 0,
            'offset'         => isset($assoc_args['offset']) ? (int) $assoc_args['offset'] : 0,
            'skip_images'    => isset($assoc_args['skip-images']),
            'require_images' => isset($assoc_args['require-images']),
            'category'       => $category,
            'dry_run'        => isset($assoc_args['dry-run']),
            'mapping'        => $mapping,
        ]);
        WP_CLI::success(sprintf(
            'NDJSON import%s: imported=%d updated=%d skipped=%d filtered=%d errors=%d web_fallback=%d category=%s file=%s',
            !empty($result['dry_run']) ? ' (dry-run)' : '',
            $result['imported'],
            $result['updated'] ?? 0,
            $result['skipped'],
            $result['filtered'] ?? 0,
            $result['errors'],
            (int) ($result['web_fallback'] ?? 0),
            ($result['category'] ?? '') !== '' ? $result['category'] : 'all',
            $file
        ));
        foreach (array_slice($result['messages'], 0, 30) as $m) {
            WP_CLI::log($m);
        }
    }

    /**
     * Repair catalog prices inflated by legacy USD→KES multiply after store switched to USD.
     *
     * ## OPTIONS
     * [--limit=<n>]
     * : Max products to examine (default 5000)
     * [--dry-run]
     * : Report only
     * [--force]
     * : Re-apply NDJSON/meta USD even when heuristic does not flag inflation
     * [--rate=<n>]
     * : Legacy multiply rate (default SUPREME_USD_TO_KES or 130)
     *
     * ## EXAMPLES
     *     wp supreme repair-prices --dry-run
     *     wp supreme repair-prices
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function repair_prices(array $args, array $assoc_args): void
    {
        require_once SA_CORE_DIR . 'includes/import-shopify.php';
        $result = sa_core_repair_inflated_usd_prices([
            'limit'   => isset($assoc_args['limit']) ? (int) $assoc_args['limit'] : 5000,
            'dry_run' => isset($assoc_args['dry-run']),
            'force'   => isset($assoc_args['force']),
            'rate'    => isset($assoc_args['rate']) ? (float) $assoc_args['rate'] : 0.0,
        ]);
        WP_CLI::success(sprintf(
            'Price repair%s: examined=%d repaired=%d skipped=%d rate=%s ndjson_files=%d',
            !empty($result['dry_run']) ? ' (dry-run)' : '',
            $result['examined'],
            $result['repaired'],
            $result['skipped'],
            (string) $result['rate'],
            count($result['ndjson_files'])
        ));
        foreach ($result['ndjson_files'] as $f) {
            WP_CLI::log('NDJSON: ' . $f);
        }
        foreach ($result['samples'] as $s) {
            WP_CLI::log(sprintf(
                '  #%d %s [%s] regular %s → %s (sale %s → %s) usd=%s',
                $s['id'],
                $s['handle'] ?: '-',
                $s['source'],
                $s['before_regular'],
                $s['after_regular'],
                $s['before_sale'] ?? '-',
                $s['after_sale'] ?? '-',
                $s['usd']
            ));
        }
        foreach ($result['messages'] as $m) {
            WP_CLI::warning($m);
        }
    }

    /**
     * Audit published products for missing/shared images; sideload unique CDN/web photos or draft.
     *
     * ## OPTIONS
     * [--limit=<n>]
     * : Max products (default 500)
     * [--dry-run]
     * : Report only
     * [--skip-web]
     * : Do not use web fallback (skip-web)
     *
     * ## EXAMPLES
     *     wp supreme fix-images
     *     wp supreme fix-images --dry-run --limit=100
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function fix_images(array $args, array $assoc_args): void
    {
        require_once SA_CORE_DIR . 'includes/import-shopify.php';
        if (!function_exists('sa_core_get_stored_shopify_image_urls')) {
            require_once SA_CORE_DIR . 'includes/product-images.php';
        }
        $result = sa_core_audit_fix_product_images([
            'limit'     => isset($assoc_args['limit']) ? (int) $assoc_args['limit'] : 500,
            'dry_run'   => isset($assoc_args['dry-run']),
            'allow_web' => !isset($assoc_args['skip-web']),
        ]);
        WP_CLI::success(sprintf(
            'Image audit%s: examined=%d ok=%d fixed=%d drafted=%d shared_flagged=%d web_fallback=%d',
            !empty($assoc_args['dry-run']) ? ' (dry-run)' : '',
            $result['examined'],
            $result['ok'],
            $result['fixed'],
            $result['drafted'],
            $result['shared_fixed'],
            $result['web_fallback']
        ));
        foreach (array_slice($result['messages'], 0, 30) as $m) {
            WP_CLI::log($m);
        }
    }

}

WP_CLI::add_command('supreme', 'SA_Core_CLI_Command');
