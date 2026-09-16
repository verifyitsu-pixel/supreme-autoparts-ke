<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thin Brevo REST client (api.brevo.com/v3).
 */
class SA_Brevo_API
{
    private const BASE = 'https://api.brevo.com/v3';

    public static function request(string $method, string $path, ?array $body = null): array
    {
        $key = sa_brevo_api_key();
        if ($key === '') {
            return [
                'ok'     => false,
                'code'   => 0,
                'error'  => 'BREVO_API_KEY not set',
                'body'   => null,
            ];
        }

        $url = self::BASE . '/' . ltrim($path, '/');
        $args = [
            'method'  => strtoupper($method),
            'timeout' => 20,
            'headers' => [
                'api-key'      => $key,
                'accept'       => 'application/json',
                'content-type' => 'application/json',
            ],
        ];
        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return [
                'ok'    => false,
                'code'  => 0,
                'error' => $response->get_error_message(),
                'body'  => null,
            ];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);

        return [
            'ok'    => $code >= 200 && $code < 300,
            'code'  => $code,
            'error' => ($code >= 200 && $code < 300) ? null : ($decoded['message'] ?? $raw),
            'body'  => is_array($decoded) ? $decoded : null,
            'raw'   => $raw,
        ];
    }

    /**
     * Send a transactional email via Brevo SMTP API.
     *
     * @param array{to:array<int,array{email:string,name?:string}>,subject:string,htmlContent?:string,textContent?:string,replyTo?:array{email:string,name?:string},tags?:list<string>} $payload
     */
    public static function send_transactional(array $payload): array
    {
        $payload['sender'] = [
            'name'  => sa_brevo_sender_name(),
            'email' => sa_brevo_sender_email(),
        ];
        if (empty($payload['tags'])) {
            $payload['tags'] = ['supreme-autoparts', 'woocommerce'];
        }
        return self::request('POST', '/smtp/email', $payload);
    }

    /**
     * Create or update a contact and optionally add to list.
     */
    public static function upsert_contact(string $email, array $attributes = [], array $list_ids = []): array
    {
        $body = [
            'email'         => $email,
            'updateEnabled' => true,
        ];
        if ($attributes) {
            $body['attributes'] = $attributes;
        }
        if ($list_ids) {
            $body['listIds'] = array_values(array_map('intval', $list_ids));
        }
        return self::request('POST', '/contacts', $body);
    }

    public static function account_info(): array
    {
        return self::request('GET', '/account');
    }

    public static function get_lists(int $limit = 50): array
    {
        return self::request('GET', '/contacts/lists?limit=' . max(1, min(50, $limit)) . '&offset=0');
    }
}
