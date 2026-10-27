"""Quantity explosion with a visited set (contract 4.4 rule 9).

The BOM graph has cycles (965+ backward edges on the real extract). A depth-first walk that keeps the
current path as its visited set cuts every edge whose target is already on the path; the reverse
post-order of that walk is a topological order of what remains.
"""

from __future__ import annotations

from typing import NamedTuple


class Tree(NamedTuple):
    order: list  # every reachable node, post-order (children before parents)
    need: dict  # node -> {unit: need for 1 unit of the root}
    depth: dict  # node -> longest path from the root
    cut: set  # (parent, child) pairs ignored as backward edges


def explode(root, graph, unit_of, root_unit) -> Tree:
    """graph: made item -> [(input_item, qty_per_unit, unit)]; anything absent is a leaf (a material).

    unit_of: made item -> its own output unit. need[parent] is read in that unit only, never converted.
    """
    order: list = []
    cut: set = set()
    path = {root}
    seen = {root}
    stack = [(root, iter(graph.get(root, ())))]
    while stack:
        node, it = stack[-1]
        for child, _q, _u in it:
            if child in path:
                cut.add((node, child))
                continue
            if child in seen:
                continue
            seen.add(child)
            path.add(child)
            stack.append((child, iter(graph.get(child, ()))))
            break
        else:
            stack.pop()
            path.discard(node)
            order.append(node)

    need: dict = {root: {root_unit: 1.0}}
    depth = {root: 0}
    for node in reversed(order):
        edges = graph.get(node)
        if not edges:
            continue
        n = need[node].get(unit_of.get(node, ""), 0.0)
        d = depth[node] + 1
        for child, q, u in edges:
            if (node, child) in cut:
                continue
            slot = need.setdefault(child, {})
            slot[u] = slot.get(u, 0.0) + n * q
            if depth.get(child, -1) < d:
                depth[child] = d
    return Tree(order, need, depth, cut)
