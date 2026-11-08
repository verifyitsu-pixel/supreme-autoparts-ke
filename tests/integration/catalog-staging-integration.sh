#!/usr/bin/env bash
# Throwaway runner database, not the user's store. No live credential or provider.
set -euo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
W=/tmp/catalog-staging-wp
WP="php /tmp/catalog-staging-wp-cli.phar --path=$W"
sudo systemctl start mysql
mysql -uroot -proot -e 'DROP DATABASE IF EXISTS catalog_staging_it; CREATE DATABASE catalog_staging_it'
curl -fsSL -o /tmp/catalog-staging-wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
mkdir -p "$W"
$WP core download --version=7.1.4 --force
$WP config create --dbname=catalog_staging_it --dbuser=root --dbpass=root --dbhost=127.0.0.1 --skip-check
$WP core install --url=http://127.0.0.1:8091 --title=Catalog-test --admin_user=fixture --admin_password=LOCAL_THROWAWAY_ONLY_73910 --admin_email=fixture@example.invalid --skip-email
$WP option update home http://127.0.0.1:8091
$WP option update siteurl http://127.0.0.1:8091
$WP plugin install woocommerce --version=11.1.0 --activate
mkdir -p "$W/wp-content/mu-plugins"
cat > "$W/wp-content/mu-plugins/catalog-test-deny-outbound.php" <<'PHP'
<?php
add_filter('pre_http_request',function(){return new WP_Error('fixture_outbound_denied','Test fixture denies all outbound HTTP');},PHP_INT_MAX);
add_filter('pre_wp_mail',fn()=>false,PHP_INT_MAX);
PHP
$WP core version
$WP plugin get woocommerce --field=version
SA_CATALOG_TEST_REPO="$REPO" $WP eval-file "$REPO/tests/integration/catalog-staging-native.php" --use-include
