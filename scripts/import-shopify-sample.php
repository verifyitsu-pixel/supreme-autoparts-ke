<?php
/**
 * Run inside container:
 *   wp eval-file wp-content/plugins/supreme-autoparts-core/scripts/import-shopify-sample.php
 * Or:
 *   php scripts/import-shopify-sample.php  (if bootstrapped)
 *
 * Looks for sample JSON in plugin data/, then /usr/src/supreme-data/, then /workspace paths.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    fwrite(STDERR, "This script must be run via WP-CLI eval-file inside WordPress.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/includes/import-shopify.php';
if (file_exists(dirname(__DIR__) . '/includes/seed-categories.php')) {
    require_once dirname(__DIR__) . '/includes/seed-categories.php';
}

$candidates = [
    dirname(__DIR__) . '/data/sample-products.json',
    '/usr/src/supreme-data/sample-products.json',
    '/var/www/html/wp-content/plugins/supreme-autoparts-core/data/sample-products.json',
    '/workspace/SUPREMEAUTOPARTS/data/sample-products.json',
    '/workspace/supreme-autoparts-brief/sample-products.json',
];

$file = null;
foreach ($candidates as $path) {
    if (is_readable($path)) {
        $file = $path;
        break;
    }
}

if (!$file) {
    fwrite(STDERR, "sample-products.json not found in known locations.\n");
    exit(1);
}

if (function_exists('sa_core_seed_categories')) {
    sa_core_seed_categories();
}

echo "Importing from {$file}\n";
$result = sa_core_import_shopify_products_file($file);
echo sprintf(
    "Imported: %d | Skipped: %d | Errors: %d\n",
    $result['imported'],
    $result['skipped'],
    $result['errors']
);
foreach ($result['messages'] as $msg) {
    echo " - {$msg}\n";
}
