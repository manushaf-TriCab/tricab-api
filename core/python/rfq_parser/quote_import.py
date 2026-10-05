# Generated from the RFQ parser's matcher/quote_import.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
"""Deterministic readers for TriCab's own printed quotations (4js and Dynamics).

This is a transcription step, not an interpretation step. It reads a quote TriCab has
already issued and records what the page says: every line's printed product code,
quantity, prices and comment, plus the header and the printed total. It never calls the
product matcher, never generates or corrects a code, and never acts on text in a
comment. "Quote end user an additional 50m" is a comment to show Sales, not an
instruction to add 50 m. The ALI-FLEX transformation (docs/ALI_FLEX_IMPLEMENTATION_WORK_ORDER.md,
slice 2) runs later, on a snapshot Sales has reviewed.

Both templates are selectable-text PDFs with a fixed layout, and the readers rely on
the typography rather than on reading order:

* 4js (e.g. quote 739343): the item number anchors a block. Description lines come
  first, then the product code line in bold with quantity/unit/price/total on the same
  line, then any line comment in regular type. Bold text is overprinted, so every
  character appears twice in the raw stream; `dedupe_chars` removes the doubles.
* Dynamics (e.g. Q-115020): the item number line carries quantity/unit/price/amount.
  The bold product code usually shares that line, but can sit just above it
  when the code wraps. Unit detail ("1 x 520 m") is 8pt text in its own column,
  the description is regular type, and the line comment is oblique.

Anything that cannot be read unambiguously is flagged, never guessed. The result is
review-only until the arithmetic and the printed total reconcile.
"""

from __future__ import annotations

import hashlib
import io
import re
from dataclasses import dataclass, field
from datetime import datetime
from decimal import ROUND_HALF_UP, Decimal, InvalidOperation
from pathlib import Path
from typing import Any, Iterable

READER_VERSION = "quote-import/1"

TEMPLATE_4JS = "4js"
TEMPLATE_DYNAMICS = "dynamics"

STATUS_RECONCILED = "reconciled"
STATUS_REVIEW = "review_required"
STATUS_UNSUPPORTED = "unsupported"

# A blocking flag keeps the import review-only; a note is shown to Sales but does not
# stop reconciliation (it describes the source, not a transcription failure).
SEVERITY_BLOCKING = "blocking"
SEVERITY_NOTE = "note"

# Line total vs printed quote total: line amounts are printed to the cent, so their sum
# must match the printed total to the cent.
TOTAL_TOLERANCE = Decimal("0.005")

# Words closer than this (in points) vertically are one printed line. The Dynamics
# template sets label and value, and the 8pt unit detail, a point or two apart.
_LINE_TOLERANCE = 2.5

# A Dynamics product code that wraps is printed up to this far above its item line.
_DYNAMICS_CODE_LEAD = 8.0

# The product code starts at the left edge of the product column; the printed colour
# (also bold) sits to its right. A bold word that does not start at the edge is never
# the code, so a blank code stays blank rather than becoming "Orange".
_CODE_EDGE = 10.0

_MONEY_RE = re.compile(r"^\$?-?[\d,]+(?:\.\d+)?$")
_INT_RE = re.compile(r"^[\d,]+(?:\.\d+)?$")
_CODE_SHAPE_RE = re.compile(r"^[A-Z0-9][A-Z0-9.\-/+]*$")
_CURRENCY_RE = re.compile(r"^(AUD|NZD|USD)$")
# "4 x 40", "(4 x 40)", "3x15", "1x241m": runs x length, read from comment text as
# evidence only. A leading "+" or trailing "mm" means a conductor construction
# (4x25+1x6mm²), which is not a cut.
_CUT_RE = re.compile(
    r"(?<![\d.,+])(\d+)\s*[x×]\s*(\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+(?:\.\d+)?)"
    r"\s*(?:m\b|metres?\b|mtrs?\b)?(?!\d|[.,]\d|\s*mm|\s*\+)",
    re.IGNORECASE,
)
# Contact detail lines in the customer block ("Ph: 0415 173 386", "Attention: Rob
# Hancock 0421186680"). Kept as contact lines; never the customer's name.
_CONTACT_LINE_RE = re.compile(r"^(?:ph|phone|tel|mob|mobile|fax|email|e-mail|attention)\s*[:.]", re.IGNORECASE)
_ADDITIONAL_QTY_RE = re.compile(r"\badditional\s+\d+(?:\.\d+)?\s*m\b", re.IGNORECASE)
# Printed conductor construction in a description, kept as evidence: the SKU encodes
# one size (5C25) while the description may say 4x25+1x6mm².
_CONSTRUCTION_4JS_RE = re.compile(r"\b(\d+x\d+(?:\.\d+)?(?:\+\d+x\d+(?:\.\d+)?)?mm²|\d+(?:\.\d+)?mm²)")
_CONSTRUCTION_DYN_RE = re.compile(
    r"^(\d+[CP]\s*\.?\d+(?:\.\d+)?\s*mm2(?:\s*\+\s*\d+C\s*\d+(?:\.\d+)?\s*mm2)?)", re.IGNORECASE
)


class QuoteImportError(ValueError):
    """The file is not a readable PDF at all (as opposed to an unsupported layout)."""


# --- page model ----------------------------------------------------------------------


@dataclass
class Word:
    text: str
    x0: float
    x1: float
    top: float
    bottom: float
    font: str
    size: float

    @property
    def bold(self) -> bool:
        return "bold" in self.font.lower()

    @property
    def oblique(self) -> bool:
        lowered = self.font.lower()
        return "oblique" in lowered or "italic" in lowered


@dataclass
class Line:
    page: int
    words: list[Word] = field(default_factory=list)

    @property
    def top(self) -> float:
        return min(w.top for w in self.words)

    @property
    def bottom(self) -> float:
        return max(w.bottom for w in self.words)

    @property
    def text(self) -> str:
        return " ".join(w.text for w in self.words)

    def text_between(self, left: float, right: float = 1e9) -> str:
        return " ".join(w.text for w in self.words if left <= w.x0 < right)

    def words_between(self, left: float, right: float = 1e9) -> list[Word]:
        return [w for w in self.words if left <= w.x0 < right]

    @property
    def all_oblique(self) -> bool:
        return bool(self.words) and all(w.oblique for w in self.words)

    def provenance(self) -> dict[str, Any]:
        return {"page": self.page, "top": round(self.top, 1), "text": self.text}


@dataclass
class Page:
    number: int
    width: float
    height: float
    lines: list[Line]


def _font_name(raw: str) -> str:
    # Embedded subsets are named "ABCDEF+Helvetica-Bold".
    return raw.split("+", 1)[-1]


def _group_lines(page_number: int, words: list[Word]) -> list[Line]:
    lines: list[Line] = []
    for word in sorted(words, key=lambda w: (w.top, w.x0)):
        if lines and abs(word.top - lines[-1].top) <= _LINE_TOLERANCE:
            lines[-1].words.append(word)
        else:
            lines.append(Line(page=page_number, words=[word]))
    for line in lines:
        line.words.sort(key=lambda w: w.x0)
    return lines


def load_pages(source: str | Path | bytes) -> tuple[list[Page], bytes]:
    """Positioned, de-duplicated words for every page, grouped into printed lines."""
    import pdfplumber

    data = source if isinstance(source, (bytes, bytearray)) else Path(source).read_bytes()
    pages: list[Page] = []
    try:
        with pdfplumber.open(io.BytesIO(data)) as pdf:
            for index, raw_page in enumerate(pdf.pages, start=1):
                page = raw_page.dedupe_chars()
                words = [
                    Word(
                        text=w["text"],
                        x0=float(w["x0"]),
                        x1=float(w["x1"]),
                        top=float(w["top"]),
                        bottom=float(w["bottom"]),
                        font=_font_name(str(w.get("fontname") or "")),
                        size=float(w.get("size") or 0),
                    )
                    for w in page.extract_words(extra_attrs=["fontname", "size"])
                ]
                pages.append(Page(index, float(page.width), float(page.height), _group_lines(index, words)))
    except Exception as exc:  # noqa: BLE001 - pdfminer raises many unrelated types
        raise QuoteImportError(f"Could not read the PDF: {exc}") from exc
    return pages, bytes(data)


# --- detection -----------------------------------------------------------------------


def detect_template(pages: list[Page]) -> tuple[str | None, list[str]]:
    """Which TriCab template this is, from several independent document signals.

    Never from the file name or the quote-number prefix alone. Returns the template (or
    None) and the signals that were found, so an unrecognised file says why.
    """
    if not pages:
        return None, ["no pages"]
    texts = [line.text for page in pages for line in page.lines]
    joined = "\n".join(texts)
    signals: list[str] = []
    tricab = "TriCab (Australia) Pty" in joined
    if tricab:
        signals.append("TriCab company identity")

    fourjs = {
        "title": any(re.match(r"^QUOTATION No \S+$", t) for t in texts),
        "columns": any(re.match(r"^Itm Product Description Quantity Unit Unit Price Total$", t) for t in texts),
        "total": any(re.match(r"^TOTAL (AUD|NZD|USD) [\d,]+\.\d{2}$", t) for t in texts),
    }
    dynamics = {
        "title": any(re.search(r"Quotation No: \S+$", t) for t in texts),
        "columns": any(
            re.match(r"^Item Product Description Unit Detail Quantity Unit Unit Price Amount$", t) for t in texts
        ),
        "total": any(re.match(r"^Total (AUD|NZD|USD) \$ [\d,]+\.\d{2}$", t) for t in texts),
    }
    signals += [f"4js {name}" for name, hit in fourjs.items() if hit]
    signals += [f"Dynamics {name}" for name, hit in dynamics.items() if hit]
    if not tricab:
        return None, signals
    # Column headings plus one of title/total: a single stray phrase is not a template.
    if fourjs["columns"] and (fourjs["title"] or fourjs["total"]) and not dynamics["columns"]:
        return TEMPLATE_4JS, signals
    if dynamics["columns"] and (dynamics["title"] or dynamics["total"]) and not fourjs["columns"]:
        return TEMPLATE_DYNAMICS, signals
    return None, signals


# --- values --------------------------------------------------------------------------


def parse_money(text: str) -> Decimal | None:
    cleaned = (text or "").strip().replace("$", "").replace(",", "").strip()
    if not cleaned or not re.match(r"^-?\d+(?:\.\d+)?$", cleaned):
        return None
    try:
        return Decimal(cleaned)
    except InvalidOperation:
        return None


def _decimal_places(text: str) -> int:
    cleaned = (text or "").replace("$", "").replace(",", "").strip()
    return len(cleaned.split(".", 1)[1]) if "." in cleaned else 0


def _money_str(value: Decimal | None) -> str:
    return "" if value is None else format(value, "f")


def _parse_date(text: str) -> str:
    text = (text or "").strip()
    for fmt in ("%d %b %y %H:%M", "%d %b %y", "%d/%m/%Y", "%d/%m/%y"):
        try:
            return datetime.strptime(text, fmt).date().isoformat()
        except ValueError:
            continue
    return ""


def _flag(code: str, message: str, *, field_name: str = "", severity: str = SEVERITY_BLOCKING) -> dict[str, str]:
    return {"code": code, "field": field_name, "message": message, "severity": severity}


def parse_cut_evidence(text: str) -> list[dict[str, str]]:
    """Runs x length pairs printed in a comment or unit detail, in order.

    Evidence for Sales only: nothing here changes a printed quantity.
    """
    cuts = []
    for match in _CUT_RE.finditer(text or ""):
        length = match.group(2).replace(",", "")
        cuts.append({"runs": match.group(1), "length": length, "text": match.group(0).strip()})
    return cuts


def _cut_total(cuts: list[dict[str, str]]) -> Decimal | None:
    if not cuts:
        return None
    return sum((Decimal(c["runs"]) * Decimal(c["length"]) for c in cuts), Decimal("0"))


# --- line model ----------------------------------------------------------------------


def _empty_line(index: int) -> dict[str, Any]:
    return {
        "line_index": index,
        "item_number": "",
        "product_code": "",
        "colour": "",
        "description": "",
        "description_lines": [],
        "construction_text": "",
        "quantity": "",
        "quantity_text": "",
        "unit": "",
        "unit_detail": "",
        "unit_price": "",
        "unit_price_text": "",
        "line_amount": "",
        "line_amount_text": "",
        "comment": "",
        "comment_lines": [],
        "cut_evidence": [],
        "provenance": {"pages": [], "lines": []},
        "flags": [],
    }


def _add_provenance(line: dict[str, Any], printed: Iterable[Line]) -> None:
    for printed_line in printed:
        line["provenance"]["lines"].append(printed_line.provenance())
        if printed_line.page not in line["provenance"]["pages"]:
            line["provenance"]["pages"].append(printed_line.page)


def _set_numbers(
    line: dict[str, Any], *, quantity: str, unit: str, unit_price: str, amount: str
) -> None:
    line["quantity_text"] = quantity
    line["unit"] = unit
    line["unit_price_text"] = unit_price
    line["line_amount_text"] = amount
    for key, text, label in (
        ("quantity", quantity, "quantity"),
        ("unit_price", unit_price, "unit price"),
        ("line_amount", amount, "line amount"),
    ):
        value = parse_money(text)
        line[key] = _money_str(value)
        if value is None:
            line["flags"].append(
                _flag("unreadable_number", f"The printed {label} could not be read: {text!r}.", field_name=key)
            )
    if not unit:
        line["flags"].append(_flag("missing_unit", "No unit was printed for this line.", field_name="unit"))


def _set_code(
    line: dict[str, Any], code_words: list[Word], colour_words: list[Word], *, column_left: float
) -> None:
    code_words = sorted(code_words, key=lambda w: w.x0)
    if code_words and code_words[0].x0 > column_left + _CODE_EDGE:
        colour_words = code_words + colour_words
        code_words = []
    line["product_code"] = code_words[0].text if code_words else ""
    extra = [w.text for w in code_words[1:]] + [w.text for w in sorted(colour_words, key=lambda w: w.x0)]
    line["colour"] = " ".join(extra)
    if not line["product_code"]:
        line["flags"].append(
            _flag(
                "missing_product_code",
                "No product code could be read on this line. It is not generated from the description.",
                field_name="product_code",
            )
        )
    elif not _CODE_SHAPE_RE.match(line["product_code"]):
        line["flags"].append(
            _flag(
                "unusual_product_code",
                f"The printed code {line['product_code']!r} has an unexpected shape; check it against the PDF.",
                field_name="product_code",
            )
        )


def _set_comment(line: dict[str, Any], comment_lines: list[str]) -> None:
    line["comment_lines"] = comment_lines
    line["comment"] = "\n".join(comment_lines)
    if _ADDITIONAL_QTY_RE.search(line["comment"]):
        line["flags"].append(
            _flag(
                "comment_requests_quantity",
                "The comment asks for additional metres. The printed quantity is kept; Sales decides.",
                field_name="comment",
                severity=SEVERITY_NOTE,
            )
        )


def _check_cuts(line: dict[str, Any]) -> None:
    """Compare printed runs x length (comment or unit detail) with the printed quantity."""
    quantity = parse_money(line["quantity"])
    sources = []
    if line["unit_detail"]:
        sources.append(("unit_detail", line["unit_detail"]))
    if line["comment"]:
        sources.append(("comment", line["comment"]))
    evidence = []
    for source, text in sources:
        cuts = parse_cut_evidence(text)
        if not cuts:
            continue
        total = _cut_total(cuts)
        entry = {"source": source, "cuts": cuts, "total": _money_str(total)}
        evidence.append(entry)
        if quantity is not None and total is not None and total != quantity:
            line["flags"].append(
                _flag(
                    "cuts_disagree_with_quantity",
                    f"The {source.replace('_', ' ')} gives {entry['total']} m "
                    f"({', '.join(c['text'] for c in cuts)}) but the printed quantity is {line['quantity']}.",
                    field_name=source,
                    severity=SEVERITY_NOTE,
                )
            )
    line["cut_evidence"] = evidence


def _check_arithmetic(line: dict[str, Any]) -> dict[str, str] | None:
    quantity = parse_money(line["quantity"])
    price = parse_money(line["unit_price"])
    amount = parse_money(line["line_amount"])
    if quantity is None or price is None or amount is None:
        return None
    computed = quantity * price
    # The printed unit price is rounded to its printed precision, so the real price is
    # within half a unit of its last digit; the amount is then rounded to the cent.
    half_ulp = Decimal(1).scaleb(-_decimal_places(line["unit_price_text"])) / 2
    tolerance = quantity * half_ulp + TOTAL_TOLERANCE
    difference = abs(computed - amount)
    check = {
        "computed": _money_str(computed.quantize(Decimal("0.001"), rounding=ROUND_HALF_UP)),
        "difference": _money_str(difference.quantize(Decimal("0.001"), rounding=ROUND_HALF_UP)),
        "tolerance": _money_str(tolerance),
        "ok": difference <= tolerance,
    }
    if not check["ok"]:
        line["flags"].append(
            _flag(
                "line_arithmetic",
                f"{line['quantity']} x {line['unit_price']} = {check['computed']}, "
                f"but the printed line amount is {line['line_amount']}.",
                field_name="line_amount",
            )
        )
    return check


# --- 4js ------------------------------------------------------------------------------


def _is_4js_table_header(line: Line) -> bool:
    return line.text == "Itm Product Description Quantity Unit Unit Price Total"


def _4js_columns(header: Line) -> dict[str, float]:
    x = {w.text: w.x0 for w in header.words}
    unit_words = [w for w in header.words if w.text == "Unit"]
    return {
        "product": x["Product"] - 2,
        "quantity": x["Quantity"] - 12,
        "unit": unit_words[0].x0 - 2,
        "unit_price": (unit_words[0].x0 + unit_words[1].x0) / 2,
        "total": x["Total"] - 35,
    }


def _read_4js(pages: list[Page]) -> dict[str, Any]:
    flags: list[dict[str, str]] = []
    header = _4js_header(pages[0])
    stream: list[Line] = []
    footer_total: tuple[str, str] | None = None
    columns: dict[str, float] | None = None
    first_header_top: float | None = None

    for page in pages:
        header_line = next((line for line in page.lines if _is_4js_table_header(line)), None)
        if header_line is None:
            flags.append(_flag("page_without_table", f"Page {page.number} has no line table heading."))
            continue
        columns = columns or _4js_columns(header_line)
        if page.number == 1:
            first_header_top = header_line.top
        for line in page.lines:
            if line.top <= header_line.top:
                continue
            text = line.text
            if text.startswith("continued over page"):
                break
            total_match = re.match(r"^TOTAL (AUD|NZD|USD) ([\d,]+\.\d{2})$", text)
            if total_match:
                footer_total = (total_match.group(1), total_match.group(2))
                break
            if line.top > page.height * 0.9:
                break  # company footer
            stream.append(line)

    if columns is None:
        return {"header": header, "lines": [], "flags": flags, "footer_total": footer_total}

    anchors = [
        i
        for i, line in enumerate(stream)
        if line.words[0].x0 < columns["product"] and re.match(r"^\d+$", line.words[0].text)
    ]
    if stream and (not anchors or anchors[0] != 0):
        flags.append(_flag("text_before_first_line", "Text in the table precedes the first item number."))

    lines = []
    for n, start in enumerate(anchors):
        end = anchors[n + 1] if n + 1 < len(anchors) else len(stream)
        block = stream[start:end]
        line = _empty_line(n)
        line["item_number"] = block[0].words[0].text
        _add_provenance(line, block)
        sku_at = next(
            (i for i, printed in enumerate(block) if printed.words_between(columns["total"])), None
        )
        if sku_at is None:
            line["flags"].append(_flag("missing_price_line", "No quantity/price line was found for this item."))
            line["description_lines"] = [_4js_desc_text(p, columns, i == 0) for i, p in enumerate(block)]
            line["description"] = "\n".join(line["description_lines"])
            lines.append(line)
            continue
        sku = block[sku_at]
        description = [_4js_desc_text(p, columns, i == 0) for i, p in enumerate(block[:sku_at])]
        line["description_lines"] = [d for d in description if d]
        line["description"] = "\n".join(line["description_lines"])
        construction = _CONSTRUCTION_4JS_RE.search(line["description"])
        line["construction_text"] = construction.group(1) if construction else ""

        left_words = sku.words_between(columns["product"], columns["quantity"])
        if sku_at == 0:
            left_words = [w for w in left_words if w is not block[0].words[0]]
        code_words = [w for w in left_words if w.bold]
        colour_words = [w for w in left_words if not w.bold]
        # First bold word is the code; any further bold words are the printed colour.
        _set_code(line, code_words, colour_words, column_left=columns["product"])
        _set_numbers(
            line,
            quantity=sku.text_between(columns["quantity"], columns["unit"]),
            unit=sku.text_between(columns["unit"], columns["unit_price"]),
            unit_price=sku.text_between(columns["unit_price"], columns["total"]),
            amount=sku.text_between(columns["total"]),
        )
        comment_lines = [printed.text_between(columns["product"]) for printed in block[sku_at + 1 :]]
        _set_comment(line, [c for c in comment_lines if c])
        _check_cuts(line)
        lines.append(line)

    header["table_top"] = first_header_top
    return {"header": header, "lines": lines, "flags": flags, "footer_total": footer_total}


def _4js_desc_text(line: Line, columns: dict[str, float], first: bool) -> str:
    words = line.words_between(columns["product"])
    return " ".join(w.text for w in words)


def _right_value(lines: list[Line], label: str, left: float) -> tuple[str, int | None]:
    for index, line in enumerate(lines):
        text = line.text_between(left)
        if text.startswith(label):
            return text[len(label) :].strip(), index
    return "", None


def _4js_header(page: Page) -> dict[str, Any]:
    table = next((line for line in page.lines if _is_4js_table_header(line)), None)
    region = [line for line in page.lines if table is None or line.top < table.top]
    right_left = 330.0
    right = [line for line in region if line.words_between(right_left)]
    # (left-column projection, full line): header comments run across the whole page.
    left = [
        (Line(line.page, line.words_between(0, right_left)), line)
        for line in region
        if line.words_between(0, right_left)
    ]
    title = next((line.text for line in region if line.text.startswith("QUOTATION No ")), "")

    quote_number, _ = _right_value(right, "Quote No:", right_left)
    if not quote_number:
        quote_number, _ = _right_value(right, "Quote#:", right_left)
    raised_by, raised_at = _right_value(right, "Raised By:", right_left)
    contact: list[str] = []
    if raised_at is not None:
        for line in right[raised_at + 1 :]:
            if ":" in line.text_between(right_left)[:20]:
                break
            contact.append(line.text_between(right_left))
    quote_date, _ = _right_value(right, "Quote Date:", right_left)
    valid_till, _ = _right_value(right, "Valid Till:", right_left)
    cust_code, _ = _right_value(right, "Cust Code:", right_left)
    total_text, _ = _right_value(right, "Total:", right_left)
    tax = next((line.text_between(right_left) for line in right if line.text_between(right_left).startswith("ALL PRICES")), "")

    attention = ""
    customer_name = ""
    address: list[str] = []
    contact_lines: list[str] = []
    customer_ref = ""
    comments: list[str] = []
    in_comments = False
    for line, full in left:
        text = line.text
        if in_comments:
            comments.append(full.text)
            continue
        if text.startswith("Attn:"):
            attention = text[len("Attn:") :].strip()
            continue
        if text.startswith("Customer Ref:"):
            customer_ref = text[len("Customer Ref:") :].strip()
            continue
        if text.startswith("Comments:"):
            in_comments = True
            continue
        if attention and not customer_ref:
            if _CONTACT_LINE_RE.match(text):
                contact_lines.append(text)
            elif not customer_name:
                customer_name = text
            else:
                address.append(text)

    total_match = re.match(r"^(AUD|NZD|USD)\s+([\d,]+\.\d{2})$", total_text)
    metal = _metal_prices(right, right_left, stop="Valid Till:")
    return {
        "title": title,
        "quote_number": quote_number or title.replace("QUOTATION No", "").strip(),
        "quote_date_text": quote_date,
        "quote_date": _parse_date(quote_date),
        "valid_till_text": valid_till,
        "valid_till": _parse_date(valid_till),
        "raised_by": raised_by,
        "raised_by_contact": contact,
        "attention": attention,
        "customer_name": customer_name,
        "address_lines": address,
        "contact_lines": contact_lines,
        "customer_code": cust_code,
        "account_code": "",
        "customer_ref": customer_ref,
        "comments": comments,
        "header_total_text": total_text,
        "header_currency": total_match.group(1) if total_match else "",
        "header_total": _money_str(parse_money(total_match.group(2))) if total_match else "",
        "tax_basis": tax,
        "metal_prices": metal,
    }


def _metal_prices(right: list[Line], left: float, *, stop: str) -> dict[str, Any]:
    """Copper/aluminium reference prices, as printed. Never the quote currency."""
    started = False
    labels: list[str] = []
    amounts: list[tuple[str, str]] = []
    raw: list[str] = []
    for line in right:
        text = line.text_between(left)
        if text.startswith("Based on"):
            started = True
        if not started:
            continue
        if text.startswith(stop):
            break
        raw.append(text)
        for metal in re.findall(r"(Copper|Aluminium)/Tonne", text):
            labels.append(metal.lower())
        amounts += re.findall(r"\b(USD|AUD|NZD)\s+\$?([\d,]+(?:\.\d+)?)", text)
    result: dict[str, Any] = {"raw": raw, "per_tonne": {}}
    if labels and amounts and len(amounts) % len(labels) == 0:
        per = len(amounts) // len(labels)
        for i, metal in enumerate(labels):
            result["per_tonne"][metal] = [
                {"currency": cur, "amount": _money_str(parse_money(value))}
                for cur, value in amounts[i * per : (i + 1) * per]
            ]
    return result


# --- Dynamics -------------------------------------------------------------------------


def _is_dynamics_table_header(line: Line) -> bool:
    return line.text == "Item Product Description Unit Detail Quantity Unit Unit Price Amount"


def _dynamics_columns(header: Line) -> dict[str, float]:
    words = header.words
    x = {}
    for i, w in enumerate(words):
        nxt = words[i + 1].text if i + 1 < len(words) else ""
        if w.text == "Unit" and nxt == "Detail":
            x["unit_detail"] = w.x0
        elif w.text == "Unit" and nxt == "Price":
            x["unit_price"] = w.x0
        elif w.text == "Unit":
            x["unit"] = w.x0
        elif w.text in ("Product", "Quantity", "Amount"):
            x[w.text.lower()] = w.x0
    return {
        "product": x["product"] - 2,
        "unit_detail": x["unit_detail"] - 2,
        "quantity": x["quantity"] - 12,
        "unit": x["unit"] - 2,
        "unit_price": (x["unit"] + x["unit_price"]) / 2,
        "amount": x["amount"] - 40,
    }


def _read_dynamics(pages: list[Page]) -> dict[str, Any]:
    flags: list[dict[str, str]] = []
    header = _dynamics_header(pages[0])
    stream: list[Line] = []
    footer_total: tuple[str, str] | None = None
    columns: dict[str, float] | None = None

    for page in pages:
        header_line = next((line for line in page.lines if _is_dynamics_table_header(line)), None)
        if header_line is not None:
            columns = columns or _dynamics_columns(header_line)
            start = header_line.top
        else:
            # Continuation pages repeat only the letterhead (the right-hand block that
            # holds "Quotation No:"); rows start below it.
            letterhead = [line for line in page.lines if line.top < page.height * 0.2 and line.words[0].x0 > 150]
            start = max((line.top for line in letterhead), default=0.0)
        if columns is None:
            continue
        for line in page.lines:
            if line.top <= start:
                continue
            text = line.text
            if re.match(r"^Page \d+ of \d+$", text):
                break
            total_match = re.match(r"^Total (AUD|NZD|USD) \$ ([\d,]+\.\d{2})$", text)
            if total_match:
                footer_total = (total_match.group(1), total_match.group(2))
                break
            stream.append(line)

    if columns is None:
        flags.append(_flag("no_table", "No line table heading was found."))
        return {"header": header, "lines": [], "flags": flags, "footer_total": footer_total}

    anchor_idx = [
        i
        for i, line in enumerate(stream)
        if line.words[0].x0 < columns["product"] and re.match(r"^\d+\.\d+$", line.words[0].text)
    ]
    # A wrapped code sits a few points above its item line; everything else above the
    # item line (the previous item's oblique comment) stays with the previous item.
    owner = [-1] * len(stream)
    for n, a in enumerate(anchor_idx):
        owner[a] = n
    for i, line in enumerate(stream):
        if owner[i] != -1:
            continue
        following = next((n for n, a in enumerate(anchor_idx) if a > i), None)
        preceding = max((n for n, a in enumerate(anchor_idx) if a < i), default=None)
        if (
            following is not None
            and stream[anchor_idx[following]].page == line.page
            and stream[anchor_idx[following]].top - line.top <= _DYNAMICS_CODE_LEAD
            and not line.all_oblique
        ):
            owner[i] = following
        elif preceding is not None:
            owner[i] = preceding
    if any(o == -1 for o in owner):
        flags.append(_flag("text_before_first_line", "Text in the table precedes the first item number."))

    lines = []
    for n, a in enumerate(anchor_idx):
        block = [stream[i] for i in range(len(stream)) if owner[i] == n]
        item_line = stream[a]
        line = _empty_line(n)
        line["item_number"] = item_line.words[0].text
        _add_provenance(line, block)

        detail_words: list[Word] = []
        code_words: list[Word] = []
        colour_words: list[Word] = []
        code_line: Line | None = None
        text_lines: list[Line] = []
        comment_lines: list[str] = []
        for printed in block:
            if printed.all_oblique:
                comment_lines.append(printed.text_between(columns["product"]))
                continue
            small = [w for w in printed.words_between(columns["unit_detail"], columns["quantity"]) if w.size < 8.5]
            detail_words += small
            product_words = [
                w
                for w in printed.words_between(columns["product"])
                if w not in small and not (printed is item_line and w.x0 >= columns["quantity"])
            ]
            bold = [w for w in product_words if w.bold]
            if bold and code_line is None:
                code_line = printed
                code_words = bold
                colour_words = [w for w in product_words if not w.bold and w.x0 < columns["unit_detail"]]
            elif product_words:
                text_lines.append(Line(printed.page, product_words))

        # A colour that did not fit beside a wrapped code is printed on its own line.
        if code_line is not None and not colour_words and text_lines:
            candidate = text_lines[0]
            if len(candidate.words) == 1 and re.match(r"^[A-Za-z]+(?:/[A-Za-z]+)?$", candidate.words[0].text):
                colour_words = candidate.words
                text_lines = text_lines[1:]
        _set_code(line, code_words, colour_words, column_left=columns["product"])
        line["description_lines"] = [t.text for t in text_lines]
        line["description"] = "\n".join(line["description_lines"])
        construction = _CONSTRUCTION_DYN_RE.search(line["description"])
        line["construction_text"] = construction.group(1) if construction else ""
        line["unit_detail"] = " ".join(w.text for w in sorted(detail_words, key=lambda w: w.x0))
        _set_numbers(
            line,
            quantity=item_line.text_between(columns["quantity"], columns["unit"]),
            unit=item_line.text_between(columns["unit"], columns["unit_price"]),
            unit_price=item_line.text_between(columns["unit_price"], columns["amount"]),
            amount=item_line.text_between(columns["amount"]),
        )
        _set_comment(line, [c for c in comment_lines if c])
        _check_cuts(line)
        lines.append(line)

    return {"header": header, "lines": lines, "flags": flags, "footer_total": footer_total}


def _dynamics_header(page: Page) -> dict[str, Any]:
    table = next((line for line in page.lines if _is_dynamics_table_header(line)), None)
    region = [line for line in page.lines if table is None or line.top < table.top]
    letterhead_top = page.height * 0.15
    right_left = 400.0
    body = [line for line in region if line.top > letterhead_top]
    right = [Line(line.page, line.words_between(right_left)) for line in body if line.words_between(right_left)]
    left = [
        (Line(line.page, line.words_between(0, right_left)), line)
        for line in body
        if line.words_between(0, right_left)
    ]

    title = next((line.text for line in region if "Quotation No:" in line.text), "")
    quote_number, _ = _right_value(right, "Quote No:", right_left)
    raised_by, _ = _right_value(right, "Raised By:", right_left)
    quote_date, _ = _right_value(right, "Quote Date:", right_left)
    valid_till, _ = _right_value(right, "Valid Till:", right_left)
    cust_code, _ = _right_value(right, "Cust Code:", right_left)
    total_text, _ = _right_value(right, "Total:", right_left)
    tax = next((line.text for line in right if line.text.startswith("ALL PRICES")), "")

    attention = ""
    customer_name = ""
    address: list[str] = []
    contact_lines: list[str] = []
    account_code = ""
    customer_ref = ""
    comments: list[str] = []
    in_comments = False
    for line, full in left:
        text = line.text
        if in_comments:
            comments.append(full.text)
        elif text.startswith("Attn:"):
            attention = text[len("Attn:") :].strip()
        elif text.startswith("Account Code:"):
            account_code = text[len("Account Code:") :].strip()
        elif text.startswith("Customer Ref:"):
            customer_ref = text[len("Customer Ref:") :].strip()
        elif text == "Comments":
            in_comments = True
        elif attention and not account_code:
            if _CONTACT_LINE_RE.match(text):
                contact_lines.append(text)
            elif not customer_name:
                customer_name = text
            else:
                address.append(text)

    total_match = re.match(r"^\$\s*([\d,]+\.\d{2})$", total_text)
    return {
        "title": title,
        "quote_number": quote_number or title.split("Quotation No:")[-1].strip(),
        "quote_date_text": quote_date,
        "quote_date": _parse_date(quote_date),
        "valid_till_text": valid_till,
        "valid_till": _parse_date(valid_till),
        "raised_by": raised_by,
        "raised_by_contact": [],
        "attention": attention,
        "customer_name": customer_name,
        "address_lines": address,
        "contact_lines": contact_lines,
        "customer_code": cust_code,
        "account_code": account_code,
        "customer_ref": customer_ref,
        "comments": comments,
        "header_total_text": total_text,
        # The Dynamics header prints "$" without a currency; the footer names it.
        "header_currency": "",
        "header_total": _money_str(parse_money(total_match.group(1))) if total_match else "",
        "tax_basis": tax,
        "metal_prices": _metal_prices(right, right_left, stop="Valid Till:"),
    }


# --- entry point ----------------------------------------------------------------------


_READERS = {TEMPLATE_4JS: _read_4js, TEMPLATE_DYNAMICS: _read_dynamics}

_LIMITATIONS = {
    TEMPLATE_4JS: [
        "Read from the 4js layout of quotes 739343 and 738990.02; other 4js layout revisions are unverified.",
        "Discount, freight and accessory lines have not been seen in a sample and are not reconciled separately.",
    ],
    TEMPLATE_DYNAMICS: [
        "Read from the Dynamics layout of quote Q-115020; other Dynamics layout revisions are unverified.",
        "Dynamics truncates long descriptions on the page; the printed text is kept as-is, not completed.",
        "Discount, freight and accessory lines have not been seen in a sample and are not reconciled separately.",
    ],
}


def read_quote_pdf(source: str | Path | bytes, *, file_name: str = "") -> dict[str, Any]:
    """Read a TriCab 4js or Dynamics quotation PDF into the imported-quote structure.

    Raises QuoteImportError only when the bytes are not a readable PDF. An unrecognised
    layout is a normal result (`status == "unsupported"`), not an exception.
    """
    pages, data = load_pages(source)
    if not file_name and not isinstance(source, (bytes, bytearray)):
        file_name = Path(source).name
    template, signals = detect_template(pages)
    base: dict[str, Any] = {
        "reader_version": READER_VERSION,
        "template": template,
        "detection_signals": signals,
        "file_name": file_name,
        "file_sha256": hashlib.sha256(data).hexdigest(),
        "page_count": len(pages),
    }
    if template is None:
        base.update(
            {
                "status": STATUS_UNSUPPORTED,
                "header": {},
                "lines": [],
                "flags": [
                    _flag(
                        "unsupported_layout",
                        "This is not a recognised 4js or Dynamics TriCab quotation, so nothing was imported.",
                    )
                ],
                "validation": {},
                "limitations": [],
            }
        )
        return base

    read = _READERS[template](pages)
    header = read["header"]
    header.pop("table_top", None)
    footer = read["footer_total"]
    header["footer_currency"] = footer[0] if footer else ""
    header["footer_total"] = _money_str(parse_money(footer[1])) if footer else ""
    header["currency"] = header["footer_currency"] or header["header_currency"]
    header["printed_total"] = header["footer_total"] or header["header_total"]

    base.update(
        {
            "header": header,
            "lines": read["lines"],
            "flags": list(read["flags"]),
            "limitations": list(_LIMITATIONS[template]),
        }
    )
    base["validation"] = validate_import(base)
    base["status"] = base["validation"]["status"]
    return base


def validate_import(imported: dict[str, Any], lines: list[dict[str, Any]] | None = None) -> dict[str, Any]:
    """Transcription checks: counts, numbers, arithmetic, totals. Not product suitability.

    `lines` lets a caller validate effective values (printed values with Sales'
    corrections applied) without mutating the printed lines. Line-level flags from the
    reader are only carried when validating the printed lines themselves.
    """
    header = imported.get("header") or {}
    printed_lines = imported.get("lines") or []
    effective = printed_lines if lines is None else lines
    quote_flags = [f for f in imported.get("flags") or [] if f.get("code") not in _DERIVED_QUOTE_FLAGS]

    arithmetic = []
    for line in effective:
        line["flags"] = [f for f in line.get("flags") or [] if f.get("code") != "line_arithmetic"]
        check = _check_arithmetic(line)
        line["arithmetic"] = check
        arithmetic.append(check)

    # Item numbers must run 1, 2, 3 ... (Dynamics prints 1.0, 2.0 ...).
    numbers = []
    for line in effective:
        try:
            numbers.append(Decimal(line["item_number"]))
        except (InvalidOperation, KeyError, TypeError):
            numbers.append(None)
    expected = [Decimal(i) for i in range(1, len(effective) + 1)]
    if numbers != expected:
        quote_flags.append(
            _flag(
                "item_sequence",
                "Item numbers are not a continuous sequence from 1; a line may be missing or duplicated.",
            )
        )
    if not effective:
        quote_flags.append(_flag("no_lines", "No quote lines were read."))

    amounts = [parse_money(line.get("line_amount", "")) for line in effective]
    line_sum = sum((a for a in amounts if a is not None), Decimal("0"))
    printed_total = parse_money(header.get("printed_total", ""))
    difference = None if printed_total is None else line_sum - printed_total
    reconciled = (
        printed_total is not None
        and all(a is not None for a in amounts)
        and abs(difference) <= TOTAL_TOLERANCE
    )
    if printed_total is None:
        quote_flags.append(_flag("missing_total", "The printed quote total could not be read."))
    elif not reconciled:
        quote_flags.append(
            _flag(
                "total_mismatch",
                f"Line amounts sum to {_money_str(line_sum)} but the printed total is "
                f"{_money_str(printed_total)} (difference {_money_str(difference)}).",
            )
        )
    header_total = parse_money(header.get("header_total", ""))
    footer_total = parse_money(header.get("footer_total", ""))
    if header_total is not None and footer_total is not None and header_total != footer_total:
        quote_flags.append(
            _flag("header_footer_total", "The header total and the closing total on the quote differ.")
        )
    if not header.get("currency"):
        quote_flags.append(_flag("currency_unknown", "The quote currency is not printed where expected."))
    elif header.get("header_currency") and header.get("footer_currency") and (
        header["header_currency"] != header["footer_currency"]
    ):
        quote_flags.append(_flag("currency_conflict", "The header and closing totals name different currencies."))
    if not header.get("quote_number"):
        quote_flags.append(_flag("missing_quote_number", "The source quote number could not be read."))

    imported["flags"] = quote_flags
    line_blocking = sum(
        1 for line in effective for f in line.get("flags") or [] if f.get("severity") == SEVERITY_BLOCKING
    )
    quote_blocking = sum(1 for f in quote_flags if f.get("severity") == SEVERITY_BLOCKING)
    notes = sum(1 for line in effective for f in line.get("flags") or [] if f.get("severity") == SEVERITY_NOTE)
    status = STATUS_RECONCILED if reconciled and not line_blocking and not quote_blocking else STATUS_REVIEW
    return {
        "status": status,
        "line_count": len(effective),
        "line_sum": _money_str(line_sum),
        "printed_total": _money_str(printed_total),
        "difference": _money_str(difference),
        "reconciled_total": reconciled,
        "arithmetic_failures": sum(1 for c in arithmetic if c is not None and not c["ok"]),
        "blocking_flags": line_blocking + quote_blocking,
        "notes": notes,
    }


# Quote-level flags that validate_import derives and must replace on each run.
_DERIVED_QUOTE_FLAGS = {
    "item_sequence",
    "no_lines",
    "missing_total",
    "total_mismatch",
    "header_footer_total",
    "currency_unknown",
    "currency_conflict",
    "missing_quote_number",
}


# --- into the editor: rows, pricing and the draft --------------------------------------

#: The draft `source` of an imported quote. It is an ordinary draft -- editable, and
#: syncable as a NEW 4js quote -- and the source only lets the dashboard filter it.
SOURCE_QUOTE_IMPORT = "quote_import"

_TEMPLATE_LABELS = {TEMPLATE_4JS: "4js", TEMPLATE_DYNAMICS: "Dynamics"}

#: Largest PDF accepted. The samples are well under 1 MB; this only stops a stray
#: upload tying up a per-request PHP worker.
MAX_IMPORT_BYTES = 15 * 1024 * 1024


def import_label(imported: dict[str, Any]) -> str:
    header = imported.get("header") or {}
    system = _TEMPLATE_LABELS.get(imported.get("template") or "", "quote")
    parts = [f"Imported {system} quote {header.get('quote_number') or '?'}"]
    if header.get("customer_name"):
        parts.append(header["customer_name"])
    return " · ".join(parts)


def _is_metres(unit: str) -> bool:
    return unit.strip().lower() in {"m", "mtr", "mtrs", "metre", "metres"}


def rows_from_import(imported: dict[str, Any], catalog_lookup: Any = None) -> list[dict[str, Any]]:
    """One editor row per printed line, in the shape `match_items` returns.

    The code is the printed code, the quantity is 1 x the printed quantity and the
    comment is the printed comment -- nothing is matched, inferred or split from the
    comment text. `length_locked` makes pricing quote the printed length exactly
    rather than snapping it to a prepack or MOQ, because it has already been quoted.

    Only blocking reader flags become row warnings (a line the reader could not
    transcribe cleanly). Source notes such as "quote an additional 50m" are already in
    the comment box, and repeating them as warnings is noise.

    `imported_line` rides along with the row and survives the editor's save: it is the
    printed record (code, quantity, comment, historical prices, page) that the
    ALI-FLEX step compares against later.
    """
    header = imported.get("header") or {}
    file_name = imported.get("file_name") or ""
    rows = []
    for line in imported.get("lines") or []:
        code = line.get("product_code") or ""
        quantity = parse_money(line.get("quantity") or "")
        total = int_or_decimal(quantity)
        unit = line.get("unit") or ""
        warnings = [f["message"] for f in line.get("flags") or [] if f.get("severity") == SEVERITY_BLOCKING]
        if unit and not _is_metres(unit):
            warnings.append(f"Printed unit is {unit!r}, not metres; check the quantity.")
        product = catalog_lookup(code) if (catalog_lookup and code) else None
        if code and catalog_lookup and product is None:
            warnings.append(f"{code} is not in the product catalogue; it is kept exactly as printed.")
        description = " ".join(line.get("description_lines") or [])
        source_text = " ".join(part for part in (code, line.get("colour") or "", description) if part)
        rows.append(
            {
                "source": {
                    "file_name": file_name,
                    "row_number": line["line_index"] + 1,
                    "original_text": source_text,
                    "entry_index": 1,
                    "split_count": 1,
                    "split_reason": "",
                },
                "raw_text": source_text,
                "normalized_text": source_text,
                "item_type": "cable",
                "line_kind": "cable",
                "status": "matched" if code and not warnings else "flagged",
                "confidence": 1.0 if code else 0.0,
                "fields": {
                    "exact_product_code": code,
                    "length_each_m": total,
                    "number_of_lengths": 1,
                    "total_length_m": total,
                    "uom": "m",
                },
                "field_sources": {
                    key: "source_quote"
                    for key in ("exact_product_code", "length_each_m", "number_of_lengths", "total_length_m", "uom")
                },
                "selected_product_code": code,
                "selected_product": product,
                "style_candidates": [],
                "warnings": warnings,
                "inferred_fields": [],
                "length": {
                    "uom": "m",
                    "length_each_m": total,
                    "number_of_lengths": 1,
                    "total_length_m": total,
                    "quantity": None,
                    "cuts_explicit": False,
                    "length_each": total,
                    "total_length": total,
                },
                "length_locked": True,
                "parse_tier": "quote_import",
                "line_note": " ".join(line.get("comment_lines") or []),
                "imported_line": {
                    "template": imported.get("template"),
                    "source_quote_number": header.get("quote_number", ""),
                    "source_currency": header.get("currency", ""),
                    "file_sha256": imported.get("file_sha256", ""),
                    "item_number": line.get("item_number", ""),
                    "product_code": code,
                    "colour": line.get("colour", ""),
                    "quantity": line.get("quantity", ""),
                    "unit": unit,
                    "unit_detail": line.get("unit_detail", ""),
                    "comment": line.get("comment", ""),
                    "construction_text": line.get("construction_text", ""),
                    # Historical selling prices: comparison evidence only, never a cost
                    # or a sell price on this draft.
                    "historical_unit_price": line.get("unit_price", ""),
                    "historical_line_amount": line.get("line_amount", ""),
                    "pages": (line.get("provenance") or {}).get("pages", []),
                },
            }
        )
    return rows


def int_or_decimal(value: Decimal | None) -> float | int | None:
    if value is None:
        return None
    return int(value) if value == value.to_integral_value() else float(value)


def read_upload_quote(data: bytes, file_name: str = "") -> dict[str, Any] | None:
    """The imported quote if these bytes are a recognised TriCab quotation, else None.

    Called on every uploaded file before the ordinary RFQ parser. Recognition is by the
    document itself -- the PDF signature, then detect_template's TriCab identity, column
    headings and title/total lines -- never by file name. A quote read by the generic
    parser comes out as ~6 fragments per printed line (headings, page numbers, doubled
    bold text, description and SKU apart), so a recognised quote must not reach it.
    """
    if not data or not data[:1024].lstrip().startswith(b"%PDF-") or len(data) > MAX_IMPORT_BYTES:
        return None
    try:
        imported = read_quote_pdf(data, file_name=file_name)
    except QuoteImportError:
        return None
    return None if imported["status"] == STATUS_UNSUPPORTED else imported


#: How 4js names a quotation PDF it sends: `TRICAB_Quotation_738865.pdf`, sometimes with a
#: browser's ` (1)` from a second download.
_QUOTE_FILE_NAME_RE = re.compile(
    r"^TRICAB[\s_-]*QUOTATION[\s_-]*(\d+(?:\.\d+)?)(?:\s*\(\d+\))?\.pdf$", re.IGNORECASE
)
STATUS_UNREADABLE = "unreadable"


def quote_number_from_file_name(file_name: str) -> str:
    """`738865` for `TRICAB_Quotation_738865.pdf`, else `''`."""
    match = _QUOTE_FILE_NAME_RE.match(Path(file_name or "").name.strip())
    return match.group(1) if match else ""


def unreadable_quote_result(file_name: str) -> dict[str, Any] | None:
    """The upload result for a PDF named like a TriCab quotation whose layout was not
    recognised, or None when the name is not a quotation's.

    Recognition stays by content: it still works when a rep renames the file, and for
    Dynamics quotes. The name is a backstop for the case content cannot catch, a layout
    this reader has not seen (an older 4js template, a scan). Without it that PDF goes to
    the RFQ parser and comes back as roughly six junk rows per printed line. With it, no
    line is read and the reason is said, in the shape of a quote import so that nothing
    queues a background re-read of it either.
    """
    number = quote_number_from_file_name(file_name)
    if not number:
        return None
    name = Path(file_name).name
    return {
        "ok": True,
        "results": [],
        "errors": [{"file_name": name, "error": (
            f"'{name}' is named like TriCab quotation {number}, but its layout was not "
            "recognised, so none of its lines were read. Enter the lines by hand, or send the "
            "PDF to the RFQ team so the layout can be added.")}],
        "llm_calls": 0,
        "quote_import": {"file_name": name, "template": None, "quote_number": number,
                         "status": STATUS_UNREADABLE, "line_count": 0, "label": ""},
    }


def import_problems(imported: dict[str, Any]) -> list[dict[str, str]]:
    """Quote-level problems as upload `errors` entries, for the editor's status line."""
    name = imported.get("file_name") or "quote.pdf"
    return [{"file_name": name, "error": flag["message"]} for flag in imported.get("flags") or []
            if flag.get("severity") == SEVERITY_BLOCKING]


def imported_quote_details(imported: dict[str, Any], *, resolve_customer=None) -> dict[str, Any]:
    """Draft header from printed evidence; never reuse the old quote's sync identity."""
    header = imported.get("header") or {}
    currency = str(header.get("currency") or "").upper()
    details = {
        "customer_id": "",
        "salesoffice": "",
        "phone": "",
        "customer_code": header.get("customer_code") or "",
        "customer_name": header.get("customer_name") or "",
        "contact": header.get("attention") or "",
        "cusref": header.get("customer_ref") or "",
        "comment": "\n".join(header.get("comments") or []),
        "currency": currency,
        "imported_quote_header": dict(header),
    }
    for line in header.get("contact_lines") or []:
        match = re.match(r"(?:Ph(?:one)?|Tel)\s*:\s*(.+)", line, re.I)
        if match:
            details["phone"] = match.group(1).strip()
            break
    # Dynamics account numbers belong to a different system; never use them as 4js IDs.
    code = details["customer_code"] if imported.get("template") == "4js" else ""
    if code:
        if resolve_customer is None:
            def resolve_customer(**_kwargs):
                return None
        try:
            resolved = resolve_customer(customer_code=code, contact_name=details["contact"])
            customer = (resolved or {}).get("customer") or {}
            if str(customer.get("code") or "").casefold() == str(code).casefold():
                details.update(customer_id=customer["id"], customer_code=customer["code"],
                               customer_name=customer.get("name") or details["customer_name"],
                               salesoffice=customer.get("sales_office") or "")
        except Exception:
            pass  # Printed details remain available for review if lookup is unavailable.
    if currency in {"AUD", "NZD"}:
        details["region"] = "newzealand" if currency == "NZD" else "australia"
    return details


def quote_upload_result(
    imported: dict[str, Any], *, price_rows: Any = None, catalog_lookup: Any = None
) -> dict[str, Any]:
    """Priced editor rows for a recognised quote, in the upload's response shape.

    `price_rows` and `catalog_lookup` default to the pipeline's; they are injectable so
    a test needs neither the catalogue nor the Cost API.
    """
    if price_rows is None:
        from rfq_parser.pipeline import apply_quote_pricing
        header = imported.get("header") or {}
        currency = header.get("currency")
        region = "newzealand" if currency == "NZD" else "australia"
        def price_rows(rows):
            apply_quote_pricing(rows, region=region, currency=currency)
    if catalog_lookup is None:
        from rfq_parser.pipeline import catalog

        def catalog_lookup(code: str, _by_code=catalog.products_by_code) -> dict[str, Any] | None:
            # `.public()` is the row form of a catalogue Product, as the resolver writes it.
            product = _by_code.get(code)
            return product.public() if product is not None else None
    rows = rows_from_import(imported, catalog_lookup)
    price_rows(rows)
    header = imported.get("header") or {}
    return {
        "ok": True,
        "results": rows,
        "errors": import_problems(imported),
        "llm_calls": 0,
        "quote_import": {
            "file_name": imported.get("file_name") or "",
            "template": imported.get("template"),
            "quote_number": header.get("quote_number", ""),
            "status": imported.get("status"),
            "line_count": len(rows),
            "label": import_label(imported),
            "quote_details": imported_quote_details(imported),
        },
    }


def upload_draft_identity(quote_imports: list[dict[str, Any]], file_count: int) -> tuple[str, str]:
    """`(source, label)` for an upload's draft.

    Only an upload made entirely of recognised quotes is a `quote_import` draft, named
    after the quote. A quote uploaded together with RFQ files is an ordinary upload
    that happens to contain the quote's lines, and keeps the usual derived label.
    """
    if quote_imports and len(quote_imports) == file_count:
        return SOURCE_QUOTE_IMPORT, " + ".join(q["label"] for q in quote_imports if q.get("label"))
    return "web", ""
