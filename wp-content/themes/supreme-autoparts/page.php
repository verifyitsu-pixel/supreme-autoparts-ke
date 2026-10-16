<?php
declare(strict_types=1);
get_header();
?>
<main id="primary" class="sa-main sa-page">
  <div class="sa-container">
    <?php while (have_posts()) : the_post(); ?>
      <article <?php post_class('sa-page__content'); ?>>
        <?php
        $sa_greet = '';
        if (is_user_logged_in() && function_exists('is_account_page') && is_account_page()
            && function_exists('sa_avatar_first_name') && function_exists('sa_avatar_greeting_html')
            && sa_avatar_first_name(get_current_user_id()) !== '') {
            $sa_greet = sa_avatar_greeting_html(get_current_user_id());
        }
        ?>
        <?php if ($sa_greet !== '') : ?>
          <h1 class="sa-page__greet"><?php echo $sa_greet; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h1>
        <?php else : ?>
          <h1><?php the_title(); ?></h1>
        <?php endif; ?>
        <div class="entry-content"><?php the_content(); ?></div>
      </article>
    <?php endwhile; ?>
  </div>
</main>
<?php
get_footer();

