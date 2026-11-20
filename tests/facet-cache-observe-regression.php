<?php
declare(strict_types=1);
$argv[1] = $argv[1] ?? dirname(__DIR__) . '/wp-content/plugins/supreme-autoparts-core/includes/catalog-facets.php';
$mode = $argv[2] ?? '';
if ($mode !== '') {
    $dir = $argv[3];
    define('SA_FX_VER', '5'); define('MINUTE_IN_SECONDS', 60);
    $cache = []; $calls = 0;
    function get_option($k, $d = false) { return 'one'; }
    function get_transient($k) { global $cache; return $cache[$k] ?? false; }
    function set_transient($k, $v, $ttl) { global $cache; $cache[$k] = $v; }
    $s = file_get_contents($argv[1]); $a = strpos($s, 'function sa_fx_observe_cache('); $b = strpos($s, "\n/**", $a);
    $body = substr($s, $a, $b - $a); $body = str_replace("'/tmp/sa-facet-observe'", var_export($dir, true), $body);
    if ($mode === 'throwing-handler') { set_error_handler(static function () { throw new RuntimeException('diagnostic I/O failure'); }); }
    eval($body);
    if ($mode === 'expiry') { sa_fx_observe_cache(true); sleep(2); sa_fx_observe_cache(true); }
    else {
        $fn = static function () { global $calls; ++$calls; return ['sample' => 7]; };
        $one = sa_fx_cache('private-key', $fn); $two = sa_fx_cache('private-key', $fn);
        if ($calls !== 1 || $one !== $two) { throw new RuntimeException('changed cache behavior'); }
        try { sa_fx_cache('exception-key', static function () { throw new LogicException('compute-failed'); }); throw new RuntimeException('compute exception lost'); }
        catch (LogicException $e) { if ($e->getMessage() !== 'compute-failed') { throw $e; } }
    }
    echo "APP_OK\n"; exit;
}
$base = sys_get_temp_dir() . '/sa-fx-tests-' . bin2hex(random_bytes(6)); mkdir($base, 0700);
$cases = ['active','absent','malformed','wrong-type','too-long','expired','future','expiry','unwritable','locked','throwing-handler','cap','partial-record','symlink','unsafe-mode','bad-window'];
try {
    foreach ($cases as $case) {
        $dir = $base . '/' . $case; mkdir($dir, 0700); $now = time(); $cfg = ['window_id' => str_repeat('a', 24), 'started_at' => $now, 'expires_at' => $now + 600];
        if ($case === 'wrong-type') { $cfg['started_at'] = (string) $now; }
        if ($case === 'too-long') { $cfg['expires_at'] = $now + 601; }
        if ($case === 'expired') { $cfg['expires_at'] = $now; }
        if ($case === 'future') { $cfg['started_at'] = $now + 2; }
        if ($case === 'expiry') { $cfg['expires_at'] = $now + 1; }
        if ($case !== 'absent') { file_put_contents($dir . '/active.json', $case === 'malformed' ? 'invalid' : json_encode($cfg)); }
        $record = $dir . '/records.jsonl'; $lock = null;
        if ($case === 'symlink') { symlink($dir . '/target', $record); }
        if ($case === 'unsafe-mode') { chmod($dir, 0777); }
        if ($case === 'bad-window') { file_put_contents($dir . '/active.json', json_encode(['window_id' => '../bad', 'started_at' => $now, 'expires_at' => $now + 600])); }
        if (in_array($case, ['unwritable','throwing-handler'], true)) { mkdir($record, 0700); }
        if ($case === 'cap') { file_put_contents($record, str_repeat('x', 1048576)); }
        if ($case === 'partial-record') { file_put_contents($record, "invalid\n"); }
        if ($case === 'locked') { $lock = fopen($record, 'a'); flock($lock, LOCK_EX); }
        $pipes = []; $p = proc_open([PHP_BINARY, '-d', 'memory_limit=32M', __FILE__, $argv[1], $case, $dir], [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); foreach ($pipes as $pipe) { fclose($pipe); } $rc = proc_close($p);
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
        if ($rc !== 0 || !str_contains($out, 'APP_OK') || $err !== '') { throw new RuntimeException($case . ': application failed ' . $err); }
        $raw = is_file($record) ? file_get_contents($record) : '';
        $lines = array_filter(explode("\n", trim($raw))); $valid = [];
        foreach ($lines as $line) { $m = json_decode($line, true); if (is_array($m)) { $valid[] = $m; } }
        $expected = in_array($case, ['active','partial-record','expiry'], true);
        if (count($valid) !== ($expected ? 1 : 0)) { throw new RuntimeException($case . ': unexpected record count'); }
        if ($expected) {
            $m = $valid[0]; $hits = $case === 'expiry' ? 1 : 1; $misses = $case === 'expiry' ? 0 : 2;
            if ($m['hits'] !== $hits || $m['misses'] !== $misses || $m['compute_count'] !== ($case === 'expiry' ? 0 : 1)) { throw new RuntimeException($case . ': wrong observed counts'); }
            if (str_contains($raw, 'private-key') || str_contains($raw, 'sample')) { throw new RuntimeException('private data logged'); }
        }
        echo 'PASS ' . $case . "\n";
    }
} finally {
    foreach (glob($base . '/*') as $dir) { foreach (glob($dir . '/*') as $file) { is_dir($file) ? rmdir($file) : unlink($file); } rmdir($dir); } rmdir($base);
}
