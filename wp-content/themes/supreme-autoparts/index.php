<?php
declare(strict_types=1);
get_header();
?>
<main id="primary" class="sa-main sa-page">
  <div class="sa-container">
    <?php if (have_posts()) : ?>
      <?php while (have_posts()) : the_post(); ?>
        <article <?php post_class('sa-page__content'); ?>>
          <h1><?php the_title(); ?></h1>
          <div class="entry-content"><?php the_content(); ?></div>
        </article>
      <?php endwhile; ?>
    <?php else : ?>
      <div class="sa-page__content">
        <h1><?php esc_html_e('Nothing found', 'supreme-autoparts'); ?></h1>
        <p><?php esc_html_e('Try searching for parts or browse categories from the menu.', 'supreme-autoparts'); ?></p>
      </div>
    <?php endif; ?>
  </div>
</main>
<?php
get_footer();
