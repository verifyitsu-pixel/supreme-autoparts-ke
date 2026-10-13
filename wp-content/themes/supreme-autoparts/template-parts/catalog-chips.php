<?php
/**
 * Active filter chips above results.
 *
 * @package Supreme_Autoparts
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

if (!function_exists('sa_fx_chips')) {
    return;
}
$chips = sa_fx_chips();
if ($chips === []) {
    return;
}
$clear = function_exists('sa_core_catalog_clear_filters_url') ? sa_core_catalog_clear_filters_url() : home_url('/shop/');
?>
<div class="sa-pf__chips" aria-label="<?php esc_attr_e('Active filters', 'supreme-autoparts'); ?>">
  <?php foreach ($chips as $chip) : ?>
    <a class="sa-chip" href="<?php echo esc_url($chip['url']); ?>" aria-label="<?php echo esc_attr(sprintf(__('Remove %s', 'supreme-autoparts'), $chip['label'])); ?>"><?php echo esc_html($chip['label']); ?> <span aria-hidden="true">&times;</span></a>
  <?php endforeach; ?>
  <a class="sa-chip sa-chip--clear" href="<?php echo esc_url($clear); ?>"><?php esc_html_e('Clear all', 'supreme-autoparts'); ?></a>
</div>
