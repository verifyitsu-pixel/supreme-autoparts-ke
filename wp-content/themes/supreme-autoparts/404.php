<?php
/**
 * 404 — product-aware enquire when a part listing is missing.
 *
 * @package Supreme_Autoparts
 */

declare(strict_types=1);

get_header();

$prefill = function_exists('sa_enquire_prefill_from_request')
    ? sa_enquire_prefill_from_request()
    : ['product' => '', 'context' => '404'];
$productish = function_exists('sa_enquire_is_productish_404') && sa_enquire_is_productish_404();
$context    = $productish ? 'product-404' : ($prefill['context'] ?? '404');
?>
<main id="primary" class="sa-main sa-page sa-404">
  <div class="sa-container">
    <div class="sa-404__wrap">
      <?php if ($productish || ($prefill['product'] ?? '') !== '') : ?>
        <?php
        sa_render_enquire([
            'context' => $context,
            'product' => (string) ($prefill['product'] ?? ''),
        ]);
        ?>
        <p class="sa-404__alt">
          <a href="<?php echo esc_url(function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/')); ?>">
            <?php esc_html_e('Browse the shop', 'supreme-autoparts'); ?>
          </a>
          <span aria-hidden="true"> · </span>
          <a href="<?php echo esc_url(home_url('/')); ?>">
            <?php esc_html_e('Home', 'supreme-autoparts'); ?>
          </a>
        </p>
      <?php else : ?>
        <div class="sa-404__generic sa-page__content">
          <p class="sa-enquire__eyebrow"><?php esc_html_e('404', 'supreme-autoparts'); ?></p>
          <h1><?php esc_html_e('Page not found', 'supreme-autoparts'); ?></h1>
          <p><?php esc_html_e('The page you requested does not exist or has moved.', 'supreme-autoparts'); ?></p>
          <p class="sa-404__actions">
            <a class="sa-btn" href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Go home', 'supreme-autoparts'); ?></a>
            <a class="sa-btn sa-btn--outline" href="<?php echo esc_url(sa_enquire_page_url()); ?>"><?php esc_html_e('Can\'t find a part?', 'supreme-autoparts'); ?></a>
          </p>
        </div>
      <?php endif; ?>
    </div>
  </div>
</main>
<?php
get_footer();
