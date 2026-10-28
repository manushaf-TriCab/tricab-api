"""The source folder: every file and header is checked before any slow read (exit 3), then the loaders (contract 2)."""

from __future__ import annotations

import csv
import json
from datetime import datetime
from pathlib import Path

from cable_routes.loader import SourceError

csv.field_size_limit(2**27)

R86_DIR = "r86"
INVENTORY = "inventory.csv"
INVENTORY_META = "inventory-meta.json"

# file -> (required columns, role)
FILES: dict[str, tuple[tuple[str, ...], str]] = {
    "production-order-headers-raw.csv": (
        ("ProductionOrderNumber", "ItemNumber", "ProductionOrderStatus", "ScheduledDate", "CsIsCuttingOrder",
         "ProductionPoolId", "CsReceivedQuantity", "ReportedAsFinishedDate"), "headers"),
    "production-bom-lines-raw.csv": (
        ("ProductionOrderNumber", "LineNumber", "ItemNumber", "BOMLineQuantity", "BOMLineQuantityDenominator",
         "BOMLineUnitSymbol", "CsPrdParallelLines"), "bom"),
    "production-route-operations-raw.csv": (
        ("ProductionOrderNumber", "OperationNumber", "OperationId", "CostingOperationResourceId"), "route"),
    "released-products.csv": (
        ("ItemNumber", "CsDiameter", "NetProductWeight", "InventoryUnitSymbol", "ProductionPoolId", "ProductSearchName"),
        "products"),
    "manufactured-items.csv": (("item_number", "pool"), "pools"),
    "purchase-order-lines-all.csv": (("ItemNumber", "LineDescription"), "descriptions"),
    "production-order-headers.csv": (
        ("ProductionOrderNumber", "ItemNumber", "ProductionPoolId", "ProductionOrderStatus", "ScheduledQuantity",
         "CsReceivedQuantity", "ScheduledStartDate", "ReportedAsFinishedDate"), "order-history"),
    "production-picking-list-lines.csv": (
        ("ProductionOrderNumber", "ItemNumber", "ConsumptionDate", "IsPosted", "ConsumptionBOMQuantity",
         "ScrapBOMQuantity", "BOMUnitSymbol"), "picking-lines"),
    INVENTORY: (("Item number", "Warehouse", "Physical inventory", "Ordered in total", "Inventory unit"), "inventory"),
    "production-route-jobs-raw.csv": (
        ("ProductionOrderNumber", "OperationNumber", "OperationsResourceId", "RouteJobType", "IsJobCancelled"),
        "machine-history"),
    "machine-operation-parameters-full.csv": (
        ("operation", "resource", "orders", "setup_minutes_median", "run_rate_per_hour_median"), "rates"),
    "work-calendars.csv": (("CalendarId", "BaseWorkCalendarId", "CalendarName"), "calendars"),
    "work-calendar-time-intervals.csv": (
        ("WorkCalendarId", "WorkCalendarDate", "StartTime", "EndTime", "EfficiencyPercentage"), "calendar-intervals"),
}


def check(folder: Path) -> None:
    """Every file, every required column, and an ORD and an INV R-86 file, before any slow read. SourceError = exit 3."""
    folder = Path(folder)
    if not folder.is_dir():
        raise SourceError(f"source folder not found: {folder.name}")
    for name, (cols, _) in FILES.items():
        path = folder / name
        if not path.is_file():
            raise SourceError(f"missing file {name}")
        with open(path, newline="", encoding="utf-8-sig") as f:
            names = next(csv.reader(f), [])
        absent = [c for c in cols if c not in names]
        if absent:
            raise SourceError(f"{name}: missing column{'s' if len(absent) > 1 else ''} {', '.join(absent)}")
    r86 = folder / R86_DIR
    if not r86.is_dir():
        raise SourceError(f"missing folder {R86_DIR}/ (R-86 files)")


def rows(folder: Path, name: str):
    """Stream a file as dicts (trimmed values) restricted to the required columns."""
    cols = FILES[name][0]
    with open(Path(folder) / name, newline="", encoding="utf-8-sig") as f:
        for row in csv.DictReader(f):
            yield {c: (row.get(c) or "").strip() for c in cols}


def count_rows(path: Path) -> int:
    with open(path, newline="", encoding="utf-8-sig") as f:
        r = csv.reader(f)
        next(r, None)
        return sum(1 for _ in r)


def num(s) -> float:
    try:
        return float(str(s).replace(",", ""))
    except (TypeError, ValueError):
        return 0.0


def mtime_date(path: Path) -> str:
    return datetime.fromtimestamp(Path(path).stat().st_mtime).date().isoformat()


def products(folder: Path) -> dict:
    """{item: {pool, name, kg_per_m (None unless positive), unit}} from released-products.csv."""
    out = {}
    for r in rows(folder, "released-products.csv"):
        kg = num(r["NetProductWeight"])
        out[r["ItemNumber"]] = {"pool": r["ProductionPoolId"], "name": r["ProductSearchName"],
                                "kg_per_m": kg if kg > 0 else None, "unit": r["InventoryUnitSymbol"]}
    return out


def inventory_exported(folder: Path) -> tuple[str, bool]:
    """(YYYY-MM-DD, known). Without inventory-meta.json (or its export_date) the file's mtime date, known = False."""
    meta = Path(folder) / INVENTORY_META
    if meta.is_file():
        try:
            d = json.loads(meta.read_text(encoding="utf-8-sig")).get("export_date")
            if isinstance(d, str):
                datetime.strptime(d, "%Y-%m-%d")
                return d, True
        except (ValueError, OSError, AttributeError):
            raise SourceError(f"{INVENTORY_META}: unreadable or no export_date (YYYY-MM-DD)") from None
        raise SourceError(f"{INVENTORY_META}: missing key export_date")
    return mtime_date(Path(folder) / INVENTORY), False
