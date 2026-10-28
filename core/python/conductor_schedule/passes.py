"""Jobs to passes: expand each job's item over the Conductor process build tree (contract 4.5; pure, no files)."""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Dict, List, Optional, Tuple

from conductor_process.build import COLS as PROCESS_COLS

from . import config
from .history import History
from .issues import IssueLog

EPS = 1e-9


@dataclass
class Pass:
    item: str
    pool: str
    depth: int
    quantity: float                      # metres (see unit)
    unit: str
    eligible: List[Tuple[str, str]]      # (machine, operation), most-used first
    parent: Optional["Pass"] = None
    children: List["Pass"] = field(default_factory=list)
    # filled by the scheduler
    pass_id: int = 0
    machine: str = ""
    operation: str = ""
    base_rate: float = 0.0
    operation_factor: float = 1.0
    product_factor: float = 1.0
    setup_h: float = 0.0
    run_h: float = 0.0
    start = None
    end = None
    batch = None                         # wire passes: the Batch they draw from (batch.py)
    stock_m = 0.0                        # metres of this pass's item taken from Dynamics on-hand (CONTRACT-STOCK)

    def __post_init__(self):
        self.orig_quantity = self.quantity     # the tree's quantity; `quantity` becomes what is left to make


@dataclass
class Op:
    """One scheduled job; op_id is the job_id."""
    op_id: int
    item: str
    pool: str
    metres: float
    required_by: str
    passes: List[Pass]                   # root first, children after their parent (pre-order)
    rod_kg: Optional[float]
    out_kg: Optional[float]
    start_at = None
    end_at = None
    late_days: int = 0
    stock_m: float = 0.0
    status: str = "scheduled"            # scheduled | from_stock | no_rate (set by the scheduler)


@dataclass
class Tree:
    """The process build tree: made inputs per item, pools, units and the rod items."""
    pool: Dict[str, str] = field(default_factory=dict)
    inputs: Dict[str, List[Tuple[str, float, str, str]]] = field(default_factory=dict)   # item -> (input, qty, unit, kind)
    rods: set = field(default_factory=set)
    kg_per_m: Dict[str, float] = field(default_factory=dict)
    traces: Dict[str, int] = field(default_factory=dict)

    @classmethod
    def from_tables(cls, process_tables: dict, kg_per_m: Dict[str, float]) -> "Tree":
        """From conductor_process build_tables() output, rows indexed by COLS names."""
        def ix(table, name):
            return PROCESS_COLS[table].index(name)

        t = cls(kg_per_m=dict(kg_per_m))
        ci = {n: ix("cd_item", n) for n in ("item", "pool", "traces_to_rod")}
        for r in process_tables["cd_item"][1]:
            t.pool[r[ci["item"]]] = r[ci["pool"]]
            t.traces[r[ci["item"]]] = r[ci["traces_to_rod"]]
        ii = {n: ix("cd_input", n) for n in ("item", "input_item", "qty_per_unit", "unit", "kind")}
        for r in process_tables["cd_input"][1]:
            t.inputs.setdefault(r[ii["item"]], []).append((r[ii["input_item"]], r[ii["qty_per_unit"]], r[ii["unit"]],
                                                          r[ii["kind"]]))
        mi = {n: ix("cd_material", n) for n in ("item", "is_rod")}
        t.rods = {r[mi["item"]] for r in process_tables["cd_material"][1] if r[mi["is_rod"]]}
        return t


class NoMachine(Exception):
    def __init__(self, item):
        self.item = item


def build_ops(jobs, tree: Tree, history: History, issues: IssueLog, yields: Optional[Dict[str, float]] = None
              ) -> Tuple[List[Op], Dict[int, str]]:
    """jobs: [(job_id, item, metres, due_date)] -> (ops in job_id order, {job_id: 'no_machine'}).

    One Pass per made edge occurrence (a node on the current path is cut). A child's metres = parent metres x
    qty_per_unit / yield(step of the parent), so a yield grows every input below its step (D6). The rod kg of the job is
    the sum over rod edges of the same quantity."""
    y = dict.fromkeys(config.YIELD_STEPS, 1.0)
    y.update(yields or {})

    def growth(item: str) -> float:
        step = config.step_of(item, tree.pool.get(item, ""))
        return 1.0 / y[step] if step else 1.0

    ops: List[Op] = []
    failed: Dict[int, str] = {}
    for job_id, item, metres, due in sorted(jobs):
        rod = [0.0, False]

        def walk(node: str, qty: float, unit: str, parent: Optional[Pass], depth: int, path: tuple) -> Pass:
            pool = tree.pool[node]
            elig, src = history.eligible(node, pool)
            if not elig:
                issues.add(node, "no_machine", "no history for the item or its pool: job dropped")
                raise NoMachine(node)
            if src == "pool":
                issues.add(node, "pool_fallback", f"no job history for the item; machines taken from pool {pool}")
            p = Pass(node, pool, depth, qty, unit, elig, parent=parent)
            if parent is not None:
                parent.children.append(p)
            k = growth(node)
            for inp, qpu, u, kind in tree.inputs.get(node, ()):
                if kind == "material":
                    if inp in tree.rods:
                        rod[0] += qty * qpu * k
                        rod[1] = True
                    continue
                if inp in path or inp not in tree.pool or tree.pool[inp] not in config.STAGE_POOLS:
                    continue
                cq, cu = qpu, "m"
                if u != "m":
                    w = tree.kg_per_m.get(inp)
                    if u == "kg" and w:
                        cq = qpu / w
                        issues.add(inp, "qty_converted", f"input in kg converted to metres at {w:.5f} kg/m")
                    else:
                        cu = u
                        issues.add(inp, "qty_unit", f"input in '{u}' without kg/m used as is")
                walk(inp, qty * cq * k, cu, p, depth + 1, path + (inp,))
            return p

        try:
            root = walk(item, metres, "m", None, 0, (item,))
        except NoMachine:
            failed[job_id] = "no_machine"
            continue
        order: List[Pass] = []

        def pre(p: Pass):
            order.append(p)
            for c in p.children:
                pre(c)
        pre(root)
        w = tree.kg_per_m.get(item)
        ops.append(Op(job_id, item, root.pool, metres, due, order, rod[0] if rod[1] else None,
                      metres * w if w else None))
    return ops, failed
