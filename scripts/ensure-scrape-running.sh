#!/usr/bin/env bash
# Keep supreme-mods scrape alive. Never kills an existing scrape.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
PATTERN='scripts/scrape-shopify-catalog.py'
if pgrep -f "$PATTERN" >/dev/null 2>&1; then
  echo "[ok] scrape already running: $(pgrep -af "$PATTERN" | head -1)"
  exit 0
fi
mkdir -p data/scrape
echo "[resume] starting scrape --resume --delay 10 @ $(TZ=Africa/Nairobi date '+%Y-%m-%d %H:%M:%S %Z')"
nohup python3 -u scripts/scrape-shopify-catalog.py --resume --delay 10 >> data/scrape/scrape.log 2>&1 &
echo "[started] pid=$!"
