# Generated from the RFQ parser's matcher/mail_ingest.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
"""One file into work items: the source's `mail_ingest.read_one_file`, cut out whole.

The source reads every file through this function, from the mailbox and from the
portal's background re-read alike; the platform's reader jobs call it too, so the
three entry points read one file one way. See `tools/sync_rfq_parser.py`.
"""

from __future__ import annotations

import os
from pathlib import Path
from tempfile import TemporaryDirectory
from typing import Any, Callable

from rfq_parser import attachment_reader
from rfq_parser.file_parser import parse_file


# Document types parse_file understands; anything else (images) goes to vision OCR.
_PARSEABLE_SUFFIXES = {".xlsx", ".xlsm", ".xls", ".docx", ".pdf", ".csv", ".txt"}
_IMAGE_CONTENT_TYPES = {"image/png", "image/jpeg", "image/jpg", "image/webp"}


def vision_rows_to_items(rows: list[dict]) -> list[dict]:
    """Turn vision-OCR rows into engine work items (text + table_quantity)."""
    items: list[dict] = []
    for row_index, row in enumerate(rows, start=1):
        description = (row.get("description") or "").strip()
        if not description:
            continue
        text = description
        code = (row.get("code") or "").strip()
        if code and code.lower() not in text.lower():
            text = f"{code} {text}"
        section = (row.get("section") or "").strip()
        if section and section.lower() not in text.lower():
            text = f"{text} {section}"
        if row.get("fire_rated") and "fire" not in text.lower():
            text = f"{text} Fire Rated"
        metadata: dict[str, Any] = {}
        length = row.get("length")
        unit = (row.get("unit") or "").strip()
        # Only treat the number as a requested length when the model also read a real
        # unit next to it. A bare numeric column (Qty, Pack, price) is not a length,
        # so we must not fabricate an "m" unit and feed it to the matcher.
        if length is not None and unit:
            metadata["table_quantity"] = length
            metadata["table_unit"] = unit
        metadata["vision_confidence"] = row.get("confidence")
        metadata["vision_uncertain"] = bool(row.get("uncertain"))
        items.append(
            {
                "text": text,
                "context": [],
                "metadata": metadata,
                "source": {"input_type": "vision_ocr", "row_number": row_index},
            }
        )
    return items


def read_one_file(
    name: str,
    data: bytes,
    content_type: str = "",
    *,
    vision_ocr: Callable[[bytes, str], Any] | None = None,
    is_inline: bool = False,
) -> tuple[list[dict], list[str]]:
    """Read ONE file into work items. Returns (items, notes).

    The single implementation of "what does this file contain?", shared by the mail path
    (`extract_items_from_attachments`, which keeps the attachment-shaped decisions around
    it -- inline-image policy, `$value` fetching, what counts as a file at all) and by the
    portal's background reader worker. PORTAL_TERRA_PARITY_PLAN.md Phase C: the
    alternative was the portal synthesising Graph attachment dicts so it could call the
    mail path, which is exactly how two entry points drift into reading one file two
    different ways.

    `is_inline` is the one caller-shaped flag left in here, and it suppresses a single
    note: an image pasted into a body producing no rows is ordinary (it was probably
    decoration that got past the size filter), while an attachment producing none is worth
    saying.
    """
    items: list[dict] = []
    notes: list[str] = []
    suffix = Path(name).suffix.lower()
    ctype = (content_type or "").split(";", 1)[0].strip().lower()
    # Graph always states a real content type, so the email path takes the first clause
    # and behaves exactly as it did. A browser upload may send nothing usable -- Windows
    # with no registered handler sends `application/octet-stream` -- and a .png is still a
    # .png, so the suffix answers when the content type declines to.
    is_image = ctype in _IMAGE_CONTENT_TYPES or (
        ctype in ("", "application/octet-stream") and suffix in attachment_reader.IMAGE_SUFFIXES
    )

    if is_image:
        # Two readers can answer here. Terra reads the picture into the same line
        # items every other attachment produces; `vision_ocr` (qwen) transcribes it
        # into rows. Terra goes first when RFQ_READER_IMAGE_ENABLED, and qwen is the
        # fallback rather than a competitor -- unlike `parse_file`, it fails loudly,
        # so falling back to it loses nothing that could hide. Which one answered is
        # readable off the row (`reader_ungrounded` vs `vision_ocr`) and said in the
        # notes; neither can auto-push.
        terra_reads_images = attachment_reader.image_reader_enabled()
        if vision_ocr is None and not terra_reads_images:
            notes.append(f"Image '{name}' skipped (no image reader available).")
            return items, notes
        if terra_reads_images:
            read = attachment_reader.read_image(data, name, ctype)
            if read.ok:
                image_items = attachment_reader.to_text_work_items(read, name)
                items.extend(image_items)
                if image_items:
                    notes.append(
                        f"Image '{name}' was read from the picture itself; there is "
                        "nothing to check its numbers against. Every quantity needs "
                        "confirming."
                    )
                return items, notes
            if vision_ocr is None:
                notes.append(f"Image '{name}' could not be read: {read.error}")
                return items, notes
            # A silent swap between two models is how "the reader is live" stops
            # meaning anything. If qwen answered because terra did not, say so.
            notes.append(
                f"Image '{name}' could not be read by the reader ({read.error}); "
                "vision OCR was used instead."
            )
        result = vision_ocr(data, ctype)
        if getattr(result, "ok", False):
            items.extend(vision_rows_to_items(getattr(result, "rows", []) or []))
        elif not is_inline:
            notes.append(f"Image '{name}' produced no rows.")
        return items, notes

    if (suffix not in _PARSEABLE_SUFFIXES
            and suffix not in attachment_reader.READER_SUFFIXES
            and suffix not in attachment_reader.PDF_SUFFIXES
            and suffix not in attachment_reader.TEXT_SUFFIXES):
        notes.append(f"Attachment '{name}' skipped (unsupported type '{suffix or 'no extension'}').")
        return items, notes

    # §3 of AGENT_FILE_READING_ORDER: a spreadsheet is read by the model itself,
    # which opens it with openpyxl in a sandbox and reports the cells each quantity
    # came from. `parse_file` is not a fallback here -- when the reader fails, the
    # message is reported as degraded rather than quietly handed to the path whose
    # silent failure prompted the order (H1104: 78 confident rows, every quantity
    # dropped, nothing to escalate on).
    # PDFs: terra reads the text layer and every quantity is grounded against it,
    # exactly as the body is. A scanned PDF has no text layer and comes back
    # ungrounded and flagged -- against a `parse_file` path that produces nothing at
    # all for one, which is the comparison that makes it worth doing.
    if suffix in attachment_reader.PDF_SUFFIXES and attachment_reader.pdf_reader_enabled():
        result = attachment_reader.read_pdf(data, name)
        if not result.ok:
            notes.append(f"Attachment '{name}' could not be read: {result.error}")
            return items, notes
        items.extend(attachment_reader.to_text_work_items(result, name))
        dropped = [d for d in result.degraded if d.get("rule") != "skipped"]
        if dropped:
            notes.append(
                f"Attachment '{name}': {len(dropped)} line(s) failed grounding and were "
                f"dropped ({dropped[0].get('rule')}: {dropped[0].get('detail')})."
            )
        if not result.validated:
            notes.append(
                f"Attachment '{name}' has no text layer; its rows were read from the "
                "page image and cannot be checked. Every quantity needs confirming."
            )
        return items, notes

    if suffix in attachment_reader.READER_SUFFIXES and attachment_reader.reader_enabled():
        result = attachment_reader.read_attachment(data, name, ctype)
        if not result.ok:
            notes.append(f"Attachment '{name}' could not be read: {result.error}")
            return items, notes
        items.extend(attachment_reader.to_work_items(result, name))
        if result.degraded:
            dropped = [d for d in result.degraded if d.get("rule") != "skipped"]
            if dropped:
                notes.append(
                    f"Attachment '{name}': {len(dropped)} row(s) failed validation and were "
                    f"dropped ({dropped[0].get('rule')}: {dropped[0].get('detail')})."
                )
        if not result.validated:
            notes.append(
                f"Attachment '{name}' could not be re-opened for checking; its rows are "
                "unverified and must not be auto-sent."
            )
        if result.note:
            # How the read was made, when that is not the ordinary way. Today:
            # the file was an .xls and was converted to be read, so the formulas
            # behind its values were not available. The rows validated and can be
            # pushed; the draft still says under what.
            notes.append(f"Attachment '{name}': {result.note}.")
        if result.retries:
            # A recovered failure is still a failure. The sandbox container expired
            # mid-read and the read was made again -- twice in six attempts on the
            # 194 KB file, which is the one that takes five minutes. Silence here is
            # how a reliability problem becomes invisible until it stops recovering.
            notes.append(
                f"Attachment '{name}' was read on attempt {result.retries + 1}: the "
                "sandbox container expired mid-read."
            )
        return items, notes

    # .docx / .csv / .txt: no cells to open, so these take the body's route --
    # terra reads the document text and quotes the span each quantity came from,
    # and `validate_text_items` re-finds that span. Same flag as the body and PDFs,
    # because it is the same guarantee. Without this they were the last inputs with
    # no terra route at all, and a .docx cable schedule ("4 drums x 240mm Alu flex
    # ( 66m )") is exactly the prose the deterministic parser reads worst.
    if suffix in attachment_reader.TEXT_SUFFIXES and attachment_reader.document_reader_enabled():
        result = attachment_reader.read_document(data, name)
        if not result.ok:
            notes.append(f"Attachment '{name}' could not be read: {result.error}")
            return items, notes
        items.extend(attachment_reader.to_text_work_items(result, name))
        dropped = [d for d in result.degraded if d.get("rule") != "skipped"]
        if dropped:
            notes.append(
                f"Attachment '{name}': {len(dropped)} line(s) failed grounding and were "
                f"dropped ({dropped[0].get('rule')}: {dropped[0].get('detail')})."
            )
        return items, notes

    with TemporaryDirectory() as tmp:
        target = Path(tmp) / os.path.basename(name)
        target.write_bytes(data)
        try:
            for idx, parsed in enumerate(parse_file(target)):
                items.append(
                    {
                        "text": parsed["text"],
                        "context": parsed.get("context") or [],
                        "metadata": parsed.get("metadata") or {},
                        "source": {"file_name": name, "row_number": parsed.get("row_number") or idx + 1},
                    }
                )
        except Exception as exc:  # noqa: BLE001 - one bad file must not stop the batch
            notes.append(f"Attachment '{name}' could not be parsed: {exc}")
    return items, notes


def _default_vision_ocr():
    """Return a callable(bytes, mime)->VisionOcrResult, or None if OCR is off."""
    try:
        from rfq_parser.vision_ocr import extract_table_with_qwen, vision_ocr_enabled
    except Exception:
        return None
    if not vision_ocr_enabled():
        return None
    return extract_table_with_qwen
