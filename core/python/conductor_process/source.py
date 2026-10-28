"""The six Cable routes extract files plus two header columns this module needs (contract section 2)."""

from __future__ import annotations

import csv
from pathlib import Path

from cable_routes.loader import Source, SourceError, load as load_six

HEADERS = "production-order-headers-raw.csv"
EXTRA = ("ProductionOrderNumber", "CsReceivedQuantity", "ReportedAsFinishedDate")


def _check_extra(folder: Path) -> None:
    path = folder / HEADERS
    if not path.is_file():
        return  # load_six names the missing file
    with open(path, newline="", encoding="utf-8-sig") as f:
        names = csv.DictReader(f).fieldnames or []
    absent = [c for c in EXTRA if c not in names]
    if absent:
        raise SourceError(f"{HEADERS}: missing column{'s' if len(absent) > 1 else ''} {', '.join(absent)}")


def load(folder: Path) -> tuple[Source, dict]:
    """(Cable routes Source, {order number: (CsReceivedQuantity text, ReportedAsFinishedDate[:10])})."""
    folder = Path(folder)
    if folder.is_dir():
        _check_extra(folder)  # before the slow read, as Cable routes checks every header first
    src = load_six(folder)
    extra = {}
    with open(folder / HEADERS, newline="", encoding="utf-8-sig") as f:
        for row in csv.DictReader(f):
            no = (row.get("ProductionOrderNumber") or "").strip()
            extra[no] = ((row.get("CsReceivedQuantity") or "").strip(),
                         (row.get("ReportedAsFinishedDate") or "").strip()[:10])
    return src, extra
