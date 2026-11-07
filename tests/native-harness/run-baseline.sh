#!/usr/bin/env bash
# Baseline probe: two processes load the same guest session snapshot, B saves, then A saves a stale snapshot.
# With the stock handler B's write is expected to be LOST. A replacement handler must make this pass.
set -u
D=$1; S=$(mktemp -d)
wp db query "DELETE FROM wp_woocommerce_sessions WHERE session_key='harness-guest-1'" >/dev/null
wp eval-file "$D/baseline.php" B "$S" & PB=$!
wp eval-file "$D/baseline.php" A "$S" & PA=$!
wait $PA; wait $PB
V=$(wp db query "SELECT session_value FROM wp_woocommerce_sessions WHERE session_key='harness-guest-1'" --skip-column-names)
echo "stored: $V"
case "$V" in *cart_a*cart_b*|*cart_b*cart_a*) echo "RESULT: both writes kept";; *) echo "RESULT: lost update (expected for stock handler; adapter must keep both)";; esac
