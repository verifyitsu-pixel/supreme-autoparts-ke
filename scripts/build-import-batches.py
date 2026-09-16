#!/usr/bin/env python3
"""Build require-images NDJSON import batches from products.ndjson (rolling)."""
from __future__ import annotations

import argparse
import json
from pathlib import Path


def has_real_image(p: dict) -> bool:
    for img in p.get("images") or []:
        if isinstance(img, dict):
            src = (img.get("src") or "").strip()
            if src.startswith("http"):
                return True
    return False


def has_sku(p: dict) -> bool:
    for v in p.get("variants") or []:
        if isinstance(v, dict) and str(v.get("sku") or "").strip():
            return True
    return False


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--src", default="data/scrape/products.ndjson")
    ap.add_argument("--outdir", default="data/scrape/chunks")
    ap.add_argument("--sizes", default="400,2000", help="Comma-separated batch sizes")
    args = ap.parse_args()
    src = Path(args.src)
    outdir = Path(args.outdir)
    outdir.mkdir(parents=True, exist_ok=True)
    sizes = [int(x) for x in args.sizes.split(",") if x.strip()]
    max_n = max(sizes)
    kept: list[str] = []
    scanned = 0
    with src.open(encoding="utf-8") as fh:
        for line in fh:
            scanned += 1
            line = line.strip()
            if not line:
                continue
            try:
                p = json.loads(line)
            except json.JSONDecodeError:
                continue
            if not has_real_image(p):
                continue
            # Prefer SKU when present; still keep image-only rows for catalog photos.
            kept.append(json.dumps(p, ensure_ascii=False, separators=(",", ":")))
            if len(kept) >= max_n:
                break
    for n in sizes:
        out = outdir / f"batch-with-images-{n}.ndjson"
        rows = kept[:n]
        out.write_text("\n".join(rows) + ("\n" if rows else ""), encoding="utf-8")
        print(f"wrote {len(rows)} -> {out} ({out.stat().st_size/1e6:.2f} MB) scanned={scanned}")


if __name__ == "__main__":
    main()
