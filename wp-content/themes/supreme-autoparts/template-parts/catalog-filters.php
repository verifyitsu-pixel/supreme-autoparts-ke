<?php
/**
 * Parts-store filters: vehicle (Make → Model → Year), part number, category,
 * brand, context specs, price slider, stock, free shipping.
 * Desktop: left sidebar. Mobile: full-screen sheet with Apply / Clear.
 * Data + counts: supreme-autoparts-core/includes/catalog-facets.php.
 *
 * @package Supreme_Autoparts
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

if (!function_exists('sa_fx_state')) {
    return;
}

$st      = sa_fx_state();
$action  = sa_fx_base_url();
$shop    = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/shop/');
$clear   = function_exists('sa_core_catalog_clear_filters_url') ? sa_core_catalog_clear_filters_url() : $shop;
$stats   = sa_fx_stats();
$chips   = sa_fx_chips();
$cur     = function_exists('get_woocommerce_currency_symbol') ? html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8') : '$';
$free_min = sa_fx_free_ship_min();
$orderby = isset($_GET['orderby']) ? sanitize_key(wp_unslash((string) $_GET['orderby'])) : ''; // phpcs:ignore

// Vehicle lists.
$makes  = sa_fx_counts('make', 'vehicle', 120);
usort($makes, static fn ($a, $b) => strcasecmp($a['label'], $b['label']));
$models = $st['make'] !== '' ? sa_fx_counts('model', 'model', 300, $st['make'] . '/') : [];
usort($models, static fn ($a, $b) => strnatcasecmp($a['label'], $b['label']));
$years  = $st['model'] !== '' ? sa_fx_counts('ymm', 'year', 120, $st['make'] . '/' . $st['model'] . '/') : [];
usort($years, static fn ($a, $b) => (int) $b['label'] <=> (int) $a['label']);

// Categories: children of the current category (or top level on shop).
$current = (function_exists('is_product_category') && is_product_category()) ? get_queried_object() : null;
$brands_root = get_term_by('slug', 'brands', 'product_cat');
$brands_root_id = $brands_root instanceof WP_Term ? (int) $brands_root->term_id : -1;
$parent_id = $current instanceof WP_Term ? (int) $current->term_id : 0;
$child_terms = get_terms(['taxonomy' => 'product_cat', 'parent' => $parent_id, 'hide_empty' => true, 'exclude' => [$brands_root_id], 'number' => 0]);
$cat_counts = [];
foreach (sa_fx_counts('cat', 'cat', 2000) as $c) {
    $cat_counts[(int) $c['value']] = $c['count'];
}
$cats = [];
if (is_array($child_terms)) {
    foreach ($child_terms as $t) {
        $n = $cat_counts[(int) $t->term_id] ?? 0;
        if ($n <= 0 || (int) $t->term_id === $brands_root_id) {
            continue;
        }
        $link = get_term_link($t);
        if (is_wp_error($link)) { continue; }
        $cats[] = ['name' => html_entity_decode($t->name, ENT_QUOTES, 'UTF-8'), 'count' => $n, 'url' => sa_fx_url([], (string) $link)];
    }
    usort($cats, static fn ($a, $b) => $b['count'] <=> $a['count']);
}
$up_url = '';
$up_name = '';
if ($current instanceof WP_Term) {
    if ((int) $current->parent > 0 && (int) $current->parent !== $brands_root_id) {
        $p = get_term((int) $current->parent, 'product_cat');
        if ($p instanceof WP_Term) {
            $l = get_term_link($p);
            $up_url = is_wp_error($l) ? '' : sa_fx_url([], (string) $l);
            $up_name = html_entity_decode($p->name, ENT_QUOTES, 'UTF-8');
        }
    } else {
        $up_url = sa_fx_url([], $shop);
        $up_name = __('All parts', 'supreme-autoparts');
    }
}

$brands = sa_fx_counts('brand', 'brand', 400);
$spec_groups = [];
if ($current instanceof WP_Term || $st['s'] !== '' || $st['make'] !== '') {
    foreach (sa_fx_spec_facets() as $f => $label) {
        $opts = sa_fx_counts($f, $f, 40);
        $selected = $st['specs'][$f] ?? [];
        // Context-aware: show only when it actually splits these results (or is in use).
        if ($selected === [] && (count($opts) < 2 || array_sum(array_column($opts, 'count')) < 3)) {
            continue;
        }
        if ($f === 'wheel_dia' || $f === 'wheel_w' || $f === 'diameter' || $f === 'lift') {
            usort($opts, static fn ($a, $b) => (float) $a['label'] <=> (float) $b['label']);
        }
        $spec_groups[$f] = ['label' => $label, 'opts' => $opts, 'sel' => $selected];
    }
}

$pmin = (int) floor($stats['min']);
$pmax = (int) ceil($stats['max']);
$vmin = $st['min'] !== '' ? (int) $st['min'] : $pmin;
$vmax = $st['max'] !== '' ? (int) $st['max'] : $pmax;
$n_active = count($chips);
?>
<div class="sa-pf-bar" data-sa-pf-bar>
  <button type="button" class="sa-pf-bar__btn" data-sa-pf-open aria-controls="sa-pf" aria-expanded="false">
    <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M3 5h18v2H3zm4 6h10v2H7zm3 6h4v2h-4z"/></svg>
    <?php esc_html_e('Filter', 'supreme-autoparts'); ?>
    <?php if ($n_active > 0) : ?><span class="sa-pf-bar__n"><?php echo (int) $n_active; ?></span><?php endif; ?>
  </button>
</div>

<aside id="sa-pf" class="sa-pf" aria-label="<?php esc_attr_e('Filter parts', 'supreme-autoparts'); ?>" data-sa-pf>
  <form class="sa-pf__form" method="get" action="<?php echo esc_url($action); ?>" data-sa-pf-form>
    <div class="sa-pf__head">
      <strong><?php esc_html_e('Filter parts', 'supreme-autoparts'); ?></strong>
      <button type="button" class="sa-pf__close" data-sa-pf-close aria-label="<?php esc_attr_e('Close filters', 'supreme-autoparts'); ?>">&times;</button>
    </div>

    <div class="sa-pf__body">
      <?php if ($orderby !== '') : ?><input type="hidden" name="orderby" value="<?php echo esc_attr($orderby); ?>" /><?php endif; ?>
      <?php if ($st['s'] !== '') : ?>
        <input type="hidden" name="s" value="<?php echo esc_attr($st['s']); ?>" />
        <input type="hidden" name="post_type" value="product" />
      <?php endif; ?>

      <?php if ($chips !== []) : ?>
        <div class="sa-pf__chips sa-pf__chips--sheet">
          <?php foreach ($chips as $chip) : ?>
            <a class="sa-chip" href="<?php echo esc_url($chip['url']); ?>"><?php echo esc_html($chip['label']); ?> <span aria-hidden="true">&times;</span></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <section class="sa-pf__group sa-pf__group--vehicle">
        <h3 class="sa-pf__title"><?php esc_html_e('Shop by vehicle', 'supreme-autoparts'); ?></h3>
        <?php if ($makes === [] && $st['make'] === '') : ?>
          <p class="sa-pf__hint"><?php esc_html_e('No vehicle fitment listed for these parts.', 'supreme-autoparts'); ?></p>
        <?php else : ?>
        <label class="sa-pf__field">
          <span><?php esc_html_e('Make', 'supreme-autoparts'); ?></span>
          <select name="sa_make" data-sa-pf-vehicle="make">
            <option value=""><?php esc_html_e('Select make', 'supreme-autoparts'); ?></option>
            <?php foreach ($makes as $m) : ?>
              <option value="<?php echo esc_attr($m['value']); ?>" <?php selected($st['make'], $m['value']); ?>><?php echo esc_html($m['label'] . ' (' . number_format_i18n($m['count']) . ')'); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="sa-pf__field">
          <span><?php esc_html_e('Model', 'supreme-autoparts'); ?></span>
          <select name="sa_model" data-sa-pf-vehicle="model" <?php disabled($st['make'] === '' || $models === []); ?>>
            <option value=""><?php echo esc_html($st['make'] === '' ? __('Select make first', 'supreme-autoparts') : __('All models', 'supreme-autoparts')); ?></option>
            <?php foreach ($models as $m) : $slug = substr($m['value'], strlen($st['make']) + 1); ?>
              <option value="<?php echo esc_attr($slug); ?>" <?php selected($st['model'], $slug); ?>><?php echo esc_html($m['label'] . ' (' . number_format_i18n($m['count']) . ')'); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php if ($st['model'] !== '' && $years !== []) : ?>
        <label class="sa-pf__field">
          <span><?php esc_html_e('Year', 'supreme-autoparts'); ?></span>
          <select name="sa_year" data-sa-pf-vehicle="year">
            <option value=""><?php esc_html_e('All years', 'supreme-autoparts'); ?></option>
            <?php foreach ($years as $y) : ?>
              <option value="<?php echo esc_attr($y['label']); ?>" <?php selected($st['year'], $y['label']); ?>><?php echo esc_html($y['label'] . ' (' . number_format_i18n($y['count']) . ')'); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php endif; ?>
        <?php endif; ?>
      </section>

      <section class="sa-pf__group">
        <h3 class="sa-pf__title"><?php esc_html_e('Part number', 'supreme-autoparts'); ?></h3>
        <label class="sa-pf__field sa-pf__field--search">
          <span class="screen-reader-text"><?php esc_html_e('Part number', 'supreme-autoparts'); ?></span>
          <input type="search" name="sa_pn" value="<?php echo esc_attr($st['pn']); ?>" placeholder="<?php esc_attr_e('e.g. 51313-B or OE number', 'supreme-autoparts'); ?>" autocomplete="off" inputmode="text" />
        </label>
      </section>

      <?php if ($cats !== [] || $up_url !== '') : ?>
      <section class="sa-pf__group">
        <h3 class="sa-pf__title"><?php echo esc_html($current instanceof WP_Term ? __('Subcategory', 'supreme-autoparts') : __('Category', 'supreme-autoparts')); ?></h3>
        <ul class="sa-pf__cats">
          <?php if ($up_url !== '') : ?>
            <li><a class="sa-pf__up" href="<?php echo esc_url($up_url); ?>">&larr; <?php echo esc_html($up_name); ?></a></li>
          <?php endif; ?>
          <?php if ($current instanceof WP_Term) : ?>
            <li><span class="is-current"><?php echo esc_html(html_entity_decode($current->name, ENT_QUOTES, 'UTF-8')); ?></span></li>
          <?php endif; ?>
          <?php foreach ($cats as $i => $c) : ?>
            <li class="<?php echo $i >= 12 ? 'sa-pf__more' : ''; ?>"><a href="<?php echo esc_url($c['url']); ?>"><?php echo esc_html($c['name']); ?> <span class="sa-pf__n"><?php echo esc_html(number_format_i18n($c['count'])); ?></span></a></li>
          <?php endforeach; ?>
        </ul>
        <?php if (count($cats) > 12) : ?><button type="button" class="sa-pf__showmore" data-sa-pf-more><?php esc_html_e('Show all', 'supreme-autoparts'); ?></button><?php endif; ?>
      </section>
      <?php endif; ?>

      <section class="sa-pf__group">
        <h3 class="sa-pf__title"><?php esc_html_e('Availability & delivery', 'supreme-autoparts'); ?></h3>
        <label class="sa-pf__check">
          <input type="checkbox" name="instock" value="1" <?php checked($st['instock']); ?> />
          <span><?php esc_html_e('In stock', 'supreme-autoparts'); ?></span>
          <span class="sa-pf__n"><?php echo esc_html(number_format_i18n($stats['instock'])); ?></span>
        </label>
        <?php if ((!function_exists('sa_ship_is_us') || sa_ship_is_us()) && ($stats['freeship'] > 0 || $st['freeship'])) : ?>
        <label class="sa-pf__check">
          <input type="checkbox" name="freeship" value="1" <?php checked($st['freeship']); ?> />
          <span><?php echo esc_html(sprintf(__('Free US shipping (%s+)', 'supreme-autoparts'), $cur . number_format_i18n($free_min))); ?></span>
          <span class="sa-pf__n"><?php echo esc_html(number_format_i18n($stats['freeship'])); ?></span>
        </label>
        <?php endif; ?>
      </section>

      <?php if ($pmax > $pmin) : ?>
      <section class="sa-pf__group">
        <h3 class="sa-pf__title"><?php esc_html_e('Price', 'supreme-autoparts'); ?></h3>
        <div class="sa-pf__range" data-sa-pf-range data-min="<?php echo (int) $pmin; ?>" data-max="<?php echo (int) $pmax; ?>">
          <div class="sa-pf__range-track"><div class="sa-pf__range-fill" data-fill></div>
            <input type="range" min="<?php echo (int) $pmin; ?>" max="<?php echo (int) $pmax; ?>" value="<?php echo (int) $vmin; ?>" data-lo aria-label="<?php esc_attr_e('Minimum price', 'supreme-autoparts'); ?>" />
            <input type="range" min="<?php echo (int) $pmin; ?>" max="<?php echo (int) $pmax; ?>" value="<?php echo (int) $vmax; ?>" data-hi aria-label="<?php esc_attr_e('Maximum price', 'supreme-autoparts'); ?>" />
          </div>
          <div class="sa-pf__range-inputs">
            <label><span><?php echo esc_html($cur); ?></span><input type="number" name="min_price" min="0" value="<?php echo esc_attr($st['min']); ?>" placeholder="<?php echo (int) $pmin; ?>" data-lo-num inputmode="numeric" /></label>
            <span aria-hidden="true">–</span>
            <label><span><?php echo esc_html($cur); ?></span><input type="number" name="max_price" min="0" value="<?php echo esc_attr($st['max']); ?>" placeholder="<?php echo (int) $pmax; ?>" data-hi-num inputmode="numeric" /></label>
          </div>
        </div>
      </section>
      <?php endif; ?>

      <?php foreach ($spec_groups as $f => $g) : ?>
      <section class="sa-pf__group">
        <h3 class="sa-pf__title"><?php echo esc_html($g['label']); ?></h3>
        <input type="hidden" name="f_<?php echo esc_attr($f); ?>" value="<?php echo esc_attr(implode(',', $g['sel'])); ?>" data-sa-pf-multi />
        <div class="sa-pf__opts">
          <?php foreach ($g['opts'] as $o) : ?>
            <label class="sa-pf__check">
              <input type="checkbox" value="<?php echo esc_attr($o['value']); ?>" <?php checked(in_array($o['value'], $g['sel'], true)); ?> data-sa-pf-multi-opt="f_<?php echo esc_attr($f); ?>" />
              <span><?php echo esc_html($o['label']); ?></span>
              <span class="sa-pf__n"><?php echo esc_html(number_format_i18n($o['count'])); ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endforeach; ?>

      <?php if ($brands !== []) : ?>
      <section class="sa-pf__group">
        <h3 class="sa-pf__title"><?php esc_html_e('Brand', 'supreme-autoparts'); ?></h3>
        <input type="hidden" name="filter_brand" value="<?php echo esc_attr(implode(',', $st['brand'])); ?>" data-sa-pf-multi />
        <?php if (count($brands) > 8) : ?>
          <input type="search" class="sa-pf__find" placeholder="<?php esc_attr_e('Find a brand', 'supreme-autoparts'); ?>" data-sa-pf-find aria-label="<?php esc_attr_e('Find a brand', 'supreme-autoparts'); ?>" />
        <?php endif; ?>
        <div class="sa-pf__opts sa-pf__opts--scroll">
          <?php
          usort($brands, static function ($a, $b) use ($st) {
              $sa = in_array($a['value'], $st['brand'], true) ? 0 : 1;
              $sb = in_array($b['value'], $st['brand'], true) ? 0 : 1;
              return $sa <=> $sb ?: $b['count'] <=> $a['count'];
          });
          foreach ($brands as $o) :
              ?>
            <label class="sa-pf__check" data-name="<?php echo esc_attr(strtolower($o['label'])); ?>">
              <input type="checkbox" value="<?php echo esc_attr($o['value']); ?>" <?php checked(in_array($o['value'], $st['brand'], true)); ?> data-sa-pf-multi-opt="filter_brand" />
              <span><?php echo esc_html(html_entity_decode($o['label'], ENT_QUOTES, 'UTF-8')); ?></span>
              <span class="sa-pf__n"><?php echo esc_html(number_format_i18n($o['count'])); ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endif; ?>
    </div>

    <div class="sa-pf__foot">
      <a class="sa-pf__clear" href="<?php echo esc_url($st['s'] !== '' ? add_query_arg(['s' => $st['s'], 'post_type' => 'product'], $clear) : $clear); ?>"><?php esc_html_e('Clear all', 'supreme-autoparts'); ?></a>
      <button type="submit" class="sa-pf__apply"><?php esc_html_e('Apply', 'supreme-autoparts'); ?>
        <?php if ($stats['total'] > 0) : ?><span>· <?php echo esc_html(sprintf(_n('%s part', '%s parts', (int) $GLOBALS['wp_query']->found_posts, 'supreme-autoparts'), number_format_i18n((int) $GLOBALS['wp_query']->found_posts))); ?></span><?php endif; ?>
      </button>
    </div>
  </form>
</aside>
