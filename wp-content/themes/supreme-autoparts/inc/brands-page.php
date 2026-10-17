<?php
declare(strict_types=1);
/**
 * /brands/ — every brand, Editorial style.
 * Logo/photo tile grid (same classes as the home "Shop by brand" section) with
 * a filter box, modelled on inc/categories-page.php. Each tile links to that
 * brand's catalog page on this site. Empty brands are hidden.
 * Geo-neutral: header/footer handle KE/US.
 */

function sa_brands_page_url(): string
{
    return home_url('/brands/');
}

function sa_brands_is_request(): bool
{
    $path = trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
    $home = trim((string) parse_url(home_url('/'), PHP_URL_PATH), '/');
    if ($home !== '' && str_starts_with($path, $home)) {
        $path = trim(substr($path, strlen($home)), '/');
    }
    return $path === 'brands';
}

function sa_brands_data(): array
{
    $key = 'sa_brands_page_v1';
    $c = get_transient($key);
    if (is_array($c)) {
        return $c;
    }
    $parent = get_term_by('slug', 'brands', 'product_cat');
    if (!$parent || is_wp_error($parent)) {
        return [];
    }
    $terms = get_terms([
        'taxonomy'   => 'product_cat',
        'parent'     => (int) $parent->term_id,
        'hide_empty' => true,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);
    if (is_wp_error($terms)) {
        return [];
    }
    $logos = function_exists('sa_brand_logo_map') ? sa_brand_logo_map() : [];
    $shop = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/shop/');
    $rows = [];
    foreach ($terms as $t) {
        $u = get_term_link($t);
        $img = $logos[$t->slug] ?? (function_exists('sa_cats_photo') ? sa_cats_photo((int) $t->term_id) : '');
        $rows[] = [
            'n'   => (string) $t->name,
            'u'   => (!is_wp_error($u) && is_string($u)) ? $u : $shop,
            'c'   => (int) $t->count,
            'img' => (string) $img,
        ];
    }
    set_transient($key, $rows, 6 * HOUR_IN_SECONDS);
    return $rows;
}

add_action('edited_product_cat', static fn() => delete_transient('sa_brands_page_v1'));
add_action('created_product_cat', static fn() => delete_transient('sa_brands_page_v1'));
add_action('delete_product_cat', static fn() => delete_transient('sa_brands_page_v1'));

add_filter('pre_get_document_title', static function ($t) {
    return sa_brands_is_request() ? __('All brands', 'supreme-autoparts') . ' | ' . get_bloginfo('name') : $t;
}, 99);

add_filter('body_class', static function (array $c): array {
    if (sa_brands_is_request()) {
        $c = array_values(array_diff($c, ['error404']));
        $c[] = 'sa-brands-page';
    }
    return $c;
});

add_action('template_redirect', static function (): void {
    if (!sa_brands_is_request() || is_admin()) {
        return;
    }
    global $wp_query;
    $wp_query->is_404 = false;
    $wp_query->is_page = true;
    status_header(200);
    nocache_headers();
    header('Cache-Control: public, max-age=0, s-maxage=300');
    $brands = sa_brands_data();
    $logo = SA_THEME_URI . '/assets/logo-light.png';
    get_header();
    ?>
<main id="main" class="sa-main">
<section class="sa-ed-sec sa-cx" aria-labelledby="sa-cx-h">
  <div class="sa-container">
    <nav class="sa-cx__crumb" aria-label="<?php esc_attr_e('Breadcrumb', 'supreme-autoparts'); ?>"><a href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Home', 'supreme-autoparts'); ?></a> <span aria-hidden="true">/</span> <?php esc_html_e('All brands', 'supreme-autoparts'); ?></nav>
    <div class="sa-cx__top">
      <div>
        <h1 id="sa-cx-h"><?php esc_html_e('All brands', 'supreme-autoparts'); ?></h1>
        <p class="sa-cx__lead"><?php printf(esc_html__('%s brands. Pick one to see its parts, or search below.', 'supreme-autoparts'), esc_html(number_format_i18n(count($brands)))); ?></p>
      </div>
      <div class="sa-cx__filter">
        <label class="screen-reader-text" for="sa-cx-q"><?php esc_html_e('Filter brands', 'supreme-autoparts'); ?></label>
        <input type="search" id="sa-cx-q" placeholder="<?php esc_attr_e('Find a brand', 'supreme-autoparts'); ?>" autocomplete="off" data-sa-cx-q>
      </div>
    </div>
    <p class="sa-cx__none" data-sa-cx-none hidden><?php esc_html_e('No brand matches that. Try the', 'supreme-autoparts'); ?> <a href="<?php echo esc_url(function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/shop/')); ?>"><?php esc_html_e('full parts search', 'supreme-autoparts'); ?></a>.</p>

    <div class="sa-ed-brandgrid" data-sa-cx-group>
      <?php foreach ($brands as $b) : ?>
      <a class="sa-ed-brand" href="<?php echo esc_url($b['u']); ?>" data-sa-cx-item data-k="<?php echo esc_attr(strtolower($b['n'])); ?>">
        <span class="sa-ed-brand__im"><img src="<?php echo esc_url($b['img'] ?: $logo); ?>" alt="<?php echo esc_attr($b['n']); ?>" width="640" height="640" loading="lazy" decoding="async"<?php echo $b['img'] ? '' : ' class="is-fallback"'; ?> onerror="this.onerror=null;this.src='<?php echo esc_url($logo); ?>';this.classList.add('is-fallback')"></span>
        <span class="sa-ed-brand__n"><?php echo esc_html($b['n']); ?> <small><?php echo esc_html(sprintf(_n('%s part', '%s parts', $b['c'], 'supreme-autoparts'), number_format_i18n($b['c']))); ?></small></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
</main>
<script>
(function(){var q=document.querySelector('[data-sa-cx-q]');if(!q)return;var none=document.querySelector('[data-sa-cx-none]');
q.addEventListener('input',function(){var v=q.value.trim().toLowerCase(),any=false;
document.querySelectorAll('[data-sa-cx-group]').forEach(function(g){var gAny=false;
g.querySelectorAll('[data-sa-cx-item]').forEach(function(it){var hit=!v||it.getAttribute('data-k').indexOf(v)>-1;it.hidden=!hit;if(hit)gAny=true;});
g.hidden=!gAny;if(gAny)any=true;});none.hidden=any;});})();
</script>
    <?php
    get_footer();
    exit;
}, 1);
