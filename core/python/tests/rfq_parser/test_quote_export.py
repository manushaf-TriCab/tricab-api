"""Platform-owned: the CSV cells and the product summary (`rfq_parser.quote_export`, parity plan E7.1)."""

from __future__ import annotations

import json

import pytest

from rfq_parser import app as service
from rfq_parser import contract
from rfq_parser.quote_export import build_export, quote_lines_table, summary_lines_table, summary_quote_row

TOKEN = "test-internal-token-0123456789"
CID = "corr-0123456789abcdef"


def line(code="TPS3C25", cuts=2, each=50, price=5.5, text="1 | Site cable", **extra):
    total = cuts * each
    return {
        "raw_text": text,
        "source": {"original_text": text},
        "item_type": "cable",
        "final_status": "matched",
        "final_product_code": code,
        "length": {"number_of_lengths": cuts, "length_each": each, "total_length": total},
        "unit_price": 3.0,
        "final_unit_price": price,
        "line_total": round(price * total, 2),
        "pricing": {"cost_status": "stock", "unit_price": 3.0},
        **extra,
    }


def test_the_quote_lines_have_the_sources_eight_columns_and_serial_split():
    table = quote_lines_table([line(), line(text="Cable to pump", code="XL-PAXN/4C16OR")], include_prices=True)
    assert table["columns"] == ["Serial Number", "Product Description", "Product Code", "Cuts", "Cut Length", "Total Length", "Price", "Total Price"]
    assert table["rows"][0] == ["1", "Site cable", "TPS3C25", "2", "50", "100", "5.5", "550"]
    # No "serial |" prefix: the serial is the line's position.
    assert table["rows"][1][:2] == ["2", "Cable to pump"]


def test_without_prices_the_money_columns_are_dropped_not_blanked():
    table = quote_lines_table([line()], include_prices=False)
    assert table["columns"] == ["Serial Number", "Product Description", "Product Code", "Cuts", "Cut Length", "Total Length"]
    assert table["rows"] == [["1", "Site cable", "TPS3C25", "2", "50", "100"]]
    assert "5.5" not in json.dumps(table) and "550" not in json.dumps(table)


def test_cost_and_margin_are_never_cells():
    table = quote_lines_table([line(margin_percent=45)], include_prices=True)
    flat = json.dumps(table)
    assert "3.0" not in flat and "45" not in flat


def test_a_unit_price_stands_in_when_there_is_no_sell_price():
    row = line()
    row["final_unit_price"] = ""
    row["line_total"] = ""
    assert quote_lines_table([row], include_prices=True)["rows"][0][-2:] == ["3", "300"]


def test_the_summary_groups_products_and_is_priced_from_the_line_totals():
    rows = [line(price=5.0), line(price=6.0, text="2 | Other"), line(code="XL-PAXN/4C16OR", price=10.0, cuts=1, each=20)]
    built = build_export(rows, include_prices=True)
    assert [(s["product_code"], s["line_count"], s["quoted_total_length"]) for s in built["summary"]] == [("TPS3C25", 2, 200), ("XL-PAXN/4C16OR", 1, 20)]
    table = built["summaryLines"]
    # (500 + 600) / 200 m = 5.5 a metre, 1100 in all; the cuts are one run of the total.
    assert table["rows"][0] == ["1", "Summary quote - TPS3C25", "TPS3C25", "1", "200", "200", "5.5", "1100"]
    assert table["rows"][1][-2:] == ["10", "200"]


def test_a_product_with_no_sell_price_is_listed_but_not_exported():
    row = line()
    row.update(final_unit_price=None, unit_price=None, line_total=None)
    built = build_export([row], include_prices=True)
    assert len(built["summary"]) == 1 and built["summaryLines"]["rows"] == []


def test_the_summary_price_rounds_half_up_like_the_browser():
    # 10 / 3 m, three decimals: 3.333 and the total 3.333 * 3 = 9.999 -> 10
    row = line(cuts=1, each=3, price=3.3333)
    row["line_total"] = 10.0
    out = summary_quote_row(build_export([row], include_prices=True)["summary"][0], [row])
    assert out["final_unit_price"] == 3.333 and out["line_total"] == 10.0


def test_accessories_and_non_cables_are_not_summarised():
    rows = [line(), line(code="CLIP", line_kind="accessory"), line(code="X", final_status="not_cable")]
    assert [s["product_code"] for s in build_export(rows, include_prices=True)["summary"]] == ["TPS3C25"]


def test_summary_lines_without_prices_keep_only_the_free_columns():
    summary = build_export([line()], include_prices=False)
    assert summary["summaryLines"]["columns"][-1] == "Total Length"
    assert summary["summaryLines"]["rows"] == [["1", "Summary quote - TPS3C25", "TPS3C25", "1", "100", "100"]]
    assert summary_lines_table([], [], include_prices=True)["rows"] == []


@pytest.fixture()
def client(monkeypatch):
    monkeypatch.setenv(contract.TOKEN_SETTING, TOKEN)
    service.app.config["TESTING"] = True
    return service.app.test_client()


def _export(client, token=TOKEN, **draft):
    body = {"rows": [line()], "includePrices": True, **draft}
    return client.post(
        "/v1/export",
        json={"contract": contract.CONTRACT, "correlationId": CID, "deadlineMs": 60_000, "draftJson": json.dumps(body)},
        headers={"Authorization": f"Bearer {token}"},
    )


def test_the_route_answers_the_summary_and_both_tables(client):
    response = _export(client)
    body = response.get_json()
    assert response.status_code == 200 and body["correlationId"] == CID
    assert body["summary"][0]["cuts_text"] == "(2 x 50)" and body["summary"][0]["line_indexes"] == [0]
    assert body["quoteLines"]["rows"][0][2] == "TPS3C25" and body["summaryLines"]["rows"][0][1] == "Summary quote - TPS3C25"


def test_the_route_needs_the_token(client):
    assert _export(client, token="wrong").status_code == 401


@pytest.mark.parametrize("change", [{"rows": ["x"]}, {"includePrices": "yes"}, {"rows": [{}] * 601}])
def test_the_route_refuses_what_the_contract_does_not_allow(client, change):
    response = _export(client, **change)
    assert response.status_code in (400, 413)
