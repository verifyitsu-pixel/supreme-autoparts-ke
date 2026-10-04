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

<section class="sa-hero" aria-labelledby="sa-hero-title">
  <div class="sa-container sa-hero__inner">
    <div class="sa-hero__copy">
      <h1 id="sa-hero-title"><?php esc_html_e('Shop', 'supreme-autoparts'); ?></h1>
      <p class="sa-hero__lead">
        <?php esc_html_e('Search by part number, brand, or keyword.', 'supreme-autoparts'); ?>
      </p>

      <form class="sa-hero__search" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>">
        <label class="screen-reader-text" for="sa-hero-search"><?php esc_html_e('Search products', 'supreme-autoparts'); ?></label>
        <input
          type="search"
          id="sa-hero-search"
          name="s"
          placeholder="<?php esc_attr_e('Part number, brand, make, or keyword…', 'supreme-autoparts'); ?>"
          value="<?php echo esc_attr(get_search_query()); ?>"
          autocomplete="off"
        />
        <input type="hidden" name="post_type" value="product" />
        <button type="submit" class="sa-btn sa-hero__search-btn">
          <?php echo sa_category_icon_svg('search'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
          <span><?php esc_html_e('Search', 'supreme-autoparts'); ?></span>
        </button>
      </form>

      <p class="sa-hero__meta">
        <a href="<?php echo esc_url($sa_shop_url); ?>"><?php esc_html_e('Shop', 'supreme-autoparts'); ?></a>
      </p>
    </div>
  </div>
</section>

<section class="sa-trust" aria-label="<?php esc_attr_e('Why shop with us', 'supreme-autoparts'); ?>">
  <div class="sa-container">
    <ul class="sa-trust__list">
      <li class="sa-trust__item">
        <span class="sa-trust__icon" aria-hidden="true">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h13v10H3z"/><path d="M16 10h3l2 3v4h-5V10z"/><circle cx="7.5" cy="18.5" r="1.5"/><circle cx="17.5" cy="18.5" r="1.5"/></svg>
        </span>
        <div>
          <strong><?php esc_html_e('Free US shipping', 'supreme-autoparts'); ?></strong>
          <span>
            <?php
            printf(
                /* translators: %s: free shipping threshold */
                esc_html__('Orders over %s* ship free in the continental US', 'supreme-autoparts'),
                esc_html($sa_threshold)
            );
            ?>
          </span>
        </div>
      </li>
      <li class="sa-trust__item">
        <span class="sa-trust__icon" aria-hidden="true">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M4 10h16v8H4z"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><path d="M12 14v2"/></svg>
        </span>
        <div>
          <strong><?php esc_html_e('USD checkout', 'supreme-autoparts'); ?></strong>
          <span><?php esc_html_e('Secure pay via Whop · guest checkout welcome', 'supreme-autoparts'); ?></span>
        </div>
      </li>
      <li class="sa-trust__item">
        <span class="sa-trust__icon" aria-hidden="true">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9"/><path d="M3 4v5h5"/><path d="M12 7v5l3 2"/></svg>
        </span>
        <div>
          <strong><?php esc_html_e('Returns', 'supreme-autoparts'); ?></strong>
          <span><?php esc_html_e('Clear return window — see our returns policy', 'supreme-autoparts'); ?></span>
        </div>
      </li>
      <li class="sa-trust__item">
        <span class="sa-trust__icon" aria-hidden="true">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8"/><path d="M12 8v4l2.5 2.5"/><path d="M9 4.5 12 3l3 1.5"/></svg>
        </span>
        <div>
          <strong><?php esc_html_e('Japan · US · UK · EV · bike', 'supreme-autoparts'); ?></strong>
          <span><?php esc_html_e('Cars from Japan, the US & UK, plus EVs and motorcycle parts.', 'supreme-autoparts'); ?></span>
        </div>
      </li>
    </ul>
  </div>
</section>

<section class="sa-section sa-section--tight" aria-labelledby="sa-types-title">
  <div class="sa-container">
    <div class="sa-section__head">
      <h2 id="sa-types-title" class="sa-section__title"><?php esc_html_e('Shop by category', 'supreme-autoparts'); ?></h2>
      <a class="sa-section__link" href="<?php echo esc_url($sa_shop_url); ?>"><?php esc_html_e('View all', 'supreme-autoparts'); ?></a>
    </div>
    <div class="sa-type-grid">
      <?php foreach (sa_product_types() as $type) :
          $img = sa_category_image_url($type['slug']);
          ?>
        <a class="sa-type-tile<?php echo $img !== '' ? ' sa-type-tile--photo' : ''; ?>" href="<?php echo esc_url(sa_term_link('product_cat', $type['slug'])); ?>">
          <?php if ($img !== '') : ?>
            <span class="sa-type-tile__media">
              <img src="<?php echo esc_url($img); ?>" alt="" class="sa-type-tile__img" loading="lazy" decoding="async" width="400" height="300" />
              <span class="sa-type-tile__name"><?php echo esc_html($type['title']); ?></span>
            </span>
          <?php else : ?>
            <span class="sa-type-tile__icon" aria-hidden="true"><?php echo sa_category_icon_svg($type['icon']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
            <span class="sa-type-tile__name"><?php echo esc_html($type['title']); ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php
/* Featured Parts — mega-store round-robin across departments (real photos). */
$sa_latest = function_exists('sa_get_homepage_latest_products')
    ? sa_get_homepage_latest_products(8)
    : [];
if (!empty($sa_latest)) :
    ?>
<section class="sa-section sa-latest-parts" aria-labelledby="sa-latest-parts-title">
  <div class="sa-container">
    <div class="sa-section__head">
      <h2 id="sa-latest-parts-title" class="sa-section__title"><?php esc_html_e('Featured parts', 'supreme-autoparts'); ?></h2>
      <a class="sa-section__link" href="<?php echo esc_url($sa_shop_url); ?>"><?php esc_html_e('Shop catalog', 'supreme-autoparts'); ?></a>
    </div>
    <ul class="products sa-products columns-4">
      <?php foreach ($sa_latest as $product) :
          $post_object = get_post($product->get_id());
          if (!$post_object) {
              continue;
          }
          setup_postdata($GLOBALS['post'] = $post_object); // phpcs:ignore
          wc_get_template_part('content', 'product');
      endforeach;
      wp_reset_postdata();
      ?>
    </ul>
  </div>
</section>
    <?php
endif;
?>

<section class="sa-section sa-how" aria-labelledby="sa-how-title">
  <div class="sa-container">
    <div class="sa-section__head">
      <h2 id="sa-how-title" class="sa-section__title"><?php esc_html_e('How ordering works', 'supreme-autoparts'); ?></h2>
      <a class="sa-section__link" href="<?php echo esc_url(sa_page_url('how-to-order')); ?>"><?php esc_html_e('Full guide', 'supreme-autoparts'); ?></a>
    </div>
    <ol class="sa-how__steps">
      <li class="sa-how__step">
        <span class="sa-how__num" aria-hidden="true">1</span>
        <strong><?php esc_html_e('Find the part', 'supreme-autoparts'); ?></strong>
        <span><?php esc_html_e('Search or browse by category. Check year / make / model on the listing.', 'supreme-autoparts'); ?></span>
      </li>
      <li class="sa-how__step">
        <span class="sa-how__num" aria-hidden="true">2</span>
        <strong><?php esc_html_e('Add to cart', 'supreme-autoparts'); ?></strong>
        <span><?php esc_html_e('No account required — guest checkout is open.', 'supreme-autoparts'); ?></span>
      </li>
      <li class="sa-how__step">
        <span class="sa-how__num" aria-hidden="true">3</span>
        <strong><?php esc_html_e('Pay in USD', 'supreme-autoparts'); ?></strong>
        <span><?php esc_html_e('Secure checkout through Whop. Local display currency where available.', 'supreme-autoparts'); ?></span>
      </li>
      <li class="sa-how__step">
        <span class="sa-how__num" aria-hidden="true">4</span>
        <strong><?php esc_html_e('We ship', 'supreme-autoparts'); ?></strong>
        <span><?php esc_html_e('Ships across the continental US — international available. Track by email.', 'supreme-autoparts'); ?></span>
      </li>
    </ol>
  </div>
</section>

<section class="sa-section sa-section--alt" aria-labelledby="sa-regions-title">
  <div class="sa-container">
    <div class="sa-section__head">
      <h2 id="sa-regions-title" class="sa-section__title"><?php esc_html_e('Shop by region', 'supreme-autoparts'); ?></h2>
    </div>
    <div class="sa-regions">
      <?php foreach (sa_regions() as $region) : ?>
        <a class="sa-region-card <?php echo esc_attr($region['class']); ?>" href="<?php echo esc_url(sa_term_link('product_cat', $region['slug'])); ?>">
          <span class="sa-region-card__label"><?php echo esc_html($region['title']); ?></span>
          <span class="sa-region-card__cta"><?php esc_html_e('Browse', 'supreme-autoparts'); ?> →</span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="sa-section" aria-labelledby="sa-brands-title">
  <div class="sa-container">
    <div class="sa-section__head">
      <h2 id="sa-brands-title" class="sa-section__title"><?php esc_html_e('Shop top brands', 'supreme-autoparts'); ?></h2>
    </div>
    <div class="sa-brands">
      <?php foreach (sa_top_brands() as $brand) : ?>
        <a class="sa-brand" href="<?php echo esc_url(sa_term_link('product_cat', $brand['slug'])); ?>">
          <?php echo esc_html($brand['title']); ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<div class="sa-ship-banner">
  <?php
  printf(
      esc_html__('Free shipping on orders over %s* in the continental US — see policy for details.', 'supreme-autoparts'),
      esc_html($sa_threshold)
  );
  ?>
  <a class="sa-ship-banner__link" href="<?php echo esc_url(sa_page_url('free-shipping')); ?>"><?php esc_html_e('Details', 'supreme-autoparts'); ?></a>
</div>

<section class="sa-section sa-blurb" aria-labelledby="sa-about-title">
  <div class="sa-container sa-blurb__inner">
    <h2 id="sa-about-title"><?php esc_html_e('About', 'supreme-autoparts'); ?></h2>
    <p>
      <?php esc_html_e('Search the part number you already have. OE/OEM-matched and aftermarket fitments for Japan, US & UK cars and trucks — plus EVs and bikes. Brakes, suspension, intake, exhaust, lighting, wheels, and more. Checkout in USD; free shipping on qualifying US orders.', 'supreme-autoparts'); ?>
    </p>
    <p class="sa-blurb__actions">
      <a class="sa-btn sa-btn--outline" href="<?php echo esc_url(sa_page_url('shipping-policy')); ?>"><?php esc_html_e('Shipping & delivery', 'supreme-autoparts'); ?></a>
      <a class="sa-btn sa-btn--outline" href="<?php echo esc_url(sa_page_url('fitment-guide')); ?>"><?php esc_html_e('Fitment guide', 'supreme-autoparts'); ?></a>
    </p>
  </div>
</section>

<section class="sa-section sa-guides-strip" aria-labelledby="sa-guides-title">
  <div class="sa-container">
    <div class="sa-section__head">
      <h2 id="sa-guides-title" class="sa-section__title"><?php esc_html_e('Guides', 'supreme-autoparts'); ?></h2>
      <a class="sa-section__link" href="<?php echo esc_url(sa_page_url('guides')); ?>"><?php esc_html_e('All guides', 'supreme-autoparts'); ?></a>
    </div>
    <div class="sa-guides-strip__grid">
      <a class="sa-guides-strip__card" href="<?php echo esc_url(sa_page_url('how-to-order')); ?>">
        <strong><?php esc_html_e('How to order', 'supreme-autoparts'); ?></strong>
        <span><?php esc_html_e('USD checkout, accounts, and what happens after you pay.', 'supreme-autoparts'); ?></span>
      </a>
      <a class="sa-guides-strip__card" href="<?php echo esc_url(sa_page_url('fitment-guide')); ?>">
        <strong><?php esc_html_e('Fitment guide', 'supreme-autoparts'); ?></strong>
        <span><?php esc_html_e('Year / make / model checks for Japan, US & UK cars, EVs, and bikes.', 'supreme-autoparts'); ?></span>
      </a>
      <a class="sa-guides-strip__card" href="<?php echo esc_url(sa_page_url('shipping-policy')); ?>">
        <strong><?php esc_html_e('Shipping & delivery', 'supreme-autoparts'); ?></strong>
        <span><?php esc_html_e('Continental US rates, free-shipping threshold, and international options.', 'supreme-autoparts'); ?></span>
      </a>
      <a class="sa-guides-strip__card" href="<?php echo esc_url(sa_page_url('performance-truck-parts')); ?>">
        <strong><?php esc_html_e('Truck & off-road', 'supreme-autoparts'); ?></strong>
        <span><?php esc_html_e('Suspension, brakes, wheels, lighting — performance categories.', 'supreme-autoparts'); ?></span>
      </a>
    </div>
  </div>
</section>

<?php
get_footer();
