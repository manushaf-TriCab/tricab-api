"""Issues keyed by (item, kind): the first detail wins, count is the number of occurrences (contract 4.8)."""

from __future__ import annotations

SEVERITY = {
    "r86_short_period": "warning", "r86_period_mismatch": "warning", "r86_older_ignored": "info",
    "r86_unplaced": "info", "inventory_date_unknown": "warning", "inventory_unconverted": "warning",
    "kanban_assumed": "info", "mica_not_scheduled": "info", "no_build_tree": "warning", "job_from_stock": "info",
    "covered_by_stock": "info", "no_machine": "warning", "no_rate": "warning", "pool_fallback": "info",
    "rate_borrowed": "info", "qty_converted": "info", "qty_unit": "warning", "no_weight": "info",
    "calendar_pattern": "info", "shift_override": "info", "efficiency_applied": "info", "yield_applied": "info",
    "spool_substituted": "info", "no_batch_size": "info", "no_feeder_batch": "warning", "rod_cover_low": "warning",
    "shutdown_in_horizon": "info",
}
ALL = "(all)"


class IssueLog:
    def __init__(self):
        self._rows: dict[tuple[str, str], list] = {}

    def add(self, item: str, kind: str, detail: str) -> None:
        if kind not in SEVERITY:
            raise ValueError(f"unknown issue kind {kind!r}")
        row = self._rows.get((item, kind))
        if row is None:
            self._rows[(item, kind)] = [detail, 1]
        else:
            row[1] += 1

    def rows(self) -> list[tuple]:
        """(item, kind, severity, detail, count), sorted."""
        return [(i, k, SEVERITY[k], d, n) for (i, k), (d, n) in sorted(self._rows.items())]

    def count(self, kind: str) -> int:
        return sum(1 for (_, k) in self._rows if k == kind)
