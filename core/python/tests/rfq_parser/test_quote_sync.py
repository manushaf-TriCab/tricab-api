"""Platform-owned: the 4js quote plan (`rfq_parser.quote_sync`, parity plan E7.3).

The scenarios are the source's own regressions for `prepare_quote_push`
(`tests/test_sales_sync_safety.py`, `tests/test_4js_push.py`, pinned commit),
restated over the plan instead of the wire: what the source's transport saw is
what the plan holds. Nothing here contacts anything.
"""

from __future__ import annotations

import json
from copy import deepcopy
from datetime import date

import pytest

from rfq_parser import app as service
from rfq_parser import contract, quote_sync

TOKEN = "test-internal-token-0123456789"
CID = "corr-0123456789abcdef"
TODAY = date(2026, 10, 2)

DETAILS = dict(customer_id="123", contact="Buyer", author="REP", salesoffice="PER", payterms="EOM30", region="australia")


def row(**changes):
    return dict(dict(final_product_code="TEST-CABLE", status="matched", length={"total_length": 100}, unit_price=10, final_unit_price=15, margin_percent=33), **changes)


def plan_for(rows, details=DETAILS, margins=None, **kwargs):
    return quote_sync.prepare_quote_push(rows, details, margins=margins or {}, today=TODAY, **kwargs)


@pytest.mark.parametrize(
    "bad",
    [
        row(status="unresolved"),
        row(final_product_code=""),
        row(length={"total_length": 0}),
        row(final_unit_price=0),
        row(length={"total_length": float("nan")}),
        row(final_unit_price=float("inf")),
        row(final_unit_price=-1),
        row(length={"total_length": 0.000001}),
        row(pricing={"status": "pending"}),
    ],
)
def test_one_invalid_included_line_stops_the_whole_quote(bad):
    plan, problem = plan_for([row(), bad])
    assert plan is None and problem is not None
    payload, status = problem
    assert status == 400 and not payload["ok"]
    assert payload["blocking_issues"][0]["row_number"] == 2


def test_an_excluded_line_is_reported_and_never_sent_even_if_approved():
    excluded = row(directive_excluded=True, final_status="approved", directive_exclusion_reason="Customer requested omission")
    plan, problem = plan_for([row(), excluded])
    assert problem is None
    assert len(plan["line_items"]) == 1
    assert plan["excluded"][0]["row_number"] == 2 and "Customer requested omission" in plan["excluded"][0]["reason"]


def test_only_excluded_lines_cannot_make_an_empty_quote():
    plan, problem = plan_for([row(directive_excluded=True)])
    assert plan is None and problem[1] == 400


def test_a_non_commercial_heading_is_reported_separately():
    plan, problem = plan_for([{"status": "admin", "raw_text": "Schedule"}, row()])
    assert problem is None and len(plan["line_items"]) == 1 and len(plan["excluded"]) == 1


def test_an_unpriced_line_is_planned_at_the_customer_default_and_the_row_is_left_alone():
    rows = [row(margin_percent=0, final_unit_price=10)]
    before = deepcopy(rows)
    plan, problem = plan_for(rows, margins={"TEST-CABLE": 25})
    assert problem is None
    assert rows == before
    assert plan["line_items"][0]["price"] == 13.334 and plan["fallback_margin"] == []


def test_with_no_rule_the_fallback_margin_is_used_and_reported():
    plan, problem = plan_for([row(margin_percent=0, final_unit_price=10)])
    assert problem is None
    # 10 at 30% is 14.286 (rounded up to the third decimal), and the line says so.
    assert plan["line_items"][0]["price"] == 14.286
    assert plan["fallback_margin"][0]["margin"] == 30 and plan["fallback_margin"][0]["row_number"] == 1


def test_the_cost_rule_is_resolved_for_the_lines_cost_value_not_sent_as_a_word():
    plan, problem = plan_for([row(margin_percent=0, final_unit_price=10)], margins={"TEST-CABLE": "cost"})
    assert problem is None and plan["fallback_margin"] == []
    assert plan["line_items"][0]["price"] > 10


def test_a_price_a_person_set_is_never_replaced_by_a_default():
    plan, problem = plan_for([row()], margins={"TEST-CABLE": 25})
    assert problem is None
    # row() carries margin 33 and a hand-set sell of 15, not the 13.334 a 25% default would give.
    assert plan["line_items"][0]["price"] == 15


@pytest.mark.parametrize(("region", "currency"), [("australia", "AUD"), ("newzealand", "NZD"), ("usa", "USD"), ("asia", "SGD")])
def test_a_supported_currency_reaches_the_header(region, currency):
    plan, problem = plan_for([row()], dict(DETAILS, region=region, currency=currency))
    assert problem is None and plan["hdr_params"]["currency"] == currency


def test_an_unsupported_currency_stops_before_a_plan_exists():
    plan, problem = plan_for([row()], dict(DETAILS, currency="EUR"))
    assert plan is None and problem[1] == 400 and problem[0]["error"] == "Unsupported currency: 'EUR'"


def test_missing_header_fields_and_an_unknown_region_are_named():
    plan, problem = plan_for([row()], dict(DETAILS, customer_id="", contact=" "))
    assert plan is None and problem[0]["error"] == "Missing required fields: customer_id, contact"
    assert plan_for([row()], dict(DETAILS, region="mars"))[1][0]["error"] == "Unknown region: 'mars'"


def test_zero_canonical_values_cannot_fall_back_to_legacy_values():
    plan, problem = plan_for([row(final_unit_price=0, final_price=15)])
    assert plan is None and problem[1] == 400


def test_a_persons_own_margin_keeps_the_reviewed_price_whatever_the_customers_rule_says():
    # A margin of 33 is a person's: the sent price is the reviewed one, so parity holds and nothing is refused.
    plan, problem = plan_for([row()], margins={"TEST-CABLE": 10})
    assert problem is None and plan["line_items"][0]["price"] == 15


def test_a_double_multiplied_legacy_cut_row_plans_the_canonical_total():
    # `test_4js_push`: the editable aliases were corrupted to 4 x 136; the canonical ones say 4 x 34 m = 136 m.
    legacy = {
        "final_product_code": "KL-PAXA/1C120BK",
        "final_status": "matched",
        "length": {"number_of_lengths": 4, "length_each": 136, "length_each_m": 34, "total_length": 544, "total_length_m": 136},
        "final_unit_price": 10,
        "pricing": {"unit_price": 8},
    }
    plan, problem = plan_for([legacy])
    assert problem is None and plan["line_items"][0]["quantity"] == 136
    assert plan["line_items"][0]["cprc"] == 8.0


def test_the_header_is_the_sources_and_carries_neither_type_nor_live():
    plan, problem = plan_for([row(line_note="Site A", line_kind="accessory")], dict(DETAILS, cusref="PO-1", comment="hello", validtill="31/12/26"), origin="portal")
    assert problem is None
    header = plan["hdr_params"]
    assert "type" not in header and "live" not in header
    assert header == {
        "ref": "0", "cust": "123", "debtor": header["debtor"], "currency": "AUD", "unitsys": "M", "salesoffice": "PER", "payterms": "EOM30",
        "author": "REP", "validtill": "31/12/26", "carrier": "", "contact": "Buyer", "phone": "",
        "comment": "hello - Auto-created from RFQ portal by REP", "cusref": "PO-1", "status": "DRAFT", "date": "02/10/26", "version": "0",
    }
    assert plan["line_items"][0]["unitsys"] == "U" and plan["line_items"][0]["comment"] == "Site A"
    assert plan["is_update"] is False and plan["push_version"] == "0" and plan["existing_refno"] == ""


def test_valid_till_defaults_to_a_week_from_the_callers_today():
    plan, _ = plan_for([row()])
    assert plan["hdr_params"]["validtill"] == "09/10/26" and plan["hdr_params"]["date"] == "02/10/26"


def test_an_email_origin_quote_keeps_only_what_was_typed():
    plan, _ = plan_for([row()], dict(DETAILS, comment="Auto-created from RFQ email forwarded by A"), origin="email")
    assert plan["hdr_params"]["comment"] == "Auto-created from RFQ email forwarded by A"
    assert quote_sync.quote_header_comment({"author": ""}, "portal") == "Auto-created from RFQ portal"
    assert quote_sync.quote_header_comment({}, "") == ""


def test_a_new_version_of_a_quote_in_4js_targets_it():
    plan, problem = plan_for([row()], existing_refno="721999", version=3)
    assert problem is None
    assert plan["is_update"] is True and plan["existing_refno"] == "721999" and plan["push_version"] == "3"
    assert plan["hdr_params"]["ref"] == "721999" and plan["hdr_params"]["version"] == "3"


def test_the_next_version_is_at_least_two():
    assert [quote_sync.next_quote_version(v) for v in (None, "", "x", 1, "1", 2, "7")] == [2, 2, 2, 2, 2, 3, 8]


def test_a_line_note_of_none_is_empty_and_a_long_one_is_cut_to_200():
    plan, _ = plan_for([row(line_note=None), row(line_note="x" * 300)])
    assert [len(item["comment"]) for item in plan["line_items"]] == [0, 200]


def test_the_plan_is_what_json_can_carry():
    plan, _ = plan_for([row(), row(directive_excluded=True)])
    assert json.loads(json.dumps(plan)) == plan


# ------------------------------------------------------------------ the route


@pytest.fixture()
def client(monkeypatch):
    monkeypatch.setenv(contract.TOKEN_SETTING, TOKEN)
    service.app.config["TESTING"] = True
    return service.app.test_client()


def _post(client, token=TOKEN, **draft):
    body = {"rows": [row()], "quote_details": DETAILS, "margins": {}, "today": "2026-10-02", "existing_refno": None, "version": None, "origin": "portal", **draft}
    return client.post(
        "/v1/sync-plan",
        json={"contract": contract.CONTRACT, "correlationId": CID, "deadlineMs": 60_000, "draftJson": json.dumps(body)},
        headers={"Authorization": f"Bearer {token}"},
    )


def test_the_route_answers_the_plan(client):
    response = _post(client)
    body = response.get_json()
    assert response.status_code == 200 and body["correlationId"] == CID and "problem" not in body
    assert body["plan"]["line_items"][0]["price"] == 15 and body["plan"]["hdr_params"]["date"] == "02/10/26"


def test_a_problem_is_an_answer_with_its_status(client):
    response = _post(client, rows=[row(final_unit_price=0)])
    body = response.get_json()
    assert response.status_code == 200 and "plan" not in body
    assert body["problem"]["status"] == 400 and body["problem"]["payload"]["blocking_issues"][0]["row_number"] == 1


def test_the_route_needs_the_token(client):
    assert _post(client, token="wrong").status_code == 401


@pytest.mark.parametrize(
    "change",
    [{"rows": ["x"]}, {"quote_details": []}, {"margins": []}, {"today": "yesterday"}, {"existing_refno": 5}, {"version": 1, "existing_refno": "7"}, {"version": 3}, {"origin": "fax"}, {"rows": [{}] * 2001}],
)
def test_the_route_refuses_what_the_contract_does_not_allow(client, change):
    assert _post(client, **change).status_code in (400, 413)
