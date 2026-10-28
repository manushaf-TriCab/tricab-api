"""Wire batching (planning/conductor/CONTRACT-BATCH.md): wire passes draw from shared fixed-size batches instead of
each op running its own rod-break, tinning and draw (pure; no database).

Spool pooling (CONTRACT-POOL.md): bunching takes any mix of drawn spools that gives the strand count, so a bunch or
conductor pass's wire is drawn in strand-metres from every spool of the same coating and wire diameter."""

from __future__ import annotations

import re
from collections import Counter, OrderedDict, defaultdict
from dataclasses import dataclass, field
from datetime import date, datetime, timedelta
from typing import Callable, Dict, List, Optional, Tuple

from . import config
from .issues import IssueLog
from .passes import Pass

EPS = 1e-6

Consumer = Tuple[str, int]                  # ('op', op_id) or ('batch', batch_id)

SPOOL_RE = re.compile(r"^(NX|DX)-(\d+)W(\d*\.\d+)$")


def spool_key(item: str) -> Optional[Tuple[str, str]]:
    """(coating, wire diameter) for a drawn multi-wire spool (NX-8W.3 -> ('NX', '.3')), else None."""
    m = SPOOL_RE.match(item)
    if not m or int(m.group(2)) > config.SPOOL_MAX_WIRES:
        return None
    return m.group(1), m.group(3)


def wires(item: str) -> int:
    return int(SPOOL_RE.match(item).group(2))


def pool_take(stock: Dict[str, float], item: str, qty: float, members) -> List[Tuple[str, float, float]]:
    """Take `qty` metres of spool `item` from on-hand as strand-metres: the item itself first, then the other members
    with the most strand-metres. Returns (item used, its metres, metres of `item` they stand for); draws `stock` down."""
    n = wires(item)
    need = qty * n
    out = []
    order = [item] + sorted((i for i in members if i != item), key=lambda i: (-stock.get(i, 0.0) * wires(i), i))
    for i in order:
        have = stock.get(i, 0.0)
        if need <= EPS:
            break
        if have <= EPS:
            continue
        take = min(have, need / wires(i))
        stock[i] = have - take
        need -= take * wires(i)
        out.append((i, take, take * wires(i) / n))
    return out


@dataclass
class WirePolicy:
    item: str
    batch_m: Optional[float]
    batch_rule: str                      # history | config | lot_for_lot
    min_m: float
    min_rule: str                        # history | config | none
    weekly_use_m: Optional[float]
    refill_days: Optional[float]
    orders: int


def _median(xs: List[float]) -> float:
    xs = sorted(xs)
    n = len(xs)
    return xs[n // 2] if n % 2 else (xs[n // 2 - 1] + xs[n // 2]) / 2.0


def history_policies(rows, today: date, min_orders: int = config.BATCH_MIN_ORDERS) -> Dict[str, WirePolicy]:
    """Per wire item, from batched-pool (15_WIR) orders Completed/ReportedFinished in the 52 weeks before `today`
    (CONTRACT-TRACKING, MINLEVEL): run size = median CsReceivedQuantity, weekly use = received / 52, refill days =
    median ScheduledStartDate -> ReportedAsFinishedDate (>= 1; the release date is not in the extract), minimum =
    weekly use x refill days / 7. Items with fewer than min_orders orders get no size and min 0."""
    lo = (today - timedelta(weeks=config.PRIOR_BATCH_WEEKS)).isoformat()
    hi = today.isoformat()
    recv: Dict[str, List[float]] = defaultdict(list)
    lead: Dict[str, List[float]] = defaultdict(list)
    for r in rows:
        if r.get("ProductionPoolId") not in config.BATCH_POOLS:
            continue
        if r.get("ProductionOrderStatus") not in ("Completed", "ReportedFinished"):
            continue
        fin = (r.get("ReportedAsFinishedDate") or "")[:10]
        if not (lo <= fin < hi):
            continue
        try:
            q = float(r.get("CsReceivedQuantity") or 0)
        except ValueError:
            continue
        if q <= 0:
            continue
        recv[r["ItemNumber"]].append(q)
        st = (r.get("ScheduledStartDate") or "")[:10]
        if st > "2000":
            try:
                lead[r["ItemNumber"]].append(max(0, (date.fromisoformat(fin) - date.fromisoformat(st)).days))
            except ValueError:
                pass
    out = {}
    for item, qs in recv.items():
        n = len(qs)
        if n < min_orders:
            out[item] = WirePolicy(item, None, "lot_for_lot", 0.0, "none", sum(qs) / 52.0, None, n)
            continue
        weekly = sum(qs) / 52.0
        refill = max(1.0, _median(lead[item])) if lead[item] else 1.0
        out[item] = WirePolicy(item, _median(qs), "history", weekly * refill / 7.0, "history", weekly, refill, n)
    return out


@dataclass(eq=False)
class Batch:
    batch_id: int
    item: str
    pool: str
    metres: float
    rule: str                                # history | config | lot_for_lot
    remaining: float
    reason: str = "shortage"                 # shortage | min_level
    feeders: Dict[str, List[Tuple["Batch", float, datetime]]] = field(default_factory=dict)
    draws: "OrderedDict[Consumer, float]" = field(default_factory=OrderedDict)
    stock_feed: Dict[str, float] = field(default_factory=dict)      # feeder metres taken from on-hand, by item
    machine: str = ""
    operation: str = ""
    base_rate: float = 0.0
    setup_h: float = 0.0
    run_h: float = 0.0
    start: Optional[datetime] = None
    end: Optional[datetime] = None
    cal: object = None                       # the machine's MachineCalendar, for when metres come off the line

    @property
    def allocated(self) -> float:
        return self.metres - self.remaining

    def available_at(self, cumulative: float) -> datetime:
        """When the first `cumulative` metres of the batch are made: wire comes off as spools while the run goes on,
        so a consumer waits for its share, not for the whole batch."""
        if self.cal is None or self.metres <= EPS or cumulative >= self.metres - EPS:
            return self.end
        return self.cal.run(self.start, self.setup_h + self.run_h * cumulative / self.metres)[1]


# place(pass, ready, work_h_for_rate) -> (machine, operation, base_rate, setup_h, run_h, start, end)
Placer = Callable[[Pass, float, datetime], Tuple[str, str, float, float, float, datetime, datetime]]


class Batcher:
    def __init__(self, sizes: Dict[str, float], rules: Dict[str, str], place: Placer, t0: datetime,
                 issues: IssueLog, cals=None, stock: Optional[Dict[str, float]] = None,
                 min_levels: Optional[Dict[str, float]] = None, pool: bool = False,
                 batch_items: Optional[Callable[[str], bool]] = None):
        self.sizes, self.rules, self.place, self.t0, self.issues = sizes, rules, place, t0, issues
        self.cals = cals or {}
        self.stock = stock if stock is not None else {}             # shared with the scheduler; drawn down here
        self.min_levels = min_levels or {}
        self.open: Dict[str, List[Batch]] = defaultdict(list)
        self.batches: List[Batch] = []
        self.pool = pool
        self.batch_items = batch_items
        self.substituted: Dict[Tuple[str, str], float] = defaultdict(float)   # (wanted, used) -> metres of used

    def members(self, item: str) -> List[str]:
        """Spools sharing `item`'s coating and diameter that have stock, a batch size or a minimum."""
        key = spool_key(item)
        seen = set(self.stock) | set(self.sizes) | set(self.min_levels) | set(self.open) | {item}
        return sorted(i for i in seen if spool_key(i) == key)

    def draw(self, item: str, qty: float, template: Pass, consumer: Consumer,
             pooled: bool = False) -> List[Tuple[Batch, float, datetime]]:
        """Take `qty` of `item`: on-hand first (ready now, batch None), then open batches (oldest first), creating
        batches until covered. Each piece comes with the time its metres are made. `pooled` (bunching input, with
        pooling on) draws in strand-metres from every spool of the item's coating and diameter."""
        if pooled and self.pool and spool_key(item) is not None:
            return self._draw_pooled(item, qty, template, consumer)
        got: List[Tuple[Optional[Batch], float, datetime]] = []
        need = qty
        floor = self.min_levels.get(item, 0.0)
        while True:                                  # keep what is left after this draw at or above the minimum
            available = self.stock.get(item, 0.0) + sum(b.remaining for b in self.open[item])
            if available + EPS >= need + floor:
                break
            short = available + EPS < need
            b = self._create(item, template, need + floor - available)
            b.reason = "shortage" if short else "min_level"
        have = self.stock.get(item, 0.0)
        if have > EPS and need > EPS:
            take = min(have, need)
            self.stock[item] = have - take
            need -= take
            got.append((None, take, self.t0))
        q = self.open[item]
        while need > EPS:
            b = q[0]
            take = min(b.remaining, need)
            b.remaining -= take
            need -= take
            b.draws[consumer] = b.draws.get(consumer, 0.0) + take
            got.append((b, take, b.available_at(b.allocated)))
            if b.remaining <= EPS:
                b.remaining = 0.0
                q.pop(0)
        return got

    def _draw_pooled(self, item: str, qty: float, template: Pass, consumer: Consumer):
        """draw() for bunching input: the pool's strand-metres must cover the need plus the members' minimums; a
        shortfall makes batches of the wanted item. Pieces are in metres of `item` (strand count kept)."""
        n = wires(item)
        group = self.members(item)
        need = qty * n
        floor = sum(self.min_levels.get(i, 0.0) * wires(i) for i in group)
        while True:
            available = sum(wires(i) * (self.stock.get(i, 0.0) + sum(b.remaining for b in self.open[i]))
                            for i in group)
            if available + EPS >= need + floor:
                break
            short = available + EPS < need
            b = self._create(item, template, (need + floor - available) / n)
            b.reason = "shortage" if short else "min_level"
        got: List[Tuple[Optional[Batch], float, datetime]] = []
        for used, metres, equiv in pool_take(self.stock, item, qty, group):
            got.append((None, equiv, self.t0))
            need -= equiv * n
            self.note(item, used, metres)
        others = sorted((i for i in group if i != item and self.open[i]), key=lambda i: self.open[i][0].batch_id)
        for i in [item] + others:
            q = self.open[i]
            while need > EPS and q:
                b = q[0]
                take = min(b.remaining, need / wires(i))
                b.remaining -= take
                need -= take * wires(i)
                b.draws[consumer] = b.draws.get(consumer, 0.0) + take
                got.append((b, take * wires(i) / n, b.available_at(b.allocated)))
                self.note(item, i, take)
                if b.remaining <= EPS:
                    b.remaining = 0.0
                    q.pop(0)
        return got

    def note(self, wanted: str, used: str, metres: float) -> None:
        if used != wanted and metres > EPS:
            self.substituted[(wanted, used)] += metres
            self.issues.add(wanted, "spool_substituted", "bunching drew %s in place of %s, strand count kept "
                            "(CONTRACT-POOL)" % (used, wanted))

    def _create(self, item: str, p: Pass, need: float) -> Batch:
        size = self.sizes.get(item)
        rule = self.rules.get(item, "history")
        if not size:
            size, rule = need, "lot_for_lot"
            self.issues.add(item, "no_batch_size", "no usual batch size in 12 months of orders: made lot-for-lot")
        b = Batch(len(self.batches) + 1, item, p.pool, size, rule, size)
        self.batches.append(b)
        ready = self.t0
        for c in p.children:
            if (c.pool not in config.BATCH_POOLS or (self.batch_items is not None and not self.batch_items(c.item))
                    or p.orig_quantity <= EPS):
                continue
            got = self.draw(c.item, size * c.orig_quantity / p.orig_quantity, c, ("batch", b.batch_id))
            from_stock = sum(m for f, m, _t in got if f is None)
            if from_stock > EPS:
                b.stock_feed[c.item] = b.stock_feed.get(c.item, 0.0) + from_stock
            made = [g for g in got if g[0] is not None]
            if made:
                b.feeders.setdefault(c.item, []).extend(made)
            ready = max([ready] + [t for _f, _m, t in got])
        (b.machine, b.operation, b.base_rate, b.setup_h, b.run_h, b.start, b.end) = self.place(p, size, ready)
        b.cal = self.cals.get(b.machine)
        self.open[item].append(b)
        return b

    def ops_served(self) -> Dict[int, set]:
        """Batch id -> op ids it serves, directly or through the batches it feeds."""
        memo: Dict[int, set] = {}
        byid = {b.batch_id: b for b in self.batches}

        def walk(b: Batch) -> set:
            if b.batch_id in memo:
                return memo[b.batch_id]
            memo[b.batch_id] = s = set()
            for kind, cid in b.draws:
                if kind == "op":
                    s.add(cid)
                else:
                    s |= walk(byid[cid])
            return s

        for b in self.batches:
            walk(b)
        return memo


def latest(got) -> Tuple[Optional[Batch], datetime]:
    """The batch whose share arrives last, and when it arrives. (None, t) when it all came from stock."""
    made = [g for g in got if g[0] is not None]
    if not made:
        return None, max(t for _b, _m, t in got)
    b, _m, t = max(made, key=lambda g: (g[2], g[0].batch_id))
    return b, t
