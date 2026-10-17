<?php
declare(strict_types=1);
/**
 * /categories/ — every product category, Editorial style.
 * Departments (categories that have sub-categories) get a photo tile with their
 * sub-categories listed beneath; single categories and brands are A–Z lists.
 * Empty categories are hidden. Geo-neutral: header/footer handle KE/US.
 */

function sa_cats_page_url(): string
{
    return home_url('/categories/');
}

function sa_cats_is_request(): bool
{
    $path = trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
    $home = trim((string) parse_url(home_url('/'), PHP_URL_PATH), '/');
    if ($home !== '' && str_starts_with($path, $home)) {
        $path = trim(substr($path, strlen($home)), '/');
    }
    return $path === 'categories' || $path === 'all-categories';
}

/** Representative catalog photo (Shopify CDN) for a category, or ''. */
function sa_cats_photo(int $term_id): string
{
    $q = get_posts([
        'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids',
        'no_found_rows' => true, 'orderby' => 'date', 'order' => 'DESC',
        'tax_query' => [['taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $term_id, 'include_children' => true]],
        'meta_query' => [['key' => '_sa_shopify_image_src', 'value' => 'https://cdn.shopify.com/', 'compare' => 'LIKE']],
    ]);
    return $q ? (string) get_post_meta((int) $q[0], '_sa_shopify_image_src', true) : '';
}

function sa_cats_data(): array
{
    $key = 'sa_cats_page_v3';
    $c = get_transient($key);
    if (is_array($c)) {
        return $c;
    }
    $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => true, 'orderby' => 'name', 'order' => 'ASC']);
    if (is_wp_error($terms)) {
        return ['dept' => [], 'more' => [], 'brands' => []];
    }
    $by_parent = [];
    $by_slug = [];
    foreach ($terms as $t) {
        $by_parent[(int) $t->parent][] = $t;
        $by_slug[$t->slug] = $t;
    }
    $skip = ['uncategorized', 'brands', 'collections'];
    $roots = $by_parent[0] ?? [];
    if (isset($by_slug['collections'])) {
        $roots = array_merge($roots, $by_parent[(int) $by_slug['collections']->term_id] ?? []);
    }
    $ed = ['brakes', 'suspension', 'engine', 'air-intake', 'lighting', 'wheels'];
    $row = static fn($t): array => ['n' => html_entity_decode($t->name, ENT_QUOTES), 'u' => (string) get_term_link($t), 'c' => (int) $t->count];
    $dept = [];
    $more = [];
    foreach ($roots as $t) {
        if (in_array($t->slug, $skip, true) || (int) $t->count < 1) {
            continue;
        }
        $kids = $by_parent[(int) $t->term_id] ?? [];
        if ($kids) {
            usort($kids, static fn($a, $b) => (int) $b->count <=> (int) $a->count);
            $d = $row($t);
            $d['s'] = $t->slug;
            $sa_tile = function_exists('sa_category_tile_image') ? sa_category_tile_image($t->slug) : '';
            $d['img'] = $sa_tile !== '' ? $sa_tile : (in_array($t->slug, $ed, true) ? SA_THEME_URI . '/assets/images/home/ed/' . $t->slug . '.jpg' : sa_cats_photo((int) $t->term_id));
            $d['kids'] = array_map($row, $kids);
            $dept[] = $d;
        } else {
            $more[] = $row($t);
        }
    }
    usort($dept, static fn($a, $b) => $b['c'] <=> $a['c']);
    usort($more, static fn($a, $b) => strcasecmp($a['n'], $b['n']));
    $brands = [];
    if (isset($by_slug['brands'])) {
        foreach ($by_parent[(int) $by_slug['brands']->term_id] ?? [] as $t) {
            $brands[] = $row($t);
        }
        usort($brands, static fn($a, $b) => strcasecmp($a['n'], $b['n']));
    }
    $c = ['dept' => $dept, 'more' => $more, 'brands' => $brands, 'brands_url' => home_url('/brands/')];
    set_transient($key, $c, 6 * HOUR_IN_SECONDS);
    return $c;
}

add_action('edited_product_cat', static fn() => delete_transient('sa_cats_page_v3'));
add_action('created_product_cat', static fn() => delete_transient('sa_cats_page_v3'));
add_action('delete_product_cat', static fn() => delete_transient('sa_cats_page_v3'));

add_filter('pre_get_document_title', static function ($t) {
    return sa_cats_is_request() ? __('All categories', 'supreme-autoparts') . ' | ' . get_bloginfo('name') : $t;
}, 99);

add_filter('body_class', static function (array $c): array {
    if (sa_cats_is_request()) {
        $c = array_values(array_diff($c, ['error404']));
        $c[] = 'sa-cats-page';
    }
    return $c;
});

add_action('template_redirect', static function (): void {
    if (!sa_cats_is_request() || is_admin()) {
        return;
    }
    global $wp_query;
    $wp_query->is_404 = false;
    $wp_query->is_page = true;
    status_header(200);
    nocache_headers();
    header('Cache-Control: public, max-age=0, s-maxage=300');
    $d = sa_cats_data();
    $total = count($d['dept']) + count($d['more']);
    $logo = SA_THEME_URI . '/assets/logo-light.png';
    get_header();
    ?>
<main id="main" class="sa-main">
<section class="sa-ed-sec sa-cx" aria-labelledby="sa-cx-h">
  <div class="sa-container">
    <nav class="sa-cx__crumb" aria-label="<?php esc_attr_e('Breadcrumb', 'supreme-autoparts'); ?>"><a href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Home', 'supreme-autoparts'); ?></a> <span aria-hidden="true">/</span> <?php esc_html_e('All categories', 'supreme-autoparts'); ?></nav>
    <div class="sa-cx__top">
      <div>
        <h1 id="sa-cx-h"><?php esc_html_e('All categories', 'supreme-autoparts'); ?></h1>
        <p class="sa-cx__lead"><?php printf(esc_html__('%1$s categories and %2$s brands. Pick one to see its parts, or search below.', 'supreme-autoparts'), esc_html(number_format_i18n($total)), esc_html(number_format_i18n(count($d['brands'])))); ?></p>
      </div>
      <div class="sa-cx__filter">
        <label class="screen-reader-text" for="sa-cx-q"><?php esc_html_e('Filter categories', 'supreme-autoparts'); ?></label>
        <input type="search" id="sa-cx-q" placeholder="<?php esc_attr_e('Find a category or brand', 'supreme-autoparts'); ?>" autocomplete="off" data-sa-cx-q>
      </div>
    </div>
    <p class="sa-cx__none" data-sa-cx-none hidden><?php esc_html_e('No category matches that. Try the', 'supreme-autoparts'); ?> <a href="<?php echo esc_url(function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/shop/')); ?>"><?php esc_html_e('full parts search', 'supreme-autoparts'); ?></a>.</p>

    <div class="sa-cx__grid" data-sa-cx-group>
      <?php foreach ($d['dept'] as $c) : ?>
      <article class="sa-cx__dept" data-sa-cx-item data-k="<?php echo esc_attr(strtolower($c['n'] . ' ' . implode(' ', array_column($c['kids'], 'n')))); ?>">
        <a class="sa-ed-cat" href="<?php echo esc_url($c['u']); ?>">
          <span class="sa-ed-im"><img src="<?php echo esc_url($c['img'] ?: $logo); ?>" alt="" width="640" height="640" loading="lazy" decoding="async"<?php echo $c['img'] ? '' : ' class="is-fallback"'; ?> onerror="this.onerror=null;this.src='<?php echo esc_url($logo); ?>';this.classList.add('is-fallback')"></span>
          <span class="sa-cx__name"><span class="sa-ed-cat__n"><?php echo esc_html($c['n']); ?></span><small><?php echo esc_html(sprintf(_n('%s part', '%s parts', $c['c'], 'supreme-autoparts'), number_format_i18n($c['c']))); ?></small></span>
        </a>
        <ul class="sa-cx__subs">
          <?php foreach ($c['kids'] as $k) : ?>
          <li data-sa-cx-sub data-k="<?php echo esc_attr(strtolower($k['n'])); ?>"><a href="<?php echo esc_url($k['u']); ?>"><?php echo esc_html($k['n']); ?></a><span><?php echo esc_html(number_format_i18n($k['c'])); ?></span></li>
          <?php endforeach; ?>
        </ul>
        <a class="sa-cx__all" href="<?php echo esc_url($c['u']); ?>"><?php printf(esc_html__('Shop all %s', 'supreme-autoparts'), esc_html($c['n'])); ?> &rarr;</a>
      </article>
      <?php endforeach; ?>
    </div>

    <?php if ($d['more']) : ?>
    <div class="sa-cx__block" data-sa-cx-group>
      <div class="sa-ed-head"><h2><?php esc_html_e('More categories', 'supreme-autoparts'); ?></h2></div>
      <ul class="sa-cx__list">
        <?php foreach ($d['more'] as $k) : ?>
        <li data-sa-cx-item data-k="<?php echo esc_attr(strtolower($k['n'])); ?>"><a href="<?php echo esc_url($k['u']); ?>"><?php echo esc_html($k['n']); ?></a><span><?php echo esc_html(number_format_i18n($k['c'])); ?></span></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <?php if ($d['brands']) : ?>
    <div class="sa-cx__block" data-sa-cx-group>
      <div class="sa-ed-head"><h2><?php esc_html_e('Shop by brand', 'supreme-autoparts'); ?></h2><?php if ($d['brands_url']) : ?><a href="<?php echo esc_url($d['brands_url']); ?>"><?php esc_html_e('All brands', 'supreme-autoparts'); ?> &rarr;</a><?php endif; ?></div>
      <ul class="sa-cx__list sa-cx__list--brands">
        <?php foreach ($d['brands'] as $k) : ?>
        <li data-sa-cx-item data-k="<?php echo esc_attr(strtolower($k['n'])); ?>"><a href="<?php echo esc_url($k['u']); ?>"><?php echo esc_html($k['n']); ?></a><span><?php echo esc_html(number_format_i18n($k['c'])); ?></span></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </div>
</section>
</main>
<script>
(function(){var q=document.querySelector('[data-sa-cx-q]');if(!q)return;var none=document.querySelector('[data-sa-cx-none]');
q.addEventListener('input',function(){var v=q.value.trim().toLowerCase(),any=false;
document.querySelectorAll('[data-sa-cx-group]').forEach(function(g){var gAny=false;
g.querySelectorAll('[data-sa-cx-item]').forEach(function(it){var hit=!v||it.getAttribute('data-k').indexOf(v)>-1;it.hidden=!hit;if(hit)gAny=true;
it.querySelectorAll('[data-sa-cx-sub]').forEach(function(s){s.classList.toggle('is-hit',!!v&&s.getAttribute('data-k').indexOf(v)>-1);});});
g.hidden=!gAny;if(gAny)any=true;});none.hidden=any;});})();
</script>
    <?php
    get_footer();
    exit;
}, 1);
