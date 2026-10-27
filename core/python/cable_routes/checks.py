"""The seven check kinds (contract 4.6). Each row: (kind, item, code, machine, operation, detail, affected, total)."""

from __future__ import annotations

import collections

ROUTE_OPS = ("LAYUP", "BEDDING", "BRAIDING", "ARMOURING", "TAPING", "SHEATHING")
OPTIONAL_OPS = ("BEDDING", "BRAIDING", "ARMOURING")
REASON_TEXT = {
    "no_rod_in_chain": "stop before copper rod or purchased conductor",
    "no_orders": "have no production orders",
    "cutting_only": "have only cutting orders",
}


def family_route_mismatch(traced, finished_ops, families):
    """traced: [(item, code)]; finished_ops: item -> set of ROUTE_OPS in the tree;
    families: [(family, codes list, expected_ops text)]."""
    by_code = collections.defaultdict(list)
    for fam, codes, expected in families:
        for c in codes:
            by_code[c].append((fam, (expected or "").strip()))
    items_of = collections.defaultdict(list)
    for item, code in traced:
        items_of[code].append(item)
    rows = []
    for code in sorted(items_of):
        fams = by_code.get(code, [])
        if len(fams) != 1 or any(not exp for _, exp in fams):
            continue
        expected = {("SHEATHING" if o == "NYLON" else o) for o in fams[0][1].split()}
        items = items_of[code]
        total = len(items)
        for op in ROUTE_OPS:
            have = sum(1 for i in items if op in finished_ops[i])
            if op in expected and have * 2 < total:
                said = "none in Dynamics" if have == 0 else f"in only {round(100 * have / total)}% of {code} items"
                rows.append(("family_route_mismatch", None, code, None, op, f"{op} expected for {code}; {said}",
                             total - have, total))
            elif op in OPTIONAL_OPS and op not in expected and have * 2 >= total:
                rows.append(("family_route_mismatch", None, code, None, op,
                             f"{op} in {round(100 * have / total)}% of {code} items; the poster family does not list it",
                             have, total))
    return rows


def machine_rows(machine_status, made_count):
    rows = []
    for m in sorted(machine_status):
        st = machine_status[m]
        if st == "not_on_poster":
            rows.append(("machine_not_on_poster", None, None, m, None,
                         f"{m} is used in Dynamics routes ({made_count.get(m, 0)} made items) but is not on the poster",
                         made_count.get(m, 0), None))
        elif st == "no_routings":
            rows.append(("poster_machine_unused", None, None, m, None,
                         f"{m} is on the poster but no finished-cable route in Dynamics uses it", None, None))
    return rows


def retired_rows(items, retired):
    rows = []
    for it in items.values():
        seen = set()
        for _seq, _no, op, machine in it.ops:
            if machine in retired and (machine, op) not in seen:
                seen.add((machine, op))
                rows.append(("routed_to_retired_machine", it.item, None, machine, op,
                             f"Order {it.order_no} ({it.order_status}, {it.order_date}) routes {op} to {machine}, "
                             f"a machine that is not in use", None, None))
    return rows


def _mm(v):
    return f"{v:g}"


def limit_rows(items, limits):
    """limits: machine -> (min_mm or None, max_mm or None)."""
    rows = []
    for it in items.values():
        made_dia = [items[i.input_item].diameter_mm for i in it.inputs
                    if i.kind == "made" and items[i.input_item].diameter_mm]
        if not made_dia:
            continue
        dia = max(made_dia)
        done = set()
        for _seq, _no, op, machine in it.ops:
            if machine in done or machine not in limits:
                continue
            done.add(machine)
            lo, hi = limits[machine]
            if (lo is not None and dia < lo) or (hi is not None and dia > hi):
                span = f"{_mm(lo)} to {_mm(hi)} mm" if lo is not None and hi is not None else (
                    f"up to {_mm(hi)} mm" if hi is not None else f"{_mm(lo)} mm or more")
                rows.append(("outside_poster_limit", it.item, None, machine, op,
                             f"input is {dia:.1f} mm; poster limit, 2023: {span}", None, None))
    return rows


def status_rows(finished_rows):
    """finished_rows: [(item, code, trace_status, reason)] for all finished items."""
    total = collections.Counter(code for _, code, _, _ in finished_rows)
    groups = collections.Counter((code, st, reason) for _, code, st, reason in finished_rows
                                 if st in ("stops_early", "untraceable"))
    rows = []
    for (code, st, reason), n in sorted(groups.items(), key=lambda kv: (kv[0][1] != "stops_early", kv[0][0], kv[0][2])):
        rows.append((st, None, code, None, None,
                     f"{n} of {total[code]} {code} items {REASON_TEXT.get(reason, reason)}", n, total[code]))
    return rows
