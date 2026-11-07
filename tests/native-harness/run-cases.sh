#!/usr/bin/env bash
# Real-Woo, independent-process cases against the TEST-ONLY adapter (barrier-file interleavings; a missed barrier FAILS).
set -u
D=$1; FAILS=0
W() { wp eval-file "$D/worker.php" 2>&1 | grep '^RESULT ' | tail -1 | sed 's/^RESULT //'; }
dump() { HW_MODE=dump HW_CID=$1 W; }
ok() { echo "PASS $1"; }; bad() { echo "FAIL $1"; FAILS=$((FAILS+1)); }
chk() { if echo "$2" | jq -e "$3" >/dev/null 2>&1; then ok "$1  $2"; else bad "$1  $2"; fi; }
same() { if [ "$2" == "$3" ]; then ok "$1  $2"; else bad "$1  before=$2 after=$3"; fi; }
waitf() { for i in $(seq 1 100); do [ -e "$1" ] && return 0; sleep 0.1; done; return 1; }
mkp() { wp eval 'foreach([1,2,3] as $i){$p=new WC_Product_Simple();$p->set_name("Harness $i");$p->set_regular_price("10");$p->set_status("publish");$p->save();echo $p->get_id()," ";}'; }
read P1 P2 P3 <<<"$(mkp)"; echo "products $P1 $P2"
seed() { HW_MODE=seed HW_OP=seed-$RANDOM$RANDOM HW_PIDS="$P1,$P2" W | jq -r .cid; }
SEEDSTATE="\"marker\":\"keep\""

echo "== seed: native add_to_cart qty 2 each, marker key"
C=$(seed); R=$(dump "$C"); chk seed "$R" ".cart==[{\"pid\":$P1,\"qty\":2,\"var\":0},{\"pid\":$P2,\"qty\":2,\"var\":0}] and .marker==\"keep\" and (.ops|length)==1"

echo "== case 1: overlapping saves; A reaches before_commit holding lock BEFORE B starts; B signals lock_wait while A holds; barrier timeout FAILS"
C=$(seed); S=$(mktemp -d)
( HW_MODE=save HW_CID=$C HW_OP=opA-save1 HW_PID=$P1 HW_CFG="{\"notify_at\":\"before_commit\",\"notify_file\":\"$S/a-held\",\"hold_at\":\"before_commit\",\"hold_until\":\"$S/b-waiting\"}" W > $S/A.out ) &
waitf $S/a-held && ok case1_A_reached_before_commit || bad case1_A_reached_before_commit
( HW_MODE=save HW_CID=$C HW_OP=opB-save2 HW_PID=$P2 HW_CFG="{\"notify_at\":\"lock_wait\",\"notify_file\":\"$S/b-waiting\"}" W > $S/B.out ) &
wait; R=$(dump "$C")
chk case1_A_ok "$(cat $S/A.out)" '.ok==true and .outcome.moved=='"$P1"
chk case1_B_ok "$(cat $S/B.out)" '.ok==true and .outcome.moved=='"$P2"
[ -e $S/b-waiting ] && ok case1_B_observed_lock_wait_while_A_held || bad case1_B_observed_lock_wait_while_A_held
chk case1_exact_state "$R" '.cart==[] and .saved==[{"pid":'"$P1"',"qty":2},{"pid":'"$P2"',"qty":2}] and .marker=="keep" and (.ops|map(select(startswith("op")))==["opA-save1","opB-save2"]) and (.ops|length)==3'

echo "== case 2: replay equals prior outcome exactly, state byte-identical"
C=$(seed); R1=$(HW_MODE=save HW_CID=$C HW_OP=opR-replay1 HW_PID=$P1 W); A1=$(dump "$C")
R2=$(HW_MODE=save HW_CID=$C HW_OP=opR-replay1 HW_PID=$P1 W); A2=$(dump "$C")
same case2_outcome_exact "$(echo "$R1" | jq -cS .outcome)" "$(echo "$R2" | jq -cS .outcome)"
same case2_state_identical "$A1" "$A2"
chk case2_state "$A2" '.cart==[{"pid":'"$P2"',"qty":2,"var":0}] and .saved==[{"pid":'"$P1"',"qty":2}] and .marker=="keep"'
RX=$(HW_MODE=save HW_CID=$C HW_OP=opR-replay1 HW_PID=$P2 W); chk case2_same_id_other_action_rejected "$RX" '.ok==false'

echo "== case 3: SIGKILL before durable commit, state byte-identical, next worker recovers"
C=$(seed); B0=$(dump "$C")
HW_MODE=save HW_CID=$C HW_OP=opC-crash1 HW_PID=$P1 HW_CFG='{"crash_at":"before_commit"}' W >/dev/null; B1=$(dump "$C")
same case3_state_identical "$B0" "$B1"
HW_MODE=save HW_CID=$C HW_OP=opC-crash2 HW_PID=$P1 W >/dev/null; B2=$(dump "$C")
chk case3_next_worker_recovers "$B2" '.saved==[{"pid":'"$P1"',"qty":2}] and .cart==[{"pid":'"$P2"',"qty":2,"var":0}]'

echo "== case 4: lock held (A at before_commit), parallel request fails closed within bound, no write"
C=$(seed); S=$(mktemp -d)
( HW_MODE=save HW_CID=$C HW_OP=opH-hold HW_PID=$P1 HW_CFG="{\"notify_at\":\"before_commit\",\"notify_file\":\"$S/a-held\",\"hold_at\":\"before_commit\",\"hold_until\":\"$S/release\"}" W > $S/A.out ) &
waitf $S/a-held && ok case4_A_holding || bad case4_A_holding
T0=$(date +%s); RB=$(HW_MODE=save HW_CID=$C HW_OP=opH-other HW_PID=$P2 W); T1=$(date +%s); touch $S/release; wait
RD=$(dump "$C")
chk case4_fail_closed "$RB" '.ok==false and (.error|test("busy"))'
chk case4_bounded_wait "{\"s\":$((T1-T0))}" '.s<=6'
chk case4_A_committed_only "$RD" '.saved==[{"pid":'"$P1"',"qty":2}] and (.ops|index("opH-other"))==null and (.ops|index("opH-hold"))!=null'

echo "== case 5: finish() then late dirty callback must fail closed; no stale overwrite"
C=$(seed); HW_MODE=save HW_CID=$C HW_OP=opL-first HW_PID=$P1 HW_LATE=latedirty W >/dev/null
L=$(HW_MODE=save HW_CID=$C HW_OP=opL-second HW_PID=$P2 HW_LATE=latedirty W); RD=$(dump "$C")
chk case5_late_refused_explicit "$L" '.late_refused==true and (.late_error|test("finalized|closed"))'
chk case5_state "$RD" '.late==false and .saved==[{"pid":'"$P1"',"qty":2},{"pid":'"$P2"',"qty":2}] and .cart==[]'



echo "== case 6: crash AFTER commit before response, then replay"
C=$(seed); HW_MODE=save HW_CID=$C HW_OP=opP-postcommit HW_PID=$P1 HW_CFG='{"crash_at":"before_response"}' W >/dev/null; D1=$(dump "$C")
chk case6_committed_despite_crash "$D1" '.saved==[{"pid":'"$P1"',"qty":2}] and (.ops|index("opP-postcommit"))!=null'
R=$(HW_MODE=save HW_CID=$C HW_OP=opP-postcommit HW_PID=$P1 W); D2=$(dump "$C")
chk case6_replay_ok "$R" '.ok==true and .outcome=={"ok":true,"moved":'"$P1"'}'
same case6_state_and_hash_identical "$D1" "$D2"

echo "== case 7: MySQL CONNECTION KILL at before_commit (GET_LOCK lost; wpdb may reconnect) must not write"
C=$(seed); K0=$(dump "$C")
R=$(HW_MODE=save HW_CID=$C HW_OP=opK-kill HW_PID=$P1 HW_CFG='{"kill_conn_at":"before_commit"}' W); K1=$(dump "$C")
chk case7_refused "$R" '.ok==false and (.error|test("lock lost|Session lock"))'
same case7_db_hash_unchanged "$K0" "$K1"

echo "== case 8: actual failing MySQL statement (trigger NULLs NOT NULL column), then readback mismatch (trigger rewrites value)"
C=$(seed); F0=$(dump "$C")
wp db query "CREATE TRIGGER hf_fail BEFORE INSERT ON wp_woocommerce_sessions FOR EACH ROW SET NEW.session_expiry = IF(NEW.session_key='$C', NULL, NEW.session_expiry)"
R=$(HW_MODE=save HW_CID=$C HW_OP=opF-fail HW_PID=$P1 W); wp db query "DROP TRIGGER hf_fail"; F1=$(dump "$C")
chk case8_commit_failure_reported "$R" '.ok==false and (.error|test("commit failed"))'
same case8_db_hash_unchanged_no_stale_flush "$F0" "$F1"
R=$(HW_MODE=save HW_CID=$C HW_OP=opF-fail HW_PID=$P1 W); chk case8_same_op_succeeds_after_fix "$R" '.ok==true'
C=$(seed)
wp db query "CREATE TRIGGER hf_rb BEFORE INSERT ON wp_woocommerce_sessions FOR EACH ROW SET NEW.session_value = IF(NEW.session_key='$C', 'x', NEW.session_value)"
R=$(HW_MODE=save HW_CID=$C HW_OP=opF-rb HW_PID=$P1 W); wp db query "DROP TRIGGER hf_rb"
chk case8_readback_mismatch_reported "$R" '.ok==false and (.error|test("readback"))'

echo "== HTTP section: PHP built-in server (multi-worker), real wc-ajax add_to_cart, adapter via test mu-plugin. NOT Apache/FPM."
BASE=http://127.0.0.1:8080; SRV=$(pwd)
PHP_CLI_SERVER_WORKERS=6 php -S 127.0.0.1:8080 -t "$SRV" >/tmp/php-server.log 2>&1 & SP=$!
for i in $(seq 1 50); do curl -s -o /dev/null $BASE/ && break; sleep 0.2; done
hreq() { curl -s -m 40 -w '\nHTTP=%{http_code}' -b "$1" -H "X-Harness-Mode: $2" -H "X-Harness-Cfg: $3" -d "product_id=$4&quantity=1" "$BASE/?wc-ajax=add_to_cart"; }
hseed() { J=$(mktemp); curl -s -m 40 -o /dev/null -c $J -H "X-Harness-Mode: adapter" -H "X-Harness-Cfg: {}" -d "product_id=$P1&quantity=1" "$BASE/?wc-ajax=add_to_cart"
  CID=$(grep wp_woocommerce_session $J | awk '{print $7}' | sed 's/%7C/|/g' | cut -d'|' -f1); }
code() { echo "$1" | sed -n 's/^HTTP=//p'; }
nofatal() { echo "$1" | grep -qiE "Fatal error|Uncaught|Stack trace" && echo false || echo true; }

echo "-- H1: seed over HTTP creates a guest session via real cookie"
hseed; D=$(dump "$CID"); chk h1_seed "$D" '.cart==[{"pid":'"$P1"',"qty":1,"var":0}] and .has_row==true'

echo "-- H2: two concurrent adapter writers, different products; X holds before_commit until Y is waiting (barriers)"
hseed; S=$(mktemp -d)
( hreq $J adapter "{\"notify_at\":\"before_commit\",\"notify_file\":\"$S/a-held\",\"hold_at\":\"before_commit\",\"hold_until\":\"$S/b-waiting\"}" $P2 > $S/X.out ) &
waitf $S/a-held && ok h2_X_at_before_commit || bad h2_X_at_before_commit
( hreq $J adapter "{\"notify_at\":\"lock_wait\",\"notify_file\":\"$S/b-waiting\"}" $P3 > $S/Y.out ) &
wait
chk h2_X_http200 "{\"c\":\"$(code "$(cat $S/X.out)")\",\"nofatal\":$(nofatal "$(cat $S/X.out)")}" '.c=="200" and .nofatal'
chk h2_Y_http200 "{\"c\":\"$(code "$(cat $S/Y.out)")\",\"nofatal\":$(nofatal "$(cat $S/Y.out)")}" '.c=="200" and .nofatal'
ls $S/*.TIMEOUT >/dev/null 2>&1 && bad h2_no_barrier_timeouts || ok h2_no_barrier_timeouts
D=$(dump "$CID"); chk h2_final_cart "$D" '.cart==[{"pid":'"$P1"',"qty":1,"var":0},{"pid":'"$P2"',"qty":1,"var":0},{"pid":'"$P3"',"qty":1,"var":0}]'
R=$(hreq $J adapter '{}' $P1); chk h2_lock_released_after_responses "{\"c\":\"$(code "$R")\"}" '.c=="200"'
D=$(dump "$CID"); chk h2_third_request_state "$D" '.cart[0].qty==2 and (.cart|length)==3'

echo "-- H3: native quantity writers: two concurrent +1 on the same product (adapter) must give qty 3"
hseed; S=$(mktemp -d)
( hreq $J adapter "{\"notify_at\":\"before_commit\",\"notify_file\":\"$S/a-held\",\"hold_at\":\"before_commit\",\"hold_until\":\"$S/b-waiting\"}" $P1 > $S/X.out ) &
waitf $S/a-held && ok h3_X_at_before_commit || bad h3_X_at_before_commit
( hreq $J adapter "{\"notify_at\":\"lock_wait\",\"notify_file\":\"$S/b-waiting\"}" $P1 > $S/Y.out ) &
wait; D=$(dump "$CID"); chk h3_qty_3 "$D" '.cart==[{"pid":'"$P1"',"qty":3,"var":0}]'

echo "-- H4: HTTP lock contention: X holds, Y fails closed (non-200), Y writes nothing"
hseed; S=$(mktemp -d)
( hreq $J adapter "{\"notify_at\":\"before_commit\",\"notify_file\":\"$S/a-held\",\"hold_at\":\"before_commit\",\"hold_until\":\"$S/release\"}" $P2 > $S/X.out ) &
waitf $S/a-held && ok h4_X_holding || bad h4_X_holding
RY=$(hreq $J adapter '{}' $P3); touch $S/release; wait
chk h4_Y_not_200 "{\"c\":\"$(code "$RY")\"}" '.c!="200"'
D=$(dump "$CID"); chk h4_only_X_committed "$D" '.cart==[{"pid":'"$P1"',"qty":1,"var":0},{"pid":'"$P2"',"qty":1,"var":0}]'

echo "-- H5 NEGATIVE CONTROL: a stock-handler writer mixed in with an adapter writer (expected UNSAFE; not an adapter PASS)"
hseed; S=$(mktemp -d)
( hreq $J stock "{\"loaded_file\":\"$S/y-loaded\",\"hold_until\":\"$S/x-done\"}" $P3 > $S/Y.out ) &
waitf $S/y-loaded && ok h5_stock_loaded_stale_snapshot || bad h5_stock_loaded_stale_snapshot
RX=$(hreq $J adapter '{}' $P2); touch $S/x-done; wait
D=$(dump "$CID")
if echo "$D" | jq -e '(.cart|map(.pid)|index('"$P2"'))==null' >/dev/null; then echo "INFO h5_UNSAFE_INVARIANT_DEMONSTRATED stock writer overwrote the adapter commit: $D (adapter protects only when ALL guest session writers use it)"; else echo "INFO h5_hazard_not_reproduced $D"; fi
kill $SP 2>/dev/null; sleep 1
grep -iE "fatal|uncaught" /tmp/php-server.log | head -3

echo "FAILS=$FAILS"; exit $FAILS
