"""Tri-AP's five purposes (Phase 6, T6.8, decision Q8).

The prompts, the model and the temperature are the source's own (`server/extraction.py` at `2db2411`): the same
words, `google/gemini-2.5-flash`, temperature 0, and the same post-processing of the model's reply (the brace search,
`normalize_currency`, `normalize_date`, the confidence clamp, the quoted-boolean rule). The prompts below were
copied from the source by a script and are compared with it, byte for byte, by `tests/finance_ai/test_ap_purposes.py`;
do not tidy them. What changed is where they live and how they are fed: PHP sends **data** (the email's fields, the
invoice's fields, the company list, one file as base64) and never a prompt, and the browser's own `prompt` on
`/api/extract` is deliberately not carried (decision Q8). Unlike Tri-AR's prompts these do NOT wrap user text in data
tags: moving the source's wording unchanged is the point of this port.

| Purpose                 | Source function                | Data                                                                 | Answer |
|-------------------------|--------------------------------|----------------------------------------------------------------------|--------|
| `ap_classify_extract`   | `classify_and_extract_invoice` | `subject`, `sender`, `companies[]`, `file{name, content_type, data}` | `is_invoice`, `classification_reason`, `classification_confidence` and the 14 invoice fields |
| `ap_extract`            | `extract_invoice_fields`       | `companies[]`, `file{name, content_type, data}`                      | the 14 invoice fields |
| `ap_vendor_email_draft` | `draft_vendor_email`           | `invoice{...}`, `instruction`, `previous_draft`                      | `subject`, `body` |
| `ap_reply_draft`        | `draft_email_reply`            | `email{...}`, `instruction`, `previous_draft`                        | `subject`, `body` |
| `ap_reply_suggest`      | `suggest_email_reply`          | `email{...}`                                                         | `body` |

`companies[]` is the source's `_active_company_names()`: `{name, sites, former_names}` per active company (a bare
string is accepted too, as `company_clause` accepts it). Deliberately not carried: `INVOICE_LLM_MODEL` and
`INVOICE_LLM_TIMEOUT_SECONDS` (the model is fixed per purpose; the timeout is the source's default, 60 s).
"""

from __future__ import annotations

import json
import re
from datetime import date, datetime
from typing import Any

from .. import registry
from ..registry import FoundrySpec, InvalidData, Purpose, nullable, strict_object

# `INVOICE_LLM_MODEL`'s default and the payload's `"temperature": 0` in the source.
MODEL = "google/gemini-2.5-flash"
TEMPERATURE = 0

# At most this many companies are offered to the model (`store.list_rows("companies", limit=200)`).
MAX_COMPANIES = 200


class ExtractionError(ValueError):
    """The source's `ExtractionError`, a ValueError here so the provider reports it as a `bad_model_answer`."""


# --------------------------------------------------------------------------
# The source's prompts (copied from `server/extraction.py`)
# --------------------------------------------------------------------------

EXTRACTION_PROMPT = """You are an AP invoice data extraction specialist. Extract all invoice data from this document.
Return ONLY a JSON object with these exact fields (use null for any field not found):
{
  "invoice_no": "invoice number",
  "vendor": "vendor/supplier company name",
  "company": "which TriCab entity the invoice is billed TO — see the entity list below; null if none of them",
  "vendor_email": "the supplier's contact or remittance email address printed on the invoice, or null",
  "tax_id": "ABN or tax ID number",
  "invoice_date": "YYYY-MM-DD format",
  "due_date": "YYYY-MM-DD format or null",
  "po_number": "purchase order number or null",
  "currency": "3-letter currency code e.g. AUD, USD",
  "net_amount": numeric value without currency symbol,
  "tax_amount": numeric GST/tax amount,
  "total_amount": numeric total amount,
  "extraction_confidence": number from 0 to 1 — how confident you are that the fields above are correct,
  "notes": "any important notes or extraction warnings"
}"""

CLASSIFICATION_PROMPT = """You classify document attachments for an accounts-payable mailbox.
Decide whether the attached PDF or image is an invoice or tax invoice that should be processed for payment.
Purchase orders, statements, remittance advices, receipts with no amount due, quotes, reports, marketing material,
logos, and unrelated documents are not invoices. Do not guess.

Email subject: {subject}
Email sender: {sender}

Return ONLY JSON using this shape:
{{
  "is_invoice": true or false,
  "classification_reason": "short factual reason",
  "classification_confidence": number from 0 to 1,
  "invoice_no": "invoice number or null",
  "vendor": "vendor/supplier company name or null",
  "company": "which TriCab entity the invoice is billed TO — see the entity list below; null if none of them",
  "vendor_email": "the supplier's contact or remittance email printed on the invoice or null",
  "tax_id": "ABN or tax ID number or null",
  "invoice_date": "YYYY-MM-DD or null",
  "due_date": "YYYY-MM-DD or null",
  "po_number": "purchase order number or null",
  "currency": "3-letter currency code or null",
  "net_amount": numeric value or null,
  "tax_amount": numeric value or null,
  "total_amount": numeric value or null,
  "notes": "important notes or extraction warnings"
}}"""

# `draft_vendor_email`'s f-string, with each `{invoice.get(...) ...}` expression named; `_build_vendor_email_draft`
# evaluates the same expressions.
VENDOR_EMAIL_PROMPT = """You draft concise, professional accounts-payable emails for TriCab.
Return ONLY JSON with `subject` and `body` fields. The body must be plain text, not HTML.
Never claim the email has already been sent. Do not invent invoice facts.

Vendor: {vendor}
Invoice number: {invoice_no}
Invoice date: {invoice_date}
Purchase order: {po_number}
Currency and total: {currency} {total_amount}
Original subject: {subject}

User's request/story:
{instruction}

Existing draft to revise, if any:
{previous_draft}
"""

# `draft_email_reply`'s f-string, likewise.
REPLY_DRAFT_PROMPT = """You draft concise, professional accounts-payable email replies for TriCab.
Return ONLY JSON with `subject` and `body` fields. The body must be plain text, not HTML.
Never claim the email has already been sent. Do not invent invoice numbers, amounts or dates
that are not in the sender's email or the user's instructions.
Do NOT leave placeholders such as [Your Name] or [Date].
End the body with a closing line such as "Regards," and nothing after it: the ap@tricab.com
mailbox appends its own signature block.

From: {sender_name} <{sender}>
Original subject: {subject}

The sender's email:
{body}

What the user wants the reply to say:
{instruction}

Existing draft to revise, if any:
{previous_draft}
"""

# `suggest_email_reply`'s f-string, likewise.
REPLY_SUGGEST_PROMPT = """You draft concise, professional accounts-payable email replies for TriCab.
Read the email below and write the reply TriCab would most likely send. Nobody has told you
what to say -- infer it from the email itself.
Return ONLY JSON with a `body` field. The body must be plain text, not HTML.
Never claim the email has already been sent, actioned or paid. Do not invent invoice numbers,
amounts, dates or commitments that are not in the sender's email.
Do NOT leave placeholders such as [Your Name] or [Date].
If the email plainly needs no reply -- an automated notification, a no-reply receipt, marketing --
return an empty body rather than inventing pleasantries.
End the body with a closing line such as "Regards," and nothing after it: the ap@tricab.com
mailbox appends its own signature block.

From: {sender_name} <{sender_address}>
Subject: {subject}

The email:
{body}
"""


# --------------------------------------------------------------------------
# The source's helpers (copied from `server/extraction.py`)
# --------------------------------------------------------------------------

def company_clause(companies: list[str] | None) -> str:
    """Constrain `company` to a choice from the configured entities.

    Suppliers write the buying entity many ways -- "TRICAB AUST PTY LTD",
    "TriCab (Australia) P/L", "Tricab Nz Limited". Asking for free text and
    string-matching afterwards missed a quarter of them; naming the entities in
    the prompt lets the model do the mapping it is actually good at. The reply
    is still validated against the list server-side -- a model that invents an
    entity must not be able to write one into the register.
    """
    if not companies:
        return ""
    lines = []
    for entry in companies:
        if isinstance(entry, str):
            lines.append(f"- {entry}")
            continue
        parts = []
        if (entry.get("former_names") or "").strip():
            parts.append(f"formerly {entry['former_names'].strip()}")
        if (entry.get("sites") or "").strip():
            parts.append(f"sites: {entry['sites'].strip()}")
        suffix = f"  ({'; '.join(parts)})" if parts else ""
        lines.append(f"- {entry['name']}{suffix}")
    listed = "\n".join(lines)
    return (
        "\n\nThe invoice is billed to one of these TriCab entities. For `company`, "
        "return one of these names copied EXACTLY, or null.\n"
        f"{listed}\n"
        "Spelling noise -- case, brackets, missing spaces, PTY/P/L/LTD/LIMITED, "
        "ACN or ABN suffixes -- does not make it a different entity. A TriCab "
        "depot, branch or site named in the delivery or bill-to block identifies "
        "the entity that operates it, even when the legal name never appears: "
        "freight invoices often say only the site. A former name identifies the "
        "entity it was renamed from, but match the WHOLE former name -- an "
        "unrelated supplier may share one word of it. But do not guess: return null "
        "if the document names no entity and no known site, names only \"TriCab\" "
        "with no country or site, or names more than one entity."
    )


_EXPECTED_FIELDS = [
    "invoice_no", "vendor", "company", "vendor_email", "tax_id", "invoice_date", "due_date", "po_number",
    "currency", "net_amount", "tax_amount", "total_amount", "extraction_confidence", "notes",
]

_CLASSIFICATION_FIELDS = [
    "is_invoice", "classification_reason", "classification_confidence", *_EXPECTED_FIELDS,
]


def _confidence(value: object) -> float:
    """A model's self-reported confidence, clamped to 0..1.

    Absent, unparseable or out of range all become 0.0. A model that did not
    say how sure it was has not said it was sure, and defaulting the other way
    is what lets a bad read through unexamined.
    """
    try:
        number = float(value)  # type: ignore[arg-type]
    except (TypeError, ValueError):
        return 0.0
    return min(max(number, 0.0), 1.0)


def _parse_json_reply(text: str) -> dict:
    text = text.strip()
    fenced = re.search(r"```(?:json)?\s*(.*?)```", text, re.DOTALL)
    if fenced:
        text = fenced.group(1).strip()
    start = text.find("{")
    end = text.rfind("}")
    if start == -1 or end == -1:
        raise ExtractionError(f"Model reply contained no JSON object: {text[:200]}")
    return json.loads(text[start : end + 1])


_CURRENCY_ALIASES = {
    "$": "AUD", "A$": "AUD", "AU$": "AUD", "AUD$": "AUD",
    "US$": "USD", "USD$": "USD", "NZ$": "NZD", "NZD$": "NZD",
    "€": "EUR", "EURO": "EUR", "£": "GBP",
}


def normalize_currency(value: object, fallback: str = "AUD") -> str:
    """Return a safe ISO 4217-style code for storage and UI formatting."""
    fallback_code = str(fallback or "AUD").strip().upper()
    if not re.fullmatch(r"[A-Z]{3}", fallback_code):
        fallback_code = "AUD"
    if not isinstance(value, str):
        return fallback_code
    raw = value.strip().upper()
    normalized = _CURRENCY_ALIASES.get(raw, raw)
    return normalized if re.fullmatch(r"[A-Z]{3}", normalized) else fallback_code


_WRITTEN_DATE_FORMATS = ("%d %b %Y", "%d %B %Y", "%b %d %Y", "%B %d %Y", "%d %b %y", "%d %B %y")


def normalize_date(value: object) -> str | None:
    """A yyyy-mm-dd string for a date the model read off a document, or None.

    Dates are compared as text -- a due date before today's is overdue -- so
    one stored as "01/10/2026" sorted before "2026-09-27" and went overdue the
    moment it was captured, and "30/09/2026" never did. The prompt asks for
    ISO, but that is a request, not a guarantee. Slashed and dashed dates are
    read day first, as Australian invoices write them; anything unreadable is
    dropped rather than stored as text that sorts wrongly.
    """
    if isinstance(value, (date, datetime)):
        return value.strftime("%Y-%m-%d")
    if not isinstance(value, str) or not value.strip():
        return None
    raw = value.strip()
    match = re.match(r"^(\d{4})-(\d{1,2})-(\d{1,2})(?:$|[T ])", raw)
    if match:
        parts = (int(match[1]), int(match[2]), int(match[3]))
    else:
        match = re.fullmatch(r"(\d{1,2})[/.-](\d{1,2})[/.-](\d{2}|\d{4})", raw)
        if match:
            year = int(match[3])
            parts = (year + 2000 if year < 100 else year, int(match[2]), int(match[1]))
        else:
            cleaned = re.sub(r"(\d)(st|nd|rd|th)\b", r"\1", raw.replace(",", " "), flags=re.I)
            cleaned = " ".join(cleaned.split())
            for fmt in _WRITTEN_DATE_FORMATS:
                try:
                    return datetime.strptime(cleaned, fmt).strftime("%Y-%m-%d")
                except ValueError:
                    continue
            return None
    try:
        return date(*parts).isoformat()
    except ValueError:
        return None


# --------------------------------------------------------------------------
# Reading data
# --------------------------------------------------------------------------


def _text(value: Any, field: str, *, required: bool = False, limit: int = 100_000) -> str:
    if value is None:
        value = ""
    if not isinstance(value, str):
        raise InvalidData(f"{field} must be text.")
    if required and not value.strip():
        raise InvalidData(f"{field} is required.")
    if len(value) > limit:
        raise InvalidData(f"{field} is too long.")
    return value


def _object(value: Any, field: str) -> dict[str, Any]:
    if not isinstance(value, dict):
        raise InvalidData(f"{field} must be an object.")
    return value


def _scalar(value: Any, field: str) -> Any:
    """A value the source printed with `{...}`: text or a number (or nothing), never a structure."""
    if value is None or isinstance(value, (str, int, float)):
        return value
    raise InvalidData(f"{field} must be text or a number.")


def _companies(value: Any) -> list[Any]:
    """The `companies` the source passed to `company_clause`: `{name, sites, former_names}` objects or bare names."""
    if value is None:
        return []
    if not isinstance(value, list):
        raise InvalidData("companies must be a list.")
    if len(value) > MAX_COMPANIES:
        raise InvalidData("companies has too many entries.")
    out: list[Any] = []
    for entry in value:
        if isinstance(entry, str):
            out.append(entry)
            continue
        entry = _object(entry, "companies[]")
        out.append({
            "name": _text(entry.get("name"), "companies[].name", required=True, limit=500),
            "sites": _text(entry.get("sites"), "companies[].sites", limit=5000),
            "former_names": _text(entry.get("former_names"), "companies[].former_names", limit=5000),
        })
    return out


def _file_data(data: dict[str, Any]) -> None:
    registry.file_part(data.get("file"))  # refuse a bad file before any prompt is built


def _file_parts(data: dict[str, Any]) -> list[dict[str, Any]]:
    return [registry.file_part(data.get("file"))]


def _as_object(parsed: Any) -> dict[str, Any]:
    if not isinstance(parsed, dict):
        raise ValueError("The reply is not a JSON object.")
    return parsed


def _reply_fields(raw: Any) -> dict[str, Any]:
    if not isinstance(raw, str):
        raise ValueError("The reply is not text.")
    return _as_object(_parse_json_reply(raw))


# --------------------------------------------------------------------------
# ap_classify_extract
# --------------------------------------------------------------------------

# Untyped: the model may answer null (or, now and then, a number for a text field) and the source passed it on.
_INVOICE_FIELD_SCHEMA: dict[str, Any] = {key: {} for key in _EXPECTED_FIELDS}
_INVOICE_FIELD_SCHEMA["currency"] = {"type": "string"}

EXTRACT_SCHEMA = {
    "type": "object",
    "properties": {**_INVOICE_FIELD_SCHEMA, "extraction_confidence": {"type": "number"}},
    "required": list(_EXPECTED_FIELDS),
}

CLASSIFY_EXTRACT_SCHEMA = {
    "type": "object",
    "properties": {
        **_INVOICE_FIELD_SCHEMA,
        "is_invoice": {"type": "boolean"},
        "classification_reason": {},
        "classification_confidence": {"type": "number"},
    },
    "required": list(_CLASSIFICATION_FIELDS),
}


def _build_classify_extract(data: dict[str, Any]) -> str:
    _file_data(data)
    subject = _text(data.get("subject"), "subject")
    sender = _text(data.get("sender"), "sender")
    prompt = CLASSIFICATION_PROMPT.format(subject=subject[:500], sender=sender[:300])
    prompt += company_clause(_companies(data.get("companies")))
    return prompt


def parse_classify_extract(raw: Any, data: dict[str, Any]) -> dict[str, Any]:
    """`classify_and_extract_invoice`'s reading of the reply, unchanged."""
    fields = _reply_fields(raw)
    result = {key: fields.get(key) for key in _CLASSIFICATION_FIELDS}
    result["currency"] = normalize_currency(result.get("currency"))
    for key in ("invoice_date", "due_date"):
        if key in result:
            result[key] = normalize_date(result[key])
    # Models occasionally quote the boolean; "true" is still a yes.
    is_invoice = result["is_invoice"]
    result["is_invoice"] = is_invoice is True or (
        isinstance(is_invoice, str) and is_invoice.strip().lower() == "true"
    )
    result["classification_confidence"] = _confidence(result.get("classification_confidence"))
    return result


# --------------------------------------------------------------------------
# ap_extract
# --------------------------------------------------------------------------


def _build_extract(data: dict[str, Any]) -> str:
    _file_data(data)
    # No caller-supplied prompt (decision Q8): the source's `prompt or EXTRACTION_PROMPT` is always the latter.
    return EXTRACTION_PROMPT + company_clause(_companies(data.get("companies")))


def parse_extract(raw: Any, data: dict[str, Any]) -> dict[str, Any]:
    """`extract_invoice_fields`'s reading of the reply, unchanged."""
    fields = _reply_fields(raw)
    result = {key: fields.get(key) for key in _EXPECTED_FIELDS}
    result["currency"] = normalize_currency(result.get("currency"))
    for key in ("invoice_date", "due_date"):
        if key in result:
            result[key] = normalize_date(result[key])
    result["extraction_confidence"] = _confidence(result.get("extraction_confidence"))
    return result


# --------------------------------------------------------------------------
# ap_vendor_email_draft
# --------------------------------------------------------------------------

DRAFT_SCHEMA = {
    "type": "object",
    "properties": {"subject": {"type": "string"}, "body": {"type": "string"}},
    "required": ["subject", "body"],
}

SUGGEST_SCHEMA = {
    "type": "object",
    "properties": {"body": {"type": "string"}},
    "required": ["body"],
}

INSTRUCTION_CHARS = 4000
PREVIOUS_DRAFT_CHARS = 6000
EMAIL_BODY_CHARS = 6000


def _invoice(data: dict[str, Any]) -> dict[str, Any]:
    invoice = _object(data.get("invoice"), "invoice")
    return {key: _scalar(invoice.get(key), f"invoice.{key}") for key in (
        "vendor", "invoice_no", "invoice_date", "po_number", "currency", "total_amount", "subject",
    )}


def _build_vendor_email_draft(data: dict[str, Any]) -> str:
    invoice = _invoice(data)
    instruction = _text(data.get("instruction"), "instruction", required=True)
    previous_draft = _text(data.get("previous_draft"), "previous_draft")
    return VENDOR_EMAIL_PROMPT.format(
        vendor=invoice.get("vendor") or "Unknown",
        invoice_no=invoice.get("invoice_no") or "Unknown",
        invoice_date=invoice.get("invoice_date") or "Unknown",
        po_number=invoice.get("po_number") or "Unknown",
        currency=invoice.get("currency") or "AUD",
        total_amount=invoice.get("total_amount") or 0,
        subject=invoice.get("subject") or "",
        instruction=instruction[:INSTRUCTION_CHARS],
        previous_draft=previous_draft[:PREVIOUS_DRAFT_CHARS],
    )


def parse_vendor_email_draft(raw: Any, data: dict[str, Any]) -> dict[str, Any]:
    fields = _reply_fields(raw)
    invoice = _invoice(data)
    return {
        "subject": str(fields.get("subject") or f"Question about invoice {invoice.get('invoice_no') or ''}").strip(),
        "body": str(fields.get("body") or "").strip(),
    }


# --------------------------------------------------------------------------
# ap_reply_draft and ap_reply_suggest
# --------------------------------------------------------------------------


def _email(data: dict[str, Any]) -> dict[str, str]:
    email = _object(data.get("email"), "email")
    return {key: _text(email.get(key), f"email.{key}") for key in (
        "sender_name", "sender", "sender_email", "subject", "body_preview", "body",
    )}


def _email_body(email: dict[str, str]) -> str:
    return (email.get("body_preview") or email.get("body") or "")[:EMAIL_BODY_CHARS]


def _build_reply_draft(data: dict[str, Any]) -> str:
    email = _email(data)
    instruction = _text(data.get("instruction"), "instruction", required=True)
    previous_draft = _text(data.get("previous_draft"), "previous_draft")
    return REPLY_DRAFT_PROMPT.format(
        sender_name=email.get("sender_name") or "",
        sender=email.get("sender") or "",
        subject=email.get("subject") or "",
        body=_email_body(email),
        instruction=instruction[:INSTRUCTION_CHARS],
        previous_draft=previous_draft[:PREVIOUS_DRAFT_CHARS] or "(none)",
    )


def parse_reply_draft(raw: Any, data: dict[str, Any]) -> dict[str, Any]:
    fields = _reply_fields(raw)
    email = _email(data)
    subject = str(fields.get("subject") or "").strip()
    if not subject:
        original = (email.get("subject") or "").strip()
        subject = original if original.lower().startswith("re:") else f"Re: {original}"
    return {"subject": subject, "body": str(fields.get("body") or "").strip()}


def _build_reply_suggest(data: dict[str, Any]) -> str:
    email = _email(data)
    return REPLY_SUGGEST_PROMPT.format(
        sender_name=email.get("sender_name") or "",
        sender_address=email.get("sender_email") or email.get("sender") or "",
        subject=email.get("subject") or "",
        body=_email_body(email),
    )


def parse_reply_suggest(raw: Any, data: dict[str, Any]) -> dict[str, Any]:
    fields = _reply_fields(raw)
    return {"body": str(fields.get("body") or "").strip()}


# --------------------------------------------------------------------------
# The Claude-on-Foundry setup (plan O4): the same prompts and parsers, the answer constrained by a strict schema
# --------------------------------------------------------------------------

_TEXT, _NUMBER = nullable({"type": "string"}), nullable({"type": "number"})
_FOUNDRY_INVOICE_FIELDS = {
    "invoice_no": _TEXT, "vendor": _TEXT, "company": _TEXT, "vendor_email": _TEXT, "tax_id": _TEXT,
    "invoice_date": _TEXT, "due_date": _TEXT, "po_number": _TEXT, "currency": _TEXT,
    "net_amount": _NUMBER, "tax_amount": _NUMBER, "total_amount": _NUMBER,
}
FOUNDRY_EXTRACT_SCHEMA = strict_object({
    **_FOUNDRY_INVOICE_FIELDS, "extraction_confidence": {"type": "number"}, "notes": _TEXT,
})
FOUNDRY_CLASSIFY_EXTRACT_SCHEMA = strict_object({
    "is_invoice": {"type": "boolean"},
    "classification_reason": {"type": "string"},
    "classification_confidence": {"type": "number"},
    **_FOUNDRY_INVOICE_FIELDS,
    # The source's classification prompt asks for no extraction confidence; the parser reads it as 0.0 when absent.
    "notes": _TEXT,
})
FOUNDRY_DRAFT_SCHEMA = strict_object({"subject": {"type": "string"}, "body": {"type": "string"}})
FOUNDRY_SUGGEST_SCHEMA = strict_object({"body": {"type": "string"}})

# Reading a document is extraction (low effort); a draft someone may send is worth more thought (medium).
_FOUNDRY_READ = FoundrySpec(FOUNDRY_EXTRACT_SCHEMA, effort="low", max_tokens=16000)
_FOUNDRY_CLASSIFY_READ = FoundrySpec(FOUNDRY_CLASSIFY_EXTRACT_SCHEMA, effort="low", max_tokens=16000)
_FOUNDRY_DRAFT = FoundrySpec(FOUNDRY_DRAFT_SCHEMA, effort="medium")
_FOUNDRY_SUGGEST = FoundrySpec(FOUNDRY_SUGGEST_SCHEMA, effort="medium")


PURPOSES = (
    Purpose(
        "ap_classify_extract", _build_classify_extract, CLASSIFY_EXTRACT_SCHEMA, model=MODEL, temperature=TEMPERATURE,
        response_format="json_object", build_parts=_file_parts, parse=parse_classify_extract,
        foundry=_FOUNDRY_CLASSIFY_READ,
    ),
    Purpose(
        "ap_extract", _build_extract, EXTRACT_SCHEMA, model=MODEL, temperature=TEMPERATURE,
        response_format="json_object", build_parts=_file_parts, parse=parse_extract, foundry=_FOUNDRY_READ,
    ),
    Purpose(
        "ap_vendor_email_draft", _build_vendor_email_draft, DRAFT_SCHEMA, model=MODEL, temperature=TEMPERATURE,
        response_format="json_object", parse=parse_vendor_email_draft, foundry=_FOUNDRY_DRAFT,
    ),
    Purpose(
        "ap_reply_draft", _build_reply_draft, DRAFT_SCHEMA, model=MODEL, temperature=TEMPERATURE,
        response_format="json_object", parse=parse_reply_draft, foundry=_FOUNDRY_DRAFT,
    ),
    Purpose(
        "ap_reply_suggest", _build_reply_suggest, SUGGEST_SCHEMA, model=MODEL, temperature=TEMPERATURE,
        response_format="json_object", parse=parse_reply_suggest, foundry=_FOUNDRY_SUGGEST,
    ),
)

NAMES = [purpose.name for purpose in PURPOSES]


def register_all() -> list[str]:
    """Registers the five purposes that are not registered yet; returns the names added."""
    added = []
    for purpose in PURPOSES:
        if registry.get(purpose.name) is None:
            registry.register(purpose)
            added.append(purpose.name)
    return added
