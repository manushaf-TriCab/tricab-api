"""Platform-owned: the ALI-FLEX alternative (`rfq_parser.aliflex`, parity plan E7.4).

The cases are the source's own (`tests/test_aliflex.py` at `ca2f924` on
`release/aliflex-prod-20260927`): the acceptance checks for the mapping, every exception
it must refuse to guess at, and the row transform. The source's store tests are not here;
creating the draft is PHP's, and its tests are in `RfqAliflexServiceTest`.
"""

from __future__ import annotations

import json

import pytest

from rfq_parser import aliflex as af
from rfq_parser import app as service
from rfq_parser import contract

TOKEN = "test-internal-token-0123456789"
CID = "corr-0123456789abcdef"


def proposed(code, comment=""):
    return af.propose(code, comment)["proposed_code"]


def test_the_policy_is_the_committed_release():
    assert af.POLICY_VERSION == "aliflex-map/2"


def test_xl_moves_to_kl_one_size_up_in_aluminium():
    assert proposed("XL-PAXN/1C25OR") == "KL-PAXA/1C35BK"
    assert proposed("XL-PAXN/1C95OR") == "KL-PAXA/1C120BK"
    assert proposed("XL-PAXN/1C240OR") == "KL-PAXA/1C300BK"


def test_lp_and_ls_earths_become_the_kl_sdi_earth():
    assert proposed("LP-XXXN/1C95EA") == "KL-PAXA/1C120EA"
    assert proposed("LS-XXXN/1C25EA") == "KL-PAXA/1C35EA"


def test_ls_carries_its_lszh_requirement_for_review():
    result = af.propose("LS-XXXN/1C25EA")
    assert result["status"] == af.STATUS_CHANGED and "LSZH" in result["review_note"]
    assert af.propose("LP-XXXN/1C25EA")["review_note"] == ""


def test_every_90c_style_moves_to_its_110c_counterpart():
    assert proposed("XU-PAZN/4C95BK") == "KU-P9ZA/4C120BK"
    assert proposed("XA-PASN/3C16OR") == "KA-P9SA/3C25BK"
    assert proposed("XN-PAXN/4C35OR") == "KN-P9XA/4C50BK"
    assert proposed("SX-PAXN/1C6BK") == "SK-PAXA/1C10BK"


def test_a_110c_style_keeps_its_style_and_only_changes_size():
    result = af.propose("KL-PAXN/5C35OR")
    assert result["proposed_code"] == "KL-P9XA/5C50BK" and result["action"] == "size_only"
    for code, expected in (("KU-PAZN/4C70BK", "KU-P9ZA/4C95BK"), ("KA-PAJN/1C50OR", "KA-PAJA/1C70BK"), ("KN-PAXN/1C120OR", "KN-PAXA/1C150BK"), ("SK-PBXN/3C25BK", "SK-P9XA/3C35BK")):
        assert proposed(code) == expected


def test_the_change_is_stated_explicitly():
    result = af.propose("XL-PAXN/1C25OR")
    assert (result["style_from"], result["style_to"]) == ("XL", "KL")
    assert (result["size_from"], result["size_to"]) == ("25", "35")
    assert (result["conductor_from"], result["conductor_to"]) == ("N", "A")
    assert result["reason"] == "Cu 25 mm² to Al 35 mm²; XL (90C) to KL (110C)."
    assert result["policy_version"] == af.POLICY_VERSION


def test_construction_is_kept_and_multicores_use_p9():
    assert proposed("XLT-PBXN/4C16BK") == "KLT-P9XA/4C25BK"
    assert proposed("XL-P9XN/5C120OR") == "KL-P9XA/5C150BK"
    for count in (2, 3, 4, 5, 12):
        result = af.propose(f"XL-PAXN/{count}C25OR")
        assert result["proposed_code"] == f"KL-P9XA/{count}C35BK"
        assert (result["config_from"], result["config_to"]) == ("PA", "P9")
    assert proposed("XL-PAXN/1C25OR") == "KL-PAXA/1C35BK"
    assert proposed("LP-XXXN/1C25EA") == "KL-PAXA/1C35EA"


def test_aluminium_jackets_are_black_except_earths():
    for source in ("XL-PAXN/1C25OR", "XL-PAXN/1C25RE", "XL-PAXN/1C25WH", "XL-PAXN/1C25BK"):
        result = af.propose(source)
        assert result["proposed_code"] == "KL-PAXA/1C35BK", source
        assert (result["colour_from"], result["colour_to"]) == (source[-2:], "BK")
    earth = af.propose("LP-XXXN/1C95EA")
    assert earth["proposed_code"] == "KL-PAXA/1C120EA" and (earth["colour_from"], earth["colour_to"]) == ("EA", "EA")


def test_the_ladder_advances_exactly_one_step():
    for before, after in zip(af.SIZE_LADDER, af.SIZE_LADDER[1:]):
        assert af.display_size(af.next_size(before)) == after
    assert proposed("XL-PAXN/4C1.5OR") == "KL-P9XA/4C2.5BK"
    # Codes write sub-1 mm² sizes without the leading zero, and so does the proposal.
    assert proposed("XL-PAXN/4C.75OR") == "KL-P9XA/4C1BK"
    assert proposed("XL-PAXN/4C.5OR") == "KL-P9XA/4C.75BK"
    assert af.propose("XL-PAXN/4C.5OR")["reason"] == "Cu 0.5 mm² to Al 0.75 mm²; XL (90C) to KL (110C)."


@pytest.mark.parametrize(
    ("code", "status", "reason", "comment"),
    [
        ("KL-PAXA/1C300BK", af.STATUS_UNCHANGED, "Already aluminium", ""),
        ("XL-PAXA/1C95OR", af.STATUS_UNCHANGED, "Already aluminium", ""),
        ("FR-PAXN/1C240RE", af.STATUS_UNCHANGED, "FR is not covered", ""),
        ("KC-CBXN/7C1.5BK", af.STATUS_UNCHANGED, "KC is not covered", ""),
        ("EA-PASN/4C16BK", af.STATUS_REVIEW, "no confirmed ALI-FLEX mapping", ""),
        ("XL-PAXN/1C630OR", af.STATUS_REVIEW, "630 mm² has no next", ""),
        ("XL-PAXN/1C800OR", af.STATUS_REVIEW, "800 mm² is not a standard size", ""),
        ("XL-PAXN/1C3OR", af.STATUS_REVIEW, "not a standard size", ""),
        ("XL-PAXN/1A4/0OR", af.STATUS_REVIEW, "Only mm²", ""),
        ("XA-PASN/2P1.5OR", af.STATUS_REVIEW, "Only mm²", ""),
        ("XL-PAXD/1C25OR", af.STATUS_REVIEW, "Tinned copper", ""),
        ("LP-XXXN/1C150BK", af.STATUS_REVIEW, "single-core green/yellow", ""),
        ("XL-PAXN/1C25OR", af.STATUS_REVIEW, "copper or no alternatives", "Client spec: copper only"),
        ("", af.STATUS_REVIEW, "No product code", ""),
        ("CABLE 25MM", af.STATUS_REVIEW, "could not be read", ""),
    ],
)
def test_what_the_rule_refuses_to_guess_at_is_kept_as_it_was(code, status, reason, comment):
    result = af.propose(code, comment)
    assert result["status"] == status, result
    assert result["proposed_code"] == (code or "").upper()
    assert reason in result["reason"]


def test_a_copper_comment_that_is_not_an_instruction_does_not_hold_the_line():
    assert af.propose("XL-PAXN/1C25OR", "Cu cable to MSB")["status"] == af.STATUS_CHANGED


def _row(code, total, *, comment="", number=1, length_each=None):
    each = total if length_each is None else length_each
    return {
        "id": f"row-{code}",
        "line_uid": "a" * 32,
        "selected_product_code": code,
        "status": "matched",
        "warnings": [],
        "line_note": comment,
        "length": {"length_each_m": each, "number_of_lengths": number, "total_length_m": total, "length_each": each, "total_length": total},
        "length_locked": True,
        "pricing": {"status": "available", "unit_price": 9.99},
        "final_unit_price": 12.5,
        "margin_percent": 30,
        "margin_source": "customer_default",
        "selected_product": {"product_code": code},
        "imported_line": {"product_code": code, "quantity": str(total), "comment": comment, "historical_unit_price": "10.00"},
    }


def test_quantities_and_comments_are_preserved():
    rows = [_row("XL-PAXN/1C240OR", 6480, comment="36 x 180m", number=36, length_each=180), _row("XL-PAXN/1C95OR", 1620, comment="9 x 180m", number=9, length_each=180)]
    out = af.alternative_rows(rows)
    assert [r["length"]["total_length"] for r in out] == [6480, 1620]
    assert [r["length"]["number_of_lengths"] for r in out] == [36, 9]
    assert [r["line_note"] for r in out] == ["36 x 180m; ALIFLEX Alt", "9 x 180m; ALIFLEX Alt"]


def test_a_changed_row_takes_the_proposal_and_drops_source_pricing_and_identity():
    source = _row("XL-PAXN/1C25OR", 160)
    changed = af.alternative_rows([source])[0]
    assert changed["selected_product_code"] == "KL-PAXA/1C35BK"
    for key in ("id", "line_uid", "pricing", "final_unit_price", "margin_percent", "margin_source", "selected_product"):
        assert key not in changed
    # The printed source record and its historical price stay as provenance.
    assert changed["imported_line"]["product_code"] == "XL-PAXN/1C25OR"
    assert changed["imported_line"]["historical_unit_price"] == "10.00"
    assert changed["aliflex"]["source_code"] == "XL-PAXN/1C25OR"
    assert changed["warnings"] == [] and changed["line_note"] == "ALIFLEX Alt"
    # The source row itself is untouched.
    assert source["line_note"] == "" and source["selected_product_code"] == "XL-PAXN/1C25OR" and source["final_unit_price"] == 12.5 and source["line_uid"] == "a" * 32


def test_the_reviewed_code_is_the_source_not_the_printed_one():
    row = _row("XL-PAXN/1C25OR", 160)
    row["final_product_code"] = "XL-PAXN/1C35OR"
    assert af.alternative_rows([row])[0]["selected_product_code"] == "KL-PAXA/1C50BK"


def test_review_and_unchanged_rows_keep_their_code_and_nothing_is_dropped():
    rows = [_row("XL-PAXN/1C630OR", 50), _row("FR-PAXN/1C240RE", 40), _row("KL-PAXA/1C300BK", 30)]
    out = af.alternative_rows(rows)
    assert [r["selected_product_code"] for r in out] == ["XL-PAXN/1C630OR", "FR-PAXN/1C240RE", "KL-PAXA/1C300BK"]
    assert out[0]["status"] == "flagged"
    assert "ALI-FLEX: 630 mm² has no next standard size. Kept as XL-PAXN/1C630OR." in out[0]["warnings"]
    assert out[1]["warnings"] == [] and [r["line_note"] for r in out] == ["", "", ""]
    assert out[1]["final_unit_price"] == 12.5  # an unchanged product keeps its price
    assert af.summarise(out) == {"changed": 0, "unchanged": 2, "review": 1}


def test_the_ls_note_is_a_row_warning():
    out = af.alternative_rows([_row("LS-XXXN/1C95EA", 80)])[0]
    assert out["selected_product_code"] == "KL-PAXA/1C120EA" and any("LSZH" in w for w in out["warnings"])


def test_the_header_carries_the_note_once_and_the_label_the_source():
    details = af.alternative_quote_details({"customer_id": "1", "comment": "hello"})
    assert details["comment"] == f"hello\n{af.QUOTE_NOTE}" and details["customer_id"] == "1"
    assert af.alternative_quote_details(details)["comment"] == details["comment"], "the note is not repeated"
    assert af.alternative_quote_details(None)["comment"] == af.QUOTE_NOTE
    assert af.alternative_label("Westelec RFQ") == "ALI-FLEX alternative · Westelec RFQ"
    assert af.alternative_label("  ") == "ALI-FLEX alternative"


def test_the_status_line_counts_what_changed_and_what_to_check():
    assert af.status_message({"changed": 3, "unchanged": 2, "review": 1}) == "3 line(s) changed to ALI-FLEX, 2 unchanged, 1 to check. Cable calculations must confirm sizing before ordering."
    assert af.status_message({"changed": 1, "unchanged": 0, "review": 0}) == "1 line(s) changed to ALI-FLEX. Cable calculations must confirm sizing before ordering."


def test_convert_is_the_pure_half_of_the_source_create():
    rows = [_row("XL-PAXN/1C25OR", 160), _row("FR-PAXN/1C240RE", 40)]
    out = af.convert(rows, {"comment": ""}, "Westelec")
    assert out["counts"] == {"changed": 1, "unchanged": 1, "review": 0}
    assert out["label"] == "ALI-FLEX alternative · Westelec" and out["policy_version"] == "aliflex-map/2"
    assert out["quote_details"]["comment"] == af.QUOTE_NOTE and len(out["rows"]) == 2
    assert out["message"].startswith("1 line(s) changed to ALI-FLEX, 1 unchanged.")
    nothing = af.convert([_row("FR-PAXN/1C240RE", 40)], {}, "")
    assert nothing["counts"]["changed"] == 0 and nothing["message"] == "No line matches the ALI-FLEX rule, so no alternative was made."


@pytest.fixture()
def client(monkeypatch):
    monkeypatch.setenv(contract.TOKEN_SETTING, TOKEN)
    service.app.config["TESTING"] = True
    return service.app.test_client()


def _post(client, token=TOKEN, **draft):
    body = {"rows": [_row("XL-PAXN/1C25OR", 160)], "quote_details": {"customer_id": "1"}, "label": "Westelec", **draft}
    return client.post(
        "/v1/aliflex",
        json={"contract": contract.CONTRACT, "correlationId": CID, "deadlineMs": 60_000, "draftJson": json.dumps(body)},
        headers={"Authorization": f"Bearer {token}"},
    )


def test_the_route_answers_the_conversion(client):
    response = _post(client)
    body = response.get_json()
    assert response.status_code == 200 and body["correlationId"] == CID
    conversion = body["conversion"]
    assert conversion["counts"]["changed"] == 1 and conversion["rows"][0]["selected_product_code"] == "KL-PAXA/1C35BK"
    assert conversion["label"] == "ALI-FLEX alternative · Westelec"


def test_the_route_needs_the_token(client):
    assert _post(client, token="wrong").status_code == 401


@pytest.mark.parametrize("change", [{"rows": ["x"]}, {"quote_details": []}, {"label": 5}, {"rows": [{}] * 2001}])
def test_the_route_refuses_what_the_contract_does_not_allow(client, change):
    assert _post(client, **change).status_code in (400, 413)
