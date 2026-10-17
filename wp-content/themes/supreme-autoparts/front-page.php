<?php
declare(strict_types=1);
/**
 * Homepage template — storefront landing.
 */
get_header();

$sa_shop_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/shop/');
$sa_threshold = sa_free_shipping_threshold();
$sa_count = function_exists('sa_published_product_count') ? sa_published_product_count() : 0;
?>

<?php
$sa_ed_icons = [
    'parts' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="13" r="8.5"/><circle cx="11" cy="13" r="2.5"/><circle cx="11" cy="8" r=".6"/><circle cx="15.8" cy="11.5" r=".6"/><circle cx="14" cy="17.2" r=".6"/><circle cx="8" cy="17.2" r=".6"/><circle cx="6.2" cy="11.5" r=".6"/><path class="a" d="M14.6 3.3a10.5 10.5 0 0 1 6.6 6.9l-3.1 1a7.3 7.3 0 0 0-4.5-4.8z"/></svg>',
    'service' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 9.5 6.6 6h10.8L19 9.5"/><path d="M3.5 9.5h17v3h-17z"/><circle cx="7" cy="12.5" r="1.6"/><circle cx="17" cy="12.5" r="1.6"/><path class="a" d="M4 15.5h16"/><path d="M3 4v17M21 4v17M1.5 21h21"/></svg>',
    'roadside' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 18v-5.5l2-4.5h5v10"/><path d="M4 12.5h5"/><path d="M9 15.5h12.5V18"/><path class="a" d="M11 15.5 20 7v5"/><path class="a" d="M20 12a1.2 1.2 0 1 1-1.2 1.2"/><circle cx="5.5" cy="18.5" r="1.8"/><circle cx="17.5" cy="18.5" r="1.8"/><path d="M7.3 18.5h8.4M19.3 18.5h2.2"/></svg>',
];
$sa_ed_services = [
    ['icon' => 'parts', 'url' => $sa_shop_url, 'title' => __('Shop parts', 'supreme-autoparts'), 'text' => __('Delivered to any town in Kenya by Fargo Courier', 'supreme-autoparts')],
    ['icon' => 'service', 'url' => home_url('/book-service/'), 'title' => __('Book a service', 'supreme-autoparts'), 'text' => __('Drop off at our garage, off Kangundo Road', 'supreme-autoparts')],
    ['icon' => 'roadside', 'url' => home_url('/roadside/'), 'title' => __('Roadside rescue', 'supreme-autoparts'), 'text' => __('Towing, jump-starts and flat tyres when you need them', 'supreme-autoparts')],
];
$sa_is_ke = (!function_exists('sa_geo_is_kenya') || sa_geo_is_kenya());
if (!$sa_is_ke) {
    $sa_is_us = function_exists('sa_geo_country') && sa_geo_country() === 'US';
    $sa_ed_services[0]['text'] = $sa_is_us ? __('Shipped across the US', 'supreme-autoparts') : __('Shipped worldwide', 'supreme-autoparts');
    $sa_ed_services[1]['text'] = __('We pass your request to our support team, who point you to a service centre', 'supreme-autoparts');
    $sa_ed_services[2] = ['icon' => 'service', 'url' => 'https://wa.me/19174375121', 'title' => __('Fitment help', 'supreme-autoparts'), 'text' => __('Send your year, make, model or VIN and we check the part number', 'supreme-autoparts')];
    if (!$sa_is_us) {
        unset($sa_ed_services[1]); // service-centre routing is only set up for the US
    }
}
?>
<section class="sa-ed-hero" aria-labelledby="sa-hero-title">
  <div class="sa-container sa-ed-hero__in">
    <div class="sa-ed-hero__copy">
      <p class="sa-ed-kicker"><?php esc_html_e('Supreme Autoparts', 'supreme-autoparts'); ?></p>
      <h1 id="sa-hero-title"><?php $sa_is_ke ? esc_html_e('Car parts, servicing and roadside help.', 'supreme-autoparts') : esc_html_e('Car parts, checked to fit your car.', 'supreme-autoparts'); ?></h1>
      <p class="sa-ed-lead"><?php if (!$sa_is_ke) : (function_exists('sa_geo_country') && sa_geo_country() === 'US') ? esc_html_e('Parts for Japanese, American, British and European cars, shipped across the US. Search by part number, or ask us to check fitment for your car.', 'supreme-autoparts') : esc_html_e('Parts for Japanese, American, British and European cars, shipped worldwide. Search by part number, or ask us to check fitment for your car.', 'supreme-autoparts'); else : ?><?php esc_html_e('We send parts for Japanese, American, British and European cars to every county, fix cars at our garage, and come out when you break down.', 'supreme-autoparts'); ?><?php endif; ?></p>
      <form class="sa-ed-search" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>" data-sa-hx-search>
        <label class="screen-reader-text" for="sa-hero-search"><?php esc_html_e('Search products', 'supreme-autoparts'); ?></label>
        <input type="search" id="sa-hero-search" name="s" placeholder="<?php esc_attr_e('Part number, part name or brand', 'supreme-autoparts'); ?>" value="<?php echo esc_attr(get_search_query()); ?>" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="sa-hx-sugg" aria-autocomplete="list" />
        <input type="hidden" name="post_type" value="product" />
        <button type="submit"><?php esc_html_e('Search', 'supreme-autoparts'); ?></button>
        <ul id="sa-hx-sugg" class="sa-hx__sugg" role="listbox" hidden></ul>
      </form>
      <form class="sa-ed-veh" method="get" action="<?php echo esc_url($sa_shop_url); ?>" data-sa-hx-veh>
        <label class="screen-reader-text" for="sa-hx-make"><?php esc_html_e('Make', 'supreme-autoparts'); ?></label>
        <select id="sa-hx-make" name="sa_make"><option value=""><?php esc_html_e('Make', 'supreme-autoparts'); ?></option></select>
        <label class="screen-reader-text" for="sa-hx-model"><?php esc_html_e('Model', 'supreme-autoparts'); ?></label>
        <select id="sa-hx-model" name="sa_model" disabled><option value=""><?php esc_html_e('Model', 'supreme-autoparts'); ?></option></select>
        <label class="screen-reader-text" for="sa-hx-year"><?php esc_html_e('Year', 'supreme-autoparts'); ?></label>
        <select id="sa-hx-year" name="sa_year" disabled><option value=""><?php esc_html_e('Year', 'supreme-autoparts'); ?></option></select>
        <button type="submit"><?php esc_html_e('Find parts for my car', 'supreme-autoparts'); ?></button>
      </form>
    </div>
    <nav class="sa-ed-svc" aria-label="<?php esc_attr_e('Our services', 'supreme-autoparts'); ?>">
      <?php foreach ($sa_ed_services as $s) : ?>
        <a class="sa-ed-svc__item" href="<?php echo esc_url($s['url']); ?>">
          <?php echo $sa_ed_icons[$s['icon']]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
          <span class="sa-ed-svc__t"><strong><?php echo esc_html($s['title']); ?></strong><small><?php echo esc_html($s['text']); ?></small></span>
          <i aria-hidden="true">&rarr;</i>
        </a>
      <?php endforeach; ?>
    </nav>
  </div>
</section>

<?php if ($sa_is_ke) : ?>
<section class="sa-ed-sec sa-ed-rs" aria-labelledby="sa-ed-rs">
  <div class="sa-container sa-ed-rs__in">
    <div class="sa-ed-rs__copy">
      <p class="sa-ed-kicker"><?php esc_html_e('Roadside Rescue', 'supreme-autoparts'); ?></p>
      <h2 id="sa-ed-rs"><?php esc_html_e('Stuck on the road? We come to you.', 'supreme-autoparts'); ?></h2>
      <p class="sa-ed-rs__lead"><?php esc_html_e('Towing, jump-starts, flat tyres, fuel delivery and lockouts. Tell us where you are and what happened, and we send help.', 'supreme-autoparts'); ?></p>
      <div class="sa-ed-rs__cta">
        <a class="sa-ed-btn" href="<?php echo esc_url((function_exists('sa_rs_url') ? sa_rs_url('request') : home_url('/roadside/'))); ?>"><?php esc_html_e('Request help now', 'supreme-autoparts'); ?></a>
        <a class="sa-ed-btn sa-ed-btn--ghost" href="tel:+254714498451"><?php esc_html_e('Call +254 714 498 451', 'supreme-autoparts'); ?></a>
        <a class="sa-ed-rs__wa" href="https://wa.me/254714498451" rel="noopener"><?php esc_html_e('WhatsApp', 'supreme-autoparts'); ?></a>
      </div>
    </div>
    <ol class="sa-ed-rs__steps">
      <li><b>1</b><div><strong><?php esc_html_e('Share your location', 'supreme-autoparts'); ?></strong><span><?php esc_html_e('Use your phone’s GPS or drop a pin on the map.', 'supreme-autoparts'); ?></span></div></li>
      <li><b>2</b><div><strong><?php esc_html_e('Tell us the problem', 'supreme-autoparts'); ?></strong><span><?php esc_html_e('Flat battery, flat tyre, no fuel, locked out, or the car needs towing.', 'supreme-autoparts'); ?></span></div></li>
      <li><b>3</b><div><strong><?php esc_html_e('We send help', 'supreme-autoparts'); ?></strong><span><?php esc_html_e('You get a request number and can follow progress on your phone.', 'supreme-autoparts'); ?></span></div></li>
    </ol>
  </div>
</section>
<?php endif; ?>

<section class="sa-ed-sec" aria-labelledby="sa-ed-cat">
  <div class="sa-container">
    <div class="sa-ed-head"><h2 id="sa-ed-cat"><?php esc_html_e('Shop by category', 'supreme-autoparts'); ?></h2><a href="<?php echo esc_url(home_url('/categories/')); ?>"><?php esc_html_e('All categories', 'supreme-autoparts'); ?> &rarr;</a></div>
    <div class="sa-ed-cats">
      <?php
      $sa_ed_cats = ['brakes' => __('Brakes', 'supreme-autoparts'), 'suspension' => __('Suspension', 'supreme-autoparts'), 'engine' => __('Engine', 'supreme-autoparts'), 'air-intake' => __('Air intake', 'supreme-autoparts'), 'lighting' => __('Lighting', 'supreme-autoparts'), 'wheels' => __('Wheels', 'supreme-autoparts')];
      foreach ($sa_ed_cats as $slug => $label) : ?>
        <a class="sa-ed-cat" href="<?php echo esc_url(sa_term_link('product_cat', $slug)); ?>">
          <span class="sa-ed-im"><img src="<?php echo esc_url(SA_THEME_URI . '/assets/images/home/ed/' . $slug . '.jpg'); ?>" alt="" width="640" height="640" loading="lazy" decoding="async"></span>
          <span class="sa-ed-cat__n"><?php echo esc_html($label); ?></span>
        </a>
      <?php endforeach; ?>
      <?php
      // Show every other department (root category with sub-categories) after the curated
      // six, so all categories are visible here without opening All categories.
      if (function_exists('sa_cats_data')) :
          $sa_ed_logo = SA_THEME_URI . '/assets/logo-light.png';
          foreach ((sa_cats_data()['dept'] ?? []) as $sa_ed_dc) :
              if (empty($sa_ed_dc['s']) || isset($sa_ed_cats[$sa_ed_dc['s']]) || (int) ($sa_ed_dc['c'] ?? 0) < 1) {
                  continue;
              }
              $sa_ed_dimg = (string) ($sa_ed_dc['img'] ?? '');
              ?>
        <a class="sa-ed-cat" href="<?php echo esc_url((string) $sa_ed_dc['u']); ?>">
          <span class="sa-ed-im"><img src="<?php echo esc_url($sa_ed_dimg !== '' ? $sa_ed_dimg : $sa_ed_logo); ?>" alt="" width="640" height="640" loading="lazy" decoding="async"<?php echo $sa_ed_dimg !== '' ? '' : ' class="is-fallback"'; ?> onerror="this.onerror=null;this.src='<?php echo esc_url($sa_ed_logo); ?>';this.classList.add('is-fallback')"></span>
          <span class="sa-ed-cat__n"><?php echo esc_html((string) $sa_ed_dc['n']); ?></span>
        </a>
      <?php
          endforeach;
      endif; ?>
    </div>
  </div>
</section>

<?php
$sa_ed_slugs = [
    'grimmspeed-13-25-subaru-brz-toyota-86-grey-4-piston-brake-caliper-kit-for-326mm-rotor',
    'fox-3-0-factory-race-14in-internal-bypass-remote-shock-dsc-adjuster',
    'mishimoto-12-15-honda-civic-si-performance-radiator',
    'k-n-universal-air-filter-4in-flg-8-1-2in-b-5in-t-8in-h-gen-4',
    'grimmspeed-02-14-subaru-wrx-5x100-25mm-wheel-spacer-pair',
    'icon-2017-ford-f-250-f-350-super-duty-4wd-0-2-5in-front-2-5-series-shocks-vs-rr-cdev-pair',
    'corsa-air-intake-maxflow-5-oiled-closed-box-18-20-ford-mustang-gt-5-0l-v8',
];
$sa_ed_products = [];
foreach ($sa_ed_slugs as $sl) {
    $pp = get_page_by_path($sl, OBJECT, 'product');
    $pr = $pp ? wc_get_product($pp->ID) : null;
    if ($pr && $pr->is_visible() && str_starts_with((string) $pr->get_meta('_sa_shopify_image_src', true), 'https://cdn.shopify.com/')) {
        $sa_ed_products[$pr->get_id()] = $pr;
    }
}
if (count($sa_ed_products) < 8 && function_exists('sa_get_homepage_latest_products')) {
    foreach (sa_get_homepage_latest_products(12) as $pr) {
        if (count($sa_ed_products) >= 8) {
            break;
        }
        if (str_starts_with((string) $pr->get_meta('_sa_shopify_image_src', true), 'https://cdn.shopify.com/')) {
            $sa_ed_products[$pr->get_id()] = $pr;
        }
    }
}
if ($sa_ed_products) : ?>
<section class="sa-ed-sec" aria-labelledby="sa-ed-pop">
  <div class="sa-container">
    <div class="sa-ed-head"><h2 id="sa-ed-pop"><?php esc_html_e('Popular parts', 'supreme-autoparts'); ?></h2><a href="<?php echo esc_url($sa_shop_url); ?>"><?php esc_html_e('View all parts', 'supreme-autoparts'); ?> &rarr;</a></div>
    <div class="sa-ed-prods">
      <?php foreach (array_slice($sa_ed_products, 0, 8) as $pr) :
          $img = (string) $pr->get_meta('_sa_shopify_image_src', true);
          $brand = strtok($pr->get_name(), ' ');
          $price = $pr->get_price() !== '' && (float) $pr->get_price() > 0 ? $pr->get_price_html() : esc_html__('Quote on request', 'supreme-autoparts');
          ?>
        <a class="sa-ed-prod" href="<?php echo esc_url($pr->get_permalink()); ?>">
          <span class="sa-ed-im"><?php if ($img) : ?><img src="<?php echo esc_url($img); ?>" alt="<?php echo esc_attr($pr->get_name()); ?>" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='<?php echo esc_url(SA_THEME_URI . '/assets/logo-light.png'); ?>';this.classList.add('is-fallback')"><?php endif; ?></span>
          <small><?php echo esc_html($brand); ?></small>
          <span class="sa-ed-prod__n"><?php echo esc_html($pr->get_name()); ?></span>
          <span class="sa-ed-prod__p"><?php echo wp_kses_post($price); ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="sa-ed-sec sa-ed-why" aria-labelledby="sa-ed-why">
  <div class="sa-container">
    <div class="sa-ed-head"><h2 id="sa-ed-why"><?php esc_html_e('Why Supreme Autoparts', 'supreme-autoparts'); ?></h2></div>
    <div class="sa-ed-facts">
      <div><h3><?php echo esc_html($sa_count > 1000 ? sprintf(__('%s parts listed', 'supreme-autoparts'), number_format_i18n(floor($sa_count / 1000) * 1000) . '+') : __('Thousands of parts', 'supreme-autoparts')); ?></h3><p><?php esc_html_e('Search by part number, brand or your car’s make, model and year.', 'supreme-autoparts'); ?></p></div>
      <?php if ($sa_is_ke) : ?>
      <div><h3><?php esc_html_e('Delivery across Kenya', 'supreme-autoparts'); ?></h3><p><?php esc_html_e('We ship with Fargo Courier and you can track the parcel on our site.', 'supreme-autoparts'); ?></p></div>
      <div><h3><?php esc_html_e('Store pickup', 'supreme-autoparts'); ?></h3><p><?php esc_html_e('Collect your order from our shop at Midax Plaza, Kangundo Road.', 'supreme-autoparts'); ?></p></div>
      <div><h3><?php esc_html_e('M-Pesa or card', 'supreme-autoparts'); ?></h3><p><?php esc_html_e('Pay through Pesapal when you check out.', 'supreme-autoparts'); ?></p></div>
      <?php else : ?>
      <?php $sa_us = function_exists('sa_geo_country') && sa_geo_country() === 'US'; ?>
      <div><h3><?php $sa_us ? esc_html_e('Shipping across the US', 'supreme-autoparts') : esc_html_e('International shipping', 'supreme-autoparts'); ?></h3><p><?php $sa_us ? printf(esc_html__('Standard and priority options at checkout, free on orders %s and up.', 'supreme-autoparts'), esc_html(sa_free_shipping_threshold())) : esc_html_e('Shipping to your country is priced at checkout before you pay.', 'supreme-autoparts'); ?></p></div>
      <div><h3><?php esc_html_e('Fitment check', 'supreme-autoparts'); ?></h3><p><?php esc_html_e('Send your year, make, model or VIN on WhatsApp and we confirm the part number.', 'supreme-autoparts'); ?></p></div>
      <div><h3><?php esc_html_e('Pay by card', 'supreme-autoparts'); ?></h3><p><?php esc_html_e('Secure card checkout. No account needed.', 'supreme-autoparts'); ?></p></div>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php
get_footer();
