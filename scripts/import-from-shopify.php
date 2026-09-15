<?php
/**
 * Documented stub for bulk Shopify → WooCommerce import.
 *
 * Full catalog on the source is on the order of ~1M listings across ~917 collections.
 * Do NOT scrape everything in one pass. Prefer:
 *   1) Shopify Admin CSV / JSON export, or
 *   2) Paginated public endpoints:
 *        GET https://{shop}/collections.json
 *        GET https://{shop}/collections/{handle}/products.json?page=N
 *        GET https://{shop}/products/{handle}.json
 *
 * Usage (inside container, after configuring SOURCE_SHOP):
 *   wp eval-file wp-content/plugins/supreme-autoparts-core/scripts/import-shopify-sample.php
 *
 * For production bulk load, extend includes/import-shopify.php with pagination,
 * rate limiting, and resume checkpoints — out of scope for the seed scaffold.
 */

declare(strict_types=1);

echo "Use the sample importer for now. Full pipeline is documented in README.md\n";
