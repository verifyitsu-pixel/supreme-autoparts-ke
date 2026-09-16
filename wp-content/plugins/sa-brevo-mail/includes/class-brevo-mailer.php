<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Route wp_mail / WooCommerce emails through Brevo Transactional API.
 * Falls back to Brevo SMTP (phpmailer_init) when API key missing but SMTP creds set.
 */
class SA_Brevo_Mailer
{
    public static function init(): void
    {
        // Short-circuit wp_mail when API key is present.
        add_filter('pre_wp_mail', [self::class, 'send_via_brevo_api'], 10, 2);

        // SMTP fallback / enrichment when using PHPMailer path.
        add_action('phpmailer_init', [self::class, 'configure_phpmailer'], 20);

        // Ensure Woo From identity matches Brevo sender.
        add_filter('woocommerce_email_from_address', static function ($email) {
            $sender = sa_brevo_sender_email();
            return is_email($sender) ? $sender : $email;
        }, 20);
        add_filter('woocommerce_email_from_name', static function () {
            return sa_brevo_sender_name();
        }, 20);
    }

    /**
     * @param null|bool $return
     * @param array{to:string|array,subject:string,message:string,headers:string|array,attachments:array} $atts
     * @return null|bool
     */
    public static function send_via_brevo_api($return, $atts)
    {
        if ($return !== null) {
            return $return;
        }
        if (!sa_brevo_is_configured()) {
            return null; // fall through to default / SMTP
        }

        $to_raw = $atts['to'] ?? '';
        $subject = (string) ($atts['subject'] ?? '');
        $message = (string) ($atts['message'] ?? '');
        $headers = $atts['headers'] ?? [];
        $attachments = $atts['attachments'] ?? [];

        $recipients = self::parse_recipients($to_raw);
        if ($recipients === []) {
            return false;
        }

        $is_html = self::message_is_html($message, $headers);
        $payload = [
            'to'      => $recipients,
            'subject' => $subject !== '' ? $subject : '(no subject)',
            'tags'    => self::infer_tags($subject),
        ];
        if ($is_html) {
            $payload['htmlContent'] = $message;
            $payload['textContent'] = wp_strip_all_tags($message);
        } else {
            $payload['textContent'] = $message;
            $payload['htmlContent'] = nl2br(esc_html($message));
        }

        $cc = self::parse_header_addresses($headers, 'cc');
        $bcc = self::parse_header_addresses($headers, 'bcc');
        $reply = self::parse_reply_to($headers);
        if ($cc) {
            $payload['cc'] = $cc;
        }
        if ($bcc) {
            $payload['bcc'] = $bcc;
        }
        if ($reply) {
            $payload['replyTo'] = $reply;
        }

        // Brevo supports attachment base64; skip large/complex for now unless small.
        $brevo_atts = self::prepare_attachments(is_array($attachments) ? $attachments : []);
        if ($brevo_atts) {
            $payload['attachment'] = $brevo_atts;
        }

        $result = SA_Brevo_API::send_transactional($payload);
        if (!empty($result['ok'])) {
            update_option('sa_brevo_last_send_ok', time());
            delete_option('sa_brevo_last_send_error');
            return true;
        }

        $err = (string) ($result['error'] ?? 'unknown');
        update_option('sa_brevo_last_send_error', $err);
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log('[sa-brevo] send failed: ' . $err);

        // Fall through to PHPMailer/SMTP if API failed.
        return null;
    }

    public static function configure_phpmailer($phpmailer): void
    {
        $smtp = sa_brevo_smtp_config();
        if ($smtp['user'] !== '' && $smtp['pass'] !== '') {
            $phpmailer->isSMTP();
            $phpmailer->Host       = $smtp['host'];
            $phpmailer->Port       = $smtp['port'];
            $phpmailer->SMTPAuth   = true;
            $phpmailer->Username   = $smtp['user'];
            $phpmailer->Password   = $smtp['pass'];
            $secure = strtolower($smtp['secure']);
            if (in_array($secure, ['tls', 'ssl'], true)) {
                $phpmailer->SMTPSecure = $secure;
            }
        }

        $phpmailer->From     = sa_brevo_sender_email();
        $phpmailer->FromName = sa_brevo_sender_name();
        $phpmailer->Sender   = sa_brevo_sender_email();
    }

    /**
     * @param string|array $to
     * @return list<array{email:string,name?:string}>
     */
    private static function parse_recipients($to): array
    {
        $list = [];
        if (is_array($to)) {
            foreach ($to as $item) {
                $list = array_merge($list, self::parse_recipients((string) $item));
            }
            return $list;
        }
        $parts = preg_split('/\s*,\s*/', (string) $to) ?: [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^(.*?)\s*<([^>]+)>$/', $part, $m)) {
                $email = sanitize_email($m[2]);
                $name  = trim($m[1], " \t\"'");
                if (is_email($email)) {
                    $row = ['email' => $email];
                    if ($name !== '') {
                        $row['name'] = $name;
                    }
                    $list[] = $row;
                }
            } elseif (is_email($part)) {
                $list[] = ['email' => sanitize_email($part)];
            }
        }
        return $list;
    }

    /**
     * @param string|array $headers
     * @return list<array{email:string,name?:string}>
     */
    private static function parse_header_addresses($headers, string $which): array
    {
        $lines = self::normalize_headers($headers);
        $out = [];
        foreach ($lines as $line) {
            if (stripos($line, $which . ':') === 0) {
                $val = trim(substr($line, strlen($which) + 1));
                $out = array_merge($out, self::parse_recipients($val));
            }
        }
        return $out;
    }

    /**
     * @param string|array $headers
     * @return array{email:string,name?:string}|null
     */
    private static function parse_reply_to($headers): ?array
    {
        $lines = self::normalize_headers($headers);
        foreach ($lines as $line) {
            if (stripos($line, 'reply-to:') === 0) {
                $parsed = self::parse_recipients(trim(substr($line, 9)));
                return $parsed[0] ?? null;
            }
        }
        return null;
    }

    /**
     * @param string|array $headers
     * @return list<string>
     */
    private static function normalize_headers($headers): array
    {
        if (is_array($headers)) {
            return array_map('strval', $headers);
        }
        $split = preg_split('/\r\n|\r|\n/', (string) $headers) ?: [];
        return array_values(array_filter(array_map('trim', $split)));
    }

    /**
     * @param string|array $headers
     */
    private static function message_is_html(string $message, $headers): bool
    {
        foreach (self::normalize_headers($headers) as $line) {
            if (stripos($line, 'content-type:') === 0 && stripos($line, 'text/html') !== false) {
                return true;
            }
        }
        return (bool) preg_match('/<(html|body|table|div|p|br\s*\/?)[\s>]/i', $message);
    }

    /**
     * @return list<string>
     */
    private static function infer_tags(string $subject): array
    {
        $tags = ['supreme-autoparts', 'woocommerce'];
        $s = strtolower($subject);
        if (str_contains($s, 'password')) {
            $tags[] = 'password-reset';
        } elseif (str_contains($s, 'account') || str_contains($s, 'welcome')) {
            $tags[] = 'account-created';
        } elseif (str_contains($s, 'processing') || str_contains($s, 'order')) {
            $tags[] = 'order';
        } elseif (str_contains($s, 'complete') || str_contains($s, 'completed')) {
            $tags[] = 'order-completed';
        } elseif (str_contains($s, 'invoice')) {
            $tags[] = 'invoice';
        }
        return $tags;
    }

    /**
     * @param list<string> $paths
     * @return list<array{name:string,content:string}>
     */
    private static function prepare_attachments(array $paths): array
    {
        $out = [];
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '' || !is_readable($path)) {
                continue;
            }
            $size = filesize($path);
            if ($size === false || $size > 5 * 1024 * 1024) {
                continue;
            }
            $data = file_get_contents($path);
            if ($data === false) {
                continue;
            }
            $out[] = [
                'name'    => basename($path),
                'content' => base64_encode($data),
            ];
        }
        return $out;
    }
}
