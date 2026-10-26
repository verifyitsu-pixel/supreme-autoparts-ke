<?php
/**
 * Admin-only, no-mail functional self-test for the cross-email non-repeat
 * machinery. Exercises the REAL production reservation/finalize/release
 * functions against the live database with a sentinel recipient, proves
 * each state transition, then cleans up every row it created. Dispatches
 * no email and touches no customer data. Reachable only by an admin with
 * a valid nonce: /wp-admin/admin.php?page=sm-flows&sm_selftest=1&_wpnonce=...
 */
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_init', function () {
    if (!isset($_GET['sm_selftest'], $_GET['page']) || $_GET['page'] !== 'sm-flows') {
        return;
    }
    if (!current_user_can('manage_options') || !wp_verify_nonce((string) ($_GET['_wpnonce'] ?? ''), 'sm_selftest')) {
        wp_die('forbidden', 403);
    }
    global $wpdb;
    $table   = SM_DB::table('send_products');
    $email   = 'selftest-' . time() . '@selftest.invalid';
    $results = [];
    $check   = function (string $name, bool $ok, string $detail = '') use (&$results) {
        $results[] = ['check' => $name, 'pass' => $ok, 'detail' => $detail];
    };

    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    $check('send_products table exists (db 1.3.0)', $exists === $table, (string) $exists);
    if ($exists !== $table) {
        wp_send_json(['results' => $results], 500);
    }

    try {
        // 1) Per-recipient lock acquires and releases.
        $lock = sm_grid_lock($email);
        $check('grid lock acquires', $lock !== null, (string) $lock);
        sm_grid_unlock($lock);
        $relock = sm_grid_lock($email);
        $check('grid lock re-acquires after release', $relock !== null);
        sm_grid_unlock($relock);

        // 2) Queue email A reserves its selected products.
        sm_reserve_products(-9001, $email, [111, 222]);
        $reserved = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE email = %s AND state = 'reserved'", $email));
        $check('reservation writes reserved rows', $reserved === 2, "reserved=$reserved");

        // 3) A second build for the same recipient sees A's picks (no overlap).
        $seen = sm_recent_sent_product_ids($email);
        $check('concurrent build sees reserved picks as taken', in_array(111, $seen, true) && in_array(222, $seen, true), implode(',', $seen));

        // 4) Queue email B reserves a different product.
        sm_reserve_products(-9002, $email, [333]);
        $seen = sm_recent_sent_product_ids($email);
        $check('second reservation adds to exclusion set', in_array(333, $seen, true), implode(',', $seen));

        // 5) Provider-accepted send finalizes A: reserved -> sent with send_id.
        sm_finalize_queue_products(-9001, -9001);
        $sent = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE email = %s AND state = 'sent' AND send_id = -9001", $email));
        $check('finalize flips reserved to sent with send_id', $sent === 2, "sent=$sent");

        // 6) Failed/cancelled send releases B's reservation - never marks history.
        sm_release_queue_products(-9002);
        $left = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE email = %s AND product_id = 333", $email));
        $check('failed send releases reservation', $left === 0, "left=$left");
        $seen = sm_recent_sent_product_ids($email);
        $check('released product is pickable again', !in_array(333, $seen, true), implode(',', $seen));

        // 7) Sent history survives (non-repeat across future emails).
        $check('sent products stay in history', in_array(111, $seen, true) && in_array(222, $seen, true), implode(',', $seen));
    } finally {
        // Cleanup: every row this test created, any state.
        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE email = %s", $email));
        $left = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE email = %s", $email));
        $check('cleanup removes all sentinel rows', $left === 0, "left=$left");
    }

    $pass = !in_array(false, array_column($results, 'pass'), true);
    wp_send_json(['overall' => $pass ? 'PASS' : 'FAIL', 'results' => $results], $pass ? 200 : 500);
});
