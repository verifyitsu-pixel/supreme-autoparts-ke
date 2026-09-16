<?php
/**
 * Enquire form + channel buttons (WhatsApp / Email / SMS).
 *
 * Expects: $contact, $context, $title, $lead, $prefill, $uid, $compact
 *
 * @package Supreme_Autoparts
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

/** @var array{phone:string,phone_display:string,whatsapp:string,email:string} $contact */
/** @var string $context */
/** @var string $title */
/** @var string $lead */
/** @var array{product:string,car:string,brand:string,year:string,notes:string} $prefill */
/** @var string $uid */
/** @var bool $compact */

$contact = $contact ?? sa_enquire_contact();
$prefill = array_merge([
    'product' => '',
    'car'     => '',
    'brand'   => '',
    'year'    => '',
    'notes'   => '',
], $prefill ?? []);
$uid     = $uid ?? ('sa-enq-' . wp_unique_id());
$compact = !empty($compact);
$context = (string) ($context ?? 'general');
$title   = (string) ($title ?? __('We are updating the catalogue', 'supreme-autoparts'));
$lead    = (string) ($lead ?? '');
?>
<section class="sa-enquire<?php echo $compact ? ' sa-enquire--compact' : ''; ?>" data-sa-enquire data-context="<?php echo esc_attr($context); ?>" aria-labelledby="<?php echo esc_attr($uid); ?>-title">
  <div class="sa-enquire__intro">
    <p class="sa-enquire__eyebrow"><?php esc_html_e('Part enquiry', 'supreme-autoparts'); ?></p>
    <h2 id="<?php echo esc_attr($uid); ?>-title" class="sa-enquire__title"><?php echo esc_html($title); ?></h2>
    <?php if ($lead !== '') : ?>
      <p class="sa-enquire__lead"><?php echo esc_html($lead); ?></p>
    <?php endif; ?>
  </div>

  <form class="sa-enquire__form sa-form" data-sa-enquire-form novalidate>
    <div class="sa-enquire__grid">
      <p class="sa-enquire__field sa-enquire__field--full">
        <label for="<?php echo esc_attr($uid); ?>-product">
          <?php esc_html_e('Product name', 'supreme-autoparts'); ?>
          <span class="sa-enquire__req" aria-hidden="true">*</span>
        </label>
        <input
          type="text"
          id="<?php echo esc_attr($uid); ?>-product"
          name="product"
          class="input-text"
          required
          autocomplete="off"
          placeholder="<?php esc_attr_e('e.g. Front brake pads', 'supreme-autoparts'); ?>"
          value="<?php echo esc_attr($prefill['product']); ?>"
        />
      </p>

      <p class="sa-enquire__field">
        <label for="<?php echo esc_attr($uid); ?>-car">
          <?php esc_html_e('Car name / model', 'supreme-autoparts'); ?>
          <span class="sa-enquire__req" aria-hidden="true">*</span>
        </label>
        <input
          type="text"
          id="<?php echo esc_attr($uid); ?>-car"
          name="car"
          class="input-text"
          required
          autocomplete="off"
          placeholder="<?php esc_attr_e('e.g. Toyota Hilux', 'supreme-autoparts'); ?>"
          value="<?php echo esc_attr($prefill['car']); ?>"
        />
      </p>

      <p class="sa-enquire__field">
        <label for="<?php echo esc_attr($uid); ?>-year">
          <?php esc_html_e('Year', 'supreme-autoparts'); ?>
          <span class="sa-enquire__req" aria-hidden="true">*</span>
        </label>
        <input
          type="text"
          id="<?php echo esc_attr($uid); ?>-year"
          name="year"
          class="input-text"
          required
          inputmode="numeric"
          pattern="[0-9]{4}"
          maxlength="4"
          autocomplete="off"
          placeholder="<?php esc_attr_e('e.g. 2018', 'supreme-autoparts'); ?>"
          value="<?php echo esc_attr($prefill['year']); ?>"
        />
      </p>

      <p class="sa-enquire__field">
        <label for="<?php echo esc_attr($uid); ?>-brand">
          <?php esc_html_e('Brand', 'supreme-autoparts'); ?>
          <span class="sa-enquire__rec"><?php esc_html_e('recommended', 'supreme-autoparts'); ?></span>
        </label>
        <input
          type="text"
          id="<?php echo esc_attr($uid); ?>-brand"
          name="brand"
          class="input-text"
          autocomplete="off"
          placeholder="<?php esc_attr_e('e.g. Brembo, OEM', 'supreme-autoparts'); ?>"
          value="<?php echo esc_attr($prefill['brand']); ?>"
        />
      </p>

      <p class="sa-enquire__field sa-enquire__field--full">
        <label for="<?php echo esc_attr($uid); ?>-notes">
          <?php esc_html_e('Notes', 'supreme-autoparts'); ?>
          <span class="sa-enquire__opt"><?php esc_html_e('optional', 'supreme-autoparts'); ?></span>
        </label>
        <textarea
          id="<?php echo esc_attr($uid); ?>-notes"
          name="notes"
          class="input-text"
          rows="3"
          placeholder="<?php esc_attr_e('Fitment, quantity, city for delivery…', 'supreme-autoparts'); ?>"
        ><?php echo esc_textarea($prefill['notes']); ?></textarea>
      </p>
    </div>

    <p class="sa-enquire__error" data-sa-enquire-error hidden role="alert">
      <?php esc_html_e('Please fill in product name, car / model, and year.', 'supreme-autoparts'); ?>
    </p>

    <div class="sa-enquire__channels">
      <p class="sa-enquire__channels-label"><?php esc_html_e('Send your enquiry via', 'supreme-autoparts'); ?></p>
      <div class="sa-enquire__actions">
        <a
          class="sa-btn sa-enquire__btn sa-enquire__btn--wa"
          data-sa-enquire-channel="whatsapp"
          href="https://wa.me/<?php echo esc_attr($contact['whatsapp']); ?>"
          target="_blank"
          rel="noopener noreferrer"
        >
          <?php esc_html_e('WhatsApp', 'supreme-autoparts'); ?>
        </a>
        <a
          class="sa-btn sa-btn--outline sa-enquire__btn"
          data-sa-enquire-channel="email"
          href="mailto:<?php echo esc_attr($contact['email']); ?>"
        >
          <?php esc_html_e('Email', 'supreme-autoparts'); ?>
        </a>
        <a
          class="sa-btn sa-btn--outline sa-enquire__btn"
          data-sa-enquire-channel="sms"
          href="sms:<?php echo esc_attr($contact['phone']); ?>"
        >
          <?php esc_html_e('SMS', 'supreme-autoparts'); ?>
        </a>
      </div>
      <p class="sa-enquire__meta">
        <?php
        printf(
            /* translators: 1: phone, 2: email */
            esc_html__('WhatsApp & SMS: %1$s · Email: %2$s', 'supreme-autoparts'),
            esc_html($contact['phone_display']),
            esc_html($contact['email'])
        );
        ?>
      </p>
    </div>

    <script type="application/json" data-sa-enquire-config>
<?php
echo wp_json_encode([
    'whatsapp' => $contact['whatsapp'],
    'phone'    => $contact['phone'],
    'email'    => $contact['email'],
    'subject'  => 'Part enquiry — Supreme Autoparts',
]);
?>
    </script>
  </form>
</section>
