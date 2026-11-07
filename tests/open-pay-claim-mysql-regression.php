<?php
// Real MySQL: the intent claim must be atomic across concurrent PHP processes and the CAS must be exact.
// Needs mysqli and a local MySQL (CI starts the runner's own MySQL). Throwaway database; never a store database.
if (!extension_loaded('mysqli')) { fwrite(STDERR, "FAIL: mysqli extension missing\n"); exit(1); }
$host = getenv('SA_TEST_MYSQL_HOST') ?: '127.0.0.1'; $user = getenv('SA_TEST_MYSQL_USER') ?: 'root'; $pass = getenv('SA_TEST_MYSQL_PASS') ?: 'root';
$db = new mysqli($host, $user, $pass);
if ($db->connect_errno) { fwrite(STDERR, "FAIL: cannot connect to test MySQL: {$db->connect_error}\n"); exit(1); }
$db->query('CREATE DATABASE IF NOT EXISTS sa_claim_test'); $db->select_db('sa_claim_test');
if (($argv[1] ?? '') !== 'worker') { $db->query('DROP TABLE IF EXISTS wp_options');
$db->query('CREATE TABLE wp_options (option_id BIGINT AUTO_INCREMENT PRIMARY KEY, option_name VARCHAR(191) NOT NULL DEFAULT "", option_value LONGTEXT NOT NULL, autoload VARCHAR(20) NOT NULL DEFAULT "yes", UNIQUE KEY option_name (option_name)) ENGINE=InnoDB'); }
if (($argv[1] ?? '') === 'worker') {
    define('ABSPATH', 1); function __($s, $d = '') { return $s; }
    final class W { public $options = 'wp_options'; public $db;
        function prepare($sql, ...$a) { $i = 0; return preg_replace_callback('/%[sd]/', function ($m) use (&$i, $a) { $v = $a[$i++]; return $m[0] === '%d' ? (string) (int) $v : "'" . $this->db->real_escape_string((string) $v) . "'"; }, $sql); }
        function query($sql) { $this->db->query($sql); return $this->db->affected_rows; }
        function get_var($sql) { $r = $this->db->query($sql); $row = $r ? $r->fetch_row() : null; return $row ? $row[0] : null; } }
    $wpdb = new W; $wpdb->db = $db;
    include __DIR__ . '/../wp-content/plugins/whop-payments/includes/class-whop-open-pay.php';
    usleep(((int) $argv[3]) * 1000);
    echo Whop_Open_Pay::claim_intent($argv[2], 'p|x|0|' . time() . '|' . $argv[4]) ? "WON\n" : "LOST\n";
    exit(0);
}
$ok = function ($c, $m) { if (!$c) { fwrite(STDERR, "FAIL: $m\n"); exit(1); } };
$checks = 0;
for ($round = 0; $round < 5; $round++) {
    $key = 'sa_op_i_' . $round; $procs = []; $pipes = [];
    for ($k = 0; $k < 12; $k++) {
        $p = proc_open([PHP_BINARY, __FILE__, 'worker', $key, '0', "w$round$k"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
        $procs[] = $p; $pipes[] = $pp;
    }
    $won = 0; $lost = 0;
    foreach ($procs as $idx => $p) { $out = stream_get_contents($pipes[$idx][1]); $err = stream_get_contents($pipes[$idx][2]); proc_close($p);
        if (strpos($out, 'WON') !== false) $won++; elseif (strpos($out, 'LOST') !== false) $lost++; else { fwrite(STDERR, "worker error: $err $out\n"); exit(1); } }
    $ok($won === 1 && $lost === 11, "round $round: exactly one of 12 concurrent processes owns the claim (won=$won lost=$lost)"); $checks++;
    $r = $db->query("SELECT COUNT(*) FROM wp_options WHERE option_name = '$key'")->fetch_row()[0];
    $ok((int) $r === 1, 'exactly one row'); $checks++;
}
// exact CAS on a real row
$db->query("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('cas', 'p|a', 'no')");
$db->query("UPDATE wp_options SET option_value = 'o|a' WHERE option_name = 'cas' AND option_value = 'p|a'"); $ok($db->affected_rows === 1, 'CAS from exact value'); $checks++;
$db->query("UPDATE wp_options SET option_value = 'i|a' WHERE option_name = 'cas' AND option_value = 'p|a'"); $ok($db->affected_rows === 0, 'CAS from stale value is a no-op'); $checks++;
// purge SQL parses on real MySQL and deletes only old rows
$db->query("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('sa_op_i_old', 'r|f|1|" . (time() - 200000) . "|o', 'no'), ('sa_op_i_new', 'r|f|1|" . (time() - 10) . "|o', 'no'), ('other_option', 'x', 'yes')");
if (!defined('ABSPATH')) define('ABSPATH', 1); if (!function_exists('__')) { function __($s, $d = '') { return $s; } }
final class W2 { public $options = 'wp_options'; public $db;
    function prepare($sql, ...$a) { $i = 0; return preg_replace_callback('/%[sd]/', function ($m) use (&$i, $a) { $v = $a[$i++]; return $m[0] === '%d' ? (string) (int) $v : "'" . $this->db->real_escape_string((string) $v) . "'"; }, $sql); }
    function query($sql) { $this->db->query($sql); if ($this->db->errno) { fwrite(STDERR, "SQL error: {$this->db->error}\n"); exit(1); } return $this->db->affected_rows; } function get_var($sql) { return null; } }
$wpdb = new W2; $wpdb->db = $db;
include __DIR__ . '/../wp-content/plugins/whop-payments/includes/class-whop-open-pay.php';
Whop_Open_Pay::purge_old_claims(time());
$left = array_column($db->query("SELECT option_name FROM wp_options WHERE option_name IN ('sa_op_i_old','sa_op_i_new','other_option')")->fetch_all(), 0);
sort($left); $ok($left === ['other_option', 'sa_op_i_new'], 'purge deletes only old claim rows: ' . implode(',', $left)); $checks++;
$db->query('DROP DATABASE sa_claim_test');
echo "$checks open-pay claim checks PASS on real MySQL (12 concurrent PHP processes x 5 rounds, exact CAS, purge SQL)\n";
