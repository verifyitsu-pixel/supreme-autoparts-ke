<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sync customers to Brevo list on register / checkout when marketing opt-in is checked.
 */
class SA_Brevo_Sync
{
    public static function init(): void
    {
        add_action('woocommerce_register_form', [self::class, 'render_optin_checkbox']);
        add_action('woocommerce_edit_account_form', [self::class, 'render_account_optin']);
        add_action('woocommerce_checkout_terms_and_conditions', [self::class, 'render_checkout_optin'], 20);

        add_action('woocommerce_created_customer', [self::class, 'on_customer_created'], 20, 3);
        add_action('woocommerce_checkout_order_processed', [self::class, 'on_checkout_order'], 20, 3);
        add_action('woocommerce_save_account_details', [self::class, 'on_account_save'], 20, 1);

        add_action('user_register', [self::class, 'on_wp_user_register'], 20, 1);
    }

    public static function render_optin_checkbox(): void
    {
        ?>
        <p class="woocommerce-form-row form-row">
          <label class="woocommerce-form__label woocommerce-form__label-for-checkbox inline">
            <input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox"
                   name="sa_brevo_optin" value="1" <?php checked(!empty($_POST['sa_brevo_optin'])); ?> />
            <span><?php esc_html_e('Email me offers, new parts, and store updates from Supreme Autoparts.', 'sa-brevo-mail'); ?></span>
          </label>
        </p>
        <?php
    }

    public static function render_account_optin(): void
    {
        $user_id = get_current_user_id();
        $opted = $user_id ? (bool) get_user_meta($user_id, 'sa_brevo_optin', true) : false;
        ?>
        <p class="woocommerce-form-row form-row">
          <label class="woocommerce-form__label woocommerce-form__label-for-checkbox inline">
            <input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox"
                   name="sa_brevo_optin" value="1" <?php checked($opted); ?> />
            <span><?php esc_html_e('Receive marketing emails from Supreme Autoparts (Brevo).', 'sa-brevo-mail'); ?></span>
          </label>
        </p>
        <?php
    }

    public static function render_checkout_optin(): void
    {
        woocommerce_form_field('sa_brevo_optin', [
            'type'  => 'checkbox',
            'class' => ['form-row-wide'],
            'label' => __('Email me offers and store updates from Supreme Autoparts.', 'sa-brevo-mail'),
        ], !empty($_POST['sa_brevo_optin']));
    }

    public static function on_customer_created(int $customer_id, array $new_customer_data, bool $password_generated): void
    {
        $optin = !empty($_POST['sa_brevo_optin']);
        update_user_meta($customer_id, 'sa_brevo_optin', $optin ? '1' : '0');
        if ($optin) {
            self::sync_user($customer_id);
        }
        unset($password_generated, $new_customer_data);
    }

    public static function on_wp_user_register(int $user_id): void
    {
        if (!empty($_POST['sa_brevo_optin'])) {
            update_user_meta($user_id, 'sa_brevo_optin', '1');
            self::sync_user($user_id);
        }
    }

    /**
     * @param WC_Order|false $order
     */
    public static function on_checkout_order($order_id, $posted_data, $order): void
    {
        $optin = !empty($_POST['sa_brevo_optin']) || (!empty($posted_data['sa_brevo_optin']));
        if (!$optin) {
            return;
        }
        $email = '';
        $user_id = 0;
        if ($order instanceof WC_Order) {
            $email = $order->get_billing_email();
            $user_id = $order->get_user_id();
            $order->update_meta_data('_sa_brevo_optin', '1');
            $order->save();
        }
        if ($user_id) {
            update_user_meta($user_id, 'sa_brevo_optin', '1');
            self::sync_user($user_id);
            return;
        }
        if (is_email($email)) {
            self::sync_email($email, [
                'FIRSTNAME' => $order instanceof WC_Order ? $order->get_billing_first_name() : '',
                'LASTNAME'  => $order instanceof WC_Order ? $order->get_billing_last_name() : '',
            ]);
        }
        unset($order_id);
    }

    public static function on_account_save(int $user_id): void
    {
        $optin = !empty($_POST['sa_brevo_optin']);
        update_user_meta($user_id, 'sa_brevo_optin', $optin ? '1' : '0');
        if ($optin) {
            self::sync_user($user_id);
        }
    }

    public static function sync_user(int $user_id): void
    {
        $user = get_userdata($user_id);
        if (!$user || !is_email($user->user_email)) {
            return;
        }
        $attrs = [
            'FIRSTNAME' => (string) get_user_meta($user_id, 'first_name', true),
            'LASTNAME'  => (string) get_user_meta($user_id, 'last_name', true),
        ];
        $billing_first = (string) get_user_meta($user_id, 'billing_first_name', true);
        $billing_last  = (string) get_user_meta($user_id, 'billing_last_name', true);
        if ($billing_first !== '') {
            $attrs['FIRSTNAME'] = $billing_first;
        }
        if ($billing_last !== '') {
            $attrs['LASTNAME'] = $billing_last;
        }
        self::sync_email($user->user_email, $attrs);
        update_user_meta($user_id, 'sa_brevo_synced_at', time());
    }

    public static function sync_email(string $email, array $attributes = []): void
    {
        if (!sa_brevo_is_configured() || !is_email($email)) {
            return;
        }
        $list_id = sa_brevo_list_id();
        $lists = $list_id > 0 ? [$list_id] : [];
        $result = SA_Brevo_API::upsert_contact($email, array_filter($attributes), $lists);
        if (empty($result['ok']) && ($result['code'] ?? 0) !== 204) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[sa-brevo] contact sync failed for ' . $email . ': ' . ($result['error'] ?? ''));
        }
    }
}
