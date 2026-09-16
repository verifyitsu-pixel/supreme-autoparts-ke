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

function sa_core_render_import_page(): void
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $message = '';
    if (isset($_POST['sa_import_sample']) && check_admin_referer('sa_import_sample')) {
        require_once SA_CORE_DIR . 'includes/import-shopify.php';
        $file = SA_CORE_DIR . 'data/sample-products.json';
        $result = sa_core_import_shopify_products_file($file, [
            'require_images' => !empty($_POST['sa_require_images']),
        ]);
        $message = sprintf(
            'Sample done: %d imported, %d updated, %d skipped, %d errors.',
            $result['imported'],
            $result['updated'] ?? 0,
            $result['skipped'],
            $result['errors']
        );
    }
    if (isset($_POST['sa_import_batch']) && check_admin_referer('sa_import_batch')) {
        require_once SA_CORE_DIR . 'includes/import-shopify.php';
        $candidates = [
            SA_CORE_DIR . 'data/scrape/chunks/batch-with-images-400.ndjson',
            '/usr/src/supreme-data/scrape/chunks/batch-with-images-400.ndjson',
            trailingslashit(ABSPATH) . 'data/scrape/chunks/batch-with-images-400.ndjson',
        ];
        $file = '';
        foreach ($candidates as $c) {
            if (is_readable($c)) {
                $file = $c;
                break;
            }
        }
        if ($file === '') {
            $message = 'Batch file not found (data/scrape/chunks/batch-with-images-400.ndjson).';
        } else {
            $limit = isset($_POST['sa_batch_limit']) ? max(1, (int) $_POST['sa_batch_limit']) : 400;
            $skip = !empty($_POST['sa_skip_sideload']);
            $result = sa_core_import_shopify_products_file($file, [
                'limit'          => $limit,
                'require_images' => true,
                'skip_images'    => $skip,
            ]);
            $message = sprintf(
                'Batch %s: imported=%d updated=%d skipped=%d errors=%d (CDN URLs stored; sideload %s).',
                $file,
                $result['imported'],
                $result['updated'] ?? 0,
                $result['skipped'],
                $result['errors'],
                $skip ? 'skipped' : 'attempted'
            );
        }
    }
    if (isset($_POST['sa_seed_tax']) && check_admin_referer('sa_seed_tax')) {
        sa_core_seed_categories();
        sa_core_seed_pages();
        $message = 'Categories and pages re-seeded.';
    }
    ?>
    <div class="wrap">
      <h1>Supreme Autoparts — Import</h1>
      <?php if ($message) : ?>
        <div class="notice notice-success"><p><?php echo esc_html($message); ?></p></div>
      <?php endif; ?>
      <p>Catalog photos come only from scraped <strong>Shopify CDN</strong> URLs (<code>cdn.shopify.com</code>). No AI or stock placeholders are generated. Products without a real <code>images[].src</code> stay imageless.</p>
      <form method="post" style="margin:1em 0;">
        <?php wp_nonce_field('sa_import_sample'); ?>
        <p><label><input type="checkbox" name="sa_require_images" value="1" checked> Require real images</label></p>
        <p><button class="button" name="sa_import_sample" value="1">Import sample products</button></p>
      </form>
      <form method="post" style="margin:1em 0;">
        <?php wp_nonce_field('sa_import_batch'); ?>
        <p>
          <label>Limit <input type="number" name="sa_batch_limit" value="400" min="1" max="2000"></label>
          <label style="margin-left:1em;"><input type="checkbox" name="sa_skip_sideload" value="1"> Skip binary sideload (CDN meta only — faster)</label>
        </p>
        <p><button class="button button-primary" name="sa_import_batch" value="1">Import first real batch (400 with Shopify photos)</button></p>
      </form>
      <form method="post">
        <?php wp_nonce_field('sa_seed_tax'); ?>
        <p><button class="button" name="sa_seed_tax" value="1">Re-seed categories &amp; pages</button></p>
      </form>
      <h2>WP-CLI</h2>
      <pre>wp supreme import-ndjson --file=.../batch-with-images-400.ndjson --require-images
wp supreme import-sample --require-images
wp supreme seed-categories
wp supreme seed-pages</pre>
    </div>
    <?php
}
