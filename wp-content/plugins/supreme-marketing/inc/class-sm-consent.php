<?php
/**
 * Consent capture: footer/newsletter form (double opt-in), checkout opt-in checkbox,
 * and account registration. Marketing consent always requires an explicit action.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class SM_Consent
{
    public static function init(): void
    {
        add_action('init', [self::class, 'register_shortcode'], 20);
        add_action('admin_post_sm_subscribe', [self::class, 'handle_subscribe']);
        add_action('admin_post_nopriv_sm_subscribe', [self::class, 'handle_subscribe']);
        add_filter('woocommerce_checkout_fields', [self::class, 'checkout_field']);
    }

    public static function register_shortcode(): void
    {
        add_shortcode('supreme_newsletter', [self::class, 'form_html']);
        add_shortcode('sm_subscribe', [self::class, 'form_html']);
    }

    public static function form_html(): string
    {
        $joined = isset($_GET['sm_joined']) ? sanitize_key((string) $_GET['sm_joined']) : '';
        $note = '';
        if ($joined === 'confirm') {
            $note = '<p><small>Almost done - check your inbox and click the confirmation link.</small></p>';
        } elseif ($joined === 'invalid') {
            $note = '<p><small>That email address does not look right. Try again?</small></p>';
        }
        $action = esc_url(admin_url('admin-post.php'));
        $nonce = wp_nonce_field('sm_subscribe', 'sm_nonce', true, false);
        return '<form class="sm-newsletter-form" action="' . $action . '" method="post">'
            . $nonce
            . '<input type="hidden" name="action" value="sm_subscribe">'
            . '<label>Email address<input type="email" name="sm_email" required autocomplete="email"></label>'
            . '<button class="sm-newsletter-button" type="submit">Join the list</button>'
            . '<p><small>New parts, restocks and offers, about once a week. Unsubscribe anytime.</small></p>'
            . $note
            . '</form>';
    }

    public static function handle_subscribe(): void
    {
        $back = wp_get_referer() ?: home_url('/');
        if (!isset($_POST['sm_nonce']) || !wp_verify_nonce(sanitize_text_field((string) $_POST['sm_nonce']), 'sm_subscribe')) {
            wp_safe_redirect(add_query_arg('sm_joined', 'invalid', $back));
            exit;
        }
        $email = strtolower(trim(sanitize_email((string) ($_POST['sm_email'] ?? ''))));
        if (!is_email($email)) {
            wp_safe_redirect(add_query_arg('sm_joined', 'invalid', $back));
            exit;
        }
        self::begin_double_optin($email, 'newsletter_form');
        $back = remove_query_arg('sm_joined', $back);
        wp_safe_redirect(add_query_arg('sm_joined', 'confirm', $back));
        exit;
    }

    /** Create/refresh the subscriber as unconfirmed and queue the confirmation email. */
    public static function begin_double_optin(string $email, string $source): void
    {
        $sub = SM_Subscribers::ensure($email, ['source' => $source]);
        if (!$sub) {
            return;
        }
        if ($sub['status'] === 'active') {
            return; // already confirmed; nothing to do
        }
        SM_Subscribers::mark_unconfirmed($email);
        SM_Queue::add([
            'subscriber_id' => (int) $sub['id'],
            'email'         => $email,
            'flow'          => 'consent',
            'step'          => 'confirm_optin',
            'template'      => 'confirm_optin',
            'payload'       => ['email' => $email, 'first_name' => $sub['first_name'] ?? ''],
            'send_at'       => sm_now(),
            'dedupe_key'    => 'confirm|' . $email . '|' . gmdate('Y-m-d'),
        ]);
    }

    /** Unchecked-by-default marketing opt-in at checkout (GDPR/PECR-safe). */
    public static function checkout_field(array $fields): array
    {
        $fields['order']['sm_marketing_optin'] = [
            'type'     => 'checkbox',
            'label'    => 'Email me about new parts, offers and service reminders. Unsubscribe anytime.',
            'required' => false,
            'class'    => ['form-row-wide'],
            'priority' => 120,
        ];
        return $fields;
    }

    /** Called from SM_Events when an order is created: apply the checkbox choice. */
    public static function apply_checkout_choice(string $email, bool $opted_in, array $args = []): void
    {
        $sub = SM_Subscribers::ensure($email, $args + ['source' => 'checkout']);
        if (!$sub) {
            return;
        }
        if ($opted_in) {
            SM_Subscribers::activate($email, 'checkout_optin');
            $fresh = SM_Subscribers::get_by_email($email);
            if ($fresh) {
                SM_Flows::start($fresh, 'welcome', 'once', []);
            }
        }
    }
}


/** Styled subscribe block for the theme footer (called from footer.php when the plugin is active). */
function sm_footer_subscribe_form(): void
{
    $joined = isset($_GET['sm_joined']) ? sanitize_key((string) $_GET['sm_joined']) : '';
    echo '<div class="sm-foot-sub" style="margin-top:1rem;max-width:340px;">'
        . '<p style="margin:0 0 .5rem;font-weight:700;font-size:.95rem;color:#F4F4F5;">New parts, restocks & offers</p>'
        . '<form action="' . esc_url(admin_url('admin-post.php')) . '" method="post" style="display:flex;gap:.5rem;">'
        . wp_nonce_field('sm_subscribe', 'sm_nonce', true, false)
        . '<input type="hidden" name="action" value="sm_subscribe">'
        . '<input type="email" name="sm_email" required autocomplete="email" placeholder="Your email" '
        . 'style="flex:1;min-width:0;padding:.6rem .8rem;border-radius:8px;border:1px solid rgba(244,244,245,.25);background:rgba(244,244,245,.08);color:#F4F4F5;font-size:.9rem;">'
        . '<button type="submit" style="padding:.6rem 1rem;border-radius:8px;border:0;background:#F5A623;color:#111;font-weight:700;font-size:.9rem;cursor:pointer;">Join</button>'
        . '</form>';
    if ($joined === 'confirm') {
        echo '<p style="margin:.5rem 0 0;font-size:.8rem;color:#F5A623;">Almost done - check your inbox and click the confirmation link.</p>';
    } elseif ($joined === 'invalid') {
        echo '<p style="margin:.5rem 0 0;font-size:.8rem;color:#F5A623;">That email address does not look right. Try again?</p>';
    } else {
        echo '<p style="margin:.5rem 0 0;font-size:.78rem;color:rgba(244,244,245,.55);">About one email a week. Unsubscribe anytime.</p>';
    }
    echo '</div>';
}
