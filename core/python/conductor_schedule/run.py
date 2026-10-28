"""Orchestrates a build: check, settings, R-86, process tree, stock, restock, jobs, (schedule), store."""

from __future__ import annotations

import json
from dataclasses import dataclass, field
from datetime import date, datetime, timedelta, timezone
from pathlib import Path

from conductor_process import source as process_source
from conductor_process.build import COLS as PROCESS_COLS, build_tables as build_process_tables

from cable_routes.loader import SourceError

from . import (SCHEMA_VERSION, __version__, batch as batch_mod, calendar as calendar_mod, config, history as history_mod,
               r86, rates, restock, schedule as sched, settings as settings_mod, source, stock as stock_mod)
from .passes import Tree, build_ops
from .issues import ALL, IssueLog
from .store import write_atomic

RUN_COLS = ("run_id", "schema_version", "built_at", "engine_version", "source_name", "today", "horizon_end", "data_as_of",
            "demand", "rate_window", "settings_hash", "settings_source", "inventory_file", "inventory_exported",
            "r86_order_file", "r86_order_from", "r86_order_printed", "r86_order_weeks",
            "r86_invoice_file", "r86_invoice_from", "r86_invoice_printed", "r86_invoice_weeks",
            "restock_items", "release_items", "release_runs", "release_m", "release_kg", "jobs", "jobs_scheduled",
            "ops", "passes", "batches", "late_ops")
COLS = {
    "cs_run": RUN_COLS,
    "cs_source": ("name", "role", "as_of", "rows"),
    "cs_setting": ("key", "value"),
    "cs_r86": ("doc", "material", "mica", "csa_mm2", "metres", "weekly_m", "placed_m", "unplaced_m"),
    "cs_demand": ("item", "material", "mica", "csa_mm2", "source", "weekly_use_m", "issue_weekly_m", "issue_weekly_52w_m",
                  "unplaced"),
    "cs_restock": ("item", "form", "material", "mica", "csa_mm2", "kg_per_m", "weekly_use_m", "kanban_m", "kanban_assumed",
                   "runs_52w", "trigger_m", "physical_m", "incoming_m", "position_m", "weeks_on_hand", "release_runs",
                   "release_m", "release_kg", "status", "scheduled", "note"),
    "cs_job": ("job_id", "item", "run_no", "metres", "due_date", "status"),
    "cs_issue": ("item", "kind", "severity", "detail", "count"),
    "cs_machine": ("machine", "poster_name", "name", "calendar", "calendar_source", "efficiency", "hours_per_week",
                   "stage_order"),
    "cs_op": ("op_id", "item", "pool", "metres", "required_by", "start_at", "end_at", "late_days", "rod_kg", "out_kg",
              "pass_count", "stock_m"),
    "cs_pass": ("pass_id", "op_id", "parent_pass_id", "item", "pool", "depth", "machine", "eligible", "operation", "quantity",
                "unit", "base_rate", "operation_factor", "product_factor", "setup_h", "run_h", "start_at", "end_at",
                "batch_id", "stock_m"),
    "cs_batch": ("batch_id", "item", "pool", "machine", "operation", "metres", "batch_rule", "allocated_m", "surplus_m",
                 "base_rate", "setup_h", "run_h", "start_at", "end_at", "op_count", "reason"),
    "cs_draw": ("batch_id", "consumer_kind", "consumer_id", "metres"),
    "cs_stock": ("item", "point", "pool", "unit", "on_hand_m", "used_m", "on_hand_kg", "used_kg", "incoming_m", "incoming_kg",
                 "kg_per_m", "weekly_use"),
    "cs_wire_policy": ("item", "batch_m", "batch_rule", "min_m", "min_rule", "weekly_use_m", "refill_days", "orders",
                       "on_hand_m", "below_min_at_start"),
    "cs_capacity": ("machine", "week", "available_h"),
    "cs_load": ("machine", "week", "hours"),
}
# Insert order: parents before children.
ORDER = ("cs_run", "cs_source", "cs_setting", "cs_r86", "cs_demand", "cs_restock", "cs_job", "cs_machine", "cs_batch",
         "cs_op", "cs_pass", "cs_draw", "cs_stock", "cs_wire_policy", "cs_capacity", "cs_load", "cs_issue")


@dataclass
class Context:
    """Everything the schedule step (Engine B) needs from Engine A."""
    folder: Path
    settings: dict
    today: date
    restock: restock.Result
    stock: dict
    products: dict
    process_tables: dict           # conductor_process build_tables() output
    issues: IssueLog
    log: object
    rod_weekly_kg: float = 0.0
    counters: dict = field(default_factory=lambda: {"horizon_end": None, "jobs_scheduled": 0, "ops": 0, "passes": 0,
                                                    "batches": 0, "late_ops": 0})


def ts(dt: datetime) -> str:
    """Plant-local ISO to the nearest minute."""
    return (dt + timedelta(seconds=30)).strftime("%Y-%m-%dT%H:%M")


def weekly(ops, batches, cals: dict, t0: datetime, horizon: datetime):
    """(machine, monday) -> productive hours available (to the horizon + 4 weeks, at least CAPACITY_WEEKS weeks), and
    -> hours booked. A bar's hours are split across weeks by its working time in each week (CONTRACT-BATCH), so a week
    cannot exceed what the calendar offers. Batch passes are left out; each batch counts once."""
    first = t0.date() - timedelta(days=t0.weekday())
    last = max(horizon.date() + timedelta(weeks=4), first + timedelta(weeks=config.CAPACITY_WEEKS - 1))
    weeks, d = [], first
    while d <= last:
        weeks.append(d)
        d += timedelta(weeks=1)
    capacity = {}
    for m, cal in cals.items():
        for w in weeks:
            ws = datetime(w.year, w.month, w.day)
            capacity[(m, w.isoformat())] = round(cal.productive_hours(max(t0, ws), ws + timedelta(weeks=1)), 4)
    bars = [(p.machine, p.start, p.end, p.setup_h + p.run_h) for o in ops for p in o.passes if p.batch is None]
    bars += [(b.machine, b.start, b.end, b.setup_h + b.run_h) for b in batches]
    load: dict = {}
    for m, st, en, hours in bars:
        if hours <= 0 or en <= st:
            continue
        total = cals[m].productive_hours(st, en)
        w = st.date() - timedelta(days=st.weekday())
        while datetime(w.year, w.month, w.day) < en:
            ws = datetime(w.year, w.month, w.day)
            part = cals[m].productive_hours(max(st, ws), min(en, ws + timedelta(weeks=1)))
            if part > 0 and total > 0:
                k = (m, w.isoformat())
                load[k] = load.get(k, 0.0) + hours * part / total
            w += timedelta(weeks=1)
    return capacity, {k: round(v, 4) for k, v in load.items()}


def _policies(order_history, today: date, cfg: dict):
    """Per 1.6 mm wire item: WirePolicy from history, then settings batch_sizes / min_levels."""
    pols = {i: p for i, p in batch_mod.history_policies(order_history, today).items() if config.is_stock_wire(i)}

    def empty(i):
        return batch_mod.WirePolicy(i, None, "lot_for_lot", 0.0, "none", None, None, 0)

    for item, metres in cfg["batch_sizes"].items():
        pol = pols.setdefault(item, empty(item))
        pol.batch_m, pol.batch_rule = float(metres), "config"
    for item, metres in cfg["min_levels"].items():
        pol = pols.setdefault(item, empty(item))
        pol.min_m, pol.min_rule = float(metres), "config"
    return pols


def schedule_stage(ctx: Context) -> dict:
    """Place the jobs on machines (Engine B, contract 4.5 to 4.7); returns {table: rows} and updates ctx.counters."""
    cfg, today, issues, res = ctx.settings, ctx.today, ctx.issues, ctx.restock
    kg_per_m = {i: p["kg_per_m"] for i, p in ctx.products.items() if p["kg_per_m"]}
    history = history_mod.load_history(ctx.folder)
    try:
        cals, meta = calendar_mod.make_calendars(history_mod.load_calendars(ctx.folder), today, cfg, issues)
    except calendar_mod.CalendarError as e:
        raise SourceError(str(e)) from None
    ctx.log("scheduling")
    yields = cfg["yields"]
    grown = {s: v for s, v in yields.items() if v != 1.0}
    if grown:
        issues.add(ALL, "yield_applied", "inputs grown by yield " + json.dumps(grown, sort_keys=True))

    tree = Tree.from_tables(ctx.process_tables, kg_per_m)
    jobs = [(j[0], j[1], j[3], j[4]) for j in res.jobs]
    ops, failed = build_ops(jobs, tree, history, issues, yields)
    order_history = list(source.rows(ctx.folder, "production-order-headers.csv"))
    pols = _policies(order_history, today, cfg)
    sizes = {i: p.batch_m for i, p in pols.items() if p.batch_m}
    rules = {i: p.batch_rule for i, p in pols.items() if p.batch_m}
    mins = {i: p.min_m for i, p in pols.items() if p.min_m > 0}

    on_hand = {i: s.physical for i, s in ctx.stock.items() if i != config.ROD_ITEM and s.physical > 0}
    stock = dict(on_hand)
    done, batches, served = sched.schedule(ops, history, cals, today, issues, batch_sizes=sizes, batch_rules=rules,
                                           stock=stock, min_levels=mins, batch_items=config.is_stock_wire,
                                           root_stock=False)
    status = {op.op_id: op.status for op in ops}
    status.update(failed)

    t0 = datetime(today.year, today.month, today.day, config.START_HOUR)
    last_op = max((o.end_at for o in done), default=None)
    horizon = max([o.end_at for o in done] + [b.end for b in batches], default=datetime(today.year, today.month, today.day))
    capacity, load = weekly(done, batches, cals, t0, horizon)
    for r in cfg["shutdown"]:
        a, b = date.fromisoformat(r["from"]), date.fromisoformat(r["to"])
        if last_op is not None and a <= last_op.date() and b >= today:
            issues.add(ALL, "shutdown_in_horizon", f"shutdown {r['from']} to {r['to']} falls inside the schedule")

    # Jobs: status; restock items whose jobs cannot be placed are not scheduled.
    job_rows = [(n, item, run_no, metres, due, status.get(n, "scheduled"))
                for (n, item, run_no, metres, due, _s) in res.jobs]
    bad = {r[1] for r in job_rows if r[5] in ("no_machine", "no_rate")}
    rix = {n: k for k, n in enumerate(COLS["cs_restock"])}
    for k, row in enumerate(res.restock):
        if row[rix["item"]] in bad:
            row = list(row)
            row[rix["scheduled"]] = 0
            row[rix["note"]] = "; ".join(x for x in (row[rix["note"]], "not scheduled: no machine or rate for a pass") if x)
            res.restock[k] = tuple(row)

    machines = [(m, config.POSTER_NAME[m], meta[m]["name"], meta[m]["calendar"], meta[m]["source"], meta[m]["efficiency"],
                 cals[m].hours_per_week(), config.STAGE_RANK[m]) for m in config.MACHINES]
    op_rows, pass_rows = [], []
    for o in done:
        order = sorted(o.passes, key=lambda p: p.pass_id)
        op_rows.append((o.op_id, o.item, o.pool, o.metres, o.required_by, ts(o.start_at), ts(o.end_at), o.late_days,
                        o.rod_kg, o.out_kg, len(order), o.stock_m))
        pass_rows += [(p.pass_id, o.op_id, p.parent.pass_id if p.parent else None, p.item, p.pool, p.depth, p.machine,
                       json.dumps([m for m, _ in p.eligible]), p.operation, p.quantity, p.unit, p.base_rate,
                       p.operation_factor, p.product_factor, p.setup_h, p.run_h, ts(p.start), ts(p.end),
                       p.batch.batch_id if p.batch is not None else None, p.stock_m) for p in order]
    batch_rows = [(b.batch_id, b.item, b.pool, b.machine, b.operation, b.metres, b.rule, b.allocated, b.remaining,
                   b.base_rate, b.setup_h, b.run_h, ts(b.start), ts(b.end), len(served.get(b.batch_id, ())), b.reason)
                  for b in batches]
    draw_rows = [(b.batch_id, kind, cid, m) for b in batches for (kind, cid), m in b.draws.items()]

    # Stock points and every item stock was drawn from.
    pool_of = {**{i: p["pool"] for i, p in ctx.products.items()}, **tree.pool}
    restock_form = {r[rix["item"]]: r[rix["form"]] for r in res.restock}
    used = {i: on_hand[i] - stock.get(i, 0.0) for i in on_hand}
    stock_items = ({i for i, u in used.items() if u > 1e-9} | set(restock_form)
                   | {i for i in on_hand if batch_mod.spool_key(i)} | {i for i in ctx.stock if config.is_stock_wire(i)})
    stock_rows = []
    for item in sorted(stock_items):
        s = ctx.stock.get(item)
        phys, inc = (s.physical if s else 0.0), (s.incoming if s else 0.0)
        w = kg_per_m.get(item)
        if config.is_stock_wire(item):
            point = "wire_plain" if item.startswith("NX") else "wire_tinned"
        elif restock_form.get(item) == "conductor":
            point = "conductor"
        elif restock_form.get(item) == "spool" or batch_mod.spool_key(item):
            point = "spool"
        else:
            point = "other"
        u = used.get(item, 0.0)
        stock_rows.append((item, point, pool_of.get(item, ""), "m", phys, u, phys * w if w else None,
                           u * w if w else None, inc, inc * w if w else None, w, None))
    rod = ctx.stock.get(config.ROD_ITEM)
    if rod:
        stock_rows.append((config.ROD_ITEM, "rod", "", "kg", None, None, rod.physical,
                           sum(o.rod_kg or 0.0 for o in done), None, rod.incoming, None, ctx.rod_weekly_kg))
        stock_rows.sort(key=lambda r: r[0])

    wire_items = sorted({p.item for o in done for p in o.passes if config.is_stock_wire(p.item)}
                        | {b.item for b in batches} | set(pols))
    empty = batch_mod.WirePolicy("", None, "lot_for_lot", 0.0, "none", None, None, 0)
    policy_rows = []
    for i in wire_items:
        pl = pols.get(i, empty)
        oh = on_hand.get(i, 0.0)
        policy_rows.append((i, pl.batch_m, pl.batch_rule, pl.min_m, pl.min_rule, pl.weekly_use_m, pl.refill_days,
                            pl.orders, oh, int(oh + 1e-6 < pl.min_m)))

    c = ctx.counters
    c["horizon_end"] = last_op.date().isoformat() if last_op else None
    c["jobs_scheduled"] = c["ops"] = len(done)
    c["passes"] = sum(len(o.passes) for o in done)
    c["batches"] = len(batches)
    c["late_ops"] = sum(1 for o in done if o.late_days > 0)
    return {
        "cs_job": job_rows, "cs_machine": machines, "cs_op": op_rows, "cs_pass": pass_rows, "cs_batch": batch_rows,
        "cs_draw": draw_rows, "cs_stock": stock_rows, "cs_wire_policy": policy_rows,
        "cs_capacity": [(m, w, h) for (m, w), h in sorted(capacity.items())],
        "cs_load": [(m, w, h) for (m, w), h in sorted(load.items())],
    }


def _tree(process_tables: dict) -> dict:
    cols = PROCESS_COLS["cd_item"]
    i_item, i_trace = cols.index("item"), cols.index("traces_to_rod")
    return {r[i_item]: r[i_trace] for r in process_tables["cd_item"][1]}


def _pick(reports: dict, doc: str, issues: IssueLog):
    reps = reports[doc]
    for old in reps[1:]:
        issues.add(old.name, "r86_older_ignored", f"{doc}: printed {old.printed}, not the latest ({reps[0].name})")
    return reps[0]


def run_build(folder: Path, db: Path, settings_path: Path | None, today: date, log=lambda m: None) -> dict:
    """Build the store; returns the cs_run row as {column: value}. Raises SourceError (3) / ConfigError (2)."""
    folder = Path(folder)
    source.check(folder)
    cfg, settings_source, settings_hash = settings_mod.load(settings_path)
    issues = IssueLog()

    reports = r86.discover(folder / source.R86_DIR)
    chosen = {doc: _pick(reports, doc, issues) for doc in ("order", "invoice")}
    for doc, rep in chosen.items():
        log(f"r86 {doc}: {rep.name} period {rep.period_from} printed {rep.printed} ({rep.weeks:.2f} weeks)")
        if rep.weeks < cfg["r86_min_weeks"]:
            issues.add(rep.name, "r86_short_period", f"{rep.weeks:.2f} weeks elapsed, under {cfg['r86_min_weeks']:g}")
    if chosen["order"].period_from != chosen["invoice"].period_from:
        issues.add(ALL, "r86_period_mismatch",
                   f"order from {chosen['order'].period_from}, invoice from {chosen['invoice'].period_from}")

    log("process tree")
    src, extra = process_source.load(folder)
    process_tables = build_process_tables(src, extra, folder.name)
    tree = _tree(process_tables)
    dates = [h["ScheduledDate"][:10] for h in src.headers if h["ScheduledDate"][:10] not in ("", "1900-01-01")]
    data_as_of = max(dates) if dates else today.isoformat()

    products = source.products(folder)
    kg_per_m = {i: p["kg_per_m"] for i, p in products.items() if p["kg_per_m"]}
    order_history = list(source.rows(folder, "production-order-headers.csv"))
    orders = {o["ProductionOrderNumber"]: (o["ItemNumber"], o["ProductionPoolId"]) for o in order_history}
    log(f"issue rates from {source.count_rows(folder / 'production-picking-list-lines.csv')} picking lines")
    issue_rates = rates.compute(folder, products, orders, today)

    exported, known = source.inventory_exported(folder)
    if not known:
        issues.add(ALL, "inventory_date_unknown", f"no {source.INVENTORY_META}; file date {exported} used")
    window = cfg["rate_window"]
    relevant = lambda base: (bool(config.CONDUCTOR_RE.match(base) or config.SPOOL_RE.match(base) or config.VX_RE.match(base))
                             or (base, "m", "downstream") in issue_rates)
    stock = stock_mod.load(folder, kg_per_m, relevant, issues)
    forms = restock.select_items(stock, issue_rates, window)
    res = restock.build(cfg, today, forms, issue_rates, stock, kg_per_m, restock.kanban_sizes(order_history, today),
                        chosen, tree, issues)
    log(f"restock: {len(res.restock)} items, {len(res.jobs)} jobs")

    rod = stock.get(config.ROD_ITEM)
    rod_rate = next((r[window] for (i, _, f), r in issue_rates.items() if i == config.ROD_ITEM and f == "rod_in"), 0.0)
    if rod and rod_rate > 0 and rod.physical / rod_rate < cfg["rod_warn_weeks"]:
        issues.add(config.ROD_ITEM, "rod_cover_low",
                   f"{rod.physical / rod_rate:.1f} weeks of cover ({rod.physical:.0f} kg at {rod_rate:.0f} kg/week), "
                   f"under {cfg['rod_warn_weeks']:g}")

    ctx = Context(folder, cfg, today, res, stock, products, process_tables, issues, log, rod_rate)
    extra_tables = schedule_stage(ctx)
    c = ctx.counters

    rs = res.restock
    ix = {n: k for k, n in enumerate(COLS["cs_restock"])}
    released = [r for r in rs if r[ix["status"]] == "release"]
    run = (1, SCHEMA_VERSION, datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"), __version__, folder.name,
           today.isoformat(), c["horizon_end"] or today.isoformat(), data_as_of, cfg["demand"], window, settings_hash,
           settings_source, source.INVENTORY, exported,
           chosen["order"].name, chosen["order"].period_from, chosen["order"].printed, chosen["order"].weeks,
           chosen["invoice"].name, chosen["invoice"].period_from, chosen["invoice"].printed, chosen["invoice"].weeks,
           len(rs), len(released), sum(r[ix["release_runs"]] for r in released),
           sum(r[ix["release_m"]] for r in released), sum(r[ix["release_kg"]] or 0.0 for r in released),
           len(extra_tables.get("cs_job", res.jobs)),
           c["jobs_scheduled"], c["ops"], c["passes"], c["batches"], c["late_ops"])

    sources = []
    for name, (_, role) in source.FILES.items():
        p = folder / name
        asof = exported if name == source.INVENTORY else source.mtime_date(p)
        sources.append((name, role, asof, source.count_rows(p)))
    if (folder / source.INVENTORY_META).is_file():
        sources.append((source.INVENTORY_META, "inventory-meta", source.mtime_date(folder / source.INVENTORY_META), None))
    for doc, rep in chosen.items():
        sources.append((f"{source.R86_DIR}/{rep.name}", f"r86-{doc}", rep.printed[:10], len(rep.rows)))

    data = {
        "cs_run": [run], "cs_source": sources,
        "cs_setting": [(k, json.dumps(v, ensure_ascii=False)) for k, v in cfg.items()],
        "cs_r86": res.r86, "cs_demand": res.demand, "cs_restock": rs, "cs_job": res.jobs,
        "cs_issue": issues.rows(),
    }
    for name, rows in extra_tables.items():
        data[name] = rows
    tables = {name: (COLS[name], data.get(name, [])) for name in ORDER}
    write_atomic(db, tables)
    return dict(zip(RUN_COLS, run))
