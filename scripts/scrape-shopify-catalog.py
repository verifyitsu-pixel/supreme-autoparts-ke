#!/usr/bin/env python3
"""
Full Shopify public-JSON catalog scrape for supreme-mods.com → data/scrape/

Strategy (slow + resilient to 429):
  1) Paginate /products.json?limit=250&page=N  (bulk, preferred)
  2) Fetch /collections.json then each /collections/{handle}/products.json
  3) Parse sitemap index + sitemap_products_*.xml; gap-fill via /products/{handle}.json

Dedupes by product id. Supports --resume from progress.json + existing ndjson/chunks.

Usage:
  python3 scripts/scrape-shopify-catalog.py
  python3 scripts/scrape-shopify-catalog.py --resume --delay 8
"""

from __future__ import annotations

import argparse
import csv
import json
import os
import random
import re
import sys
import time
import traceback
from datetime import datetime, timedelta, timezone
from html import unescape
from http.cookiejar import CookieJar
from pathlib import Path
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.parse import unquote
from urllib.request import HTTPCookieProcessor, Request, build_opener

BASE_DEFAULT = "https://supreme-mods.com"
USER_AGENTS = [
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15",
    "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36",
    "Mozilla/5.0 (compatible; SupremeAutopartsBot/1.2; +https://supremeautoparts.co.ke; owner-authorized catalog mirror)",
]
LIMIT = 250
DEFAULT_DELAY = 7.0  # seconds between successful requests
CHUNK_BYTES = 35 * 1024 * 1024


def utc_now() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def nairobi_now() -> str:
    return (datetime.now(timezone.utc) + timedelta(hours=3)).strftime("%Y-%m-%d %H:%M:%S EAT")


class RateLimiter:
    def __init__(self, min_interval: float = DEFAULT_DELAY):
        self.min_interval = max(5.0, float(min_interval))
        self._last = 0.0

    def wait(self) -> None:
        now = time.monotonic()
        delta = now - self._last
        # small jitter so we don't look perfectly periodic
        need = self.min_interval + random.uniform(0.0, 1.5)
        if delta < need:
            time.sleep(need - delta)
        self._last = time.monotonic()

    def slow_down(self, add: float = 2.0, cap: float = 30.0) -> None:
        self.min_interval = min(cap, self.min_interval + add)
        print(f"[rate] min_interval now {self.min_interval:.1f}s @ {nairobi_now()}", flush=True)



def curl_get(url: str, ua: str, timeout: int = 120) -> tuple[int, bytes]:
    """Prefer curl — Shopify/CF often 429s bare urllib from this host."""
    import subprocess
    try:
        r = subprocess.run(
            [
                "curl", "-sS", "-L", "--compressed",
                "-A", ua,
                "-H", "Accept: application/json,text/xml,application/xml,*/*",
                "-H", "Accept-Language: en-US,en;q=0.9",
                "-w", "\n__HTTP_STATUS__%{http_code}",
                "--max-time", str(timeout),
                url,
            ],
            capture_output=True,
            timeout=timeout + 30,
        )
        out = r.stdout or b""
        # status trailer
        if b"__HTTP_STATUS__" in out:
            body, _, status_b = out.rpartition(b"__HTTP_STATUS__")
            try:
                code = int(status_b.strip() or b"0")
            except ValueError:
                code = 0
            return code, body
        return (0 if r.returncode else 200), out
    except Exception as e:
        raise URLError(str(e)) from e


class Scraper:
    def __init__(self, base_url: str, out_dir: Path, resume: bool = False, delay: float = DEFAULT_DELAY):
        self.base = base_url.rstrip("/")
        self.out = out_dir
        self.out.mkdir(parents=True, exist_ok=True)
        (self.out / "chunks").mkdir(parents=True, exist_ok=True)
        self.limiter = RateLimiter(delay)
        self._cookies = CookieJar()
        self._opener = build_opener(HTTPCookieProcessor(self._cookies))
        self._ua_i = 0
        self.seen_ids: set[int] = set()
        self.seen_handles: set[str] = set()
        self.collections: list[dict[str, Any]] = []
        self.errors: list[dict[str, Any]] = []
        self.stats: dict[str, Any] = {
            "started_at": utc_now(),
            "started_at_eat": nairobi_now(),
            "updated_at": utc_now(),
            "updated_at_eat": nairobi_now(),
            "status": "running",
            "phase": "init",
            "collections_total": 0,
            "collections_done": 0,
            "last_collection": None,
            "last_collection_index": -1,
            "products_json_page": 0,
            "products_json_done": False,
            "sitemap_files_done": 0,
            "sitemap_files_total": 0,
            "sitemap_handles_queued": 0,
            "sitemap_fetched": 0,
            "products_unique": 0,
            "ndjson_lines": 0,
            "products_seen_raw": 0,
            "requests": 0,
            "delay_s": self.limiter.min_interval,
            "chunk_index": 0,
            "errors": [],
        }
        self._ndjson_path = self.out / "products.ndjson"
        self._index_path = self.out / "products-index.csv"
        self._progress_path = self.out / "progress.json"
        self._collections_path = self.out / "collections.json"
        self._handles_path = self.out / "sitemap-handles.txt"
        self._ndjson_fh = None
        self._index_fh = None
        self._index_writer = None
        self._chunk_fh = None
        self._chunk_bytes = 0
        self._chunk_index = 0
        self._since_save = 0

        if resume:
            self._load_resume()

    def _next_ua(self) -> str:
        ua = USER_AGENTS[self._ua_i % len(USER_AGENTS)]
        self._ua_i += 1
        return ua

    def _load_resume(self) -> None:
        if self._progress_path.exists():
            try:
                prog = json.loads(self._progress_path.read_text(encoding="utf-8"))
                for k, v in prog.items():
                    if k == "errors":
                        continue
                    self.stats[k] = v
                self.errors = list(prog.get("errors") or [])
                self.stats["errors"] = self.errors
                self._chunk_index = int(prog.get("chunk_index") or 0)
                print(
                    f"[resume] unique={prog.get('products_unique')} "
                    f"phase={prog.get('phase')} collections_done={prog.get('collections_done')} "
                    f"products_json_page={prog.get('products_json_page')}",
                    flush=True,
                )
            except Exception as e:
                print(f"[resume] progress.json: {e}", flush=True)

        if self._collections_path.exists():
            try:
                raw = json.loads(self._collections_path.read_text(encoding="utf-8"))
                self.collections = raw.get("collections", raw) if isinstance(raw, dict) else raw
                if not isinstance(self.collections, list):
                    self.collections = []
                self.stats["collections_total"] = len(self.collections)
            except Exception as e:
                print(f"[resume] collections.json: {e}", flush=True)

        paths = []
        if self._ndjson_path.exists():
            paths.append(self._ndjson_path)
        paths.extend(sorted((self.out / "chunks").glob("products-*.ndjson")))
        ndjson_lines = 0
        for p in paths:
            try:
                with p.open("r", encoding="utf-8") as fh:
                    for line in fh:
                        line = line.strip()
                        if not line:
                            continue
                        if p == self._ndjson_path:
                            ndjson_lines += 1
                        try:
                            obj = json.loads(line)
                            pid = obj.get("id")
                            handle = obj.get("handle")
                            if pid is not None:
                                self.seen_ids.add(int(pid))
                            if handle:
                                self.seen_handles.add(str(handle))
                        except json.JSONDecodeError:
                            continue
            except Exception as e:
                print(f"[resume] scan {p}: {e}", flush=True)
        self.stats["products_unique"] = len(self.seen_ids)
        self.stats["ndjson_lines"] = ndjson_lines
        print(f"[resume] seen ids={len(self.seen_ids)} handles={len(self.seen_handles)}", flush=True)

    def _open_writers(self, append: bool) -> None:
        mode = "a" if append else "w"
        self._ndjson_fh = self._ndjson_path.open(mode, encoding="utf-8")
        index_exists = self._index_path.exists() and append and self._index_path.stat().st_size > 0
        self._index_fh = self._index_path.open(mode, encoding="utf-8", newline="")
        self._index_writer = csv.writer(self._index_fh)
        if not index_exists:
            self._index_writer.writerow(
                ["id", "handle", "title", "vendor", "product_type", "price",
                 "compare_at", "sku", "image", "updated_at"]
            )
            self._index_fh.flush()
        chunk_path = self.out / "chunks" / f"products-{self._chunk_index:04d}.ndjson"
        if append and chunk_path.exists():
            self._chunk_fh = chunk_path.open("a", encoding="utf-8")
            self._chunk_bytes = chunk_path.stat().st_size
        else:
            self._open_new_chunk()

    def _open_new_chunk(self) -> None:
        if self._chunk_fh:
            self._chunk_fh.close()
        chunk_path = self.out / "chunks" / f"products-{self._chunk_index:04d}.ndjson"
        self._chunk_fh = chunk_path.open("a", encoding="utf-8")
        self._chunk_bytes = chunk_path.stat().st_size if chunk_path.exists() else 0
        self.stats["chunk_index"] = self._chunk_index

    def _rotate_chunk_if_needed(self, nbytes: int) -> None:
        if self._chunk_bytes + nbytes > CHUNK_BYTES and self._chunk_bytes > 0:
            self._chunk_index += 1
            self._open_new_chunk()

    def close(self) -> None:
        for fh in (self._ndjson_fh, self._index_fh, self._chunk_fh):
            if fh:
                try:
                    fh.flush()
                    fh.close()
                except Exception:
                    pass
        self._ndjson_fh = self._index_fh = self._chunk_fh = None

    def fetch_bytes(self, path_or_url: str, retries: int = 14, accept: str = "application/json,text/xml,*/*") -> bytes | None:
        url = path_or_url if path_or_url.startswith("http") else f"{self.base}{path_or_url}"
        url = unescape(url)
        backoff = 10.0
        last_err: Exception | None = None
        for attempt in range(retries):
            self.limiter.wait()
            try:
                ua = self._next_ua()
                code, body = curl_get(url, ua)
                if code == 404:
                    return None
                if code == 429 or code >= 500 or code == 0:
                    raise HTTPError(url, code or 429, f"curl status {code}", hdrs=None, fp=None)
                if code >= 400:
                    raise HTTPError(url, code, f"curl status {code}", hdrs=None, fp=None)
                self.stats["requests"] = int(self.stats.get("requests") or 0) + 1
                self.stats["delay_s"] = self.limiter.min_interval
                return body
            except HTTPError as e:
                last_err = e
                code = e.code
                if code == 429 or code >= 500:
                    if code == 429:
                        self.limiter.slow_down(add=3.0, cap=45.0)
                    retry_after = e.headers.get("Retry-After") if e.headers else None
                    try:
                        sleep_for = float(retry_after) if retry_after else backoff
                    except ValueError:
                        sleep_for = backoff
                    sleep_for = min(max(sleep_for, backoff), 300.0)
                    print(f"[http {code}] {url} attempt={attempt+1}/{retries} sleep={sleep_for:.0f}s", flush=True)
                    time.sleep(sleep_for)
                    backoff = min(backoff * 1.8, 180.0)
                    continue
                if code == 404:
                    return None
                print(f"[http {code}] {url} fatal", flush=True)
                raise
            except (URLError, TimeoutError, OSError) as e:
                last_err = e
                print(f"[net] {url} {type(e).__name__}: {e} attempt={attempt+1} sleep={backoff:.0f}s", flush=True)
                time.sleep(backoff)
                backoff = min(backoff * 1.8, 180.0)
        self.errors.append({"url": url, "error": str(last_err), "at": utc_now()})
        self.stats["errors"] = self.errors[-100:]
        self.save_progress()
        raise RuntimeError(f"Failed after retries: {url}: {last_err}")

    def fetch_json(self, path_or_url: str) -> Any:
        body = self.fetch_bytes(path_or_url, accept="application/json,text/plain,*/*")
        if body is None:
            return None
        return json.loads(body.decode("utf-8"))

    def save_progress(self) -> None:
        self.stats["updated_at"] = utc_now()
        self.stats["updated_at_eat"] = nairobi_now()
        self.stats["products_unique"] = len(self.seen_ids)
        self.stats["errors"] = self.errors[-100:]
        self.stats["chunk_index"] = self._chunk_index
        self.stats["delay_s"] = self.limiter.min_interval
        tmp = self._progress_path.with_suffix(".json.tmp")
        tmp.write_text(json.dumps(self.stats, indent=2), encoding="utf-8")
        tmp.replace(self._progress_path)
        self._since_save = 0

    def write_product(self, product: dict[str, Any]) -> bool:
        pid = product.get("id")
        handle = product.get("handle") or ""
        if pid is None:
            return False
        pid = int(pid)
        if pid in self.seen_ids:
            return False
        self.seen_ids.add(pid)
        if handle:
            self.seen_handles.add(handle)

        line = json.dumps(product, ensure_ascii=False, separators=(",", ":"))
        encoded = (line + "\n").encode("utf-8")
        assert self._ndjson_fh and self._chunk_fh and self._index_writer

        self._ndjson_fh.write(line + "\n")
        self._rotate_chunk_if_needed(len(encoded))
        self._chunk_fh.write(line + "\n")
        self._chunk_bytes += len(encoded)

        variants = product.get("variants") or []
        v0 = variants[0] if variants else {}
        images = product.get("images") or []
        img = ""
        if images and isinstance(images[0], dict):
            img = images[0].get("src") or ""
        self._index_writer.writerow([
            pid, handle, product.get("title") or "", product.get("vendor") or "",
            product.get("product_type") or "", v0.get("price") or "",
            v0.get("compare_at_price") or "", v0.get("sku") or "", img,
            product.get("updated_at") or "",
        ])
        self.stats["products_unique"] = len(self.seen_ids)
        self.stats["ndjson_lines"] = int(self.stats.get("ndjson_lines") or 0) + 1
        self._since_save += 1
        if self._since_save >= 50:
            self.flush_writers()
            self.save_progress()
        return True

    def flush_writers(self) -> None:
        if self._ndjson_fh:
            self._ndjson_fh.flush()
        if self._index_fh:
            self._index_fh.flush()
        if self._chunk_fh:
            self._chunk_fh.flush()

    def write_readme(self) -> None:
        text = f"""# Shopify catalog scrape (supreme-mods.com)

Owner-authorized full catalog mirror for Supreme Autoparts.

## Strategy

1. `/products.json` pagination (bulk)
2. `/collections.json` + `/collections/{{handle}}/products.json`
3. Sitemap gap-fill: `sitemap.xml` → `sitemap_products_*.xml` → `/products/{{handle}}.json`

Rate limit: **≥5–10s** between requests (adaptive up to 45s on 429).

## Resume

```bash
python3 scripts/scrape-shopify-catalog.py --resume --delay 8
nohup python3 -u scripts/scrape-shopify-catalog.py --resume --delay 8 >> data/scrape/scrape.log 2>&1 &
```

## Monitor

```bash
tail -f data/scrape/scrape.log
cat data/scrape/progress.json
wc -l data/scrape/products.ndjson
```

## Import

```bash
wp supreme import-ndjson --file=/var/www/html/data/scrape/products.ndjson --limit=500 --offset=0
```

Huge `products.ndjson` is gitignored; use Railway volume or `chunks/` (<40MB).

Updated: {nairobi_now()}
"""
        (self.out / "README.md").write_text(text, encoding="utf-8")

    # ---- phases ----

    def phase_products_json(self) -> None:
        if self.stats.get("products_json_done"):
            print("[products.json] already done", flush=True)
            return
        self.stats["phase"] = "products_json"
        self.save_progress()
        page = int(self.stats.get("products_json_page") or 0) + 1
        while True:
            try:
                data = self.fetch_json(f"/products.json?limit={LIMIT}&page={page}")
            except Exception as e:
                # Shopify's legacy public JSON endpoint rejects page 101 with
                # HTTP 400 even when pages 1-100 were fully consumed. Treat
                # that endpoint cap as completion rather than a resumable
                # error, otherwise every restart retries page 101 forever.
                if isinstance(e, HTTPError) and e.code == 400 and page > 100:
                    self.stats["products_json_done"] = True
                    self.save_progress()
                    print(f"[products.json] endpoint page cap reached at page={page}", flush=True)
                    return
                print(f"[products.json] stop page={page}: {e}", flush=True)
                self.errors.append({"phase": "products_json", "page": page, "error": str(e), "at": utc_now()})
                # don't mark done — allow resume retry
                self.save_progress()
                return
            if data is None:
                break
            products = data.get("products") or []
            if not products:
                break
            added = 0
            for p in products:
                self.stats["products_seen_raw"] = int(self.stats.get("products_seen_raw") or 0) + 1
                if self.write_product(p):
                    added += 1
            self.stats["products_json_page"] = page
            self.flush_writers()
            self.save_progress()
            print(
                f"[products.json page={page}] batch={len(products)} +{added} "
                f"unique={len(self.seen_ids)} delay={self.limiter.min_interval:.1f}s @ {nairobi_now()}",
                flush=True,
            )
            if len(products) < LIMIT:
                break
            page += 1
            if page > 5000:
                print("[products.json] page>5000 stop", flush=True)
                break
        self.stats["products_json_done"] = True
        self.save_progress()

    def phase_collections(self) -> None:
        self.stats["phase"] = "collections"
        self.save_progress()
        if not self.collections:
            all_cols: list[dict[str, Any]] = []
            page = 1
            while True:
                data = self.fetch_json(f"/collections.json?limit={LIMIT}&page={page}")
                if data is None:
                    break
                cols = data.get("collections") or []
                if not cols:
                    break
                all_cols.extend(cols)
                print(f"[collections] page={page} got={len(cols)} total={len(all_cols)}", flush=True)
                self.save_progress()
                page += 1
                if len(cols) < LIMIT:
                    break
            self.collections = all_cols
            self.stats["collections_total"] = len(all_cols)
            self._collections_path.write_text(
                json.dumps({
                    "scraped_at": utc_now(),
                    "scraped_at_eat": nairobi_now(),
                    "source": self.base,
                    "count": len(all_cols),
                    "collections": all_cols,
                }, indent=2),
                encoding="utf-8",
            )
            self.save_progress()

        self.stats["phase"] = "collection_products"
        start_idx = int(self.stats.get("last_collection_index") or -1) + 1
        total = len(self.collections)
        for i in range(start_idx, total):
            col = self.collections[i]
            handle = col.get("handle") or ""
            if not handle:
                continue
            added = 0
            page = 1
            try:
                while True:
                    data = self.fetch_json(f"/collections/{handle}/products.json?limit={LIMIT}&page={page}")
                    if data is None:
                        break
                    products = data.get("products") or []
                    if not products:
                        break
                    for p in products:
                        self.stats["products_seen_raw"] = int(self.stats.get("products_seen_raw") or 0) + 1
                        if self.write_product(p):
                            added += 1
                    if len(products) < LIMIT:
                        break
                    page += 1
                    if page > 5000:
                        break
            except Exception as e:
                self.errors.append({"collection": handle, "error": str(e), "at": utc_now()})
                print(f"[ERR] collection={handle}: {e}", flush=True)
            self.stats["collections_done"] = i + 1
            self.stats["last_collection"] = handle
            self.stats["last_collection_index"] = i
            self.flush_writers()
            self.save_progress()
            print(
                f"[collection {i+1}/{total}] {handle!r} +{added} "
                f"unique={len(self.seen_ids)} @ {nairobi_now()}",
                flush=True,
            )

    def phase_sitemap_gapfill(self) -> None:
        self.stats["phase"] = "sitemap"
        self.save_progress()
        # Load or build handle list
        handles: list[str] = []
        if self._handles_path.exists() and self._handles_path.stat().st_size > 0:
            handles = [ln.strip() for ln in self._handles_path.read_text(encoding="utf-8").splitlines() if ln.strip()]
            print(f"[sitemap] loaded {len(handles)} handles from cache", flush=True)
        else:
            body = self.fetch_bytes("/sitemap.xml", accept="application/xml,text/xml,*/*")
            if not body:
                print("[sitemap] no sitemap.xml", flush=True)
                return
            index_xml = body.decode("utf-8", errors="replace")
            locs = [unescape(u) for u in re.findall(r"<loc>\s*([^<]+)\s*</loc>", index_xml)]
            product_maps = [u for u in locs if "sitemap_products" in u]
            self.stats["sitemap_files_total"] = len(product_maps)
            print(f"[sitemap] {len(product_maps)} product sitemap files", flush=True)
            start_file = int(self.stats.get("sitemap_files_done") or 0)
            seen_h: set[str] = set()
            for fi, sm_url in enumerate(product_maps):
                if fi < start_file:
                    continue
                try:
                    xb = self.fetch_bytes(sm_url, accept="application/xml,text/xml,*/*")
                except Exception as e:
                    self.errors.append({"sitemap": sm_url, "error": str(e), "at": utc_now()})
                    print(f"[sitemap] fail {sm_url}: {e}", flush=True)
                    self.save_progress()
                    continue
                if not xb:
                    continue
                xml = xb.decode("utf-8", errors="replace")
                urls = re.findall(r"<loc>\s*([^<]+)\s*</loc>", xml)
                for u in urls:
                    u = unescape(u).strip()
                    # https://supreme-mods.com/products/HANDLE
                    m = re.search(r"/products/([^/?#]+)", u)
                    if not m:
                        continue
                    h = unquote(m.group(1))
                    if h not in seen_h:
                        seen_h.add(h)
                        handles.append(h)
                self.stats["sitemap_files_done"] = fi + 1
                self.stats["sitemap_handles_queued"] = len(handles)
                if (fi + 1) % 5 == 0 or fi + 1 == len(product_maps):
                    self._handles_path.write_text("\n".join(handles) + "\n", encoding="utf-8")
                    self.save_progress()
                    print(
                        f"[sitemap] files {fi+1}/{len(product_maps)} handles={len(handles)} "
                        f"@ {nairobi_now()}",
                        flush=True,
                    )
            self._handles_path.write_text("\n".join(handles) + "\n", encoding="utf-8")

        self.stats["sitemap_handles_queued"] = len(handles)
        self.stats["phase"] = "sitemap_fetch"
        self.save_progress()

        # Gap-fill missing handles
        missing = [h for h in handles if h not in self.seen_handles]
        print(f"[sitemap] gap-fill {len(missing)} / {len(handles)} handles not yet scraped", flush=True)
        fetched_already = int(self.stats.get("sitemap_fetched") or 0)
        # allow resume mid-gapfill by skipping first N successful attempts tracked
        # simpler: skip handles already in seen_handles (done above)
        for n, handle in enumerate(missing):
            if handle in self.seen_handles:
                continue
            try:
                data = self.fetch_json(f"/products/{handle}.json")
            except Exception as e:
                self.errors.append({"handle": handle, "error": str(e), "at": utc_now()})
                if (n + 1) % 20 == 0:
                    self.save_progress()
                continue
            if not data:
                continue
            product = data.get("product") if isinstance(data, dict) else None
            if not isinstance(product, dict):
                continue
            self.stats["products_seen_raw"] = int(self.stats.get("products_seen_raw") or 0) + 1
            self.write_product(product)
            self.stats["sitemap_fetched"] = fetched_already + n + 1
            if (n + 1) % 10 == 0:
                self.flush_writers()
                self.save_progress()
                print(
                    f"[sitemap fetch {n+1}/{len(missing)}] unique={len(self.seen_ids)} "
                    f"last={handle!r} @ {nairobi_now()}",
                    flush=True,
                )
        self.flush_writers()
        self.save_progress()

    def run(self, resume: bool = False) -> None:
        append = resume and (self._ndjson_path.exists() or len(self.seen_ids) > 0)
        self._open_writers(append=append)
        # A resumed process must advertise its live state even when the
        # previous process checkpointed as interrupted or errored.
        self.stats["status"] = "running"
        self.write_readme()
        self.save_progress()
        try:
            # Bulk JSON first; on persistent empty, sitemap still runs after
            self.phase_products_json()
            # Sitemap early so we accumulate even if collections.json is hot-429
            self.phase_sitemap_gapfill()
            self.phase_collections()

            self.stats["status"] = "complete"
            self.stats["phase"] = "done"
            self.stats["finished_at"] = utc_now()
            self.stats["finished_at_eat"] = nairobi_now()
            self.stats["products_unique"] = len(self.seen_ids)
            self.flush_writers()
            self.save_progress()
            self.write_readme()
            print(f"[DONE] unique={len(self.seen_ids)} requests={self.stats.get('requests')} @ {nairobi_now()}", flush=True)
        except KeyboardInterrupt:
            self.stats["status"] = "interrupted"
            self.flush_writers()
            self.save_progress()
            print("[interrupted] re-run with --resume", flush=True)
            raise
        except Exception:
            self.stats["status"] = "error"
            self.errors.append({"fatal": traceback.format_exc(), "at": utc_now()})
            self.flush_writers()
            self.save_progress()
            raise
        finally:
            self.close()


def main() -> int:
    ap = argparse.ArgumentParser(description="Scrape Shopify public catalog JSON (slow/resilient)")
    ap.add_argument("--base-url", default=BASE_DEFAULT)
    ap.add_argument("--out", default="data/scrape")
    ap.add_argument("--resume", action="store_true")
    ap.add_argument("--delay", type=float, default=DEFAULT_DELAY, help="Min seconds between requests (default 7)")
    args = ap.parse_args()

    repo = Path(__file__).resolve().parent.parent
    out = Path(args.out)
    if not out.is_absolute():
        out = repo / out

    print(
        f"[start] base={args.base_url} out={out} resume={args.resume} "
        f"delay={args.delay}s @ {nairobi_now()}",
        flush=True,
    )
    scraper = Scraper(args.base_url, out, resume=args.resume, delay=args.delay)
    scraper.run(resume=args.resume)
    return 0


if __name__ == "__main__":
    sys.exit(main())
