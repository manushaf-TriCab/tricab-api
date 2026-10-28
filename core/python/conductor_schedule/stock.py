"""Inventory to stock per base item (contract 4.3; plan 5.2): metres for metre items, rod in kg, QC left out."""

from __future__ import annotations

import csv
from dataclasses import dataclass, field

from . import config, source
from .issues import IssueLog


@dataclass
class Stock:
    physical: float = 0.0     # metres (kg for the rod)
    incoming: float = 0.0     # "Ordered in total": purchase and production orders still to arrive
    notes: list = field(default_factory=list)


def load(folder, kg_per_m: dict, relevant, issues: IssueLog) -> dict[str, Stock]:
    """{base item: Stock}. kg_per_m: {item: kg/m}. relevant(base) says whether an unconverted row is worth an issue.

    A `-S` (purchased) item adds to its base item. `On order` (outgoing) is ignored. Unit m and the rod stay as they are;
    t or kg is converted with the base item's kg/m; with no kg/m the row is skipped (issue `inventory_unconverted`).
    """
    out: dict[str, Stock] = {}
    with open(folder / source.INVENTORY, newline="", encoding="utf-8-sig") as f:
        for r in csv.DictReader(f):
            if (r.get("Warehouse") or "").strip().upper() == "QC":
                continue
            item = (r.get("Item number") or "").strip()
            if not item:
                continue
            unit = (r.get("Inventory unit") or "").strip().lower()
            phys, inc = source.num(r.get("Physical inventory")), source.num(r.get("Ordered in total"))
            base = item[:-2] if item.endswith("-S") else item
            if unit == "m" or base == config.ROD_ITEM:
                factor = 1.0
            elif unit in ("t", "kg") and kg_per_m.get(base):
                factor = (1000.0 if unit == "t" else 1.0) / kg_per_m[base]
            else:
                if unit in ("t", "kg") and (phys or inc) and relevant(base):
                    issues.add(base, "inventory_unconverted", f"{item}: {unit} stock without kg/m, skipped")
                continue
            s = out.setdefault(base, Stock())
            s.physical += phys * factor
            s.incoming += inc * factor
            if base != item and (phys or inc):
                s.notes.append(f"incl. purchased {item}")
    return out
