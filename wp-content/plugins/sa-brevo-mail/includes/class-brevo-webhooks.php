<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST stubs for Brevo campaign / transactional event webhooks.
 * Endpoint: POST /wp-json/sa-brevo/v1/webhook
 */
class SA_Brevo_Webhooks
{
    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void
    {
        register_rest_route('sa-brevo/v1', '/webhook', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handle'],
            'permission_callback' => [self::class, 'permission'],
        ]);
        register_rest_route('sa-brevo/v1', '/events', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'list_events'],
            'permission_callback' => static function () {
                return current_user_can('manage_woocommerce');
            },
        ]);
    }

    public static function permission(\WP_REST_Request $request): bool
    {
        $secret = getenv('BREVO_WEBHOOK_SECRET') ?: (string) get_option('sa_brevo_webhook_secret', '');
        if ($secret === '') {
            // Allow when no secret configured (stub mode) — log only.
            return true;
        }
        $hdr = (string) $request->get_header('x-sa-brevo-secret');
        if ($hdr !== '' && hash_equals($secret, $hdr)) {
            return true;
        }
        $q = (string) $request->get_param('secret');
        return $q !== '' && hash_equals($secret, $q);
    }

    public static function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            $payload = ['raw' => $request->get_body()];
        }
        $event = [
            'received_at' => gmdate('c'),
            'event'       => $payload['event'] ?? $payload['type'] ?? 'unknown',
            'email'       => $payload['email'] ?? null,
            'payload'     => $payload,
        ];
        $log = get_option('sa_brevo_webhook_log', []);
        if (!is_array($log)) {
            $log = [];
        }
        array_unshift($log, $event);
        $log = array_slice($log, 0, 50);
        update_option('sa_brevo_webhook_log', $log, false);

        /**
         * Stub hook for campaign events (delivered, opened, clicked, unsubscribed, softBounce, hardBounce, spam, etc.).
         *
         * @param array $event
         * @param array $payload
         */
        do_action('sa_brevo_campaign_event', $event, $payload);

        return new \WP_REST_Response(['ok' => true, 'stored' => true], 200);
    }

    public static function list_events(): \WP_REST_Response
    {
        $log = get_option('sa_brevo_webhook_log', []);
        return new \WP_REST_Response(['events' => is_array($log) ? $log : []], 200);
    }
}
