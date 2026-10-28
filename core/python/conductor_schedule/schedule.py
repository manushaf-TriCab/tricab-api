"""Finite-capacity forward schedule of the combined operations (pure; no database)."""

from __future__ import annotations

from datetime import date, datetime, timedelta
from typing import Callable, Dict, List, Optional, Tuple

from . import config
from .batch import Batch, Batcher, latest, pool_take, spool_key
from .calendar import MachineCalendar
from .history import History, Rate
from .issues import IssueLog
from .passes import Op, Pass

EPS = 1e-6


def late_days(end: datetime, required_by: str) -> int:
    """Whole days the op finishes after required_by. An end exactly at midnight counts as the previous day."""
    last = (end - timedelta(minutes=1)).date() if end.time() == datetime.min.time() else end.date()
    return max(0, (last - date.fromisoformat(required_by)).days)


def _post_order(p: Pass):
    for c in p.children:
        yield from _post_order(c)
    yield p


def _candidates(p: Pass, history: History, cals: Dict[str, MachineCalendar]) -> List[Tuple[str, str, Rate, str]]:
    out = []
    for m, op in p.eligible:
        if m not in cals:
            continue
        rate, how = history.rate(op, m)
        if rate is not None:
            out.append((m, op, rate, how))
    return out


def _pre_order(p: Pass):
    yield p
    for c in p.children:
        yield from _pre_order(c)


def schedule(ops: List[Op], history: History, cals: Dict[str, MachineCalendar], today: date, issues: IssueLog,
             operation_factor: float = 1.0, product_factor: float = 1.0,
             batch_sizes: Optional[Dict[str, float]] = None, batch_rules: Optional[Dict[str, str]] = None,
             batching: bool = True, stock: Optional[Dict[str, float]] = None,
             min_levels: Optional[Dict[str, float]] = None, pool: bool = True,
             batch_items: Optional[Callable[[str], bool]] = None, root_stock: bool = True
             ) -> Tuple[List[Op], List[Batch], Dict[int, set]]:
    """Schedule ops in order (as given), children first. Passes take Dynamics on-hand first (CONTRACT-STOCK.md;
    `stock` is drawn down in place); wire passes (config.BATCH_POOLS) then draw from shared batches
    (CONTRACT-BATCH.md) unless batching is off. With `pool`, a spool drawn by a bunch or conductor pass is taken in
    strand-metres from any spool of its coating and diameter (CONTRACT-POOL.md). Returns (scheduled ops, batches,
    batch id -> op ids served). An op with a pass that has no machine with a rate is dropped and logged; an op
    covered by stock is skipped and logged. `batch_items` limits batching to the wire it accepts (the run passes
    config.is_stock_wire: only 1.6 mm is batched, CONTRACT-TRACKING); None batches every wire item."""

    def is_batched(p: Pass) -> bool:
        return p.pool in config.BATCH_POOLS and (batch_items is None or batch_items(p.item))

    stock = stock if stock is not None else {}
    t0 = datetime(today.year, today.month, today.day, config.START_HOUR)
    free: Dict[str, datetime] = {m: t0 for m in cals}
    cand_cache: Dict[int, list] = {}

    def place(p: Pass, qty: float, ready: datetime):
        """Earliest-finishing eligible machine for qty of p's item; books the machine."""
        best = None
        for m, oper, rate, how in cand_cache[id(p)]:
            setup_h = rate.setup_min / 60.0
            run_h = qty / (rate.per_hour * operation_factor * product_factor)
            st, en = cals[m].run(max(free[m], ready), setup_h + run_h)
            key = (en, config.STAGE_RANK.get(m, 99))
            if best is None or key < best[0]:
                best = (key, m, oper, rate.per_hour, setup_h, run_h, st, en, how)
        _k, m, oper, base, setup_h, run_h, st, en, how = best
        free[m] = en
        if how != "exact":
            issues.add(p.item, "rate_borrowed", "(%s, %s) has no rate row: used %s" % (oper, m, how))
        return m, oper, base, setup_h, run_h, st, en

    batcher = Batcher(batch_sizes or {}, batch_rules or {}, place, t0, issues, cals, stock, min_levels, pool,
                      batch_items)

    def bunching_input(p: Pass) -> bool:
        """A spool fed to a bunch or conductor pass: interchangeable by strand count. A spool that is the op's root
        goes to a cable as it is, and a feeder into another wire is drawn as named."""
        return (pool and p.parent is not None and p.parent.pool not in config.BATCH_POOLS
                and spool_key(p.item) is not None)
    done: List[Op] = []
    pass_id = 0
    for op in ops:
        order = list(_post_order(op.passes[0]))
        for p in order:
            cand_cache[id(p)] = _candidates(p, history, cals)
        bad = next((p for p in order if not cand_cache[id(p)]), None)
        if bad is not None:
            issues.add(bad.item, "no_rate", "no rate for any eligible machine %s: operation dropped"
                       % [m for m, _ in bad.eligible])
            op.status = "no_rate"
            continue
        removed = set()
        for p in _pre_order(op.passes[0]):                    # stock first, parents first; children shrink with them
            if p.parent is not None:
                if id(p.parent) in removed:
                    removed.add(id(p))
                    continue
                par = p.parent
                p.quantity = p.orig_quantity * (par.quantity / par.orig_quantity if par.orig_quantity > EPS else 0.0)
            if p.parent is None and not root_stock:
                continue                                      # the job's own item: restock already netted its stock
            if batching and is_batched(p):
                continue                                      # wire takes its stock in the batcher
            if bunching_input(p):
                got = pool_take(stock, p.item, p.quantity, batcher.members(p.item))
                for used, metres, _e in got:
                    batcher.note(p.item, used, metres)
                take = sum(e for _u, _m, e in got)
                p.stock_m, p.quantity = take, max(0.0, p.quantity - take)
                if p.quantity <= EPS:
                    removed.add(id(p))
                    if p.parent is not None:
                        issues.add(p.item, "covered_by_stock", "Dynamics on-hand covers the pass: not scheduled")
                continue
            have = stock.get(p.item, 0.0)
            take = min(have, p.quantity) if have > EPS else 0.0
            if take > EPS:
                stock[p.item] = have - take
                p.stock_m, p.quantity = take, p.quantity - take
            if p.quantity <= EPS:
                removed.add(id(p))
                if p.parent is not None:
                    issues.add(p.item, "covered_by_stock", "Dynamics on-hand covers the pass: not scheduled")
        op.stock_m = op.passes[0].stock_m
        if id(op.passes[0]) in removed:
            issues.add(op.item, "job_from_stock", "job covered by Dynamics on-hand: not scheduled")
            op.status = "from_stock"
            continue
        batched = set()
        if batching:
            for p in _pre_order(op.passes[0]):                # parents first: a wire pass's batch decides its feeders
                if not is_batched(p) or id(p) in removed:
                    continue
                if p.parent is not None and id(p.parent) in removed:
                    removed.add(id(p))
                    continue
                if p.parent is None or not is_batched(p.parent):
                    got = batcher.draw(p.item, p.quantity, p, ("op", op.op_id), pooled=bunching_input(p))
                    p.stock_m = sum(m for g, m, _t in got if g is None)
                    b, ready_at = latest(got)
                    if b is None:                             # all of it from stock
                        removed.add(id(p))
                        if p.parent is not None:
                            issues.add(p.item, "covered_by_stock", "Dynamics on-hand covers the pass: not scheduled")
                        continue
                else:
                    pb = p.parent.batch
                    feeders = pb.feeders.get(p.item) if pb is not None else None
                    if not feeders and pb is not None and pb.stock_feed.get(p.item, 0.0) > EPS:
                        removed.add(id(p))                    # the parent batch got this wire from stock
                        continue
                    if not feeders:
                        issues.add(p.item, "no_feeder_batch", "parent batch was made without this wire: shown "
                                   "against the item's latest batch")
                        known = [x for x in batcher.batches if x.item == p.item]
                        b = known[-1] if known else None
                        ready_at = b.end if b is not None else None
                    else:
                        b, ready_at = latest(feeders)
                if b is None:
                    continue
                p.batch = b
                p.machine, p.operation, p.base_rate = b.machine, b.operation, b.base_rate
                p.operation_factor, p.product_factor = operation_factor, product_factor
                # the op's share is ready when its metres come off the batch, not when the whole batch ends
                p.setup_h, p.run_h, p.start, p.end = 0.0, 0.0, b.start, max(b.start, ready_at)
                batched.add(id(p))
        op.stock_m = op.passes[0].stock_m
        if id(op.passes[0]) in removed:                       # a wire root taken wholly from stock
            issues.add(op.item, "job_from_stock", "job covered by Dynamics on-hand: not scheduled")
            op.status = "from_stock"
            continue
        if removed:                                           # drop covered passes (and their subtrees)
            for p in order:
                p.children = [c for c in p.children if id(c) not in removed]
            op.passes = [p for p in op.passes if id(p) not in removed]
            order = [p for p in order if id(p) not in removed]
        for p in order:
            if id(p) in batched:
                continue
            ready = t0
            for c in p.children:
                ready = max(ready, c.end)
            m, oper, base, setup_h, run_h, st, en = place(p, p.quantity, ready)
            p.machine, p.operation, p.base_rate = m, oper, base
            p.operation_factor, p.product_factor = operation_factor, product_factor
            p.setup_h, p.run_h, p.start, p.end = setup_h, run_h, st, en
        for p in order:
            pass_id += 1
            p.pass_id = pass_id
        op.status = "scheduled"
        op.start_at = min(p.start for p in order)
        op.end_at = op.passes[0].end
        op.late_days = late_days(op.end_at, op.required_by)
        done.append(op)
    return done, batcher.batches, batcher.ops_served()
