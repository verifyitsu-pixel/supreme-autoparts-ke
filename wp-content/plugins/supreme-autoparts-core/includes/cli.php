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
        ]);
        WP_CLI::success(sprintf(
            'Imported %d, updated %d (%d skipped, %d errors).',
            $result['imported'],
            $result['updated'] ?? 0,
            $result['skipped'],
            $result['errors']
        ));
    }

    /**
     * Stream-import Shopify NDJSON catalog (idempotent by handle/SKU/shopify id).
     *
     * ## OPTIONS
     * [--file=<path>]
     * : Path to products.ndjson (default: ABSPATH/data/scrape/products.ndjson)
     * [--limit=<n>]
     * : Max products this run (0 = all remaining from offset)
     * [--offset=<n>]
     * : Skip first N NDJSON lines
     * [--skip-images]
     * : Skip binary sideload (still stores Shopify CDN URL meta for display)
     * [--require-images]
     * : Only import products that have at least one real http(s) image
     *
     * ## EXAMPLES
     *     wp supreme import-ndjson --limit=500 --require-images
     *     wp supreme import-ndjson --file=/var/www/html/wp-content/plugins/supreme-autoparts-core/data/scrape/chunks/batch-with-images-400.ndjson --require-images
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function import_ndjson(array $args, array $assoc_args): void
    {
        $default = trailingslashit(ABSPATH) . 'data/scrape/products.ndjson';
        $file = $assoc_args['file'] ?? $default;
        require_once SA_CORE_DIR . 'includes/import-shopify.php';
        $result = sa_core_import_shopify_products_file($file, [
            'limit'          => isset($assoc_args['limit']) ? (int) $assoc_args['limit'] : 0,
            'offset'         => isset($assoc_args['offset']) ? (int) $assoc_args['offset'] : 0,
            'skip_images'    => isset($assoc_args['skip-images']),
            'require_images' => isset($assoc_args['require-images']),
        ]);
        WP_CLI::success(sprintf(
            'NDJSON import: imported=%d updated=%d skipped=%d errors=%d file=%s',
            $result['imported'],
            $result['updated'] ?? 0,
            $result['skipped'],
            $result['errors'],
            $file
        ));
        foreach (array_slice($result['messages'], 0, 30) as $m) {
            WP_CLI::log($m);
        }
    }
}

WP_CLI::add_command('supreme', 'SA_Core_CLI_Command');
