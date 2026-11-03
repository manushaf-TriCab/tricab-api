"""`rfq-mail-envelope.v1` (`rfq_parser.mail_envelope`), platform-owned.

The reading it leans on (HTML to text, tables, the original sender) is the source's and is
tested by `test_mail_reading.py`; this tests the contract, the validator, the chain split and
the derived fields.
"""

from __future__ import annotations

import base64
import copy
import json
from pathlib import Path

import pytest

import mail_fixtures
from rfq_parser import mail_envelope as me

SCHEMA = Path(me.__file__).parent / "data" / "rfq-mail-envelope.v1.schema.json"


def raw(**over):
    base = {
        "contract": me.CONTRACT,
        "internetMessageId": "<Abc.123@Mail.Example.com>",
        "subject": "RFQ",
        "sender": {"name": "Jane", "address": "jane@customer-example.com"},
        "body": {"contentType": "text", "content": "4C 16mm2 XLPE/PVC 120m"},
    }
    base.update(over)
    return base


# --------------------------------------------------------------------------- validation


def test_a_minimal_envelope_validates_and_gets_its_defaults():
    out = me.validate(raw())
    assert out["toRecipients"] == [] and out["ccRecipients"] == [] and out["documents"] == []
    assert out["messageId"] is None and out["conversationId"] is None and out["receivedAt"] is None
    assert out["body"] == {"contentType": "text", "content": "4C 16mm2 XLPE/PVC 120m"}


@pytest.mark.parametrize(
    "change,path",
    [
        ({"contract": "rfq-mail-envelope.v2"}, "contract"),
        ({"internetMessageId": ""}, "internetMessageId"),
        ({"internetMessageId": None}, "internetMessageId"),
        ({"sender": None}, "sender"),
        ({"sender": {"name": "x"}}, "sender.address"),
        ({"sender": {"address": "a@b.example.com", "mail": "x"}}, "sender"),
        ({"body": None}, "body"),
        ({"body": {"contentType": "rtf", "content": "x"}}, "body.contentType"),
        ({"body": {"contentType": "text"}}, "body.content"),
        ({"body": {"contentType": "text", "content": 7}}, "body.content"),
        ({"receivedAt": "yesterday"}, "receivedAt"),
        ({"toRecipients": "a@b.example.com"}, "toRecipients"),
        ({"toRecipients": [{"address": 5}]}, "toRecipients[0].address"),
        ({"toReceipients": []}, "toReceipients"),
        ({"documents": {}}, "documents"),
        ({"documents": [{"documentPublicId": "a", "fileName": "x.csv", "contentBase64": "!!"}]}, "documents[0].contentBase64"),
        ({"documents": [{"documentPublicId": "a", "fileName": "x.csv", "contentBase64": "", "isInline": "yes"}]}, "documents[0].isInline"),
        ({"documents": [{"documentPublicId": "a", "fileName": "x.csv", "contentBase64": "", "path": "/etc"}]}, "documents[0]"),
    ],
)
def test_a_bad_envelope_is_refused_naming_the_field(change, path):
    with pytest.raises(me.EnvelopeError) as caught:
        me.validate(raw(**change))
    assert caught.value.path == path


def test_a_non_object_is_refused():
    for value in (None, [], "mail", 3):
        with pytest.raises(me.EnvelopeError):
            me.validate(value)


def test_duplicate_document_ids_are_refused():
    entry = {"documentPublicId": "a", "fileName": "x.csv", "contentType": "text/csv", "contentBase64": ""}
    with pytest.raises(me.EnvelopeError, match="unique"):
        me.validate(raw(documents=[entry, dict(entry, fileName="y.csv")]))


def test_an_error_never_carries_the_content():
    secret = "S3CRET-CUSTOMER-TEXT"
    with pytest.raises(me.EnvelopeError) as caught:
        me.validate(raw(body={"contentType": "rtf", "content": secret}))
    assert secret not in str(caught.value)


def test_too_many_documents_and_too_long_a_subject_are_refused():
    entry = {"documentPublicId": "a", "fileName": "x.csv", "contentType": "text/csv", "contentBase64": ""}
    many = [dict(entry, documentPublicId=f"d{i}") for i in range(me.MAX_DOCUMENTS + 1)]
    with pytest.raises(me.EnvelopeError):
        me.validate(raw(documents=many))
    with pytest.raises(me.EnvelopeError):
        me.validate(raw(subject="s" * (me.MAX_SUBJECT_CHARS + 1)))


def test_the_input_is_never_modified():
    original = raw(documents=[])
    snapshot = copy.deepcopy(original)
    me.normalise(original)
    assert original == snapshot


# ----------------------------------------------------------------------------- identity


@pytest.mark.parametrize(
    "given,key",
    [
        ({"internetMessageId": "<Abc.123@Mail.Example.com>"}, "abc.123@mail.example.com"),
        ({"internetMessageId": "  abc@x.example.com "}, "abc@x.example.com"),
        ({"internetMessageId": "", "messageId": "AAMk-1"}, "aamk-1"),
        ({}, ""),
    ],
)
def test_the_dedupe_key(given, key):
    assert me.dedupe_key(given) == key


def test_one_message_has_one_key_however_the_id_is_written():
    assert me.dedupe_key({"internetMessageId": "<A@x.example.com>"}) == me.dedupe_key({"internetMessageId": "a@X.example.com"})


# ------------------------------------------------------------------------------- the body


def test_html_tables_are_kept_as_tables_and_as_rows_in_the_text():
    html = "<p>Please quote</p><table><tr><th>Item</th><th>Length</th></tr><tr><td>4C 16mm2</td><td>120m</td></tr></table><p>Thanks</p>"
    out = me.normalise(raw(body={"contentType": "html", "content": html}))
    assert out["body"]["tables"] == [[["Item", "Length"], ["4C 16mm2", "120m"]]]
    assert out["body"]["text"].splitlines() == ["Please quote", "", "Item | Length", "4C 16mm2 | 120m", "", "Thanks"]


def test_spans_are_expanded_in_the_tables():
    html = '<table><tr><td colspan="2">Cables</td></tr><tr><td rowspan="2">A</td><td>1</td></tr><tr><td>2</td></tr></table>'
    out = me.normalise(raw(body={"contentType": "html", "content": html}))
    assert out["body"]["tables"] == [[["Cables", "Cables"], ["A", "1"], ["A", "2"]]]


def test_a_plain_text_body_has_no_tables():
    out = me.normalise(raw(body={"contentType": "text", "content": "  hello  \n"}))
    assert out["body"]["text"] == "hello" and out["body"]["tables"] == []


def test_derived_fields_are_recomputed_not_trusted():
    out = me.normalise(raw(dedupeKey="forged", forwardedChain={"originalSender": {"address": "x@y.example.com"}, "parts": []},
                           body={"contentType": "text", "content": "hi", "text": "forged", "tables": [[["x"]]]}))
    assert out["dedupeKey"] == "abc.123@mail.example.com"
    assert out["body"]["text"] == "hi" and out["body"]["tables"] == []
    assert out["forwardedChain"]["originalSender"] is None


def test_a_normalised_envelope_is_itself_valid():
    out = me.normalise(mail_fixtures.build()["forwarded_chain"])
    assert me.validate(out)["internetMessageId"] == out["internetMessageId"]


# ------------------------------------------------------------------------ forwarded chain


OUTLOOK = (
    "Can you quote this?\nThanks\nSam\n\n"
    "From: Jane Doe <jane.doe@customer-example.com>\nSent: Monday, 12 October 2026 9:15 AM\n"
    "To: Sam Rep <sam.rep@tricab.com>\nCc: Pat Lee <pat.lee@tricab.com>\nSubject: Cable quote\n\n"
    "4C 16mm2 XLPE/PVC 120m\n"
)


def test_an_outlook_forward_splits_into_the_note_and_the_forwarded_message():
    chain = me.split_forwarded_chain(OUTLOOK, {"name": "Sam", "address": "sam.rep@tricab.com"})
    top, forwarded = chain["parts"]
    assert (top["kind"], top["index"], top["from"]["address"]) == ("top", 0, "sam.rep@tricab.com")
    assert top["text"] == "Can you quote this?\nThanks\nSam"
    assert forwarded["kind"] == "forwarded" and forwarded["index"] == 1
    assert forwarded["from"] == {"name": "Jane Doe", "address": "jane.doe@customer-example.com"}
    assert forwarded["sentAt"] == "Monday, 12 October 2026 9:15 AM"
    assert forwarded["subject"] == "Cable quote" and forwarded["to"] == "Sam Rep <sam.rep@tricab.com>"
    assert forwarded["text"] == "4C 16mm2 XLPE/PVC 120m"
    assert chain["originalSender"] == {"name": "Jane Doe", "address": "jane.doe@customer-example.com"}


def test_marker_lines_open_a_part_and_are_not_part_of_it():
    text = "FYI\n\n---------- Forwarded message ---------\nFrom: Pat <pat@tricab.com>\nDate: Tue\nSubject: x\nTo: a@tricab.com\n\nbody"
    chain = me.split_forwarded_chain(text)
    assert [p["text"] for p in chain["parts"]] == ["FYI", "body"]
    assert chain["originalSender"] is None  # only internal addresses


def test_a_chain_two_deep_finds_the_external_sender_in_the_inner_message():
    chain = me.split_forwarded_chain(mail_fixtures.build()["forwarded_chain_two_deep"]["body"]["content"])
    assert [p["kind"] for p in chain["parts"]] == ["top", "forwarded", "forwarded"]
    assert chain["originalSender"]["address"] == "jane.doe@customer-example.com"
    assert chain["parts"][2]["text"].startswith("4C 16mm2")


def test_a_gmail_style_attribution_line_opens_a_quoted_part():
    text = "Thanks, add 100m please.\n\nOn Mon, 12 Oct 2026 at 15:00, Jane Doe <jane.doe@customer-example.com> wrote:\n> 4C 16mm2 120m\n"
    chain = me.split_forwarded_chain(text)
    assert [p["kind"] for p in chain["parts"]] == ["top", "forwarded"]
    assert chain["parts"][1]["from"]["address"] == "jane.doe@customer-example.com"
    assert chain["parts"][1]["text"] == "> 4C 16mm2 120m"


def test_a_sentence_that_starts_with_from_is_not_a_header_block():
    chain = me.split_forwarded_chain("From: the look of it, the site needs 4C 16mm2.\nPlease price.\n")
    assert len(chain["parts"]) == 1


def test_no_chain_is_one_part_and_no_original_sender():
    chain = me.split_forwarded_chain("4C 16mm2 120m", {"name": "J", "address": "j@customer-example.com"})
    assert len(chain["parts"]) == 1 and chain["parts"][0]["text"] == "4C 16mm2 120m"
    # The source's fallback: the first external address anywhere in the body.
    assert me.split_forwarded_chain("contact bob@customer-example.com")["originalSender"]["address"] == "bob@customer-example.com"


def test_a_typed_email_label_names_the_customer_when_the_chain_does_not():
    out = me.normalise(mail_fixtures.build()["forwarded_template_labels"])
    assert out["forwardedChain"]["originalSender"]["address"] == "jane.doe@customer-example.com"


@pytest.mark.parametrize(
    "text,expected",
    [
        ("Jane Doe <Jane.Doe@Customer-Example.com>", {"name": "Jane Doe", "address": "jane.doe@customer-example.com"}),
        ('"Doe, Jane" <jane@customer-example.com>', {"name": "Doe, Jane", "address": "jane@customer-example.com"}),
        ("Jane [mailto:jane@customer-example.com]", {"name": "Jane", "address": "jane@customer-example.com"}),
        ("jane@customer-example.com", {"name": "", "address": "jane@customer-example.com"}),
    ],
)
def test_addresses_in_headers(text, expected):
    assert me.parse_address(text) == expected


def test_a_header_without_an_address_is_none():
    assert me.parse_address("Jane Doe") is None


# ----------------------------------------------------------------------------- from Graph


def test_a_graph_message_maps_to_an_envelope():
    message = {
        "id": "AAMk-1", "internetMessageId": "<g@x.example.com>", "conversationId": "AAQk-1",
        "receivedDateTime": "2026-10-14T01:02:03Z", "subject": "RFQ",
        "from": {"emailAddress": {"name": "Jane", "address": "Jane@Customer-Example.com"}},
        "toRecipients": [{"emailAddress": {"name": "Mel", "address": "melbourne@tricab.com"}}],
        "ccRecipients": [], "body": {"contentType": "html", "content": "<p>hi</p>"},
    }
    attachments = [
        {"@odata.type": "#microsoft.graph.fileAttachment", "id": "att1", "name": "a.csv", "contentType": "text/csv", "contentBytes": base64.b64encode(b"a,b").decode(), "isInline": False},
        {"@odata.type": "#microsoft.graph.itemAttachment", "id": "att2", "name": "mail"},
        {"@odata.type": "#microsoft.graph.fileAttachment", "id": "att3", "name": "i.png", "contentType": "image/png", "contentBytes": "", "isInline": True, "contentId": "cid"},
    ]
    env = me.from_graph_message(message, attachments, mailbox="melbourne@tricab.com")
    out = me.validate(env)
    assert out["sender"]["address"] == "jane@customer-example.com"
    assert [d["documentPublicId"] for d in out["documents"]] == ["att1", "att3"]
    assert out["documents"][1]["isInline"] is True and out["documents"][1]["contentId"] == "cid"
    assert out["body"]["contentType"] == "html" and out["mailbox"] == "melbourne@tricab.com"


# -------------------------------------------------------------------------------- schema


def test_the_schema_file_lists_exactly_what_the_validator_allows():
    schema = json.loads(SCHEMA.read_text(encoding="utf-8"))
    assert schema["properties"]["contract"]["const"] == me.CONTRACT
    assert set(schema["properties"]) == set(me._TOP_KEYS)
    assert set(schema["required"]) == {"contract", "internetMessageId", "sender", "body"}
    assert set(schema["properties"]["body"]["properties"]) == set(me._BODY_KEYS)
    assert set(schema["properties"]["documents"]["items"]["properties"]) == set(me._DOCUMENT_KEYS)
    assert set(schema["$defs"]["address"]["properties"]) == set(me._ADDRESS_KEYS)
    assert schema["additionalProperties"] is False
    assert schema["properties"]["documents"]["maxItems"] == me.MAX_DOCUMENTS
    assert schema["properties"]["body"]["properties"]["contentType"]["enum"] == list(me.BODY_CONTENT_TYPES)


@pytest.mark.parametrize("name", mail_fixtures.names())
def test_every_fixture_is_a_valid_envelope(name):
    out = me.normalise(mail_fixtures.load(name))
    assert out["dedupeKey"].endswith("@mail.example.com")


def test_a_nested_layout_table_does_not_lose_its_text():
    # Outlook signatures nest tables; cutting at the first </table> dropped the outer cell's text
    # (and with it a customer's address) from the rendered body.
    html = ('<p>Hello</p><table><tr><td><table><tr><td>Peter</td></tr></table>'
            '<p>peter@customer-example.com</p></td></tr></table><p>Bye</p>')
    out = me.normalise(raw(body={"contentType": "html", "content": html}))
    assert "peter@customer-example.com" in out["body"]["text"] and "Peter" in out["body"]["text"]
    assert out["forwardedChain"]["originalSender"]["address"] == "peter@customer-example.com"


def test_a_table_with_no_rows_is_left_as_text():
    out = me.normalise(raw(body={"contentType": "html", "content": "<table><tr><td></td></tr></table><p>4C 16mm2</p>"}))
    assert out["body"]["text"] == "4C 16mm2"
