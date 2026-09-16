<?php
/**
 * Payment methods — Woo tokens + Whop sync UI.
 *
 * @package Supreme_Autoparts
 */

defined('ABSPATH') || exit;

$user_id = get_current_user_id();
$methods = class_exists('Whop_Payment_Methods')
    ? Whop_Payment_Methods::get_methods_for_display($user_id)
    : [];
$synced  = (int) get_user_meta($user_id, '_sa_whop_payment_methods_synced_at', true);
$add_url = class_exists('Whop_Payment_Methods') ? Whop_Payment_Methods::add_url() : '#';
$refresh = class_exists('Whop_Payment_Methods') ? Whop_Payment_Methods::refresh_url() : '#';
?>
<div class="sa-account-panel sa-pm">
  <header class="sa-account-panel__head sa-pm__head">
    <div>
      <h2><?php esc_html_e('Payment methods', 'supreme-autoparts'); ?></h2>
      <p><?php esc_html_e('Cards saved securely with Whop for faster checkout.', 'supreme-autoparts'); ?></p>
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
    <div class="sa-pm__actions">
      <a class="sa-btn sa-btn--outline sa-btn--sm" href="<?php echo esc_url($refresh); ?>">
        <?php esc_html_e('Refresh from Whop', 'supreme-autoparts'); ?>
      </a>
      <a class="sa-btn sa-btn--sm" href="<?php echo esc_url($add_url); ?>">
        <?php esc_html_e('Add payment method', 'supreme-autoparts'); ?>
      </a>
    </div>
  </header>

  <?php if (empty($methods)) : ?>
    <div class="sa-dash__empty">
      <p><?php esc_html_e('No saved payment methods yet.', 'supreme-autoparts'); ?></p>
      <a class="sa-btn" href="<?php echo esc_url($add_url); ?>">
        <?php esc_html_e('Save a card with Whop', 'supreme-autoparts'); ?>
      </a>
    </div>
  <?php else : ?>
    <ul class="sa-pm__list">
      <?php foreach ($methods as $m) : ?>
        <li class="sa-pm__item">
          <div class="sa-pm__meta">
            <span class="sa-pm__brand"><?php echo esc_html(strtoupper((string) ($m['brand'] ?? $m['gateway'] ?? 'CARD'))); ?></span>
            <span class="sa-pm__label"><?php echo esc_html((string) ($m['label'] ?? '')); ?></span>
            <?php if (!empty($m['exp'])) : ?>
              <span class="sa-pm__exp"><?php echo esc_html(sprintf(__('Exp %s', 'supreme-autoparts'), $m['exp'])); ?></span>
            <?php endif; ?>
            <?php if (!empty($m['whop_id'])) : ?>
              <span class="sa-pm__whop" title="<?php echo esc_attr($m['whop_id']); ?>"><?php esc_html_e('Whop', 'supreme-autoparts'); ?></span>
            <?php endif; ?>
            <?php if (!empty($m['is_default'])) : ?>
              <span class="sa-pm__default"><?php esc_html_e('Default', 'supreme-autoparts'); ?></span>
            <?php endif; ?>
          </div>
          <div class="sa-pm__item-actions">
            <?php if (!empty($m['whop_id']) || ($m['gateway'] ?? '') === 'whop') : ?>
              <a class="sa-btn sa-btn--outline sa-btn--sm sa-pm__delete"
                 href="<?php echo esc_url(Whop_Payment_Methods::delete_url((int) $m['token_id'], (string) ($m['whop_id'] ?? ''))); ?>"
                 onclick="return confirm('<?php echo esc_js(__('Remove this payment method?', 'supreme-autoparts')); ?>');">
                <?php esc_html_e('Remove', 'supreme-autoparts'); ?>
              </a>
            <?php elseif (function_exists('wc_get_account_endpoint_url')) : ?>
              <?php
              // Fallback for non-Whop tokens: use Woo default delete if available via filter later.
              ?>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <p class="sa-pm__note">
    <?php esc_html_e('Adding a method opens Whop Checkout in setup mode (no charge). After you return, we sync your Whop wallet into this account.', 'supreme-autoparts'); ?>
  </p>
</div>
