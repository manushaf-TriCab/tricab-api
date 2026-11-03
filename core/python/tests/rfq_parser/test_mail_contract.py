"""`POST /v1/parse` with `email` (`rfq_parser.contract._parse_email`), platform-owned.

The plain `text` and `documents` inputs are tested in `test_contract.py`; this is the third
input: an `rfq-mail-envelope.v1`, the body and every attachment read in one pass.
"""

from __future__ import annotations

import copy

import pytest

import mail_fixtures
from rfq_parser import app as service
from rfq_parser import contract

TOKEN = "test-internal-token-0123456789"
CID = "corr-0123456789abcdef"


@pytest.fixture()
def client(monkeypatch):
    monkeypatch.setenv(contract.TOKEN_SETTING, TOKEN)
    service.app.config["TESTING"] = True
    return service.app.test_client()


def post(client, email, **extra):
    body = {"contract": contract.CONTRACT, "correlationId": CID, "deadlineMs": 60_000, "email": email,
            "options": {"allowModel": False, "maxModelCalls": 0}, **extra}
    return client.post("/v1/parse", json=body, headers={"Authorization": f"Bearer {TOKEN}"})


def parse(client, name_or_envelope):
    env = mail_fixtures.load(name_or_envelope) if isinstance(name_or_envelope, str) else name_or_envelope
    response = post(client, env)
    assert response.status_code == 200, response.get_json()
    return response.get_json()


def origins(body):
    return [(line["source"]["file_name"], line["source"]["original_text"]) for line in body["lines"]]


def counts(body):
    c = body["coverage"]
    return (c["inputRows"], c["keptRows"], c["excludedRows"], c["skippedRows"], len(c["unaccounted"]))


# ------------------------------------------------------------------------------ fixtures

CABLES = ["4C 16mm2 XLPE/PVC", "3C 2.5mm2 XLPE/PVC", "1C 120mm2 XLPE/PVC"]


@pytest.mark.parametrize(
    "name,lines,source",
    [
        ("table_in_body", CABLES, "email body"),
        ("attachment_only", CABLES, "schedule.xlsx"),
        ("plain_text_body", ["4C 16mm2 XLPE/PVC 120m", "3C 2.5mm2 XLPE/PVC 4 x 55m"], "email body"),
        ("forwarded_chain", ["4C 16mm2 XLPE/PVC 120m", "3C 2.5mm2 XLPE/PVC 4 x 55m"], "email body"),
        ("forwarded_chain_two_deep", ["4C 16mm2 XLPE/PVC 120m", "1C 120mm2 XLPE/PVC 300m"], "email body"),
        ("forwarded_template_labels", ["4C 16mm2 XLPE/PVC 120m", "3C 2.5mm2 XLPE/PVC 4 x 55m"], "email body"),
        ("inline_decoration_only", CABLES, "email body"),
        ("unreadable_attachment", CABLES, "email body"),
    ],
)
def test_each_shape_yields_its_lines(client, name, lines, source):
    body = parse(client, name)
    assert origins(body) == [(source, text) for text in lines]


def test_an_email_that_is_not_an_rfq_has_no_lines_and_a_balanced_account(client):
    body = parse(client, "not_an_rfq")
    assert body["lines"] == []
    assert counts(body) == (2, 0, 0, 2, 0)
    assert body["email"]["body"]["residueCount"] == 2
    assert body["coverage"]["measured"] is True


def test_the_body_and_the_attachment_are_read_in_one_pass_without_duplicates(client):
    body = parse(client, "body_and_attachment_same_lines")
    assert origins(body) == [("schedule.xlsx", text) for text in CABLES]
    assert body["email"]["duplicatesDropped"] == 3
    # 4 sheet rows (header + 3) + 3 pasted rows + 1 line of prose = 8, all accounted.
    assert counts(body) == (8, 3, 0, 5, 0)
    assert any("repeated one already read" in note for note in body["notes"])


def test_attachments_of_unsupported_types_are_left_out_not_fatal(client):
    body = parse(client, "several_attachments")
    assert [d["fileName"] for d in body["documents"]] == ["schedule.xlsx", "lines.csv"]
    assert [s["fileName"] for s in body["email"]["skippedAttachments"]] == ["smime.p7s", "invite.ics"]
    assert origins(body) == [("schedule.xlsx", text) for text in CABLES]  # lines.csv repeats the first line
    assert body["email"]["duplicatesDropped"] == 1


def test_an_unreadable_attachment_keeps_the_body_and_makes_the_parse_partial(client):
    body = parse(client, "unreadable_attachment")
    c = body["coverage"]
    assert c["measured"] is True and len(c["unaccounted"]) == 1
    assert c["unaccounted"][0]["fileName"] == "broken.xlsx"
    assert c["keptRows"] + c["excludedRows"] + c["skippedRows"] < c["inputRows"]  # never reads as complete
    assert body["email"]["unreadableAttachments"] == 1
    assert any("broken.xlsx could not be read" in note for note in body["notes"])


def test_an_inline_screenshot_is_a_document_for_the_reader(client):
    body = parse(client, "inline_screenshot")
    (document,) = body["documents"]
    assert document["fileName"] == "image001.png" and document["inline"] is True
    assert document["readerRoute"] is False and document["lineCount"] == 0  # no reader is on under test
    assert body["coverage"]["measured"] is False  # an image is never reported as complete


def test_decorative_inline_images_are_ignored_and_say_so(client):
    body = parse(client, "inline_decoration_only")
    assert body["email"]["ignoredInline"] == ["image002.png", "linkedin.gif"]
    assert "documents" not in body
    assert any("decoration" in note for note in body["notes"])


def test_inline_images_can_be_turned_off(client, monkeypatch):
    monkeypatch.setenv("RFQ_PROCESS_INLINE_IMAGES", "0")
    body = parse(client, "inline_screenshot")
    assert body["email"]["ignoredInline"] == ["image001.png"] and "documents" not in body


def test_a_small_inline_image_is_read_when_the_floor_is_lowered(client, monkeypatch):
    monkeypatch.setenv("RFQ_MIN_INLINE_IMAGE_BYTES", "1000")
    body = parse(client, "inline_decoration_only")
    assert body["email"]["ignoredInline"] == ["linkedin.gif"]  # noise-named / not an image type still dropped
    assert [d["fileName"] for d in body["documents"]] == ["image002.png"]


# ---------------------------------------------------------------------------- the email block


def test_the_email_block_carries_what_the_reader_needs(client):
    body = parse(client, "forwarded_chain")
    email = body["email"]
    assert email["dedupeKey"] == "forwarded_chain.0001@mail.example.com"
    assert email["originalSender"] == {"name": "Jane Doe", "address": "jane.doe@customer-example.com"}
    assert email["forwardedParts"] == 1
    assert {line["source"]["chain_part"] for line in body["lines"]} == {1}


def test_a_reply_reads_the_new_line_and_the_quoted_one_and_tells_them_apart(client):
    body = parse(client, "reply_to_earlier_rfq")
    assert [(l["source"]["chain_part"], l["source"]["original_text"][:12]) for l in body["lines"]] == [(0, "Please add 2"), (1, "Quote attach")]


def test_customer_labels_typed_by_the_rep_are_reported(client):
    labels = parse(client, "forwarded_template_labels")["email"]["customerLabels"]
    assert labels["customer"] == "Example Electrical Pty Ltd" and labels["customer_ref"] == "PO-1234"
    assert labels["delivery_address"] == "1 Example St, Springfield 3000"


def test_a_parse_of_plain_text_has_no_email_block(client):
    response = client.post("/v1/parse", json={"contract": contract.CONTRACT, "correlationId": CID, "deadlineMs": 60_000,
                                              "text": "4C 16mm2 XLPE/PVC 120m", "documents": [], "options": {"allowModel": False, "maxModelCalls": 0}},
                           headers={"Authorization": f"Bearer {TOKEN}"})
    assert response.status_code == 200 and "email" not in response.get_json()


def test_the_answer_keeps_the_ordinary_parse_shape(client):
    body = parse(client, "table_in_body")
    for key in ("lines", "coverage", "engine", "provider", "degraded", "notes", "elapsedMs", "contract", "correlationId"):
        assert key in body
    assert body["provider"]["mode"] == "deterministic"


# ---------------------------------------------------------------------------- loop guards


@pytest.mark.parametrize("name,reason", [("own_reply_loop", "rfq_reply"), ("own_mailbox_sender", "self_sent")])
def test_the_parsers_own_mail_is_refused(client, name, reason):
    response = post(client, mail_fixtures.load(name))
    assert response.status_code == 422
    error = response.get_json()["error"]
    assert error["code"] == "invalid_request" and reason in error["message"]


def test_our_notification_subject_is_refused(client):
    env = mail_fixtures.load("table_in_body")
    env["subject"] = "RE: [RFQ] Assigned to you: Example Pty"
    response = post(client, env)
    assert response.status_code == 422 and "notification" in response.get_json()["error"]["message"]


def test_without_a_mailbox_only_the_tags_guard(client):
    env = mail_fixtures.load("own_mailbox_sender")
    env["mailbox"] = None
    assert post(client, env).status_code == 200


# ------------------------------------------------------------------------------- limits


def _csv(n):
    return {"documentPublicId": f"d{n}", "fileName": f"lines{n}.csv", "contentType": "text/csv",
            "contentBase64": "RGVzY3JpcHRpb24sTGVuZ3RoCjRDIDE2bW0yIFhMUEUvUFZDLDEyMG0K", "isInline": False}


def test_ten_attachments_are_read_and_eleven_refused(client):
    env = mail_fixtures.load("table_in_body")
    env["documents"] = [_csv(n) for n in range(10)]
    assert post(client, env).status_code == 200
    env["documents"] = [_csv(n) for n in range(11)]
    response = post(client, env)
    assert response.status_code == 413 and response.get_json()["error"]["code"] == "input_too_large"


def test_decorative_inline_images_do_not_count_against_the_ten(client):
    env = mail_fixtures.load("inline_decoration_only")
    env["documents"] = [_csv(n) for n in range(10)] + env["documents"]
    assert post(client, env).status_code == 200


def test_an_attachment_over_the_document_limit_is_unaccounted_not_fatal(client, monkeypatch):
    monkeypatch.setattr(contract, "MAX_DOCUMENT_BYTES", 10)
    body = parse(client, "attachment_only")
    assert body["lines"] == [] and len(body["coverage"]["unaccounted"]) == 1
    assert "larger than" in body["coverage"]["unaccounted"][0]["reason"]


def test_an_image_over_the_image_limit_is_unaccounted_not_fatal(client, monkeypatch):
    monkeypatch.setattr(contract, "MAX_IMAGE_BYTES", 1000)
    body = parse(client, "inline_screenshot")
    assert len(body["coverage"]["unaccounted"]) == 1


def test_the_limits_are_the_documents_limits():
    assert (contract.MAX_DOCUMENTS, contract.MAX_DOCUMENT_BYTES, contract.MAX_IMAGE_BYTES) == (10, 20 * 1024 * 1024, 8 * 1024 * 1024)


# --------------------------------------------------------------------------- refusals


def test_exactly_one_input(client):
    env = mail_fixtures.load("table_in_body")
    both = client.post("/v1/parse", json={"contract": contract.CONTRACT, "correlationId": CID, "deadlineMs": 60_000, "email": env,
                                          "text": "4C 16mm2 120m", "options": {}}, headers={"Authorization": f"Bearer {TOKEN}"})
    assert both.status_code == 400 and both.get_json()["error"]["code"] == "invalid_request"
    docs = post(client, env, documents=[_csv(1)])
    assert docs.status_code == 400


def test_a_malformed_envelope_names_the_field_and_never_the_content(client):
    env = mail_fixtures.load("table_in_body")
    env["body"]["contentType"] = "rtf"
    env["subject"] = "SECRET-SUBJECT-TEXT"
    response = post(client, env)
    assert response.status_code == 400
    message = response.get_json()["error"]["message"]
    assert message.startswith("email.body.contentType") and "SECRET" not in message


@pytest.mark.parametrize("value", ["mail", [], 7, {}])
def test_a_non_envelope_is_refused(client, value):
    assert post(client, value).status_code == 400


def test_an_empty_email_body_with_nothing_attached_is_an_empty_but_valid_parse(client):
    env = copy.deepcopy(mail_fixtures.load("table_in_body"))
    env["body"] = {"contentType": "text", "content": ""}
    body = parse(client, env)
    assert body["lines"] == [] and counts(body) == (0, 0, 0, 0, 0)


def test_the_deadline_still_applies(client):
    response = client.post("/v1/parse", json={"contract": contract.CONTRACT, "correlationId": CID, "email": mail_fixtures.load("table_in_body")},
                           headers={"Authorization": f"Bearer {TOKEN}"})
    assert response.status_code == 400
