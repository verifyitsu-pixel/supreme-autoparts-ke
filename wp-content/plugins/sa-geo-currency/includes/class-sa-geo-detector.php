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
    /** Pairs with COOKIE: short hash of client IP so a VPN/IP change invalidates sticky country. */
    private const COOKIE_IP = 'sa_geo_ip';
    private const TRANSIENT_PREFIX = 'sa_geo_ip_';

    /** @var array{country:string,currency:string}|null */
    private static ?array $resolved = null;

    /**
     * ISO-3166-1 alpha-2 → ISO-4217 for ~all countries/territories.
     * Unknown CF codes fall back to checkout currency (USD) — "rest of world".
     * FX rates (open.er-api) auto-cover currencies present in the feed; missing rates → USD display.
     *
     * @return array<string,string>
     */
    public static function country_currency_map(): array
    {
        return [
            'AD' => 'EUR',
            'AE' => 'AED',
            'AF' => 'AFN',
            'AG' => 'XCD',
            'AI' => 'XCD',
            'AL' => 'ALL',
            'AM' => 'AMD',
            'AO' => 'AOA',
            'AQ' => 'USD',
            'AR' => 'ARS',
            'AS' => 'USD',
            'AT' => 'EUR',
            'AU' => 'AUD',
            'AW' => 'AWG',
            'AX' => 'EUR',
            'AZ' => 'AZN',
            'BA' => 'BAM',
            'BB' => 'BBD',
            'BD' => 'BDT',
            'BE' => 'EUR',
            'BF' => 'XOF',
            'BG' => 'EUR',
            'BH' => 'BHD',
            'BI' => 'BIF',
            'BJ' => 'XOF',
            'BL' => 'EUR',
            'BM' => 'BMD',
            'BN' => 'BND',
            'BO' => 'BOB',
            'BQ' => 'USD',
            'BR' => 'BRL',
            'BS' => 'BSD',
            'BT' => 'BTN',
            'BV' => 'NOK',
            'BW' => 'BWP',
            'BY' => 'BYN',
            'BZ' => 'BZD',
            'CA' => 'CAD',
            'CC' => 'AUD',
            'CD' => 'CDF',
            'CF' => 'XAF',
            'CG' => 'XAF',
            'CH' => 'CHF',
            'CI' => 'XOF',
            'CK' => 'NZD',
            'CL' => 'CLP',
            'CM' => 'XAF',
            'CN' => 'CNY',
            'CO' => 'COP',
            'CR' => 'CRC',
            'CU' => 'CUP',
            'CV' => 'CVE',
            'CW' => 'ANG',
            'CX' => 'AUD',
            'CY' => 'EUR',
            'CZ' => 'CZK',
            'DE' => 'EUR',
            'DJ' => 'DJF',
            'DK' => 'DKK',
            'DM' => 'XCD',
            'DO' => 'DOP',
            'DZ' => 'DZD',
            'EC' => 'USD',
            'EE' => 'EUR',
            'EG' => 'EGP',
            'EH' => 'MAD',
            'ER' => 'ERN',
            'ES' => 'EUR',
            'ET' => 'ETB',
            'EU' => 'EUR',
            'FI' => 'EUR',
            'FJ' => 'FJD',
            'FK' => 'FKP',
            'FM' => 'USD',
            'FO' => 'DKK',
            'FR' => 'EUR',
            'GA' => 'XAF',
            'GB' => 'GBP',
            'GD' => 'XCD',
            'GE' => 'GEL',
            'GF' => 'EUR',
            'GG' => 'GBP',
            'GH' => 'GHS',
            'GI' => 'GIP',
            'GL' => 'DKK',
            'GM' => 'GMD',
            'GN' => 'GNF',
            'GP' => 'EUR',
            'GQ' => 'XAF',
            'GR' => 'EUR',
            'GS' => 'GBP',
            'GT' => 'GTQ',
            'GU' => 'USD',
            'GW' => 'XOF',
            'GY' => 'GYD',
            'HK' => 'HKD',
            'HM' => 'AUD',
            'HN' => 'HNL',
            'HR' => 'EUR',
            'HT' => 'HTG',
            'HU' => 'HUF',
            'ID' => 'IDR',
            'IE' => 'EUR',
            'IL' => 'ILS',
            'IM' => 'GBP',
            'IN' => 'INR',
            'IO' => 'USD',
            'IQ' => 'IQD',
            'IR' => 'IRR',
            'IS' => 'ISK',
            'IT' => 'EUR',
            'JE' => 'GBP',
            'JM' => 'JMD',
            'JO' => 'JOD',
            'JP' => 'JPY',
            'KE' => 'KES',
            'KG' => 'KGS',
            'KH' => 'KHR',
            'KI' => 'AUD',
            'KM' => 'KMF',
            'KN' => 'XCD',
            'KP' => 'KPW',
            'KR' => 'KRW',
            'KW' => 'KWD',
            'KY' => 'KYD',
            'KZ' => 'KZT',
            'LA' => 'LAK',
            'LB' => 'LBP',
            'LC' => 'XCD',
            'LI' => 'CHF',
            'LK' => 'LKR',
            'LR' => 'LRD',
            'LS' => 'LSL',
            'LT' => 'EUR',
            'LU' => 'EUR',
            'LV' => 'EUR',
            'LY' => 'LYD',
            'MA' => 'MAD',
            'MC' => 'EUR',
            'MD' => 'MDL',
            'ME' => 'EUR',
            'MF' => 'EUR',
            'MG' => 'MGA',
            'MH' => 'USD',
            'MK' => 'MKD',
            'ML' => 'XOF',
            'MM' => 'MMK',
            'MN' => 'MNT',
            'MO' => 'MOP',
            'MP' => 'USD',
            'MQ' => 'EUR',
            'MR' => 'MRU',
            'MS' => 'XCD',
            'MT' => 'EUR',
            'MU' => 'MUR',
            'MV' => 'MVR',
            'MW' => 'MWK',
            'MX' => 'MXN',
            'MY' => 'MYR',
            'MZ' => 'MZN',
            'NA' => 'NAD',
            'NC' => 'XPF',
            'NE' => 'XOF',
            'NF' => 'AUD',
            'NG' => 'NGN',
            'NI' => 'NIO',
            'NL' => 'EUR',
            'NO' => 'NOK',
            'NP' => 'NPR',
            'NR' => 'AUD',
            'NU' => 'NZD',
            'NZ' => 'NZD',
            'OM' => 'OMR',
            'PA' => 'PAB',
            'PE' => 'PEN',
            'PF' => 'XPF',
            'PG' => 'PGK',
            'PH' => 'PHP',
            'PK' => 'PKR',
            'PL' => 'PLN',
            'PM' => 'EUR',
            'PN' => 'NZD',
            'PR' => 'USD',
            'PS' => 'ILS',
            'PT' => 'EUR',
            'PW' => 'USD',
            'PY' => 'PYG',
            'QA' => 'QAR',
            'RE' => 'EUR',
            'RO' => 'RON',
            'RS' => 'RSD',
            'RU' => 'RUB',
            'RW' => 'RWF',
            'SA' => 'SAR',
            'SB' => 'SBD',
            'SC' => 'SCR',
            'SD' => 'SDG',
            'SE' => 'SEK',
            'SG' => 'SGD',
            'SH' => 'SHP',
            'SI' => 'EUR',
            'SJ' => 'NOK',
            'SK' => 'EUR',
            'SL' => 'SLE',
            'SM' => 'EUR',
            'SN' => 'XOF',
            'SO' => 'SOS',
            'SR' => 'SRD',
            'SS' => 'SSP',
            'ST' => 'STN',
            'SV' => 'USD',
            'SX' => 'ANG',
            'SY' => 'SYP',
            'SZ' => 'SZL',
            'TC' => 'USD',
            'TD' => 'XAF',
            'TF' => 'EUR',
            'TG' => 'XOF',
            'TH' => 'THB',
            'TJ' => 'TJS',
            'TK' => 'NZD',
            'TL' => 'USD',
            'TM' => 'TMT',
            'TN' => 'TND',
            'TO' => 'TOP',
            'TT' => 'TTD',
            'TV' => 'AUD',
            'TW' => 'TWD',
            'TZ' => 'TZS',
            'UA' => 'UAH',
            'UG' => 'UGX',
            'UM' => 'USD',
            'US' => 'USD',
            'UY' => 'UYU',
            'UZ' => 'UZS',
            'VA' => 'EUR',
            'VC' => 'XCD',
            'VE' => 'VES',
            'VG' => 'USD',
            'VI' => 'USD',
            'VN' => 'VND',
            'VU' => 'VUV',
            'WF' => 'XPF',
            'WS' => 'WST',
            'XK' => 'EUR',
            'YE' => 'YER',
            'YT' => 'EUR',
            'ZA' => 'ZAR',
            'ZM' => 'ZMW',
            'ZW' => 'ZWL',
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
        // Rest of world / unmapped territory → checkout currency (USD).
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
     * Prefer CF-IPCountry (every request), then IP-bound cookie, then free IP geo API.
     * Cookie alone must not stick across IP/VPN changes — pair with sa_geo_ip hash.
     */
    public static function detect_country(): string
    {
        $cf = self::header_country();
        if ($cf !== '') {
            self::maybe_set_cookie($cf);
            return $cf;
        }

        $ip = self::client_ip();
        $cookie = self::cookie_country();
        // Honor sticky cookie only while the client IP fingerprint still matches.
        if ($cookie !== '' && self::cookie_matches_current_ip($ip)) {
            return $cookie;
        }

        if ($ip === '' || self::is_private_ip($ip)) {
            // No reliable IP — do not invent from a stale cookie.
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
        $ip = self::client_ip();
        $ip_hash = self::ip_fingerprint($ip);
        $existing = self::cookie_country();
        $existing_ip = isset($_COOKIE[self::COOKIE_IP]) ? (string) $_COOKIE[self::COOKIE_IP] : '';
        if ($existing === $cc && $existing_ip === $ip_hash && $ip_hash !== '') {
            return;
        }
        // Short sticky for fallback-only paths; CF-IPCountry always re-evaluated first.
        // Bound to IP fingerprint so VPN / network change forces re-detect.
        $opts = [
            'expires'  => time() + 2 * HOUR_IN_SECONDS,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        setcookie(self::COOKIE, $cc, $opts);
        $_COOKIE[self::COOKIE] = $cc;
        if ($ip_hash !== '') {
            setcookie(self::COOKIE_IP, $ip_hash, $opts);
            $_COOKIE[self::COOKIE_IP] = $ip_hash;
        }
    }

    /**
     * True when sa_geo_ip cookie matches the current client IP fingerprint.
     */
    private static function cookie_matches_current_ip(string $ip): bool
    {
        $want = self::ip_fingerprint($ip);
        if ($want === '') {
            return false;
        }
        $have = isset($_COOKIE[self::COOKIE_IP]) ? (string) $_COOKIE[self::COOKIE_IP] : '';
        return hash_equals($want, $have);
    }

    private static function ip_fingerprint(string $ip): string
    {
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return '';
        }
        // Short non-reversible tag (not a secret) — enough to detect IP change.
        return substr(hash('sha256', 'sa-geo|' . $ip), 0, 12);
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
