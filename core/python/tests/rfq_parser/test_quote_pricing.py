"""Platform-owned: the margin pass the source left in the browser (`rfq_parser.quote_pricing`)."""

from __future__ import annotations

import json

import pytest

from rfq_parser import app as service
from rfq_parser import contract
from rfq_parser.quote_pricing import derive_margin, normalize_margin, price_rows, unpriced_count

TOKEN = "test-internal-token-0123456789"
CID = "corr-0123456789abcdef"


@pytest.fixture()
def cost(monkeypatch):
    prices = {"XL-PAXN/4C16OR": 10.0, "TPS3C25": 4.0}

    def lookup(code, length, region=None):
        if code in prices:
            return {"status": "available", "unit_price": prices[code], "region": region or "australia", "dbco": "2507", "price_uom": "m"}
        return {"status": "not_found", "unit_price": None, "region": region or "australia", "dbco": "2507"}

    monkeypatch.setattr("rfq_parser.pipeline.cost_api.lookup", lookup)
    monkeypatch.setattr("rfq_parser.pipeline.cost_api.warm", lambda *a, **k: None, raising=False)
    return prices


def row(code="XL-PAXN/4C16OR", metres=100, **extra):
    return {"final_product_code": code, "status": "matched", "length": {"total_length": metres, "number_of_lengths": 1, "length_each": metres}, "margin_percent": 0, **extra}


def priced(rows, *, margins=None, reprice=True, currency="AUD", region="australia"):
    return price_rows(rows, region=region, currency=currency, default_margins=margins or {}, reprice=reprice)


def test_a_row_is_priced_at_cost_when_nobody_set_a_margin(cost):
    (result,) = priced([row()])
    assert (result["unit_price"], result["margin_percent"], result["final_unit_price"], result["line_total"]) == (10.0, 0, 10.0, 1000.0)


def test_the_customer_default_margin_applies_and_rounds_the_sell_price_up(cost):
    (result,) = priced([row()], margins={"XL-PAXN/4C16OR": 33})
    # margin on sell: 10 * 100 / 67 = 14.9253..., rounded up at the third decimal
    assert result["margin_percent"] == 33 and result["final_unit_price"] == 14.926
    assert result["line_total"] == 1492.6


def test_a_style_with_no_customer_rule_falls_back_to_the_cost_value_margin(cost):
    # 4js's x_cval2mgnr: 70 less 5, 5, 5, 2, 1 per band until the line's cost value (1000) is under the band's threshold.
    (result,) = priced([row()], margins={"XL-PAXN/4C16OR": "cost"})
    assert result["margin_percent"] == 52 and result["final_unit_price"] == 20.834
    assert price_rows([row(metres=2000)], region="australia", currency="AUD", default_margins={"XL-PAXN/4C16OR": "cost"}, reprice=True)[0]["margin_percent"] == 25


def test_a_typed_margin_outranks_the_customer_default(cost):
    (result,) = priced([row(margin_percent=20, margin_locked=True)], margins={"XL-PAXN/4C16OR": 33})
    assert result["margin_percent"] == 20 and result["final_unit_price"] == 12.5


def test_a_typed_sell_price_holds_and_the_margin_moves(cost):
    (result,) = priced([row(final_unit_price=15, sell_price_locked=True, unit_price=10)], margins={"XL-PAXN/4C16OR": 33})
    assert result["final_unit_price"] == 15 and result["margin_percent"] == 33  # trunc((1 - 10/15) * 100)
    assert result["sell_price_locked"] is True
    assert result["line_total"] == 1500.0


def test_an_exact_margin_is_not_lost_to_float_noise(cost):
    # 1 - 10/12.5 is 0.19999999999999996 in floats; the source's formula reads 19.
    assert derive_margin(10, 12.5) == 20 and derive_margin(10, 15) == 33 and derive_margin(10, 8) == -25
    (result,) = priced([row(final_unit_price=12.5, sell_price_locked=True, unit_price=10)])
    assert result["margin_percent"] == 20


def test_a_margin_edit_without_repricing_keeps_the_cost_it_holds(cost, monkeypatch):
    def refuse(*_a, **_k):
        raise AssertionError("the Cost API must not be asked")

    monkeypatch.setattr("rfq_parser.pipeline.cost_api.lookup", refuse)
    (result,) = priced([row(unit_price=8.0, margin_percent=50, margin_locked=True)], reprice=False)
    assert result["unit_price"] == 8.0 and result["final_unit_price"] == 16.0 and result["line_total"] == 1600.0


def test_an_unlocked_row_keeps_the_margin_it_had_when_there_is_no_default(cost):
    (result,) = priced([row(margin_percent=12)])
    assert result["margin_percent"] == 12


def test_accessories_default_to_fifty(cost):
    accessory = row("TPS3C25", 1, line_kind="accessory", margin_percent=None)
    (result,) = priced([accessory])
    assert result["margin_percent"] == 50 and result["final_unit_price"] == 8.0


def test_a_code_the_cost_api_does_not_carry_is_unpriced(cost):
    rows = priced([row(), row("NOT-A-CODE")])
    assert rows[1]["pricing"]["status"] == "not_found" and rows[1]["final_unit_price"] is None
    assert unpriced_count(rows) == 1


def test_changing_currency_converts_cost_then_margins_again(cost):
    (result,) = priced([row(margin_percent=20, margin_locked=True)], currency="NZD")
    assert result["pricing"]["quote_currency"] == "NZD"
    assert result["unit_price"] == 12.351 and result["final_unit_price"] == 15.439


@pytest.mark.parametrize("value,expected", [(23.987, 23), ("33", 33), (-5, 0), ("x", 0), (None, 0), (float("nan"), 0)])
def test_margins_are_whole_numbers_truncated(value, expected):
    assert normalize_margin(value) == expected


# ---------------------------------------------------------------- the route


@pytest.fixture()
def client(monkeypatch):
    monkeypatch.setenv(contract.TOKEN_SETTING, TOKEN)
    service.app.config["TESTING"] = True
    return service.app.test_client()


def _price(client, **draft):
    body = {"rows": [row()], "region": "australia", "currency": "AUD", "defaultMargins": {}, "reprice": True, **draft}
    return client.post(
        "/v1/price",
        json={"contract": contract.CONTRACT, "correlationId": CID, "deadlineMs": 60_000, "draftJson": json.dumps(body)},
        headers={"Authorization": f"Bearer {TOKEN}"},
    )


def test_the_route_prices_in_order_and_counts_what_has_no_price(client, cost):
    response = _price(client, rows=[row("NOT-A-CODE"), row(), row("TPS3C25", 10)], defaultMargins={"tps3c25": 20})
    body = response.get_json()
    assert response.status_code == 200 and body["correlationId"] == CID
    assert [r["final_product_code"] for r in body["rows"]] == ["NOT-A-CODE", "XL-PAXN/4C16OR", "TPS3C25"]
    assert body["unpriced"] == 1
    assert body["rows"][2]["margin_percent"] == 20 and body["rows"][2]["final_unit_price"] == 5.0


@pytest.mark.parametrize(
    "change",
    [{"region": "mars"}, {"currency": "EUR"}, {"reprice": "yes"}, {"rows": ["x"]}, {"defaultMargins": []}, {"rows": [row()] * 601}],
)
def test_the_route_refuses_what_the_contract_does_not_allow(client, cost, change):
    response = _price(client, **change)
    assert response.status_code in (400, 413) and response.get_json()["error"]["code"] in ("invalid_request", "input_too_large")


def test_styles_come_from_the_catalogue(client):
    from rfq_parser.pipeline import catalog

    code = next(iter(catalog.products_by_code))
    response = client.post(
        "/v1/products/styles",
        json={"contract": contract.CONTRACT, "correlationId": CID, "codes": [code.lower(), "NOT-A-CODE"]},
        headers={"Authorization": f"Bearer {TOKEN}"},
    )
    styles = response.get_json()["styles"]
    assert styles == {code.upper(): str(catalog.products_by_code[code].style).upper()}
