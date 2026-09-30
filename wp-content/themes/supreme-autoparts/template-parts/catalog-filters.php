<?php
/**
 * Shop / search catalog filters — part number first.
 *
 * @package Supreme_Autoparts
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$filters = function_exists('sa_core_catalog_filters_from_request')
    ? sa_core_catalog_filters_from_request()
    : [
        's' => get_search_query(),
        'sa_pn' => '',
        'filter_brand' => '',
        'sa_make' => '',
        'sa_model' => '',
        'sa_year' => '',
    ];

$brands = function_exists('sa_core_brand_filter_options') ? sa_core_brand_filter_options() : [];
$makes  = function_exists('sa_core_make_filter_options') ? sa_core_make_filter_options() : [];
$action = function_exists('sa_core_catalog_filter_form_action') ? sa_core_catalog_filter_form_action() : home_url('/shop/');
$clear  = function_exists('sa_core_catalog_clear_filters_url') ? sa_core_catalog_clear_filters_url() : home_url('/shop/');
$active = function_exists('sa_core_catalog_filters_active') ? sa_core_catalog_filters_active($filters) : false;

$total = 0;
if (isset($GLOBALS['wp_query']) && $GLOBALS['wp_query'] instanceof WP_Query) {
    $total = (int) $GLOBALS['wp_query']->found_posts;
}
?>
<section class="sa-filters" aria-label="<?php esc_attr_e('Filter parts', 'supreme-autoparts'); ?>">
  <form class="sa-filters__form" method="get" action="<?php echo esc_url($action); ?>" data-sa-filters>
    <div class="sa-filters__grid">
      <label class="sa-filters__field sa-filters__field--pn">
        <span class="sa-filters__label"><?php esc_html_e('Part number', 'supreme-autoparts'); ?></span>
        <input
          type="search"
          name="sa_pn"
          value="<?php echo esc_attr($filters['sa_pn'] ?? ''); ?>"
          placeholder="<?php esc_attr_e('SKU / OE / part #', 'supreme-autoparts'); ?>"
          autocomplete="off"
          enterkeyhint="search"
        />
      </label>

      <label class="sa-filters__field">
        <span class="sa-filters__label"><?php esc_html_e('Keyword', 'supreme-autoparts'); ?></span>
        <input
          type="search"
          name="s"
          value="<?php echo esc_attr($filters['s'] ?? ''); ?>"
          placeholder="<?php esc_attr_e('Name or keyword…', 'supreme-autoparts'); ?>"
          autocomplete="off"
        />
      </label>

      <label class="sa-filters__field">
        <span class="sa-filters__label"><?php esc_html_e('Brand', 'supreme-autoparts'); ?></span>
        <select name="filter_brand">
          <option value=""><?php esc_html_e('All brands', 'supreme-autoparts'); ?></option>
          <?php foreach ($brands as $b) : ?>
            <option value="<?php echo esc_attr($b['slug']); ?>" <?php selected($filters['filter_brand'] ?? '', $b['slug']); ?>>
              <?php echo esc_html($b['name'] . ' (' . number_format_i18n($b['count']) . ')'); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="sa-filters__field">
        <span class="sa-filters__label"><?php esc_html_e('Make', 'supreme-autoparts'); ?></span>
        <select name="sa_make">
          <option value=""><?php esc_html_e('All makes', 'supreme-autoparts'); ?></option>
          <?php foreach ($makes as $make) : ?>
            <option value="<?php echo esc_attr($make); ?>" <?php selected($filters['sa_make'] ?? '', $make); ?>>
              <?php echo esc_html($make); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="sa-filters__field">
        <span class="sa-filters__label"><?php esc_html_e('Model', 'supreme-autoparts'); ?></span>
        <input
          type="text"
          name="sa_model"
          value="<?php echo esc_attr($filters['sa_model'] ?? ''); ?>"
          placeholder="<?php esc_attr_e('F-150, Civic…', 'supreme-autoparts'); ?>"
          autocomplete="off"
        />
      </label>

      <label class="sa-filters__field sa-filters__field--year">
        <span class="sa-filters__label"><?php esc_html_e('Year', 'supreme-autoparts'); ?></span>
        <input
          type="text"
          name="sa_year"
          value="<?php echo esc_attr($filters['sa_year'] ?? ''); ?>"
          placeholder="<?php esc_attr_e('2021', 'supreme-autoparts'); ?>"
          inputmode="numeric"
          autocomplete="off"
        />
      </label>

      <div class="sa-filters__actions">
        <button type="submit" class="sa-btn sa-filters__submit">
          <?php esc_html_e('Apply filters', 'supreme-autoparts'); ?>
        </button>
        <?php if ($active) : ?>
          <a class="sa-filters__clear" href="<?php echo esc_url($clear); ?>">
            <?php esc_html_e('Clear all', 'supreme-autoparts'); ?>
          </a>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($active) : ?>
      <div class="sa-filters__chips" aria-live="polite">
        <?php
        $chips = [];
        if (($filters['sa_pn'] ?? '') !== '') {
            $chips[] = sprintf(__('Part # %s', 'supreme-autoparts'), $filters['sa_pn']);
        }
        if (($filters['s'] ?? '') !== '') {
            $chips[] = sprintf(__('“%s”', 'supreme-autoparts'), $filters['s']);
        }
        if (($filters['filter_brand'] ?? '') !== '') {
            $label = $filters['filter_brand'];
            foreach ($brands as $b) {
                if ($b['slug'] === $filters['filter_brand']) {
                    $label = $b['name'];
                    break;
                }
            }
            $chips[] = sprintf(__('Brand: %s', 'supreme-autoparts'), $label);
        }
        if (($filters['sa_make'] ?? '') !== '') {
            $chips[] = sprintf(__('Make: %s', 'supreme-autoparts'), $filters['sa_make']);
        }
        if (($filters['sa_model'] ?? '') !== '') {
            $chips[] = sprintf(__('Model: %s', 'supreme-autoparts'), $filters['sa_model']);
        }
        if (($filters['sa_year'] ?? '') !== '') {
            $chips[] = sprintf(__('Year: %s', 'supreme-autoparts'), $filters['sa_year']);
        }
        foreach ($chips as $chip) :
            ?>
          <span class="sa-filters__chip"><?php echo esc_html($chip); ?></span>
        <?php endforeach; ?>
        <span class="sa-filters__count">
          <?php
          printf(
              /* translators: %s: result count */
              esc_html(_n('%s result', '%s results', $total, 'supreme-autoparts')),
              esc_html(number_format_i18n($total))
          );
          ?>
        </span>
      </div>
    <?php endif; ?>
  </form>
</section>
