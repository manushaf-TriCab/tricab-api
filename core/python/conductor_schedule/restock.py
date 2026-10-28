"""Demand to releases to jobs (contract 4.4; port of restock_check.py, kanban model)."""

from __future__ import annotations

import math
import statistics
from dataclasses import dataclass, field
from datetime import date, timedelta

from . import config
from .issues import IssueLog

MICA_NOTE = "not scheduled: taping not modelled"
KANBAN_NOTE = "no runs in 52 weeks; kanban size unknown (1 week of use assumed)"


@dataclass
class Result:
    demand: list = field(default_factory=list)    # cs_demand rows
    restock: list = field(default_factory=list)   # cs_restock rows
    jobs: list = field(default_factory=list)      # cs_job rows
    r86: list = field(default_factory=list)       # cs_r86 rows


def _csa_value(text: str) -> float:
    return float(text)


def kanban_sizes(order_history, today: date) -> dict[str, list[float]]:
    """{item: [metres per run]} for orders finished within 364 days of today: CsReceivedQuantity, else ScheduledQuantity."""
    runs: dict[str, list[float]] = {}
    for o in order_history:
        d = (o["ReportedAsFinishedDate"] or "")[:10]
        try:
            age = (today - date.fromisoformat(d)).days
        except ValueError:
            continue
        if age > config.KANBAN_DAYS:
            continue
        q = _num(o["CsReceivedQuantity"]) or _num(o["ScheduledQuantity"])
        if q > 0:
            runs.setdefault(o["ItemNumber"], []).append(q)
    return runs


def _num(s) -> float:
    try:
        return float(s or 0)
    except ValueError:
        return 0.0


def median(values: list[float]) -> float:
    """Median; an even count takes the mean of the middle two (contract Q8)."""
    return statistics.median(values)


def select_items(stock: dict, rates: dict, window: str) -> dict[str, str]:
    """{item: form} for the restock item set (contract 4.4 step 1)."""
    down = {i: r for (i, unit, flow), r in rates.items() if unit == "m" and flow == "downstream"}
    out: dict[str, str] = {}
    for item in sorted(set(stock) | set(down)):
        if config.CONDUCTOR_RE.match(item):
            out[item] = "conductor"
        elif (m := config.SPOOL_RE.match(item)) and int(m.group(2)) <= config.SPOOL_MAX_WIRES and down.get(item, {}).get(window, 0) > 0:
            out[item] = "spool"
        elif config.VX_RE.match(item) and down.get(item, {}).get(window, 0) > 0:
            out[item] = "conductor"
    return out


def describe(item: str) -> tuple[str, int, float | None]:
    """(material, mica, csa) of a restock item; material 'unknown' for VX/VDX, csa None for spools."""
    if (m := config.CONDUCTOR_RE.match(item)):
        material, mica = config.MATERIAL[m.group(1)]
        return material, mica, _csa_value(m.group(2))
    if (m := config.VX_RE.match(item)):
        return "unknown", 0, _csa_value(m.group(2))
    m = config.SPOOL_RE.match(item)
    return ("plain" if m.group(1) == "NX" else "tinned"), 0, None


def build(settings: dict, today: date, forms: dict, rates: dict, stock: dict, kg_per_m: dict, runs: dict,
          r86_reports: dict, tree: dict, issues: IssueLog) -> Result:
    """tree: {item: traces_to_rod} for the process items (an item absent from it has no build tree)."""
    res = Result()
    window = settings["rate_window"]
    demand_mode = settings["demand"]
    doc = {"r86-order": "order", "r86-invoice": "invoice"}.get(demand_mode)

    def issue_rate(item, key):
        return rates.get((item, "m", "downstream"), {}).get(key, 0.0)

    # Weekly use per item.
    weekly: dict[str, tuple[float, str, int]] = {}   # item -> (weekly, source, unplaced)
    placed: dict[tuple, float] = {}
    bucket_members: dict[tuple, list] = {}
    if doc:
        rep = r86_reports[doc]
        weeks = rep.weeks
        totals = {k: v / weeks for k, v in rep.buckets().items()}
        for item, form in forms.items():
            m = config.CONDUCTOR_RE.match(item)
            if m:
                material, mica = config.MATERIAL[m.group(1)]
                bucket_members.setdefault((material, mica, _csa_value(m.group(2))), []).append(item)
        for key, members in bucket_members.items():
            total = totals.get(key, 0.0)
            hist = {i: issue_rate(i, "52w") for i in members}
            if sum(hist.values()) > 0:
                share = {i: total * hist[i] / sum(hist.values()) for i in members}
            else:
                lead = next((i for code in config.PLAIN_CODES for i in members if i.split("-")[0] == code), members[0])
                share = {i: (total if i == lead else 0.0) for i in members}
            for i, v in share.items():
                weekly[i] = (v, demand_mode, 0)
            placed[key] = sum(share.values())
        for key, w in totals.items():
            if key not in bucket_members:
                material, mica, csa = key
                issues.add(f"R-86 {material} {'mica ' if mica else ''}C{csa:g}", "r86_unplaced",
                           f"{w:.0f} m/week in the {doc} report has no restock item")
    for item in forms:
        if item not in weekly:
            weekly[item] = (issue_rate(item, window), "issues", 1 if doc else 0)

    # cs_r86: both documents, copper buckets with metres.
    for d, rep in sorted(r86_reports.items()):
        for (material, mica, csa), metres in sorted(rep.buckets().items()):
            w = metres / rep.weeks
            p = placed.get((material, mica, csa), 0.0) if d == doc else 0.0
            res.r86.append((d, material, mica, csa, float(metres), w, p, w - p))

    trigger_weeks = settings["refill_weeks"] + settings["safety_weeks"]
    for item, form in sorted(forms.items()):
        material, mica, csa = describe(item)
        use, src, unplaced = weekly[item]
        s = stock.get(item)
        physical, incoming = (s.physical, s.incoming) if s else (0.0, 0.0)
        notes = list(dict.fromkeys(s.notes)) if s else []
        res.demand.append((item, material, mica, csa, src, use, issue_rate(item, window), issue_rate(item, "52w"), unplaced))

        q = runs.get(item, [])
        assumed = 0
        if q:
            kanban = median(q)
        else:
            kanban, assumed = max(use, 0.0), 1
            notes.append(KANBAN_NOTE)
            if use > 0:
                issues.add(item, "kanban_assumed", KANBAN_NOTE)
        trigger = max(use, 0.0) * trigger_weeks
        position = physical + incoming
        if use <= 0:
            status, release_runs = "no_use", 0
        elif position <= trigger:
            status, release_runs = "release", math.floor((trigger - position) / kanban) + 1
        else:
            status, release_runs = "ok", 0
        release_m = release_runs * kanban
        kg = kg_per_m.get(item)
        weeks_on_hand = physical / use if use > 0 else None

        scheduled = 1
        if mica:
            scheduled = 0
            notes.append(MICA_NOTE)
            if status == "release":
                issues.add(item, "mica_not_scheduled", f"{release_runs} run(s) released; {MICA_NOTE}")
        elif not tree.get(item):
            scheduled = 0
            if status == "release":
                why = "no process item (off the map, or never made)" if item not in tree else "does not trace to rod"
                issues.add(item, "no_build_tree", f"{why}; {release_runs} run(s) released but not scheduled")
        if status == "release" and scheduled and kg is None:
            issues.add(item, "no_weight", "no NetProductWeight; no kg out")
        res.restock.append((item, form, material, mica, csa, kg, use, kanban, assumed, len(q), trigger, physical, incoming,
                            position, weeks_on_hand, release_runs, release_m, release_m * kg if kg is not None else None,
                            status, scheduled, "; ".join(notes) or None))

        if status == "release" and scheduled:
            for i in range(release_runs):
                days = math.floor(max(0.0, physical / use) * 7 + i * kanban / use * 7)
                res.jobs.append([item, i + 1, kanban, (today + timedelta(days=days)).isoformat()])

    res.jobs.sort(key=lambda j: (j[3], j[0], j[1]))
    res.jobs = [(n, item, run_no, metres, due, "scheduled") for n, (item, run_no, metres, due) in enumerate(res.jobs, 1)]
    return res
