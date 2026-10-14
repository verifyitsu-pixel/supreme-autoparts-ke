#!/usr/bin/env bash
# Geo consistency regression check: one currency + zone-correct copy per visitor.
# Simulates zones with the X-Country-Code header the detector honours (same path as CF-IPCountry).
# Usage: scripts/geo-consistency-check.sh [base_url]
set -u
B="${1:-https://www.supremeautoparts.co.ke}"
PDP="${PDP:-/product/dba-13-22-honda-accord-ex-4cyl-w-292mm-front-rotor-front-xp-performance-brake-pads/}"
fail=0; T=$(mktemp -d)
chk() { if eval "$2"; then echo "  ok   $1"; else echo "  FAIL $1"; fail=1; fi; }
sym() { grep -o 'currencySymbol" translate="no">[^<]*' "$1" | sed 's/.*>//' | sort -u | tr '\n' ' '; }
for cc in KE US GB; do
  case $cc in KE) want='KES ';; US) want='&#36; ';; *) want='';; esac
  echo "== $cc (expect ${want% })"
  cj="$T/cj$cc"; H=(-s -L -b "$cj" -c "$cj" -H "X-Country-Code: $cc")
  curl "${H[@]}" "$B/shop/?nc=$RANDOM" -o "$T/shop.html"
  curl "${H[@]}" "$B/?nc=$RANDOM" -o "$T/home.html"
  curl "${H[@]}" "$B$PDP?nc=$RANDOM" -o "$T/pdp.html"
  # Other countries: whatever local currency the shop grid uses must be used everywhere.
  [ -n "$want" ] || want=$(sym "$T/shop.html")
  pid=$(grep -o 'data-product_id="[0-9]*"' "$T/shop.html" | head -1 | grep -o '[0-9]*')
  # Personalised rows: send a viewed-product profile cookie so the REST row renders.
  pz=$(python3 -c "import json,urllib.parse;print(urllib.parse.quote(json.dumps({'c':{},'b':{},'p':[int('$pid')],'q':[],'cart':[]})))")
  curl "${H[@]}" -b "sa_pz=$pz" "$B/wp-json/sa/v1/recs?ctx=home&_=$RANDOM" | python3 -c "import sys,json;print(json.load(sys.stdin).get('html',''))" > "$T/recs.html"
  for f in shop home pdp recs; do s=$(sym "$T/$f.html"); chk "$f prices [$s]" "[ -z \"$s\" ] || [ \"$s\" = \"$want\" ]"; done
  curl "${H[@]}" "$B/?add-to-cart=$pid" -o /dev/null
  curl "${H[@]}" "$B/checkout/?nc=$RANDOM" -o "$T/co.html"
  rates=$(grep -o 'value="[a-z_]*:[0-9]*" class="shipping_method"' "$T/co.html" | cut -d'"' -f2 | cut -d: -f1 | sort -u | tr '\n' ' ')
  s=$(sym "$T/co.html"); chk "checkout prices [$s]" "[ \"$s\" = \"$want\" ]"
  chk "checkout has a rate [$rates]" "[ -n \"$rates\" ]"
  chk "no woo error notice" "! grep -q 'woocommerce-error' $T/co.html"
  if [ "$cc" = KE ]; then
    chk "KE rates fargo+pickup" "echo '$rates' | grep -q sa_fargo && echo '$rates' | grep -q local_pickup"
    chk "KE roadside bar" "grep -q 'Broken down in Nairobi' $T/home.html"
    chk "KE Book installation" "grep -q 'Book installation' $T/pdp.html"
  else
    chk "no fargo/pickup rate" "! echo '$rates' | grep -Eq 'sa_fargo|local_pickup'"
    chk "no Nairobi roadside bar" "! grep -q 'Broken down in Nairobi' $T/home.html $T/shop.html $T/pdp.html"
    chk "no Book installation" "! grep -q 'Book installation' $T/pdp.html"
    chk "no Fargo on shop cards" "! grep -q 'Fargo delivery' $T/shop.html $T/recs.html"
  fi
done
rm -rf "$T"; [ $fail = 0 ] && echo "ALL PASS" || { echo "FAILURES"; exit 1; }
