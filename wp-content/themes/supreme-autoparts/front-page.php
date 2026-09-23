<?php
declare(strict_types=1);
/**
 * Homepage template.
 */
get_header();
?>

<section class="sa-hero">
  <div class="sa-container sa-hero__inner">
    <p class="sa-hero__eyebrow"><?php esc_html_e('Supreme Autoparts · Kenya', 'supreme-autoparts'); ?></p>
    <h1><?php esc_html_e('Car Parts & Accessories', 'supreme-autoparts'); ?></h1>
    <p class="sa-hero__lead"><?php esc_html_e('US-spec performance and aftermarket parts for cars, trucks, and SUVs — USD checkout, shipping to Kenya and beyond.', 'supreme-autoparts'); ?></p>
    <div class="sa-hero__actions">
      <?php if (function_exists('wc_get_page_permalink')) : ?>
        <a class="sa-btn" href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>"><?php esc_html_e('Shop Now', 'supreme-autoparts'); ?></a>
      <?php endif; ?>
      <a class="sa-btn sa-btn--outline" href="<?php echo esc_url(sa_term_link('product_cat', 'brakes')); ?>"><?php esc_html_e('Shop Brakes', 'supreme-autoparts'); ?></a>
    </div>
  </div>
</section>

<section class="sa-section">
  <div class="sa-container">
    <h2 class="sa-section__title"><?php esc_html_e('Shop by Region', 'supreme-autoparts'); ?></h2>
    <div class="sa-regions">
      <?php foreach (sa_regions() as $region) : ?>
        <a class="sa-region-card <?php echo esc_attr($region['class']); ?>" href="<?php echo esc_url(sa_term_link('product_cat', $region['slug'])); ?>">
          <span class="sa-region-card__label"><?php echo esc_html($region['title']); ?></span>
          <span class="sa-region-card__cta"><?php esc_html_e('Browse', 'supreme-autoparts'); ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="sa-section sa-section--tight">
  <div class="sa-container">
    <h2 class="sa-section__title"><?php esc_html_e('Shop by Product Type', 'supreme-autoparts'); ?></h2>
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

<section class="sa-section sa-blurb">
  <div class="sa-container sa-blurb__inner">
    <h2><?php esc_html_e('Top Performance Parts for Cars & Trucks', 'supreme-autoparts'); ?></h2>
    <p>
      <?php esc_html_e('Supreme Autoparts shares your passion for enhancing and customizing cars and trucks. Our extensive selection of auto parts includes everything from aftermarket performance engine components to wheels, suspension systems, and accessories for the most sought-after vehicles. Our goal is to provide you with top-quality aftermarket and performance auto parts at competitive prices. Whether you\'re looking to upgrade your daily driver or completely transform your weekend warrior, we share your excitement for performance. Shop for quality auto parts — and enjoy free shipping on qualifying orders.', 'supreme-autoparts'); ?>
    </p>
    <p>
      <a class="sa-btn" href="<?php echo esc_url(sa_page_url('free-shipping')); ?>"><?php esc_html_e('Free Shipping Details', 'supreme-autoparts'); ?></a>
    </p>
  </div>
</section>

<section class="sa-section">
  <div class="sa-container">
    <h2 class="sa-section__title"><?php esc_html_e('Shop Top Brands', 'supreme-autoparts'); ?></h2>
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
      esc_html__('Complimentary shipping on orders over %s* — see policy for details.', 'supreme-autoparts'),
      esc_html(sa_free_shipping_threshold())
  );
  ?>
</div>

<?php
/* Latest Parts — distinct recent products with real photos (no spam duplicates). */
$sa_latest = function_exists('sa_get_homepage_latest_products')
    ? sa_get_homepage_latest_products(8)
    : [];
if (!empty($sa_latest)) :
    ?>
<section class="sa-section sa-latest-parts" aria-labelledby="sa-latest-parts-title">
  <div class="sa-container">
    <div class="sa-section__head">
      <h2 id="sa-latest-parts-title" class="sa-section__title"><?php esc_html_e('Featured parts', 'supreme-autoparts'); ?></h2>
      <?php if (function_exists('wc_get_page_permalink')) : ?>
        <a class="sa-section__link" href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>"><?php esc_html_e('View all', 'supreme-autoparts'); ?></a>
      <?php endif; ?>
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


<section class="sa-section sa-guides-strip" aria-labelledby="sa-guides-title">
  <div class="sa-container">
    <div class="sa-section__head">
      <h2 id="sa-guides-title" class="sa-section__title"><?php esc_html_e('Guides for US & Kenya buyers', 'supreme-autoparts'); ?></h2>
      <a class="sa-section__link" href="<?php echo esc_url(sa_page_url('guides')); ?>"><?php esc_html_e('All guides', 'supreme-autoparts'); ?></a>
    </div>
    <div class="sa-guides-strip__grid">
      <a class="sa-guides-strip__card" href="<?php echo esc_url(sa_page_url('how-to-order')); ?>">
        <strong><?php esc_html_e('How to order', 'supreme-autoparts'); ?></strong>
        <span><?php esc_html_e('USD checkout, accounts, and what happens after you pay.', 'supreme-autoparts'); ?></span>
      </a>
      <a class="sa-guides-strip__card" href="<?php echo esc_url(sa_page_url('fitment-guide')); ?>">
        <strong><?php esc_html_e('Fitment guide', 'supreme-autoparts'); ?></strong>
        <span><?php esc_html_e('Year / make / model checks for US-spec trucks and SUVs.', 'supreme-autoparts'); ?></span>
      </a>
      <a class="sa-guides-strip__card" href="<?php echo esc_url(sa_page_url('shipping-to-kenya')); ?>">
        <strong><?php esc_html_e('Shipping to Kenya', 'supreme-autoparts'); ?></strong>
        <span><?php esc_html_e('Import delivery, free-shipping threshold, WhatsApp support.', 'supreme-autoparts'); ?></span>
      </a>
      <a class="sa-guides-strip__card" href="<?php echo esc_url(sa_page_url('performance-truck-parts')); ?>">
        <strong><?php esc_html_e('Truck & off-road', 'supreme-autoparts'); ?></strong>
        <span><?php esc_html_e('Suspension, brakes, wheels, lighting — performance categories.', 'supreme-autoparts'); ?></span>
      </a>
    </div>
  </div>
</section>

get_footer();
