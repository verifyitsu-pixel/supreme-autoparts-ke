<?php
/**
 * Plugin Name: Supreme Brevo Mail
 * Description: Brevo (Sendinblue) transactional email + contact sync for Supreme Autoparts WooCommerce.
 * Version: 1.0.0
 * Author: Supreme Autoparts
 * Text Domain: sa-brevo-mail
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('SA_BREVO_VERSION', '1.0.0');
define('SA_BREVO_FILE', __FILE__);
define('SA_BREVO_DIR', plugin_dir_path(__FILE__));
define('SA_BREVO_URL', plugin_dir_url(__FILE__));

require_once SA_BREVO_DIR . 'includes/class-brevo-api.php';
require_once SA_BREVO_DIR . 'includes/class-brevo-mailer.php';
require_once SA_BREVO_DIR . 'includes/class-brevo-sync.php';
require_once SA_BREVO_DIR . 'includes/class-brevo-webhooks.php';
require_once SA_BREVO_DIR . 'includes/admin-settings.php';

/**
 * Resolve Brevo API key from env (Railway) or WP option.
 */
function sa_brevo_api_key(): string
{
    $env = getenv('BREVO_API_KEY');
    if (is_string($env) && $env !== '') {
        return trim($env);
    }
    $opt = (string) get_option('sa_brevo_api_key', '');
    return trim($opt);
}

function sa_brevo_list_id(): int
{
    $env = getenv('BREVO_LIST_ID');
    if (is_string($env) && ctype_digit(trim($env))) {
        return (int) trim($env);
    }
    return (int) get_option('sa_brevo_list_id', 0);
}

function sa_brevo_sender_email(): string
{
    $env = getenv('BREVO_SENDER_EMAIL') ?: getenv('SUPREME_STORE_EMAIL') ?: getenv('WORDPRESS_ADMIN_EMAIL');
    if (is_string($env) && is_email($env)) {
        return $env;
    }
    $opt = (string) get_option('sa_brevo_sender_email', '');
    if (is_email($opt)) {
        return $opt;
    }
    return 'calvin@supremeautoparts.co.ke';
}

function sa_brevo_sender_name(): string
{
    $env = getenv('BREVO_SENDER_NAME');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    return 'Supreme Autoparts';
}

/**
 * Optional SMTP credentials (documented for WP Mail SMTP / phpmailer fallback).
 *
 * @return array{host:string,port:int,user:string,pass:string,secure:string}
 */
function sa_brevo_smtp_config(): array
{
    return [
        'host'   => (string) (getenv('BREVO_SMTP_HOST') ?: 'smtp-relay.brevo.com'),
        'port'   => (int) (getenv('BREVO_SMTP_PORT') ?: 587),
        'user'   => (string) (getenv('BREVO_SMTP_USER') ?: getenv('BREVO_SMTP_LOGIN') ?: ''),
        'pass'   => (string) (getenv('BREVO_SMTP_PASS') ?: getenv('BREVO_SMTP_KEY') ?: getenv('BREVO_SMTP_PASSWORD') ?: ''),
        'secure' => (string) (getenv('BREVO_SMTP_SECURE') ?: 'tls'),
    ];
}

function sa_brevo_is_configured(): bool
{
    return sa_brevo_api_key() !== '';
}

register_activation_hook(__FILE__, static function (): void {
    if (sa_brevo_list_id() <= 0) {
        $env = getenv('BREVO_LIST_ID');
        if (is_string($env) && ctype_digit(trim($env))) {
            update_option('sa_brevo_list_id', (int) trim($env));
        }
    }
    update_option('sa_brevo_sender_email', sa_brevo_sender_email());
    flush_rewrite_rules();
});

add_action('plugins_loaded', static function (): void {
    SA_Brevo_Mailer::init();
    SA_Brevo_Sync::init();
    SA_Brevo_Webhooks::init();
    SA_Brevo_Admin_Settings::init();
}, 20);

add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            SA_BREVO_FILE,
            true
        );
    }
});
