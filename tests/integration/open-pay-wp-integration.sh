#!/usr/bin/env bash
# CI-only: real WordPress 7.1.x + WooCommerce 11.1.0 (same versions the live site reports), the repo's real plugins,
# real MySQL, real admin-post handler. Only the outbound Paystack initialize is stubbed; all other HTTP is denied.
set -euo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"; W=/tmp/wp; export SA_IT_LOG=/tmp/sa-it; mkdir -p $SA_IT_LOG; : > $SA_IT_LOG/init.log; : > $SA_IT_LOG/blocked.log
WP="php /tmp/wp-cli.phar --path=$W"
sudo systemctl start mysql
for i in $(seq 1 15); do mysql -uroot -proot -e 'SELECT 1' >/dev/null 2>&1 && break; sleep 2; done
mysql -uroot -proot -e 'DROP DATABASE IF EXISTS wp_it; CREATE DATABASE wp_it'
curl -fsSL -o /tmp/wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
rm -rf $W; mkdir $W
$WP core download --version=7.1.4 --force || $WP core download --force
$WP config create --dbname=wp_it --dbuser=root --dbpass=root --dbhost=127.0.0.1 --skip-check
$WP core install --url=http://127.0.0.1:8080 --title=it --admin_user=itadmin --admin_password="$(openssl rand -hex 12)" --admin_email=it@example.invalid --skip-email
$WP plugin install woocommerce --version=11.1.0 --activate
$WP core version; $WP plugin get woocommerce --field=version
mkdir -p $W/wp-content/mu-plugins
cp -r $REPO/wp-content/plugins/{whop-payments,woo-paystack,sa-geo-currency} $W/wp-content/plugins/
cp $REPO/wp-content/mu-plugins/sa-paystack.php $REPO/tests/integration/zz-test-http-stub.php $W/wp-content/mu-plugins/
$WP plugin activate sa-geo-currency woo-paystack whop-payments
$WP option update woocommerce_currency KES
$WP option update woocommerce_paystack_settings '{"enabled":"yes","testmode":"no","live_secret_key":"sk_live_TESTONLY_NOT_REAL","live_public_key":"pk_live_TESTONLY_NOT_REAL"}' --format=json
$WP eval 'set_transient("sa_fx_rates_usd_v1", ["USD"=>1, "rates"=>["KES"=>129]], 28800);'
NONCE=$($WP eval 'echo wp_create_nonce("sa_open_pay");')
export NONCE
orders() { $WP eval 'echo count(wc_get_orders(["limit"=>-1,"created_via"=>"sa_open_pay","return"=>"ids"]));'; }
inits() { wc -l < $SA_IT_LOG/init.log | tr -d ' '; }
intent() { printf '%08x%s' "$(date +%s)" "$(openssl rand -hex 12)"; }
fail() { echo "FAIL: $*" >&2; echo "--- blocked:"; sort $SA_IT_LOG/blocked.log | uniq -c; exit 1; }
n=0; ok() { n=$((n+1)); [ "$1" = 1 ] || fail "$2"; }
post() { # port intent amount email ip [nointent]
  local port=$1 it=$2 amt=$3 em=$4 ip=$5
  curl -s -o /dev/null -D - -X POST "http://127.0.0.1:$port/wp-admin/admin-post.php" -H "CF-Connecting-IP: $ip" \
    --data-urlencode "action=sa_open_pay" --data-urlencode "sa_open_pay_nonce=$NONCE" --data-urlencode "currency=KES" --data-urlencode "gateway=paystack" \
    --data-urlencode "amount=$amt" --data-urlencode "email=$em" --data-urlencode "note=" --data-urlencode "sa_intent=$it" | tr -d '\r' | awk 'tolower($1)=="location:"{print $2}'
}
export -f post; export SA_IT_LOG
PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8080 -t $W >/tmp/sa-it/server.log 2>&1 & S=$!
trap 'kill $S 2>/dev/null || true; kill ${S2:-0} 2>/dev/null || true' EXIT
for i in $(seq 1 20); do curl -s -o /dev/null http://127.0.0.1:8080/wp-login.php && break; sleep 1; done

# T0 pre-state
O0=$(orders); I0=$(inits)
# T1 normal submit reaches the stubbed Paystack hosted URL
I1=$(intent); L=$(post 8080 $I1 100 a@example.invalid 10.0.0.1)
echo "T1 Location: $L"
ok $([[ "$L" == https://checkout.paystack.com/stub* ]] && echo 1 || echo 0) "T1 redirect not to checkout.paystack.com"
ok $([ "$(orders)" = "$((O0+1))" ] && [ "$(inits)" = "$((I0+1))" ] && echo 1 || echo 0) "T1 expected exactly one order and one initialize"
OID=$($WP eval 'echo (wc_get_orders(["limit"=>1,"created_via"=>"sa_open_pay","return"=>"ids","orderby"=>"id","order"=>"DESC"])[0]);')
ok $([ "$($WP eval "echo wc_get_order($OID)->get_meta('_sa_op_hosted_url');")" = "$L" ] && echo 1 || echo 0) "T1 hosted URL not persisted in the real datastore"
ok $([ "$($WP eval "\$o=wc_get_order($OID); echo \$o->get_currency().'|'.(float)\$o->get_total().'|'.\$o->get_payment_method().'|'.\$o->get_status();")" = "KES|100|paystack|pending" ] && echo 1 || echo 0) "T1 order currency/total/method/status (got: $($WP eval "\$o=wc_get_order($OID); echo \$o->get_currency().'|'.(float)\$o->get_total().'|'.\$o->get_payment_method().'|'.\$o->get_status();"))"
# T2 duplicate submit of the same intent: same URL, no second order or initialize
L2=$(post 8080 $I1 100 a@example.invalid 10.0.0.2)
ok $([ "$L2" = "$L" ] && [ "$(orders)" = "$((O0+1))" ] && [ "$(inits)" = "$((I0+1))" ] && echo 1 || echo 0) "T2 duplicate created a second order or initialize"
# T3 different details on the same intent never reaches the provider
L3=$(post 8080 $I1 101 a@example.invalid 10.0.0.3)
ok $([[ "$L3" == *sa_pay_recover=* ]] && [ "$(inits)" = "$((I0+1))" ] && echo 1 || echo 0) "T3 mismatch must be non-payable"
# T4 missing / malformed intent: no order
O4=$(orders); L4=$(post 8080 "" 55 a@example.invalid 10.0.0.4); L5=$(post 8080 "zzzz" 55 a@example.invalid 10.0.0.5)
ok $([[ "$L4" == *sa_pay_err=* ]] && [[ "$L5" == *sa_pay_err=* ]] && [ "$(orders)" = "$O4" ] && echo 1 || echo 0) "T4 bad intent created an order"
# T5 concurrency: 8 simultaneous submits of one fresh intent against real MySQL + 8 PHP workers
I5=$(intent); O5=$(orders); N5=$(inits)
seq 1 8 | xargs -P8 -I{} bash -c "post 8080 $I5 120 c@example.invalid 10.0.1.{}" > /tmp/sa-it/t5.txt
cat /tmp/sa-it/t5.txt | sed 's#\(https://checkout.paystack.com/\).*#\1...#; s#\(sa_pay_recover=\).*#\1...#' | sort | uniq -c
EXT=$(grep -c '^https://checkout.paystack.com/' /tmp/sa-it/t5.txt || true); REC=$(grep -c 'sa_pay_recover=' /tmp/sa-it/t5.txt || true); OP=$(grep -c 'order-pay' /tmp/sa-it/t5.txt || true)
echo "T5 external=$EXT recover=$REC order-pay=$OP orders+=$(( $(orders)-O5 )) inits+=$(( $(inits)-N5 ))"
ok $([ "$(( $(orders)-O5 ))" = 1 ] && [ "$(( $(inits)-N5 ))" = 1 ] && [ "$OP" = 0 ] && [ "$EXT" -ge 1 ] && [ $((EXT+REC)) = 8 ] && echo 1 || echo 0) "T5 concurrency: not exactly one order and one initialize, or order-pay leaked"
# T6 real SIGKILL of the PHP process inside the provider call (state i), then retry on a fresh server
PHP_CLI_SERVER_WORKERS=0 php -S 127.0.0.1:8081 -t $W >/tmp/sa-it/server2.log 2>&1 & S2=$!
for i in $(seq 1 20); do curl -s -o /dev/null http://127.0.0.1:8081/wp-login.php && break; sleep 1; done
I6=$(intent); O6=$(orders); N6=$(inits)
post 8081 $I6 77 k@example.invalid 10.0.2.1 >/dev/null || true
sleep 1; kill $S2 2>/dev/null || true; wait $S2 2>/dev/null || true
PHP_CLI_SERVER_WORKERS=0 php -S 127.0.0.1:8082 -t $W >/tmp/sa-it/server3.log 2>&1 & S2=$!
for i in $(seq 1 20); do curl -s -o /dev/null http://127.0.0.1:8082/wp-login.php && break; sleep 1; done
L6=$(post 8082 $I6 77 k@example.invalid 10.0.2.2)
echo "T6 killed-attempt orders+=$(( $(orders)-O6 )) inits+=$(( $(inits)-N6 )) retry Location: ${L6%%=*}="
ok $([ "$(( $(orders)-O6 ))" = 1 ] && [ "$(( $(inits)-N6 ))" = 1 ] && [[ "$L6" == *sa_pay_recover=* ]] && [[ "$L6" != *order-pay* ]] && echo 1 || echo 0) "T6 after SIGKILL: second order/initialize or actionable page"
echo "--- outbound requests denied during the run (host/path only):"; sed -E 's#(https?://[^/?]+)(/[^?]*)?.*#\1\2#' $SA_IT_LOG/blocked.log | sort | uniq -c | head -20
echo "$n real WordPress/WooCommerce integration checks PASS (Paystack initialize stubbed; other HTTP denied; not provider proof)"
