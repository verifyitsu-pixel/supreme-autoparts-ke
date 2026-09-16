<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Customers list quick link + staff notes (option-backed).
 */
add_action('admin_menu', static function (): void {
    add_submenu_page(
        'supreme-autoparts',
        'Customers',
        'Customers',
        'manage_woocommerce',
        'supreme-customers',
        'sa_core_render_customers_page'
    );
}, 22);

/**
 * @return array<int, array{user_id:int,note:string,author:string,at:int}>
 */
function sa_core_ultra_customer_notes(): array
{
    $notes = get_option('sa_ultra_customer_notes', []);
    return is_array($notes) ? $notes : [];
}

function sa_core_render_customers_page(): void
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $notice = '';
    if (isset($_POST['sa_add_customer_note']) && check_admin_referer('sa_customer_notes')) {
        $user_id = absint($_POST['sa_customer_id'] ?? 0);
        $note = sanitize_textarea_field(wp_unslash((string) ($_POST['sa_note'] ?? '')));
        if ($user_id > 0 && $note !== '') {
            $user = get_userdata($user_id);
            if ($user) {
                $notes = sa_core_ultra_customer_notes();
                array_unshift($notes, [
                    'user_id' => $user_id,
                    'note'    => $note,
                    'author'  => wp_get_current_user()->user_login ?: 'admin',
                    'at'      => time(),
                ]);
                $notes = array_slice($notes, 0, 100);
                update_option('sa_ultra_customer_notes', $notes, false);
                $notice = 'Note saved.';
            } else {
                $notice = 'Customer not found.';
            }
        } else {
            $notice = 'User ID and note are required.';
        }
    }

    $customers = get_users([
        'role'    => 'customer',
        'number'  => 20,
        'orderby' => 'registered',
        'order'   => 'DESC',
    ]);
    $notes = sa_core_ultra_customer_notes();
    $email = function_exists('sa_core_store_email') ? sa_core_store_email() : 'calvin@supremeautoparts.co.ke';
    ?>
    <div class="wrap sa-ultra">
      <div class="sa-ultra__header">
        <div>
          <h1 class="sa-ultra__title">Customers</h1>
          <p class="sa-ultra__sub">Quick list of WooCommerce customers plus internal staff notes.</p>
        </div>
        <a class="sa-ultra__email" href="mailto:<?php echo esc_attr($email); ?>"><?php echo esc_html($email); ?></a>
      </div>

      <?php if ($notice !== '') : ?>
        <div class="sa-inline-notice sa-inline-notice--ok"><?php echo esc_html($notice); ?></div>
      <?php endif; ?>

      <div class="sa-actions" style="margin-bottom:16px;">
        <a class="button button-primary" href="<?php echo esc_url(admin_url('users.php?role=customer')); ?>">All customers (Users)</a>
        <a class="button" href="<?php echo esc_url(admin_url('user-new.php')); ?>">Add user</a>
        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=account')); ?>">Account settings</a>
      </div>

      <div class="sa-ultra__grid">
        <div class="sa-panel">
          <div class="sa-panel__head">
            <h2 class="sa-panel__title">Recent customers</h2>
          </div>
          <div class="sa-table-wrap">
            <table class="sa-table">
              <thead>
                <tr><th>ID</th><th>Name</th><th>Email</th><th>Registered</th></tr>
              </thead>
              <tbody>
              <?php if (!$customers) : ?>
                <tr><td colspan="4" class="sa-muted">No customers yet.</td></tr>
              <?php else : ?>
                <?php foreach ($customers as $user) : ?>
                  <tr>
                    <td><a href="<?php echo esc_url(get_edit_user_link($user->ID)); ?>"><?php echo esc_html((string) $user->ID); ?></a></td>
                    <td><?php echo esc_html($user->display_name); ?></td>
                    <td><a href="mailto:<?php echo esc_attr($user->user_email); ?>"><?php echo esc_html($user->user_email); ?></a></td>
                    <td><?php echo esc_html(mysql2date('Y-m-d', $user->user_registered)); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="sa-panel">
          <h2 class="sa-panel__title">Staff notes</h2>
          <form method="post" class="sa-form-grid" style="margin:12px 0 16px;">
            <?php wp_nonce_field('sa_customer_notes'); ?>
            <label>
              Customer user ID
              <input type="text" name="sa_customer_id" inputmode="numeric" placeholder="e.g. 12" />
            </label>
            <label>
              Note
              <textarea name="sa_note" rows="3" placeholder="Internal only — not shown to customer"></textarea>
            </label>
            <button type="submit" name="sa_add_customer_note" class="button button-primary" value="1">Save note</button>
          </form>
          <ul class="sa-note-list">
            <?php if (!$notes) : ?>
              <li class="sa-muted">No notes yet.</li>
            <?php else : ?>
              <?php foreach (array_slice($notes, 0, 12) as $row) : ?>
                <?php
                $uid = (int) ($row['user_id'] ?? 0);
                $u = $uid ? get_userdata($uid) : false;
                $who = $u ? $u->display_name : ('#' . $uid);
                $when = !empty($row['at']) ? wp_date('Y-m-d H:i', (int) $row['at']) : '';
                ?>
                <li>
                  <div class="sa-note-list__meta"><?php echo esc_html($who); ?> · <?php echo esc_html((string) ($row['author'] ?? '')); ?> · <?php echo esc_html($when); ?> EAT</div>
                  <div><?php echo esc_html((string) ($row['note'] ?? '')); ?></div>
                </li>
              <?php endforeach; ?>
            <?php endif; ?>
          </ul>
        </div>
      </div>
    </div>
    <?php
}
