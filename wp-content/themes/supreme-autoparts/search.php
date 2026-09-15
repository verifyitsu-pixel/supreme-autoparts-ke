<?php
declare(strict_types=1);
get_header();
?>
<main id="primary" class="sa-main sa-page">
  <div class="sa-container">
    <header class="sa-archive-header">
      <h1>
        <?php
        printf(
            /* translators: %s: search query */
            esc_html__('Search results for: %s', 'supreme-autoparts'),
            esc_html(get_search_query())
        );
        ?>
      </h1>
    </header>
    <?php if (have_posts()) : ?>
      <?php if (function_exists('woocommerce_product_loop_start')) : ?>
        <?php woocommerce_product_loop_start(); ?>
        <?php while (have_posts()) : the_post(); ?>
          <?php if (get_post_type() === 'product') : ?>
            <?php wc_get_template_part('content', 'product'); ?>
          <?php else : ?>
            <article <?php post_class('sa-page__content'); ?> style="margin-bottom:1rem;">
              <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
              <?php the_excerpt(); ?>
            </article>
          <?php endif; ?>
        <?php endwhile; ?>
        <?php woocommerce_product_loop_end(); ?>
      <?php else : ?>
        <?php while (have_posts()) : the_post(); ?>
          <article <?php post_class('sa-page__content'); ?> style="margin-bottom:1rem;">
            <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
            <?php the_excerpt(); ?>
          </article>
        <?php endwhile; ?>
      <?php endif; ?>
      <?php the_posts_pagination(); ?>
    <?php else : ?>
      <div class="sa-page__content">
        <p><?php esc_html_e('No parts matched your search. Try another keyword or browse categories.', 'supreme-autoparts'); ?></p>
      </div>
    <?php endif; ?>
  </div>
</main>
<?php
get_footer();
