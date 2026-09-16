<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', static function (): void {
    add_management_page(
        'Supreme Import',
        'Supreme Import',
        'manage_woocommerce',
        'supreme-import',
        'sa_core_render_import_page'
    );
});

/**
 * @return list<string>
 */
function sa_core_import_ui_parent_slugs(): array
{
    return [
        'brakes',
        'suspension',
        'exhaust',
        'engine',
        'air-intake',
        'lighting',
        'drivetrain',
        'exterior',
        'interior',
        'wheels',
        'tires',
    ];
}

/**
 * @return list<string>
 */
function sa_core_import_ui_batch_candidates(): array
{
    return [
        SA_CORE_DIR . 'data/scrape/chunks/batch-with-images-400.ndjson',
        SA_CORE_DIR . 'data/scrape/chunks/batch-with-images-50.ndjson',
        SA_CORE_DIR . 'data/scrape/chunks/batch-with-images-2000.ndjson',
        '/usr/src/supreme-data/scrape/chunks/batch-with-images-400.ndjson',
        '/usr/src/supreme-data/scrape/chunks/batch-with-images-50.ndjson',
        trailingslashit(ABSPATH) . 'data/scrape/chunks/batch-with-images-400.ndjson',
        trailingslashit(ABSPATH) . 'data/scrape/chunks/batch-with-images-50.ndjson',
    ];
}

function sa_core_render_import_page(): void
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $message = '';
    $is_error = false;

    if (isset($_POST['sa_import_sample']) && check_admin_referer('sa_import_sample')) {
        require_once SA_CORE_DIR . 'includes/import-shopify.php';
        $file = SA_CORE_DIR . 'data/sample-products.json';
        $result = sa_core_import_shopify_products_file($file, [
            'require_images' => !empty($_POST['sa_require_images']),
            'dry_run'        => !empty($_POST['sa_dry_run']),
            'category'       => sanitize_title((string) ($_POST['sa_category'] ?? '')),
            'limit'          => isset($_POST['sa_batch_limit']) ? max(0, (int) $_POST['sa_batch_limit']) : 0,
        ]);
        $message = sprintf(
            'Sample%s: %d imported, %d updated, %d skipped, %d filtered, %d errors.',
            !empty($result['dry_run']) ? ' [dry-run]' : '',
            $result['imported'],
            $result['updated'] ?? 0,
            $result['skipped'],
            $result['filtered'] ?? 0,
            $result['errors']
        );
        $is_error = ($result['errors'] ?? 0) > 0 && ($result['imported'] + ($result['updated'] ?? 0)) === 0;
    }

    if (isset($_POST['sa_import_batch']) && check_admin_referer('sa_import_batch')) {
        require_once SA_CORE_DIR . 'includes/import-shopify.php';
        $file = '';
        foreach (sa_core_import_ui_batch_candidates() as $c) {
            if (is_readable($c)) {
                $file = $c;
                break;
            }
        }
        if ($file === '') {
            $message = 'Batch file not found (data/scrape/chunks/batch-with-images-*.ndjson).';
            $is_error = true;
        } else {
            $limit = isset($_POST['sa_batch_limit']) ? max(1, (int) $_POST['sa_batch_limit']) : 50;
            $skip = !empty($_POST['sa_skip_sideload']);
            $category = sanitize_title((string) ($_POST['sa_category'] ?? ''));
            $dry = !empty($_POST['sa_dry_run']);
            $require = !empty($_POST['sa_require_images']);
            $mapping = SA_CORE_DIR . 'data/product-type-parent-map.json';
            $result = sa_core_import_shopify_products_file($file, [
                'limit'          => $limit,
                'require_images' => $require,
                'skip_images'    => $skip,
                'category'       => $category,
                'dry_run'        => $dry,
                'mapping'        => is_readable($mapping) ? $mapping : '',
            ]);
            $message = sprintf(
                'Batch%s %s: imported=%d updated=%d skipped=%d filtered=%d errors=%d category=%s (CDN URLs stored; sideload %s).',
                $dry ? ' [dry-run]' : '',
                $file,
                $result['imported'],
                $result['updated'] ?? 0,
                $result['skipped'],
                $result['filtered'] ?? 0,
                $result['errors'],
                $category !== '' ? $category : 'all',
                $skip ? 'skipped' : 'attempted'
            );
            $is_error = ($result['errors'] ?? 0) > 0 && ($result['imported'] + ($result['updated'] ?? 0)) === 0;
        }
    }


    if (isset($_POST['sa_repair_prices']) && check_admin_referer('sa_repair_prices')) {
        require_once SA_CORE_DIR . 'includes/import-shopify.php';
        $result = sa_core_repair_inflated_usd_prices([
            'limit'   => isset($_POST['sa_repair_limit']) ? max(1, (int) $_POST['sa_repair_limit']) : 5000,
            'dry_run' => !empty($_POST['sa_repair_dry_run']),
            'force'   => !empty($_POST['sa_repair_force']),
        ]);
        $sample = '';
        if (!empty($result['samples'][0])) {
            $s = $result['samples'][0];
            $sample = sprintf(
                ' Sample: %s regular %s → %s.',
                $s['handle'] ?: ('#' . $s['id']),
                $s['before_regular'],
                $s['after_regular']
            );
        }
        $message = sprintf(
            'Price repair%s: examined=%d repaired=%d skipped=%d rate=%s.%s',
            !empty($result['dry_run']) ? ' [dry-run]' : '',
            $result['examined'],
            $result['repaired'],
            $result['skipped'],
            (string) $result['rate'],
            $sample
        );
        $is_error = false;
        if (!empty($result['repaired']) && empty($result['dry_run'])) {
            update_option('sa_price_usd_repair_v1', '1');
        }
    }

    if (isset($_POST['sa_seed_tax']) && check_admin_referer('sa_seed_tax')) {
        sa_core_seed_categories();
        sa_core_seed_pages();
        $message = 'Categories and pages re-seeded.';
    }

    $parents = sa_core_import_ui_parent_slugs();
    ?>
    <div class="wrap">
      <h1>Supreme Autoparts — Import</h1>
      <?php if ($message) : ?>
        <div class="notice notice-<?php echo $is_error ? 'error' : 'success'; ?>"><p><?php echo esc_html($message); ?></p></div>
      <?php endif; ?>
      <p>Catalog photos come only from scraped <strong>Shopify CDN</strong> URLs (<code>cdn.shopify.com</code>). No AI or stock placeholders are generated. Import <strong>one parent category at a time</strong> (e.g. brakes) while the scrape continues — see <code>docs/SUPREME_IMPORT.md</code>.</p>

      <form method="post" style="margin:1em 0;padding:1em;border:1px solid #c3c4c7;background:#fff;max-width:720px;">
        <?php wp_nonce_field('sa_import_batch'); ?>
        <h2 style="margin-top:0;">Customizable batch import</h2>
        <p>
          <label>Parent category
            <select name="sa_category">
              <option value="">All (not recommended for first live pass)</option>
              <?php foreach ($parents as $slug) : ?>
                <option value="<?php echo esc_attr($slug); ?>"<?php selected($slug, 'brakes'); ?>><?php echo esc_html($slug); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </p>
        <p>
          <label>Limit <input type="number" name="sa_batch_limit" value="50" min="1" max="2000"></label>
          <span class="description">Max matching products (category filter does not burn the limit).</span>
        </p>
        <p>
          <label><input type="checkbox" name="sa_require_images" value="1" checked> Require real images</label>
          <label style="margin-left:1em;"><input type="checkbox" name="sa_skip_sideload" value="1" checked> Skip binary sideload (CDN meta only — faster)</label>
          <label style="margin-left:1em;"><input type="checkbox" name="sa_dry_run" value="1"> Dry-run (no writes)</label>
        </p>
        <p><button class="button button-primary" name="sa_import_batch" value="1">Run import</button></p>
        <p class="description">Uses the first readable <code>batch-with-images-*.ndjson</code> (prefers 400, then 50). Mapping: <code>data/product-type-parent-map.json</code>.</p>
      </form>

      <form method="post" style="margin:1em 0;">
        <?php wp_nonce_field('sa_import_sample'); ?>
        <p>
          <label>Category
            <select name="sa_category">
              <option value="">All</option>
              <?php foreach ($parents as $slug) : ?>
                <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($slug); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label style="margin-left:1em;">Limit <input type="number" name="sa_batch_limit" value="40" min="0" max="200"></label>
          <label style="margin-left:1em;"><input type="checkbox" name="sa_require_images" value="1" checked> Require images</label>
          <label style="margin-left:1em;"><input type="checkbox" name="sa_dry_run" value="1"> Dry-run</label>
        </p>
        <p><button class="button" name="sa_import_sample" value="1">Import sample products</button></p>
      </form>


      <form method="post" style="margin:1em 0;padding:1em;border:1px solid #c3c4c7;background:#fff;max-width:720px;">
        <?php wp_nonce_field('sa_repair_prices'); ?>
        <h2 style="margin-top:0;">Repair inflated USD prices</h2>
        <p>Fixes catalogs imported as Shopify&nbsp;USD × <code>SUPREME_USD_TO_KES</code> (~130) after the store switched to USD checkout. Prefers NDJSON / <code>_sa_shopify_price_usd</code>; otherwise divides when amounts look like ×130.</p>
        <p>
          <label>Limit <input type="number" name="sa_repair_limit" value="5000" min="1" max="50000"></label>
          <label style="margin-left:1em;"><input type="checkbox" name="sa_repair_dry_run" value="1"> Dry-run</label>
          <label style="margin-left:1em;"><input type="checkbox" name="sa_repair_force" value="1"> Force from NDJSON/meta</label>
        </p>
        <p><button class="button button-secondary" name="sa_repair_prices" value="1">Repair prices</button></p>
        <p class="description">WP-CLI: <code>wp supreme repair-prices</code> · <code>wp supreme repair-prices --dry-run</code></p>
      </form>

      <form method="post">
        <?php wp_nonce_field('sa_seed_tax'); ?>
        <p><button class="button" name="sa_seed_tax" value="1">Re-seed categories &amp; pages</button></p>
      </form>

      <h2>WP-CLI</h2>
      <pre>wp supreme import-ndjson --category=brakes --limit=50 --require-images --dry-run
wp supreme import-ndjson --file=.../batch-with-images-400.ndjson --category=brakes --require-images --skip-images
wp supreme import-ndjson --mapping=/path/to/product-type-parent-map.json --category=suspension --limit=100 --require-images
wp supreme seed-categories
wp supreme seed-pages</pre>
    </div>
    <?php
}
