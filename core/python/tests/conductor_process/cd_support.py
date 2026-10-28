"""Synthetic D365 extract folders for the conductor_process tests (only the columns the engines read)."""

from __future__ import annotations

import csv
import sqlite3
from pathlib import Path

from cable_routes.loader import REQUIRED
from conductor_process.build import run_build
from conductor_process.source import EXTRA, HEADERS, load

AS_OF = "2026-10-20"  # newest ScheduledDate in the fixture; the window starts 2025-10-21


class Extract:
    """Collects orders, BOM lines, route operations and products, and writes the six CSV files."""

    def __init__(self):
        self.headers, self.bom, self.route = [], [], []
        self.products, self.pools, self.po = {}, {}, []
        self._n = 0

    def order(self, item, pool, ops=(), bom=(), status="Completed", date="2026-01-01", cutting=False, no=None,
              received="", finished="", order_pool=None):
        """ops: [(operation, machine)]. bom: [(item, qty[, unit[, denominator[, parallel]]])]."""
        self._n += 1
        no = no or f"W{self._n:06d}"
        if pool is not None:
            self.pools[item] = pool
        self.products.setdefault(item, {"ItemNumber": item, "CsDiameter": "0", "NetProductWeight": "0",
                                        "InventoryUnitSymbol": "m"})
        self.headers.append({"ProductionOrderNumber": no, "ItemNumber": item, "ProductionOrderStatus": status,
                             "ScheduledDate": f"{date}T12:00:00Z", "CsIsCuttingOrder": "Yes" if cutting else "No",
                             "ProductionPoolId": order_pool or "", "CsReceivedQuantity": str(received),
                             "ReportedAsFinishedDate": f"{finished}T12:00:00Z" if finished else "1900-01-01T12:00:00Z"})
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
            HEADERS: self.headers,
            "production-bom-lines-raw.csv": self.bom,
            "production-route-operations-raw.csv": self.route,
            "released-products.csv": list(self.products.values()),
            "manufactured-items.csv": [{"item_number": i, "pool": p} for i, p in self.pools.items()],
            "purchase-order-lines-all.csv": self.po,
        }
        for name, rows in files.items():
            cols = REQUIRED[name] + tuple(c for c in EXTRA if c not in REQUIRED[name]) if name == HEADERS else REQUIRED[name]
            write_csv(folder / name, cols, rows)
        return folder


def write_csv(path: Path, cols, rows):
    with open(path, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=list(cols))
        w.writeheader()
        w.writerows(rows)


def build_store(extract: Extract, tmp_path: Path) -> sqlite3.Connection:
    """Write the extract, run the real build into a SQLite file, and return a connection to it."""
    src, extra = load(extract.write(tmp_path / "dynamics"))
    db = tmp_path / "out" / "conductor-process.sqlite"
    run_build(src, extra, "dynamics", db)
    con = sqlite3.connect(db)
    con.row_factory = sqlite3.Row
    return con


def factory() -> Extract:
    """Contract section 4's test folder: the NX-1C16 chain from rod, a tinned branch through TL1/WD5, a 30_TAPE item
    on TP2, an LU3-only item (excluded), a cancelled BU7 order, and NX-1C500 whose latest order is on LU3 but whose
    earlier ones ran on ST1."""
    e = Extract()
    # Plain chain: rod -> NX-1W1.6 (RB1) -> NX-16W.3 (WD4) -> NX-32W.3 (BU6) -> NX-1C16 (BU1).
    e.order("NX-1W1.6", "15_WIR", [("ROD_BREAK", "RB1")], [("NX-1R8-NEX", 0.01804, "kg")], date="2026-09-01")
    e.order("NX-16W.3", "15_WIR", [("WIRE_DRAW", "WD4")], [("NX-1W1.6", 0.26829)], date="2026-09-02")
    e.order("NX-32W.3", "20_BUN", [("BUNCHING", "BU6")], [("NX-16W.3", 2)], date="2026-09-03",
            received=10, finished="2026-09-04")
    e.order("NX-32W.3", "20_BUN", [("BUNCHING", "BU6")], [("NX-16W.3", 2)], date="2026-08-03",
            received=20, finished="2026-08-04")
    e.order("NX-32W.3", "20_BUN", [("BUNCHING", "BU7")], [("NX-16W.3", 2)], status="Cancelled", date="2026-10-01",
            received=99, finished="2026-10-02")
    # NX-1C16: four live BU1 orders in the window (received 100, 400, 200, 0), one before it, one on BU2.
    for k, (q, f) in enumerate([(100, "2026-10-01"), (400, "2025-10-21"), (200, "2026-05-01"), (0, "2026-06-01"),
                                (900, "2025-10-20")]):
        e.order("NX-1C16", "25_FCON", [("BUNCHING", "BU1")], [("NX-32W.3", 7.02611, "m", "", 7)],
                date=f"2026-0{k + 1}-01", no=f"C16-{k}", received=q, finished=f)
    e.order("NX-1C16", "25_FCON", [("BUNCHING", "BU2")], [("NX-32W.3", 7.02611, "m", "", 7)], date="2025-12-01",
            no="C16-BU2", received=300, finished="2025-12-02")
    e.order("NX-1C16", "25_FCON", [("CUT", "CT1")], [("NX-1C16X", 1)], date=AS_OF, cutting=True)
    # Tinned branch: NX-1W1.6 -> DX-1W1.6 (TL1) -> DX-7W.3 (WD5) -> DX-1C1.5 (BU4) -> DM-1C1.5 (TP2).
    e.order("DX-1W1.6", "15_WIR", [("TINNING", "TL1")], [("NX-1W1.6", 1), ("TIN-ANODE", 0.00018, "kg")])
    e.order("DX-7W.3", "15_WIR", [("WIRE_DRAW", "WD5")], [("DX-1W1.6", 0.23)])
    e.order("DX-1C1.5", "25_FCON", [("BUNCHING", "BU4")], [("DX-7W.3", 3)])
    e.order("DM-1C1.5", "30_TAPE", [("TAPING", "TP2")], [("DX-1C1.5", 1), ("TAPE_MICA-8MM", 0.004, "kg")])
    # NX-1C500: two ST1 orders, then a later one on LU3. NX-1C500-AA: LU3 only, consumed by NM-1C500-AA on ST1.
    for k, d in enumerate(["2025-03-01", "2025-06-01"]):
        e.order("NX-1C500", "25_FCON", [("ROPESTRAND", "ST1"), ("ROPESTRND2", "ST1")], [("NX-32W.3", 62)], date=d,
                no=f"C500-ST1-{k}")
    e.order("NX-1C500", "25_FCON", [("LAYUP", "LU3")], [("NX-32W.3", 62)], date="2026-07-01", no="C500-LU3")
    e.order("NX-1C500-AA", "25_FCON", [("LAYUP", "LU3")], [("NX-32W.3", 62)])
    e.order("NM-1C500-AA", "30_TAPE", [("TAPING", "ST1")], [("NX-1C500-AA", 1), ("TAPE_MICA-30MM", 0.1, "kg")])
    # Not copper conductor: wrong prefix, wrong pool, cutting only.
    e.order("AL-1C16", "25_FCON", [("BUNCHING", "BU1")], [("AL-ROD", 1, "kg")])
    e.order("NX-1C16-INS", "35_WIP", [("INSULATION", "EX6")], [("NX-1C16", 1)], date=AS_OF)
    e.order("NX-1C10", "25_FCON", [("CUT", "CT1")], [("NX-1C16", 1)], cutting=True)
    e.order("VX-SPECIAL", "15_WIR", [("WIRE_DRAW", "WD4")], [("NX-1W1.6", 1)])
    e.product("NX-1C16", 5.18, 0.1361)
    e.product("NX-1R8-NEX", 8, 0, "kg")
    e.product("TAPE_MICA-8MM", 0, 0, "kg")
    e.desc("NX-1R8-NEX", "Rod PCu 8mm Rod PCu 8mm 8mm plain annealed coppe, extra")
    return e
