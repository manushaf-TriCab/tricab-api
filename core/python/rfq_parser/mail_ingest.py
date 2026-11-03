# Generated from the RFQ parser's matcher/mail_ingest.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
"""Mail reading: one email into text, tables, quote lines, a sender and loop guards.

The source's `mail_ingest` helpers that read an email, cut out whole. The platform's own
`mail_envelope` and `mail_parse` call them; Graph, replies, drafts, the audit store and
the 4js push stay in the source (see `_unported`). See `tools/sync_rfq_parser.py`.
"""

from __future__ import annotations

import base64
import binascii
import html
import os
import re
from dataclasses import dataclass
from html.parser import HTMLParser
from pathlib import Path
from typing import Any

from tricab_cable_rules.extractor import (
    BARE_QUANTITY_LINE_RE,
    CABLE_SIGNAL_RE,
    CIRCUIT_LABEL_LINE_RE,
    DRUM_RE,
    LENGTH_RE,
    PACK_QUANTITY_RE,
    RUNS_RE,
    TRAILING_CUTS_RE,
    find_accessory_code,
    find_exact_product_code,
    looks_like_circuit_label_line,
)
from rfq_parser.file_parser import BODY_CONTEXT_DEPTH, looks_like_section_heading
from rfq_parser import pipeline

NOTIFICATION_SUBJECT_TAG = "[RFQ]"

INTERNAL_DOMAIN = os.environ.get("RFQ_INTERNAL_DOMAIN", "tricab.com")


_IMAGE_CONTENT_TYPES = {"image/png", "image/jpeg", "image/jpg", "image/webp"}


_TAG_RE = re.compile(r"<[^>]+>")
_SCRIPT_STYLE_RE = re.compile(r"<(script|style)\b.*?</\1>", re.I | re.S)
_BLOCK_BREAK_RE = re.compile(r"(?i)<\s*(br|/p|/div|/tr|/li|/h[1-6])\s*/?>")


def html_to_text(content: str) -> str:
    """Best-effort HTML → text without a parser dependency. Good enough to read
    a forwarded RFQ body and its quoted headers."""
    if not content:
        return ""
    text = _SCRIPT_STYLE_RE.sub(" ", content)
    text = _BLOCK_BREAK_RE.sub("\n", text)
    text = _TAG_RE.sub("", text)
    text = html.unescape(text)
    # &nbsp; and friends unescape to non-breaking/zero-width spaces; normalize to
    # plain spaces so downstream matching/whitespace handling behaves.
    text = text.replace("\xa0", " ").replace("​", "").replace(" ", " ").replace(" ", " ")
    # Collapse runs of blank lines / trailing spaces.
    lines = [re.sub(r"[ \t]+", " ", line).strip() for line in text.splitlines()]
    out: list[str] = []
    for line in lines:
        if line or (out and out[-1]):
            out.append(line)
    return "\n".join(out).strip()


def message_body_text(message: dict) -> str:
    """Extract readable text from a Graph message body (HTML or plain)."""
    body = message.get("body") or {}
    content = body.get("content") or ""
    if (body.get("contentType") or "").lower() == "html":
        return html_to_text(content)
    return content.strip()


def message_body_html(message: dict) -> str:
    """Return the raw HTML body content when Graph supplied HTML."""
    body = message.get("body") or {}
    if (body.get("contentType") or "").lower() != "html":
        return ""
    return body.get("content") or ""


_TABLE_RE = re.compile(r"<table\b.*?</table>", re.I | re.S)


def strip_html_tables(content: str) -> str:
    """Remove table markup before the ordinary body-text line fallback runs."""
    return _TABLE_RE.sub("\n", content or "")


class _HtmlTableParser(HTMLParser):
    """Small HTML table reader for Outlook/Graph bodies.

    We only need cell text plus row/column spans; keeping this local avoids adding
    an HTML parser dependency to the mail worker.
    """

    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.tables: list[list[list[dict[str, Any]]]] = []
        self._depth = 0
        self._table: list[list[dict[str, Any]]] = []
        self._row: list[dict[str, Any]] = []
        self._cell: dict[str, Any] | None = None
        self._buf: list[str] = []

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        tag = tag.lower()
        if tag == "table":
            if self._depth == 0:
                self._table = []
            self._depth += 1
            return
        if self._depth != 1:
            return
        if tag == "tr":
            self._row = []
            return
        if tag in {"td", "th"}:
            attr = {key.lower(): value for key, value in attrs}
            self._cell = {
                "rowspan": _span_value(attr.get("rowspan")),
                "colspan": _span_value(attr.get("colspan")),
            }
            self._buf = []
            return
        if tag == "br" and self._cell is not None:
            self._buf.append("\n")

    def handle_endtag(self, tag: str) -> None:
        tag = tag.lower()
        if self._depth == 1 and tag in {"td", "th"} and self._cell is not None:
            text = " ".join("".join(self._buf).replace("\xa0", " ").split())
            self._cell["text"] = text
            self._row.append(self._cell)
            self._cell = None
            self._buf = []
            return
        if self._depth == 1 and tag == "tr":
            if any(cell.get("text") for cell in self._row):
                self._table.append(self._row)
            self._row = []
            return
        if tag == "table" and self._depth:
            if self._depth == 1 and self._table:
                self.tables.append(self._table)
                self._table = []
            self._depth -= 1

    def handle_data(self, data: str) -> None:
        if self._cell is not None:
            self._buf.append(data)


def _span_value(value: str | None) -> int:
    try:
        return max(1, int(value or 1))
    except ValueError:
        return 1


def parse_html_tables(content: str) -> list[list[list[str]]]:
    parser = _HtmlTableParser()
    parser.feed(content or "")
    return [_expand_table_spans(table) for table in parser.tables]


def _expand_table_spans(table: list[list[dict[str, Any]]]) -> list[list[str]]:
    expanded: list[list[str]] = []
    active: dict[int, list[Any]] = {}
    max_cols = 0

    for row in table:
        out: list[str] = []
        col = 0

        def fill_active() -> None:
            nonlocal col
            remaining, text = active[col]
            out.append(text)
            remaining -= 1
            if remaining <= 0:
                del active[col]
            else:
                active[col] = [remaining, text]
            col += 1

        for cell in row:
            while col in active:
                fill_active()
            text = str(cell.get("text") or "").strip()
            colspan = int(cell.get("colspan") or 1)
            rowspan = int(cell.get("rowspan") or 1)
            for _ in range(colspan):
                out.append(text)
                if rowspan > 1:
                    active[col] = [rowspan - 1, text]
                col += 1

        while active and col <= max(active):
            if col in active:
                fill_active()
            else:
                out.append("")
                col += 1

        max_cols = max(max_cols, len(out))
        expanded.append(out)

    for row in expanded:
        row.extend([""] * (max_cols - len(row)))
    return expanded


def extract_items_from_body_html(content: str) -> list[dict]:
    """Extract structured quote rows from HTML tables in an email body."""
    items: list[dict] = []
    for table_index, table in enumerate(parse_html_tables(content), start=1):
        drum_items = drum_matrix_table_to_items(table)
        if drum_items:
            items.extend(drum_items)
            continue
        items.extend(generic_html_table_to_items(table, table_index))
    return items


def generic_html_table_to_items(table: list[list[str]], table_index: int) -> list[dict]:
    """Parse ordinary pasted email tables with the same rules as CSV/XLSX files."""
    from rfq_parser.file_parser import _tabular_items

    parsed = _tabular_items(table, sheet_title="")
    if not parsed:
        return []
    if all((item.get("metadata") or {}).get("low_relevance_sheet") for item in parsed):
        return []

    items: list[dict] = []
    for idx, item in enumerate(parsed):
        metadata = item.get("metadata") or {}
        if metadata.get("low_relevance_sheet"):
            continue
        items.append(
            {
                "text": item["text"],
                "context": item.get("context") or [],
                "metadata": metadata,
                "source": {
                    "input_type": "email_html_table",
                    "table_index": table_index,
                    "row_number": item.get("row_number") or idx + 1,
                },
            }
        )
    return items


def drum_matrix_table_to_items(table: list[list[str]]) -> list[dict]:
    """Extract a drum schedule whose lengths live in sparse size columns."""
    header_index = _find_drum_matrix_header(table)
    if header_index is None:
        return []

    header = table[header_index]
    drum_col = _find_header_col(header, {"drum #", "drum no", "drum"})
    drum_id_col = _find_header_col(header, {"drum id"})
    desc_col = _find_header_col(header, {"cable size", "cable description", "description"})
    length_col = _find_header_col(header, {"drum lengths", "drum length", "length"})
    if drum_col is None or drum_id_col is None or desc_col is None:
        return []

    size_row = table[header_index - 1] if header_index >= 1 else []
    group_row = table[header_index - 2] if header_index >= 2 else []
    title = _table_title(table[:header_index])
    quantity_cols = _drum_matrix_quantity_cols(header, size_row, {drum_col, drum_id_col, desc_col, length_col})

    items: list[dict] = []
    for row_number, row in enumerate(table[header_index + 1 :], start=header_index + 2):
        row = _padded(row, len(header))
        drum = _cell(row, drum_col)
        drum_id = _cell(row, drum_id_col)
        description = _cell(row, desc_col)
        if not description or not re.search(r"\d+\s*(?:mm|c\b)|earth", description, re.I):
            continue

        quantities = [
            (col, _cell(row, col), _parse_positive_number(_cell(row, col)))
            for col in quantity_cols
        ]
        quantities = [(col, raw, value) for col, raw, value in quantities if value is not None]
        if not quantities and length_col is not None:
            raw = _cell(row, length_col)
            value = _parse_positive_number(raw)
            if value is not None:
                quantities = [(length_col, raw, value)]

        for col, raw_quantity, quantity in quantities:
            header_text = _quantity_header(group_row, size_row, col)
            reference = " ".join(part for part in (drum, drum_id) if part)
            metadata: dict[str, Any] = {
                "table_quantity": quantity,
                "table_quantity_raw": raw_quantity,
                "table_quantity_header": header_text,
                "table_unit": "m",
            }
            if reference:
                metadata["reference"] = reference
            if drum:
                metadata["drum_number"] = drum
            if drum_id:
                metadata["drum_id"] = drum_id
            if length_col is not None and _cell(row, length_col):
                metadata["drum_length_raw"] = _cell(row, length_col)

            context = [part for part in (title, header_text) if part]
            items.append(
                {
                    "text": description,
                    "context": context,
                    "metadata": metadata,
                    "source": {
                        "input_type": "email_html_table",
                        "row_number": row_number,
                        **({"reference": reference} if reference else {}),
                    },
                }
            )
    return items


def _find_drum_matrix_header(table: list[list[str]]) -> int | None:
    for index, row in enumerate(table):
        roles = {_norm_cell(cell) for cell in row}
        if {"drum #", "drum id", "cable size"}.issubset(roles) and any(
            _norm_cell(cell) in {"drum lengths", "drum length"} for cell in row
        ):
            return index
    return None


def _find_header_col(row: list[str], names: set[str]) -> int | None:
    for index, cell in enumerate(row):
        if _norm_cell(cell) in names:
            return index
    return None


def _drum_matrix_quantity_cols(
    header: list[str], size_row: list[str], reserved: set[int | None]
) -> list[int]:
    cols: list[int] = []
    for index, cell in enumerate(header):
        if index in reserved:
            continue
        size = _cell(size_row, index)
        if re.fullmatch(r"\d+(?:\.\d+)?\s*mm", size, re.I) or _parse_positive_number(cell) is not None:
            cols.append(index)
    return cols


def _quantity_header(group_row: list[str], size_row: list[str], col: int) -> str:
    parts: list[str] = []
    for value in (_cell(group_row, col), _cell(size_row, col)):
        if value and value not in parts:
            parts.append(value)
    return " ".join(parts) or "Quantity"


def _table_title(rows: list[list[str]]) -> str:
    skip = {"sdi", "earth", "oc", "drum lengths", "drum length"}
    for row in rows:
        for cell in row:
            value = str(cell or "").strip()
            if value and _norm_cell(value) not in skip and not re.fullmatch(r"\d+(?:\.\d+)?\s*mm", value, re.I):
                return value
    return ""


def _padded(row: list[str], width: int) -> list[str]:
    return list(row) + [""] * max(0, width - len(row))


def _cell(row: list[str], index: int | None) -> str:
    if index is None or index >= len(row):
        return ""
    return str(row[index] or "").strip()


def _norm_cell(value: str) -> str:
    return re.sub(r"\s+", " ", str(value or "").replace("\xa0", " ").strip().lower())


def _parse_positive_number(value: str) -> int | float | None:
    match = re.search(r"\d+(?:\.\d+)?", str(value or "").replace(",", ""))
    if not match:
        return None
    number = float(match.group(0))
    if number <= 0:
        return None
    return int(number) if number.is_integer() else number


_FROM_LINE_RE = re.compile(r"^\s*(?:From|De|Van)\s*:\s*(.+)$", re.I | re.M)
_EMAIL_RE = re.compile(r"[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}")


def extract_original_sender(body_text: str, internal_domain: str = INTERNAL_DOMAIN) -> str | None:
    """Pull the customer's address out of a forwarded body.

    The rep is the message sender, so the customer lives in the quoted
    ``From:`` header inside the body. Prefer the first ``From:`` line whose email
    is not on the internal (tricab) domain; fall back to the first external email
    anywhere in the body. Returns a lowercased address or None."""
    internal_domain = (internal_domain or "").lower()

    def is_external(addr: str) -> bool:
        return bool(addr) and not addr.endswith("@" + internal_domain)

    for match in _FROM_LINE_RE.finditer(body_text or ""):
        found = _EMAIL_RE.search(match.group(1))
        if found and is_external(found.group(0).lower()):
            return found.group(0).lower()

    for found in _EMAIL_RE.finditer(body_text or ""):
        addr = found.group(0).lower()
        if is_external(addr):
            return addr
    return None


_BODY_LABEL_RE = re.compile(r"^\s*([^:\n]{1,40})\s*:\s*(.+?)\s*$")

# The rep's CREATE QUOTE template, as one vocabulary. It drives both
# extract_customer_labels (what the fields mean) and the consume/residue split
# (what counts as template scaffolding rather than an unclassified line), so the
# two can never disagree about which lines the template owns.
#
# Bare "delivery" and bare "ref"/"address" are deliberately absent: "Delivery:
# 500m of XL-PAXA/1C500BK" is a quote line, not an address. The value-level cable
# signal guard in _label_field covers the rest of that risk.
_BODY_LABEL_FIELDS: dict[str, tuple[str, ...]] = {
    "customer_code": ("customer code", "cust code", "customer no", "customer number", "customer id"),
    "customer": ("customer", "customer name", "company", "customer company"),
    "contact": ("contact", "contact name", "attn", "attention"),
    "customer_ref": ("customer ref", "customer reference", "cust ref", "your ref", "your reference"),
    "email": ("email", "e mail", "email address", "contact email"),
    "delivery_address": ("delivery address", "deliver to", "ship to", "shipping address", "delivery site"),
}
_BODY_LABEL_KEYS = {
    alias: field for field, aliases in _BODY_LABEL_FIELDS.items() for alias in aliases
}

# Scaffold lines in the template that carry no value ("CREATE QUOTE" as a banner).
# Consumed so the block is fully eaten and leaves no residue behind.
_TEMPLATE_BANNER_RE = re.compile(r"^\s*(?:create|new)\s+quote\b[\s:_*-]*$", re.I)

# An address line that wrapped onto the row below "DELIVERY ADDRESS:". Kept tight
# on purpose -- a street number, a postcode, or a comma-separated locality. Prose
# like "Please quote the following" has none of these and stays residue.
_ADDRESS_CONTINUATION_RE = re.compile(r"\d|,")
_ADDRESS_CONTINUATION_MAX_LINES = 3


def _label_field(line: str) -> tuple[str, str] | None:
    """Return (field, value) when ``line`` is a recognised template label.

    A label whose value carries cable signal or a product code is not treated as
    a label at all -- it is a quote line that happens to start with a colon-ed
    word, and consuming it would silently delete an item.
    """
    match = _BODY_LABEL_RE.match(line)
    if not match:
        return None
    key = re.sub(r"[^a-z0-9]+", " ", match.group(1).lower()).strip()
    field = _BODY_LABEL_KEYS.get(key)
    if not field:
        return None
    value = " ".join(match.group(2).split()).strip(" ;")
    if not value:
        return None
    if _CABLE_SIGNAL_RE.search(value) or _carries_explicit_code(value):
        return None
    if field == "email":
        found = _EMAIL_RE.search(value)
        if not found:
            return None
        value = found.group(0).lower()
    return field, value


def extract_customer_labels(body_text: str) -> dict[str, str]:
    """Read the explicit CREATE QUOTE labels a rep typed into the email body.

    ``delivery_address`` folds in the wrapped lines that follow it, which is what
    makes the template block fully consumed (see split_body_text)."""
    labels = {field: "" for field in _BODY_LABEL_FIELDS}
    address_run = 0
    for raw_line in (body_text or "").splitlines():
        parsed = _label_field(raw_line)
        if parsed:
            field, value = parsed
            labels[field] = labels[field] or value
            address_run = _ADDRESS_CONTINUATION_MAX_LINES if field == "delivery_address" else 0
            continue
        if address_run and _is_address_continuation(raw_line):
            labels["delivery_address"] = f"{labels['delivery_address']}, {raw_line.strip()}"
            address_run -= 1
            continue
        address_run = 0
    return labels


def _is_address_continuation(line: str) -> bool:
    value = " ".join(str(line or "").split())
    if not value or value.endswith(":") or len(value) > 80:
        return False
    if _BOILERPLATE_RE.match(value) or _BODY_LABEL_RE.match(value):
        return False
    if _CABLE_SIGNAL_RE.search(value) or _carries_explicit_code(value):
        return False
    return bool(_ADDRESS_CONTINUATION_RE.search(value))


def sender_address(message: dict) -> str:
    return (((message.get("from") or {}).get("emailAddress") or {}).get("address") or "").strip().lower()


# Names that are always decoration. Note what is NOT here: imageNNN.png. Outlook
# assigns that name to *every* inline image it re-encodes, including the pasted
# cable schedule we exist to read, so filtering on it silently dropped real RFQs.
#
# The second alternative is TriCab's own signature: tc_logo, separator, au_made and the
# YouTube thumbnail, each named `<asset>_<uuid>.png` by the signature service. Every rep
# email carries them, and the 516 KB thumbnail cleared the old size floor, so it was read
# as a possible schedule on every message. Outlook renames them imageNNN.png when a reply
# re-encodes them; those copies get through and are read as the logos they are.
_NOISE_NAME_RE = re.compile(
    r"^(?:outlook-[\w.-]+|.*\.gif"
    r"|(?:tc_logo|separator|au_made|ytthumbnail\w*)_[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\.\w+)$",
    re.I,
)

# Below this an inline image is a spacer or a separator rule. It was 25,600 until Ref
# 42387 (9 October 2026): a customer pasted three schedule tables as screenshots of 1.7,
# 3.5 and 5.2 KB, all three were dropped as decoration, and the reply said no lines could
# be detected. A table screenshot is a few flat colours and compresses to almost nothing,
# so size cannot tell it from a logo. A logo that gets through is read and yields no rows.
DEFAULT_MIN_INLINE_IMAGE_BYTES = 1024


def is_processable_inline_image(attachment: dict, min_bytes: int) -> bool:
    """Decide whether an inline body image is worth OCR'ing.

    Filters out the decoration that can be recognised -- known signature assets by name,
    spacers by size -- which look identical to a pasted cable schedule at the protocol
    level. Keep PNG/JPEG/WEBP above a small floor whose name isn't a known-noise pattern."""
    content_type = (attachment.get("contentType") or "").split(";", 1)[0].strip().lower()
    if content_type not in _IMAGE_CONTENT_TYPES:
        return False
    size = attachment.get("size")
    if not size:
        size = len(_attachment_bytes(attachment))
    if size < min_bytes:
        return False
    name = attachment.get("name") or ""
    if _NOISE_NAME_RE.match(name):
        return False
    return True


# Forwarded-header / signature / disclaimer lines that should never be matched as
# cable rows (they would burn LLM budget and add noise).
_BOILERPLATE_RE = re.compile(
    r"^\s*(?:"
    r"from|to|cc|bcc|sent|date|subject|de|van|objet|importance|reply-to|"
    r"-{2,}\s*forwarded message|begin forwarded message|on .+ wrote|"
    r"regards|kind regards|best regards|thanks|thank you|cheers|sincerely|"
    r"sent from my|this email|disclaimer|confidential|privileged"
    r")\b",
    re.I,
)

# Legal footers and confidentiality notices. _BOILERPLATE_RE is anchored at the
# start of the line, and these open with arbitrary wording -- "This message
# (including any attachments) may contain...", "Butler Electrical is committed
# to...", "****DISCLAIMER**** This e-mail and..." -- so 231 of them survive into
# residue across the audit corpus, in 33 distinct openings (audit 4.9). Harmless
# as residue today, but they are classifier input, so removing them is a
# prerequisite for RFQ_DIRECTIVES_ENABLED.
#
# Searched anywhere in the line rather than anchored, because the wording that
# identifies a disclaimer is rarely what starts it. Applied ONLY to lines with
# no cable signal and no product code (see the loop), so a genuine quote line
# cannot be swallowed by a stray "proprietary" or "copyright".
_LEGAL_FOOTER_RE = re.compile(
    r"intended\s+recipient|strictly\s+prohibited|unauthoris|unauthoriz"
    r"|confidential|privileged|proprietary"
    r"|notify\s+the\s+sender|delete\s+th(?:is|e)\s+(?:message|e-?mail|transmission)"
    r"|not\s+accept\s+liability|no\s+liability|virus"
    r"|consider\s+the\s+environment|think\s+before\s+you\s+print"
    r"|copyright|disclaimer",
    re.I,
)


# A line is worth matching only if it carries a structural cable signal: a sized
# conductor (10mm2 / 1.5mm²), a core/pair/triad count (4C, 4C+E, 1Pr), a voltage
# (0.6/1kV, 11kV), or an AWG gauge. Prose ("TriCab Flexible Cables are designed
# to have a Low Bending Radius...") has none of these, so it is dropped. This is
# the body-text path only; structured attachment rows bypass it entirely.
# (Shared with the plain-line circuit-label pairing in matcher.extractor.)
_CABLE_SIGNAL_RE = CABLE_SIGNAL_RE
_CIRCUIT_LABEL_RE = CIRCUIT_LABEL_LINE_RE
_looks_like_circuit_label = looks_like_circuit_label_line


def _carries_pack_quantity(line: str) -> bool:
    """True when the line is an accessory quoted by the pack.

    "1 bag x Uninsulated lugs 120 x 8" has no size, core count or voltage -- the
    "120 x 8" is a lug bore and bolt hole -- so the cable-signal gate dropped it
    and nine siblings with it (docs/EMAIL_CORPUS_AUDIT.md 4.6). The pack unit is
    the signal that this is a real line item; extract_entities then classifies it
    as an accessory rather than letting a bore size resolve to a cable.

    The pack unit alone is not enough. A quantity column reflowed out of a table
    yields orphan lines reading just "10 bags" / "2 bags", which name no product
    and cannot be quoted -- 0031 contributes 29 of them. Require something to be
    left over once the quantity is removed.
    """
    match = PACK_QUANTITY_RE.search(line)
    if not match:
        return False
    remainder = (line[: match.start()] + line[match.end() :]).strip(" \t-|x")
    return len(remainder) >= 3 and bool(re.search(r"[A-Za-z]", remainder))


def _carries_explicit_code(line: str) -> bool:
    """True when the line names a product or accessory code outright.

    "360m x KL-PAXA/1C500BK" has no free-text size, core count or voltage, so the
    cable signal above never fires -- yet it is the most precise form an RFQ can
    take. Codes are validated (catalog grammar for cables, the accessory pattern
    for accessories) before a line is kept on their account.
    """
    return bool(find_exact_product_code(line, set()) or find_accessory_code(line))

_INLINE_LENGTH_ITEM_RE = re.compile(
    r"(\d+\s*[x×]\s*\d+(?:\.\d+)?\s*(?:mtrs?|metres?|meters?|m(?!m))\b)",
    re.I,
)
_INLINE_SIGNOFF_RE = re.compile(
    r"((?:cu|al|deg|degree|degrees|mtrs?|metres?|meters?|m|mm2?|kv|v))"
    r"(?=(?:kind\s+|best\s+)?regards\b|thanks\b|cheers\b)",
    re.I,
)


def _candidate_text_segments(raw: str) -> list[str]:
    """Recover RFQ rows when Outlook/Graph gives us one visually-multiline body
    as a single run-on sentence.

    Example:
    ``Please quote4 x 34Mtrs - 70mm AL - 90deg4 x 62Mtrs...CuRegards``
    should still become independent quote lines. The splitter is deliberately
    narrow: it only inserts breaks before length-led RFQ item starts and before
    common sign-offs glued to the last cable token.

    **The length split needs two or more item starts on the line.** A run-on body
    is by definition several items glued together; one item start is an ordinary
    well-formed row that states its own quantity. Splitting that shape tore
    ``630mm2 Copper flex cabling - 16 x 10m runs`` into a spec segment and an
    orphan ``16 x 10m runs``, and the orphan -- carrying no cable signal -- was
    then dropped by the body filter. The row silently kept the 1 m default
    instead of 16 runs of 10 m. Under-splitting is the safe direction: a single
    item left glued to its preamble still extracts its own length.
    """
    text = str(raw or "").strip()
    if not text:
        return []
    text = _INLINE_SIGNOFF_RE.sub(r"\1\n", text)
    if len(_INLINE_LENGTH_ITEM_RE.findall(text)) >= 2:
        text = _INLINE_LENGTH_ITEM_RE.sub(r"\n\1", text)
    return [part.strip(" \t\r\n,;:-–—") for part in text.splitlines() if part.strip(" \t\r\n,;:-–—")]


# Scaffolding around the request itself: a greeting, a "please quote the
# following" preamble, or a closing courtesy. These are tested AFTER the
# cable-signal check, never before it -- which is precisely why they are not in
# _BOILERPLATE_RE. That runs first (a forwarded "Subject:" header can name a
# cable), so "Please quote 4C 10mm2 x 100m" would be destroyed by a prefix rule
# there. Down here the line has already failed the item test, so consuming it is
# safe.
_REQUEST_PREAMBLE_RE = re.compile(
    r"^(?:hi|hello|hey|dear)\b[\w\s,'-]*$"
    r"|\b(?:please|kindly|can|could|would)\b.{0,60}?"
    r"\b(?:quote|quotation|price|pricing|costing|supply|order|purchase)\b",
    re.I,
)
# A line that is nothing but a quantity: "200", "2 X 50M", "16 x 10m runs",
# "8 x 240m (1920m total)". These come from the alternating spec-line/qty-line
# RFQ style, where the length lives on the row below the spec. The .txt path
# folds them into the spec above (extractor.pair_schedule_lines); the body path
# now does the same, via _fold_quantity_line -- the plain-text half of §1.4.
#
# Wider than BARE_QUANTITY_LINE_RE, which only recognises the bare forms. The
# trailing "(1920m total)" is a restatement of the same quantity and is stripped
# before folding, or the extractor would see two lengths on one line.
_QUANTITY_ONLY_LINE_RE = re.compile(
    r"^\s*(?:\d+(?:\.\d+)?\s*[x×]\s*)?\d+(?:\.\d+)?\s*"
    r"(?:m|mtrs?|metres?|meters?)?\s*(?:runs?|lengths?|cuts?|off|ea|each)?"
    r"(?:\s*\(.*?\))?\s*$",
    re.I,
)
_QUANTITY_RESTATEMENT_RE = re.compile(r"\s*\([^)]*\)\s*$")


def _fold_quantity_line(items: list[dict[str, Any]], line: str) -> bool:
    """Merge a quantity-only line into the spec item above it.

    Same guard as extractor.pair_schedule_lines: only when the previous item
    reads as a cable spec and carries no length of its own, so a row that already
    stated its quantity can never have a second one appended.
    """
    if not items:
        return False
    previous = str(items[-1].get("text") or "")
    is_spec = bool(_CABLE_SIGNAL_RE.search(previous) or _carries_explicit_code(previous))
    if not is_spec or BARE_QUANTITY_LINE_RE.match(previous):
        return False
    if (
        LENGTH_RE.search(previous)
        or RUNS_RE.search(previous)
        or DRUM_RE.search(previous)
        or TRAILING_CUTS_RE.search(previous)
    ):
        return False
    quantity = _QUANTITY_RESTATEMENT_RE.sub("", line).strip()
    if not quantity:
        return False
    items[-1]["text"] = f"{previous} {quantity}"
    return True


# MacKillop's "Let me know if you need any further clarification" -- named in
# DIRECTIVE_CAPTURE_PLAN B1 as a line that must never reach the classifier.
_CLOSING_COURTESY_RE = re.compile(
    r"^(?:let me know|please let me know|any (?:further |other )?(?:questions|queries)|"
    r"hope (?:this|that) helps|look(?:ing)? forward|happy to (?:discuss|help)|"
    r"feel free to|(?:many )?thanks (?:in advance|again)|appreciate)\b"
    r"|\bif you (?:need|require|have) any (?:further |more |other )?"
    r"(?:questions|queries|clarification|info|information|details)\b",
    re.I,
)
# A sign-off opens a signature block: the name/title lines under it are part of
# the boilerplate, not unclassified content. Bounded hard (short, numberless, a
# few lines) so a directive typed below a sign-off still reaches the residue.
_SIGNOFF_RE = re.compile(
    r"^\s*(?:regards|kind regards|best regards|thanks|thank you|cheers|sincerely)\b", re.I
)
_SIGNATURE_BLOCK_MAX_LINES = 4


def _is_signature_line(line: str) -> bool:
    value = " ".join(str(line or "").split())
    if not value or any(char.isdigit() for char in value):
        return False
    return len(value.split()) <= 4


@dataclass(frozen=True)
class BodySegment:
    """One visible piece of body text, with why it was (or wasn't) consumed."""

    index: int
    text: str
    reason: str = ""


@dataclass(frozen=True)
class BodySplit:
    """The consume/residue partition of an email body (DIRECTIVE_CAPTURE_PLAN B1).

    Every segment lands in exactly one of ``items`` / ``consumed`` / ``residue``,
    and each carries the stable ``index`` it had in the body's segment stream, so
    a later stage can scope a directive back to the line that stated it.

    ``residue`` is what the deterministic parser could not account for: not a
    quote line, not template scaffolding, not boilerplate. That is the classifier's
    input in B2 -- and it is deliberately honest. A line we merely *suspect* is
    noise stays in residue rather than being quietly discarded, because a dropped
    directive is the failure this whole plan exists to fix.
    """

    items: list[dict[str, Any]]
    consumed: list[BodySegment]
    residue: list[BodySegment]
    labels: dict[str, str]


def split_body_text(body_text: str) -> BodySplit:
    """Partition an email body into quote items, consumed scaffolding, and residue.

    Consume order matters. Boilerplate first (a forwarded ``Subject:`` header can
    name a cable), then template labels, then anything carrying cable signal or a
    product code -- so the item path always wins over the heading/preamble paths
    below it and no quote line can be swallowed as prose.
    """
    items: list[dict[str, Any]] = []
    consumed: list[BodySegment] = []
    residue: list[BodySegment] = []
    labels: dict[str, str] = {field: "" for field in _BODY_LABEL_FIELDS}
    context: list[str] = []
    pending_label = ""
    address_run = 0
    signature_run = 0
    index = 0

    for raw in (body_text or "").splitlines():
        for line in _candidate_text_segments(raw):
            index += 1
            # The short-line guard drops stray punctuation and split artefacts,
            # but a one- or two-digit quantity ("60", "80") is a real length in
            # the alternating spec/qty style and must reach the fold below.
            if len(line) < 3 and not line.isdigit():
                consumed.append(BodySegment(index, line, "noise"))
                address_run = 0
                continue
            if _BOILERPLATE_RE.match(line):
                consumed.append(BodySegment(index, line, "boilerplate"))
                pending_label = ""
                address_run = 0
                signature_run = (
                    _SIGNATURE_BLOCK_MAX_LINES if _SIGNOFF_RE.match(line) else 0
                )
                continue
            if (
                _LEGAL_FOOTER_RE.search(line)
                and not _CABLE_SIGNAL_RE.search(line)
                and not _carries_explicit_code(line)
            ):
                # A confidentiality notice that _BOILERPLATE_RE could not anchor
                # on. The cable-signal and product-code guards mean this can only
                # ever consume a line that was unquotable anyway.
                consumed.append(BodySegment(index, line, "boilerplate"))
                pending_label = ""
                address_run = 0
                continue
            parsed = _label_field(line)
            if parsed:
                field, value = parsed
                labels[field] = labels[field] or value
                address_run = _ADDRESS_CONTINUATION_MAX_LINES if field == "delivery_address" else 0
                consumed.append(BodySegment(index, line, "label"))
                continue
            if address_run and _is_address_continuation(line):
                labels["delivery_address"] = f"{labels['delivery_address']}, {line}"
                address_run -= 1
                consumed.append(BodySegment(index, line, "label_continuation"))
                continue
            address_run = 0
            if _TEMPLATE_BANNER_RE.match(line):
                consumed.append(BodySegment(index, line, "template"))
                continue
            if (
                _CABLE_SIGNAL_RE.search(line)
                or _carries_explicit_code(line)
                or _carries_pack_quantity(line)
            ):
                text = f"{pending_label} | {line}" if pending_label else line
                pending_label = ""
                signature_run = 0
                items.append(
                    {
                        "text": text,
                        "context": list(context),
                        "metadata": {},
                        "source": {"input_type": "email_body", "line_number": index},
                    }
                )
                continue
            if _QUANTITY_ONLY_LINE_RE.match(line):
                folded = _fold_quantity_line(items, line)
                consumed.append(
                    BodySegment(index, line, "quantity_line" if folded else "quantity_orphan")
                )
                continue
            if _looks_like_circuit_label(line):
                pending_label = line
                consumed.append(BodySegment(index, line, "circuit_label"))
                continue
            if looks_like_section_heading(line):
                context = (context + [line])[-BODY_CONTEXT_DEPTH:]
                consumed.append(BodySegment(index, line, "heading"))
                continue
            if _REQUEST_PREAMBLE_RE.search(line):
                consumed.append(BodySegment(index, line, "preamble"))
                continue
            if _CLOSING_COURTESY_RE.search(line):
                consumed.append(BodySegment(index, line, "closing"))
                continue
            if signature_run and _is_signature_line(line):
                signature_run -= 1
                consumed.append(BodySegment(index, line, "signature"))
                continue
            signature_run = 0
            residue.append(BodySegment(index, line))

    return BodySplit(items=items, consumed=consumed, residue=residue, labels=labels)


def candidate_lines_from_text(body_text: str) -> list[str]:
    """Pull plausible cable lines out of an email body, dropping forwarded
    headers, signatures, and prose. Keeps a line only when it is not boilerplate
    and either carries a cable signal (size / core count / voltage / AWG) or names
    a product or accessory code outright. A circuit
    label on the line immediately before a cable row is prefixed to that row so
    it survives as the quote-line comment. Structured attachment rows do not pass
    through here, so they are unaffected.

    Thin view over :func:`split_body_text` for callers that only want the text."""
    return [str(item["text"]) for item in split_body_text(body_text).items]


def _attachment_bytes(attachment: dict) -> bytes:
    """The attachment's content, from Graph's base64 or -- offline only -- from disk.

    Graph always supplies ``contentBytes`` and never supplies ``path``, so the second
    branch is unreachable in production and this function is bit-for-bit what it was for
    a Graph-shaped attachment. ``path`` is set only by ``mail_read.HarvestMailSource``,
    which deliberately leaves the bytes on disk rather than re-inlining 35 MB of corpus
    attachments. The alternative was a second attachment reader inside the shadow runner,
    which would drift from this one and re-create the blind spot it exists to close --
    see SHADOW_ATTACHMENTS_MEASUREMENT.md §2. contentBytes wins when both are present.
    """
    raw = attachment.get("contentBytes") or ""
    if raw:
        try:
            return base64.b64decode(raw)
        except (binascii.Error, ValueError):
            return b""
    path = attachment.get("path")
    if not path:
        return b""
    try:
        return Path(path).read_bytes()
    except OSError:
        return b""


def _is_file_attachment(attachment: dict) -> bool:
    return (attachment.get("@odata.type") or "").lower().endswith("fileattachment")


def match_items(items: list[dict], *, message_context: dict | None = None,
                degraded: dict | None = None) -> list[dict]:
    """Run every extracted item through the shared engine. Mirrors the web
    /api/upload path: same shared ``pipeline.match_items`` orchestrator, so the
    AI normalizer (when enabled) and the regex parser behave identically here.

    ``message_context`` is the email the rows came from. Only this path supplies it, and
    supplying it is what lets the agent run at all -- see pipeline.match_items.
    """
    work_items = [
        {
            "text": item.get("text"),
            "source": item.get("source") or {"line_number": idx + 1},
            "context": item.get("context") or [],
            "metadata": item.get("metadata") or {},
        }
        for idx, item in enumerate(items)
    ]
    return pipeline.match_items(work_items, message_context=message_context, degraded=degraded)


#: Stamped into the subject of every reply the worker sends a rep. The reply carries
#: the app link and, when a quote was created, the 4js reference and the PDF -- so a
#: copy of it arriving back in the inbox looks exactly like an RFQ with an attachment,
#: and was ingested as one. Tagged rather than header-marked for `is_own_mail`'s
#: reason: a bounce or an auto-reply keeps the subject and drops custom headers.
REPLY_SUBJECT_TAG = "[RFQ-Reply]"


#: Reply prefixes in the languages this mailbox actually sees, so an existing one is
#: recognised instead of being stacked with another.
_REPLY_PREFIX_RE = re.compile(r"^((?:(?:re|fw|fwd|aw|tr|antw)\s*(?:\[\d+\])?\s*:\s*)+)", re.I)


#: Reply/forward prefixes plus "RFQ:", stripped from the front of a subject before it
#: stands in as the customer ref. Kept separate from `_REPLY_PREFIX_RE` so our own reply
#: subjects are unaffected. Colon required: "RFQ-1234" is a reference, not a prefix.
_CUSREF_PREFIX_RE = re.compile(r"^(?:(?:re|fw|fwd|aw|tr|antw|rfq)\s*(?:\[\d+\])?\s*:\s*)+", re.I)

#: Typographic characters that reach the quote PDF as mojibake ("â€“" for an en dash,
#: seen on 749970.04), folded to their ASCII equivalents.
_CUSREF_ASCII = str.maketrans({
    "–": "-", "—": "-", "‘": "'", "’": "'",
    "“": '"', "”": '"', " ": " ",
})

CUSREF_MAX_LENGTH = 60


def customer_ref_for_quote(typed_ref: str, subject: str) -> str:
    """The customer ref for a mailed RFQ: the typed "CUSTOMER REF:" if there is one,
    otherwise the subject with its "Fwd:" / "RE:" / "RFQ:" prefixes trimmed (Sales
    asked 2026-10-29 -- `Fwd: RFQ: P92837 - Rio Tinto` printed as the Customer Ref).

    A typed ref is the customer's own wording and keeps its prefixes; both get the
    ASCII fold. Trimmed before the 60-character cut so a prefix costs no length.
    """
    typed = str(typed_ref or "").strip()
    text = typed or _CUSREF_PREFIX_RE.sub("", str(subject or "").strip())
    text = re.sub(r"\s+", " ", text.translate(_CUSREF_ASCII)).strip()
    return text[:CUSREF_MAX_LENGTH].rstrip()


def reply_subject(subject: str) -> str:
    """The subject to send a draft-review reply under.

    `14033 Magnum` -> `RE: [RFQ-Reply] 14033 Magnum`. Idempotent: an already-tagged or
    already-prefixed subject is returned with one prefix and one tag, not two.

    The tag sits behind the reply prefix rather than in front of it so the subject still
    reads as a reply to the rep. Graph's `createReply` composes `RE: <original>` on its
    own; this replaces that subject, so the prefix has to be put back here.
    """
    text = str(subject or "").strip()
    prefix = _REPLY_PREFIX_RE.match(text)
    head, rest = (prefix.group(1), text[len(prefix.group(1)):]) if prefix else ("RE: ", text)
    if REPLY_SUBJECT_TAG.lower() in rest.lower():
        return f"{head}{rest}".strip()
    return f"{head}{REPLY_SUBJECT_TAG} {rest}".strip()


def is_own_mail(message: dict, *, mailbox: str = "") -> str:
    """Why this message is the parser's own and must never be ingested, or `''`.

    Three ways a message in this mailbox is something we sent, not an RFQ someone
    asked us to quote:

    - `notification` -- an assignment notification, or an answer to one.
    - `rfq_reply` -- one of our own draft-review replies, tagged on the way out.
    - `self_sent` -- the mailbox is the sender. The catch-all, and the only one that
      holds when the subject has been rewritten past recognition.

    Ingesting any of them turns our own output back into input. That is not a wasted
    poll: `MAIL_REPLY_ENABLED` means the new draft is replied to as well, and a loop
    does not limit itself. Seen once on 2026-09-01 (five emails) with only the
    notification tag checked -- see docs/PHASE2_CLOSE.md §6.

    Matched on the subject tag rather than a header, because a bounce or an auto-reply
    keeps the subject -- usually behind a Re:/Undeliverable: prefix -- but drops custom
    headers entirely.
    """
    subject = str(message.get("subject") or "").lower()
    if NOTIFICATION_SUBJECT_TAG.lower() in subject:
        return "notification"
    if REPLY_SUBJECT_TAG.lower() in subject:
        return "rfq_reply"
    # Last, because it is the coarsest: a rep who genuinely sends an RFQ *from* the
    # shared mailbox would be skipped here too. That is the right trade -- a missed
    # RFQ is a person waiting, a mail loop is unattended quotes in 4js -- but it means
    # the two specific tags above should carry the ordinary cases.
    address = str(mailbox or "").strip().lower()
    if address and sender_address(message) == address:
        return "self_sent"
    return ""


def is_own_notification(message: dict, *, mailbox: str = "") -> bool:
    """`is_own_mail` as a boolean, for callers that only need to skip-or-not."""
    return bool(is_own_mail(message, mailbox=mailbox))
