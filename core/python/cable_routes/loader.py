"""Read the six D365 extract files, failing loudly (exit 3) on a missing file or column."""

from __future__ import annotations

import csv
from dataclasses import dataclass, field
from pathlib import Path

REQUIRED: dict[str, tuple[str, ...]] = {
    "production-order-headers-raw.csv": (
        "ProductionOrderNumber", "ItemNumber", "ProductionOrderStatus", "ScheduledDate", "CsIsCuttingOrder", "ProductionPoolId"),
    "production-bom-lines-raw.csv": (
        "ProductionOrderNumber", "LineNumber", "ItemNumber", "BOMLineQuantity", "BOMLineQuantityDenominator",
        "BOMLineUnitSymbol", "CsPrdParallelLines"),
    "production-route-operations-raw.csv": (
        "ProductionOrderNumber", "OperationNumber", "OperationId", "CostingOperationResourceId"),
    "released-products.csv": ("ItemNumber", "CsDiameter", "NetProductWeight", "InventoryUnitSymbol"),
    "manufactured-items.csv": ("item_number", "pool"),
    "purchase-order-lines-all.csv": ("ItemNumber", "LineDescription"),
}

csv.field_size_limit(2**27)


class SourceError(Exception):
    """A required file or column is missing (exit code 3)."""


@dataclass
class Source:
    headers: list = field(default_factory=list)
    bom: list = field(default_factory=list)
    route: list = field(default_factory=list)
    products: dict = field(default_factory=dict)
    pools: dict = field(default_factory=dict)
    descriptions: dict = field(default_factory=dict)  # first non-empty LineDescription per item


def _rows(folder: Path, name: str):
    path = folder / name
    if not path.is_file():
        raise SourceError(f"missing required file {name} in {folder.name}")
    cols = REQUIRED[name]
    with open(path, newline="", encoding="utf-8-sig") as f:
        reader = csv.DictReader(f)
        absent = [c for c in cols if c not in (reader.fieldnames or [])]
        if absent:
            raise SourceError(f"{name}: missing column{'s' if len(absent) > 1 else ''} {', '.join(absent)}")
        for row in reader:
            yield {c: (row.get(c) or "").strip() for c in cols}


def _check(folder: Path, name: str) -> None:
    gen = _rows(folder, name)
    try:
        next(gen, None)
    finally:
        gen.close()


def load(folder: Path) -> Source:
    folder = Path(folder)
    if not folder.is_dir():
        raise SourceError(f"source folder not found: {folder.name}")
    for name in REQUIRED:  # every file and header first, so the message names the problem before the slow read
        _check(folder, name)
    src = Source()
    src.headers = list(_rows(folder, "production-order-headers-raw.csv"))
    src.bom = list(_rows(folder, "production-bom-lines-raw.csv"))
    src.route = list(_rows(folder, "production-route-operations-raw.csv"))
    for r in _rows(folder, "released-products.csv"):
        src.products[r["ItemNumber"]] = r
    for r in _rows(folder, "manufactured-items.csv"):
        src.pools[r["item_number"]] = r["pool"]
    for r in _rows(folder, "purchase-order-lines-all.csv"):
        if r["LineDescription"] and r["ItemNumber"] not in src.descriptions:
            src.descriptions[r["ItemNumber"]] = r["LineDescription"]
    return src


def num(s) -> float:
    try:
        return float(s)
    except (TypeError, ValueError):
        return 0.0
