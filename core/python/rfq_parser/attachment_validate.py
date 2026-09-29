# Generated from the RFQ parser's matcher/attachment_validate.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
"""Deterministic check of a model's claims about a workbook it read.

[AGENT_FILE_READING_ORDER.md](../docs/AGENT_FILE_READING_ORDER.md) §5. The reader
(whatever provider hosts it) returns line items that each name the cells their quantity
came from. This module re-opens the workbook and checks that claim arithmetically.

**This is not a parser and must never become one.** It knows nothing about cables,
headers, units or table shape; it knows how to look up a cell and add up numbers. The
order is explicit that a validator which *corrects* the model is a second parser and
will drift -- so a failing item is dropped and recorded, never repaired. If you find
yourself reaching for `select_quantity`-style domain rules in here, stop: the thing you
want belongs upstream in the prompt, or nowhere.

It also carries two **flags** (§4 of
[READER_STABILISATION_ORDER.md](../docs/READER_STABILISATION_ORDER.md)), which are not the
same thing as a rule. A rule is a claim about the workbook, and a claim that fails is
dropped. A flag marks an item whose claim *checks out* and still cannot be trusted as a
count: a derivation that multiplies by a number no cell holds, and two items on one row
each claiming the same cell's whole value. Neither is repairable by re-reading the file, and
neither is a reason to throw away a real requirement, so they mark rather than drop -- and
an item carrying either must never be auto-pushed.

Provider-independent by construction. It validates claims about a file, so it does not
care which model made them, and it survives a change of reader.
"""

from __future__ import annotations

import ast
import re
from collections import defaultdict
from dataclasses import dataclass, field
from typing import Any

from openpyxl.utils.cell import coordinate_to_tuple
from openpyxl.utils.exceptions import CellCoordinatesException

# "Sheet1!C14", and sheet names that contain spaces, digits and punctuation:
# "90% Design!F13" is a real sheet name from the corpus.
_CELL_REF = re.compile(r"^(?P<sheet>.+)!(?P<col>[A-Za-z]{1,3})(?P<row>\d+)$")

# Arithmetic only. No calls, no attributes, no subscripts, no comprehensions, no names
# other than the cell references the item itself declared.
_ALLOWED_NODES = (
    ast.Expression,
    ast.BinOp,
    ast.UnaryOp,
    ast.Add,
    ast.Sub,
    ast.Mult,
    ast.Div,
    ast.USub,
    ast.UAdd,
    ast.Constant,
    ast.Name,
    ast.Load,
)

# Floating point: 8400.0000001 == 8400 for our purposes, and a sheet that stores
# 2197513.0000000005 must not be called a mismatch. Relative, because quantities in the
# corpus span 4 m to 2.2M m.
_TOLERANCE = 1e-6

# Rules 1-5 DROP an item. These two FLAG one: the arithmetic checks out, and what is wrong
# with it is something no amount of re-reading the workbook can settle. Both are
# machine-readable keys on the accepted item rather than prose in `note`, because §4 of
# READER_STABILISATION_ORDER turns on a consumer being able to act on them -- an item
# carrying either is never eligible for auto-push, whatever its confidence.
INFERRED_MULTIPLIER_KEY = "inferred_multiplier"
DOUBLE_COUNT_KEY = "double_counted"


@dataclass
class ValidationOutcome:
    accepted: list[dict[str, Any]] = field(default_factory=list)
    degraded: list[dict[str, Any]] = field(default_factory=list)

    @property
    def total(self) -> int:
        return len(self.accepted) + len(self.degraded)

    @property
    def failure_share(self) -> float:
        """Share of items that failed. The caller gates auto-push on this (§5)."""
        return (len(self.degraded) / self.total) if self.total else 0.0


def _drop(item: Any, rule: str, detail: str) -> dict[str, Any]:
    return {"item": item, "rule": rule, "detail": detail}


def _parse_ref(ref: Any) -> tuple[str, str] | None:
    """('Sheet1!C14') -> ('Sheet1', 'C14'), or None if it is not a cell reference."""
    match = _CELL_REF.match(str(ref or "").strip())
    if not match:
        return None
    return match.group("sheet"), f"{match.group('col').upper()}{match.group('row')}"


def _cell_value(workbook: Any, sheet: str, address: str) -> tuple[bool, Any]:
    """(exists, value). `exists` is about the cell being addressable, not about it
    holding anything -- a blank cell exists and its value is None. §5.5 turns on that
    distinction: blank is not zero, and a blank that becomes 0 is a silent quantity."""
    if sheet not in workbook.sheetnames:
        return False, None
    worksheet = workbook[sheet]
    # Bounds are checked from the address, BEFORE the cell is touched. `worksheet["C9999"]`
    # does not raise -- openpyxl creates the cell and the sheet's max_row grows to match,
    # so a check made afterwards would find the hallucinated cell comfortably in range and
    # report it as merely blank. Reading the coordinate first is what makes rule 2 real.
    try:
        row, column = coordinate_to_tuple(address)
    except (ValueError, TypeError, CellCoordinatesException):
        return False, None
    if row > worksheet.max_row or column > worksheet.max_column:
        return False, None
    return True, worksheet.cell(row=row, column=column).value


def _as_number(value: Any) -> float | None:
    if isinstance(value, bool):  # bool is an int in Python; a TRUE cell is not a quantity
        return None
    if isinstance(value, (int, float)):
        return float(value)
    return None


def _evaluate(expression: str, values: dict[str, float]) -> float | None:
    """Recompute a derivation over the item's own declared cells.

    Returns None if the expression is not pure arithmetic, names a cell the item did not
    declare, or cannot be evaluated. None always means "reject the item" -- there is no
    path here that guesses a value.
    """
    try:
        tree = ast.parse(str(expression), mode="eval")
    except SyntaxError:
        return None

    for node in ast.walk(tree):
        if not isinstance(node, _ALLOWED_NODES):
            return None
        if isinstance(node, ast.Constant) and not isinstance(node.value, (int, float)):
            return None
        if isinstance(node, ast.Constant) and isinstance(node.value, bool):
            return None
        if isinstance(node, ast.Name) and node.id.upper() not in values:
            # §5.4: "over the named cells only". A reference the item did not declare is
            # not evidence, even if the workbook happens to contain it.
            return None

    def walk(node: ast.AST) -> float | None:
        if isinstance(node, ast.Expression):
            return walk(node.body)
        if isinstance(node, ast.Constant):
            return float(node.value)
        if isinstance(node, ast.Name):
            return values[node.id.upper()]
        if isinstance(node, ast.UnaryOp):
            inner = walk(node.operand)
            if inner is None:
                return None
            return -inner if isinstance(node.op, ast.USub) else inner
        if isinstance(node, ast.BinOp):
            left, right = walk(node.left), walk(node.right)
            if left is None or right is None:
                return None
            if isinstance(node.op, ast.Add):
                return left + right
            if isinstance(node.op, ast.Sub):
                return left - right
            if isinstance(node.op, ast.Mult):
                return left * right
            if isinstance(node.op, ast.Div):
                if right == 0:
                    return None
                return left / right
        return None

    try:
        return walk(tree)
    except (TypeError, ValueError, OverflowError, ZeroDivisionError):
        return None


def _close(a: float, b: float) -> bool:
    return abs(a - b) <= _TOLERANCE * max(1.0, abs(a), abs(b))


def _referenced_names(expression: str) -> set[str]:
    """Cell addresses the expression actually uses, upper-cased.

    Empty when the expression will not parse, which is safe: rule 4 then falls through to
    `_evaluate`, whose job is to reject it.
    """
    try:
        tree = ast.parse(str(expression), mode="eval")
    except SyntaxError:
        return set()
    return {node.id.upper() for node in ast.walk(tree) if isinstance(node, ast.Name)}


def _constant_factors(expression: str) -> list[float]:
    """The numeric literals in a derivation -- the numbers no cited cell states.

    `F5 * 16` returns `[16.0]`. The cell reference is real and the arithmetic is right, and
    the 16 came from the words `4x4x1c` in a text cell. Re-opening the workbook proves the
    route length was read correctly and proves nothing whatever about the count, so this is
    the one quantity claim in the contract that cannot be checked -- up to 16x, in the
    direction that inflates an order.
    """
    try:
        tree = ast.parse(str(expression), mode="eval")
    except SyntaxError:
        return []
    return [
        float(node.value)
        for node in ast.walk(tree)
        if isinstance(node, ast.Constant)
        and isinstance(node.value, (int, float))
        and not isinstance(node.value, bool)
    ]


def _with_inferred_multiplier(item: dict[str, Any], derivation: str, provenance: list[str]) -> dict[str, Any]:
    """Mark an item whose derivation multiplies by a number no cell holds.

    `provenance` is the cells the item cited that hold no value -- typically the very text
    cell the count was read out of (`Sheet1!G5` = `4x1c+e XLPE X-90`). Worth keeping: it is
    where a reviewer looks to confirm the count.
    """
    factors = _constant_factors(derivation)
    if not factors:
        return item
    factor_text = ", ".join(f"{f:g}" for f in factors)
    flagged = dict(item)
    flagged[INFERRED_MULTIPLIER_KEY] = {
        "factors": factors,
        "derivation": derivation,
        "provenance_cells": [str(cell) for cell in provenance],
        "detail": (
            f"derivation {derivation!r} uses {factor_text}, which no cited cell states"
            + (f" (read from {', '.join(str(c) for c in provenance)})" if provenance else "")
        ),
    }
    return flagged


def _flag_double_counted(accepted: list[dict[str, Any]]) -> None:
    """Two items on one row, each claiming the same cell's whole value as its quantity.

    That is the shape pass 2 produced on `23-Allison`: a main and an earth both reported as
    the plain contents of the row's one length cell, which counts the same metres twice.
    Each item passes rules 1-5 on its own -- the cell exists and holds exactly what the item
    claims -- so this is only visible across items, and it is checked here because nothing
    downstream can see it either.

    **Flagged, not dropped**, unlike a rules 1-5 failure. Two items legitimately share one
    length cell when a row asks for two cable types over one route, and dropping a real
    requirement to prevent an over-quote just trades a visible error for a silent one -- the
    13% under-quote of READER_MEASUREMENT_RESULTS §3.2 is what that looks like. A human
    confirms the count; the module does not guess it.

    Not flagged: a main and an earth citing the same cell with DIFFERENT derivations
    (`F5 * 16` and `F5 * 3`). Those are two different quantities read off one route, which
    is correct and common.
    """
    claims: dict[tuple[Any, Any, str], list[int]] = defaultdict(list)
    for index, item in enumerate(accepted):
        source = item.get("quantity_source") or {}
        cells = source.get("cells") or []
        if item.get("quantity") is None or len(cells) != 1:
            continue
        if str(source.get("derivation") or "").strip():
            continue
        claims[(item.get("sheet"), item.get("row"), str(cells[0]))].append(index)

    for (sheet, row, cell), indexes in claims.items():
        if len(indexes) < 2:
            continue
        for index in indexes:
            flagged = dict(accepted[index])
            flagged[DOUBLE_COUNT_KEY] = {
                "cell": cell,
                "items_on_row": len(indexes),
                "detail": (
                    f"{len(indexes)} items on {sheet}!{row} each claim {cell} as their whole "
                    "quantity, so the same metres may be counted more than once"
                ),
            }
            accepted[index] = flagged


def validate_items(items: list[dict[str, Any]], workbook: Any) -> ValidationOutcome:
    """Check every item against the workbook it claims to have come from.

    `workbook` is an already-open ``openpyxl`` workbook loaded with ``data_only=True``
    -- values, because rule 3 compares against what the sheet shows, not the formula
    text behind it. Opening it is the caller's job so that one message's attachment is
    read once rather than once per row.

    Returns accepted and degraded items. §5: "Any item failing 1-5 is dropped and
    recorded in `degraded`."
    """
    outcome = ValidationOutcome()

    for item in items:
        if not isinstance(item, dict):
            outcome.degraded.append(_drop(item, "shape", "item is not an object"))
            continue

        sheet = str(item.get("sheet") or "").strip()
        row = item.get("row")

        # Rule 1 -- the sheet exists and the row is within its bounds.
        if sheet not in workbook.sheetnames:
            outcome.degraded.append(_drop(item, "1_sheet", f"no sheet named {sheet!r}"))
            continue
        worksheet = workbook[sheet]
        if not isinstance(row, int) or isinstance(row, bool) or row < 1 or row > worksheet.max_row:
            outcome.degraded.append(
                _drop(item, "1_row", f"row {row!r} outside 1..{worksheet.max_row} on {sheet!r}")
            )
            continue

        quantity = item.get("quantity")

        # Rule 5 -- a number, not negative, and blank preserved as null.
        #
        # A null quantity is a legitimate reading: §4 says rows the model cannot read are
        # omitted, but a row that genuinely states no quantity keeps `quantity: null`
        # rather than inventing one. It carries no cell claim to check, so rules 2-4 do
        # not apply to it and it is accepted as-is for a human to complete.
        if quantity is None:
            outcome.accepted.append(item)
            continue
        number = _as_number(quantity)
        if number is None:
            outcome.degraded.append(_drop(item, "5_type", f"quantity {quantity!r} is not a number"))
            continue
        if number < 0:
            outcome.degraded.append(_drop(item, "5_negative", f"quantity {number} is negative"))
            continue

        source = item.get("quantity_source")
        if not isinstance(source, dict):
            outcome.degraded.append(_drop(item, "2_source", "quantity_source is missing"))
            continue
        raw_cells = source.get("cells")
        if not isinstance(raw_cells, list) or not raw_cells:
            outcome.degraded.append(_drop(item, "2_source", "quantity_source.cells is empty"))
            continue

        # Rule 2 -- every named cell exists.
        values: dict[str, float] = {}
        blanks: list[str] = []
        # A cited cell holding TEXT, as distinct from one holding nothing. Both are
        # unusable as arithmetic, and only the first is evidence: `Sheet1!G5` reading
        # `4x1c+e XLPE X-90` is where a count was read from, and telling a reviewer to go
        # look at an empty cell would waste the one instruction the flag gets to give.
        text_cells: list[str] = []
        failed = None
        for ref in raw_cells:
            parsed = _parse_ref(ref)
            if parsed is None:
                failed = _drop(item, "2_cell", f"{ref!r} is not a Sheet!A1 reference")
                break
            cell_sheet, address = parsed
            exists, value = _cell_value(workbook, cell_sheet, address)
            if not exists:
                failed = _drop(item, "2_cell", f"cell {ref} does not exist")
                break
            number_value = _as_number(value)
            if number_value is None:
                blanks.append(str(ref))
                if isinstance(value, str) and value.strip():
                    text_cells.append(str(ref))
            else:
                values[address] = number_value
        if failed is not None:
            outcome.degraded.append(failed)
            continue

        derivation = source.get("derivation")
        has_derivation = bool(str(derivation or "").strip())

        if not has_derivation:
            # Rule 3 -- single cell, no derivation: the cell's value IS the quantity.
            if len(raw_cells) != 1:
                outcome.degraded.append(
                    _drop(item, "3_multi", f"{len(raw_cells)} cells named but no derivation given")
                )
                continue
            if blanks:
                outcome.degraded.append(
                    _drop(item, "3_blank", f"cell {blanks[0]} is blank but quantity is {number}")
                )
                continue
            actual = next(iter(values.values()))
            if not _close(actual, number):
                outcome.degraded.append(
                    _drop(item, "3_mismatch", f"cell holds {actual}, item claims {number}")
                )
                continue
            outcome.accepted.append(item)
            continue

        # Rule 4 -- the derivation is arithmetic over the named cells, and it reproduces
        # the quantity. A blank inside a derivation is a reject: `F13 * 4` where F13 is
        # empty cannot produce a number, and treating the blank as 0 would silently
        # yield a quantity of zero -- the exact failure this whole order exists for.
        # A cited cell the derivation does not USE is provenance, not arithmetic. This
        # distinction cost `23-Allison` every one of its 66 items on one run in three: the
        # model cited `Sheet1!G5` -- the `Type` text `4x1c+e XLPE X-90` the x16 was read
        # out of -- alongside `F5`, wrote `F5 * 16`, and was rejected for showing its
        # working. The arithmetic is checked against `F5` either way; the unsourced 16 is
        # what needs marking, and that is the inferred-multiplier flag below, not a drop.
        referenced = _referenced_names(str(derivation))
        used_blanks = [
            ref for ref in blanks
            if (_parse_ref(ref) or ("", ""))[1] in referenced
        ]
        if used_blanks:
            outcome.degraded.append(
                _drop(item, "4_blank", f"derivation references blank cell {used_blanks[0]}")
            )
            continue
        recomputed = _evaluate(str(derivation), values)
        if recomputed is None:
            outcome.degraded.append(
                _drop(item, "4_expression", f"derivation {derivation!r} is not arithmetic over the named cells")
            )
            continue
        if not _close(recomputed, number):
            outcome.degraded.append(
                _drop(item, "4_mismatch", f"derivation yields {recomputed}, item claims {number}")
            )
            continue
        outcome.accepted.append(_with_inferred_multiplier(item, str(derivation), text_cells))

    # Across items, once every item has been checked on its own.
    _flag_double_counted(outcome.accepted)
    return outcome


# --- the text twin: body copy and PDFs, which have no cells -------------------------

# A quantity as it appears in prose or a rendered table: `1,080`, `8400`, `2.5`.
_NUMBER_IN_TEXT = re.compile(r"\d[\d,]*(?:\.\d+)?")


def _numbers_in(text: str) -> list[float]:
    out: list[float] = []
    for match in _NUMBER_IN_TEXT.finditer(text or ""):
        try:
            out.append(float(match.group(0).replace(",", "")))
        except ValueError:
            continue
    return out


def _flatten(text: str) -> str:
    """Whitespace-insensitive, case-insensitive form for span matching.

    A model quoting a table row will not reproduce the original run of spaces, tabs and
    newlines, and rejecting it for that would fail every real quote. Everything else about
    the span still has to match exactly -- this normalises layout, not content.
    """
    return " ".join(str(text or "").split()).casefold()


def validate_customer_claim(claim: Any, source_text: str) -> tuple[str, str]:
    """Check a proposed customer name against the text it was read from.

    Returns ``(name, "")`` when the claim holds, or ``("", reason)`` when it does not.

    Same evidence rule as a quantity, for the same reason: a name the model produces
    without a span behind it is a name it may have produced from nowhere, and a quote
    addressed to the wrong company is worse than a quote with no company on it. What this
    cannot check -- exactly as with a quantity -- is that the span is the *right* one. The
    model may quote a real company that is mentioned in the email for some other reason.
    That residual risk is why a name accepted here still cannot auto-push.
    """
    if claim is None:
        return "", ""
    if not isinstance(claim, dict):
        return "", "customer claim is not an object"
    name = " ".join(str(claim.get("name") or "").split())
    if not name:
        return "", ""
    quote = str(claim.get("quote") or "")
    if not quote.strip():
        return "", "customer name carries no quoted span"
    haystack = _flatten(source_text)
    if _flatten(quote) not in haystack:
        return "", f"quoted span for the customer is not in the source: {quote[:80]!r}"
    if _flatten(name) not in _flatten(quote):
        # The name has to appear inside the span it is claimed to come from, or the span
        # is evidence for something else.
        return "", f"customer name {name!r} is not inside its own quoted span"
    return name, ""


def validate_text_items(items: list[dict[str, Any]], source_text: str) -> ValidationOutcome:
    """Check every item against the text it claims to have come from.

    The spreadsheet path can re-open the workbook and read the cell, which is what lets a
    model read a schedule without ever transcribing a number (§1.3). Body copy and PDFs
    have no cells, so without an equivalent the model would be transcribing quantities by
    eye straight into a quote that auto-pushes to 4js -- the one thing CLAUDE.md's first
    rule forbids.

    The equivalent is a quoted span. The model must return the verbatim text each quantity
    came from; this re-finds that span in the real source and re-derives the number from
    the digits inside it. A span that is not in the source, or that does not contain the
    arithmetic, is not evidence -- and an item whose evidence does not hold up is dropped,
    exactly as rules 1-5 drop a bad cell claim.

    What this deliberately does NOT do is check that the span is the *right* span. A model
    can quote a real line and read the wrong number off it. That is the same residual risk
    the cell path carries (a real cell, wrongly chosen) and it is why neither path is
    allowed to set a product code or a price.
    """
    outcome = ValidationOutcome()
    haystack = _flatten(source_text)

    for item in items:
        if not isinstance(item, dict):
            outcome.degraded.append(_drop(item, "shape", "item is not an object"))
            continue

        quantity = item.get("quantity")

        # Rule 5, as in the workbook path: a blank quantity is a legitimate reading and
        # carries no claim to check. It is accepted for a human to complete.
        if quantity is None:
            outcome.accepted.append(item)
            continue
        number = _as_number(quantity)
        if number is None:
            outcome.degraded.append(_drop(item, "5_type", f"quantity {quantity!r} is not a number"))
            continue
        if number < 0:
            outcome.degraded.append(_drop(item, "5_negative", f"quantity {number} is negative"))
            continue

        source = item.get("quantity_source")
        if not isinstance(source, dict):
            outcome.degraded.append(_drop(item, "2_source", "quantity_source is missing"))
            continue
        quote = str(source.get("quote") or "").strip()
        if not quote:
            outcome.degraded.append(_drop(item, "2_source", "quantity_source.quote is empty"))
            continue

        # Rule T1 -- the span is really in the source. This is the whole guarantee: a
        # quantity whose evidence cannot be found in the document did not come from it.
        flat_quote = _flatten(quote)
        if flat_quote not in haystack:
            outcome.degraded.append(
                _drop(item, "1_quote", f"quoted span is not in the source text: {quote[:80]!r}")
            )
            continue

        available = _numbers_in(quote)
        derivation = str(source.get("derivation") or "").strip()

        # Rule T3 -- the number is re-derivable from the digits inside the span.
        if derivation:
            computed = _evaluate(derivation, {})
            if computed is None:
                outcome.degraded.append(
                    _drop(item, "3_derivation", f"derivation {derivation!r} is not plain arithmetic")
                )
                continue
            if not _close(computed, number):
                outcome.degraded.append(
                    _drop(item, "3_mismatch", f"derivation {derivation!r} gives {computed}, item says {number}")
                )
                continue
            # A literal in the derivation that the span does not state is a count the model
            # inferred from prose -- the text equivalent of `F5 * 16`, and the same
            # unquantifiable inflation risk. Flag, never drop; a human confirms the count.
            unstated = [f for f in _constant_factors(derivation)
                        if not any(_close(f, n) for n in available)]
            if unstated:
                item = _with_inferred_multiplier_text(item, derivation, quote, unstated)
        else:
            if not any(_close(number, n) for n in available):
                outcome.degraded.append(
                    _drop(item, "3_absent",
                          f"quantity {number:g} does not appear in the quoted span {quote[:60]!r}")
                )
                continue

        outcome.accepted.append(item)

    _flag_double_counted_text(outcome.accepted)
    return outcome


def _with_inferred_multiplier_text(
    item: dict[str, Any], derivation: str, quote: str, unstated: list[float]
) -> dict[str, Any]:
    """Flag a text derivation that multiplies by a number the span does not state.

    Deliberately NOT `_with_inferred_multiplier`. That one reports every literal in the
    derivation, which is correct for `F5 * 16` -- a cell reference plus a count, where the
    only literal IS the inferred one. A text derivation is literals all the way down
    (`1080 * 3`), so reporting all of them would tell a reviewer to go and confirm the
    1,080 that the quoted line states in full. The reviewer gets one instruction; it has to
    point at the number that actually needs confirming.

    Same key and same shape as the workbook flag, so `_flag_reason` and the editor's
    warning routing treat both identically -- an item carrying it never auto-pushes.
    """
    factor_text = ", ".join(f"{f:g}" for f in unstated)
    flagged = dict(item)
    flagged[INFERRED_MULTIPLIER_KEY] = {
        "factors": unstated,
        "derivation": derivation,
        "provenance_cells": [quote],
        "detail": (
            f"derivation {derivation!r} uses {factor_text}, which the quoted text does not "
            f"state (read from {quote[:60]!r})"
        ),
    }
    return flagged


def _flag_double_counted_text(accepted: list[dict[str, Any]]) -> None:
    """Two items claiming the same span's whole value -- the text form of §6.

    Same judgement as the workbook version: flagged, never dropped. Two cable types over
    one stated route length is a real requirement, and dropping one to avoid an over-quote
    trades a visible error for a silent one. Items with different derivations off the same
    span are two readings of one route and are not flagged.
    """
    claims: dict[str, list[int]] = defaultdict(list)
    for index, item in enumerate(accepted):
        source = item.get("quantity_source") or {}
        quote = str(source.get("quote") or "").strip()
        if item.get("quantity") is None or not quote:
            continue
        if str(source.get("derivation") or "").strip():
            continue
        claims[_flatten(quote)].append(index)

    for quote, indexes in claims.items():
        if len(indexes) < 2:
            continue
        for index in indexes:
            flagged = dict(accepted[index])
            flagged[DOUBLE_COUNT_KEY] = {
                "cell": quote[:80],
                "items_on_row": len(indexes),
                "detail": (
                    f"{len(indexes)} items each claim the same quoted text as their whole "
                    "quantity, so the same metres may be counted more than once"
                ),
            }
            accepted[index] = flagged
