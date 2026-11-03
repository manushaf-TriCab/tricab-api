"""Synthetic `rfq-mail-envelope.v1` fixtures (RFQ mail intake plan, M2.4).

Every envelope here is invented: people are made-up names, every domain is `example.com`
(or `tricab.com` for our own side), and every product line is a catalogue-shaped line that
names no customer. They mirror the *shapes* the real corpus has (`EMAIL_CORPUS_AUDIT.md`):
a table in the body, an attachment only, a forwarded chain, an inline screenshot, a reply to
an earlier RFQ, mail that is not an RFQ, and the traps around them (decoration, signatures,
our own replies, an unreadable attachment).

`python tests/rfq_parser/mail_fixtures.py` writes them to `fixtures/mail/<name>.json`, the
files PHP tests and the reader's tests can load; `test_mail_fixtures.py` checks each one's
parse. The committed JSON is the fixture; this file is how it was made, so a new shape is
added here and regenerated, never hand-edited (the spreadsheet bytes are not reproducible).
"""

from __future__ import annotations

import base64
import io
import json
import struct
import zlib
from pathlib import Path

CONTRACT = "rfq-mail-envelope.v1"
HERE = Path(__file__).resolve().parent
OUT = HERE / "fixtures" / "mail"
MAILBOX = "melbourne@tricab.com"

SALES = {"name": "Sam Rep", "address": "sam.rep@tricab.com"}
CUSTOMER = {"name": "Jane Doe", "address": "jane.doe@customer-example.com"}


def _b64(data: bytes) -> str:
    return base64.b64encode(data).decode("ascii")


def xlsx(rows: list[list[object]], title: str = "Schedule") -> bytes:
    from openpyxl import Workbook

    book = Workbook()
    sheet = book.active
    sheet.title = title
    for row in rows:
        sheet.append(row)
    out = io.BytesIO()
    book.save(out)
    return out.getvalue()


def png(size: int = 30_000) -> bytes:
    """A valid 1x1 PNG padded with a text chunk to `size` bytes: it reads as a PNG and is as
    big as a pasted screenshot, which is all the filters look at."""
    def chunk(kind: bytes, data: bytes) -> bytes:
        return struct.pack(">I", len(data)) + kind + data + struct.pack(">I", zlib.crc32(kind + data) & 0xFFFFFFFF)

    head = b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", 1, 1, 8, 2, 0, 0, 0))
    tail = chunk(b"IDAT", zlib.compress(b"\x00\xff\xff\xff")) + chunk(b"IEND", b"")
    pad = max(0, size - len(head) - len(tail) - 12)
    return head + chunk(b"tEXt", b"Comment\x00" + b"x" * pad) + tail


def doc(public_id: str, name: str, content_type: str, data: bytes, *, inline: bool = False, content_id: str | None = None) -> dict:
    entry = {"documentPublicId": public_id, "fileName": name, "contentType": content_type, "contentBase64": _b64(data), "isInline": inline}
    if content_id:
        entry["contentId"] = content_id
    return entry


def envelope(name: str, subject: str, html: str | None = None, text: str | None = None, *, sender: dict | None = None,
             documents: list[dict] | None = None, conversation: str | None = None) -> dict:
    body = {"contentType": "html", "content": html} if html is not None else {"contentType": "text", "content": text or ""}
    return {
        "contract": CONTRACT,
        "internetMessageId": f"<{name}.0001@mail.example.com>",
        "messageId": f"AAMkAD-{name}",
        "conversationId": conversation or f"AAQkAD-{name}",
        "receivedAt": "2026-10-14T01:02:03Z",
        "mailbox": MAILBOX,
        "subject": subject,
        "sender": sender or SALES,
        "toRecipients": [{"name": "Melbourne Sales", "address": MAILBOX}],
        "ccRecipients": [],
        "body": body,
        "documents": documents or [],
    }


TABLE = """<table border="1"><tr><th>Item</th><th>Description</th><th>Length</th></tr>
<tr><td>1</td><td>4C 16mm2 XLPE/PVC</td><td>120m</td></tr>
<tr><td>2</td><td>3C 2.5mm2 XLPE/PVC</td><td>4 x 55m</td></tr>
<tr><td>3</td><td>1C 120mm2 XLPE/PVC</td><td>300m</td></tr></table>"""

SCHEDULE_ROWS = [["Description", "Length"], ["4C 16mm2 XLPE/PVC", "120m"], ["3C 2.5mm2 XLPE/PVC", "4 x 55m"], ["1C 120mm2 XLPE/PVC", "300m"]]


def build() -> dict[str, dict]:
    out: dict[str, dict] = {}

    out["table_in_body"] = envelope(
        "table_in_body", "Quote request - site cabling",
        html=f"<p>Hi team,</p><p>Please quote the following.</p>{TABLE}<p>Kind regards<br>Jane</p>",
        sender=CUSTOMER)

    out["attachment_only"] = envelope(
        "attachment_only", "RFQ attached", html="<p>Please see attached.</p>", sender=CUSTOMER,
        documents=[doc("att-1", "schedule.xlsx", "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet", xlsx(SCHEDULE_ROWS))])

    out["forwarded_chain"] = envelope(
        "forwarded_chain", "FW: Cable quote",
        html=("<p>Can you quote this one please?</p><p>Thanks<br>Sam</p><hr>"
              "<p>From: Jane Doe &lt;jane.doe@customer-example.com&gt;<br>Sent: Monday, 12 October 2026 9:15 AM<br>"
              "To: Sam Rep &lt;sam.rep@tricab.com&gt;<br>Subject: Cable quote</p>"
              "<p>Hello Sam,</p><p>4C 16mm2 XLPE/PVC 120m</p><p>3C 2.5mm2 XLPE/PVC 4 x 55m</p><p>Regards, Jane</p>"))

    out["forwarded_chain_two_deep"] = envelope(
        "forwarded_chain_two_deep", "Fwd: Fwd: Cable quote",
        text=("FYI, for Melbourne.\n\n---------- Forwarded message ---------\nFrom: Pat Lee <pat.lee@tricab.com>\n"
              "Date: Tue, 13 Oct 2026 at 08:00\nSubject: Fwd: Cable quote\nTo: Sam Rep <sam.rep@tricab.com>\n\n"
              "Please action.\n\n-----Original Message-----\nFrom: Jane Doe <jane.doe@customer-example.com>\n"
              "Sent: Monday, 12 October 2026 9:15 AM\nTo: Pat Lee <pat.lee@tricab.com>\nSubject: Cable quote\n\n"
              "4C 16mm2 XLPE/PVC 120m\n1C 120mm2 XLPE/PVC 300m\n"))

    out["forwarded_template_labels"] = envelope(
        "forwarded_template_labels", "CREATE QUOTE",
        text=("CREATE QUOTE\nCustomer: Example Electrical Pty Ltd\nContact: Jane Doe\nEmail: jane.doe@customer-example.com\n"
              "Customer Ref: PO-1234\nDelivery Address: 1 Example St\nSpringfield 3000\n\n"
              "4C 16mm2 XLPE/PVC 120m\n3C 2.5mm2 XLPE/PVC 4 x 55m\n"))

    out["inline_screenshot"] = envelope(
        "inline_screenshot", "Quote from the attached picture",
        html='<p>Can you price this schedule?</p><p><img src="cid:image001.png@01D00000.00000000"></p><p>Thanks</p>',
        sender=CUSTOMER,
        documents=[doc("img-1", "image001.png", "image/png", png(30_000), inline=True, content_id="image001.png@01D00000.00000000")])

    out["inline_decoration_only"] = envelope(
        "inline_decoration_only", "Quote request",
        html=f'<p>Hello,</p>{TABLE}<p>Regards<br><img src="cid:logo"> <img src="cid:icon"></p>',
        sender=CUSTOMER,
        documents=[
            doc("logo", "image002.png", "image/png", png(4_000), inline=True, content_id="logo"),
            doc("icon", "linkedin.gif", "image/gif", b"GIF89a" + b"\0" * 40_000, inline=True, content_id="icon"),
        ])

    out["reply_to_earlier_rfq"] = envelope(
        "reply_to_earlier_rfq", "RE: Cable quote",
        html=("<p>Hi Sam,</p><p>Please add 200m of 1C 70mm2 XLPE/PVC to the quote.</p><p>Thanks<br>Jane</p><hr>"
              "<p>From: Sam Rep &lt;sam.rep@tricab.com&gt;<br>Sent: Monday, 12 October 2026 3:00 PM<br>"
              "To: Jane Doe &lt;jane.doe@customer-example.com&gt;<br>Subject: Cable quote</p>"
              "<p>Quote attached for 4C 16mm2 XLPE/PVC 120m.</p>"),
        sender=CUSTOMER, conversation="AAQkAD-cable-quote-thread")

    out["not_an_rfq"] = envelope(
        "not_an_rfq", "Team meeting moved to 3pm",
        html="<p>Hi all,</p><p>The weekly sales meeting has moved to 3pm on Thursday in the Melbourne boardroom.</p>"
             "<p>Please bring your pipeline updates.</p><p>Thanks<br>Pat</p>",
        sender={"name": "Pat Lee", "address": "pat.lee@tricab.com"})

    out["body_and_attachment_same_lines"] = envelope(
        "body_and_attachment_same_lines", "Schedule (pasted and attached)",
        html=f"<p>Pasted below and attached.</p>{TABLE}", sender=CUSTOMER,
        documents=[doc("att-1", "schedule.xlsx", "application/octet-stream", xlsx(SCHEDULE_ROWS))])

    out["several_attachments"] = envelope(
        "several_attachments", "RFQ with documents", html="<p>Documents attached.</p>", sender=CUSTOMER,
        documents=[
            doc("att-1", "schedule.xlsx", "application/octet-stream", xlsx(SCHEDULE_ROWS)),
            doc("att-2", "smime.p7s", "application/pkcs7-signature", b"0\x82\x01\x00signature"),
            doc("att-3", "invite.ics", "text/calendar", b"BEGIN:VCALENDAR\nEND:VCALENDAR\n"),
            doc("att-4", "lines.csv", "text/csv", b"Description,Length\n4C 16mm2 XLPE/PVC,120m\n"),
        ])

    out["unreadable_attachment"] = envelope(
        "unreadable_attachment", "RFQ - see body and file", html=f"<p>Quote please.</p>{TABLE}", sender=CUSTOMER,
        documents=[doc("att-1", "broken.xlsx", "application/octet-stream", b"this is not a spreadsheet")])

    out["plain_text_body"] = envelope(
        "plain_text_body", "Cable enquiry",
        text="Hello,\n\nCould you price:\nCircuit A\n4C 16mm2 XLPE/PVC 120m\nCircuit B\n3C 2.5mm2 XLPE/PVC 4 x 55m\n\nThanks,\nJane\n\n"
             "This email is confidential and intended only for the recipient.\n",
        sender=CUSTOMER)

    out["own_reply_loop"] = envelope(
        "own_reply_loop", "RE: [RFQ-Reply] Cable quote", text="Your draft is ready: https://example.com/rfq/1\n",
        sender={"name": "Melbourne Sales", "address": "someone@example.com"})

    out["own_mailbox_sender"] = envelope(
        "own_mailbox_sender", "Cable quote", text="4C 16mm2 XLPE/PVC 120m\n", sender={"name": "Melbourne Sales", "address": MAILBOX})
    return out


def write() -> None:
    OUT.mkdir(parents=True, exist_ok=True)
    for name, value in build().items():
        (OUT / f"{name}.json").write_text(json.dumps(value, indent=1, sort_keys=False) + "\n", encoding="utf-8")


def load(name: str) -> dict:
    return json.loads((OUT / f"{name}.json").read_text(encoding="utf-8"))


def names() -> list[str]:
    return sorted(p.stem for p in OUT.glob("*.json"))


if __name__ == "__main__":
    write()
    print(f"wrote {len(build())} envelopes to {OUT}")
