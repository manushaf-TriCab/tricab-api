"""An email's body into work items, and the lines of a whole email merged (plan M2.3).

Platform-owned and pure: no Flask, no I/O, no model. `contract.parse` calls it for the
`email` input of `POST /v1/parse`; the attachments go through the same document path as
a plain `documents[]` request, so a file is read one way whether it arrives on its own or
inside an email.

**The body is read as the source's `process_message` reads it** (and each text line says which part of a forwarded or quoted chain it came from, `source.chain_part`, when that is certain). HTML tables become work
items from their cells (`extract_items_from_body_html`), the text with those tables
removed goes through the line reader (`split_body_text`), and an HTML body whose tables
yielded nothing (a layout table around a letter) is read as flattened text, so nothing is
lost to a signature banner. The source's optional model read of the body
(`RFQ_READER_TEXT_ENABLED`) is not used here: a model read is a reader job (`/v1/read`).

**Accounting.** Every work item is one input row of the pseudo-file `email body`, kept or
excluded by what it matched. Body text the line reader could not account for (its
`residue`) is counted as skipped rows with that reason and listed, so a requirement stated
in a sentence is visible as unread instead of vanishing. Greetings, headers, signatures and
disclaimers (`consumed`) are scaffolding, not rows.

**Merging.** Attachments are read first and win; a body line is dropped when an attachment
already carries it (same product and total length, or the same text). A later attachment loses
lines only when it is a copy of earlier ones (nearly all its lines repeat), never for a partial
overlap. Two identical lines inside one source are both kept: a schedule may genuinely repeat itself.
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from typing import Any

from . import accounting, mail_envelope, mail_ingest
from .quote_readiness import exclusion_reason

BODY_FILE = "email body"
MAX_RESIDUE_LISTED = 50
RESIDUE_TEXT_CHARS = 300
SKIP_RESIDUE = "email text that was not read as a quote line"


@dataclass
class BodyRead:
    items: list[dict[str, Any]]
    table_count: int
    residue: list[dict[str, Any]] = field(default_factory=list)
    labels: dict[str, str] = field(default_factory=dict)
    notes: list[str] = field(default_factory=list)

    def rows(self) -> list[accounting.Row]:
        rows = [accounting.Row((BODY_FILE, "", n), "line") for n in range(1, len(self.items) + 1)]
        for entry in self.residue:
            rows.append(accounting.Row((BODY_FILE, "", 10_000_000 + int(entry["index"])), "skipped", SKIP_RESIDUE))
        return rows


def read_body(envelope: dict[str, Any]) -> BodyRead:
    """The envelope's body as work items. `envelope` is normalised (`mail_envelope.normalise`)."""
    html = mail_envelope.body_html(envelope)
    text = mail_envelope.body_plain(envelope)
    table_items = mail_ingest.extract_items_from_body_html(html) if html else []
    # `process_message`: with table rows read, the table markup is taken out of the text the
    # line reader sees, so a cell is never read twice; without any, the flattened text stands.
    fallback = mail_ingest.html_to_text(mail_ingest.strip_html_tables(html)) if table_items and html else text
    split = mail_ingest.split_body_text(fallback)

    parts = _chain_parts(fallback, envelope["sender"], split.items)
    items: list[dict[str, Any]] = []
    for number, item in enumerate([*table_items, *split.items], start=1):
        source = dict(item.get("source") or {})
        position = number - len(table_items) - 1
        if parts is not None and position >= 0:
            source["chain_part"] = parts[position]
        for key in ("row_number", "line_number"):
            if key in source:
                source["segment_" + key] = source.pop(key)
        source.update({"file_name": BODY_FILE, "row_number": number})
        items.append({**item, "source": source})

    residue = [{"index": s.index, "text": s.text[:RESIDUE_TEXT_CHARS]} for s in split.residue]
    notes = []
    if residue:
        notes.append(f"{len(residue)} line(s) of the email text were not read as quote lines.")
    return BodyRead(items=items, table_count=len(mail_ingest.parse_html_tables(html)) if html else 0, residue=residue, labels=split.labels, notes=notes)


def _chain_parts(text: str, sender: dict[str, str], text_items: list[dict[str, Any]]) -> list[int] | None:
    """For each line read from the body text, the forwarded-chain part (`mail_envelope.split_forwarded_chain`,
    0 the message itself) it came from, or None when that cannot be told exactly.

    A reply carries the quoted earlier message, and its lines are read again: the same
    RFQ twice, or last week's lines beside this week's addition. The source reads the body
    whole and never says which. Here each part is read on its own with the same line reader
    and the answer is used only when the parts' lines, end to end, are exactly the whole
    body's lines; a reader state that leaks across a boundary (a circuit label above a
    forwarded header) makes them differ and the attribution is withheld rather than guessed.
    """
    chain = mail_envelope.split_forwarded_chain(text, sender)
    by_part = [[str(item["text"]) for item in mail_ingest.split_body_text(part["text"]).items] for part in chain["parts"]]
    flat = [index for index, texts in enumerate(by_part) for _ in texts]
    if [t for texts in by_part for t in texts] != [str(item["text"]) for item in text_items]:
        return None
    return flat


# --- merging -----------------------------------------------------------------------------------

_WORD = re.compile(r"[^a-z0-9]+")


def _text_key(line: dict[str, Any]) -> str:
    source = line.get("source") or {}
    text = str(source.get("original_text") or line.get("raw_text") or line.get("input") or "")
    return _WORD.sub("", text.lower())


def _code_key(line: dict[str, Any]) -> tuple[str, float] | None:
    code = str(line.get("final_product_code") or line.get("selected_product_code") or "").strip().upper()
    length = line.get("length") or {}
    total = length.get("total_length") or length.get("total_length_m")
    try:
        total_number = float(total)
    except (TypeError, ValueError):
        return None
    return (code, total_number) if code and total_number > 0 else None


def _origin(line: dict[str, Any]) -> str:
    return str((line.get("source") or {}).get("file_name") or "")


# A later attachment is a copy of earlier ones (the same schedule as a spreadsheet and as a PDF)
# only when nearly all of its lines are already there. A share of its lines repeating is not
# evidence of that: two drawings' schedules legitimately hold the same cable at the same length,
# and dropping one of them would drop a requirement. In the 2026 corpus a rule that dropped
# every repeated (product, length) took 167 of 724 lines out of one real RFQ's attachments.
ATTACHMENT_COPY_SHARE = 0.9


def _runs(lines: list[dict[str, Any]]) -> list[tuple[str, list[dict[str, Any]]]]:
    runs: list[tuple[str, list[dict[str, Any]]]] = []
    for line in lines:
        origin = _origin(line)
        if runs and runs[-1][0] == origin:
            runs[-1][1].append(line)
        else:
            runs.append((origin, [line]))
    return runs


def merge_lines(lines: list[dict[str, Any]], coverage: accounting.Coverage) -> tuple[list[dict[str, Any]], int]:
    """`lines` (attachments first, the body last, each source's lines together) without the
    ones an earlier source already carries; and how many were dropped.

    A body line is dropped when an attachment already carries it: the same product at the same
    total length, or the same text. An attachment's lines are dropped only when it is a copy of
    earlier ones (`ATTACHMENT_COPY_SHARE` of its lines repeat earlier lines), and then only the
    repeated ones. Identical lines inside one source are all kept: a schedule may repeat itself.

    A dropped line moves from `kept` (or `excluded`) to `skipped` in `coverage`, with its
    reason, so the counts still add up and the drop is visible."""
    kept: list[dict[str, Any]] = []
    seen_codes: dict[tuple[str, float], str] = {}
    seen_texts: dict[str, str] = {}
    dropped = 0

    def earlier_source(line: dict[str, Any], origin: str) -> str | None:
        code_key, text_key = _code_key(line), _text_key(line)
        found = seen_codes.get(code_key) if code_key else None
        found = found or (seen_texts.get(text_key) if text_key else None)
        return found if found and found != origin else None

    for origin, run in _runs(lines):
        matches = [earlier_source(line, origin) for line in run]
        if origin != BODY_FILE and sum(1 for m in matches if m) < ATTACHMENT_COPY_SHARE * len(run):
            matches = [None] * len(run)
        for line, earlier in zip(run, matches):
            if earlier:
                dropped += 1
                _move_to_skipped(coverage, line, earlier)
            else:
                kept.append(line)
        for line in run:
            code_key, text_key = _code_key(line), _text_key(line)
            if code_key:
                seen_codes.setdefault(code_key, origin)
            if text_key:
                seen_texts.setdefault(text_key, origin)
    return kept, dropped


def _move_to_skipped(coverage: accounting.Coverage, line: dict[str, Any], earlier: str) -> None:
    if exclusion_reason(line) and coverage.excluded > 0:
        coverage.excluded -= 1
    elif coverage.kept > 0:
        coverage.kept -= 1
    else:
        return
    coverage.skipped += 1
    if len(coverage.skipped_rows) < accounting.MAX_LISTED_ROWS:
        source = line.get("source") or {}
        coverage.skipped_rows.append(
            {
                "fileName": source.get("file_name") or None,
                "sheet": source.get("sheet") or None,
                "row": source.get("row_number") if isinstance(source.get("row_number"), int) else None,
                "reason": f"the same line was already read from {earlier}",
            }
        )
