"""The jobs: seed from the August files, pull named designs, the nightly run,
the rolling backstop, and lookup tables. Each returns a summary dict that the
CLI writes to runs/ in the store, so every run leaves a record."""

from __future__ import annotations

import xml.etree.ElementTree as ET
from collections import Counter
from datetime import datetime, timedelta, timezone
from pathlib import Path

from . import changefeed
from .archive import Archive, Identity, sub_designs, utcnow
from .cablebuilder import CableBuilder

SAVE_EVERY = 200  # manifest checkpoints, so a crash loses minutes, not hours


def _iso(ts: float) -> str:
    return datetime.fromtimestamp(ts, timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


# --------------------------------------------------------------------- seed


def seed(archive: Archive, raw_dir: Path, log=print) -> dict:
    """Load the first pull (planning/cablebuilder/raw) into the archive.

    The pull time is the file's modification time, which is when pull.py wrote
    it. No request is made to CableBuilder.
    """
    design_dir = raw_dir / "design"
    counts: Counter[str] = Counter()
    unidentified: list[str] = []
    files = sorted(design_dir.glob("*.xml"))
    for i, f in enumerate(files, 1):
        kind = "header" if f.name.endswith(".header.xml") else "full"
        outcome, _ = archive.record_design(f.read_bytes(), kind, when=_iso(f.stat().st_mtime))
        counts[outcome] += 1
        if outcome == "unidentified":
            unidentified.append(f.name)
        if i % 2000 == 0:
            log(f"  designs {i}/{len(files)}  {dict(counts)}")
            archive.save()

    lookups: Counter[str] = Counter()
    for f in sorted((raw_dir / "lookup").glob("*.xml")):
        body = f.read_bytes()
        lt = ET.fromstring(body).find("lookuptable")
        if lt is None:
            continue
        g = lambda n: (lt.findtext(n) or "").strip()  # noqa: E731
        meta = {"description": g("description"), "standard": g("standard"), "plant": g("plant")}
        lookups[archive.record_lookup(g("tablecode"), body, meta, when=_iso(f.stat().st_mtime))] += 1
    archive.save()

    failures = []
    fpath = design_dir / "_failures.csv"
    if fpath.exists():
        failures = sorted(set(fpath.read_text(encoding="utf-8").splitlines()[1:]))
    return {"designs": dict(counts), "unidentified": unidentified,
            "lookups": dict(lookups), "first_pull_failures": failures}


# --------------------------------------------------------------------- pull


UNKNOWN_DAYS = 30


def pull(archive: Archive, cb: CableBuilder,
         targets: list[tuple[str, str | None, str | None]],
         recurse: bool = True, skip_unknown: bool = True, log=print) -> dict:
    """Pull each (code, alt, ver); None means alternative 0 / latest version known.

    With recurse, any sub-design a pulled design names that the archive does
    not hold is queued too. That closes the gap the first pull left (about
    1,750 sub-designs that never appeared in the search list).

    The change feed also names purchased materials (tapes, masterbatch, copper
    rod) that have no design. A code CableBuilder could not return is
    remembered and, with skip_unknown, not asked for again for UNKNOWN_DAYS,
    so the nightly record lists real failures rather than the same materials.
    """
    unknown = archive.get_state("unknown")
    cutoff = (datetime.now(timezone.utc) - timedelta(days=UNKNOWN_DAYS)).strftime("%Y-%m-%dT%H:%M:%SZ")
    item_to_code = archive.by_erp_item()
    known_versions: dict[tuple[str, str], str] = {}
    for code, alt, ver in archive.designs:
        if int(ver) >= int(known_versions.get((code, alt), "-1")):
            known_versions[(code, alt)] = ver

    queue: list[tuple[str, str, str, str]] = []  # code, alt, ver, why
    queued: set[tuple[str, str]] = set()

    def enqueue(code: str, alt: str | None, ver: str | None, why: str) -> None:
        if code not in archive.codes() and code in item_to_code:
            code = item_to_code[code]  # a D365 item id that is not the design code
        alt = alt or "0"
        if (code, alt) in queued:
            return
        if skip_unknown and unknown.get(code, "") > cutoff:
            counts["skipped: not in CableBuilder"] += 1
            return
        queued.add((code, alt))
        queue.append((code, alt, ver or known_versions.get((code, alt), "0"), why))

    counts: Counter[str] = Counter()
    for code, alt, ver in targets:
        enqueue(code, alt, ver, "requested")

    failed: list[dict] = []
    changed: list[str] = []
    i = 0
    while i < len(queue):
        code, alt, ver, why = queue[i]
        i += 1
        kind, body = cb.design(code, alt, ver)
        if body is None:
            counts["failed"] += 1
            failed.append({"code": code, "alt": alt, "ver": ver, "why": why})
            unknown[code] = utcnow()
            continue
        unknown.pop(code, None)
        outcome, entry = archive.record_design(body, kind, fallback=Identity(code, alt, ver))
        counts[outcome] += 1
        counts[f"kind:{kind}"] += 1
        if why == "sub-design":
            counts["sub-designs fetched"] += 1
        if outcome in ("new", "changed"):
            changed.append(f"{code} a{alt}v{ver} {outcome}")
        if recurse and kind == "full":
            for sub, sub_alt in sub_designs(body):
                if sub not in archive.codes():
                    enqueue(sub, sub_alt, None, "sub-design")
        if i % SAVE_EVERY == 0:
            archive.save()
            log(f"  {i}/{len(queue)}  {dict(counts)}")
    archive.save()
    archive.put_state("unknown", unknown)
    return {"requested": len(targets), "pulled": i, "counts": dict(counts),
            "changed": changed, "failed": failed, "cb_requests": cb.requests}


# --------------------------------------------------------------------- nightly


def nightly(archive: Archive, cb: CableBuilder, since: datetime | None = None,
            rotate_pages: int = 0, bearer: str | None = None, log=print) -> dict:
    """Changed items from Dynamics since the watermark, then an optional slice
    of the rolling backstop. The watermark only moves after the pull succeeds."""
    state = archive.get_state("changefeed")
    start = since or changefeed.default_since(state.get("watermark"))
    changes, newest, messages = changefeed.changes_since(start, bearer=bearer)
    log(f"  feed: {messages} messages since {start:%Y-%m-%d %H:%M}Z -> {len(changes)} items")
    summary = {"feed_since": start.isoformat(), "feed_messages": messages,
               "feed_items": len(changes)}
    summary["pull"] = pull(archive, cb, [(c.code, c.alt, c.ver) for c in changes], log=log)
    if newest:
        archive.put_state("changefeed", {"watermark": newest, "updated": utcnow()})
    if rotate_pages:
        summary["rotate"] = rotate(archive, cb, rotate_pages, log=log)
    return summary


# --------------------------------------------------------------------- rotate


def rotate(archive: Archive, cb: CableBuilder, pages: int, alternative: str | None = "0",
           page_size: int = 200, log=print) -> dict:
    """Re-check the next `pages` pages of the design list, wrapping at the end.

    The backstop for anything the change feed misses (designs never sent to
    D365, or a feed outage). The list is about 500 pages of 200, so 70 pages a
    night covers everything weekly. Unchanged designs cost one request each
    and write nothing but a manifest timestamp.
    """
    state = archive.get_state("rotate")
    page = int(state.get("next_page", 1))
    targets: list[tuple[str, str | None, str | None]] = []
    total = 0
    first = page
    for _ in range(pages):
        rows, total = cb.search_page(page, page_size)
        targets += [r for r in rows if alternative is None or r[1] == alternative]
        page = 1 if (not rows or page * page_size >= total) else page + 1
    log(f"  rotate: pages {first}..{page - 1 if page > 1 else 'end'} of "
        f"{-(-total // page_size)} -> {len(targets)} designs")
    result = pull(archive, cb, targets, log=log)
    archive.put_state("rotate", {"next_page": page, "total_rows": total, "updated": utcnow()})
    return {"from_page": first, "next_page": page, "list_total": total, **result}


# --------------------------------------------------------------------- lookups


def lookups(archive: Archive, cb: CableBuilder, log=print) -> dict:
    counts: Counter[str] = Counter()
    for meta in cb.lookup_list():
        body = cb.lookup(meta["tablecode"])
        if body is None:
            counts["failed"] += 1
            continue
        counts[archive.record_lookup(meta["tablecode"], body,
                                     {k: meta[k] for k in ("description", "standard", "plant")})] += 1
    archive.save()
    return {"counts": dict(counts), "cb_requests": cb.requests}
