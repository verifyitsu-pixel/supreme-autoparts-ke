#!/usr/bin/env bash
# Real-Woo, independent-process cases against the TEST-ONLY adapter (barrier-file interleavings; a missed barrier FAILS).
set -u
D=$1; FAILS=0; export HW_ADAPTER=${ADAPTER:-old}; MODE=adapter; [ "$HW_ADAPTER" = dedicated ] && MODE=dedicated; echo "##### ADAPTER SUITE: $HW_ADAPTER"
W() { wp eval-file "$D/worker.php" 2>&1 | grep '^RESULT ' | tail -1 | sed 's/^RESULT //'; }
dump() { local o; o=$(HW_MODE=dump HW_CID=$1 wp eval-file "$D/worker.php" 2>&1); echo "$o" | grep "^RESULT " | tail -1 | sed "s/^RESULT //"; echo "$o" | grep -q "^RESULT " || echo "DUMPFAIL: $(echo "$o" | tail -3 | cut -c1-300)" >&2; }
ok() { echo "PASS $1"; }; bad() { echo "FAIL $1"; FAILS=$((FAILS+1)); }
chk() { if echo "$2" | jq -e "$3" >/dev/null 2>&1; then ok "$1  $2"; else bad "$1  $2"; fi; }
chk_neg() { if echo "$2" | jq -e ".ok==true" >/dev/null 2>&1; then echo "INFO $1 (write succeeded after connection kill, as designer predicted for the wpdb adapter) $2"; else echo "INFO $1_not_reproduced $2"; fi; }
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

if [ "$HW_ADAPTER" = old ]; then
echo "== case 7: MySQL CONNECTION KILL at before_commit (GET_LOCK lost; wpdb may reconnect) must not write"
C=$(seed); K0=$(dump "$C")
R=$(HW_MODE=save HW_CID=$C HW_OP=opK-kill HW_PID=$P1 HW_CFG='{"kill_conn_at":"before_commit"}' W); K1=$(dump "$C")
chk_neg case7_OLD_ADAPTER_REPRODUCES_UNSAFE_WRITE "$R" '.ok==false and (.error|test("lock lost|Session lock"))'
true "$K0" "$K1"

else
echo "== case 7 (dedicated): kill the adapter's dedicated storage connection at each fault point; must fail closed, raw SHA256 unchanged (before_response: committed + replay)"
for PT in before_read before_commit before_readback before_sql_commit; do
  C=$(seed); K0=$(dump "$C"); OPK=opK-$PT
  R=$(HW_MODE=save HW_CID=$C HW_OP=$OPK HW_PID=$P1 HW_CFG="{\"kill_ded_at\":\"$PT\"}" W); K1=$(dump "$C")
  chk case7_${PT}_refused "$R" '.ok==false'
  same case7_${PT}_sha_state_unchanged "$K0" "$K1"
  R2=$(HW_MODE=save HW_CID=$C HW_OP=$OPK HW_PID=$P1 W); chk case7_${PT}_retry_fresh_request_ok "$R2" '.ok==true'
done
C=$(seed); R0=$(HW_MODE=save HW_CID=$C HW_OP=opK-after HW_PID=$P1 HW_CFG='{"kill_ded_at":"after_commit"}' W); D1=$(dump "$C")
echo "INFO case7_after_commit_initial_outcome $R0"
R=$(HW_MODE=save HW_CID=$C HW_OP=opK-after HW_PID=$P1 W); D2=$(dump "$C")
chk case7_after_commit_committed "$D1" '(.ops|index("opK-after"))!=null'
same case7_after_commit_replay_state_identical "$D1" "$D2"
same case7_after_commit_replay_outcome_exact "$(echo "$R0" | jq -cS '.outcome // .error')" "$(echo "$R" | jq -cS '.outcome // .error')"
fi

echo "== case 8: actual failing MySQL statement (trigger NULLs NOT NULL column), then readback mismatch (trigger rewrites value)"
C=$(seed); F0=$(dump "$C")
wp db query "CREATE TRIGGER hf_fail BEFORE INSERT ON wp_woocommerce_sessions FOR EACH ROW SET NEW.session_expiry = IF(NEW.session_key='$C', NULL, NEW.session_expiry)"
R=$(HW_MODE=save HW_CID=$C HW_OP=opF-fail HW_PID=$P1 W); wp db query "DROP TRIGGER hf_fail"; F1=$(dump "$C")
chk case8_commit_failure_reported "$R" '.ok==false and (.error|test("commit failed"))'
same case8_db_hash_unchanged_no_stale_flush "$F0" "$F1"
R=$(HW_MODE=save HW_CID=$C HW_OP=opF-fail HW_PID=$P1 W); chk case8_same_op_succeeds_after_fix "$R" '.ok==true'
C=$(seed); F0R=$(dump "$C")
wp db query "CREATE TRIGGER hf_rb BEFORE INSERT ON wp_woocommerce_sessions FOR EACH ROW SET NEW.session_value = IF(NEW.session_key='$C', 'x', NEW.session_value)"
R=$(HW_MODE=save HW_CID=$C HW_OP=opF-readback HW_PID=$P1 W); wp db query "DROP TRIGGER hf_rb"
chk case8_readback_mismatch_reported "$R" '.ok==false and (.error|test("readback"))'
[ "$HW_ADAPTER" = dedicated ] && { DB=$(dump "$C"); same case8_dedicated_readback_rollback_exact_raw_sha_and_ledger "$F0R" "$DB"; }


if [ "$HW_ADAPTER" = dedicated ]; then
echo "== FUNCTIONAL BATCH (dedicated adapter, real native variable products/variations/attributes): exact save/restore"
read VP V1 V2 SI <<<"$(wp eval '$p=new WC_Product_Variable();$p->set_name("Var");$a=new WC_Product_Attribute();$a->set_name("Size");$a->set_options(array("S","M"));$a->set_visible(true);$a->set_variation(true);$p->set_attributes(array($a));$p->set_status("publish");$pid=$p->save();$ids=array();foreach(array("S","M") as $s){$v=new WC_Product_Variation();$v->set_parent_id($pid);$v->set_attributes(array("size"=>$s));$v->set_regular_price("10");$v->set_manage_stock(true);$v->set_stock_quantity(20);$v->set_status("publish");$ids[]=$v->save();}WC_Product_Variable::sync($pid);$si=new WC_Product_Simple();$si->set_name("SI");$si->set_regular_price("5");$si->set_sold_individually(true);$si->set_status("publish");$si->save();echo $pid," ",$ids[0]," ",$ids[1]," ",$si->get_id();')"
echo "fixtures parent=$VP vS=$V1 vM=$V2 soldindiv=$SI"
IT() { echo "{\"pid\":$1,\"vid\":$2,\"qty\":$3,\"attrs\":$4}"; }
fw() { HW_MODE=$1 HW_CID=$2 HW_OP=$3 HW_ITEMS="$4" W; }
fd() { HW_MODE=dumpf HW_CID=$1 W; }
AS='{"attribute_size":"S"}'; AM='{"attribute_size":"M"}'
fseed() { local c; c=$(HW_MODE=seedv HW_OP=fs-$RANDOM$RANDOM HW_ITEMS="[$(IT $VP $V1 2 "$AS"),$(IT $VP $V2 3 "$AM"),$(IT $P1 0 1 '{}'),$(IT $SI 0 1 '{}')]" W | jq -r .cid); echo $c; }

C=$(fseed); G0=$(fd "$C")
chk f0_seed_two_variants_same_parent_distinct "$G0" '(.cart|map(select(.pid=='"$VP"'))|map({vid,qty,a:.attrs.attribute_size}))==[{"vid":'"$V1"',"qty":2,"a":"S"},{"vid":'"$V2"',"qty":3,"a":"M"}] and (.cart|map(.key)|unique|length)==4'
KEY1=$(echo "$G0" | jq -r '.cart[]|select(.vid=='"$V1"')|.key')
R=$(fw savekey "$C" f1-save "$(IT $VP $V1 0 '{}')"); G1=$(fd "$C")
chk f1_save_variant_exact_payload_key "$G1" '.saved==[{"key":"'"$KEY1"'","pid":'"$VP"',"vid":'"$V1"',"attrs":{"attribute_size":"S"},"qty":2}] and (.cart|map(select(.vid=='"$V2"'))|.[0].qty)==3 and ((.cart|map(.vid))|index('"$V1"'))==null'
R=$(fw restore "$C" f2-restore "$(IT $VP $V1 0 '{}')"); G2=$(fd "$C")
chk f2_restore_exact_same_key_qty_attrs "$G2" '.saved==[] and (.cart|map(select(.vid=='"$V1"'))|.[0]|{key,qty,a:.attrs.attribute_size})=={"key":"'"$KEY1"'","qty":2,"a":"S"} and (.cart|length)==4'

C=$(fseed); fw savekey "$C" f3-save "$(IT $VP $V1 0 '{}')" >/dev/null; fw addv "$C" f3-add "[$(IT $VP $V1 1 "$AS")]" >/dev/null
R=$(fw restore "$C" f3-restore "$(IT $VP $V1 0 '{}')"); G3=$(fd "$C")
chk f3_restore_native_merge "$G3" '.saved==[] and (.cart|map(select(.vid=='"$V1"'))|.[0].qty)==3'

echo "-- last active line saved: cart empties, saved persists"
C=$(HW_MODE=seedv HW_OP=f4-seed HW_ITEMS="[$(IT $VP $V1 2 "$AS")]" W | jq -r .cid)
fw savekey "$C" f4-save "$(IT $VP $V1 0 '{}')" >/dev/null; G4=$(fd "$C")
chk f4_last_line_saved_persists "$G4" '.cart==[] and (.saved|length)==1 and .saved[0].vid=='"$V1"' and .saved[0].qty==2 and .sha256!=null'

echo "-- failures keep the saved payload byte-identical (raw sha) and the cart unchanged"
C=$(fseed); fw savekey "$C" f5-save "$(IT $VP $V2 0 '{}')" >/dev/null; B5=$(fd "$C")
wp eval '$v=wc_get_product('$V2');$v->set_stock_quantity(0);$v->set_stock_status("outofstock");$v->save();'
R=$(fw restore "$C" f5-restore "$(IT $VP $V2 0 '{}')"); A5=$(fd "$C")
chk f5a_out_of_stock_restore_refused "$R" '.ok==false and (.error|test("refused"))'
same f5a_out_of_stock_saved_and_cart_raw_sha_unchanged "$B5" "$A5"
C=$(fseed); fw savekey "$C" f6-save "$(IT $VP $V1 0 '{}')" >/dev/null; B6=$(fd "$C")
wp post delete $V1 --force >/dev/null
R=$(fw restore "$C" f6-restore "$(IT $VP $V1 0 '{}')"); A6=$(fd "$C")
chk f5b_deleted_variation_restore_refused "$R" '.ok==false'
same f5b_deleted_variation_saved_and_cart_raw_sha_unchanged "$B6" "$A6"
C=$(fseed); fw savekey "$C" f7-save "$(IT $SI 0 0 '{}')" >/dev/null; fw addv "$C" f7-add "[$(IT $SI 0 1 '{}')]" >/dev/null; B7=$(fd "$C")
R=$(fw restore "$C" f7-restore "$(IT $SI 0 0 '{}')"); A7=$(fd "$C")
chk f5c_sold_individually_restore_refused "$R" '.ok==false and (.error|test("refused"))'
same f5c_sold_individually_saved_and_cart_raw_sha_unchanged "$B7" "$A7"
fi

echo "== HTTP section: PHP built-in server (multi-worker), real wc-ajax add_to_cart, adapter via test mu-plugin. NOT Apache/FPM."
BASE=http://127.0.0.1:8080; SRV=$(pwd)
( PHP_CLI_SERVER_WORKERS=6 nohup php -S 127.0.0.1:8080 -t "$SRV" >/tmp/php-server.log 2>&1 & )  # subshell: not a job of this shell, so bare `wait` does not block on it
for i in $(seq 1 50); do curl -s -o /dev/null $BASE/ && break; sleep 0.2; done
hreq() { curl -s -m 40 -w '\nHTTP=%{http_code}' -b "$1" -H "X-Harness-Mode: $2" -H "X-Harness-Cfg: $3" -d "product_id=$4&quantity=1" "$BASE/?wc-ajax=add_to_cart"; }
hseed() { J=$(mktemp); curl -s -m 40 -o /dev/null -c $J -H "X-Harness-Mode: $MODE" -H "X-Harness-Cfg: {}" -d "product_id=$P1&quantity=1" "$BASE/?wc-ajax=add_to_cart"
  CID=$(grep wp_woocommerce_session $J | awk '{print $7}' | sed 's/%7C/|/g' | cut -d'|' -f1); }
code() { echo "$1" | sed -n 's/^HTTP=//p'; }
nofatal() { echo "$1" | grep -qiE "Fatal error|Uncaught|Stack trace" && echo false || echo true; }

echo "-- H1: seed over HTTP creates a guest session via real cookie"
hseed; HD=$(dump "$CID"); chk h1_seed "$HD" '.cart==[{"pid":'"$P1"',"qty":1,"var":0}] and .has_row==true'

echo "-- H2: two concurrent adapter writers, different products; X holds before_commit until Y is waiting (barriers)"
hseed; S=$(mktemp -d)
( hreq $J $MODE "{\"notify_at\":\"before_commit\",\"notify_file\":\"$S/a-held\",\"hold_at\":\"before_commit\",\"hold_until\":\"$S/b-waiting\"}" $P2 > $S/X.out ) &
waitf $S/a-held && ok h2_X_at_before_commit || bad h2_X_at_before_commit
( hreq $J $MODE "{\"notify_at\":\"lock_wait\",\"notify_file\":\"$S/b-waiting\"}" $P3 > $S/Y.out ) &
wait
chk h2_X_http200 "{\"c\":\"$(code "$(cat $S/X.out)")\",\"nofatal\":$(nofatal "$(cat $S/X.out)")}" '.c=="200" and .nofatal'
chk h2_Y_http200 "{\"c\":\"$(code "$(cat $S/Y.out)")\",\"nofatal\":$(nofatal "$(cat $S/Y.out)")}" '.c=="200" and .nofatal'
ls $S/*.TIMEOUT >/dev/null 2>&1 && bad h2_no_barrier_timeouts || ok h2_no_barrier_timeouts
HD=$(dump "$CID"); chk h2_final_cart "$HD" '.cart==[{"pid":'"$P1"',"qty":1,"var":0},{"pid":'"$P2"',"qty":1,"var":0},{"pid":'"$P3"',"qty":1,"var":0}]'
R=$(hreq $J $MODE '{}' $P1); chk h2_lock_released_after_responses "{\"c\":\"$(code "$R")\"}" '.c=="200"'
HD=$(dump "$CID"); chk h2_third_request_state "$HD" '.cart[0].qty==2 and (.cart|length)==3'

echo "-- H3: native quantity writers: two concurrent +1 on the same product (adapter) must give qty 3"
hseed; S=$(mktemp -d)
( hreq $J $MODE "{\"notify_at\":\"before_commit\",\"notify_file\":\"$S/a-held\",\"hold_at\":\"before_commit\",\"hold_until\":\"$S/b-waiting\"}" $P1 > $S/X.out ) &
waitf $S/a-held && ok h3_X_at_before_commit || bad h3_X_at_before_commit
( hreq $J $MODE "{\"notify_at\":\"lock_wait\",\"notify_file\":\"$S/b-waiting\"}" $P1 > $S/Y.out ) &
wait; chk h3_X_status "{\"c\":\"$(code "$(cat $S/X.out)")\"}" '.c=="200"'; chk h3_Y_status "{\"c\":\"$(code "$(cat $S/Y.out)")\"}" '.c=="200"'; ls $S/*.TIMEOUT >/dev/null 2>&1 && bad h3_barrier_timeout || ok h3_no_barrier_timeout
HD=$(dump "$CID"); chk h3_qty_3 "$HD" '.cart==[{"pid":'"$P1"',"qty":3,"var":0}]'

echo "-- H4: HTTP lock contention: X holds, Y fails closed (non-200), Y writes nothing"
hseed; S=$(mktemp -d)
( hreq $J $MODE "{\"notify_at\":\"before_commit\",\"notify_file\":\"$S/a-held\",\"hold_at\":\"before_commit\",\"hold_until\":\"$S/release\"}" $P2 > $S/X.out ) &
waitf $S/a-held && ok h4_X_holding || bad h4_X_holding
LN0=$(wc -l < /tmp/php-server.log); RY=$(hreq $J $MODE '{}' $P3); touch $S/release; wait
tail -n +$((LN0+1)) /tmp/php-server.log | grep -q "Guest session busy" && ok h4_expected_busy_exception_in_server_log || bad h4_expected_busy_exception_in_server_log
chk h4_Y_not_200 "{\"c\":\"$(code "$RY")\"}" '.c!="200"'
HD=$(dump "$CID"); chk h4_only_X_committed "$HD" '.cart==[{"pid":'"$P1"',"qty":1,"var":0},{"pid":'"$P2"',"qty":1,"var":0}]'

echo "-- H5 NEGATIVE CONTROL: a stock-handler writer mixed in with an adapter writer (expected UNSAFE; not an adapter PASS)"
hseed; S=$(mktemp -d)
( hreq $J stock "{\"loaded_file\":\"$S/y-loaded\",\"hold_until\":\"$S/x-done\"}" $P3 > $S/Y.out ) &
waitf $S/y-loaded && ok h5_stock_loaded_stale_snapshot || bad h5_stock_loaded_stale_snapshot
RX=$(hreq $J $MODE '{}' $P2); touch $S/x-done; wait
HD=$(dump "$CID")
if echo "$HD" | jq -e '(.cart|map(.pid)|index('"$P2"'))==null' >/dev/null; then echo "INFO h5_UNSAFE_INVARIANT_DEMONSTRATED stock writer overwrote the adapter commit: $HD (adapter protects only when ALL guest session writers use it)"; else echo "INFO h5_hazard_not_reproduced $HD"; fi
pkill -f "php -S 127.0.0.1:8080" 2>/dev/null; sleep 1
grep -iE "fatal|uncaught" /tmp/php-server.log | head -3

echo "FAILS=$FAILS"; exit $FAILS
