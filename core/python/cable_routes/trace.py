"""Trace status of a finished item (contract 4.5)."""

from __future__ import annotations

from .parse import conductor_prefix


def classify(tree_made, tree_materials, has_rod_break, depth):
    """Return (trace_status, reason, reason_items) for a finished item that has a real order.

    tree_made: made items in the tree; tree_materials: material items in the tree.
    has_rod_break: item -> bool. depth: item -> longest path (to list terminal items deepest first).
    """
    if any(has_rod_break(i) for i in tree_made):
        return "to_rod", None, None
    purchased = sorted(m for m in tree_materials if conductor_prefix(m))
    if purchased:
        return "to_purchased_conductor", "purchased_conductor", ",".join(purchased[:5])
    terminal = sorted(tree_materials, key=lambda m: (-depth.get(m, 0), m))
    return "stops_early", "no_rod_in_chain", ",".join(terminal[:5]) or None
