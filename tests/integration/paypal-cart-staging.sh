#!/usr/bin/env bash
# Disposable runner only. Never production credentials, data, volumes or services.
set -euo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
WORK="${SA_STAGE_WORK:-/tmp/sa-paypal-cart-fixture}"
EVIDENCE="${SA_STAGE_EVIDENCE:-/tmp/sa-paypal-cart-evidence}"
export SA_STAGE_EVIDENCE="$EVIDENCE"
export SA_STAGE_DB_HOST=127.0.0.1 SA_STAGE_DB_USER=root SA_STAGE_DB_NAME=sa_paypal_fixture
: "${SA_STAGE_DB_PASSWORD:?Set only the disposable runner MySQL password}"
# No destructive reset of an existing DB. This script refuses if it already exists.
export MYSQL_PWD="$SA_STAGE_DB_PASSWORD"
test ! -e "$WORK"
mkdir -p "$WORK" "$EVIDENCE"
mysql -h127.0.0.1 -uroot -e 'CREATE DATABASE sa_paypal_fixture'
cleanup() { if [ -n "${SERVER_PID:-}" ]; then kill "$SERVER_PID" 2>/dev/null || true; wait "$SERVER_PID" 2>/dev/null || true; fi; }
trap cleanup EXIT
curl -fsSL https://wordpress.org/latest.zip -o "$WORK/wordpress.zip"
curl -fsSL https://downloads.wordpress.org/plugin/woocommerce.11.1.0.zip -o "$WORK/woo.zip"
curl -fsSL https://downloads.wordpress.org/plugin/woocommerce-paypal-payments.4.1.3.zip -o "$WORK/paypal.zip"
# Exact package hashes from the inspected rehearsal. Fail on changed downloads.
(cd "$WORK" && sha256sum -c "$REPO/tests/staging/paypal-cart/downloads.sha256")
unzip -q "$WORK/wordpress.zip" -d "$WORK"
WP="$WORK/wordpress"
unzip -q "$WORK/woo.zip" -d "$WP/wp-content/plugins"
unzip -q "$WORK/paypal.zip" -d "$WP/wp-content/plugins"
cp -a "$REPO/wp-content/themes/supreme-autoparts" "$WP/wp-content/themes/"
cp -a "$REPO/tests/staging/paypal-cart/mu-plugins" "$WP/wp-content/"
cp -a "$REPO/tests/staging/paypal-cart/templates" "$WP/wp-content/"
cat > "$WP/wp-config.php" <<'PHP'
<?php
define('DB_NAME',getenv('SA_STAGE_DB_NAME'));define('DB_USER',getenv('SA_STAGE_DB_USER'));define('DB_PASSWORD',getenv('SA_STAGE_DB_PASSWORD'));define('DB_HOST',getenv('SA_STAGE_DB_HOST'));define('DB_CHARSET','utf8mb4');$table_prefix='wp_';
define('WP_ENVIRONMENT_TYPE','staging');define('SA_SYNTHETIC_STAGING',true);define('DISABLE_WP_CRON',true);define('DISALLOW_FILE_MODS',true);define('WP_DEBUG',true);define('WP_DEBUG_DISPLAY',false);define('WP_DEBUG_LOG',true);define('WP_HOME','http://127.0.0.1:8097');define('WP_SITEURL','http://127.0.0.1:8097');
define('AUTH_KEY','fictional-local-disabled-login-only');define('SECURE_AUTH_KEY','fictional-local-disabled-login-only');define('LOGGED_IN_KEY','fictional-local-disabled-login-only');define('NONCE_KEY','fictional-local-disabled-login-only');
if(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');require_once ABSPATH.'wp-settings.php';
PHP
cat > "$WORK/install.php" <<'PHP'
<?php
define('WP_INSTALLING',true);require __DIR__.'/wordpress/wp-load.php';require_once ABSPATH.'wp-admin/includes/upgrade.php';
$result=wp_install('Private synthetic staging','synthetic-review-disabled','fixture@example.invalid',false,'','', 'en_US');unset($result);
$wpdb->update($wpdb->users,['user_pass'=>'!synthetic-staging-login-disabled'],['ID'=>1]);
update_option('active_plugins',['woocommerce/woocommerce.php','woocommerce-paypal-payments/woocommerce-paypal-payments.php']);update_option('template','supreme-autoparts');update_option('stylesheet','supreme-autoparts');echo "Installed local disabled-login fixture\n";
PHP
php "$WORK/install.php"
export SA_STAGE_REPO="$REPO" SA_STAGE_WORK="$WORK"
cat > "$WORK/seed.php" <<'PHP'
<?php require __DIR__.'/wordpress/wp-load.php'; require getenv('SA_STAGE_REPO').'/tests/staging/paypal-cart/bootstrap.php';
PHP
php "$WORK/seed.php" > "$EVIDENCE/seed.json"
export SA_STAGE_SEED="$EVIDENCE/seed.json"
cat "$SA_STAGE_SEED"
php -S 127.0.0.1:8097 -t "$WP" > "$EVIDENCE/server.log" 2>&1 &
SERVER_PID=$!
for i in {1..40}; do if curl -sS http://127.0.0.1:8097/ -o "$EVIDENCE/entry.html"; then break; fi; sleep .1; done
# Install Playwright into this disposable worktree, never the store repository.
npm install --prefix "$WORK" --no-audit --no-fund playwright@1.65.1
"$WORK/node_modules/.bin/playwright" install --with-deps chromium
NODE_PATH="$WORK/node_modules" node "$REPO/tests/staging/paypal-cart/browser.cjs" | tee "$EVIDENCE/browser.log"
cat > "$WORK/verify.php" <<'PHP'
<?php require __DIR__.'/wordpress/wp-load.php'; require getenv('SA_STAGE_REPO').'/tests/staging/paypal-cart/verify-state.php';
PHP
php "$WORK/verify.php" | tee "$EVIDENCE/isolation.log"
