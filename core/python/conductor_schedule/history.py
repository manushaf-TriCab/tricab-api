"""Machine history, rates and Dynamics calendars from the source folder (port of the prototype `inputs.py`).

Dynamics CSV dates are noon-UTC stamps (`2026-10-05T12:00:00Z`); only the date part is used.
"""

from __future__ import annotations

from collections import Counter, defaultdict
from dataclasses import dataclass, field
from datetime import date, timedelta
from pathlib import Path
from typing import Dict, List, Optional, Tuple

from . import config, source

Interval = Tuple[int, int, float]         # start second, end second, efficiency 0..1


@dataclass
class Rate:
    setup_min: float
    per_hour: float
    orders: int = 0


class History:
    """Which machines ran which items, and the (operation, machine) rate table."""

    def __init__(self):
        self.item_jobs: Dict[str, Counter] = defaultdict(Counter)    # item -> (machine, operation) -> jobs
        self.pool_jobs: Dict[str, Counter] = defaultdict(Counter)    # pool -> (machine, operation) -> jobs
        self.rates: Dict[Tuple[str, str], Rate] = {}
        self.alias: Dict[Tuple[str, str], Counter] = defaultdict(Counter)   # (op, machine) -> costing resource -> n

    def _eligible(self, jobs: Counter) -> List[Tuple[str, str]]:
        """Machines with >= MIN_SHARE of the jobs, each with its most frequent operation; most jobs first."""
        total = sum(jobs.values())
        if not total:
            return []
        per_machine: Dict[str, Counter] = defaultdict(Counter)
        for (m, op), n in jobs.items():
            per_machine[m][op] += n
        out = []
        for m, ops in per_machine.items():
            n = sum(ops.values())
            if n / total >= config.MIN_SHARE - 1e-12:
                out.append((n, m, sorted(ops.items(), key=lambda kv: (-kv[1], kv[0]))[0][0]))
        out.sort(key=lambda t: (-t[0], config.STAGE_RANK.get(t[1], 99), t[1]))
        return [(m, op) for _n, m, op in out]

    def eligible(self, item: str, pool: str) -> Tuple[List[Tuple[str, str]], str]:
        """([(machine, operation)], source) with source 'item', 'pool' or 'none'."""
        e = self._eligible(self.item_jobs.get(item, Counter()))
        if e:
            return e, "item"
        e = self._eligible(self.pool_jobs.get(pool, Counter()))
        return (e, "pool") if e else ([], "none")

    def rate(self, op: str, machine: str) -> Tuple[Optional[Rate], str]:
        """Rate for (operation, machine) and how it was found: exact, costing:<res>, operation_median, or none.

        Some machines (WD3, BU7) have no rows of their own: Dynamics costs them on another resource, so the most
        common costing resource seen in history for that (operation, machine) is used.
        """
        r = self.rates.get((op, machine))
        if r:
            return r, "exact"
        for res, _n in self.alias.get((op, machine), Counter()).most_common():
            r = self.rates.get((op, res))
            if r:
                return r, "costing:" + res
        same = [v for (o, _m), v in self.rates.items() if o == op]
        if same:
            vals = sorted(same, key=lambda v: v.per_hour)
            return vals[len(vals) // 2], "operation_median"
        return None, "none"


def load_history(folder: Path) -> History:
    """Process jobs of the 12 scheduled machines (not cancelled) joined to order item and route operation."""
    h = History()
    machines = set(config.MACHINES)
    for r in source.rows(folder, "machine-operation-parameters-full.csv"):
        rate = source.num(r["run_rate_per_hour_median"])
        if rate > 0:
            h.rates[(r["operation"], r["resource"])] = Rate(source.num(r["setup_minutes_median"]), rate,
                                                            int(source.num(r["orders"])))
    order_item: Dict[str, Tuple[str, str]] = {}
    for r in source.rows(folder, "production-order-headers-raw.csv"):
        order_item[r["ProductionOrderNumber"]] = (r["ItemNumber"], r["ProductionPoolId"])
    op_of: Dict[Tuple[str, str], Tuple[str, str]] = {}
    for r in source.rows(folder, "production-route-operations-raw.csv"):
        op_of[(r["ProductionOrderNumber"], r["OperationNumber"])] = (r["OperationId"], r["CostingOperationResourceId"])
    for r in source.rows(folder, "production-route-jobs-raw.csv"):
        if r["RouteJobType"] != "Process" or r["IsJobCancelled"] != "No" or r["OperationsResourceId"] not in machines:
            continue
        key = (r["ProductionOrderNumber"], r["OperationNumber"])
        if key not in op_of or key[0] not in order_item:
            continue
        op, costing = op_of[key]
        item, pool = order_item[key[0]]
        m = r["OperationsResourceId"]
        h.item_jobs[item][(m, op)] += 1
        if pool:
            h.pool_jobs[pool][(m, op)] += 1
        if costing:
            h.alias[(op, m)][costing] += 1
    return h


@dataclass
class Calendars:
    machine_calendar: Dict[str, str] = field(default_factory=dict)      # machine -> base calendar id
    machine_name: Dict[str, str] = field(default_factory=dict)
    days: Dict[str, Dict[str, List[Interval]]] = field(default_factory=dict)   # calendar -> ISO date -> intervals


def load_calendars(folder: Path) -> Calendars:
    cal, name = {}, {}
    for r in source.rows(folder, "work-calendars.csv"):
        if r["CalendarId"] in config.MACHINES:
            cal[r["CalendarId"]] = r["BaseWorkCalendarId"] or r["CalendarId"]
            name[r["CalendarId"]] = r["CalendarName"] or r["CalendarId"]
    need = set(cal.values())
    days: Dict[str, Dict[str, List[Interval]]] = {c: defaultdict(list) for c in need}
    for r in source.rows(folder, "work-calendar-time-intervals.csv"):
        c = r["WorkCalendarId"]
        if c in need:
            eff = source.num(r["EfficiencyPercentage"]) if r["EfficiencyPercentage"] else 100.0
            days[c][r["WorkCalendarDate"][:10]].append((int(source.num(r["StartTime"])), int(source.num(r["EndTime"])),
                                                        eff / 100.0))
    return Calendars(cal, name, {c: dict(v) for c, v in days.items()})


def week_pattern(days: Dict[str, List[Interval]], today: date) -> Tuple[Dict[int, List[Interval]], Optional[str]]:
    """Weekday (0=Mon) -> intervals, taken from the most recent full Monday-Sunday week ending before `today`
    that has any working interval. Returns (pattern, monday of the week used)."""
    monday = today - timedelta(days=today.weekday() + 7)       # last full week before this one
    for _ in range(520):
        wk = [(monday + timedelta(days=i)) for i in range(7)]
        if any(d.isoformat() in days for d in wk):
            pat = {d.weekday(): sorted(days[d.isoformat()]) for d in wk if d.isoformat() in days}
            return pat, monday.isoformat()
        monday -= timedelta(days=7)
    return {}, None
