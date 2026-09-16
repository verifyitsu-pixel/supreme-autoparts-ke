#!/usr/bin/env bash
# Apply custom_logo, site_icon, and WooCommerce email header logo on the live WP.
# Forces fresh media import for sports-car charcoal+amber lockup (replaces Apex attachments).
set -euo pipefail
cd /var/www/html
THEME_ASSETS="wp-content/themes/supreme-autoparts/assets"
WP=(wp --allow-root --path=/var/www/html)

logo_dark="${THEME_ASSETS}/logo.jpg"
[[ -f "$logo_dark" ]] || logo_dark="${THEME_ASSETS}/logo.png"
logo_light="${THEME_ASSETS}/logo-light.jpg"
[[ -f "$logo_light" ]] || logo_light="${THEME_ASSETS}/logo-light.png"
icon="${THEME_ASSETS}/icon.png"

for f in "$logo_dark" "$logo_light" "$icon"; do
  if [[ ! -f "$f" ]]; then
    echo "MISSING: $f" >&2
    exit 1
  fi
done

# Drop prior brand attachments so we do not keep old S-monogram / Bauhaus / AI-car files.
for title in \
  "Supreme Autoparts Logo Dark" \
  "Supreme Autoparts Logo Light" \
  "Supreme Autoparts Icon" \
  "Supreme Autoparts Apex Logo Dark" \
  "Supreme Autoparts Apex Logo Light" \
  "Supreme Autoparts Apex Icon" \
  "Supreme Autoparts Sports Car Logo Dark" \
  "Supreme Autoparts Sports Car Logo Light" \
  "Supreme Autoparts Sports Car Icon"
do
  ids=$("${WP[@]}" post list --post_type=attachment --title="$title" --field=ID --posts_per_page=20 2>/dev/null || true)
  for id in $ids; do
    if [[ "$id" =~ ^[0-9]+$ ]]; then
      "${WP[@]}" post delete "$id" --force 2>/dev/null || true
    fi
  done
done

import_file() {
  local file="$1" title="$2"
  "${WP[@]}" media import "$file" --title="$title" --porcelain
}

LOGO_ID=$(import_file "$logo_dark" "Supreme Autoparts Sports Car Logo Dark")
LIGHT_ID=$(import_file "$logo_light" "Supreme Autoparts Sports Car Logo Light")
ICON_ID=$(import_file "$icon" "Supreme Autoparts Sports Car Icon")

echo "LOGO_ID=$LOGO_ID LIGHT_ID=$LIGHT_ID ICON_ID=$ICON_ID"

"${WP[@]}" theme mod set custom_logo "$LOGO_ID"
"${WP[@]}" option update site_icon "$ICON_ID"

LIGHT_URL=$("${WP[@]}" eval "echo wp_get_attachment_url((int) $LIGHT_ID);")
if [[ -z "${LIGHT_URL:-}" ]]; then
  LIGHT_URL="https://www.supremeautoparts.co.ke/wp-content/themes/supreme-autoparts/assets/logo-light.jpg"
fi
LIGHT_URL="${LIGHT_URL/http:\/\//https:\/\/}"

"${WP[@]}" option update woocommerce_email_header_image "$LIGHT_URL"
"${WP[@]}" option update sa_email_logo_url "$LIGHT_URL"
"${WP[@]}" option update sa_brevo_sender_name "Supreme Autoparts"
"${WP[@]}" option update woocommerce_email_from_name "Supreme Autoparts"

"${WP[@]}" cache flush 2>/dev/null || true
"${WP[@]}" rewrite flush 2>/dev/null || true

echo "CUSTOM_LOGO=$("${WP[@]}" theme mod get custom_logo)"
echo "SITE_ICON=$("${WP[@]}" option get site_icon)"
echo "EMAIL_LOGO_URL=$LIGHT_URL"
echo "WOO_ACTIVE=$("${WP[@]}" plugin is-active woocommerce && echo yes || echo no)"
echo "BRANDING_APPLY_OK"
