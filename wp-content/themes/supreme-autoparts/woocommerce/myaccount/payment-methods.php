<?php
/**
 * Payment methods — Woo tokens + Whop embedded verify checkout (same-page).
 *
 * @package Supreme_Autoparts
 */

defined('ABSPATH') || exit;

$user_id     = get_current_user_id();
$whop_ready  = class_exists('Whop_Payment_Methods');
$methods     = $whop_ready ? Whop_Payment_Methods::get_methods_for_display($user_id) : [];
$synced      = (int) get_user_meta($user_id, '_sa_whop_payment_methods_synced_at', true);
$add_url     = $whop_ready ? Whop_Payment_Methods::add_url() : '';
$refresh     = $whop_ready ? Whop_Payment_Methods::refresh_url() : '';
$delete_confirm = esc_js(__('Remove this payment method from your account? This cannot be undone.', 'supreme-autoparts'));
$embed_open = !empty($_GET['sa_whop_embed']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
?>
<div class="sa-account-panel sa-pm">
  <header class="sa-account-panel__head sa-pm__head">
    <div>
      <h2><?php esc_html_e('Payment methods', 'supreme-autoparts'); ?></h2>
      <p class="sa-account-panel__lead">
        <?php esc_html_e('Save a card for faster checkout.', 'supreme-autoparts'); ?>
      </p>
      <?php if ($synced) : ?>
        <p class="sa-pm__synced">
          <?php
          printf(
              /* translators: %s: local datetime */
              esc_html__('Last synced: %s', 'supreme-autoparts'),
              esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $synced))
          );
          ?>
        </p>
      <?php endif; ?>
    </div>
    <?php if ($whop_ready) : ?>
      <div class="sa-pm__actions">
        <a class="sa-btn sa-btn--outline sa-btn--sm" href="<?php echo esc_url($refresh); ?>">
          <?php esc_html_e('Refresh methods', 'supreme-autoparts'); ?>
        </a>
        <button type="button" class="sa-btn sa-btn--sm" data-sa-whop-add-card
                data-fallback-href="<?php echo esc_url($add_url); ?>">
          <?php esc_html_e('Add card', 'supreme-autoparts'); ?>
        </button>
      </div>
    <?php endif; ?>
  </header>

  <?php if ($whop_ready) : ?>
    <section id="sa-whop-embed" class="sa-pm-embed" <?php echo $embed_open ? '' : 'hidden'; ?> aria-hidden="<?php echo $embed_open ? 'false' : 'true'; ?>" aria-label="<?php esc_attr_e('Add payment method', 'supreme-autoparts'); ?>">
      <div class="sa-pm-embed__head">
        <h3 class="sa-pm-embed__title"><?php esc_html_e('Add card', 'supreme-autoparts'); ?></h3>
        <button type="button" class="sa-btn sa-btn--outline sa-btn--sm" id="sa-whop-embed-cancel">
          <?php esc_html_e('Cancel', 'supreme-autoparts'); ?>
        </button>
      </div>
      <p id="sa-whop-embed-status" class="sa-pm-embed__status" role="status" aria-live="polite"></p>
      <div id="sa-whop-pm-embed" class="sa-pm-embed__mount" style="min-height:480px;"></div>
      <noscript>
        <p class="sa-pm-embed__noscript">
          <?php esc_html_e('JavaScript is required to add a card on this page.', 'supreme-autoparts'); ?>
          <a href="<?php echo esc_url($add_url); ?>"><?php esc_html_e('Continue', 'supreme-autoparts'); ?></a>
        </p>
      </noscript>
    </section>
  <?php endif; ?>

  <?php if (!$whop_ready) : ?>
    <div class="sa-dash__empty sa-pm__unavailable">
      <p><?php esc_html_e('Payment methods are temporarily unavailable. Please try again later or contact support.', 'supreme-autoparts'); ?></p>
      <a class="sa-btn sa-btn--outline" href="<?php echo esc_url(wc_get_account_endpoint_url('support')); ?>">
        <?php esc_html_e('Contact support', 'supreme-autoparts'); ?>
      </a>
    </div>
  <?php elseif (empty($methods)) : ?>
    <div class="sa-dash__empty" id="sa-pm-empty">
      <p><?php esc_html_e('No saved payment methods yet.', 'supreme-autoparts'); ?></p>
      <button type="button" class="sa-btn" data-sa-whop-add-card
              data-fallback-href="<?php echo esc_url($add_url); ?>">
        <?php esc_html_e('Add card', 'supreme-autoparts'); ?>
      </button>
    </div>
  <?php else : ?>
    <ul class="sa-pm__list" role="list">
      <?php foreach ($methods as $m) :
          $kind    = (string) ($m['kind'] ?? 'card');
          $is_bank = ($kind === 'bank' || strtolower((string) ($m['brand'] ?? '')) === 'bank');
          $brand   = strtoupper((string) ($m['brand'] ?? ($is_bank ? 'BANK' : ($m['gateway'] ?? 'CARD'))));
          $label   = (string) ($m['label'] ?? '');
          $last4   = (string) ($m['last4'] ?? '');
          $exp     = $is_bank ? '' : (string) ($m['exp'] ?? '');
          $whop_id = (string) ($m['whop_id'] ?? '');
          $token_id = (int) ($m['token_id'] ?? 0);
          $is_whop = $whop_id !== '' || (($m['gateway'] ?? '') === 'whop');
          if ($is_bank && $label === '' && $last4 !== '') {
              $label = 'Bank •••• ' . $last4;
          } elseif ($last4 !== '' && $label === '') {
              $label = '•••• ' . $last4;
          } elseif ($last4 !== '' && !str_contains($label, $last4)) {
              $label = trim($label . ' •••• ' . $last4);
          }
          ?>
        <li class="sa-pm__item<?php echo !empty($m['is_default']) ? ' sa-pm__item--default' : ''; ?>">
          <div class="sa-pm__icon" aria-hidden="true"><?php echo esc_html(substr($brand, 0, 4)); ?></div>
          <div class="sa-pm__meta">
            <span class="sa-pm__brand"><?php echo esc_html($brand); ?></span>
            <span class="sa-pm__label"><?php echo esc_html($label !== '' ? $label : ($is_bank ? __('Saved bank account', 'supreme-autoparts') : __('Saved card', 'supreme-autoparts'))); ?></span>
            <?php if ($exp !== '') : ?>
              <span class="sa-pm__exp"><?php echo esc_html(sprintf(/* translators: %s: MM/YYYY */ __('Exp %s', 'supreme-autoparts'), $exp)); ?></span>
            <?php endif; ?>
            <?php if (!empty($m['is_default'])) : ?>
              <span class="sa-pm__default"><?php esc_html_e('Default', 'supreme-autoparts'); ?></span>
            <?php endif; ?>
          </div>
          <div class="sa-pm__item-actions">
            <?php if ($is_whop && $token_id > 0) : ?>
              <a class="sa-btn sa-btn--outline sa-btn--sm sa-pm__delete"
                 href="<?php echo esc_url(Whop_Payment_Methods::delete_url($token_id, $whop_id)); ?>"
                 onclick="return confirm('<?php echo $delete_confirm; ?>');">
                <?php esc_html_e('Remove', 'supreme-autoparts'); ?>
              </a>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if ($whop_ready) : ?>
    <p class="sa-pm__note">
      <?php esc_html_e('Card fields appear on this page. You can remove a saved card anytime.', 'supreme-autoparts'); ?>
    </p>
  <?php endif; ?>
</div>
