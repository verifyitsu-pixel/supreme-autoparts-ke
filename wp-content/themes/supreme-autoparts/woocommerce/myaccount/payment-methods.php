<?php
/**
 * Payment methods — Woo tokens + Whop sync UI (hardened).
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
?>
<div class="sa-account-panel sa-pm">
  <header class="sa-account-panel__head sa-pm__head">
    <div>
      <h2><?php esc_html_e('Payment methods', 'supreme-autoparts'); ?></h2>
      <p class="sa-account-panel__lead">
        <?php esc_html_e('Save a card securely with Whop for one-tap checkout. Adding a method opens Whop in setup mode — you are not charged.', 'supreme-autoparts'); ?>
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
          <?php esc_html_e('Refresh from Whop', 'supreme-autoparts'); ?>
        </a>
        <a class="sa-btn sa-btn--sm" href="<?php echo esc_url($add_url); ?>">
          <?php esc_html_e('Add card via Whop', 'supreme-autoparts'); ?>
        </a>
      </div>
    <?php endif; ?>
  </header>

  <?php if (!$whop_ready) : ?>
    <div class="sa-dash__empty sa-pm__unavailable">
      <p><?php esc_html_e('Whop payment methods are temporarily unavailable. Please try again later or contact support.', 'supreme-autoparts'); ?></p>
      <a class="sa-btn sa-btn--outline" href="<?php echo esc_url(wc_get_account_endpoint_url('support')); ?>">
        <?php esc_html_e('Contact support', 'supreme-autoparts'); ?>
      </a>
    </div>
  <?php elseif (empty($methods)) : ?>
    <div class="sa-dash__empty">
      <p><?php esc_html_e('No saved payment methods yet.', 'supreme-autoparts'); ?></p>
      <a class="sa-btn" href="<?php echo esc_url($add_url); ?>">
        <?php esc_html_e('Add card via Whop (no charge)', 'supreme-autoparts'); ?>
      </a>
    </div>
  <?php else : ?>
    <ul class="sa-pm__list" role="list">
      <?php foreach ($methods as $m) :
          $brand   = strtoupper((string) ($m['brand'] ?? $m['gateway'] ?? 'CARD'));
          $label   = (string) ($m['label'] ?? '');
          $last4   = (string) ($m['last4'] ?? '');
          $exp     = (string) ($m['exp'] ?? '');
          $whop_id = (string) ($m['whop_id'] ?? '');
          $token_id = (int) ($m['token_id'] ?? 0);
          $is_whop = $whop_id !== '' || (($m['gateway'] ?? '') === 'whop');
          if ($last4 !== '' && $label === '') {
              $label = '•••• ' . $last4;
          } elseif ($last4 !== '' && !str_contains($label, $last4)) {
              $label = trim($label . ' •••• ' . $last4);
          }
          ?>
        <li class="sa-pm__item<?php echo !empty($m['is_default']) ? ' sa-pm__item--default' : ''; ?>">
          <div class="sa-pm__icon" aria-hidden="true"><?php echo esc_html(substr($brand, 0, 4)); ?></div>
          <div class="sa-pm__meta">
            <span class="sa-pm__brand"><?php echo esc_html($brand); ?></span>
            <span class="sa-pm__label"><?php echo esc_html($label !== '' ? $label : __('Saved card', 'supreme-autoparts')); ?></span>
            <?php if ($exp !== '') : ?>
              <span class="sa-pm__exp"><?php echo esc_html(sprintf(/* translators: %s: MM/YYYY */ __('Exp %s', 'supreme-autoparts'), $exp)); ?></span>
            <?php endif; ?>
            <?php if ($is_whop) : ?>
              <span class="sa-pm__whop"><?php esc_html_e('Whop', 'supreme-autoparts'); ?></span>
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
      <?php esc_html_e('Adding a method opens Whop Checkout in setup mode (no charge). After you return, we sync your Whop wallet into this account. You can remove a method anytime.', 'supreme-autoparts'); ?>
    </p>
  <?php endif; ?>
</div>
