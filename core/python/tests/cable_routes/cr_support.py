"""Synthetic D365 extract folders for the cable_routes tests (only the columns the engine reads)."""

from __future__ import annotations

import csv
import sqlite3
from pathlib import Path

from cable_routes.build import run_build
from cable_routes.loader import REQUIRED, load


class Extract:
    """Collects orders, BOM lines, route operations and products, and writes the six CSV files."""

    def __init__(self):
        self.headers, self.bom, self.route = [], [], []
        self.products, self.pools, self.po = {}, {}, []
        self._n = 0

    def order(self, item, pool="40_SFG", ops=(), bom=(), status="Released", date="2026-01-01", cutting=False,
              no=None, order_pool=None):
        """ops: [(operation, machine)]. bom: [(item, qty[, unit[, denominator[, parallel]]])]."""
        self._n += 1
        no = no or f"W{self._n:06d}"
        if pool is not None:
            self.pools[item] = pool
        self.products.setdefault(item, {"ItemNumber": item, "CsDiameter": "0", "NetProductWeight": "0",
                                        "InventoryUnitSymbol": "m"})
        self.headers.append({"ProductionOrderNumber": no, "ItemNumber": item, "ProductionOrderStatus": status,
                             "ScheduledDate": f"{date}T12:00:00Z", "CsIsCuttingOrder": "Yes" if cutting else "No",
                             "ProductionPoolId": order_pool or ""})
        for k, (op, machine) in enumerate(ops, 1):
            self.route.append({"ProductionOrderNumber": no, "OperationNumber": str(k * 10), "OperationId": op,
                               "CostingOperationResourceId": machine})
        for k, line in enumerate(bom, 1):
            item_, qty, *rest = line
            unit, den, par = (list(rest) + ["m", "", ""][len(rest):])[:3] if rest else ("m", "", "")
            self.bom.append({"ProductionOrderNumber": no, "LineNumber": str(k), "ItemNumber": item_,
                             "BOMLineQuantity": str(qty), "BOMLineQuantityDenominator": str(den),
                             "BOMLineUnitSymbol": unit, "CsPrdParallelLines": str(par)})
        return no

    def product(self, item, dia=0, kg=0, unit="m"):
        self.products[item] = {"ItemNumber": item, "CsDiameter": str(dia), "NetProductWeight": str(kg),
                               "InventoryUnitSymbol": unit}

    def desc(self, item, text):
        self.po.append({"ItemNumber": item, "LineDescription": text})

    def write(self, folder: Path) -> Path:
        folder.mkdir(parents=True, exist_ok=True)
        files = {
            "production-order-headers-raw.csv": self.headers,
            "production-bom-lines-raw.csv": self.bom,
            "production-route-operations-raw.csv": self.route,
            "released-products.csv": list(self.products.values()),
            "manufactured-items.csv": [{"item_number": i, "pool": p} for i, p in self.pools.items()],
            "purchase-order-lines-all.csv": self.po,
        }
        for name, rows in files.items():
            write_csv(folder / name, REQUIRED[name], rows)
        return folder


def write_csv(path: Path, cols, rows):
    with open(path, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=list(cols))
        w.writeheader()
        w.writerows(rows)


def build_store(extract: Extract, tmp_path: Path) -> sqlite3.Connection:
    """Write the extract, run the real build into a SQLite file, and return a connection to it."""
    src = load(extract.write(tmp_path / "dynamics"))
    db = tmp_path / "out" / "cable-routes.sqlite"
    run_build(src, "dynamics", db)
    con = sqlite3.connect(db)
    con.row_factory = sqlite3.Row
    return con


def worked_example() -> Extract:
    """KA9X-PASN/5C16BK, plan section 4 and contract 8.4: the nine-line chain, four 1C16 cores and the earth core."""
    e = Extract()
    cores = ["LM1X-XXXN/1C16RE", "LM1X-XXXN/1C16WH", "LM1X-XXXN/1C16BL", "LM1X-XXXN/1C16BK"]
    e.order("KA9X-PASN/5C16BK", "50_FMAN", [("SHEATHING", "EX4")],
            [("WAKA9X-PASN/5C16", 1), ("RS0002", 0.21541, "kg"), ("MB_BLACK", 0.0057, "kg")], status="Completed",
            date="2023-12-18", no="151107M")
    e.order("WAKA9X-PASN/5C16", "40_SFG", [("ARMOURING", "LU3")],
            [("BEKA9X-PASN/5C16OR", 1), ("GSWA_1.60MM", 0.61207, "kg"), ("TAPE_FPP130-40MM", 0.01314, "kg")])
    e.order("BEKA9X-PASN/5C16OR", "40_SFG", [("BEDDING", "EX3")],
            [("LUKA9X-PASN/5C16", 1), ("RS0002", 0.08933, "kg"), ("MB_ORANGE", 0.00225, "kg")])
    e.order("LUKA9X-PASN/5C16", "40_SFG", [("LAYUP", "LU9")],
            [(c, 1.0099) for c in cores] + [("LM16X-XXXN/1C6EA", 1.0139), ("TAPE_PP50-40MM", 0.01, "kg")])
    for c in cores:
        e.order(c, "35_WIP", [("INSULATION", "EX6")], [("NX-1C16", 1), ("RI0001", 0.05, "kg")])
    e.order("LM16X-XXXN/1C6EA", "35_WIP", [("INSULATION", "EX6")], [("NX-1C6", 1)])
    e.order("NX-1C16", "25_FCON", [("BUNCHING", "BU1")], [("NX-32W.3", 7.02611, "m", "", 7)])
    e.order("NX-32W.3", "20_BUN", [("BUNCHING", "BU6")], [("NX-8W.3", 4.00232)])
    e.order("NX-8W.3", "15_WIR", [("WIRE_DRAW", "WD4")], [("NX-1W1.6", 0.26829)])
    e.order("NX-1W1.6", "15_WIR", [("ROD_BREAK", "RB1")], [("NX-1R8-NEX", 0.01804, "kg")])
    e.order("NX-1C6", "25_FCON", [("BUNCHING", "BU2")], [("NX-7W.3", 6.0)])  # fixture only
    e.order("NX-7W.3", "15_WIR", [("WIRE_DRAW", "WD4")], [("NX-1W1.6", 0.2)])  # fixture only
    for item, dia, kg in [("KA9X-PASN/5C16BK", 27.85, 1.678), ("NX-1C16", 5.18, 0.1361), ("NX-1W1.6", 1.6, 0.01804)]:
        e.product(item, dia, kg)
    e.desc("NX-1R8-NEX", "Rod PCu 8mm Rod PCu 8mm 8mm plain annealed coppe, extra")
    return e
