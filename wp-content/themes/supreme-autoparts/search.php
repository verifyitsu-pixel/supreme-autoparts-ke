<?php
/**
 * Product-first search results.
 *
 * @package Supreme_Autoparts
 */

declare(strict_types=1);

get_header();

$sa_total = isset($GLOBALS['wp_query']) && $GLOBALS['wp_query'] instanceof WP_Query
    ? (int) $GLOBALS['wp_query']->found_posts
    : 0;
$q = get_search_query();
?>
<main id="primary" class="sa-main sa-page sa-search-results">
  <div class="sa-container">
    <header class="sa-archive-header sa-archive-header--shop">
      <p class="sa-archive-header__eyebrow"><?php esc_html_e('Search', 'supreme-autoparts'); ?></p>
      <h1>
        <?php
        if ($q !== '') {
            printf(
                /* translators: %s: search query */
                esc_html__('Results for “%s”', 'supreme-autoparts'),
                esc_html($q)
            );
        } else {
            esc_html_e('Search parts', 'supreme-autoparts');
        }
        ?>
      </h1>
      <?php if ($sa_total > 0) : ?>
        <p class="sa-archive-header__count">
          <?php
          printf(
              esc_html(_n('%s part', '%s parts', $sa_total, 'supreme-autoparts')),
              esc_html(number_format_i18n($sa_total))
          );
          ?>
        </p>
      <?php endif; ?>
      <p class="sa-archive-header__hint"><?php esc_html_e('Part numbers match first, then brand and title.', 'supreme-autoparts'); ?></p>
    </header>

    <?php get_template_part('template-parts/catalog', 'filters'); ?>

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
      <?php get_template_part('template-parts/catalog', 'empty'); ?>
    <?php endif; ?>
  </div>
</main>
<?php
get_footer();
