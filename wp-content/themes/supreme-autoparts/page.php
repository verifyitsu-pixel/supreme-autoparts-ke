<?php
declare(strict_types=1);
get_header();
?>
<main id="primary" class="sa-main sa-page">
  <div class="sa-container">
    <?php while (have_posts()) : the_post(); ?>
      <article <?php post_class('sa-page__content'); ?>>
        <h1><?php the_title(); ?></h1>
        <div class="entry-content"><?php the_content(); ?></div>
      </article>
    <?php endwhile; ?>
  </div>
</main>
<?php
get_footer();
