<?php
/**
 * Support endpoint — enquire + contact + policy links.
 *
 * @package Supreme_Autoparts
 */

defined('ABSPATH') || exit;

$email   = 'calvin@supremeautoparts.co.ke';
$contact = function_exists('sa_enquire_contact') ? sa_enquire_contact() : [
    'phone_display' => '+254 714 498 451',
    'email'         => $email,
];
$policies = [
    ['slug' => 'shipping-policy', 'label' => __('Shipping policy', 'supreme-autoparts')],
    ['slug' => 'refund-policy', 'label' => __('Refund policy', 'supreme-autoparts')],
    ['slug' => 'returns', 'label' => __('Returns', 'supreme-autoparts')],
    ['slug' => 'chargeback-policy', 'label' => __('Chargeback / disputes', 'supreme-autoparts')],
    ['slug' => 'privacy-policy', 'label' => __('Privacy policy', 'supreme-autoparts')],
    ['slug' => 'terms-of-service', 'label' => __('Terms of service', 'supreme-autoparts')],
    ['slug' => 'enquire', 'label' => __('Can\'t find a part?', 'supreme-autoparts')],
    ['slug' => 'contact', 'label' => __('Contact', 'supreme-autoparts')],
];
?>
<div class="sa-account-panel sa-support">
  <header class="sa-account-panel__head">
    <h2><?php esc_html_e('Support', 'supreme-autoparts'); ?></h2>
    <p><?php esc_html_e('Questions about fitment, delivery in Kenya, or an existing order — we are here to help.', 'supreme-autoparts'); ?></p>
  </header>

  <div class="sa-support__grid">
    <div class="sa-support__card">
      <h3><?php esc_html_e('Reach us', 'supreme-autoparts'); ?></h3>
      <p><?php esc_html_e('Typical reply during business hours (Africa/Nairobi).', 'supreme-autoparts'); ?></p>
      <ul class="sa-support__reach">
        <li>
          <strong><?php esc_html_e('WhatsApp / SMS', 'supreme-autoparts'); ?></strong>
          <a href="https://wa.me/254714498451"><?php echo esc_html($contact['phone_display']); ?></a>
        </li>
        <li>
          <strong><?php esc_html_e('Email', 'supreme-autoparts'); ?></strong>
          <a href="mailto:<?php echo esc_attr($email); ?>?subject=<?php echo rawurlencode('Supreme Autoparts support'); ?>">
            <?php echo esc_html($email); ?>
          </a>
        </li>
      </ul>
    </div>
    <div class="sa-support__card">
      <h3><?php esc_html_e('Store policies', 'supreme-autoparts'); ?></h3>
      <ul class="sa-support__links">
        <?php foreach ($policies as $p) :
            $page = get_page_by_path($p['slug']);
            $url  = $page ? get_permalink($page) : home_url('/' . $p['slug'] . '/');
            ?>
          <li><a href="<?php echo esc_url($url); ?>"><?php echo esc_html($p['label']); ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>

  <?php if (function_exists('sa_render_enquire')) : ?>
    <div class="sa-support__enquire">
      <?php
      sa_render_enquire([
          'context' => 'account',
          'compact' => true,
      ]);
      ?>
    </div>
  <?php endif; ?>
</div>
