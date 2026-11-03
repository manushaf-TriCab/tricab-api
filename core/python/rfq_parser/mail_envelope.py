"""`rfq-mail-envelope.v1`: one email, as the parser reads it (RFQ mail intake plan, M2).

Platform-owned. The mail reader (PHP, plan M3) builds an envelope from a Graph message
and sends it to `POST /v1/parse` as `email`; this module is the contract, the validator
and the pure normalisation that turns the raw body into what the parser and the
classifier read. The reading itself (HTML to text, tables, the original sender, the
inline-image filter, the loop guards) is the source's, generated into
`rfq_parser.mail_ingest`; nothing here re-implements it.

Field names are camelCase, like the `/v1/parse` request, and attachments are the
request's own `documents[]` shape (`documentPublicId`, `fileName`, `contentType`,
`contentBase64`) with `isInline` and `contentId` added. The full field list is in
`docs/rfq-mail-envelope.md` and `data/rfq-mail-envelope.v1.schema.json`.

**Two layers.** What a producer sends is the *raw* envelope: the body exactly as Graph
gives it (`body.contentType` + `body.content`). `normalise` adds what is derived from
it, under the same keys the schema allows on input, so a normalised envelope is also a
valid one: `body.text` (clean text, HTML tables rendered as ` | ` rows in place),
`body.tables` (every table as rows of cells, spans expanded), `forwardedChain` (the chain
split into parts, and the original sender) and `dedupeKey`.

No Flask, no I/O, no model.
"""

from __future__ import annotations

import base64
import binascii
import copy
import re
from typing import Any

from . import mail_ingest

CONTRACT = "rfq-mail-envelope.v1"

# Bounds. The parser refuses an envelope over any of them before reading it.
MAX_ID_CHARS = 998  # RFC 5322's line limit; a Message-ID is one header line
MAX_SUBJECT_CHARS = 998
MAX_BODY_CHARS = 2_000_000
MAX_RECIPIENTS = 500
MAX_DOCUMENTS = 40  # what an envelope may carry; /v1/parse still reads at most 10 (after the inline filter)

BODY_CONTENT_TYPES = ("html", "text")

_TOP_KEYS = frozenset(
    {
        "contract", "internetMessageId", "messageId", "conversationId", "receivedAt", "mailbox", "subject",
        "sender", "toRecipients", "ccRecipients", "body", "documents", "forwardedChain", "dedupeKey",
    }
)
_BODY_KEYS = frozenset({"contentType", "content", "text", "tables"})
_ADDRESS_KEYS = frozenset({"name", "address"})
_DOCUMENT_KEYS = frozenset({"documentPublicId", "fileName", "contentType", "contentBase64", "isInline", "contentId"})
_RECEIVED_AT = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$")


class EnvelopeError(ValueError):
    """The envelope is not a valid `rfq-mail-envelope.v1`. `path` names the field
    (`body.content`, `documents[2].fileName`); the message never carries content."""

    def __init__(self, path: str, message: str) -> None:
        super().__init__(f"{path}: {message}")
        self.path = path
        self.reason = message


# --- validation ------------------------------------------------------------------------


def _fail(path: str, message: str) -> EnvelopeError:
    return EnvelopeError(path, message)


def _string(value: Any, path: str, *, required: bool = False, maximum: int = MAX_SUBJECT_CHARS, allow_empty: bool = True) -> str | None:
    if value is None:
        if required:
            raise _fail(path, "is required")
        return None
    if not isinstance(value, str):
        raise _fail(path, "must be a string")
    if len(value) > maximum:
        raise _fail(path, f"is longer than {maximum} characters")
    if not allow_empty and not value.strip():
        raise _fail(path, "must not be empty")
    return value


def _address(value: Any, path: str, *, required: bool = False) -> dict[str, str] | None:
    if value is None:
        if required:
            raise _fail(path, "is required")
        return None
    if not isinstance(value, dict):
        raise _fail(path, "must be an object with name and address")
    extra = set(value) - _ADDRESS_KEYS
    if extra:
        raise _fail(path, f"has unknown field {sorted(extra)[0]}")
    address = _string(value.get("address"), f"{path}.address", required=True, maximum=320)
    name = _string(value.get("name"), f"{path}.name", maximum=500) or ""
    return {"name": name, "address": address or ""}


def _addresses(value: Any, path: str) -> list[dict[str, str]]:
    if value is None:
        return []
    if not isinstance(value, list):
        raise _fail(path, "must be a list")
    if len(value) > MAX_RECIPIENTS:
        raise _fail(path, f"has more than {MAX_RECIPIENTS} entries")
    return [_address(entry, f"{path}[{index}]", required=True) or {"name": "", "address": ""} for index, entry in enumerate(value)]


def _document(value: Any, path: str) -> dict[str, Any]:
    if not isinstance(value, dict):
        raise _fail(path, "must be an object")
    extra = set(value) - _DOCUMENT_KEYS
    if extra:
        raise _fail(path, f"has unknown field {sorted(extra)[0]}")
    public_id = _string(value.get("documentPublicId"), f"{path}.documentPublicId", required=True, maximum=200, allow_empty=False)
    name = _string(value.get("fileName"), f"{path}.fileName", required=True, maximum=1000, allow_empty=False)
    content_type = _string(value.get("contentType"), f"{path}.contentType", maximum=200) or ""
    encoded = value.get("contentBase64")
    if not isinstance(encoded, str):
        raise _fail(f"{path}.contentBase64", "is required and must be a base64 string")
    try:
        base64.b64decode(encoded, validate=True)
    except (binascii.Error, ValueError):
        raise _fail(f"{path}.contentBase64", "is not base64") from None
    inline = value.get("isInline", False)
    if not isinstance(inline, bool):
        raise _fail(f"{path}.isInline", "must be true or false")
    content_id = _string(value.get("contentId"), f"{path}.contentId", maximum=500)
    out: dict[str, Any] = {
        "documentPublicId": public_id,
        "fileName": name,
        "contentType": content_type,
        "contentBase64": encoded,
        "isInline": inline,
    }
    if content_id is not None:
        out["contentId"] = content_id
    return out


def validate(raw: Any) -> dict[str, Any]:
    """The envelope, checked and with its defaults filled, or `EnvelopeError`.

    Unknown fields are refused rather than ignored: a producer that misspells `toRecipients`
    would otherwise lose its recipients without a sound. Derived fields (`body.text`,
    `body.tables`, `forwardedChain`, `dedupeKey`) are accepted so a normalised envelope
    validates, and are always recomputed by `normalise`, never trusted.
    """
    if not isinstance(raw, dict):
        raise _fail("email", "must be an object")
    extra = set(raw) - _TOP_KEYS
    if extra:
        raise _fail(str(sorted(extra)[0]), "is not a field of rfq-mail-envelope.v1")
    if raw.get("contract") != CONTRACT:
        raise _fail("contract", f"must be {CONTRACT}")

    internet_id = _string(raw.get("internetMessageId"), "internetMessageId", required=True, maximum=MAX_ID_CHARS, allow_empty=False)
    message_id = _string(raw.get("messageId"), "messageId", maximum=MAX_ID_CHARS)
    conversation_id = _string(raw.get("conversationId"), "conversationId", maximum=MAX_ID_CHARS)
    mailbox = _string(raw.get("mailbox"), "mailbox", maximum=320)
    received_at = _string(raw.get("receivedAt"), "receivedAt", maximum=40)
    if received_at is not None and not _RECEIVED_AT.match(received_at):
        raise _fail("receivedAt", "must be an ISO 8601 date-time with a zone, such as 2026-10-14T01:02:03Z")
    subject = _string(raw.get("subject"), "subject") or ""

    body = raw.get("body")
    if not isinstance(body, dict):
        raise _fail("body", "is required and must be an object")
    extra_body = set(body) - _BODY_KEYS
    if extra_body:
        raise _fail(f"body.{sorted(extra_body)[0]}", "is not a field of rfq-mail-envelope.v1")
    content_type = body.get("contentType")
    if content_type not in BODY_CONTENT_TYPES:
        raise _fail("body.contentType", "must be html or text")
    content = _string(body.get("content"), "body.content", required=True, maximum=MAX_BODY_CHARS)

    documents_raw = raw.get("documents")
    if documents_raw is None:
        documents_raw = []
    if not isinstance(documents_raw, list):
        raise _fail("documents", "must be a list")
    if len(documents_raw) > MAX_DOCUMENTS:
        raise _fail("documents", f"has more than {MAX_DOCUMENTS} entries")
    documents = [_document(entry, f"documents[{index}]") for index, entry in enumerate(documents_raw)]
    ids = [d["documentPublicId"] for d in documents]
    if len(set(ids)) != len(ids):
        raise _fail("documents", "documentPublicId must be unique within the envelope")

    return {
        "contract": CONTRACT,
        "internetMessageId": internet_id,
        "messageId": message_id,
        "conversationId": conversation_id,
        "receivedAt": received_at,
        "mailbox": mailbox,
        "subject": subject,
        "sender": _address(raw.get("sender"), "sender", required=True),
        "toRecipients": _addresses(raw.get("toRecipients"), "toRecipients"),
        "ccRecipients": _addresses(raw.get("ccRecipients"), "ccRecipients"),
        "body": {"contentType": content_type, "content": content},
        "documents": documents,
    }


# --- identity --------------------------------------------------------------------------


def dedupe_key(envelope: dict[str, Any]) -> str:
    """The key a message is de-duplicated on: its `internetMessageId`, as the source's
    audit keys on it (`process_message`'s `internet_id`), without the angle brackets and
    case-folded so a client that re-cases it does not defeat the check. Falls back to the
    Graph `messageId` when there is none, as the source does. The store that uses it
    (`rfq_mail_audit` / `[mail]`, plan M3) is the reader's; this is only the key."""
    raw = str(envelope.get("internetMessageId") or envelope.get("messageId") or "").strip()
    if raw.startswith("<") and raw.endswith(">"):
        raw = raw[1:-1].strip()
    return raw.lower()


# --- the body: clean text, tables --------------------------------------------------------


def body_html(envelope: dict[str, Any]) -> str:
    body = envelope["body"]
    return body["content"] if body["contentType"] == "html" else ""


def body_plain(envelope: dict[str, Any]) -> str:
    """The source's `message_body_text`: HTML flattened, or the plain text trimmed."""
    body = envelope["body"]
    if body["contentType"] == "html":
        return mail_ingest.html_to_text(body["content"])
    return body["content"].strip()


def _render_table(table: list[list[str]]) -> str:
    return "\n".join(" | ".join(cell for cell in row).rstrip(" |") for row in table)


_TABLE_TAG = re.compile(r"<(/?)table\b[^>]*>", re.I)


def _top_level_tables(html_content: str) -> list[tuple[int, int]]:
    """`(start, end)` of each outermost `<table>…</table>`, nesting counted. (The source's own
    `strip_html_tables` stops at the first `</table>`, which is right for removing markup and
    wrong for a nested layout table, whose text it would cut in half.)"""
    spans: list[tuple[int, int]] = []
    depth = 0
    start = 0
    for tag in _TABLE_TAG.finditer(html_content):
        if tag.group(1):
            if depth:
                depth -= 1
                if depth == 0:
                    spans.append((start, tag.end()))
        else:
            if depth == 0:
                start = tag.start()
            depth += 1
    return spans


def render_text(html_content: str) -> str:
    """Clean text from an HTML body with each top-level table kept as its rows, in place.

    `html_to_text` alone glues a row's cells together (`<td>a</td><td>b</td>` reads `ab`),
    which is right for the line reader (tables are read from their cells, not from this
    text) but useless to a person or a classifier. Here a table becomes one line per row,
    cells joined with ` | `, where it stood. A table that reads as no rows (a layout wrapper)
    is left as markup, so its text is flattened rather than lost.
    """
    if not html_content:
        return ""
    pieces: list[str] = []
    cursor = 0
    for start, end in _top_level_tables(html_content):
        tables = mail_ingest.parse_html_tables(html_content[start:end])
        pieces.append(html_content[cursor:start])
        if tables:
            pieces.append("<br>" + "".join(f"<p>{_escape(_render_table(table))}</p>" for table in tables) + "<br>")
        else:
            pieces.append(html_content[start:end])
        cursor = end
    pieces.append(html_content[cursor:])
    return mail_ingest.html_to_text("".join(pieces))


def _escape(text: str) -> str:
    return text.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;").replace("\n", "<br>")


# --- the forwarded chain -------------------------------------------------------------------

_HEADER_KEYS = {
    "from": ("from", "de", "van", "von", "da"),
    "sent": ("sent", "date", "envoyé", "envoye", "verzonden", "gesendet", "datum", "data"),
    "to": ("to", "à", "a", "aan", "an"),
    "cc": ("cc", "kopie"),
    "subject": ("subject", "objet", "onderwerp", "betreff", "oggetto", "asunto"),
}
_KEY_OF = {alias: key for key, aliases in _HEADER_KEYS.items() for alias in aliases}
_HEADER_LINE = re.compile(r"^\s*\**([A-Za-zÀ-ÿ]{1,12})\**\s*:\s*\**\s*(.*?)\s*$")
_BOUNDARY = re.compile(
    r"^\s*(?:-{2,}\s*(?:original message|forwarded message|original-?nachricht|message d'origine)\s*-{2,}"
    r"|-{3,}\s*forwarded message\s*-{3,}|begin forwarded message:?|_{10,})\s*$",
    re.I,
)
_ON_WROTE = re.compile(r"^\s*(?:on|le|op|am)\s+.{5,200}?\b(?:wrote|a écrit|schreef|schrieb)\s*:\s*$", re.I)
_NAME_BEFORE_ADDRESS = re.compile(r"^[\s\"'<(\[]*(.*?)[\s\"'<(\[]*$")


def parse_address(text: str) -> dict[str, str] | None:
    """`{name, address}` from a header value (`Jane Doe <jane@x.com>`, `Jane [mailto:jane@x.com]`,
    a bare address), or None when it holds no address. The address is the source's own
    `_EMAIL_RE` match, lower-cased."""
    found = mail_ingest._EMAIL_RE.search(text or "")
    if not found:
        return None
    before = (text or "")[: found.start()]
    before = re.sub(r"mailto:?\s*$", "", before.strip(), flags=re.I)
    name = _NAME_BEFORE_ADDRESS.match(before.strip()).group(1).strip(" \"'<([") if before.strip() else ""
    return {"name": name, "address": found.group(0).lower()}


def _header_block(lines: list[str], start: int) -> tuple[dict[str, str], int] | None:
    """A forwarded-message header block beginning at `lines[start]` (a `From:` line):
    its fields, and the index of the first line after it. A block needs `From` plus at
    least two more recognised headers, so a sentence that merely starts `From:` is not one."""
    fields: dict[str, str] = {}
    index = start
    last_key = ""
    while index < len(lines):
        line = lines[index]
        if not line.strip():
            break
        match = _HEADER_LINE.match(line)
        key = _KEY_OF.get(match.group(1).lower()) if match else None
        if key and key not in fields:
            fields[key] = match.group(2)
            last_key = key
        elif last_key in ("to", "cc") and index > start and (";" in lines[index - 1] or lines[index - 1].rstrip().endswith(",")):
            fields[last_key] += " " + line.strip()  # a recipient list wrapped onto the next line
        else:
            break
        index += 1
    if "from" in fields and len(fields) >= 3:
        return fields, index
    return None


def split_forwarded_chain(text: str, sender: dict[str, str] | None = None, internal_domain: str | None = None, *, sender_text: str | None = None) -> dict[str, Any]:
    """The body text split into the message and each message it forwards or quotes.

    `parts[0]` is the top message, what the sender wrote (`kind: "top"`, `from` the
    envelope's sender). Every later part (`kind: "forwarded"`) opens at a forwarded-message
    marker, an Outlook header block (`From:` with `Sent:`/`Date:`, `To:`, `Subject:` and the
    like) or a `On <date>, <name> wrote:` line, and runs to the next one. `from` is the
    header's sender, `sentAt`, `to` and `subject` are the header's own strings (not parsed:
    mail clients write dates in every locale), and `text` is what follows the header.

    `originalSender` is the customer the chain came from, found by the source's own rule
    (`mail_ingest.extract_original_sender`: the first external `From:` line, else the first
    external address in the body), so the platform and the old application agree. Its `name`
    is filled when a part's header carried one for that address. `sender_text`, when given, is the
    text that rule reads instead of `text` (the source reads the body flattened; `text` may carry
    tables as rows).
    """
    internal = internal_domain if internal_domain is not None else mail_ingest.INTERNAL_DOMAIN
    lines = (text or "").replace("\r\n", "\n").split("\n")
    parts: list[dict[str, Any]] = []
    current: dict[str, Any] = {"kind": "top", "from": sender, "sentAt": None, "to": None, "subject": None, "lines": []}
    index = 0
    while index < len(lines):
        line = lines[index]
        block = None
        if _BOUNDARY.match(line):
            # A marker: the header block follows it, possibly after a blank line.
            probe = index + 1
            while probe < len(lines) and not lines[probe].strip():
                probe += 1
            block = _header_block(lines, probe) if probe < len(lines) else None
            if block:
                index = probe
            else:
                index += 1
                continue
        elif _HEADER_LINE.match(line) and _KEY_OF.get((_HEADER_LINE.match(line).group(1) or "").lower()) == "from":
            block = _header_block(lines, index)
        elif _ON_WROTE.match(line):
            parts.append(_closed(current))
            current = {"kind": "forwarded", "from": parse_address(line), "sentAt": None, "to": None, "subject": None, "lines": []}
            index += 1
            continue
        if block:
            fields, after = block
            parts.append(_closed(current))
            current = {
                "kind": "forwarded",
                "from": parse_address(fields.get("from", "")),
                "sentAt": fields.get("sent") or None,
                "to": fields.get("to") or None,
                "subject": fields.get("subject") or None,
                "lines": [],
            }
            index = after
            continue
        current["lines"].append(line)
        index += 1
    parts.append(_closed(current))
    parts = [part for part in parts if part["kind"] == "top" or part["text"] or part["from"] or part["subject"]]
    for number, part in enumerate(parts):
        part["index"] = number

    original = mail_ingest.extract_original_sender(text if sender_text is None else sender_text, internal)
    original_sender = None
    if original:
        name = next((part["from"]["name"] for part in parts if part["from"] and part["from"]["address"] == original and part["from"]["name"]), "")
        original_sender = {"name": name, "address": original}
    return {"originalSender": original_sender, "parts": parts}


def _closed(part: dict[str, Any]) -> dict[str, Any]:
    lines = part.pop("lines")
    part["text"] = "\n".join(lines).strip()
    return {"index": 0, **part}


# --- normalising ----------------------------------------------------------------------------


def normalise(raw: Any) -> dict[str, Any]:
    """`validate`, then the derived fields: a new dict, the input untouched.

    `body.text` and `body.tables` come from the HTML (or the plain text), `forwardedChain`
    from that text, and `dedupeKey` from the identifiers. The source's `extract_customer_labels`
    fallback (a typed `EMAIL:` label names the customer when the chain has no external
    `From:`) is applied to `forwardedChain.originalSender` exactly as `process_message` does.
    """
    envelope = copy.deepcopy(validate(raw))
    html_content = body_html(envelope)
    envelope["body"]["text"] = render_text(html_content) if html_content else envelope["body"]["content"].strip()
    envelope["body"]["tables"] = mail_ingest.parse_html_tables(html_content) if html_content else []
    flat = body_plain(envelope)
    chain = split_forwarded_chain(envelope["body"]["text"], envelope["sender"], sender_text=flat)
    if chain["originalSender"] is None:
        typed = mail_ingest.extract_customer_labels(flat).get("email")
        if typed and not typed.endswith("@" + (mail_ingest.INTERNAL_DOMAIN or "").lower()):
            chain["originalSender"] = {"name": "", "address": typed}
    envelope["forwardedChain"] = chain
    envelope["dedupeKey"] = dedupe_key(envelope)
    return envelope


# --- building one from a Graph message (what the PHP reader does) ---------------------------


def _graph_address(entry: Any) -> dict[str, str]:
    mail = (entry or {}).get("emailAddress") or {}
    return {"name": str(mail.get("name") or ""), "address": str(mail.get("address") or "").strip().lower()}


def from_graph_message(message: dict[str, Any], attachments: list[dict[str, Any]] | None = None, *, mailbox: str = "") -> dict[str, Any]:
    """A raw envelope from a Microsoft Graph message resource (`GET /messages/{id}`) and its
    `fileAttachment`s (`contentBytes` base64). This is the mapping the PHP reader performs,
    written once in Python so the tests and the comparison against the old application use
    the same one. Attachments that are not file attachments (item, reference) are left out;
    ids are `documentPublicId = <messageId>:<n>` unless Graph gave an attachment id."""
    body = message.get("body") or {}
    content_type = "html" if str(body.get("contentType") or "").lower() == "html" else "text"
    documents = []
    for number, attachment in enumerate(attachments if attachments is not None else (message.get("attachments") or []), start=1):
        if not str(attachment.get("@odata.type") or "").lower().endswith("fileattachment"):
            continue
        document = {
            "documentPublicId": str(attachment.get("id") or f"att-{number}"),
            "fileName": str(attachment.get("name") or "attachment"),
            "contentType": str(attachment.get("contentType") or ""),
            "contentBase64": str(attachment.get("contentBytes") or ""),
            "isInline": bool(attachment.get("isInline")),
        }
        if attachment.get("contentId"):
            document["contentId"] = str(attachment["contentId"])
        documents.append(document)
    envelope: dict[str, Any] = {
        "contract": CONTRACT,
        "internetMessageId": str(message.get("internetMessageId") or message.get("id") or ""),
        "messageId": message.get("id"),
        "conversationId": message.get("conversationId"),
        "receivedAt": message.get("receivedDateTime"),
        "mailbox": mailbox or None,
        "subject": str(message.get("subject") or ""),
        "sender": _graph_address(message.get("from")),
        "toRecipients": [_graph_address(r) for r in message.get("toRecipients") or []],
        "ccRecipients": [_graph_address(r) for r in message.get("ccRecipients") or []],
        "body": {"contentType": content_type, "content": str(body.get("content") or "")},
        "documents": documents,
    }
    return envelope
