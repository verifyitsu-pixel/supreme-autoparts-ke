<?php
/**
 * Empty catalog / filter results.
 *
 * @package Supreme_Autoparts
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$clear = function_exists('sa_core_catalog_clear_filters_url') ? sa_core_catalog_clear_filters_url() : home_url('/shop/');
$shop  = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/shop/');
$active = function_exists('sa_core_catalog_filters_active') && sa_core_catalog_filters_active();
?>
<div class="sa-empty sa-empty--filters">
  <div class="sa-empty__icon" aria-hidden="true">⌀</div>
  <h2 class="sa-empty__title"><?php esc_html_e('No parts matched', 'supreme-autoparts'); ?></h2>
  <p class="sa-empty__text">
    <?php if ($active) : ?>
      <?php esc_html_e('Try a different part number, clear filters, or browse categories. Catalog is still growing — enquire if you need a specific OE number.', 'supreme-autoparts'); ?>
    <?php else : ?>
      <?php esc_html_e('Nothing in this section yet. Search by part number or browse another category.', 'supreme-autoparts'); ?>
    <?php endif; ?>
  </p>
  <div class="sa-empty__actions">
    <?php if ($active) : ?>
      <a class="sa-btn" href="<?php echo esc_url($clear); ?>"><?php esc_html_e('Clear filters', 'supreme-autoparts'); ?></a>
    <?php endif; ?>
    <a class="sa-btn sa-btn--ghost" href="<?php echo esc_url($shop); ?>"><?php esc_html_e('Browse shop', 'supreme-autoparts'); ?></a>
    <a class="sa-btn sa-btn--ghost" href="<?php echo esc_url(function_exists('sa_page_url') ? sa_page_url('contact') : home_url('/contact/')); ?>"><?php esc_html_e('Enquire', 'supreme-autoparts'); ?></a>
  </div>
</div>
<?php
if (function_exists('sa_render_enquire')) {
    echo '<div class="sa-empty__enquire">';
    sa_render_enquire(['context' => $active ? 'empty-filters' : 'empty-shop']);
    echo '</div>';
}
