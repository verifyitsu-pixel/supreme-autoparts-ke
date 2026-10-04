<?php
/**
 * Shop / search filters grouped like a parts catalog.
 *
 * Groups use real Woo categories, brand terms, and the existing
 * part-number / make / model / year search. Price uses Woo min_price / max_price.
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

$sa_price_bound = static function (string $key): string {
    if (!isset($_GET[$key])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return '';
    }
    $raw = trim((string) wp_unslash($_GET[$key])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ($raw === '' || !is_numeric($raw)) {
        return '';
    }
    return function_exists('wc_format_decimal') ? (string) wc_format_decimal($raw) : $raw;
};
$min_price = $sa_price_bound('min_price');
$max_price = $sa_price_bound('max_price');

$orderby = isset($_GET['orderby']) ? sanitize_text_field(wp_unslash((string) $_GET['orderby'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$active = ($filters['s'] ?? '') !== ''
    || ($filters['sa_pn'] ?? '') !== ''
    || ($filters['filter_brand'] ?? '') !== ''
    || ($filters['sa_make'] ?? '') !== ''
    || ($filters['sa_model'] ?? '') !== ''
    || ($filters['sa_year'] ?? '') !== ''
    || $min_price !== ''
    || $max_price !== '';

$total = 0;
if (isset($GLOBALS['wp_query']) && $GLOBALS['wp_query'] instanceof WP_Query) {
    $total = (int) $GLOBALS['wp_query']->found_posts;
}

$shop_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/shop/');

$keep = [];
foreach (['s', 'sa_pn', 'filter_brand', 'sa_make', 'sa_model', 'sa_year', 'orderby'] as $key) {
    $val = $key === 'orderby' ? $orderby : (string) ($filters[$key] ?? '');
    if ($val !== '') {
        $keep[$key] = $val;
    }
}
if ($min_price !== '') {
    $keep['min_price'] = $min_price;
}
if ($max_price !== '') {
    $keep['max_price'] = $max_price;
}

$with_filters = static function (string $url) use ($keep): string {
    return $keep === [] ? $url : add_query_arg($keep, $url);
};

$current_term = (function_exists('is_product_category') && is_product_category()) ? get_queried_object() : null;
$current_id = ($current_term instanceof WP_Term) ? (int) $current_term->term_id : 0;

$exclude_ids = [];
$brands_parent = get_term_by('slug', 'brands', 'product_cat');
if ($brands_parent instanceof WP_Term) {
    $exclude_ids[] = (int) $brands_parent->term_id;
}

$cat_parent = 0;
if ($current_term instanceof WP_Term && (int) $current_term->parent > 0 && !in_array((int) $current_term->parent, $exclude_ids, true)) {
    $cat_parent = (int) $current_term->parent;
}

$categories = [];
if (taxonomy_exists('product_cat')) {
    $terms = get_terms([
        'taxonomy'   => 'product_cat',
        'parent'     => $cat_parent,
        'hide_empty' => true,
        'exclude'    => $exclude_ids,
        'orderby'    => 'name',
        'order'      => 'ASC',
        'number'     => 40,
    ]);
    if (!is_wp_error($terms) && is_array($terms)) {
        foreach ($terms as $term) {
            if (!$term instanceof WP_Term) {
                continue;
            }
            if (in_array((int) $term->term_id, $exclude_ids, true)) {
                continue;
            }
            $link = get_term_link($term);
            if (is_wp_error($link)) {
                continue;
            }
            $categories[] = [
                'name'    => $term->name,
                'count'   => (int) $term->count,
                'url'     => $with_filters((string) $link),
                'current' => (int) $term->term_id === $current_id,
            ];
        }
    }
}

$currency = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$';
?>
<aside class="sa-filters<?php echo $active ? ' is-open' : ''; ?>" aria-label="<?php esc_attr_e('Filter parts', 'supreme-autoparts'); ?>">
  <button type="button" class="sa-filters__toggle" data-sa-filters-toggle aria-expanded="<?php echo $active ? 'true' : 'false'; ?>">
    <?php esc_html_e('Filters', 'supreme-autoparts'); ?>
    <?php if ($active) : ?>
      <span class="sa-filters__toggle-dot" aria-hidden="true"></span>
    <?php endif; ?>
  </button>

  <form class="sa-filters__form" method="get" action="<?php echo esc_url($action); ?>" data-sa-filters>
    <?php if ($orderby !== '') : ?>
      <input type="hidden" name="orderby" value="<?php echo esc_attr($orderby); ?>" />
    <?php endif; ?>

    <?php if ($categories !== []) : ?>
      <details class="sa-filter-group" open>
        <summary><?php esc_html_e('Category', 'supreme-autoparts'); ?></summary>
        <ul class="sa-filter-group__list">
          <li>
            <a class="<?php echo $current_id === 0 ? 'is-current' : ''; ?>" href="<?php echo esc_url($with_filters($shop_url)); ?>">
              <?php esc_html_e('All categories', 'supreme-autoparts'); ?>
            </a>
          </li>
          <?php foreach ($categories as $cat) : ?>
            <li>
              <a class="<?php echo $cat['current'] ? 'is-current' : ''; ?>" href="<?php echo esc_url($cat['url']); ?>">
                <?php echo esc_html($cat['name']); ?>
                <span class="sa-filter-group__count"><?php echo esc_html(number_format_i18n($cat['count'])); ?></span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </details>
    <?php endif; ?>

    <?php if ($brands !== []) : ?>
      <details class="sa-filter-group" open>
        <summary><?php esc_html_e('Brand', 'supreme-autoparts'); ?></summary>
        <label class="sa-filters__field">
          <span class="screen-reader-text"><?php esc_html_e('Brand', 'supreme-autoparts'); ?></span>
          <select name="filter_brand">
            <option value=""><?php esc_html_e('All brands', 'supreme-autoparts'); ?></option>
            <?php foreach ($brands as $b) : ?>
              <option value="<?php echo esc_attr($b['slug']); ?>" <?php selected($filters['filter_brand'] ?? '', $b['slug']); ?>>
                <?php echo esc_html($b['name'] . ' (' . number_format_i18n((int) $b['count']) . ')'); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
      </details>
    <?php endif; ?>

    <details class="sa-filter-group" open>
      <summary><?php esc_html_e('Price', 'supreme-autoparts'); ?></summary>
      <div class="sa-filters__price">
        <label class="sa-filters__field">
          <span class="sa-filters__label"><?php echo esc_html(sprintf(/* translators: %s currency symbol */ __('Min (%s)', 'supreme-autoparts'), wp_strip_all_tags($currency))); ?></span>
          <input type="text" inputmode="decimal" name="min_price" value="<?php echo esc_attr($min_price); ?>" placeholder="0" autocomplete="off" />
        </label>
        <label class="sa-filters__field">
          <span class="sa-filters__label"><?php echo esc_html(sprintf(/* translators: %s currency symbol */ __('Max (%s)', 'supreme-autoparts'), wp_strip_all_tags($currency))); ?></span>
          <input type="text" inputmode="decimal" name="max_price" value="<?php echo esc_attr($max_price); ?>" placeholder="<?php esc_attr_e('Any', 'supreme-autoparts'); ?>" autocomplete="off" />
        </label>
      </div>
    </details>

    <details class="sa-filter-group" open>
      <summary><?php esc_html_e('Vehicle', 'supreme-autoparts'); ?></summary>
      <div class="sa-filters__stack">
        <label class="sa-filters__field">
          <span class="sa-filters__label"><?php esc_html_e('Make', 'supreme-autoparts'); ?></span>
          <?php if ($makes !== []) : ?>
            <select name="sa_make">
              <option value=""><?php esc_html_e('All makes', 'supreme-autoparts'); ?></option>
              <?php foreach ($makes as $make) : ?>
                <option value="<?php echo esc_attr($make); ?>" <?php selected($filters['sa_make'] ?? '', $make); ?>>
                  <?php echo esc_html($make); ?>
                </option>
              <?php endforeach; ?>
            </select>
          <?php else : ?>
            <input type="text" name="sa_make" value="<?php echo esc_attr($filters['sa_make'] ?? ''); ?>" placeholder="<?php esc_attr_e('Toyota, Ford…', 'supreme-autoparts'); ?>" autocomplete="off" />
          <?php endif; ?>
        </label>
        <label class="sa-filters__field">
          <span class="sa-filters__label"><?php esc_html_e('Model', 'supreme-autoparts'); ?></span>
          <input type="text" name="sa_model" value="<?php echo esc_attr($filters['sa_model'] ?? ''); ?>" placeholder="<?php esc_attr_e('F-150, Civic…', 'supreme-autoparts'); ?>" autocomplete="off" />
        </label>
        <label class="sa-filters__field">
          <span class="sa-filters__label"><?php esc_html_e('Year', 'supreme-autoparts'); ?></span>
          <input type="text" name="sa_year" value="<?php echo esc_attr($filters['sa_year'] ?? ''); ?>" placeholder="<?php esc_attr_e('2021', 'supreme-autoparts'); ?>" inputmode="numeric" autocomplete="off" />
        </label>
      </div>
    </details>

    <details class="sa-filter-group" open>
      <summary><?php esc_html_e('Part', 'supreme-autoparts'); ?></summary>
      <div class="sa-filters__stack">
        <label class="sa-filters__field">
          <span class="sa-filters__label"><?php esc_html_e('Part number', 'supreme-autoparts'); ?></span>
          <input type="search" name="sa_pn" value="<?php echo esc_attr($filters['sa_pn'] ?? ''); ?>" placeholder="<?php esc_attr_e('SKU / OE / part #', 'supreme-autoparts'); ?>" autocomplete="off" enterkeyhint="search" />
        </label>
        <label class="sa-filters__field">
          <span class="sa-filters__label"><?php esc_html_e('Keyword', 'supreme-autoparts'); ?></span>
          <input type="search" name="s" value="<?php echo esc_attr($filters['s'] ?? ''); ?>" placeholder="<?php esc_attr_e('Name or keyword…', 'supreme-autoparts'); ?>" autocomplete="off" />
        </label>
      </div>
    </details>

    <div class="sa-filters__actions">
      <button type="submit" class="sa-btn sa-filters__submit">
        <?php esc_html_e('Apply filters', 'supreme-autoparts'); ?>
      </button>
      <?php if ($active || $current_id > 0) : ?>
        <a class="sa-filters__clear" href="<?php echo esc_url($clear); ?>">
          <?php esc_html_e('Clear all', 'supreme-autoparts'); ?>
        </a>
      <?php endif; ?>
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
        if ($min_price !== '' || $max_price !== '') {
            $chips[] = sprintf(__('Price: %1$s–%2$s', 'supreme-autoparts'), $min_price !== '' ? $min_price : '0', $max_price !== '' ? $max_price : '…');
        }
        foreach ($chips as $chip) :
            ?>
          <span class="sa-filters__chip"><?php echo esc_html($chip); ?></span>
        <?php endforeach; ?>
        <span class="sa-filters__count">
          <?php
          printf(
              esc_html(_n('%s result', '%s results', $total, 'supreme-autoparts')),
              esc_html(number_format_i18n($total))
          );
          ?>
        </span>
      </div>
    <?php endif; ?>
  </form>
</aside>
