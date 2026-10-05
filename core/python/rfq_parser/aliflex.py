"""ALI-FLEX alternative: the agreed copper-to-aluminium mapping, as a proposal (parity plan E7.4).

Platform-owned. The mapping is the source's `matcher/aliflex.py` at `ca2f924` on
`release/aliflex-prod-20260927` (policy `aliflex-map/2`), brought across function for
function: `propose`, `alternative_rows`, `summarise`, `alternative_quote_details`,
`alternative_label` and `status_message`, with their rules and wording unchanged, plus
`convert`, the pure half of the source's `create_alternative_draft`. The other half,
which reads and writes the store, prices the changed lines and applies the customer's
margins, is PHP's (`RfqAliflexService`), as is the source's `eligibility`.

It is not a generated module because the pinned source (`6e70ee3`) does not have it:
the ALI-FLEX work lives on its own release branch, which the parser is not generated
from. Until it is, this is a hand port, and `tests/rfq_parser/test_aliflex.py` holds the
source's own cases.

**Not ported, and for the owner to decide:** the source's working tree holds uncommitted
changes on top of that commit (policy `aliflex-map/4`). They add a `kept_copper` status
for copper lines below the smallest aluminium size (25 mm2 for single cores and earths,
16 mm2 for multicores), with the smallest aluminium size offered in the row's
Alternatives, and let a tinned copper LP or LS earth map to the KL SDI earth. They are
neither committed nor released, so they are not the reference here.

One difference from the source: the platform's line identity (`line_uid`) is cleared with
the source's `id`, so the new draft assigns its own.

The rule, per copper line:

* the conductor becomes aluminium (`N` -> `A`);
* the size advances exactly one entry on the standard ladder;
* a 90C style moves to its 110C counterpart (XL->KL, XU->KU, XA->KA, XN->KN, SX->SK) and a
  110C style keeps its style (KL, KU, KA, KN, SK), which is a size-only change;
* the jacket becomes black (`BK`): ALI-FLEX aluminium cables are black whatever the
  copper source was (Manusha, 25 September 2026), except earths;
* a separate LP or LS earth becomes the KL SDI earth `KL-PAXA/1C{size}EA`, green/yellow;
* aluminium multicores use core colour configuration P9; single cores keep their configuration;
* everything else about the code -- custom jacket, screen/armour,
  core count and any trailing text -- is kept as printed.

The proposed code is not checked against the product catalogue. Lines the rule does not
cover are never guessed at: an already-aluminium line or a style outside the table is
`unchanged`; where the rule covers the style but the line does not fit it, the line is
`review` (tinned copper, AWG/MCM or pair sizing, a nonstandard size, 630 mm2 with no next
size, EA VFD/VSD, an earth that is not a green/yellow single core, a copper-only comment,
or a code that cannot be read). Quantities are unchanged: 36 x 180 m stays 6480 m. Changed
lines append ALIFLEX Alt to their comment.
"""

from __future__ import annotations

import copy
import re
from dataclasses import dataclass
from typing import Any

from tricab_cable_rules.cable_code import fmt_csa, parse_code

POLICY_VERSION = "aliflex-map/2"

STATUS_CHANGED = "changed"
STATUS_UNCHANGED = "unchanged"
STATUS_REVIEW = "review"

#: The standard conductor sizes in mm2, as the plan writes them. A mapped line moves
#: exactly one entry.
SIZE_LADDER = ("0.5", "0.75", "1", "1.5", "2.5", "4", "6", "10", "16", "25", "35", "50",
               "70", "95", "120", "150", "185", "240", "300", "400", "500", "630")
#: The same ladder as a product code writes it: 4js drops the leading zero (`.5`, `.75`).
_CODE_LADDER = tuple(fmt_csa(size) for size in SIZE_LADDER)

COPPER = "N"
TINNED_COPPER = "D"
ALUMINIUM = "A"

#: The customer-facing note every ALI-FLEX alternative carries (the plan's "Quote note").
QUOTE_NOTE = (
    "ALI-FLEX alternatives have been proposed using our provisional sales sizing and style "
    "substitution rules. Cable calculations must be completed before ordering to confirm "
    "the suitability and sizing of all runs."
)


@dataclass(frozen=True)
class StyleRule:
    destination: str
    action: str  # "style_110c", "size_only", "earth", "hold"
    note: str = ""


#: One record per source style in the supplied Flexible Cables and Solar list.
STYLE_RULES: dict[str, StyleRule] = {
    "XL": StyleRule("KL", "style_110c"),
    "KL": StyleRule("KL", "size_only"),
    "XU": StyleRule("KU", "style_110c"),
    "KU": StyleRule("KU", "size_only"),
    "XA": StyleRule("KA", "style_110c"),
    "KA": StyleRule("KA", "size_only"),
    "XN": StyleRule("KN", "style_110c"),
    "KN": StyleRule("KN", "size_only"),
    "SX": StyleRule("SK", "style_110c"),
    "SK": StyleRule("SK", "size_only"),
    "LP": StyleRule("KL", "earth"),
    "LS": StyleRule("KL", "earth",
                    "The source earth is LS (LSZH, 125C). Check that no LSZH or 125C "
                    "requirement applies before using the KL earth."),
    "EA": StyleRule("EA", "hold"),
}

#: The jacket colour of every ALI-FLEX cable that is not an earth.
ALUMINIUM_COLOUR = "BK"

#: The KL SDI earth: colour configuration PA, no screen/armour, green/yellow sheath.
EARTH_CONFIG = "PA"
EARTH_SCREEN = "X"
EARTH_COLOUR = "EA"

_COPPER_ONLY_RE = re.compile(
    r"\b(?:copper\s+only|cu\s+only|no\s+alternatives?|no\s+alternates?|no\s+substitut\w*)\b", re.I
)


def next_size(csa: str) -> str | None:
    """The next standard size after `csa`, in code form (`.75`, `25`), or None when `csa`
    is not on the ladder or is the last size. Accepts either form."""
    size = fmt_csa(csa)
    if size not in _CODE_LADDER:
        return None
    index = _CODE_LADDER.index(size)
    return _CODE_LADDER[index + 1] if index + 1 < len(_CODE_LADDER) else None


def display_size(csa: str) -> str:
    """`.75` -> `0.75`: the size as Sales write it."""
    size = fmt_csa(csa)
    return SIZE_LADDER[_CODE_LADDER.index(size)] if size in _CODE_LADDER else size


def _result(code: str, status: str, reason: str, **fields: Any) -> dict[str, Any]:
    return {
        "policy_version": POLICY_VERSION,
        "source_code": code,
        "proposed_code": fields.pop("proposed_code", code),
        "status": status,
        "reason": reason,
        **fields,
    }


def propose(code: str, comment: str = "") -> dict[str, Any]:
    """The agreed ALI-FLEX proposal for one product code.

    Returns `status` (changed / unchanged / review), `proposed_code` (the source code
    when nothing changes), `reason` in Sales' words, and for a change the explicit
    before/after style, size and conductor.
    """
    source = (code or "").strip().upper()
    if not source:
        return _result(source, STATUS_REVIEW, "No product code to map.")
    parsed = parse_code(source)
    if not parsed.get("valid"):
        return _result(source, STATUS_REVIEW, f"{source} could not be read as a cable code.")

    style = parsed["style"]
    rule = STYLE_RULES.get(style)
    conductor = parsed["conductor"]
    if conductor == ALUMINIUM:
        return _result(source, STATUS_UNCHANGED, "Already aluminium.")
    if rule is None:
        return _result(source, STATUS_UNCHANGED, f"{style} is not covered by the ALI-FLEX rule.")
    if rule.action == "hold":
        return _result(source, STATUS_REVIEW,
                       f"{style} (VFD/VSD) has no confirmed ALI-FLEX mapping yet.")
    if comment and _COPPER_ONLY_RE.search(comment):
        return _result(source, STATUS_REVIEW,
                       "The line comment asks for copper or no alternatives.")
    if conductor == TINNED_COPPER:
        return _result(source, STATUS_REVIEW, "Tinned copper is not covered by the ALI-FLEX rule.")
    if conductor != COPPER:
        return _result(source, STATUS_REVIEW, f"Conductor '{conductor}' is not copper.")
    if parsed["core_type"] != "C":
        return _result(source, STATUS_REVIEW, "Only mm² power/control sizes are mapped, not "
                                              "AWG/MCM, pairs, triads or quads.")
    size = parsed["csa"]
    to_size = next_size(size)
    if to_size is None:
        reason = ("630 mm² has no next standard size." if size == _CODE_LADDER[-1]
                  else f"{display_size(size)} mm² is not a standard size.")
        return _result(source, STATUS_REVIEW, reason)

    config, screen, source_colour = parsed["config_core"], parsed["armour"], parsed["colour"]
    colour = ALUMINIUM_COLOUR
    if parsed["count"] > 1:
        config = "P9"
    if rule.action == "earth":
        if parsed["count"] != 1 or source_colour != EARTH_COLOUR:
            return _result(source, STATUS_REVIEW,
                           "The earth rule covers a single-core green/yellow earth only.")
        config, screen, colour = EARTH_CONFIG, EARTH_SCREEN, EARTH_COLOUR

    proposed = (
        f"{rule.destination}{parsed['sheath']}-{config}{screen}{ALUMINIUM}/"
        f"{parsed['count']}{parsed['core_type']}{to_size}{colour}{parsed['tail']}"
    )
    return _result(
        source,
        STATUS_CHANGED,
        _change_summary(style, rule, size, to_size),
        proposed_code=proposed,
        action=rule.action,
        style_from=style,
        style_to=rule.destination,
        size_from=display_size(size),
        size_to=display_size(to_size),
        conductor_from=conductor,
        conductor_to=ALUMINIUM,
        config_from=parsed["config_core"],
        config_to=config,
        colour_from=source_colour,
        colour_to=colour,
        review_note=rule.note,
    )


def _change_summary(style: str, rule: StyleRule, size: str, to_size: str) -> str:
    sizes = f"Cu {display_size(size)} mm² to Al {display_size(to_size)} mm²"
    if rule.action == "earth":
        return f"{sizes}; {style} earth to KL SDI earth."
    if rule.action == "size_only":
        return f"{sizes}; keeps the 110C {style} style."
    return f"{sizes}; {style} (90C) to {rule.destination} (110C)."


#: Row keys that belong to the source line's product or pricing and must not carry
#: across to the proposed product (the plan: "cleared source prices, approvals and 4js
#: sync identity for changed products"). `id` is the draft line identity, which a new
#: draft assigns afresh.
_CLEARED_ON_CHANGE = ("pricing", "unit_price", "final_unit_price", "margin_percent", "margin_source",
                      "final_product_code", "final_status", "selected_product", "style_candidates")
_ROW_IDENTITY = ("id", "_id", "_draft_row_id", "line_uid")


def _row_code(row: dict[str, Any]) -> str:
    return str(row.get("final_product_code") or row.get("selected_product_code") or "").strip()


def _row_comment(row: dict[str, Any]) -> str:
    imported = row.get("imported_line") or {}
    return " ".join(part for part in (row.get("line_note") or "", imported.get("comment") or "") if part)


def alternative_rows(rows: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """One ALI-FLEX row per source row, in order, with nothing dropped.

    Each row is a deep copy with a fresh identity. Quantities, lengths and the printed
    `imported_line` record are kept exactly. Changed lines append ALIFLEX Alt to the comment. A changed row takes the
    proposed code with its source pricing and margins cleared, ready for the Cost API. A
    review row keeps its source code and says why in its warnings. An unchanged row is
    the source row. Every row carries its proposal under `aliflex`.
    """
    out = []
    for row in rows or []:
        new = copy.deepcopy(row)
        for key in _ROW_IDENTITY:
            new.pop(key, None)
        proposal = propose(_row_code(row), _row_comment(row))
        new["aliflex"] = proposal
        if proposal["status"] == STATUS_CHANGED:
            for key in _CLEARED_ON_CHANGE:
                new.pop(key, None)
            new["selected_product_code"] = proposal["proposed_code"]
            comment = str(new.get("line_note") or "")
            if "ALIFLEX Alt" not in comment:
                new["line_note"] = f"{comment}; ALIFLEX Alt" if comment else "ALIFLEX Alt"
            fields = new.get("fields")
            if isinstance(fields, dict) and "exact_product_code" in fields:
                fields["exact_product_code"] = proposal["proposed_code"]
            if proposal.get("review_note"):
                new.setdefault("warnings", []).append(proposal["review_note"])
        elif proposal["status"] == STATUS_REVIEW:
            new.setdefault("warnings", []).append(f"ALI-FLEX: {proposal['reason']} Kept as {proposal['source_code'] or 'blank'}.")
            new["status"] = "flagged"
        out.append(new)
    return out


def summarise(rows: list[dict[str, Any]]) -> dict[str, int]:
    """Counts of changed / unchanged / review rows, for the status line."""
    counts = {STATUS_CHANGED: 0, STATUS_UNCHANGED: 0, STATUS_REVIEW: 0}
    for row in rows or []:
        status = (row.get("aliflex") or {}).get("status")
        if status in counts:
            counts[status] += 1
    return counts


#: The draft `source` of an ALI-FLEX alternative. The calculator's cheaper-cable
#: alternative uses the same one, so both sit under "Alternative" on the dashboard; the
#: label says which it is.
SOURCE_ALTERNATIVE = "alternative"


def alternative_quote_details(quote_details: dict[str, Any] | None) -> dict[str, Any]:
    """The source quote's header, with the required calculation note in the comment.

    Customer, contact, reference, branch and region carry across unchanged. There is no
    4js identity in quote_details to clear: a quote number lives on the draft record,
    and a new draft has none.
    """
    details = copy.deepcopy(quote_details or {})
    comment = str(details.get("comment") or "").strip()
    if QUOTE_NOTE not in comment:
        details["comment"] = f"{comment}\n{QUOTE_NOTE}".strip()
    return details


def alternative_label(source_label: str) -> str:
    source = (source_label or "").strip()
    return f"ALI-FLEX alternative · {source}" if source else "ALI-FLEX alternative"



def status_message(counts: dict[str, int]) -> str:
    """The editor's status line for a new alternative."""
    parts = [f"{counts[STATUS_CHANGED]} line(s) changed to ALI-FLEX"]
    if counts[STATUS_UNCHANGED]:
        parts.append(f"{counts[STATUS_UNCHANGED]} unchanged")
    if counts[STATUS_REVIEW]:
        parts.append(f"{counts[STATUS_REVIEW]} to check")
    return ", ".join(parts) + ". Cable calculations must confirm sizing before ordering."


def convert(rows: list[dict[str, Any]], quote_details: dict[str, Any] | None, label: str) -> dict[str, Any]:
    """The pure half of the source's `create_alternative_draft`: the alternative rows, the
    header with the quote note, the label and the counts, from a finished quote's rows.

    Prices, margins, eligibility and the store are the caller's. With nothing to change
    (`counts["changed"] == 0`) the caller makes no draft, as the source does.
    """
    alternative = alternative_rows(rows)
    counts = summarise(alternative)
    return {
        "policy_version": POLICY_VERSION,
        "rows": alternative,
        "quote_details": alternative_quote_details(quote_details),
        "label": alternative_label(label),
        "counts": counts,
        "message": status_message(counts) if counts[STATUS_CHANGED] else "No line matches the ALI-FLEX rule, so no alternative was made.",
    }
