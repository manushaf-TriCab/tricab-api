"""Synthetic schedule source folders for the conductor_schedule tests (only the columns the engine reads)."""

from __future__ import annotations

import csv
import importlib.util
import json
import sqlite3
from datetime import date
from pathlib import Path

from conductor_schedule import source
from conductor_schedule.run import run_build

TODAY = date(2026, 10, 28)

# The conductor_process fixture builder is reused under a private module name (both test dirs have a conftest).
_spec = importlib.util.spec_from_file_location(
    "cs_cd_support", Path(__file__).resolve().parents[1] / "conductor_process" / "cd_support.py")
cd_support = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(cd_support)

R86_ORD_ROWS = {
    # label: (pacw m, pacw mica m, pacw kg, tacw m, tacw mica m, tacw kg, alu m, alu kg[, X])
    "C1.5": (0, 0, 0, 600, 800, 5, 0, 0),     # tinned plain 600 m, tinned mica 800 m
    "C16": (2000, 400, 300, 0, 0, 0, 0, 0, 55),
    "C70": (700, 0, 90, 0, 0, 0, 0, 0),
    "C95": (0, 280, 100, 0, 0, 0, 0, 0),
    "A10": (0, 0, 0, 0, 0, 0, 3440, 165),
}
R86_INV_ROWS = {"C16": (3000, 0, 400, 0, 0, 0, 0, 0), "C1.5": (0, 0, 0, 900, 0, 7, 0, 0)}


def r86_text(doc: str, rows: dict, printed: str = "29Oct'26  20:01:57", frm: str = "01Oct'26", form_feed=True) -> str:
    """An R-86 print whose size lines add up to the Total line."""
    sums = [0] * 8
    lines = ["", f"TRI.MWH               R-86  Conductor summary -  Period: {frm}->",
             f"(v357.5) Printed: {printed} by: jeffe".ljust(110) + "page:  1",
             f"Selected:  Dbco:ALL DocTyp: {doc} data",
             "            ______________pacw_______________   ______________tacw_______________   ________alumin________",
             "Csa           NOmica_m     mica_m    wght_kg      NOmica_m     mica_m    wght_kg      NOmica_m    wght_kg     X",
             "-" * 120]
    for label, vals in rows.items():
        x = vals[8] if len(vals) > 8 else None
        vals = vals[:8]
        sums = [a + b for a, b in zip(sums, vals)]
        lines.append(f"{label:<7}" + "".join(f"{v:>12}" for v in vals) + (f"{x:>10}" if x is not None else ""))
    lines.append("            " + "-" * 33 + "   " + "-" * 33 + "   " + "-" * 22)
    units = ("m", "m", "kg", "m", "m", "kg", "m", "kg")
    lines.append("Total:      " + "".join(f"{v:>9}{u}   " for v, u in zip(sums, units)).rstrip())
    return "\n".join(lines) + ("\n\f" if form_feed else "\n")


def write_r86(folder: Path, name: str, text: str) -> Path:
    d = folder / "r86"
    d.mkdir(parents=True, exist_ok=True)
    (d / name).write_text(text, encoding="ascii", newline="")
    return d / name


def picking(order, item, qty, day, unit="m", posted="Yes", scrap=0):
    return {"ProductionOrderNumber": order, "ItemNumber": item, "ConsumptionDate": f"{day}T12:00:00Z", "IsPosted": posted,
            "ConsumptionBOMQuantity": str(qty), "ScrapBOMQuantity": str(scrap), "BOMUnitSymbol": unit}


# operation -> (setup minutes, metres per hour); orders count does not matter
RATES = {"ROD_BREAK": ("RB1", 45, 90000), "TINNING": ("TL1", 30, 20000), "WIRE_DRAW": ("WD4", 60, 40000),
         "BUNCHING": ("BU1", 30, 800)}
RATE_ROWS = [("ROD_BREAK", "RB1", 45, 90000), ("TINNING", "TL1", 30, 20000), ("WIRE_DRAW", "WD4", 60, 40000),
             ("WIRE_DRAW", "WD5", 60, 40000), ("BUNCHING", "BU1", 30, 800), ("BUNCHING", "BU2", 30, 1200),
             ("BUNCHING", "BU4", 30, 800), ("BUNCHING", "BU6", 30, 5000), ("BUNCHING", "BU7", 30, 5000)]
MACHINES = ("RB1", "TL1", "WD3", "WD4", "WD5", "BU1", "BU2", "BU4", "BU5", "BU6", "BU7", "ST1")
WEEKDAY_SHIFT = (6 * 3600, 22 * 3600)     # Mon-Fri 06:00-22:00


def write_machine_files(folder: Path, ex, rates=None, days=None):
    """Route jobs (one Process job per route operation, cancelled for cancelled orders), rates, one base calendar
    BASE for all 12 machines whose last full week before today (19-25 Oct 2026) is Mon-Fri 06:00-22:00.
    days: ISO dates with intervals (default 2026-10-19..23); rates: [(operation, resource, setup min, per hour)]."""
    status = {h["ProductionOrderNumber"]: h["ProductionOrderStatus"] for h in ex.headers}
    jobs = [{"ProductionOrderNumber": r["ProductionOrderNumber"], "OperationNumber": r["OperationNumber"],
             "OperationsResourceId": r["CostingOperationResourceId"], "RouteJobType": "Process",
             "IsJobCancelled": "Yes" if status.get(r["ProductionOrderNumber"]) == "Cancelled" else "No"} for r in ex.route]
    cd_support.write_csv(folder / "production-route-jobs-raw.csv", source.FILES["production-route-jobs-raw.csv"][0], jobs)
    cd_support.write_csv(folder / "machine-operation-parameters-full.csv",
                         source.FILES["machine-operation-parameters-full.csv"][0],
                         [{"operation": o, "resource": m, "orders": 50, "setup_minutes_median": su, "run_rate_per_hour_median": r}
                          for o, m, su, r in (RATE_ROWS if rates is None else rates)])
    cd_support.write_csv(folder / "work-calendars.csv", source.FILES["work-calendars.csv"][0],
                         [{"CalendarId": m, "BaseWorkCalendarId": "BASE", "CalendarName": "Machine " + m} for m in MACHINES])
    days = days if days is not None else [f"2026-10-{d}" for d in range(19, 24)]
    cd_support.write_csv(folder / "work-calendar-time-intervals.csv", source.FILES["work-calendar-time-intervals.csv"][0],
                         [{"WorkCalendarId": "BASE", "WorkCalendarDate": f"{d}T12:00:00Z", "StartTime": WEEKDAY_SHIFT[0],
                           "EndTime": WEEKDAY_SHIFT[1], "EfficiencyPercentage": 100} for d in days])


def make_source(tmp_path: Path, name="cs-source", inventory=None, picks=None, runs=None, r86_rows=None, extract=None,
                rates=None, days=None) -> Path:
    """The fixture folder (today 2026-10-28).

    NX-1C16: stock 1000 m, 100 m/week issues, R-86 order 2000 m / 4 weeks = 500 m/week, 4 runs (100..400, median 250).
    DX-1C1.5 (tinned) and DM-1C1.5 (tinned mica): R-86 only, no runs; DM has 100 m on hand.
    NM-1C16 / MNX-1C16 share the plain mica C16 bucket (400 m = 100 m/week) by 3:1 issues; NM-1C95 / MNX-1C95 share
    280 m (70 m/week) with no history, so NM takes it all. R-86 C70 has no restock item. NX-4W.3 is a spool on issues.
    """
    folder = tmp_path / name
    ex = extract or cd_support.factory()
    ex.write(folder)
    t = TODAY.isoformat()
    cd_support.write_csv(folder / "released-products.csv", source.FILES["released-products.csv"][0], [
        {**p, "ProductionPoolId": ex.pools.get(i, ""), "ProductSearchName": i}
        for i, p in ex.products.items()])
    # Order history: three runs of NX-1C16 plus the cable order that consumes it.
    hist = [("H1", "NX-1C16", "25_FCON", 100, "2026-09-01"), ("H2", "NX-1C16", "25_FCON", 200, "2026-08-01"),
            ("H3", "NX-1C16", "25_FCON", 300, "2026-07-01"), ("H4", "NX-1C16", "25_FCON", 400, "2026-06-01"),
            ("H5", "NX-1C16", "25_FCON", 9999, "2025-01-01"), ("CAB1", "CABLE-A", "35_WIP", 1000, "2026-09-01"),
            ("W000001", "NX-1W1.6", "15_WIR", 800000, "2025-01-01")]
    rows = [{"ProductionOrderNumber": no, "ItemNumber": item, "ProductionPoolId": pool, "ProductionOrderStatus": "Completed",
             "ScheduledQuantity": str(q), "CsReceivedQuantity": str(q), "ScheduledStartDate": f"{fin}T12:00:00Z",
             "ReportedAsFinishedDate": f"{fin}T12:00:00Z"} for no, item, pool, q, fin in (runs if runs is not None else hist)]
    cd_support.write_csv(folder / "production-order-headers.csv", source.FILES["production-order-headers.csv"][0], rows)

    if picks is None:
        picks = []
        for k in range(13):   # 13 weeks of 100 m: weekly 100
            picks.append(picking("CAB1", "NX-1C16", 100, date.fromordinal(TODAY.toordinal() - 3 - 7 * k).isoformat()))
        picks += [picking("CAB1", "NX-1C16", 5000, "2025-01-05"),                  # outside every window
                  picking("CAB1", "NX-1C16", 50, t, posted="No"),                  # not posted
                  picking("CAB1", "NX-1C16", 50, "2026-10-29"),                    # after today
                  picking("CAB1", "NM-1C16", 300, "2026-10-01"), picking("CAB1", "MNX-1C16", 100, "2026-10-02"),
                  picking("CAB1", "NX-4W.3", 650, "2026-10-03"),                   # spool: 650 / 13 = 50 m/week
                  picking("W000001", "NX-1W1.6", 900, "2026-10-04"),
                  picking("W000001", "NX-1R8-NEX", 13000, "2026-10-10", unit="kg")]   # rod: 1000 kg/week               # into a wire order: internal
    cd_support.write_csv(folder / "production-picking-list-lines.csv", source.FILES["production-picking-list-lines.csv"][0], picks)

    inv_rows = inventory if inventory is not None else [
        ("NX-1C16", "MAN", 1000, 0, "m"), ("NX-1C16", "QC", 99999, 0, "m"), ("DM-1C1.5", "MAN", 100, 0, "m"),
        ("DX-1C1.5", "MAN", 0, 0, "m"), ("NM-1C16", "MAN", 5, 0, "m"), ("MNX-1C16", "MAN", 5, 0, "m"), ("NM-1C95", "MAN", 50, 0, "m"),
        ("MNX-1C95", "MAN", 50, 0, "m"), ("NX-1R8-NEX", "MAN", 40000, 900000, "kg"), ("FIL-12.00MM", "MAN", 5, 0, "kg")]
    with open(folder / source.INVENTORY, "w", newline="", encoding="utf-8-sig") as f:
        w = csv.writer(f)
        w.writerow(["Item number", "Product name", "Warehouse", "Physical inventory", "Ordered in total", "Inventory unit"])
        for item, wh, phys, inc, unit in inv_rows:
            w.writerow([item, "", wh, phys, inc, unit])
    (folder / source.INVENTORY_META).write_text(json.dumps({"export_date": "2026-10-26"}), encoding="utf-8")

    write_machine_files(folder, ex, rates=rates, days=days)

    ord_rows = r86_rows or R86_ORD_ROWS
    write_r86(folder, "R-86_ORD_MTD_2610", r86_text("order", ord_rows))
    write_r86(folder, "R-86_INV_MTD_2610", r86_text("invoice", R86_INV_ROWS, printed="29Oct'26  20:02:31"))
    return folder


def build(tmp_path: Path, folder: Path | None = None, settings=None, today: date = TODAY, **kw):
    """Build into a store; returns (cs_run dict, connection)."""
    folder = folder or make_source(tmp_path, **kw)
    db = tmp_path / "out" / "conductor-schedule.sqlite"
    run = run_build(folder, db, settings, today)
    con = sqlite3.connect(db)
    con.row_factory = sqlite3.Row
    return run, con


# ---------------------------------------------------------------- in-memory helpers for the scheduler unit tests

from datetime import datetime  # noqa: E402

from conductor_schedule import config as _config  # noqa: E402
from conductor_schedule.calendar import MachineCalendar  # noqa: E402
from conductor_schedule.history import History, Rate  # noqa: E402
from conductor_schedule.passes import Tree  # noqa: E402


def tree(pools: dict, inputs: dict, kg_per_m: dict | None = None) -> Tree:
    """A process tree: pools {item: pool}; inputs {item: [(input, qty_per_unit[, unit])]}. An input that is a key of
    `pools` is a made edge; ROD (NX-1R8-NEX) and anything else is a material."""
    t = Tree(pool=dict(pools), kg_per_m=dict(kg_per_m or {}), rods={_config.ROD_ITEM})
    for item, ins in inputs.items():
        t.inputs[item] = [(i, q, (rest[0] if rest else "kg" if i == _config.ROD_ITEM else "m"),
                           "made" if i in pools else "material") for i, q, *rest in ins]
    return t


def hist(item_jobs=None, rates=None, pool_jobs=None, alias=None) -> History:
    """item_jobs: {item: {(machine, op): n}}; rates: {(op, machine): (setup_min, per_hour)}."""
    h = History()
    for it, d_ in (item_jobs or {}).items():
        h.item_jobs[it].update(d_)
    for pl, d_ in (pool_jobs or {}).items():
        h.pool_jobs[pl].update(d_)
    for k, (s, r) in (rates or {}).items():
        h.rates[k] = Rate(s, r, 100)
    for k, v in (alias or {}).items():
        h.alias[k].update({v: 1})
    return h


WEEKDAYS = {i: [(8 * 3600, 16 * 3600, 1.0)] for i in range(5)}      # Mon-Fri 08:00-16:00


def cal(pattern=None, shutdown=()) -> MachineCalendar:
    return MachineCalendar(WEEKDAYS if pattern is None else pattern, shutdown)


def d(s: str) -> date:
    return date.fromisoformat(s)


ROD = _config.ROD_ITEM
H_BASIC = dict(
    item_jobs={"C": {("BU1", "BUNCHING"): 10}, "B": {("BU2", "BUNCHING"): 10}, "W": {("WD4", "WIRE_DRAW"): 10},
               "F": {("RB1", "ROD_BREAK"): 10}},
    rates={("BUNCHING", "BU1"): (30, 500), ("BUNCHING", "BU2"): (60, 1000), ("WIRE_DRAW", "WD4"): (60, 1000),
           ("ROD_BREAK", "RB1"): (45, 2000)})
WEIGHTS = {"C": 0.03, "B": 0.02, "W": 0.01, "F": 0.01}


def chain_tree(f: float = 1.0) -> Tree:
    """C (25_FCON) <- B (20_BUN) <- W (15_WIR) <- F (15_WIR) <- rod (0.018 kg per metre of F); f = metres of F per W."""
    return tree({"C": "25_FCON", "B": "20_BUN", "W": "15_WIR", "F": "15_WIR"},
                {"C": [("B", 1)], "B": [("W", 1)], "W": [("F", f)], "F": [(ROD, 0.018)]}, WEIGHTS)


def job(job_id, item="C", metres=1000.0, due="2026-11-02"):
    return (job_id, item, metres, due)


def kinds(issues) -> set:
    """{(item, kind)} of an IssueLog."""
    return {(i, k) for i, k, *_ in issues.rows()}
