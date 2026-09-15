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
        $result = sa_core_import_shopify_products_file($file);
        $message = sprintf(
            'Done: %d imported, %d skipped, %d errors.',
            $result['imported'],
            $result['skipped'],
            $result['errors']
        );
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
      <p>Source catalog is huge (~1M listings). This tool only imports the <strong>bundled sample</strong> JSON. Full catalog migration is a later pipeline using public Shopify JSON or admin exports.</p>
      <form method="post" style="margin:1em 0;">
        <?php wp_nonce_field('sa_import_sample'); ?>
        <p><button class="button button-primary" name="sa_import_sample" value="1">Import sample products</button></p>
      </form>
      <form method="post">
        <?php wp_nonce_field('sa_seed_tax'); ?>
        <p><button class="button" name="sa_seed_tax" value="1">Re-seed categories &amp; pages</button></p>
      </form>
      <h2>WP-CLI</h2>
      <pre>wp supreme import-sample
wp supreme seed-categories
wp supreme seed-pages</pre>
      <h2>Container script</h2>
      <pre>wp eval-file wp-content/plugins/supreme-autoparts-core/scripts/import-shopify-sample.php</pre>
    </div>
    <?php
}
