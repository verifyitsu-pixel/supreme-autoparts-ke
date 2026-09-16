<?php
/**
 * USD → local FX rates with transient cache (8h).
 * Source: SA_FX_API_URL or open.er-api.com.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class SA_FX_Rates
{
    private const TRANSIENT = 'sa_fx_rates_usd_v1';
    private const TTL       = 8 * HOUR_IN_SECONDS; // 6–12h window

    /** @var array<string,float>|null */
    private static ?array $rates = null;

    /**
     * Rate to multiply USD amount by to get $currency (1 USD = rate units).
     * Returns null if unavailable (caller should fall back to USD display).
     */
    public static function rate_for(string $currency): ?float
    {
        $currency = strtoupper(trim($currency));
        if ($currency === '' || $currency === 'USD') {
            return 1.0;
        }

        $rates = self::get_rates();
        if ($rates === null || !isset($rates[$currency])) {
            return null;
        }

        $rate = (float) $rates[$currency];
        return $rate > 0 ? $rate : null;
    }

    /**
     * @return array<string,float>|null
     */
    public static function get_rates(): ?array
    {
        if (self::$rates !== null) {
            return self::$rates;
        }

        $cached = get_transient(self::TRANSIENT);
        if (is_array($cached) && isset($cached['USD']) && is_array($cached['rates'] ?? null)) {
            /** @var array<string,float> $r */
            $r = [];
            foreach ($cached['rates'] as $code => $val) {
                if (is_string($code) && is_numeric($val) && (float) $val > 0) {
                    $r[strtoupper($code)] = (float) $val;
                }
            }
            $r['USD'] = 1.0;
            self::$rates = $r;
            return self::$rates;
        }

        $fetched = self::fetch_rates();
        if ($fetched === null) {
            self::$rates = null;
            return null;
        }

        set_transient(self::TRANSIENT, [
            'USD'   => 1,
            'rates' => $fetched,
            'at'    => time(),
        ], self::TTL);

        self::$rates = $fetched;
        return self::$rates;
    }

    /**
     * @return array<string,float>|null
     */
    private static function fetch_rates(): ?array
    {
        $url = getenv('SA_FX_API_URL');
        if (!is_string($url) || $url === '') {
            // Free, no key: https://open.er-api.com/v6/latest/USD
            $url = 'https://open.er-api.com/v6/latest/USD';
        }

        $res = wp_remote_get($url, [
            'timeout' => 4,
            'headers' => ['Accept' => 'application/json', 'User-Agent' => 'SupremeAutoparts-GeoCurrency/1.0'],
        ]);

        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
            // Secondary fallback: exchangerate.host
            $res = wp_remote_get('https://api.exchangerate.host/latest?base=USD', [
                'timeout' => 4,
                'headers' => ['Accept' => 'application/json', 'User-Agent' => 'SupremeAutoparts-GeoCurrency/1.0'],
            ]);
            if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
                return null;
            }
        }

        $body = json_decode((string) wp_remote_retrieve_body($res), true);
        if (!is_array($body)) {
            return null;
        }

        $raw = $body['rates'] ?? null;
        if (!is_array($raw)) {
            return null;
        }

        $out = ['USD' => 1.0];
        foreach ($raw as $code => $val) {
            if (!is_string($code) || !is_numeric($val)) {
                continue;
            }
            $f = (float) $val;
            if ($f > 0) {
                $out[strtoupper($code)] = $f;
            }
        }

        return count($out) > 1 ? $out : null;
    }

    public static function convert_usd(float $usd, string $currency): ?float
    {
        $rate = self::rate_for($currency);
        if ($rate === null) {
            return null;
        }
        return round($usd * $rate, self::decimals_for($currency));
    }

    public static function decimals_for(string $currency): int
    {
        $currency = strtoupper($currency);
        // Zero-decimal currencies
        if (in_array($currency, ['JPY', 'KRW', 'UGX', 'RWF', 'VND', 'CLP'], true)) {
            return 0;
        }
        return 2;
    }
}
