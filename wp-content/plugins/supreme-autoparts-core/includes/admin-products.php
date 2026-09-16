<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Product bulk tools stub: republish + assign parent category.
 */
add_action('admin_menu', static function (): void {
    add_submenu_page(
        'supreme-autoparts',
        'Product tools',
        'Product tools',
        'manage_woocommerce',
        'supreme-products',
        'sa_core_render_product_tools_page'
    );
}, 21);

function sa_core_render_product_tools_page(): void
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $notice = '';
    $notice_ok = false;

    if (isset($_POST['sa_bulk_republish']) && check_admin_referer('sa_bulk_products')) {
        $ids = sa_core_ultra_parse_product_ids((string) ($_POST['sa_product_ids'] ?? ''));
        $done = 0;
        foreach ($ids as $id) {
            $post = get_post($id);
            if (!$post || $post->post_type !== 'product') {
                continue;
            }
            wp_update_post([
                'ID'          => $id,
                'post_status' => 'publish',
            ]);
            $done++;
        }
        $notice = sprintf('Republished %d product(s). (Stub — no catalog rebuild.)', $done);
        $notice_ok = true;
    }

    if (isset($_POST['sa_bulk_assign_cat']) && check_admin_referer('sa_bulk_products')) {
        $ids = sa_core_ultra_parse_product_ids((string) ($_POST['sa_product_ids'] ?? ''));
        $term_id = absint($_POST['sa_parent_cat'] ?? 0);
        $done = 0;
        if ($term_id > 0 && term_exists($term_id, 'product_cat')) {
            foreach ($ids as $id) {
                $post = get_post($id);
                if (!$post || $post->post_type !== 'product') {
                    continue;
                }
                wp_set_object_terms($id, [$term_id], 'product_cat', true);
                $done++;
            }
            $notice = sprintf('Assigned parent category to %d product(s).', $done);
            $notice_ok = true;
        } else {
            $notice = 'Choose a valid product category.';
        }
    }

    $cats = get_terms([
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
        'parent'     => 0,
    ]);
    if (is_wp_error($cats)) {
        $cats = [];
    }

    $email = function_exists('sa_core_store_email') ? sa_core_store_email() : 'calvin@supremeautoparts.co.ke';
    ?>
    <div class="wrap sa-ultra">
      <div class="sa-ultra__header">
        <div>
          <h1 class="sa-ultra__title">Product tools</h1>
          <p class="sa-ultra__sub">Bulk stubs for republish and parent category assignment. Paste product IDs (comma or newline separated).</p>
        </div>
        <a class="sa-ultra__email" href="mailto:<?php echo esc_attr($email); ?>"><?php echo esc_html($email); ?></a>
      </div>

      <?php if ($notice !== '') : ?>
        <div class="sa-inline-notice<?php echo $notice_ok ? ' sa-inline-notice--ok' : ' sa-inline-notice--warn'; ?>">
          <?php echo esc_html($notice); ?>
        </div>
      <?php endif; ?>

      <div class="sa-actions" style="margin-bottom:16px;">
        <a class="button button-primary" href="<?php echo esc_url(admin_url('edit.php?post_type=product')); ?>">All products</a>
        <a class="button" href="<?php echo esc_url(admin_url('edit-tags.php?taxonomy=product_cat&post_type=product')); ?>">Categories</a>
        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=supreme-import')); ?>">Import tools</a>
      </div>

      <div class="sa-panel">
        <h2 class="sa-panel__title">Bulk actions (stub)</h2>
        <form method="post" class="sa-form-grid" style="margin-top:12px;">
          <?php wp_nonce_field('sa_bulk_products'); ?>
          <label>
            Product IDs
            <textarea name="sa_product_ids" rows="4" placeholder="101, 102, 103"><?php echo isset($_POST['sa_product_ids']) ? esc_textarea(wp_unslash((string) $_POST['sa_product_ids'])) : ''; ?></textarea>
          </label>
          <label>
            Parent category (for assign)
            <select name="sa_parent_cat">
              <option value="0">— Select —</option>
              <?php foreach ($cats as $cat) : ?>
                <option value="<?php echo esc_attr((string) $cat->term_id); ?>"><?php echo esc_html($cat->name); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <div class="sa-actions">
            <button type="submit" name="sa_bulk_republish" class="button button-primary" value="1">Republish</button>
            <button type="submit" name="sa_bulk_assign_cat" class="button" value="1">Assign parent category</button>
          </div>
        </form>
        <p class="sa-muted" style="margin-bottom:0;">These are lightweight stubs — no mass rewrite of SKUs or images. Use Import tools for catalog restore.</p>
      </div>
    </div>
    <?php
}

/**
 * @return list<int>
 */
function sa_core_ultra_parse_product_ids(string $raw): array
{
    $parts = preg_split('/[\s,;]+/', $raw) ?: [];
    $ids = [];
    foreach ($parts as $p) {
        $id = absint($p);
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    return array_values(array_unique($ids));
}
