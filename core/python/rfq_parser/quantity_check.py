# Generated from the RFQ parser's matcher/quantity_check.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
"""Recompute what the workbook implies, and compare it with what the reader claimed.

Phase 0 §2 measured the hole this fills. `attachment_validate.validate_items` proves a
derivation reproduces the quantity it claims -- and proves nothing whatever about the
multiplier, because `I3 * 24` and `I3 * 20` validate identically against the same cell. The
count is the part no cell states and nothing checked.

So this module reads the cells itself, through the column map the reader proposed, applies
`schedule_arithmetic`, and **compares**. Three things it deliberately does not do:

- **It does not rewrite a quantity.** Writing a recomputed total back into `table_quantity`
  without `quantity_basis="total"` is the 2026-09-09 double-multiplication bug (`1600 ->
  6400`); on the estimating workbook it would be 24x. The reader's number stays the
  reader's, and a disagreement is a thing a person settles.
- **It does not multiply a stated total.** A row whose basis is `total` was already totalled
  by the sheet. Checking it means comparing against the total column, never re-applying a
  conductor count to it.
- **It does not turn unknown into 1.** A count the cells do not determine is reported as
  undeterminable, because a silent 1 is the shape an under-quote takes.

A disagreement is not an assertion that the reader is wrong. It is an assertion that two
independent readings of the same cells differ, which is precisely the thing that was
invisible before.
"""
from __future__ import annotations

from dataclasses import dataclass, asdict
from typing import Any

from . import schedule_arithmetic
from rfq_parser.schedule_source import ColumnMap, cell_value as _cell

# What a disagreement is about. `kind` is machine-readable because the consequence must not
# depend on a reviewer reading prose -- the same argument as the inferred-multiplier flag.
COUNT_MISMATCH = "count_mismatch"
TOTAL_MISMATCH = "total_mismatch"
UNSUPPORTED_MULTIPLIER = "unsupported_multiplier"
UNDETERMINABLE = "undeterminable_count"
NO_COLUMN_MAP = "no_column_map"


@dataclass
class Disagreement:
    kind: str
    sheet: str
    row: int | None
    component: str
    detail: str
    claimed_conductors: int | None = None
    expected_conductors: int | None = None
    claimed_quantity: float | None = None
    expected_quantity: float | None = None
    rule: str = ""
    evidence: str = ""

    def as_dict(self) -> dict[str, Any]:
        return {k: v for k, v in asdict(self).items() if v not in (None, "")}


def _number(value: Any) -> float | None:
    if value is None or isinstance(value, bool):
        return None
    try:
        return float(str(value).strip())
    except (TypeError, ValueError):
        return None


def _close(a: float, b: float, tolerance: float = 0.5) -> bool:
    return abs(a - b) <= tolerance


def _claimed_conductors(item: dict[str, Any]) -> int | None:
    model = item.get("quantity_model")
    if not isinstance(model, dict):
        return None
    per_set = model.get("conductors_per_set")
    if not isinstance(per_set, int) or isinstance(per_set, bool) or per_set < 1:
        return None
    sets = model.get("sets")
    if not isinstance(sets, int) or isinstance(sets, bool) or sets < 1:
        sets = 1
    return sets * per_set


def check_items(
    items: list[dict[str, Any]],
    workbook: Any,
    column_map: list[dict[str, Any]] | None,
) -> list[dict[str, Any]]:
    """Compare every item's arithmetic against the cells it came from.

    Returns disagreements as plain dicts, ready to ride on the result. An empty list means
    the two readings agreed everywhere they could both be computed -- NOT that everything
    was checked; `UNDETERMINABLE` and `NO_COLUMN_MAP` say where checking was not possible,
    and they are reported rather than passed over.
    """
    mapping = ColumnMap.from_reader(column_map)
    out: list[Disagreement] = []

    if not mapping:
        # Loud, not silent. Without a column map nothing below can run, and a check that
        # quietly passes because it never ran is worse than no check -- that is the dead
        # OPENROUTER_API_KEY shape, where /api/health stayed green for 30x the failures.
        out.append(Disagreement(
            kind=NO_COLUMN_MAP, sheet="", row=None, component="",
            detail="the reader proposed no usable column map, so no quantity could be rechecked",
        ))
        return [d.as_dict() for d in out]

    for item in items:
        if not isinstance(item, dict):
            continue
        sheet = str(item.get("sheet") or "").strip()
        row = item.get("row")
        component = str(item.get("component") or "").strip()
        quantity = _number(item.get("quantity"))
        if quantity is None:
            # A row that states no quantity is a legitimate reading and is preserved as
            # missing. There is nothing here to disagree with.
            continue

        model = item.get("quantity_model") if isinstance(item.get("quantity_model"), dict) else {}
        basis = str(model.get("basis") or "").strip()
        claimed = _claimed_conductors(item)

        # --- a stated total is compared, never multiplied --------------------------------
        if basis == "total":
            total_letter = mapping.column(sheet, "total_length")
            if not total_letter:
                # The reader says the sheet totalled this row, but named no total column.
                # Unchecked is not the same as agreed, and saying nothing here is the
                # failure this module exists to stop: a check that passes because it never
                # ran. One of the two readings is wrong and a person has to look.
                out.append(Disagreement(
                    kind=UNDETERMINABLE, sheet=sheet, row=row, component=component,
                    claimed_quantity=quantity,
                    detail="reader claims a stated total, but no total_length column was "
                           "mapped on this sheet, so the total could not be rechecked",
                ))
                continue
            stated = _number(_cell(workbook, sheet, total_letter, row))
            if stated is None:
                out.append(Disagreement(
                    kind=UNDETERMINABLE, sheet=sheet, row=row, component=component,
                    claimed_quantity=quantity,
                    detail=f"reader claims a stated total, but {total_letter}{row} holds no number",
                ))
                continue
            if not _close(stated, quantity):
                out.append(Disagreement(
                    kind=TOTAL_MISMATCH, sheet=sheet, row=row, component=component,
                    claimed_quantity=quantity, expected_quantity=stated,
                    detail=f"reader reported {quantity} against a stated total of {stated} "
                           f"in {total_letter}{row}",
                ))
            continue

        # --- a route basis: recompute the count from the cells ---------------------------
        expected = schedule_arithmetic.expected_conductors(
            component,
            active=_cell(workbook, sheet, mapping.column(sheet, "active_size"), row),
            neutral=_cell(workbook, sheet, mapping.column(sheet, "neutral_size"), row),
            earth=_cell(workbook, sheet, mapping.column(sheet, "earth_size"), row),
            cores=_cell(workbook, sheet, mapping.column(sheet, "cores"), row),
        )

        # Bullet 3: a multiplier needs a cell or a named rule behind it.
        if claimed is not None and claimed > 1 and not str(item.get("rule_id") or "").strip() \
                and str(item.get("quantity_evidence") or "") != "source":
            out.append(Disagreement(
                kind=UNSUPPORTED_MULTIPLIER, sheet=sheet, row=row, component=component,
                claimed_conductors=claimed, expected_conductors=expected.count,
                detail=f"the route was multiplied by {claimed} with neither a cell nor a "
                       f"named rule behind it",
            ))

        if expected.count is None:
            out.append(Disagreement(
                kind=UNDETERMINABLE, sheet=sheet, row=row, component=component,
                claimed_conductors=claimed, rule=expected.rule, evidence=expected.evidence,
                detail=f"the cells do not determine a conductor count: {expected.detail}",
            ))
            continue

        if claimed is not None and claimed != expected.count:
            route = _number(_cell(workbook, sheet, mapping.column(sheet, "route_length"), row))
            out.append(Disagreement(
                kind=COUNT_MISMATCH, sheet=sheet, row=row, component=component,
                claimed_conductors=claimed, expected_conductors=expected.count,
                claimed_quantity=quantity,
                expected_quantity=(route * expected.count) if route is not None else None,
                rule=expected.rule, evidence=expected.evidence,
                detail=f"reader multiplied by {claimed}; the cells give {expected.count} "
                       f"({expected.detail})",
            ))

    return [d.as_dict() for d in out]


def summary(disagreements: list[dict[str, Any]]) -> str:
    """One sentence for the draft. Empty when there is nothing to say."""
    if not disagreements:
        return ""
    counts: dict[str, int] = {}
    for entry in disagreements:
        counts[entry.get("kind", "?")] = counts.get(entry.get("kind", "?"), 0) + 1
    if list(counts) == [NO_COLUMN_MAP]:
        return "Quantities could not be rechecked: the reader proposed no column map."
    parts = ", ".join(f"{n} {kind.replace('_', ' ')}" for kind, n in sorted(counts.items()))
    return f"Quantity recheck found {parts} -- each needs a person to settle it."
