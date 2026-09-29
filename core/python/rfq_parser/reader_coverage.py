# Generated from the RFQ parser's matcher/reader_coverage.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
"""Phase 0 coverage evidence for byte-pinned schedules, not a workbook classifier.

Unknown workbooks explicitly report unverified coverage until preflight establishes their
inventory. The baseline registry contains only reviewed original attachment hashes.
"""
from __future__ import annotations

from collections import Counter
import hashlib
import json
from pathlib import Path
from typing import Any

BASELINES = Path(__file__).resolve().parent / "data" / "rfq_coverage_baselines.json"


def measure(data: bytes, items: list[dict], skipped: list[dict], *, accepted: int,
            rejected: int) -> dict[str, Any]:
    digest = hashlib.sha256(data).hexdigest()
    baselines = json.loads(BASELINES.read_text(encoding="utf-8"))
    baseline = baselines.get(digest)
    report: dict[str, Any] = {
        "attachment_sha256": digest, "status": "unverified", "expected": None,
        "returned_items": len(items), "accepted": accepted, "rejected": rejected,
        "reported_skipped": len(skipped), "missing_count": None,
    }
    if baseline is None:
        return report
    expected = {(baseline["sheet"], r) for r in baseline["rows"]}
    observed: Counter = Counter()
    invalid = 0
    for item in items + skipped:
        sheet, row = item.get("sheet"), item.get("row")
        if not isinstance(sheet, str) or type(row) is not int or row < 1:
            invalid += 1
        else:
            observed[(sheet, row)] += 1
    missing = expected - observed.keys()
    unexpected = observed.keys() - expected
    duplicates = sum(n - 1 for n in observed.values() if n > 1)
    unexplained_skips = sum(not str(i.get("reason") or "").strip() for i in skipped)
    held = baseline.get("held_rows", [])
    report.update(
        expected=len(expected), returned_unique=len(observed), missing_count=len(missing),
        missing_rows=[{"sheet": s, "row": r} for s, r in sorted(missing)],
        unexpected_rows=[{"sheet": s, "row": r} for s, r in sorted(unexpected)],
        duplicate_count=duplicates, invalid_identity_count=invalid,
        unexplained_skip_count=unexplained_skips, held_rows=held,
        status="incomplete" if (missing or unexpected or duplicates or invalid or unexplained_skips)
        else "review_required" if held else "complete",
    )
    return report


def summary(report: dict[str, Any]) -> str:
    if report["status"] == "unverified":
        return "Reader coverage unverified: no independently established candidate inventory"
    return (
        f"Reader coverage {report['status']}: {report['returned_unique']}/{report['expected']} "
        f"source rows returned; {report['accepted']} accepted, {report['rejected']} rejected, "
        f"{report['reported_skipped']} reported skipped; {report['missing_count']} missing, "
        f"{report['duplicate_count']} duplicate, {len(report['unexpected_rows'])} unexpected, "
        f"{report['invalid_identity_count']} invalid identities, "
        f"{report['unexplained_skip_count']} skips without reasons; "
        f"{len(report['held_rows'])} source rows require human review"
    )
