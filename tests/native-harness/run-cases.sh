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
mkp() { wp eval 'foreach([1,2] as $i){$p=new WC_Product_Simple();$p->set_name("Harness $i");$p->set_regular_price("10");$p->set_status("publish");$p->save();echo $p->get_id()," ";}'; }
read P1 P2 <<<"$(mkp)"; echo "products $P1 $P2"
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
L=$(HW_MODE=save HW_CID=$C HW_OP=opL-second HW_PID=$P2 HW_LATE=latedirty W | tail -1); RD=$(dump "$C")
chk case5_state "$RD" '.late==false and .saved==[{"pid":'"$P1"',"qty":2},{"pid":'"$P2"',"qty":2}] and .cart==[]'

echo "FAILS=$FAILS"; exit $FAILS
