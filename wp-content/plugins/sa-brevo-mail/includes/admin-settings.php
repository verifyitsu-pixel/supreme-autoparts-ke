<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce → Supreme Brevo settings page.
 */
class SA_Brevo_Admin_Settings
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu'], 60);
        add_action('admin_post_sa_brevo_test_email', [self::class, 'handle_test_email']);
        add_action('admin_post_sa_brevo_save', [self::class, 'handle_save']);
        add_action('admin_notices', [self::class, 'maybe_notice']);
    }

    public static function menu(): void
    {
        add_submenu_page(
            'woocommerce',
            'Supreme Brevo',
            'Supreme Brevo',
            'manage_woocommerce',
            'sa-brevo-settings',
            [self::class, 'render']
        );
    }

    public static function maybe_notice(): void
    {
        if (!isset($_GET['page']) || $_GET['page'] !== 'sa-brevo-settings') {
            return;
        }
        if (!empty($_GET['sa_brevo_test']) && $_GET['sa_brevo_test'] === 'ok') {
            echo '<div class="notice notice-success"><p>Test email sent via Brevo.</p></div>';
        }
        if (!empty($_GET['sa_brevo_test']) && $_GET['sa_brevo_test'] === 'fail') {
            $err = esc_html((string) get_option('sa_brevo_last_send_error', 'Unknown error'));
            echo '<div class="notice notice-error"><p>Test email failed: ' . $err . '</p></div>';
        }
        if (!empty($_GET['sa_brevo_saved'])) {
            echo '<div class="notice notice-success"><p>Brevo settings saved.</p></div>';
        }
    }

    public static function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $configured = sa_brevo_is_configured();
        $key_source = getenv('BREVO_API_KEY') ? 'env (BREVO_API_KEY)' : (get_option('sa_brevo_api_key') ? 'WP option' : 'not set');
        $list_id = sa_brevo_list_id();
        $sender = sa_brevo_sender_email();
        $account = null;
        $account_error = null;
        if ($configured) {
            $info = SA_Brevo_API::account_info();
            if (!empty($info['ok'])) {
                $account = $info['body'];
            } else {
                $account_error = (string) ($info['error'] ?? 'Could not reach Brevo');
            }
        }
        $smtp = sa_brevo_smtp_config();
        $webhook = rest_url('sa-brevo/v1/webhook');
        ?>
        <div class="wrap">
          <h1>Supreme Brevo</h1>
          <p>Transactional mail for account, password reset, order processing/completed, and invoices. Marketing sync uses list opt-in.</p>

          <table class="widefat striped" style="max-width:720px;margin:1em 0;">
            <tbody>
              <tr><th>Connection</th><td>
                <?php if ($configured && empty($account_error)) : ?>
                  <span style="color:green;font-weight:600;">Connected</span>
                  <?php if (is_array($account)) : ?>
                    — <?php echo esc_html($account['companyName'] ?? ($account['email'] ?? 'account OK')); ?>
                  <?php endif; ?>
                <?php elseif ($configured) : ?>
                  <span style="color:#b32d2e;font-weight:600;">Key set but API error</span>
                  — <?php echo esc_html((string) $account_error); ?>
                <?php else : ?>
                  <span style="color:#996800;font-weight:600;">Not configured</span>
                  — set <code>BREVO_API_KEY</code> on Railway
                <?php endif; ?>
              </td></tr>
              <tr><th>API key source</th><td><code><?php echo esc_html($key_source); ?></code></td></tr>
              <tr><th>List ID</th><td><?php echo $list_id > 0 ? (int) $list_id : '<em>not set (BREVO_LIST_ID)</em>'; ?></td></tr>
              <tr><th>Sender</th><td><?php echo esc_html(sa_brevo_sender_name() . ' &lt;' . $sender . '&gt;'); ?></td></tr>
              <tr><th>SMTP (fallback)</th><td>
                <?php if ($smtp['user'] !== '') : ?>
                  <?php echo esc_html($smtp['host'] . ':' . $smtp['port'] . ' as ' . $smtp['user']); ?>
                <?php else : ?>
                  Optional — set <code>BREVO_SMTP_USER</code> / <code>BREVO_SMTP_PASS</code>
                <?php endif; ?>
              </td></tr>
              <tr><th>Webhook endpoint</th><td><code><?php echo esc_html($webhook); ?></code></td></tr>
              <tr><th>Last send error</th><td><?php
                $last = get_option('sa_brevo_last_send_error');
                echo $last ? esc_html((string) $last) : '—';
              ?></td></tr>
            </tbody>
          </table>

          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:1.5em 0;">
            <?php wp_nonce_field('sa_brevo_save'); ?>
            <input type="hidden" name="action" value="sa_brevo_save" />
            <h2>Optional overrides (prefer Railway env)</h2>
            <table class="form-table" role="presentation">
              <tr>
                <th><label for="sa_brevo_list_id">List ID</label></th>
                <td><input name="sa_brevo_list_id" id="sa_brevo_list_id" type="number" value="<?php echo esc_attr((string) $list_id); ?>" class="regular-text" /></td>
              </tr>
              <tr>
                <th><label for="sa_brevo_sender_email">Sender email</label></th>
                <td><input name="sa_brevo_sender_email" id="sa_brevo_sender_email" type="email" value="<?php echo esc_attr($sender); ?>" class="regular-text" /></td>
              </tr>
              <tr>
                <th><label for="sa_brevo_api_key_opt">API key (option only)</label></th>
                <td>
                  <input name="sa_brevo_api_key" id="sa_brevo_api_key_opt" type="password" value="" class="regular-text" autocomplete="off" placeholder="<?php echo getenv('BREVO_API_KEY') ? 'Using BREVO_API_KEY from env' : 'Paste only if not using env'; ?>" />
                  <p class="description">Leave blank to keep env / existing option. Prefer Railway <code>BREVO_API_KEY</code>.</p>
                </td>
              </tr>
            </table>
            <?php submit_button('Save Brevo settings'); ?>
          </form>

          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('sa_brevo_test_email'); ?>
            <input type="hidden" name="action" value="sa_brevo_test_email" />
            <p>
              <label>Send test to
                <input type="email" name="sa_brevo_test_to" value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>" class="regular-text" required />
              </label>
              <?php submit_button('Send test email', 'secondary', 'submit', false); ?>
            </p>
          </form>
        </div>
        <?php
    }

    public static function handle_save(): void
    {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer('sa_brevo_save')) {
            wp_die('Forbidden');
        }
        $list = isset($_POST['sa_brevo_list_id']) ? (int) $_POST['sa_brevo_list_id'] : 0;
        update_option('sa_brevo_list_id', $list);
        $sender = isset($_POST['sa_brevo_sender_email']) ? sanitize_email(wp_unslash((string) $_POST['sa_brevo_sender_email'])) : '';
        if (is_email($sender)) {
            update_option('sa_brevo_sender_email', $sender);
        }
        $key = isset($_POST['sa_brevo_api_key']) ? trim(wp_unslash((string) $_POST['sa_brevo_api_key'])) : '';
        if ($key !== '') {
            update_option('sa_brevo_api_key', $key);
        }
        wp_safe_redirect(admin_url('admin.php?page=sa-brevo-settings&sa_brevo_saved=1'));
        exit;
    }

    public static function handle_test_email(): void
    {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer('sa_brevo_test_email')) {
            wp_die('Forbidden');
        }
        $to = isset($_POST['sa_brevo_test_to']) ? sanitize_email(wp_unslash((string) $_POST['sa_brevo_test_to'])) : '';
        if (!is_email($to)) {
            wp_safe_redirect(admin_url('admin.php?page=sa-brevo-settings&sa_brevo_test=fail'));
            exit;
        }
        $ok = wp_mail(
            $to,
            'Supreme Autoparts — Brevo test',
            '<p>This is a <strong>Brevo transactional</strong> test from Supreme Autoparts.</p><p>If you received this, API/SMTP routing works.</p>',
            ['Content-Type: text/html; charset=UTF-8']
        );
        wp_safe_redirect(admin_url('admin.php?page=sa-brevo-settings&sa_brevo_test=' . ($ok ? 'ok' : 'fail')));
        exit;
    }
}
