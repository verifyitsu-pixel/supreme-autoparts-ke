#!/usr/bin/env bash
# Real-Woo, independent-process cases against the TEST-ONLY adapter. Throwaway DB only.
set -u
D=$1; FAILS=0
W() { wp eval-file "$D/worker.php" 2>&1 | grep '^RESULT ' | tail -1 | sed 's/^RESULT //'; }
dump() { HW_MODE=dump HW_CID=$1 W; }
chk() { # name, actual-json, jq-bool
  if echo "$2" | jq -e "$3" >/dev/null 2>&1; then echo "PASS $1  $2"; else echo "FAIL $1  $2"; FAILS=$((FAILS+1)); fi; }
mkp() { wp eval 'foreach([1,2] as $i){$p=new WC_Product_Simple();$p->set_name("Harness $i");$p->set_regular_price("10");$p->set_status("publish");$p->save();echo $p->get_id()," ";}'; }
read P1 P2 <<<"$(mkp)"; echo "products $P1 $P2"
seed() { HW_MODE=seed HW_OP=seed-$RANDOM$RANDOM HW_PIDS="$P1,$P2" W | jq -r .cid; }

echo "== case: seed through adapter (native add_to_cart)"
C=$(seed); R=$(dump "$C"); chk seed "$R" ".cart==[$P1,$P2] and (.ops|length)==1"

echo "== case 1: two independent saves, overlapping (A holds lock until B is waiting)"
C=$(seed); S=$(mktemp -d)
( HW_MODE=save HW_CID=$C HW_OP=opA-save1 HW_PID=$P1 HW_CFG="{\"hold_at\":\"before_commit\",\"hold_until\":\"$S/b-waiting\"}" W > $S/A.out ) &
sleep 3
( HW_MODE=save HW_CID=$C HW_OP=opB-save2 HW_PID=$P2 HW_CFG="{\"notify_at\":\"lock_wait\",\"notify_file\":\"$S/b-waiting\"}" W > $S/B.out ) &
wait; R=$(dump "$C"); echo "A: $(cat $S/A.out)"; echo "B: $(cat $S/B.out)"
# B waits max 2s for the lock; A releases after B notifies. B must either see both writes or fail closed, never lose A's.
chk case1_no_lost_write "$R" '(.saved|index('"$P1"')!=null)'
chk case1_consistent "$R" '((.cart+.saved)|sort|unique)==['"$P1,$P2"']'

echo "== case 2: replay of same op id after commit (response-loss recovery)"
C=$(seed); HW_MODE=save HW_CID=$C HW_OP=opR-replay1 HW_PID=$P1 W >/dev/null
A1=$(dump "$C"); RR=$(HW_MODE=save HW_CID=$C HW_OP=opR-replay1 HW_PID=$P1 W); A2=$(dump "$C")
chk case2_first "$A1" ".saved==[$P1] and .cart==[$P2]"
chk case2_replay_same_outcome "$RR" '.ok==true'
chk case2_no_duplicate "$A2" ".saved==[$P1] and .cart==[$P2] and (.ops|length)==2"

echo "== case 3: crash (SIGKILL) before durable commit"
C=$(seed); B0=$(dump "$C")
HW_MODE=save HW_CID=$C HW_OP=opC-crash1 HW_PID=$P1 HW_CFG='{"crash_at":"before_commit"}' W >/dev/null; B1=$(dump "$C")
chk case3_old_blob_intact "$B1" ".cart==[$P1,$P2] and .saved==[] and (.ops|index(\"opC-crash1\"))==null"
HW_MODE=save HW_CID=$C HW_OP=opC-crash2 HW_PID=$P1 W >/dev/null; B2=$(dump "$C")   # lock released by server, next worker recovers
chk case3_next_worker_recovers "$B2" ".saved==[$P1]"

echo "== case 4: lock held, parallel request fails closed (bounded wait) with no write"
C=$(seed); S=$(mktemp -d)
( HW_MODE=save HW_CID=$C HW_OP=opH-hold HW_PID=$P1 HW_CFG="{\"hold_at\":\"before_commit\",\"hold_until\":\"$S/release\"}" W > $S/A.out ) &
sleep 4; T0=$(date +%s)
RB=$(HW_MODE=save HW_CID=$C HW_OP=opH-other HW_PID=$P2 W); T1=$(date +%s); touch $S/release; wait
RD=$(dump "$C")
chk case4_fail_closed "$RB" '.ok==false and (.error|test("busy"))'
chk case4_bounded_wait "{\"s\":$((T1-T0))}" '.s<=6'
chk case4_no_write_by_blocked "$RD" "(.ops|index(\"opH-other\"))==null"

echo "FAILS=$FAILS"; exit $FAILS
