<?php
/**
 * Resolve visitor country → display currency.
 * Prefers Cloudflare CF-IPCountry; falls back to free geo API (cached).
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class SA_Geo_Detector
{
    private const COOKIE = 'sa_geo_cc';
    private const TRANSIENT_PREFIX = 'sa_geo_ip_';

    /** @var array{country:string,currency:string}|null */
    private static ?array $resolved = null;

    /**
     * ISO-3166-1 alpha-2 → ISO-4217.
     * KE → KES; common markets covered; unknown → USD.
     *
     * @return array<string,string>
     */
    public static function country_currency_map(): array
    {
        return [
            'KE' => 'KES',
            'UG' => 'UGX',
            'TZ' => 'TZS',
            'RW' => 'RWF',
            'US' => 'USD',
            'GB' => 'GBP',
            'EU' => 'EUR', // Cloudflare may send XX / T1; EU not a country but keep.
            'DE' => 'EUR',
            'FR' => 'EUR',
            'IT' => 'EUR',
            'ES' => 'EUR',
            'NL' => 'EUR',
            'BE' => 'EUR',
            'AT' => 'EUR',
            'IE' => 'EUR',
            'PT' => 'EUR',
            'FI' => 'EUR',
            'GR' => 'EUR',
            'AE' => 'AED',
            'SA' => 'SAR',
            'IN' => 'INR',
            'ZA' => 'ZAR',
            'NG' => 'NGN',
            'GH' => 'GHS',
            'CA' => 'CAD',
            'AU' => 'AUD',
            'NZ' => 'NZD',
            'JP' => 'JPY',
            'CN' => 'CNY',
            'HK' => 'HKD',
            'SG' => 'SGD',
            'CH' => 'CHF',
            'SE' => 'SEK',
            'NO' => 'NOK',
            'DK' => 'DKK',
            'PL' => 'PLN',
            'BR' => 'BRL',
            'MX' => 'MXN',
            'TR' => 'TRY',
            'EG' => 'EGP',
            'IL' => 'ILS',
            'KR' => 'KRW',
            'TH' => 'THB',
            'MY' => 'MYR',
            'PH' => 'PHP',
            'ID' => 'IDR',
            'PK' => 'PKR',
            'BD' => 'BDT',
        ];
    }

    /**
     * @return array{country:string,currency:string}
     */
    public static function resolve(): array
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        $checkout = function_exists('sa_geo_checkout_currency') ? sa_geo_checkout_currency() : 'USD';
        $country  = self::detect_country();
        $map      = self::country_currency_map();
        $currency = $map[$country] ?? $checkout;

        // If rates unavailable for this currency, display layer falls back to USD.
        self::$resolved = [
            'country'  => $country,
            'currency' => strtoupper($currency),
        ];

        return self::$resolved;
    }

    public static function display_currency(): string
    {
        return self::resolve()['currency'];
    }

    public static function country_code(): string
    {
        return self::resolve()['country'];
    }

    /**
     * Prefer CF-IPCountry, then cookie, then free IP geo API.
     */
    public static function detect_country(): string
    {
        $cf = self::header_country();
        if ($cf !== '') {
            self::maybe_set_cookie($cf);
            return $cf;
        }

        $cookie = self::cookie_country();
        if ($cookie !== '') {
            return $cookie;
        }

        $ip = self::client_ip();
        if ($ip === '' || self::is_private_ip($ip)) {
            return 'KE'; // store default audience
        }

        $from_api = self::lookup_ip_country($ip);
        if ($from_api !== '') {
            self::maybe_set_cookie($from_api);
            return $from_api;
        }

        return 'KE';
    }

    private static function header_country(): string
    {
        $raw = $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '';
        if (!is_string($raw) || $raw === '') {
            // Some proxies / tests
            $raw = $_SERVER['HTTP_X_COUNTRY_CODE'] ?? '';
        }
        $cc = strtoupper(trim((string) $raw));
        // Cloudflare uses XX (unknown), T1 (tor)
        if ($cc === '' || $cc === 'XX' || $cc === 'T1' || !preg_match('/^[A-Z]{2}$/', $cc)) {
            return '';
        }
        return $cc;
    }

    private static function cookie_country(): string
    {
        $raw = $_COOKIE[self::COOKIE] ?? '';
        $cc  = strtoupper(trim((string) $raw));
        if (preg_match('/^[A-Z]{2}$/', $cc)) {
            return $cc;
        }
        return '';
    }

    private static function maybe_set_cookie(string $cc): void
    {
        if (headers_sent()) {
            return;
        }
        $existing = self::cookie_country();
        if ($existing === $cc) {
            return;
        }
        // 12h sticky — aligns with FX cache window.
        setcookie(self::COOKIE, $cc, [
            'expires'  => time() + 12 * HOUR_IN_SECONDS,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = $cc;
    }

    private static function client_ip(): string
    {
        $candidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
        ];
        foreach ($candidates as $raw) {
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            // XFF may be a list
            $parts = array_map('trim', explode(',', $raw));
            foreach ($parts as $part) {
                if (filter_var($part, FILTER_VALIDATE_IP)) {
                    return $part;
                }
            }
        }
        return '';
    }

    private static function is_private_ip(string $ip): bool
    {
        // false => private/reserved/invalid — skip remote geo lookup.
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    /**
     * Free geo fallback: ip-api.com (HTTP, non-SSL free tier) via WP HTTP.
     * Cached per IP for 12h.
     */
    private static function lookup_ip_country(string $ip): string
    {
        $key = self::TRANSIENT_PREFIX . md5($ip);
        $cached = get_transient($key);
        if (is_string($cached) && preg_match('/^[A-Z]{2}$/', $cached)) {
            return $cached;
        }
        if ($cached === '0') {
            return '';
        }

        // ipapi.co JSON — HTTPS free (no key, rate-limited).
        $url = 'https://ipapi.co/' . rawurlencode($ip) . '/country/';
        $res = wp_remote_get($url, [
            'timeout' => 2.5,
            'headers' => ['User-Agent' => 'SupremeAutoparts-GeoCurrency/1.0'],
        ]);

        $cc = '';
        if (!is_wp_error($res) && (int) wp_remote_retrieve_response_code($res) === 200) {
            $body = strtoupper(trim((string) wp_remote_retrieve_body($res)));
            if (preg_match('/^[A-Z]{2}$/', $body)) {
                $cc = $body;
            }
        }

        set_transient($key, $cc !== '' ? $cc : '0', 12 * HOUR_IN_SECONDS);
        return $cc;
    }
}
