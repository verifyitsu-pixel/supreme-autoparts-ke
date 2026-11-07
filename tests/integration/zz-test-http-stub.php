<?php
// TEST ONLY (CI). Denies every outbound HTTP request except a stubbed Paystack initialize. Never deployed.
$GLOBALS['sa_it_log'] = getenv('SA_IT_LOG') ?: '/tmp/sa-it';
add_filter('pre_http_request', function ($pre, $args, $url) {
    $h = (string) parse_url($url, PHP_URL_HOST);
    $p = rtrim((string) parse_url($url, PHP_URL_PATH), '/');
    $dir = $GLOBALS['sa_it_log'];
    if ($h === 'api.paystack.co' && $p === '/transaction/initialize') {
        $b = json_decode((string) ($args['body'] ?? ''), true) ?: [];
        file_put_contents("$dir/init.log", ($b['reference'] ?? '?') . ' ' . ($b['amount'] ?? '?') . ' ' . ($b['currency'] ?? '?') . "\n", FILE_APPEND | LOCK_EX);
        if ((int) ($b['amount'] ?? 0) === 7700) {
            posix_kill(getmypid(), 9); // real SIGKILL of this PHP process after the claim is in state i
        }
        usleep(1500000);
        return ['headers' => [], 'body' => json_encode(['status' => true, 'message' => 'stub', 'data' => ['authorization_url' => 'https://checkout.paystack.com/stub' . substr(md5((string) ($b['reference'] ?? '')), 0, 8), 'access_code' => 'x', 'reference' => $b['reference'] ?? '']]), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
    }
    file_put_contents("$dir/blocked.log", $url . "\n", FILE_APPEND | LOCK_EX);
    return new WP_Error('blocked_by_test', 'external request denied in test');
}, 1, 3);
