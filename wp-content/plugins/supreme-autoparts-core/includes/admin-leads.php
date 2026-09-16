<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enquire leads stub — storefront enquire opens WhatsApp/email/SMS;
 * this page holds a manual lead log for follow-up.
 */
add_action('admin_menu', static function (): void {
    add_submenu_page(
        'supreme-autoparts',
        'Enquire leads',
        'Enquire leads',
        'manage_woocommerce',
        'supreme-leads',
        'sa_core_render_leads_page'
    );
}, 23);

/**
 * @return array<int, array{product:string,car:string,contact:string,channel:string,notes:string,author:string,at:int}>
 */
function sa_core_ultra_leads(): array
{
    $leads = get_option('sa_ultra_enquire_leads', []);
    return is_array($leads) ? $leads : [];
}

function sa_core_render_leads_page(): void
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $notice = '';
    if (isset($_POST['sa_add_lead']) && check_admin_referer('sa_enquire_leads')) {
        $product = sanitize_text_field(wp_unslash((string) ($_POST['sa_product'] ?? '')));
        $car = sanitize_text_field(wp_unslash((string) ($_POST['sa_car'] ?? '')));
        $contact = sanitize_text_field(wp_unslash((string) ($_POST['sa_contact'] ?? '')));
        $channel = sanitize_key((string) ($_POST['sa_channel'] ?? 'whatsapp'));
        $notes = sanitize_textarea_field(wp_unslash((string) ($_POST['sa_notes'] ?? '')));
        if ($product !== '' || $contact !== '') {
            $leads = sa_core_ultra_leads();
            array_unshift($leads, [
                'product' => $product,
                'car'     => $car,
                'contact' => $contact,
                'channel' => $channel,
                'notes'   => $notes,
                'author'  => wp_get_current_user()->user_login ?: 'admin',
                'at'      => time(),
            ]);
            $leads = array_slice($leads, 0, 200);
            update_option('sa_ultra_enquire_leads', $leads, false);
            $notice = 'Lead logged.';
        } else {
            $notice = 'Add at least a product or contact.';
        }
    }

    if (isset($_POST['sa_clear_leads']) && check_admin_referer('sa_enquire_leads')) {
        delete_option('sa_ultra_enquire_leads');
        $notice = 'Lead log cleared.';
    }

    $leads = sa_core_ultra_leads();
    $store = function_exists('sa_core_store_email') ? sa_core_store_email() : 'calvin@supremeautoparts.co.ke';
    $wa = '254714498451';
    ?>
    <div class="wrap sa-ultra">
      <div class="sa-ultra__header">
        <div>
          <h1 class="sa-ultra__title">Enquire leads</h1>
          <p class="sa-ultra__sub">Storefront enquire sends customers to WhatsApp / email / SMS. Log follow-ups here for the team.</p>
        </div>
        <a class="sa-ultra__email" href="mailto:<?php echo esc_attr($store); ?>"><?php echo esc_html($store); ?></a>
      </div>

      <?php if ($notice !== '') : ?>
        <div class="sa-inline-notice sa-inline-notice--ok"><?php echo esc_html($notice); ?></div>
      <?php endif; ?>

      <div class="sa-actions" style="margin-bottom:16px;">
        <a class="button button-primary" href="<?php echo esc_url('https://wa.me/' . $wa); ?>" target="_blank" rel="noopener">Open WhatsApp</a>
        <a class="button" href="mailto:<?php echo esc_attr($store); ?>">Email inbox</a>
        <a class="button" href="<?php echo esc_url(home_url('/enquire/')); ?>" target="_blank" rel="noopener">View enquire page</a>
      </div>

      <div class="sa-ultra__grid">
        <div class="sa-panel">
          <h2 class="sa-panel__title">Log a lead</h2>
          <form method="post" class="sa-form-grid" style="margin-top:12px;">
            <?php wp_nonce_field('sa_enquire_leads'); ?>
            <label>Product / part<input type="text" name="sa_product" /></label>
            <label>Car / model<input type="text" name="sa_car" /></label>
            <label>Customer contact<input type="text" name="sa_contact" placeholder="Phone or email" /></label>
            <label>Channel
              <select name="sa_channel">
                <option value="whatsapp">WhatsApp</option>
                <option value="email">Email</option>
                <option value="sms">SMS</option>
                <option value="phone">Phone</option>
                <option value="other">Other</option>
              </select>
            </label>
            <label>Notes<textarea name="sa_notes" rows="3"></textarea></label>
            <div class="sa-actions">
              <button type="submit" name="sa_add_lead" class="button button-primary" value="1">Save lead</button>
              <button type="submit" name="sa_clear_leads" class="button" value="1" onclick="return confirm('Clear all logged leads?');">Clear log</button>
            </div>
          </form>
        </div>

        <div class="sa-panel">
          <h2 class="sa-panel__title">Recent leads</h2>
          <ul class="sa-note-list" style="margin-top:12px;">
            <?php if (!$leads) : ?>
              <li class="sa-muted">No leads logged yet.</li>
            <?php else : ?>
              <?php foreach (array_slice($leads, 0, 25) as $row) : ?>
                <li>
                  <div class="sa-note-list__meta">
                    <?php echo esc_html((string) ($row['channel'] ?? '')); ?>
                    · <?php echo esc_html(wp_date('Y-m-d H:i', (int) ($row['at'] ?? 0))); ?> EAT
                    · <?php echo esc_html((string) ($row['author'] ?? '')); ?>
                  </div>
                  <div><strong><?php echo esc_html((string) ($row['product'] ?: '—')); ?></strong>
                    <?php if (!empty($row['car'])) : ?> · <?php echo esc_html((string) $row['car']); ?><?php endif; ?>
                  </div>
                  <?php if (!empty($row['contact'])) : ?>
                    <div class="sa-muted"><?php echo esc_html((string) $row['contact']); ?></div>
                  <?php endif; ?>
                  <?php if (!empty($row['notes'])) : ?>
                    <div><?php echo esc_html((string) $row['notes']); ?></div>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            <?php endif; ?>
          </ul>
        </div>
      </div>
    </div>
    <?php
}
