#!/usr/bin/env bash
set -euo pipefail

# ---------------------------------------------------------------------------
# Map Railway MySQL plugin → WordPress DB env
# ---------------------------------------------------------------------------
if [[ -z "${WORDPRESS_DB_HOST:-}" && -n "${MYSQLHOST:-}" ]]; then
  export WORDPRESS_DB_HOST="${MYSQLHOST}:${MYSQLPORT:-3306}"
fi
[[ -z "${WORDPRESS_DB_USER:-}" && -n "${MYSQLUSER:-}" ]] && export WORDPRESS_DB_USER="$MYSQLUSER"
[[ -z "${WORDPRESS_DB_PASSWORD:-}" && -n "${MYSQLPASSWORD:-}" ]] && export WORDPRESS_DB_PASSWORD="$MYSQLPASSWORD"
[[ -z "${WORDPRESS_DB_NAME:-}" && -n "${MYSQLDATABASE:-}" ]] && export WORDPRESS_DB_NAME="$MYSQLDATABASE"
# Runtime guard: ensure Apache starts with only prefork MPM.
rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf 2>/dev/null || true
a2enmod mpm_prefork >/dev/null 2>&1 || true

export WORDPRESS_DB_HOST="${WORDPRESS_DB_HOST:-db:3306}"
export WORDPRESS_DB_USER="${WORDPRESS_DB_USER:-wordpress}"
export WORDPRESS_DB_PASSWORD="${WORDPRESS_DB_PASSWORD:-wordpress}"
export WORDPRESS_DB_NAME="${WORDPRESS_DB_NAME:-wordpress}"
export WP_HOME="${WP_HOME:-http://localhost:8080}"
export WP_SITEURL="${WP_SITEURL:-${WP_HOME}}"
export WOO_CURRENCY="${WOO_CURRENCY:-KES}"
export TZ="${TZ:-Africa/Nairobi}"
export WORDPRESS_ADMIN_USER="${WORDPRESS_ADMIN_USER:-admin}"
export WORDPRESS_ADMIN_PASSWORD="${WORDPRESS_ADMIN_PASSWORD:-adminpass}"
export WORDPRESS_ADMIN_EMAIL="${WORDPRESS_ADMIN_EMAIL:-admin@supremeautoparts.co.ke}"
export WORDPRESS_TITLE="${WORDPRESS_TITLE:-Supreme Autoparts}"

export WORDPRESS_CONFIG_EXTRA="${WORDPRESS_CONFIG_EXTRA:-}
if (!defined('WP_HOME')) define('WP_HOME', getenv('WP_HOME') ?: 'http://localhost:8080');
if (!defined('WP_SITEURL')) define('WP_SITEURL', getenv('WP_SITEURL') ?: WP_HOME);
if (!defined('FS_METHOD')) define('FS_METHOD', 'direct');
"

sync_custom_content() {
  mkdir -p /var/www/html/wp-content/themes /var/www/html/wp-content/plugins /var/www/html/wp-content/mu-plugins
  if [[ -d /usr/src/wordpress/wp-content/themes/supreme-autoparts ]]; then
    rm -rf /var/www/html/wp-content/themes/supreme-autoparts
    cp -a /usr/src/wordpress/wp-content/themes/supreme-autoparts /var/www/html/wp-content/themes/
  fi
  if [[ -d /usr/src/wordpress/wp-content/plugins/supreme-autoparts-core ]]; then
    rm -rf /var/www/html/wp-content/plugins/supreme-autoparts-core
    cp -a /usr/src/wordpress/wp-content/plugins/supreme-autoparts-core /var/www/html/wp-content/plugins/
  fi
  if [[ -d /usr/src/wordpress/wp-content/plugins/whop-payments ]]; then
    rm -rf /var/www/html/wp-content/plugins/whop-payments
    cp -a /usr/src/wordpress/wp-content/plugins/whop-payments /var/www/html/wp-content/plugins/
  fi
  if [[ -f /usr/src/wordpress/wp-content/mu-plugins/supreme-loader.php ]]; then
    cp -f /usr/src/wordpress/wp-content/mu-plugins/supreme-loader.php /var/www/html/wp-content/mu-plugins/ || true
  fi
  [[ -f /usr/src/wordpress/healthz.php ]] && cp -f /usr/src/wordpress/healthz.php /var/www/html/healthz.php
  if [[ -d /usr/src/supreme-data ]]; then
    mkdir -p /var/www/html/wp-content/plugins/supreme-autoparts-core/data
    cp -a /usr/src/supreme-data/. /var/www/html/wp-content/plugins/supreme-autoparts-core/data/ || true
  fi
  if [[ -d /usr/src/supreme-scripts ]]; then
    mkdir -p /var/www/html/wp-content/plugins/supreme-autoparts-core/scripts
    cp -a /usr/src/supreme-scripts/. /var/www/html/wp-content/plugins/supreme-autoparts-core/scripts/ || true
  fi
  chown -R www-data:www-data \
    /var/www/html/wp-content/themes/supreme-autoparts \
    /var/www/html/wp-content/plugins/supreme-autoparts-core \
    /var/www/html/wp-content/plugins/whop-payments 2>/dev/null || true
}

wait_for_db() {
  local host port
  host="${WORDPRESS_DB_HOST%%:*}"
  port="${WORDPRESS_DB_HOST##*:}"
  [[ "$host" == "$port" ]] && port=3306
  echo "[supreme] Waiting for MySQL at ${host}:${port}..."
  for _ in $(seq 1 90); do
    if mysqladmin ping -h"$host" -P"$port" -u"$WORDPRESS_DB_USER" -p"$WORDPRESS_DB_PASSWORD" --silent 2>/dev/null; then
      echo "[supreme] MySQL ready."
      return 0
    fi
    sleep 2
  done
  echo "[supreme] WARNING: MySQL not reachable after wait." >&2
  return 1
}

wp_as() {
  wp --allow-root --path=/var/www/html "$@"
}

bootstrap_wordpress() {
  # Wait until core files + wp-config exist (official entrypoint creates them)
  for _ in $(seq 1 60); do
    [[ -f /var/www/html/wp-settings.php && -f /var/www/html/wp-config.php ]] && break
    sleep 2
  done
  sync_custom_content
  wait_for_db || true

  if ! wp_as core is-installed 2>/dev/null; then
    echo "[supreme] Running wp core install..."
    wp_as core install \
      --url="$WP_HOME" \
      --title="$WORDPRESS_TITLE" \
      --admin_user="$WORDPRESS_ADMIN_USER" \
      --admin_password="$WORDPRESS_ADMIN_PASSWORD" \
      --admin_email="$WORDPRESS_ADMIN_EMAIL" \
      --skip-email || true
  else
    echo "[supreme] WordPress already installed — syncing URLs."
    wp_as option update home "$WP_HOME" || true
    wp_as option update siteurl "$WP_SITEURL" || true
  fi

  wp_as option update timezone_string "Africa/Nairobi" || true
  wp_as rewrite structure '/%postname%/' --hard || true

  if ! wp_as plugin is-installed woocommerce 2>/dev/null; then
    echo "[supreme] Installing WooCommerce..."
    wp_as plugin install woocommerce --activate || true
  else
    wp_as plugin activate woocommerce || true
  fi

  wp_as plugin activate supreme-autoparts-core || true
  wp_as plugin activate whop-payments || true
  wp_as theme activate supreme-autoparts || true

  wp_as option update woocommerce_currency "$WOO_CURRENCY" || true
  wp_as option update woocommerce_default_country "KE" || true
  wp_as option update woocommerce_currency_pos "left" || true
  wp_as option update woocommerce_price_thousand_sep "," || true
  wp_as option update woocommerce_price_decimal_sep "." || true
  wp_as option update woocommerce_price_num_decimals "2" || true
  wp_as option update woocommerce_store_city "Nairobi" || true
  wp_as option update woocommerce_enable_guest_checkout "yes" || true

  if [[ "${SUPREME_SEED_ON_BOOT:-1}" == "1" ]]; then
    wp_as supreme seed-categories 2>/dev/null || true
    wp_as supreme seed-pages 2>/dev/null || true
  fi

  wp_as rewrite flush --hard || true
  echo "[supreme] Bootstrap complete."
}

# Ensure healthz + custom code are in the image source tree for volume seeding
mkdir -p /usr/src/wordpress/wp-content/mu-plugins
if [[ -f /var/www/html/../.. ]]; then :; fi
# healthz already copied into image at /usr/src/wordpress/healthz.php via Dockerfile

# Background bootstrap after Apache/official entrypoint brings files online
(
  sleep 8
  bootstrap_wordpress || echo "[supreme] Bootstrap finished with warnings."
) &

exec docker-entrypoint.sh "$@"
