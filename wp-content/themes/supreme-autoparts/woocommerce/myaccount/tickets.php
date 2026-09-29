<?php
/**
 * My Account — Support / Tickets
 *
 * @package Supreme_Autoparts
 */

defined('ABSPATH') || exit;

if (!is_user_logged_in()) {
    echo '<p>' . esc_html__('Please log in to view tickets.', 'supreme-autoparts') . '</p>';
    return;
}

$user = wp_get_current_user();
$view = isset($_GET['ticket']) ? absint($_GET['ticket']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$open_notice = '';
if (!$view && isset($_POST['sa_customer_open_ticket']) && check_admin_referer('sa_customer_open_ticket')) {
    $subject = sanitize_text_field(wp_unslash((string) ($_POST['ticket_subject'] ?? '')));
    $body    = sanitize_textarea_field(wp_unslash((string) ($_POST['ticket_body'] ?? '')));
    $user    = wp_get_current_user();
    if ($subject !== '' && $body !== '' && function_exists('sa_core_ticket_create')) {
        $tid = sa_core_ticket_create([
            'email'   => (string) $user->user_email,
            'name'    => trim($user->first_name . ' ' . $user->last_name) ?: (string) $user->display_name,
            'subject' => $subject,
            'body'    => $body,
            'source'  => 'my_account',
            'user_id' => (int) $user->ID,
        ]);
        if ($tid) {
            wp_safe_redirect(add_query_arg('ticket', $tid, wc_get_account_endpoint_url('tickets')));
            exit;
        }
        $open_notice = __('Could not open ticket. Please try again.', 'supreme-autoparts');
    } else {
        $open_notice = __('Subject and message are required.', 'supreme-autoparts');
    }
}

?>
<div class="sa-account-panel sa-tickets">
<?php if ($view && function_exists('sa_core_ticket_get')) :
    $ticket = sa_core_ticket_get($view);
    if (!$ticket || !function_exists('sa_core_ticket_user_can_access') || !sa_core_ticket_user_can_access($ticket, $user)) :
        echo '<p>' . esc_html__('Ticket not found.', 'supreme-autoparts') . '</p></div>';
        return;
    endif;

    if (isset($_POST['sa_customer_ticket_reply']) && check_admin_referer('sa_customer_ticket_' . $view)) {
        $body = sanitize_textarea_field(wp_unslash((string) ($_POST['reply_body'] ?? '')));
        if ($body !== '') {
            sa_core_ticket_add_reply($view, $body, (int) $user->ID, false);
            echo '<div class="woocommerce-message" role="status">' . esc_html__('Reply sent.', 'supreme-autoparts') . '</div>';
            $ticket = sa_core_ticket_get($view);
        }
    }
    $replies = sa_core_ticket_replies($view);
    ?>
  <p><a href="<?php echo esc_url(wc_get_account_endpoint_url('tickets')); ?>">&larr; <?php esc_html_e('All tickets', 'supreme-autoparts'); ?></a></p>
  <header class="sa-account-panel__head">
    <h2>#<?php echo esc_html((string) $ticket->id); ?> — <?php echo esc_html((string) $ticket->subject); ?></h2>
    <p class="sa-account-panel__lead">
      <?php esc_html_e('Status:', 'supreme-autoparts'); ?>
      <strong><?php echo esc_html((string) $ticket->status); ?></strong>
    </p>
  </header>
  <div class="sa-ticket-thread">
    <?php foreach ($replies as $rep) :
        $who = ((int) $rep->is_staff) ? __('Support team', 'supreme-autoparts') : __('You', 'supreme-autoparts');
        ?>
      <div class="sa-ticket-msg<?php echo ((int) $rep->is_staff) ? ' sa-ticket-msg--staff' : ''; ?>">
        <div class="sa-ticket-msg__meta"><strong><?php echo esc_html($who); ?></strong> · <?php echo esc_html((string) $rep->created_at); ?></div>
        <div class="sa-ticket-msg__body"><?php echo nl2br(esc_html((string) $rep->body)); ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if ((string) $ticket->status !== 'closed') : ?>
    <form method="post" class="sa-ticket-reply-form" style="margin-top:1.25rem;">
      <?php wp_nonce_field('sa_customer_ticket_' . $view); ?>
      <p>
        <label for="sa_ticket_reply_body"><?php esc_html_e('Reply', 'supreme-autoparts'); ?></label>
        <textarea id="sa_ticket_reply_body" name="reply_body" rows="4" required></textarea>
      </p>
      <p>
        <button type="submit" class="button" name="sa_customer_ticket_reply" value="1">
          <?php esc_html_e('Send reply', 'supreme-autoparts'); ?>
        </button>
      </p>
    </form>
  <?php endif; ?>

<?php else :
    $list = function_exists('sa_core_tickets_list')
        ? sa_core_tickets_list(['user_id' => (int) $user->ID, 'limit' => 50])
        : [];
    if (function_exists('sa_core_tickets_list') && is_email($user->user_email)) {
        $by_email = sa_core_tickets_list(['email' => $user->user_email, 'limit' => 50]);
        $seen = [];
        $merged = [];
        foreach (array_merge($list, $by_email) as $row) {
            if (isset($seen[(int) $row->id])) {
                continue;
            }
            $seen[(int) $row->id] = true;
            $merged[] = $row;
        }
        $list = $merged;
    }
    ?>

  <?php if ($open_notice) : ?>
    <div class="woocommerce-error" role="alert"><?php echo esc_html($open_notice); ?></div>
  <?php endif; ?>
  <details class="sa-ticket-open">
    <summary><?php esc_html_e('Open a ticket', 'supreme-autoparts'); ?></summary>
    <form method="post" class="sa-ticket-open-form">
      <?php wp_nonce_field('sa_customer_open_ticket'); ?>
      <p>
        <label for="sa_ticket_subject"><?php esc_html_e('Subject', 'supreme-autoparts'); ?></label>
        <input type="text" id="sa_ticket_subject" name="ticket_subject" required maxlength="190" />
      </p>
      <p>
        <label for="sa_ticket_body"><?php esc_html_e('Message', 'supreme-autoparts'); ?></label>
        <textarea id="sa_ticket_body" name="ticket_body" rows="4" required></textarea>
      </p>
      <p>
        <button type="submit" class="button sa-btn" name="sa_customer_open_ticket" value="1">
          <?php esc_html_e('Submit ticket', 'supreme-autoparts'); ?>
        </button>
      </p>
    </form>
  </details>
  <header class="sa-account-panel__head">
    <h2><?php esc_html_e('Support / Tickets', 'supreme-autoparts'); ?></h2>
    <p class="sa-account-panel__lead">
      <?php esc_html_e('Messages from Contact us and replies from our team appear here.', 'supreme-autoparts'); ?>
    </p>
  </header>
  <?php if (!$list) : ?>
    <p><?php esc_html_e('No tickets yet. Use the Contact us form in the footer or Enquire to open one.', 'supreme-autoparts'); ?></p>
  <?php else : ?>
    <ul class="sa-ticket-list">
      <?php foreach ($list as $row) :
          $url = add_query_arg('ticket', (int) $row->id, wc_get_account_endpoint_url('tickets'));
          ?>
        <li>
          <a href="<?php echo esc_url($url); ?>">
            #<?php echo esc_html((string) $row->id); ?> — <?php echo esc_html((string) $row->subject); ?>
          </a>
          <span class="sa-muted">(<?php echo esc_html((string) $row->status); ?> · <?php echo esc_html((string) $row->updated_at); ?>)</span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
<?php endif; ?>
</div>
