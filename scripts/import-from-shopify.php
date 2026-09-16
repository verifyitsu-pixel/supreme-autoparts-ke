<?php
/**
 * Stream Shopify NDJSON (or products.json) into WooCommerce.
 *
 * Idempotent by Shopify id meta / handle / SKU.
 *
 * Usage (inside WP container with WP loaded via eval-file):
 *   wp eval-file scripts/import-from-shopify.php -- \
 *     --file=data/scrape/products.ndjson --limit=500 --offset=0
 *
 * Flags:
 *   --file=PATH       Path to products.ndjson or sample-products.json
 *   --limit=N         Max matching products this run (0 = all)
 *   --offset=N        Skip first N lines/products
 *   --category=SLUG   IA parent filter (brakes, suspension, …)
 *   --dry-run         Count only — no writes
 *   --mapping=PATH    product_type → parent JSON map
 *   --skip-images     Do not sideload images (faster bulk pass)
 *   --require-images  Skip products without real CDN images
 *
 * Full catalog scrape first:
 *   python3 scripts/scrape-shopify-catalog.py --resume
 * See data/scrape/README.md
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    // Allow direct bootstrap hints when not under wp eval-file
    fwrite(STDERR, "Run via: wp eval-file scripts/import-from-shopify.php -- --file=data/scrape/products.ndjson --limit=500\n");
    // Continue if SA_CORE is loadable in CLI context later
}

$opts = [
    'file'          => '',
    'limit'         => 0,
    'offset'        => 0,
    'skip_images'   => false,
    'require_images'=> false,
    'category'      => getenv('SUPREME_IMPORT_CATEGORY') ?: '',
    'dry_run'       => false,
    'mapping'       => getenv('SUPREME_IMPORT_MAPPING') ?: '',
];

$argv_list = $GLOBALS['argv'] ?? [];
foreach ($argv_list as $arg) {
    if (!is_string($arg)) {
        continue;
    }
    if (str_starts_with($arg, '--file=')) {
        $opts['file'] = substr($arg, 7);
    } elseif (str_starts_with($arg, '--limit=')) {
        $opts['limit'] = (int) substr($arg, 8);
    } elseif (str_starts_with($arg, '--offset=')) {
        $opts['offset'] = (int) substr($arg, 9);
    } elseif (str_starts_with($arg, '--category=')) {
        $opts['category'] = substr($arg, 11);
    } elseif (str_starts_with($arg, '--mapping=')) {
        $opts['mapping'] = substr($arg, 10);
    } elseif ($arg === '--skip-images') {
        $opts['skip_images'] = true;
    } elseif ($arg === '--require-images') {
        $opts['require_images'] = true;
    } elseif ($arg === '--dry-run') {
        $opts['dry_run'] = true;
    }
}

if ($opts['file'] === '') {
    $candidates = [
        dirname(__DIR__) . '/data/scrape/products.ndjson',
        dirname(__DIR__) . '/data/sample-products.json',
    ];
    foreach ($candidates as $c) {
        if (is_readable($c)) {
            $opts['file'] = $c;
            break;
        }
    }
}

if ($opts['file'] === '' || !is_readable($opts['file'])) {
    $msg = 'No readable --file= given and no default scrape/sample found.';
    if (defined('WP_CLI') && WP_CLI) {
        WP_CLI::error($msg);
    }
    fwrite(STDERR, $msg . "\n");
    return;
}

$plugin_import = dirname(__DIR__) . '/wp-content/plugins/supreme-autoparts-core/includes/import-shopify.php';
if (is_readable($plugin_import)) {
    require_once $plugin_import;
}

if (!function_exists('sa_core_import_shopify_products_file')) {
    $msg = 'sa_core_import_shopify_products_file() not available — load WordPress + plugin.';
    if (defined('WP_CLI') && WP_CLI) {
        WP_CLI::error($msg);
    }
    fwrite(STDERR, $msg . "\n");
    return;
}

$result = sa_core_import_shopify_products_file($opts['file'], [
    'limit'          => $opts['limit'],
    'offset'         => $opts['offset'],
    'skip_images'    => $opts['skip_images'],
    'require_images' => $opts['require_images'],
    'category'       => (string) $opts['category'],
    'dry_run'        => (bool) $opts['dry_run'],
    'mapping'        => (string) $opts['mapping'],
]);

$summary = sprintf(
    "Import done%s: imported=%d updated=%d skipped=%d filtered=%d errors=%d file=%s limit=%s offset=%d category=%s",
    !empty($result['dry_run']) ? ' [dry-run]' : '',
    $result['imported'],
    $result['updated'] ?? 0,
    $result['skipped'],
    $result['filtered'] ?? 0,
    $result['errors'],
    $opts['file'],
    $opts['limit'] ?: 'all',
    $opts['offset'],
    ($opts['category'] !== '' ? $opts['category'] : 'all')
);

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::success($summary);
    foreach (array_slice($result['messages'], 0, 20) as $m) {
        WP_CLI::log($m);
    }
} else {
    echo $summary . "\n";
}
