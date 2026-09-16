#!/usr/bin/env python3
"""Pick one real Shopify CDN product photo per homepage product-type category.

Sources: data/scrape/products-index.csv (preferred) and batch-with-images NDJSON.
Never invents images — only cdn.shopify.com URLs already in the scrape.
"""
from __future__ import annotations

import csv
import json
import os
import re
import sys
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
INDEX = ROOT / "data/scrape/products-index.csv"
BATCH = ROOT / "data/scrape/chunks/batch-with-images-400.ndjson"
OUT_DIR = ROOT / "wp-content/themes/supreme-autoparts/assets/images/categories"
MANIFEST = OUT_DIR / "manifest.json"

# Ordered preferred product_type substrings (most iconic first).
PREFERRED: dict[str, list[str]] = {
    "air-intake": [
        "Cold Air Intakes",
        "Air Intake Systems",
        "Air Filters - Direct Fit",
        "Air Filters - Universal Fit",
        "Intercooler Pipe",
    ],
    "brakes": [
        "Big Brake Kits",
        "Brake Rotors - Slotted",
        "Brake Rotors - 2 Piece",
        "Brake Kits - Performance",
        "Brake Pads - Performance",
        "Brake Calipers - Perf",
    ],
    "drivetrain": [
        "Clutch Kits",
        "Differentials",
        "Driveshafts",
        "Axles",
        "Transfer Cases",
        "Torque Converters",
    ],
    "engine": [
        "Turbochargers",
        "Superchargers",
        "Spark Plugs",
        "Oil Filters",
        "Radiators",
        "Water Pumps",
        "Fuel Injectors",
        "Camshafts",
    ],
    "exhaust": [
        "Catback",
        "Cat-Back",
        "Axleback",
        "Headers",
        "Downpipes",
        "Mufflers",
        "Exhaust Tips",
    ],
    "exterior": [
        "Body Armor",
        "Grilles",
        "Off Road Bumpers",
        "Bumper Accessories",
        "Fender Flares",
        "Running Boards",
        "Spoilers",
        "Tonneau Covers",
        "Mud Flaps",
    ],
    "interior": [
        "Steering Wheels",
        "Seat Covers",
        "Gauges",
        "Shift Knobs",
        "Floor Mats - Rubber",
        "Pedals",
    ],
    "lighting": [
        "Headlights",
        "Fog Lights",
        "Light Bars",
        "LED Lights",
        "Tail Lights",
        "Off-Road Lights",
    ],
    "suspension": [
        "Coilovers",
        "Lift Kits",
        "Shocks and Struts",
        "Lowering Springs",
        "Control Arms",
        "Sway Bars",
    ],
    "tires": [
        "Automotive/UTV Tires",
        "Light Truck Tires",
        "Passenger Tires",
        "All Terrain",
    ],
    "wheels": [
        "Wheels - Forged",
        "Wheels - Cast",
        "Wheels - Flow Form",
    ],
}

# Soft preference boosts / penalties on handle+title+type blob.
BOOST = {
    "air-intake": [r"\bk-n\b", r"\bafe\b", r"cold.?air", r"intake.?system"],
    "brakes": [r"\bebc\b", r"rotor", r"caliper", r"big.?brake"],
    "drivetrain": [r"\bmcleod\b", r"\bact\b", r"clutch.?kit"],
    "engine": [r"turbo", r"\bhks\b", r"spark"],
    "exhaust": [r"\bcorsa\b", r"\bbanks\b", r"catback", r"tip"],
    "exterior": [r"armor", r"grille", r"bumper", r"bushwacker"],
    "interior": [r"steering.?wheel", r"\bprp\b", r"weathertech"],
    "lighting": [r"alpharex", r"headlight", r"baja.?design"],
    "suspension": [r"\bfox\b", r"\bicon\b", r"coilover"],
    "tires": [r"mickey.?thompson", r"bfgoodrich", r"toyo", r"falken", r"\blt\b", r"baja"],
    "wheels": [r"vossen", r"kansei", r"\bicon\b", r"method", r"wheel"],
}
PENALIZE = {
    "tires": [r"motorcycle", r"\bm-c\b", r"dirt.?bike"],
    "air-intake": [r"cabin.?air", r"powersport", r"trx\d"],
    "interior": [r"window.?shade", r"protective.?film"],
    "exterior": [r"chrome.?filter"],  # false positive from "chrome"
}


def is_shopify_cdn(url: str) -> bool:
    return bool(url) and "cdn.shopify.com" in url and url.startswith("http")


def score_row(slug: str, pt: str, title: str, handle: str, img: str) -> tuple[int, int] | None:
    prefs = PREFERRED[slug]
    rank = None
    pt_l = pt.lower()
    for i, pref in enumerate(prefs):
        if pref.lower() in pt_l:
            rank = i
            break
    if rank is None:
        return None

    blob = f"{pt} {title} {handle}".lower()
    for pat in PENALIZE.get(slug, []):
        if re.search(pat, blob, re.I):
            return None

    boost = 0
    for pat in BOOST.get(slug, []):
        if re.search(pat, blob, re.I):
            boost += 3
    # Prefer jpg photos over png packaging shots when tied.
    if re.search(r"\.(jpe?g)(\?|$)", img, re.I):
        boost += 1
    elif re.search(r"\.png(\?|$)", img, re.I):
        boost -= 1
    # Prefer larger-looking product photos (L.jpg suffix common in this catalog).
    if re.search(r"L\.(jpe?g|png)", img):
        boost += 1

    # Lower rank number is better; higher boost is better → sort by (rank, -boost)
    return (rank, -boost)


def iter_rows():
    if INDEX.is_file():
        with INDEX.open(newline="", encoding="utf-8", errors="replace") as f:
            for row in csv.DictReader(f):
                yield {
                    "handle": (row.get("handle") or "").strip(),
                    "title": (row.get("title") or "").strip(),
                    "product_type": (row.get("product_type") or "").strip(),
                    "image": (row.get("image") or "").strip(),
                }
    if BATCH.is_file():
        with BATCH.open(encoding="utf-8", errors="replace") as f:
            for line in f:
                line = line.strip()
                if not line:
                    continue
                try:
                    o = json.loads(line)
                except json.JSONDecodeError:
                    continue
                imgs = o.get("images") or []
                src = ""
                if imgs and isinstance(imgs[0], dict):
                    src = (imgs[0].get("src") or "").strip()
                yield {
                    "handle": (o.get("handle") or "").strip(),
                    "title": (o.get("title") or "").strip(),
                    "product_type": (o.get("product_type") or "").strip(),
                    "image": src,
                }


def pick_best() -> dict[str, dict]:
    best: dict[str, tuple[tuple[int, int], dict]] = {}
    for row in iter_rows():
        img = row["image"]
        if not is_shopify_cdn(img):
            continue
        for slug in PREFERRED:
            scored = score_row(slug, row["product_type"], row["title"], row["handle"], img)
            if scored is None:
                continue
            cur = best.get(slug)
            if cur is None or scored < cur[0]:
                best[slug] = (scored, {
                    "slug": slug,
                    "handle": row["handle"],
                    "title": row["title"],
                    "product_type": row["product_type"],
                    "source_url": img.split("?")[0],  # stable without cache-buster for filename
                    "download_url": img,
                })
    missing = [s for s in PREFERRED if s not in best]
    if missing:
        raise SystemExit(f"Missing category picks: {missing}")
    return {s: best[s][1] for s in PREFERRED}


def download(url: str, dest: Path) -> None:
    dest.parent.mkdir(parents=True, exist_ok=True)
    req = urllib.request.Request(
        url,
        headers={
            "User-Agent": "SupremeAutopartsCategoryPicker/1.0",
            "Accept": "image/*,*/*",
        },
    )
    with urllib.request.urlopen(req, timeout=60) as resp:
        data = resp.read()
        ctype = (resp.headers.get("Content-Type") or "").lower()
    if len(data) < 2000:
        raise RuntimeError(f"Image too small ({len(data)} bytes): {url}")
    # Normalize to .jpg bytes when possible via Pillow; else write raw and convert with ffmpeg/magick.
    tmp = dest.with_suffix(".download")
    tmp.write_bytes(data)
    try:
        from PIL import Image
        import io

        im = Image.open(io.BytesIO(data))
        im = im.convert("RGB")
        # Reasonable tile size — keep detail, not huge.
        im.thumbnail((1200, 900), Image.Resampling.LANCZOS)
        im.save(dest, "JPEG", quality=85, optimize=True)
        tmp.unlink(missing_ok=True)
        return
    except Exception:
        pass
    # Fallback: if already jpeg, rename; else keep extension from URL
    if "jpeg" in ctype or "jpg" in ctype or url.lower().split("?")[0].endswith((".jpg", ".jpeg")):
        tmp.replace(dest)
        return
    # Last resort write as jpg name anyway (browser may still render)
    tmp.replace(dest)


def main() -> int:
    picks = pick_best()
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    manifest = {"categories": {}}
    for slug, info in picks.items():
        dest = OUT_DIR / f"{slug}.jpg"
        print(f"[{slug}] {info['handle']} ({info['product_type']})")
        print(f"  -> {info['download_url'][:110]}...")
        if not dest.exists() or dest.stat().st_size < 2000:
            download(info["download_url"], dest)
        else:
            print(f"  (cached {dest.name})")
        info["local"] = f"assets/images/categories/{slug}.jpg"
        info["bytes"] = dest.stat().st_size
        manifest["categories"][slug] = info
        print(f"  saved {dest} ({info['bytes']} bytes)")

    MANIFEST.write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")
    print(f"\nWrote {MANIFEST}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
