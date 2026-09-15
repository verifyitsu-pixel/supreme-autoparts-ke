<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

/**
 * WP-CLI commands: wp supreme seed-pages | seed-categories | import-sample
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
     *
     * ## EXAMPLES
     *     wp supreme import-sample
     *     wp supreme import-sample --file=/var/www/html/wp-content/plugins/supreme-autoparts-core/data/sample-products.json
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function import_sample(array $args, array $assoc_args): void
    {
        $file = $assoc_args['file'] ?? SA_CORE_DIR . 'data/sample-products.json';
        require_once SA_CORE_DIR . 'includes/import-shopify.php';
        $result = sa_core_import_shopify_products_file($file);
        WP_CLI::success(sprintf('Imported %d products (%d skipped, %d errors).', $result['imported'], $result['skipped'], $result['errors']));
    }
}

WP_CLI::add_command('supreme', 'SA_Core_CLI_Command');
