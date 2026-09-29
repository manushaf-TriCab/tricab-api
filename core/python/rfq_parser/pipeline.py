# Generated from the RFQ parser's matcher/pipeline.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
"""Shared RFQ processing pipeline.

This module owns the long-lived singletons (product catalog, cost API client,
review log) and the front-end-agnostic logic for turning raw RFQ text into
matched/priced line items and pushing them to 4js as a draft quote.

It was extracted from ``app.py`` so that more than one front door can drive the
same engine: the Flask web UI (``app.py``) and the email worker (``mail_ingest``)
both import from here instead of duplicating the matching/pricing/push code.
Behaviour is intentionally identical to the previous in-``app`` implementation.
"""

from __future__ import annotations

import math
import os
import re
import urllib.parse
import urllib.request
from datetime import date, timedelta
from math import ceil
from pathlib import Path


from tricab_cable_rules.cable_code import parse_code
from rfq_parser.cost_api import CostApiClient, REGION_DBCO
from tricab_cable_rules.data import load_catalog
from rfq_parser.quote_readiness import (
    REGION_PROFILE, normalize_currency, region_profile, row_push_block_reason, quote_readiness,
    product_code as canonical_product_code, quote_quantity, reviewed_price,
    positive_number, exclusion_reason, row_reference,
    COUNT_INFERENCE_WARNING_PREFIX, DOUBLE_COUNT_WARNING_PREFIX,
    MAKE_MOQ_WARNING_PREFIXES, PREPACK_WARNING_PREFIXES, QUANTITY_WARNING_PREFIXES,
)
from rfq_parser.currency import convert_priced_rows
from tricab_cable_rules.accessories import find_accessory_code
from tricab_cable_rules.extractor import (
    FIELD_SOURCE_CUSTOMER,
    extract_line_note,
    find_exact_product_code,
    mentions_high_temp,
)
from rfq_parser.table_units import is_metre_valued, normalize_header
from rfq_parser.llm_fallback import (
    apply_llm_fields,
    context_supplies_missing_construction,
    entry_declares_non_cable,
    mark_llm_attempt_failed,
    mark_regex_result,
    needs_ai_normalization,
    parse_with_openrouter,
    sanitize_llm_entry,
    should_try_llm,
)
from rfq_parser import normalizer
from rfq_parser import reader_normalizer
from rfq_parser import corrections
from rfq_parser._unported import agent
from rfq_parser.reel_quantity import extract_pipe_per_reel_quantity
from tricab_cable_rules.resolver import match_line
from rfq_parser.splitter import split_compound_line

import tricab_cable_rules as _rules

_rules_data = Path(_rules.__file__).resolve().parent / "data" / "catalog"


# --- Configuration & singletons ------------------------------------------------
# The catalogue ships inside tricab_cable_rules. PRODUCT_CSV / STYLE_XLSX / MARINE_XLSX
# still override it; an empty value means unset, as in the source.
PRODUCT_CSV = Path(os.environ.get("PRODUCT_CSV") or _rules_data / "tpro_ext_current_cables.csv")
STYLE_XLSX = Path(os.environ.get("STYLE_XLSX") or _rules_data / "current_cables.xlsx")
MARINE_XLSX = Path(os.environ.get("MARINE_XLSX") or _rules_data / "marine_offshore.xlsx")

class _LazyCatalog:
    """The product catalog, built on first use rather than on import.

    `load_catalog` already skips the CSV/xlsx parse via its pickle cache, but even a
    cache hit costs ~33ms, and the PHP bridge pays it on every request -- including
    `whoami`, `draft-list`, `customers-search` and `payterms`, none of which read a
    single product. Deferring it means only the actions that genuinely need the
    catalog pay for it.

    Every consumer reaches it through attribute access (`catalog.products`,
    `.styles`, `.products_by_code`, `.products_by_style`), so forwarding those is
    enough; the real ProductCatalog is what gets passed on from there.
    """

    __slots__ = ("_catalog",)

    def __init__(self) -> None:
        self._catalog = None

    def _resolve(self):
        if self._catalog is None:
            self._catalog = load_catalog(PRODUCT_CSV, STYLE_XLSX, MARINE_XLSX)
        return self._catalog

    def __getattr__(self, name: str):
        return getattr(self._resolve(), name)

    @property
    def loaded(self) -> bool:
        """Whether anything has actually needed the catalog yet (health/diagnostics)."""
        return self._catalog is not None


catalog = _LazyCatalog()
cost_api = CostApiClient()


def to_float(value, default=None):
    """A float, or ``default`` when the value is not one.

    NaN and the infinities are not answers, and `float()` produces both of them without
    complaint: `float("NaN")`, `float("inf")` and any arithmetic that reaches them all
    succeed. One such value stored on a draft row makes the whole draft unopenable --
    `json.dumps` writes bare `NaN`/`Infinity`, which Python reads back happily but is not
    JSON, so `rfq_api.php` fails to decode the response and the rep sees only "RFQ Python
    returned invalid JSON" with an empty reason. `quote_readiness.positive_number` already
    guarded this; nothing else did.
    """
    if value in ("", None):
        return default
    try:
        number = float(value)
    except (TypeError, ValueError, OverflowError):
        return default
    return number if math.isfinite(number) else default


def to_positive_float(value):
    return positive_number(value)


# --- Line matching -------------------------------------------------------------
# Matches a trailing "| 135" or "| 1865" in table rows where the file-parser
# didn't recognise the length column header and merged it into the description
# text instead of setting metadata["table_quantity"].
# Matches trailing "| 135" or "| 30 | M" from table rows where the file-parser
# joined all columns with " | " but didn't recognise the length/unit headers.
_PIPE_LENGTH_RE = re.compile(r"\|\s*(\d+(?:\.\d+)?)\s*(?:\|\s*([A-Za-z]{1,3}))?\s*$")
# A trailing "| 8 X 40" qty cell means 8 cuts of 40 m (44-Amanda), not 8 m --
# checked before the single-number recovery so the cut count isn't lost.
_PIPE_CUTS_RE = re.compile(r"\|\s*(\d+)\s*[x×]\s*(\d+(?:\.\d+)?)\s*(?:m|mtrs?)?\s*$", re.I)
_LEADING_PIPE_LENGTH_RE = re.compile(r"^\s*(\d+(?:\.\d+)?)\s*\|\s*(.+)$")
_QUANTITY_LENGTH_HEADER_RE = re.compile(r"\b(?:qty|quantity|length)\b.*\b(?:metres?|meters?|m)\b", re.I)
_CABLE_DESCRIPTION_HEADER_RE = re.compile(r"\b(?:types?\s+of\s+cables?|cable\s+types?|description|name)\b", re.I)


def _extract_pipe_length(raw_text: str, metadata: dict | None) -> tuple[str, dict | None]:
    """If the raw text ends with '| NUMBER' or '| NUMBER | UNIT' and no
    table_quantity is already set, strip the pipe-length and return it as
    table_quantity in a copy of metadata.

    A trailing ``0`` is left alone (treated as no match) rather than recovered:
    this heuristic exists to recover an omitted length column, and unlike a
    genuinely structured quantity column (see ``has_explicit_zero_length``,
    which does treat an explicit, correctly-identified 0 as "customer wants
    this line dropped"), a guessed trailing pipe-column is just as likely to be
    an unrelated field that happens to be 0 (price, fee, earth total, ...) in a
    raw dump the file-parser couldn't structure -- wrongly recovering it as a
    length silently drops the entire line with no signal to the user.
    """
    if metadata and metadata.get("table_quantity") not in (None, ""):
        return raw_text, metadata
    cuts_match = _PIPE_CUTS_RE.search(str(raw_text))
    if cuts_match and float(cuts_match.group(2)) != 0:
        stripped = raw_text[: cuts_match.start()].rstrip()
        new_meta = dict(metadata or {})
        new_meta["table_quantity"] = float(cuts_match.group(2))
        new_meta["number_of_lengths"] = int(cuts_match.group(1))
        new_meta["quantity_basis"] = "per_cut"
        new_meta.setdefault("table_unit", "m")
        return stripped, new_meta
    m = _PIPE_LENGTH_RE.search(str(raw_text))
    if not m or float(m.group(1)) == 0:
        return raw_text, metadata
    stripped = raw_text[: m.start()].rstrip()
    new_meta = dict(metadata or {})
    new_meta["table_quantity"] = float(m.group(1))
    unit = (m.group(2) or "").strip().lower() or "m"
    new_meta.setdefault("table_unit", unit)
    return stripped, new_meta


def _extract_leading_pipe_length(raw_text: str, metadata: dict | None, context: list[str] | None) -> tuple[str, dict | None]:
    """Recover rows like '45 | 6mm Building Wire - Earth' when the table header
    says the first column is Quantity/Length in metres."""
    if metadata and metadata.get("table_quantity") not in (None, ""):
        return raw_text, metadata
    header_text = " | ".join(str(value or "") for value in (context or []))
    if not (
        _QUANTITY_LENGTH_HEADER_RE.search(header_text)
        and _CABLE_DESCRIPTION_HEADER_RE.search(header_text)
    ):
        return raw_text, metadata
    m = _LEADING_PIPE_LENGTH_RE.match(str(raw_text))
    if not m:
        return raw_text, metadata
    stripped = m.group(2).strip()
    if not stripped:
        return raw_text, metadata
    new_meta = dict(metadata or {})
    new_meta["table_quantity"] = float(m.group(1))
    new_meta["table_quantity_raw"] = m.group(1)
    new_meta["table_quantity_header"] = "Quantity"
    new_meta.setdefault("table_unit", "m")
    return stripped, new_meta


def _fmt_num(value) -> str:
    """Format a number without a trailing ``.0`` (220.0 -> "220", 12.5 -> "12.5")."""
    try:
        number = float(value)
    except (TypeError, ValueError):
        return str(value)
    return str(int(number)) if number.is_integer() else str(number)


def _route_label(raw_text: str, metadata: dict | None) -> str:
    """The circuit-label part of the comment: ``FROM > TO`` from table metadata,
    falling back to a circuit label extracted from free-text (e.g. "Circuit 1")."""
    return _metadata_route_label(metadata) or extract_line_note(str(raw_text or ""))


def _metadata_drum_mark(metadata: dict | None) -> str:
    """The drum mark a table/reader row carried, for the tail of the comment.

    Only the explicit key. `mail_ingest`'s drum-matrix reader already folds its
    `drum #`/`drum id` cells into `reference`, which reaches the comment as the label,
    so reading them here too would print the same drum twice on one line.
    """
    return str((metadata or {}).get("drum_mark") or "").strip()


def _metadata_customer_note(metadata: dict | None) -> str:
    """The customer-stated note a table/reader row carried, for the tail of the comment."""
    return str((metadata or {}).get("customer_note") or "").strip()


def _metadata_route_label(metadata: dict | None) -> str:
    """The cell-grounded half of the label -- FROM/TO/reference columns a parser read out
    of the customer's own file. Only reached on rows the AI never read: when terra
    answers, its own fields are the comment and none of this runs.
    """
    if metadata:
        from_v = (metadata.get("from") or "").strip()
        to_v = (metadata.get("to") or "").strip()
        reference = (metadata.get("reference") or "").strip()
        route = ""
        if from_v and to_v:
            route = f"{from_v} > {to_v}"
        elif from_v:
            route = from_v
        elif to_v:
            route = to_v
        if reference and route:
            return f"{reference}: {route}"
        if reference:
            return reference
        if route:
            return route
    return ""


def _format_cuts(length: dict | None) -> str:
    """The ``(CUTS x LENGTH)`` part of the comment, or "" when there's no genuine
    multi-cut breakdown to show.

    Only shown when ``number_of_lengths`` is a real cut count greater than 1 --
    a single cut is always mathematically identical to the total length shown
    elsewhere on the line (1 x X == X), so it carries no new information whether
    that "1" was customer-stated or just a parser default.
    """
    length = length or {}
    each = length.get("length_each") or length.get("length_each_m")
    cuts = length.get("number_of_lengths")
    if not each or not cuts or cuts in (1, 1.0):
        return ""
    return f"({_fmt_num(cuts)} x {_fmt_num(each)})"


# --- cuts on a consolidated summary line ------------------------------------------
#
# Syncing from the Product Summary panel turns each product into ONE synthetic line
# (`summaryQuoteRow`), so the per-line circuit labels and cut breakdowns the normal push
# sends to 4js are gone entirely. Sales hit that and asked for the cut information back.
#
# Built here rather than in the browser's `summaryLineNote` so the summary table, the CSV
# and the 4js comment render from one source instead of three that drift.
#
# Two rules differ from `_format_cuts`, deliberately:
#
#  1. A single cut MUST show. `_format_cuts` suppresses `1 x 884` because on one line it
#     is identical to the total shown beside it. In a consolidated list, dropping it means
#     the cuts stop accounting for the product's total.
#  2. Identical cuts are NEVER merged. Two lines of `(6 x 35)` render as
#     `(6 x 35), (6 x 35)`, not `(12 x 35)` -- Sales reconciles against the customer's
#     BoQ line by line, and merging would also have quietly absorbed the length problem
#     that §3.1 exists to stop us pretending we have solved.

#: Written after an over-long list rather than letting it end mid-item. A cutting
#: instruction truncated mid-list is worse than no list at all.
SUMMARY_CUTS_MORE = '+{count} more'


def summary_cut(length: dict | None) -> dict | None:
    """One line's cut breakdown as `{number_of_lengths, length_each, text}`.

    `None` when the line carries no usable quantity at all. A line with a total but no
    per-cut length is reported as a single cut of its total, which is what it is.
    """
    length = length or {}
    each = to_positive_float(length.get("length_each") or length.get("length_each_m"))
    cuts = to_positive_float(length.get("number_of_lengths"))
    if not each:
        each = to_positive_float(length.get("total_length") or length.get("total_length_m"))
        cuts = 1.0
    if not each:
        return None
    cuts = cuts or 1.0
    return {
        "number_of_lengths": int_if_whole_float(cuts),
        "length_each": int_if_whole_float(each),
        "text": f"({_fmt_num(cuts)} x {_fmt_num(each)})",
    }


def format_summary_cuts(cuts, limit: int | None = None) -> str:
    """`(6 x 35), (6 x 35)` for a consolidated line, capped without ending mid-item.

    `limit` is a character budget for the whole string. When the list does not fit, as
    many whole cuts as do fit are kept and an explicit `+N more` is appended, so a reader
    can see that the list is short rather than believing it complete. The comment we send
    to 4js is truncated at a hard `[:200]` today and that number was never verified
    against 4js -- see docs/SALES_GRID_VIEW_ORDER.md §3.1.
    """
    parts = [str(cut.get("text") or "") for cut in (cuts or []) if cut and cut.get("text")]
    if not parts:
        return ""
    joined = ", ".join(parts)
    if limit is None or len(joined) <= limit:
        return joined
    kept: list[str] = []
    for index, part in enumerate(parts):
        more = SUMMARY_CUTS_MORE.format(count=len(parts) - index - 1)
        candidate = ", ".join([*kept, part])
        # Every kept item has to leave room for the tail that says what was dropped.
        if len(candidate) + len(", " + more) > limit:
            break
        kept.append(part)
    dropped = len(parts) - len(kept)
    if not kept:
        # Not even one cut fits the budget. Say only how many there were; a fragment of
        # a cutting instruction is the one thing worse than none.
        return SUMMARY_CUTS_MORE.format(count=len(parts))
    if not dropped:
        return ", ".join(kept)
    return ", ".join([*kept, SUMMARY_CUTS_MORE.format(count=dropped)])


def _first_llm_field(results: list[dict], key: str) -> str:
    """The first non-empty ``key`` the AI returned across one row's entries.

    A row can split into several entries (a mains and its earth), and they share one
    comment, so the first entry that names a label or a drum mark supplies it.
    """
    for result in results or []:
        value = str(((result.get("llm") or {}).get("fields") or {}).get(key) or "").strip()
        if value:
            return value
    return ""


def _build_line_comment(
    label: str, length: dict | None, drum_mark: str = "", customer_note: str = ""
) -> str:
    """Combine the circuit label, cut breakdown, drum mark and customer note into the
    4js comment.

    Format: ``FROM > TO (CUTS x LENGTH) DRUM_MARK - CUSTOMER NOTE``. Every part is
    independent and any of them may be absent; with none of them the comment is empty
    and no comment is pushed. The cuts part only appears for a genuine multi-cut
    breakdown (see :func:`_format_cuts`).

    The identifiers are space-joined and the note is set off with " - ", because the
    note is prose and the rest are tags: `MSB > DB-3 (6 x 45) PH-1 4 weeks ex Melbourne`
    reads as one run-on string. " - " is the separator `_append_line_note` already uses
    for the MOQ/prepack segments that land after this, so a comment carrying both stays
    consistent instead of mixing two conventions.
    """
    tags = " ".join(
        part
        for part in (str(label or "").strip(), _format_cuts(length), str(drum_mark or "").strip())
        if part
    )
    return " - ".join(part for part in (tags, str(customer_note or "").strip()) if part)


def _has_customer_product_code(text, known_codes):
    """Protect explicit cable and accessory codes from inferred replacements."""
    return bool(find_exact_product_code(text, known_codes) or find_accessory_code(text))


def _text_with_customer_code(raw_text, metadata):
    """Carry the reader's separately supplied customer code into exact matching."""
    code = str((metadata or {}).get("product_code") or "").strip()
    if code and _has_customer_product_code(code, set(catalog.products_by_code)):
        if not _has_customer_product_code(str(raw_text), set(catalog.products_by_code)):
            return f"{code} {raw_text}".strip()
    return raw_text


def match_source_line(raw_text, source, context=None, metadata=None, llm_budget=None, allow_llm_fallback=True):
    """Match one raw RFQ line: regex parse, then (when ``allow_llm_fallback``) the
    reactive per-line OpenRouter fallback for low-confidence results.

    ``allow_llm_fallback`` is set to ``False`` by :func:`match_items` when the
    up-front AI normalizer is enabled, so a line is never sent to the AI twice.
    """
    # The deterministic comment, for rows the AI never reads. Derived from the original
    # line/metadata before pipe-length extraction mutates them; the cut breakdown is
    # added per result below. When the AI does answer, its own fields replace all of this
    # (see below) rather than competing with it.
    line_label = _route_label(raw_text, metadata)
    line_drum_mark = _metadata_drum_mark(metadata)
    line_customer_note = _metadata_customer_note(metadata)
    llm_results: list[dict] = []
    raw_text = _text_with_customer_code(raw_text, metadata)
    raw_text, metadata = extract_pipe_per_reel_quantity(raw_text, metadata)
    raw_text, metadata = _extract_pipe_length(raw_text, metadata)
    raw_text, metadata = _extract_leading_pipe_length(raw_text, metadata, context)
    entries = split_compound_line(str(raw_text))
    results = []
    for entry in entries:
        entry_context = list(context or [])
        entry_context.extend(earth_inherited_markers(entry.text, entries))
        entry_source = {
            **source,
            **({"reference": metadata.get("reference")} if metadata and metadata.get("reference") else {}),
            "original_text": str(raw_text),
            "entry_index": entry.entry_index,
            "split_count": len(entries),
            "split_reason": entry.split_reason,
        }
        # A directive the classifier scoped to this row (DIRECTIVE_CAPTURE_PLAN B3)
        # merges at the same field_overrides seam the AI normalizer uses -- a typed
        # field on a named row, never rewritten text and never a product code.
        directive_overrides = (metadata or {}).get("directive_overrides") or None
        result = mark_regex_result(
            match_line(
                entry.text,
                catalog,
                source=entry_source,
                context=entry_context,
                field_overrides=directive_overrides,
                row_interpreted=False,
            )
        )
        learned = corrections.correction_for(entry.text)
        if (learned and learned["product_code"] in catalog.products_by_code
                and not _has_customer_product_code(entry.text, set(catalog.products_by_code))):
            apply_learned_correction(result, catalog.products_by_code[learned["product_code"]], learned["confirmations"])
        # Always run, even with no table metadata at all: a spool length embedded in
        # an exact product code (e.g. "...EA200") still needs applying as the line's
        # length when there's no other length signal -- see apply_table_quantity.
        apply_table_quantity(result, metadata or {})
        if has_explicit_zero_length(result):
            continue

        if allow_llm_fallback and should_try_llm(result):
            if llm_budget is not None and llm_budget["used"] >= llm_budget["limit"]:
                result.setdefault("warnings", []).append(
                    f"LLM fallback deferred: request limit of {llm_budget['limit']} calls reached."
                )
                results.append(result)
                continue
            if llm_budget is not None:
                llm_budget["used"] += 1
            parse_result = parse_with_openrouter(
                entry.text,
                context=entry_context,
                previous_fields=result.get("fields") or {},
            )
            if parse_result.ok:
                for llm_index, llm_entry in enumerate(parse_result.entries, start=1):
                    llm_result = _match_llm_entry(
                        llm_entry,
                        base_source=entry_source,
                        context=entry_context,
                        metadata=metadata,
                        source_text=entry.text,
                        model=parse_result.model,
                        extra_source={
                            "llm_entry_index": llm_index,
                            "llm_split_count": len(parse_result.entries),
                            "llm_original_entry_text": entry.text,
                        },
                    )
                    if llm_result is not None:
                        llm_results.append(llm_result)
                        results.append(llm_result)
                continue

            mark_llm_attempt_failed(result, parse_result)
        results.append(result)
    # The AI answered, so it owns the comment: its fields REPLACE the deterministic
    # reading rather than filling gaps in it. Nothing is merged and nothing competes.
    if llm_results:
        line_label = _first_llm_field(llm_results, "circuit_label")
        line_drum_mark = _first_llm_field(llm_results, "drum_mark")
        line_customer_note = _first_llm_field(llm_results, "customer_note")
    for result in results:
        apply_default_cable_length(result)
        result["line_note"] = _build_line_comment(
            line_label, result.get("length"), line_drum_mark, line_customer_note
        )
    # Each row keeps its own pricing (no quote-wide MOQ grouping on a single
    # re-matched line), but the round trips are made concurrently up front.
    _warm_quote_prices(results, group=False)
    for result in results:
        apply_price(result)
    return results


def _match_llm_entry(llm_entry, *, base_source, context, metadata, source_text, model, extra_source=None):
    """Resolve one AI-produced cable entry into a matched result.

    Shared by the reactive per-line fallback in :func:`match_source_line` and the
    up-front normalizer in :func:`match_items`. Sanitizes inferred construction
    flags against ``source_text`` (the real customer text, never AI-generated
    prose), then feeds the entry's structured fields directly into the resolver
    as field_overrides -- no free-text round trip, so nothing re-parses the
    model's own wording. Colour/voltage/style-hint/routing still come from
    regex over ``source_text`` as usual. Returns ``None`` when the entry
    resolves to an explicit zero length (no line).
    """
    llm_entry = sanitize_llm_entry(llm_entry, source_text, context=context)
    llm_source = {
        **base_source,
        **(extra_source or {}),
        "normalized_text": llm_entry.description,
    }
    if entry_declares_non_cable(llm_entry):
        llm_result = match_line(source_text, catalog, source=llm_source, context=context)
        llm_result["status"] = "not_cable"
        llm_result["item_type"] = "admin"
        llm_result["confidence"] = min(0.8, max(float(llm_entry.confidence or 0), 0.5))
        llm_result["selected_product_code"] = ""
        llm_result["selected_product"] = None
        llm_result["candidates"] = []
        llm_result["style_candidates"] = []
        fields = llm_result.setdefault("fields", {})
        for key in (
            "canonical_code",
            "canonical_in_catalog",
            "code_source",
            "code_validated",
            "description",
            "style_title",
            "style_application",
        ):
            fields.pop(key, None)
        llm_result["warnings"] = ["AI identified this row as not a cable; no cable product code generated."]
        apply_llm_fields(llm_result, llm_entry, model)
        apply_table_quantity(llm_result, metadata or {})
        return llm_result
    llm_result = match_line(source_text, catalog, source=llm_source, context=context, field_overrides=llm_entry.fields)
    apply_llm_fields(llm_result, llm_entry, model)
    apply_table_quantity(llm_result, metadata or {})
    if has_explicit_zero_length(llm_result):
        return None
    return llm_result


def match_items(items, *, budget=None, message_context=None, degraded=None):
    """Shared front-door orchestrator: extracted work items -> matched rows.

    ``items`` are work items shaped like ``{text, source, context, metadata}``.
    When the AI normalizer is enabled (``RFQ_NORMALIZER_ENABLED=true``), every row
    first gets a regex-only trial match (cheap, local); only rows that trial
    can't confidently resolve (see needs_ai_normalization) -- and that don't
    already name an exact, known product code (see find_exact_product_code) --
    are sent to the AI at all. A row regex already resolves confidently (e.g. a
    well-formed "0.6/1kV ... CU 25mm" line) never touches the AI: cheaper, and a
    plain deterministic answer is a smaller risk surface than even a
    well-grounded AI-inferred one -- confirmed by a real misfire where the AI's
    own guess corrupted an otherwise-solid regex match. Rows the AI still
    doesn't handle (or all rows, when the normalizer is disabled) fall back to
    that same trial result, with the reactive per-line fallback active only
    when the normalizer is off.
    """
    items = [
        {**item, "text": _text_with_customer_code(item.get("text") or "", item.get("metadata"))}
        for item in (items or [])
    ]
    if budget is None:
        budget = new_llm_budget(line_count=len(items))
    # Phase 1 of AGENT_EMAIL_PLAN.md: the agent is an alternative to the normalizer for
    # the same job on the same rows, so it reuses the trial-match pre-filter below rather
    # than seeing every row. It wins when both are on -- the plan measures one against the
    # other, it never runs both. With RFQ_AGENT_ENABLED unset this is all dead weight and
    # behaviour is exactly what it was.
    #
    # `message_context` is the second half of the gate, and it is what keeps the agent off
    # the PHP request path: rfq_api.php spawns rfq_cli.py per request under a 100s
    # per-action timeout, which plan §5.1 rules out for a multi-turn loop, and the actions
    # on that path have no message to pass. "Do I have a message to read?" is the honest
    # gate for a component whose job is reading the message -- and unlike a second flag, it
    # cannot be switched on from App Settings by mistake.
    #
    # The portal is no longer only that request path. PORTAL_TERRA_PARITY_PLAN.md Phase C
    # re-reads a portal upload in a WebJob (`matcher/reader_jobs.py`), which has no cap,
    # and it passes a real `message_context` built from the draft's label and the uploaded
    # filenames -- so a portal upload reaches this gate exactly as an email does. The gate
    # did not need loosening for that, which is the argument for it having been written
    # this way: what changed is that the portal acquired a message, not that the rule
    # about needing one was relaxed.
    # The translator (reader_normalizer) is the third implementation of the same job -- row
    # text in, resolver-shaped field overrides out -- on the reader's model rather than
    # OpenRouter's or Foundry's. It is gated exactly like the agent: enabled AND a message in
    # hand, which keeps it off the PHP request path. Rows that came off the reader bypass the
    # regex trial gate entirely and go straight to it (the reader already structures them, so
    # the deterministic extractor has nothing to add in between -- see _reader_sourced below).
    use_translator = reader_normalizer.translator_enabled() and message_context is not None
    # The translator wins over the agent, and the agent over the normalizer. Never two at
    # once: the point of each was to replace the one before it, and running two puts one
    # message's rows through two translators with no way to tell afterwards which answered.
    #
    # Translator first specifically so the swap is ONE App Settings change on a poller that
    # is already live and auto-pushing. With the agent winning instead, switching terra on
    # did nothing until RFQ_AGENT_ENABLED was also cleared -- two restarts, with a window in
    # between where the flags disagree about who is translating, on a system that quotes
    # unattended every minute.
    use_agent = agent.agent_enabled() and message_context is not None and not use_translator
    use_normalizer = normalizer.normalizer_enabled()
    use_ai = use_agent or use_translator or use_normalizer
    known_codes = set(catalog.products_by_code.keys())

    trial_results: dict[int, list[dict]] = {}
    ai_indexes: set[int] = set()
    if use_ai:
        for idx, item in enumerate(items):
            text = str(item.get("text") or "").strip()
            if not text:
                continue
            source = dict(item.get("source") or {"line_number": idx + 1})
            context = item.get("context") or []
            metadata = item.get("metadata") or {}
            # "The email and attachments go straight to Terra. No regex checks in between."
            # A row the reader produced never goes through the deterministic trial match: the
            # reader already resolved the sheet and validated every quantity against a cell,
            # so the regex extractor has nothing to contribute and its verdict must not decide
            # whether the row reaches the AI. Absent from trial_results on purpose -- if the
            # translator fails, the assembly loop below runs the regex path fresh, which is
            # the degraded fallback, not a gate in front of the AI.
            if use_translator and _reader_sourced(source) and not _has_customer_product_code(text, known_codes):
                ai_indexes.add(idx)
                continue
            rows = match_source_line(
                text, source, context=context, metadata=metadata, llm_budget=budget, allow_llm_fallback=False
            )
            trial_results[idx] = rows
            # Rows from a sheet the file-parser flagged as having no cable
            # signal at all (questionnaires, legal tabs, #REF! costing sheets)
            # are never worth an AI call -- they'd all look "ambiguous".
            if metadata.get("low_relevance_sheet"):
                continue
            if not _has_customer_product_code(text, known_codes) and (
                any(needs_ai_normalization(row) for row in rows)
                # A confident trial can still be wrong when a section heading
                # above the row states a construction fact regex never reads
                # ("Al/XLPE cables" over "95mm x 525mtr" resolved as copper) --
                # the AI is the only reader of current_section, so route the
                # row to it whenever the heading supplies what regex assumed.
                or context_supplies_missing_construction(context, rows)
            ):
                ai_indexes.add(idx)

        # Group rows by their shared context (the sheet heading/section they sit
        # under). When at least one row in a group already needs AI help, pull
        # in siblings that are similarly typeless -- their own text/regex gave
        # no cable_type_hint at all (e.g. a bare "2.5mm2" with no core count,
        # keywords, or pair/triad notation) -- so the model sees the whole
        # sparse block together and can classify it the way a person would if
        # they pasted the whole section into chat (a run of bare sizes under
        # "CONTROLS Cable Types" are all control cables, not power defaults).
        # A sibling whose own text already resolved a cable type (e.g. "2Pr
        # 0.5mm2 Cu PVC/PVC/CS" -> Instrumentation, from its own "2Pr") is left
        # alone: it isn't ambiguous, and routing an already-correct regex match
        # through the AI just to borrow a heading it doesn't need risks the
        # model corrupting a good result (seen with a real file: a clean 2-pair
        # instrumentation match came back re-typed with a nonexistent style).
        context_groups: dict[tuple[str, ...], list[int]] = {}
        for idx, item in enumerate(items):
            if not str(item.get("text") or "").strip():
                continue
            key = tuple(item.get("context") or ())
            if key:
                context_groups.setdefault(key, []).append(idx)
        for group_indexes in context_groups.values():
            if len(group_indexes) < 2 or ai_indexes.isdisjoint(group_indexes):
                continue
            for idx in group_indexes:
                text = str(items[idx].get("text") or "").strip()
                if not text or _has_customer_product_code(text, known_codes):
                    continue
                if idx in ai_indexes:
                    continue
                if (items[idx].get("metadata") or {}).get("low_relevance_sheet"):
                    continue
                rows = trial_results.get(idx) or []
                if any((row.get("fields") or {}).get("cable_type_hint") for row in rows):
                    continue
                # Only genuinely sparse siblings (a bare "2.5mm2" with no core
                # count or construction) benefit from borrowing the group's
                # heading. A row that already carries its own explicit structure --
                # it split into an active/earth pair, states parallel runs, or
                # names an earth conductor -- is a fully-specified schedule line
                # that resolves on its own; pulling it in only bloats the batch.
                # One 35-row cable schedule otherwise became a single ~20k-token
                # AI call that truncated at the model's output cap and timed out,
                # returning no rows at all.
                if len(rows) > 1 or any(
                    (row.get("fields") or {}).get(key)
                    for row in rows
                    for key in ("earth", "parallel_run_count", "parallel_single_core")
                ):
                    continue
                ai_indexes.add(idx)

    skip = set(range(len(items))) - ai_indexes
    # Which module answered, for the record kept below when a row comes back with nothing
    # in it. The normalizer keeps no run record, so it is None here -- and a counter with
    # nowhere honest to go is not written at all, rather than written somewhere wrong.
    ai_runner = None
    if use_translator and ai_indexes:
        # Returns {} on any failure, which drops these rows to the regex trial result
        # below. last_run() carries the reason so the caller can stamp the draft degraded
        # rather than shipping a quietly worse quote.
        ai_runner = reader_normalizer
        normalized = reader_normalizer.normalize_items(items, budget=budget, skip_indexes=skip)
        # Carry the failure out to the caller. Returning {} makes these rows fall back to
        # regex, and without this the resulting draft is indistinguishable from a clean
        # one -- which is exactly how the dead OPENROUTER_API_KEY went unnoticed while the
        # failures amplified. An out-dict rather than a changed return type: match_items
        # has five call sites and only the email path can act on this.
        if degraded is not None:
            reason = reader_normalizer.degraded_reason()
            if reason:
                degraded["degraded"] = True
                degraded["reason"] = reason
                degraded["run"] = reader_normalizer.last_run()
    elif use_agent and ai_indexes:
        ai_runner = agent
        normalized = agent.normalize_items(
            items, budget=budget, skip_indexes=skip, message_context=message_context
        )
        if degraded is not None:
            reason = agent.degraded_reason()
            if reason:
                degraded["degraded"] = True
                degraded["reason"] = reason
                degraded["run"] = agent.last_run()
    elif use_normalizer and ai_indexes:
        normalized = normalizer.normalize_items(items, budget=budget, skip_indexes=skip)
    else:
        normalized = {}

    results = []
    for idx, item in enumerate(items):
        text = str(item.get("text") or "").strip()
        if not text:
            continue
        source = dict(item.get("source") or {"line_number": idx + 1})
        context = item.get("context") or []
        metadata = item.get("metadata") or {}

        row = normalized.get(idx)
        if row is not None and not row.entries:
            # The agent answered and had nothing to say for this row. Decision recorded in
            # AGENT_SHADOW_FIX_ORDER.md §2: fall back to the regex trial result rather than
            # emit nothing.
            #
            # Before this the row was deleted outright -- no result, no fallback, no
            # signal. A row the agent *failed* is None and falls back; a row it *succeeded*
            # on with an empty entries list appended nothing, and `degraded_reason()` keys
            # on failure, so `ok: true, chunks_failed: 0` came back on a message that
            # produced no rows at all. Purchase order 601003394 did exactly that three runs
            # out of three, taking three correct accessory matches with it.
            #
            # Falling back rather than stamping degraded, because "not a cable" is a
            # legitimate and common answer -- the same purchase order has 34 address and
            # header lines -- and degrading every message containing admin text would block
            # auto-push on most of the corpus. The regex path already emits those rows as
            # `not_cable`/`unresolved`, which is visible on the draft and reviewable. A
            # vanished row is neither. The warning below is the loud part.
            row = None
            no_reading = True
            if ai_runner is not None:
                # On whichever module actually ran. It used to be written to `agent`
                # unconditionally, which put a count on a record reading `ran: False`
                # whenever anything else translated -- a loud signal filed under the wrong
                # name is no easier to act on than a silent one.
                ai_runner._last_run["rows_without_reading"] = (
                    int(ai_runner._last_run.get("rows_without_reading") or 0) + 1
                )
        else:
            no_reading = False
        if row is None:
            before = len(results)
            # Regex already resolved this row confidently (never sent to the AI),
            # or the AI failed/dropped it (fall back to the trial regex result
            # computed above, or -- no up-front AI active -- run it fresh with the
            # reactive per-line fallback active). The translator is an up-front AI
            # exactly like the normalizer, so a row it drops must not silently swap
            # in the differently-configured OpenRouter fallback for a Terra reading.
            if idx in trial_results:
                results.extend(trial_results[idx])
            else:
                results.extend(
                    match_source_line(
                        text,
                        source,
                        context=context,
                        metadata=metadata,
                        llm_budget=budget,
                        allow_llm_fallback=not use_normalizer and not use_translator,
                    )
                )
            if no_reading:
                # Attached here rather than to the trial cache: the fallback is whichever
                # of the two branches above ran, and only one of them reuses trial_results.
                for fallback in results[before:]:
                    warnings = fallback.setdefault("warnings", [])
                    note = "AI returned no reading for this row; the deterministic result is shown."
                    if note not in warnings:
                        warnings.append(note)
            continue

        # AI translated this row, so the AI owns its comment. Nothing here re-reads the
        # row to work out a label: the comment is assembled from what the AI reported and
        # from cells the customer filled in -- never from `extract_line_note` scraping
        # the row's prose for a label, which is the "resolving the comment" this path no
        # longer does and which is how a junk `tal)` came to outrank a correctly-read
        # `MSSB-L3-4`.
        #
        # `metadata` is NOT a deterministic reader here, and treating it as one was a
        # mistake: on a reader-sourced row these keys ARE terra's output. The attachment
        # reader puts its own circuit_label/drum_mark/customer_note into metadata, and
        # zeroing them discarded 178 verbatim lead times terra had read correctly out of
        # Firmus_Q-134882 (2026-09-10). On a file-parser row the same keys are cells the
        # customer typed. Either way they belong in the comment, so they seed it, and the
        # translator's own fields take precedence below when it supplied any.
        line_label = _metadata_route_label(metadata)
        line_drum_mark = _metadata_drum_mark(metadata)
        line_customer_note = _metadata_customer_note(metadata)
        match_text, metadata = extract_pipe_per_reel_quantity(text, metadata)
        match_text, metadata = _extract_pipe_length(match_text, metadata)
        match_text, metadata = _extract_leading_pipe_length(match_text, metadata, context)
        base_source = {
            **source,
            "original_text": text,
            "normalizer_model": row.model,
            "normalizer_confidence": row.confidence,
            "normalizer_assumptions": row.assumptions,
        }
        row_results = []
        for entry_index, llm_entry in enumerate(row.entries, start=1):
            result = _match_llm_entry(
                llm_entry,
                base_source=base_source,
                context=context,
                metadata=metadata,
                source_text=match_text,
                model=row.model,
                extra_source={
                    "normalizer_entry_index": entry_index,
                    "normalizer_split_count": len(row.entries),
                },
            )
            if result is None:
                continue
            if row.needs_review:
                warning = "AI-normalized row — review before quoting."
                if row.assumptions:
                    warning += " Assumptions: " + "; ".join(row.assumptions)
                warnings = result.setdefault("warnings", [])
                if warning not in warnings:
                    warnings.append(warning)
            row_results.append(result)
        # The translator wins where it spoke, and stays silent where it did not: the
        # reader and the translator are two different terra calls on the same row, and
        # only the reader sees the sheet's own Notes/Lead Time cell. A plain assignment
        # here erased the reader's reading whenever the translator left the field null.
        line_label = _first_llm_field(row_results, "circuit_label") or line_label
        line_drum_mark = _first_llm_field(row_results, "drum_mark") or line_drum_mark
        line_customer_note = _first_llm_field(row_results, "customer_note") or line_customer_note
        for result in row_results:
            apply_default_cable_length(result)
            result["line_note"] = _build_line_comment(
                line_label, result.get("length"), line_drum_mark, line_customer_note
            )
        results.extend(row_results)

    # Priced once, in one concurrent quote-wide pass. Pricing each row here as
    # well only duplicated the work apply_quote_pricing redoes below.
    apply_quote_pricing(results)
    return results


def apply_learned_correction(result, product, confirmations):
    fields = result.setdefault("fields", {})
    fields.update(
        {
            "style": product.style,
            "config_core": product.config_core,
            "armour": product.armour,
            "conductor": product.conductor_type,
            "count": int(product.no_core) if str(product.no_core).isdigit() else product.no_core,
            "core_type": product.core,
            "cross_section": product.cross_core,
            "colour": product.colour,
        }
    )
    result["selected_product_code"] = product.product_code
    result["selected_product"] = product.public()
    result["candidates"] = [product.public()]
    result["status"] = "matched"
    result["confidence"] = 0.99
    result["parse_tier"] = "learned"
    result["learned_correction"] = {
        "confirmations": confirmations,
        "product_code": product.product_code,
    }
    result["warnings"] = [
        warning
        for warning in result.get("warnings", [])
        if not warning.startswith("Multiple products") and not warning.startswith("No product")
    ]


# --- Pricing -------------------------------------------------------------------
MAKE_MOQ_NOTE_PREFIX = "Defaulted to MAKE MOQ"
MAKE_MOQ_SIMPLE_NOTE_RE = re.compile(
    r"^(?:RQ )?\d+(?:\.\d+)?m below MOQ(?:\. Set length \d+(?:\.\d+)?m\.?)?$"
)
PREPACK_NOTE_PREFIX = "Defaulted to prepack length"
PREPACK_ROUNDING_NOTE_RE = re.compile(
    r"^(?:\d+(?:\.\d+)?m prepack: |RQ )\d+(?:\.\d+)?m -> \d+(?:\.\d+)?m "
    r"\(\d+(?:\.\d+)? x \d+(?:\.\d+)?m\)$"
)
PREPACK_BREAKDOWN_NOTE_RE = re.compile(r"^\(\d+(?:\.\d+)? x \d+(?:\.\d+)?m\)$")
QUANTITY_NOTE_PREFIXES = (MAKE_MOQ_NOTE_PREFIX, PREPACK_NOTE_PREFIX)
# Stock cable at or above this size is cut to length -- no MOQ (see prepack_allowed).
PREPACK_STOCK_MAX_CSA = 25
# The warning prefixes now live in `matcher/quote_readiness.py`, imported above, and are
# re-exported here because this is where callers have always found them. They moved
# because they were also hard-coded in static/app.js under a "change both or neither"
# comment: quote_readiness classifies them into codes the browser can switch on, so
# there is one definition and the browser no longer needs the strings at all.
ACCESSORY_DEFAULT_MARGIN = 50
# The margin a cable line goes to 4js with when the customer has no rule for it.
#
# `default_margins_for_customer` mirrors 4js's own product -> style-group -> territory
# lookup, and normally answers. When it has nothing for a product/customer pair the line
# used to block the whole sync, which a sales rep cannot clear: the simple view hides
# cost, margin and sell price, so there is no control that sets one. Sending it at cost
# instead is worse -- `price > 0` holds, so it lands in 4js at mgn% 0 and reads as a
# priced line.
#
# So an unknown margin becomes a deliberate, visible-in-4js 30% and the quote goes
# through, for Sales to adjust there. A 0 the engine genuinely RETURNS is an answer and
# is still honoured; this covers only the absent case.
FALLBACK_PUSH_MARGIN = 30


# The input types the attachment reader stamps on the rows it produces. A row carrying one
# of these came off Terra with every quantity grounded against a cell (or quote span) and
# validated; the regex extractor has nothing to add to it, so when the translator is on
# these rows skip the deterministic trial gate entirely and go straight to the AI. Keyed on
# the stamp rather than a flag so the behaviour follows the reader's own output — the same
# shape the repo uses to keep vision-OCR rows out of auto-push on `input_type`.
READER_INPUT_TYPES = frozenset({"attachment_reader", "text_reader", "reader_ungrounded"})


def _reader_sourced(source: dict) -> bool:
    return source.get("input_type") in READER_INPUT_TYPES


def apply_price(result, region=None):
    apply_quote_pricing([result], region=region)


def apply_quote_pricing(rows, region=None, currency=None):
    """Price a quote as a whole so MAKE MOQ can be evaluated per product.

    The Cost API's calculated cost per metre already accounts for MAKE MOQ when
    the requested length is below the product's default length. For that reason
    default-length MOQ is now a pricing-only rule: visible cuts and lengths stay
    as requested, while same-product quote lines are priced from their combined
    requested length.
    """
    rows = [row for row in (rows or []) if isinstance(row, dict)]
    for row in rows:
        prior_pricing = row.get("pricing") if isinstance(row.get("pricing"), dict) else {}
        row["_previous_quote_currency"] = prior_pricing.get("quote_currency")
    cable_groups: dict[str, dict] = {}

    # Fetch every price this quote needs concurrently before pricing anything.
    # The loop below stays serial and untouched -- it just reads from a warm
    # cache instead of paying a round trip per row.
    _warm_quote_prices(rows, region)

    for result in rows:
        mode = _row_pricing_mode(result)
        product_code = product_code_for_result(result)
        if mode == "excluded":
            # No lookup, and no contribution to any group total. Whatever price the row
            # already carries is left alone rather than cleared: an exclusion can be
            # undone in the editor, and a re-included line should come back with the
            # price it had instead of a hole where a hand-typed one used to be.
            continue
        if mode == "accessory":
            _price_accessory(result, product_code, region=region)
            continue

        length = result.get("length") or {}
        total_length = length.get("total_length") or length.get("total_length_m")
        if mode == "direct":
            pricing = _lookup_price([result], product_code, total_length, region)
            _apply_pricing(result, pricing)
            continue

        if mode == "defaulted":
            pricing = _lookup_price([result], product_code, total_length, region)
            _clear_default_length_moq(result)
            _apply_pricing(result, pricing)
            continue

        if mode == "locked":
            # The salesperson has manually set this line's length. Price it exactly as
            # entered and skip the prepack/MOQ rules -- re-snapping here silently reverted
            # their edit back to the prepack length.
            pricing = _lookup_price([result], product_code, total_length, region)
            _clear_quantity_rule(result)
            _apply_pricing(result, pricing)
            continue

        if mode == "prepack":
            prepack = prepack_size_for_result(result)
            pricing = _lookup_price([result], product_code, total_length, region)
            if not prepack_allowed(result, pricing):
                # Stock, 25mm2 and over: quote it as requested, cut to length.
                _clear_quantity_rule(result)
                _apply_pricing(result, pricing)
                continue
            if apply_prepack_length(result, prepack):
                length = result.get("length") or {}
                total_length = length.get("total_length") or length.get("total_length_m")
                # Only the post-snap length is unknown ahead of time, so this is
                # the one lookup the warming pass above cannot cover.
                pricing = _lookup_price([result], product_code, total_length, region)
            elif prepack <= 1:
                _clear_quantity_rule(result)
            _apply_pricing(result, pricing)
            continue

        if mode == "unpriceable":
            pricing = _lookup_price([result], product_code, total_length, region)
            _clear_default_length_moq(result)
            _apply_pricing(result, pricing)
            continue

        group = cable_groups.setdefault(
            _cable_group_key(product_code, region),
            {"product_code": product_code, "total_length": 0.0, "rows": []},
        )
        group["total_length"] += to_positive_float(total_length)
        group["rows"].append(result)

    for group in cable_groups.values():
        product_code = group["product_code"]
        group_total = int_if_whole_float(group["total_length"])
        pricing = _lookup_price(group["rows"], product_code, group_total, region)
        moq = to_positive_float((pricing or {}).get("default_length"))
        is_make = str((pricing or {}).get("cost_status") or "").upper() == "MAKE"
        below_moq = bool(is_make and moq and to_positive_float(group_total) and to_positive_float(group_total) < moq)
        for result in group["rows"]:
            if below_moq:
                apply_default_length_moq(result, moq, group_total_length=group_total)
            else:
                _clear_default_length_moq(result)
            _apply_pricing(result, pricing)

    selected_region = region or "australia"
    profile = region_profile(selected_region) or REGION_PROFILE["australia"]
    convert_priced_rows(rows, selected_region, currency or profile["currency"])
    return {"rows": rows, "summary": build_product_summary(rows)}


def _row_pricing_mode(result):
    """Which pricing rule a row falls under.

    Single source of truth: both the concurrent warming pass and the serial
    pricing loop dispatch on this, so the set of prices we prefetch can never
    drift away from the set the loop actually asks for.
    """
    # A row nobody is quoting must not reach the Cost API, and -- the reason this is a
    # correctness fix rather than a politeness one -- must not be in the `grouped` sum
    # either. `apply_quote_pricing` prices same-product lines at their combined length
    # because the API's cost per metre depends on `userInputLength`, so an excluded
    # 900m line silently repriced the 100m line beside it as if the customer were
    # buying 1000m. `prepare_quote_push` has always skipped these rows; pricing did not.
    #
    # `exclusion_reason` is the shared definition of "not in this quote" and stays the
    # only one (CLAUDE.md). The product-code guard keeps this narrow: its third branch
    # covers headings and non-cable prose, which carry no code, make no request and
    # therefore have no group to pollute -- those keep exactly the row shape, warnings
    # and `pricing` dict they have today.
    if _quantity_basis_unclear(result):
        return "excluded"
    if exclusion_reason(result) and product_code_for_result(result):
        return "excluded"
    if str(result.get("line_kind") or result.get("item_type") or "").lower() == "accessory":
        return "accessory"
    if result.get("item_type") and result.get("item_type") != "cable":
        return "direct"
    if result.get("length_defaulted"):
        return "defaulted"
    if result.get("length_locked"):
        return "locked"
    if prepack_size_for_result(result) is not None:
        return "prepack"
    length = result.get("length") or {}
    total_length = length.get("total_length") or length.get("total_length_m")
    if not product_code_for_result(result) or not to_positive_float(total_length):
        return "unpriceable"
    return "grouped"


def _cable_group_key(product_code, region):
    return f"{str(region or '').lower()}|{str(product_code or '').upper()}"


def _warm_quote_prices(rows, region=None, group=True):
    """Prefetch every Cost API price this quote will need, concurrently.

    ``group=False`` is for callers that price each row on its own (so a shared
    product code is looked up at its row length, not at the quote-wide sum).
    """
    specs = []
    group_totals: dict[str, list] = {}
    for result in rows:
        mode = _row_pricing_mode(result)
        product_code = product_code_for_result(result)
        if mode == "excluded":
            continue
        if mode == "accessory":
            specs.append((product_code, 1, region))
            continue
        length = result.get("length") or {}
        total_length = length.get("total_length") or length.get("total_length_m")
        if mode == "grouped" and group:
            entry = group_totals.setdefault(_cable_group_key(product_code, region), [product_code, 0.0])
            entry[1] += to_positive_float(total_length)
        else:
            specs.append((product_code, total_length, region))

    for product_code, total in group_totals.values():
        specs.append((product_code, int_if_whole_float(total), region))

    # Warming is a pure optimisation on top of lookup(), so a client that does
    # not offer it (a test double, a future alternate backend) just prices
    # serially as before.
    warm = getattr(cost_api, "warm", None)
    if callable(warm):
        warm(specs)


# --- the colour a code has to wear to be priceable -------------------------------------
# `EU-PAZN/4C120OR` matched confidently and then could not be priced: the catalogue extract
# carries that product in orange and the cost API only knows it in black. The catalogue's
# own `Default Clr` for EU is BK, so on this line three sources say black and one says
# orange -- and the odd one out is the one the resolver had to pick from, because the black
# code is not in the catalogue to be selected.
#
# So when a code comes back not_found, the same product in its style's default colour is
# tried once. The cost API answering for it is the validation: a constructed code that the
# ERP prices is a code that exists, which is a stronger check than the catalogue membership
# it cannot pass. Nothing is guessed -- the colour comes from the catalogue's own default
# for the style, not from a model and not from the price table.
#
# 23 EU codes and ~625 catalogue-wide have this shape (a matchable code whose colour twin
# is the priced one). This makes those lines quote instead of dying; it does not make the
# catalogue right, and the reconciliation report is still the fix for that.

def _default_colour_twin(result: dict, product_code: str) -> str:
    """`product_code` in its style's default colour, or "" when that is not a safe swap.

    The guard that matters is provenance. A customer who asked for orange gets orange, and
    an unpriceable line, because silently shipping a different colour is a worse failure
    than a quote that stops -- `field_sources["colour"]` is the only thing that separates
    "the customer said OR" from "the catalogue offered OR and nobody asked".
    """
    if (result.get("field_sources") or {}).get("colour") == FIELD_SOURCE_CUSTOMER:
        return ""
    selected = result.get("selected_product") or {}
    style = catalog.styles.get(selected.get("style") or "")
    default_colour = str(getattr(style, "default_colour", "") or "").upper()
    colour = str(selected.get("colour") or "").upper()
    if not default_colour or not colour or default_colour == colour:
        return ""
    code = str(product_code or "").upper()
    if not code.endswith(colour):
        return ""
    return code[: -len(colour)] + default_colour


def _lookup_price(rows, product_code: str, length, region=None) -> dict:
    """`cost_api.lookup`, retried once in the style's default colour on not_found.

    Takes the rows the price is for, not one row: the MAKE-MOQ path prices a whole group
    of same-product lines with a single lookup, and a swap that landed on only the first
    of them would push the priced code on one line and the unpriceable one on its
    neighbours. The decision is read off the first row -- they share a product code, so
    they share the colour and its provenance.

    Only on `not_found`. An `error` status is the API being unreachable or refusing the
    request, and retrying a second code through an outage doubles the traffic while
    answering nothing -- the distinction the cost API draws at its 404 branch is exactly
    the one worth respecting here.
    """
    pricing = cost_api.lookup(product_code, length, region)
    if pricing.get("status") != "not_found":
        return pricing
    rows = [row for row in rows if isinstance(row, dict)]
    if not rows:
        return pricing
    twin = _default_colour_twin(rows[0], product_code)
    if not twin:
        return pricing
    retry = cost_api.lookup(twin, length, region)
    if retry.get("status") != "available":
        return pricing

    for row in rows:
        row["final_product_code"] = twin
        if row.get("colour_priced_fallback"):
            # The prepack path prices a row twice; the swap is the same one both times.
            continue
        row["colour_priced_fallback"] = {
            "catalog_code": str(product_code).upper(),
            "priced_code": twin,
        }
        row.setdefault("warnings", []).append(
            f"{str(product_code).upper()} is not priced; quoted as {twin}, the style's "
            "default colour. Confirm the colour."
        )
    return retry


def _price_accessory(result, product_code, region=None):
    pricing = cost_api.lookup(product_code, 1, region)
    pricing["price_uom"] = "unit"
    result["pricing"] = pricing
    result["unit_price"] = pricing.get("unit_price")
    if not _keep_manual_sell_price(result):
        result["margin_percent"] = ACCESSORY_DEFAULT_MARGIN
        result["final_unit_price"] = calculate_final_unit_price(result["unit_price"], result["margin_percent"])
    result["line_total"] = calculate_line_total(result.get("length") or {}, result["final_unit_price"])
    result.pop("make_moq", None)


def _apply_pricing(result, pricing):
    result["pricing"] = pricing
    result["unit_price"] = (pricing or {}).get("unit_price")
    if not _keep_manual_sell_price(result):
        result["margin_percent"] = 0
        result["final_unit_price"] = calculate_final_unit_price(result["unit_price"], result["margin_percent"])
    result["line_total"] = calculate_line_total(result.get("length") or {}, result["final_unit_price"])
    _flag_unpriceable(result, pricing)


def _keep_manual_sell_price(result):
    """Hold a hand-entered sell price steady across a re-price, re-deriving margin.

    Cost always comes from the Cost API, so when it moves, something has to give.
    A rep who typed a sell price has committed to that number (it is what the
    customer was quoted), so the margin absorbs the change rather than the price.
    Mirrors sellPriceLocked in app.js.
    """
    if not result.get("sell_price_locked"):
        return False
    sell = to_positive_float(result.get("final_unit_price"))
    if not sell:
        result.pop("sell_price_locked", None)
        return False
    result["final_unit_price"] = sell
    result["margin_percent"] = calculate_margin_percent(result.get("unit_price"), sell) or 0
    return True


# Every one of these opens with UNPRICED_WARNING_PREFIX so the front end can tell
# "this line cannot be quoted" from "the parser guessed something" without parsing
# prose -- app.js:UNPRICED_WARNING_PREFIXES is the other half.
UNPRICED_WARNING_PREFIX = "No price:"

# The Cost API was asked and had no answer. `missing_length` is deliberately not
# here: that row's empty quantity box is already on screen, and calling it "no
# price" would name the wrong cause. `missing_code` cannot reach a matched row.
_UNPRICEABLE_STATUSES = frozenset({"not_found", "error"})

# Records that _flag_unpriceable -- and not the resolver -- wrote the status, so
# the revert below can never promote a line something else had real doubts about.
_UNPRICED_FLAG = "unpriced_flagged"


def _flag_unpriceable(result, pricing):
    """Turn a line the Cost API could not price into a line that needs a person.

    Two shapes of this, and until 2026-08-26 only the first was caught:

      * a code the resolver *constructed* for a cable that is not in the catalog
        -- an 11-core 1mm2 in a style not made at that size. A phantom, not a
        product. (A genuine uncatalogued MAKE cable prices fine and stays matched.)
      * an ordinary catalogued code the Cost API happens to have no answer for --
        not loaded yet, or the call failed.

    The second used to stay `matched`, and that was the hole. In a view with no
    price column there is nothing on screen to notice: the line reads green, gets
    swept up by "Approve all", and is then dropped into `skipped` by
    push_quote_to_4js's quantity/price check -- *after* the quote already exists in
    4js. The rep's whole notice was a "(N skipped)" parenthetical, and the app and
    4js then disagree about what was quoted with nothing saying so.

    Reversible, and it has to be: `error` covers a timed-out call as much as a
    missing product, and a retry that succeeds must not leave the line flagged for
    a problem that is over.
    """
    if not isinstance(result, dict):
        return
    if _row_is_priced(result, pricing):
        _clear_unpriceable_flag(result)
        return
    if str((pricing or {}).get("status") or "").lower() not in _UNPRICEABLE_STATUSES:
        return
    if str(result.get("status") or "").lower() != "matched":
        return
    result["status"] = "needs_clarification"
    result[_UNPRICED_FLAG] = True
    note = _unpriceable_note(result, pricing)
    warnings = result.setdefault("warnings", [])
    if note not in warnings:
        warnings.append(note)


def _row_is_priced(result, pricing) -> bool:
    """Whether this line has a price to be quoted at, from anywhere.

    A hand-typed sell price counts: push_unit_price sends it even with no cost
    behind it (_row_needs_default_margin bails out on sell_price_locked), so the
    line is not one that will vanish at sync.
    """
    if (pricing or {}).get("unit_price") is not None:
        return True
    return bool(result.get("sell_price_locked")) and to_positive_float(
        result.get("final_unit_price")
    ) is not None


def _clear_unpriceable_flag(result) -> None:
    if not result.pop(_UNPRICED_FLAG, False):
        return
    if str(result.get("status") or "").lower() == "needs_clarification":
        result["status"] = "matched"
    warnings = result.get("warnings")
    if isinstance(warnings, list):
        result["warnings"] = [
            warning
            for warning in warnings
            if not str(warning).startswith(UNPRICED_WARNING_PREFIX)
        ]


def _unpriceable_note(result, pricing) -> str:
    """Why there is no price, in the terms the rep would act on.

    Three different problems wear the same empty price box, and they need three
    different next moves -- fix the spec, fix the code, or just try again.
    """
    code = product_code_for_result(result) or "this line"
    fields = result.get("fields") or {}
    if fields.get("canonical_in_catalog") is False:
        return (
            f"{UNPRICED_WARNING_PREFIX} {code} was built from the description and is not a "
            f"known product. Confirm the correct product/size."
        )
    if str((pricing or {}).get("status") or "").lower() == "not_found":
        region = str((pricing or {}).get("region") or "").strip()
        where = f" in {region}" if region else ""
        return (
            f"{UNPRICED_WARNING_PREFIX} the Cost API does not carry {code}{where}. "
            f"Check the code before syncing."
        )
    return (
        f"{UNPRICED_WARNING_PREFIX} the Cost API did not answer for {code}. "
        f"Retry pricing before syncing."
    )


def product_code_for_result(result) -> str:
    return str(result.get("final_product_code") or result.get("selected_product_code") or "").strip()


def build_product_summary(rows):
    grouped: dict[str, dict] = {}
    for index, row in enumerate(rows or []):
        if not isinstance(row, dict) or not _is_summary_cable_row(row):
            continue
        product_code = product_code_for_result(row)
        if not product_code:
            continue
        key = product_code.upper()
        entry = grouped.setdefault(
            key,
            {
                "product_code": product_code,
                "line_count": 0,
                "requested_total_length": 0.0,
                "quoted_total_length": 0.0,
                "line_indexes": [],
                # One entry per source line, in the order the lines were quoted, never
                # merged. See `summary_cuts` for why.
                "cuts": [],
                "cost_status": "",
                "unit_price": None,
                "moq": None,
                "moq_applied": False,
                "prepack_applied": False,
            },
        )
        length = row.get("length") or {}
        visible_total = to_positive_float(length.get("total_length") or length.get("total_length_m")) or 0.0
        moq_info = row.get("make_moq") or {}
        requested_total = to_positive_float(moq_info.get("requested_total_length")) or visible_total
        entry["line_count"] += 1
        entry["requested_total_length"] += requested_total
        entry["quoted_total_length"] += visible_total
        entry["line_indexes"].append(index)
        cut = summary_cut(length)
        if cut:
            entry["cuts"].append(cut)

        pricing = row.get("pricing") or {}
        if pricing.get("cost_status"):
            entry["cost_status"] = str(pricing.get("cost_status")).upper()
        if pricing.get("unit_price") not in (None, ""):
            entry["unit_price"] = pricing.get("unit_price")
        if moq_info.get("source") == "default_length":
            entry["moq_applied"] = True
            entry["moq"] = moq_info.get("moq")
            entry["pricing_total_length"] = moq_info.get("pricing_total_length") or moq_info.get("group_total_length")
        elif moq_info.get("source") == "prepack_size":
            entry["prepack_applied"] = True
            entry["moq"] = moq_info.get("moq")

    summary = []
    for entry in grouped.values():
        entry["requested_total_length"] = int_if_whole_float(round(entry["requested_total_length"], 5))
        entry["quoted_total_length"] = int_if_whole_float(round(entry["quoted_total_length"], 5))
        # The rendered form travels with the structured one so the table, the CSV and the
        # comment cannot each format it slightly differently. Uncapped here: the cap is a
        # property of where the string is going, not of the summary.
        entry["cuts_text"] = format_summary_cuts(entry["cuts"])
        summary.append(entry)
    return sorted(summary, key=lambda item: item["product_code"].upper())


def _is_summary_cable_row(row) -> bool:
    if str(row.get("line_kind") or "").lower() == "accessory":
        return False
    status = str(row.get("final_status") or row.get("status") or "").lower()
    if status in {"accessory", "not_cable", "unresolved"}:
        return False
    if row.get("item_type") and row.get("item_type") != "cable":
        return False
    return True


def apply_make_moq(result, pricing) -> bool:
    """Apply cable quantity rules before quoting.

    LP/LS/KL/EH single-core copper rows are sold as whole prepacked spools, including
    when the Cost API says STOCK -- but only below 25mm2, since stock cable at 25mm2
    and over is cut to length (see :func:`prepack_allowed`). Other MAKE cable styles
    keep the requested cuts/lengths and use Cost API MOQ pricing when ``defaultLength``
    applies.
    """
    if result.get("item_type") and result.get("item_type") != "cable":
        return False
    if result.get("length_defaulted"):
        return False

    prepack = prepack_size_for_result(result)
    if prepack is not None:
        if not prepack_allowed(result, pricing):
            _clear_quantity_rule(result)
            return False
        return apply_prepack_length(result, prepack)

    if str((pricing or {}).get("cost_status") or "").upper() != "MAKE":
        return False
    moq = to_positive_float((pricing or {}).get("default_length"))
    return apply_default_length_moq(result, moq)


def apply_default_length_moq(result, moq, *, group_total_length=None) -> bool:
    requested = to_positive_float(group_total_length) or requested_total_length_for_quantity_rule(result)
    if not moq or not requested:
        _clear_default_length_moq(result)
        return False
    if requested >= moq:
        _clear_default_length_moq(result)
        return False

    row_requested = requested_total_length_for_quantity_rule(result) or requested
    requested_fmt = _fmt_num(requested)
    moq_fmt = _fmt_num(moq)
    result["make_moq"] = {
        "requested_total_length": int_if_whole_float(row_requested),
        "quoted_total_length": int_if_whole_float(row_requested),
        "moq": int_if_whole_float(moq),
        "source": "default_length",
        "group_total_length": int_if_whole_float(requested),
        "pricing_total_length": int_if_whole_float(requested),
    }
    # No line note. Sales asked for this off the quote (2026-09-15): the customer does
    # not need to be told the order was under our minimum, and every one of these had
    # to be deleted by hand before sending. The fact itself is not lost -- it stays on
    # the `warnings` below, which is rep-facing, and in `make_moq` for the UI.
    #
    # Still CLEARED rather than merely not written, so re-pricing a draft that already
    # carries the old note drops it. `_clear_quantity_notes` is what `_append_line_note`
    # called first anyway, so the cleanup is unchanged; only the append is gone.
    _clear_quantity_notes(result)
    warning = f"MAKE cable priced at MOQ: RQ {requested_fmt}m below {moq_fmt}m MOQ."
    _clear_quantity_warnings(result, MAKE_MOQ_WARNING_PREFIXES)
    warnings = result.setdefault("warnings", [])
    if warning not in warnings:
        warnings.append(warning)
    return True


def apply_prepack_length(result, prepack) -> bool:
    length = result.setdefault("length", {})
    requested = requested_total_length_for_quantity_rule(result)
    prepack = to_positive_float(prepack)
    if not prepack or prepack <= 1 or not requested:
        return False

    if not _prepack_applies(result):
        _clear_quantity_rule(result)
        return False

    spools = _prepack_spools(result, requested, prepack)
    quoted = spools * prepack
    quoted = int(quoted) if float(quoted).is_integer() else quoted
    current_total = to_positive_float(length.get("total_length") or length.get("total_length_m"))
    current_cuts = to_positive_float(length.get("number_of_lengths"))
    current_each = to_positive_float(length.get("length_each") or length.get("length_each_m"))
    requested_fmt = _fmt_num(requested)
    quoted_fmt = _fmt_num(quoted)
    prepack_fmt = _fmt_num(prepack)
    if current_total == quoted and current_cuts == spools and current_each == prepack:
        if (result.get("make_moq") or {}).get("source") == "prepack_size":
            _append_line_note(result, _prepack_note(prepack_fmt, spools, requested_fmt, quoted_fmt, requested, quoted))
        return False

    _set_prepack_length(length, prepack, spools, quoted)
    result["make_moq"] = {
        "requested_total_length": int_if_whole_float(requested),
        "quoted_total_length": quoted,
        "moq": int_if_whole_float(prepack),
        "source": "prepack_size",
        "quoted_number_of_lengths": spools,
        "quoted_length_each": int_if_whole_float(prepack),
    }
    _append_line_note(result, _prepack_note(prepack_fmt, spools, requested_fmt, quoted_fmt, requested, quoted))
    warnings = result.setdefault("warnings", [])
    warning = f"Prepack cable quoted as whole spools: {requested_fmt}m -> {spools} x {prepack_fmt}m ({quoted_fmt}m total)."
    if warning not in warnings:
        warnings.append(warning)
    return True


def _prepack_applies(result) -> bool:
    """Whether whole-spool rounding may rewrite this row's lengths.

    Asked-for cuts win over prepack. A stated run ("4 x 55m", a schedule's route
    length) is a continuous length the customer needs end to end; spools cannot be
    joined to make one, so rounding 55 m up to 2 x 50 m would quote cable that
    cannot do the job. Those rows are quoted as cut, and cut to length.

    Only a bulk figure -- "700m of 2.5mm2", no cuts named -- defaults to prepack.
    """
    return not (result.get("length") or {}).get("cuts_explicit")


def _prepack_spools(result, requested, prepack) -> int:
    """How many whole spools a bulk prepack row needs.

    A bulk figure at or under the spool size is one spool (120 m of a 200 m
    prepack is a 200 m spool). Above it, it is made up from whole spools: 700 m
    of 2.5 mm2 panel wire over a 200 m spool is 4 spools.
    """
    each, count = _requested_cuts_for_prepack(result)
    if each and count and each <= prepack:
        return count
    return ceil(requested / prepack)


def _requested_cuts_for_prepack(result) -> tuple[float | None, int | None]:
    """The cuts as the customer asked for them, not as a previous pass rewrote them."""
    length = result.get("length") or {}
    each = to_positive_float(length.get("length_each") or length.get("length_each_m"))
    count = to_positive_float(length.get("number_of_lengths"))
    if each and count:
        return each, int(count)
    return None, None


def _prepack_note(prepack_fmt: str, spools: int, requested_fmt: str, quoted_fmt: str, requested, quoted) -> str:
    breakdown = f"{spools} x {prepack_fmt}m"
    if float(requested) == float(quoted):
        return f"({breakdown})"
    return f"RQ {requested_fmt}m -> {quoted_fmt}m ({breakdown})"


def _prepack_fields_for_result(result) -> dict:
    """The style/cores/conductor/size the prepack rules read, from the product code
    when it parses, else from the selected product / extracted fields."""
    parsed_code = parsed_code_fields_for_result(result)
    if parsed_code:
        return parsed_code

    product = result.get("selected_product") or {}
    fields = result.get("fields") or {}
    return {
        "style": product.get("style") or fields.get("style"),
        "count": product.get("no_core") or fields.get("count"),
        "conductor": product.get("conductor_type") or fields.get("conductor"),
        "cross_section": product.get("cross_core") or fields.get("cross_section"),
    }


def prepack_size_for_result(result) -> int | None:
    return prepack_size_for_fields(_prepack_fields_for_result(result))


def prepack_cross_section(result) -> float | None:
    try:
        return float(_prepack_fields_for_result(result).get("cross_section"))
    except (TypeError, ValueError):
        return None


def prepack_allowed(result, pricing) -> bool:
    """Whether whole-spool prepack rounding may apply to this row at all.

    Stock cable of 25mm2 and over is cut to length off a drum, so no minimum order
    applies to it (confirmed by Manusha 2026-08-03). Prepack rounding is kept for MAKE
    cable at any size, and for stock cable below 25mm2 -- the 200m/100m panel and
    building-wire spools, which really are sold pre-packed.

    An unknown cost status means the price lookup failed or found nothing, so there is
    no stock/make answer to act on. The customer's quantities are then left as asked:
    rounding them on a guess rewrote "4 x 55 m" as 5 x 50 m spools whenever the Cost API
    was down (owner decision 2026-09-29). The rule applies when the line is priced again
    with a real answer.
    """
    status = str((pricing or {}).get("cost_status") or "").upper()
    if not status:
        return False
    if status == "MAKE":
        return True
    size = prepack_cross_section(result)
    if size is None:
        return True
    return size < PREPACK_STOCK_MAX_CSA


def parsed_code_fields_for_result(result) -> dict | None:
    product = result.get("selected_product") or {}
    code = result.get("final_product_code") or result.get("selected_product_code") or product.get("product_code")
    parsed = parse_code(str(code or ""))
    return parsed if parsed.get("valid") else None


def prepack_size_for_fields(fields: dict) -> int | None:
    style = str(fields.get("style") or "").upper()
    if style not in {"LP", "LS", "KL", "EH"}:
        return None

    no_core = fields.get("count")
    try:
        if no_core not in ("", None) and int(float(no_core)) > 1:
            return 1
    except (TypeError, ValueError):
        pass

    conductor = str(fields.get("conductor") or "").upper()
    if conductor == "A":
        return 1

    size = fields.get("cross_section")
    try:
        size = float(size)
    except (TypeError, ValueError):
        return 1
    return get_prepack_size(style, size)


def get_prepack_size(style: str, size: float) -> int:
    style = str(style or "").upper()
    if style in {"LP", "LS"}:
        if 0.5 <= size <= 2.5:
            return 200
        if 4 <= size <= 16:
            return 100
        if 25 <= size <= 95:
            return 50
        return 1
    if style == "KL":
        if 0.6 <= size <= 16:
            return 100
        if 25 <= size <= 120:
            return 50
        return 1
    if style == "EH":
        if 6 <= size <= 16:
            return 100
        if 25 <= size <= 120:
            return 50
        return 1
    return 1


def _set_total_length(length: dict, total_length):
    length["total_length"] = total_length
    length["total_length_m"] = total_length
    cuts = to_positive_float(length.get("number_of_lengths")) or 1
    length["number_of_lengths"] = int(cuts) if float(cuts).is_integer() else cuts
    each = float(total_length) / float(cuts)
    length["length_each"] = int(each) if each.is_integer() else round(each, 5)
    length["length_each_m"] = length["length_each"]
    length.setdefault("unit", length.get("uom") or "m")


def _set_prepack_length(length: dict, prepack, spools, total_length):
    prepack = int_if_whole_float(prepack)
    length["number_of_lengths"] = spools
    length["length_each"] = prepack
    length["length_each_m"] = prepack
    length["total_length"] = total_length
    length["total_length_m"] = total_length
    length.setdefault("unit", length.get("uom") or "m")


def requested_total_length_for_quantity_rule(result):
    existing = result.get("make_moq") or {}
    requested = to_positive_float(existing.get("requested_total_length"))
    if requested:
        return requested
    length = result.get("length") or {}
    return to_positive_float(length.get("total_length") or length.get("total_length_m"))


def _append_line_note(result, note: str):
    _clear_quantity_notes(result)
    current = str(result.get("line_note") or "").strip()
    segments = [segment.strip() for segment in re.split(r"\s+-\s+", current) if segment.strip()]
    segments.append(note)
    result["line_note"] = " - ".join(segments)


def _clear_quantity_rule(result):
    _clear_quantity_notes(result)
    _clear_quantity_warnings(result)
    result.pop("make_moq", None)


def _clear_default_length_moq(result):
    _clear_line_note_segments(
        result,
        lambda segment: segment.startswith(MAKE_MOQ_NOTE_PREFIX) or bool(MAKE_MOQ_SIMPLE_NOTE_RE.match(segment)),
    )
    _clear_quantity_warnings(result, MAKE_MOQ_WARNING_PREFIXES)
    if (result.get("make_moq") or {}).get("source") == "default_length":
        result.pop("make_moq", None)


def _clear_quantity_notes(result):
    current = str(result.get("line_note") or "").strip()
    if not current:
        return
    _clear_line_note_segments(result, lambda segment: _is_quantity_note_segment(result, segment))


def _is_quantity_note_segment(result, segment: str) -> bool:
    if any(segment.startswith(prefix) for prefix in QUANTITY_NOTE_PREFIXES):
        return True
    if MAKE_MOQ_SIMPLE_NOTE_RE.match(segment) or PREPACK_ROUNDING_NOTE_RE.match(segment):
        return True
    return (
        PREPACK_BREAKDOWN_NOTE_RE.match(segment)
        and (result.get("make_moq") or {}).get("source") == "prepack_size"
    )


def _clear_line_note_segments(result, predicate):
    current = str(result.get("line_note") or "").strip()
    segments = [segment.strip() for segment in re.split(r"\s+-\s+", current) if segment.strip()]
    if not segments:
        return
    kept = [segment for segment in segments if not predicate(segment)]
    if len(kept) != len(segments):
        result["line_note"] = " - ".join(kept)


def _clear_quantity_warnings(result, prefixes=QUANTITY_WARNING_PREFIXES):
    if not isinstance(result.get("warnings"), list):
        return
    result["warnings"] = [
        warning for warning in result["warnings"]
        if not any(str(warning or "").startswith(prefix) for prefix in prefixes)
    ]


def int_if_whole_float(value):
    try:
        number = float(value)
    except (TypeError, ValueError):
        return value
    return int(number) if number.is_integer() else number


def calculate_final_unit_price(unit_price, margin_percent):
    # TriCab uses margin-on-sell: sell = cost * 100 / (100 - margin), NOT markup
    # (cost * (1 + margin/100)). Matches computeCabPrice/computeAccPrice in the
    # website's ProductConfigurator. Margin must be < 100.
    if unit_price in (None, ""):
        return None
    try:
        cost = float(unit_price)
        margin = float(margin_percent or 0)
    except (TypeError, ValueError):
        return None
    # Margins are whole numbers, truncated like 4js truncates its derived mgn%
    # (a fractional margin would always display one lower there). Keeps old
    # drafts with fractional stored margins pricing the same as the portal UI,
    # which truncates at entry (app.js normalizeMargin).
    if margin > 0:
        margin = math.trunc(margin)
    denominator = 100 - margin
    if denominator <= 0:
        return None
    # Round the sell price UP at the 3rd decimal, not to nearest. 4js derives its
    # mgn% from price/cprc and TRUNCATES to an integer (legacy display; changing
    # it would restate historical quotes), so a nearest-rounded price can leave
    # the derived margin a hair under the entered one (33% -> 32.9997% -> "32").
    # Ceiling keeps the derived margin >= the entered margin so truncation lands
    # exactly on it. The epsilon absorbs float noise so a price already on a
    # 3-decimal boundary is not bumped a mil. Mirrored in app.js
    # recalculateSellingPrice and rfq_api.php rfq_sell_price.
    return math.ceil(cost * 100 / denominator * 1000 - 1e-6) / 1000


def calculate_margin_percent(unit_price, sell_price):
    """Derive the margin a given sell price implies, the inverse of
    ``calculate_final_unit_price``.

    Used when the salesperson types a sell price directly instead of a margin.
    Truncated to a whole number because that is exactly what 4js will show when
    it derives mgn% from price/cprc -- so the portal and 4js agree on the figure
    even though the stored sell price keeps its full precision. A cost above the
    sell price yields a negative margin, which is surfaced rather than clamped:
    selling under cost is a real (if unusual) decision the rep should see.
    """
    if unit_price in (None, "") or sell_price in (None, ""):
        return None
    try:
        cost = float(unit_price)
        sell = float(sell_price)
    except (TypeError, ValueError):
        return None
    if sell <= 0:
        return None
    return math.trunc((1 - cost / sell) * 100)


def calculate_line_total(length, unit_price):
    if unit_price in (None, ""):
        return None
    total_length = length.get("total_length") or length.get("total_length_m")
    if total_length in (None, ""):
        return None
    try:
        return round(float(total_length) * float(unit_price), 2)
    except (TypeError, ValueError):
        return None


# --- Length / quantity helpers -------------------------------------------------
def has_explicit_zero_length(result):
    """Whether the customer explicitly asked for zero of this line.

    Only when EVERY length signal is zero: a zero next to a positive signal is
    parser noise, not intent -- a schedule's empty "UNIT RATE | TOTAL" costing
    cells read as a 0m length while the real quantity column says 121m, and
    dropping on any single zero silently discarded all 77 such rows of a real
    submains schedule."""
    length = result.get("length") or {}
    fields = result.get("fields") or {}
    table_quantity = result.get("table_quantity") or {}
    length_keys = ("length_each", "length_each_m", "total_length", "total_length_m")
    values = [length.get(key) for key in length_keys if key in length]
    values.extend(fields.get(key) for key in length_keys if key in fields)
    if "value" in table_quantity:
        values.append(table_quantity.get("value"))
    return any(is_zero(value) for value in values) and not any(
        is_positive(value) for value in values
    )


def is_zero(value):
    if value in ("", None):
        return False
    try:
        return float(value) == 0
    except (TypeError, ValueError):
        return False


def is_positive(value):
    try:
        return float(value) > 0
    except (TypeError, ValueError):
        return False


def earth_inherited_markers(entry_text, entries):
    """Construction context a separated earth line inherits from its sibling cables.

    A small standalone earth defaults to LS, while an earth paired with ordinary mains
    cable uses LP. ``PAIRED MAINS CABLE`` preserves that relationship after a compound
    request has been split into separate resolver calls. A fire-rated/Radox sibling still
    overrides the paired LP default; mains temperature alone does not."""
    if len(entries) <= 1 or not re.search(r"\b(?:EARTH|ECC|G/Y|GN\s*/\s*YE|GREEN\s*/?\s*YELLOW)\b", entry_text, re.I):
        return []
    siblings = [other.text for other in entries if other.text != entry_text]
    # Reaching this point with several SplitEntries already proves both sides passed
    # splitter.has_cable_facts; an accessory/prose sibling cannot create this shape.
    markers = ["PAIRED MAINS CABLE"]
    # "RADOX" names the fire-rated range (see resolver.FIRE_RATED_RE), so a Radox main
    # cable promotes its split earth to LS just as an explicit "fire rated" one does.
    if any(re.search(r"\b(?:FIRE\s*(?:RATED|RESISTANT)|FR\s*110|RADOX)\b", text, re.I) for text in siblings):
        markers.append("FIRE RATED")
    return markers


def new_llm_budget(line_count=0):
    """Per-request LLM call budget. Scales with the number of RFQ lines so a large
    sheet isn't starved (each line can need a fallback call, and some split into
    several). The env var sets a floor; a free model means we can afford to be
    generous. Set OPENROUTER_MAX_CALLS_PER_REQUEST=0 to disable the LLM entirely."""
    try:
        floor = max(0, int(os.environ.get("OPENROUTER_MAX_CALLS_PER_REQUEST", "50")))
    except ValueError:
        floor = 50
    # Allow up to ~3 calls per line (covers compound-line splits) above the floor,
    # unless the floor was explicitly set to 0 to switch the LLM off.
    limit = floor if floor == 0 else max(floor, line_count * 3)
    return {"used": 0, "limit": limit}


def apply_table_quantity(result, metadata):
    fields = result.setdefault("fields", {})
    length = result.setdefault("length", {})
    # A spool length embedded directly in the product code itself (e.g.
    # "...EA200" -- see find_exact_product_code) is a real length signal but a
    # less specific one than a dedicated quantity column/pipe-length, which wins
    # outright when both are present; the two are only flagged for the rep to
    # confirm when they disagree, never silently reconciled.
    code_length = fields.get("exact_product_code_length_m")

    quantity = metadata.get("table_quantity")
    if quantity in ("", None):
        if code_length is not None and not length.get("length_each"):
            length["length_each"] = code_length
            fields["length_each"] = code_length
        return

    unit = metadata.get("table_unit") or ""
    if str(result.get("line_kind") or result.get("item_type") or "").lower() == "accessory":
        requested = quantity
        quantity = accessory_sell_units(quantity)
        fields["quantity"] = quantity
        fields["number_of_lengths"] = quantity
        length["length_each"] = 1
        length["number_of_lengths"] = quantity
        length["total_length"] = quantity
        if unit:
            length["unit"] = unit
            fields["unit"] = unit
        result["table_quantity"] = {
            "value": requested,
            "unit": unit,
            "header": metadata.get("table_quantity_header") or "",
            "raw": metadata.get("table_quantity_raw") or "",
        }
        return

    runs = metadata.get("number_of_lengths") or 1

    if code_length is not None:
        # The code already carries the cut length ("...OR200" = a 200 m reel), so a
        # plain count beside it is how many of those cuts are wanted: Qty 5 means
        # 5 x 200 m. Only a count -- a quantity in metres is a length, and a length
        # that disagrees with the code is a conflict for the rep, not a multiplier.
        if not _table_quantity_is_a_length(metadata):
            cuts = int_if_whole_float(to_positive_float(quantity)) or 1
            total = float(code_length) * float(cuts)
            length["length_each"] = code_length
            length["number_of_lengths"] = cuts
            length["total_length"] = int(total) if float(total).is_integer() else total
            length["cuts_explicit"] = True
            length.setdefault("unit", "m")
            fields["length_each"] = code_length
            fields["number_of_lengths"] = cuts
            fields["total_length"] = length["total_length"]
            fields["cuts_explicit"] = True
            result["table_quantity"] = {
                "value": quantity,
                "unit": unit,
                "header": metadata.get("table_quantity_header") or "",
                "raw": metadata.get("table_quantity_raw") or "",
            }
            return

        try:
            differs = float(code_length) != float(quantity)
        except (TypeError, ValueError):
            differs = False
        if differs:
            warnings = result.setdefault("warnings", [])
            warning = (
                f"Product code implies a {_fmt_num(code_length)}m length but the sheet's "
                f"quantity says {_fmt_num(quantity)}{unit or 'm'}; confirm which is correct."
            )
            if warning not in warnings:
                warnings.append(warning)

    # A route header alone cannot distinguish an original cut length from a
    # recomputed total. Producers must label multi-cut quantities before matching.
    # Do not guess either interpretation: clear inferred lengths and require review.
    basis = str(metadata.get("quantity_basis") or "").strip().lower()
    effective_runs = to_positive_float(runs) or 1
    effective_runs = max(effective_runs, to_positive_float(length.get("number_of_lengths")) or 1,
                         to_positive_float(fields.get("number_of_lengths")) or 1)
    if effective_runs > 1 and basis not in {"per_cut", "per_reel", "total"} and (
        basis or not _table_quantity_is_already_total(metadata)
    ):
        for target in (length, fields):
            for key in ("length_each", "length_each_m", "total_length", "total_length_m", "quantity"):
                target.pop(key, None)
        result["status"] = "needs_clarification"
        warning = ("Quantity basis unclear: confirm whether the stated quantity is per cut "
                   "or the total across all cuts; no length has been calculated.")
        warnings = result.setdefault("warnings", [])
        if warning not in warnings:
            warnings.append(warning)
        result["table_quantity"] = {
            "value": quantity, "unit": unit,
            "header": metadata.get("table_quantity_header") or "",
            "raw": metadata.get("table_quantity_raw") or "",
        }
        return

    # A quantity that is ALREADY the total across every cut must be DIVIDED by the cut
    # count, never multiplied by it. The tail of this function multiplies
    # `quantity * number_of_lengths` unconditionally, which is right when the column is a
    # per-cut length and catastrophic when it is a total: a `No. of Lengths 4 | Total
    # Length (m) 1600` row quoted 6400 m and asked the Cost API to price 6400 m for a
    # 1600 m requirement.
    #
    # This is worst on the reader path, where terra's own contract guarantees a total
    # ("A column that already states a total is the answer as it stands. Never multiply
    # it.") and it reports the cut count separately. Verified live 2026-09-09: terra
    # returned `quantity 1600, cuts 4` with the note "it was not multiplied by No. of
    # Lengths", and the pipeline multiplied anyway. Nothing read that note --
    # `metadata["reader_note"]` has no consumers.
    #
    # Dividing also gives the comment its `(CUTS x EACH)` for free: 1600/4 = 400.
    if _table_quantity_is_already_total(metadata):
        stated_cuts = to_positive_float(runs)
        if stated_cuts and stated_cuts > 1:
            try:
                each = float(quantity) / stated_cuts
            except (TypeError, ValueError, ZeroDivisionError):
                each = None
            if each is not None:
                # The TOTAL is kept exactly as the sheet stated it, because that is what
                # prices; only the per-cut length is derived. Rounded to 3dp so a
                # non-divisible split cannot leak `333.3333333333333` into the 4js
                # comment -- `_fmt_num` stringifies floats and does not round.
                length["total_length"] = int_if_whole_float(quantity)
                fields["total_length"] = length["total_length"]
                if not length.get("length_each"):
                    length["length_each"] = int_if_whole_float(round(each, 3))
                if not fields.get("length_each"):
                    fields["length_each"] = length["length_each"]
                # A total that does not divide by its own cut count is not a cut plan we
                # can hand a factory -- 1000 m in 3 cuts is 3 x 333.333. The split is
                # left uneven rather than rounded (the same call `extract_quantity`
                # makes: rounding here would silently change what gets shipped), so say
                # so instead of presenting the derivation as clean.
                if abs(each - round(each)) > 0.001:
                    warnings = result.setdefault("warnings", [])
                    warning = (
                        f"Stated total {_fmt_num(quantity)}m does not divide evenly into "
                        f"{_fmt_num(stated_cuts)} cuts ({_fmt_num(length['length_each'])}m each); "
                        "confirm the cut lengths."
                    )
                    if warning not in warnings:
                        warnings.append(warning)

    if not length.get("length_each"):
        length["length_each"] = quantity
    if not fields.get("length_each"):
        fields["length_each"] = quantity
    if runs not in ("", None, 1, 1.0) and not fields.get("number_of_lengths"):
        length["number_of_lengths"] = runs
        fields["number_of_lengths"] = runs
    elif not length.get("number_of_lengths"):
        length["number_of_lengths"] = runs
    if not fields.get("number_of_lengths"):
        fields["number_of_lengths"] = length["number_of_lengths"]
    if unit:
        length["unit"] = unit
        fields["unit"] = unit

    number_of_lengths = length.get("number_of_lengths") or fields.get("number_of_lengths")
    if number_of_lengths and not length.get("total_length"):
        try:
            total = float(quantity) * float(number_of_lengths)
            length["total_length"] = int(total) if total.is_integer() else total
            fields["total_length"] = length["total_length"]
        except (TypeError, ValueError):
            pass

    if _table_length_is_a_run(metadata):
        length["cuts_explicit"] = True
        fields["cuts_explicit"] = True

    result["table_quantity"] = {
        "value": quantity,
        "unit": unit,
        "header": metadata.get("table_quantity_header") or "",
        "raw": metadata.get("table_quantity_raw") or "",
    }


# A schedule's "Length" / "Route Length" column is the distance a cable runs, so each
# row is a continuous cut. A "Qty" / "Total Length" column is a bulk figure. The two
# look identical once they are numbers, so the distinction comes from the header (and
# the cell's own unit) that the file-parser recorded. See _prepack_applies: cuts are
# quoted as cut, bulk rounds up to whole spools.
def _table_quantity_is_a_length(metadata: dict) -> bool:
    metadata = metadata or {}
    if str(metadata.get("table_unit") or "").strip().lower() in {"m", "mtr", "mtrs", "metre", "metres"}:
        return True
    return is_metre_valued(
        str(metadata.get("table_quantity_header") or ""),
        str(metadata.get("table_quantity_raw") or ""),
    )


# Metres alone does not make a cut: "QUANTITY (In Metres) 45" is 45 m of bulk wire,
# not a 45 m run. A run is named by a *route* header ("Length", "Cable Length"), or
# by a cut count sitting beside the length.
_ROUTE_HEADER_RE = re.compile(r"\blength\b|\broute\b|\brun\b", re.I)
_BULK_HEADER_RE = re.compile(r"\btotal\b|\bqty\b|\bquantity\b", re.I)


# Whether `table_quantity` already counts every cut, so a cut count beside it divides it
# rather than multiplying it.
#
# Two independent signals, because the two producers speak differently. The reader states
# it outright (`quantity_basis`), since terra's instructions define `quantity` as the total
# metres and nothing about a header is available on that path. The file-parser now
# labels its selected value too. Legacy total headers remain a fallback, using
# the same vocabulary `_table_length_is_a_run` already uses to mean
# "bulk, not a run" -- note that function checks the cut count FIRST and returns True, so
# it calls a bulk column with a cut count a run; that ordering is exactly what let the
# multiplication through, and it is why this asks the question separately.
#
# `select_quantity` has already preferred a genuine per-run column when the row had one
# (its reconcile branch), so a column still labelled total/qty here is a total.
def _table_quantity_is_already_total(metadata: dict) -> bool:
    metadata = metadata or {}
    basis = str(metadata.get("quantity_basis") or "").strip().lower()
    if basis in {"per_cut", "per_reel"}:
        return False
    if basis == "total":
        return True
    header = normalize_header(str(metadata.get("table_quantity_header") or ""))
    return bool(header and _BULK_HEADER_RE.search(header))


def _table_length_is_a_run(metadata: dict) -> bool:
    metadata = metadata or {}
    if to_positive_float(metadata.get("number_of_lengths") or 0) not in (None, 1, 1.0):
        return True  # the sheet named a cut count outright
    header = normalize_header(str(metadata.get("table_quantity_header") or ""))
    if _BULK_HEADER_RE.search(header):
        return False  # a total or a quantity is bulk, whatever unit it is in
    return bool(_ROUTE_HEADER_RE.search(header))


def accessory_sell_units(value):
    quantity = to_positive_float(value)
    if quantity is None:
        return 1
    return int_if_whole_float(quantity)


DEFAULT_LENGTH_ASSUMPTION = "Length not stated; defaulted to 1m — confirm quantity before quoting."


def _quantity_basis_unclear(result):
    return any(str(w).startswith("Quantity basis unclear:")
               for w in result.get("warnings", []))


def apply_default_cable_length(result):
    """Price a cable that states no length at all as a 1m unit-rate line.

    Customers routinely ask for a per-metre price without naming a quantity. For
    a cable -- never a per-piece accessory (lug/gland/heat-shrink), which is why
    this is gated on item_type == "cable" -- default the length to 1m so the line
    still prices, the way a rep would quote a unit rate. It only fires when there
    is NO length signal at all: a stated length (or a table quantity, already
    folded into length_each by apply_table_quantity before this runs) always wins,
    and an explicit zero length is dropped upstream by has_explicit_zero_length,
    so this never turns a real 0 into 1. The assumption is flagged as a warning so
    the rep can tell an assumed 1m from a genuine 1m request and edit it.
    """
    if result.get("item_type") != "cable" or _quantity_basis_unclear(result):
        return
    length = result.setdefault("length", {})
    if any(
        length.get(key) not in (None, "")
        for key in ("length_each", "length_each_m", "total_length", "total_length_m")
    ):
        return
    # A feeder with a known run/cable count but no stated length (e.g. "3x4x1c+e")
    # still defaults each run to 1m, but the total is that 1m across every run so
    # the count isn't silently collapsed to a single unit line.
    runs = length.get("number_of_lengths") or 1
    length["length_each_m"] = 1
    length["number_of_lengths"] = runs
    length["total_length_m"] = runs
    if not length.get("uom"):
        length["uom"] = "m"
    # Record that the length was defaulted (not customer-stated) so the AI-routing
    # gate can still tell a length was originally missing even after this ran -- the
    # batch normalizer's pre-filter inspects the trial result after this default has
    # already masked the empty length as 1 (see _cable_length_missing in llm_fallback).
    result["length_defaulted"] = True
    warnings = result.setdefault("warnings", [])
    if DEFAULT_LENGTH_ASSUMPTION not in warnings:
        warnings.append(DEFAULT_LENGTH_ASSUMPTION)
