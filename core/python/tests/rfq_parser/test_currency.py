# Generated from the RFQ parser's tests/test_currency.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
from rfq_parser.currency import RATE_EFFECTIVE_DATE, conversion_rate
from rfq_parser.pipeline import apply_quote_pricing


def test_supplied_matrix_rates_are_oriented_source_to_target():
    assert conversion_rate("AUD", "NZD") == 1.2109
    assert conversion_rate("AUD", "USD") == 0.7166
    assert conversion_rate("AUD", "SGD") == 0.9122
    assert conversion_rate("NZD", "AUD") == 0.8258


def test_pricing_converts_aud_cost_and_records_rate(monkeypatch):
    monkeypatch.setattr(
        "rfq_parser.pipeline.cost_api.lookup",
        lambda *_args, **_kwargs: {"status": "ok", "unit_price": 100.0, "region": "australia", "dbco": "2507"},
    )
    row = {
        "final_product_code": "TEST",
        "status": "matched",
        "length": {"total_length": 10},
        "margin_percent": 20,
    }

    result = apply_quote_pricing([row], region="australia", currency="NZD")["rows"][0]

    assert result["unit_price"] == 121.09
    assert result["final_unit_price"] == 121.09
    assert result["line_total"] == 1210.9
    assert result["pricing"]["quote_currency"] == "NZD"
    assert result["pricing"]["exchange_rate"] == 1.2109
    assert result["pricing"]["exchange_rate_date"] == RATE_EFFECTIVE_DATE


def test_locked_sell_converts_from_previous_quote_currency(monkeypatch):
    monkeypatch.setattr(
        "rfq_parser.pipeline.cost_api.lookup",
        lambda *_args, **_kwargs: {"status": "ok", "unit_price": 100.0, "region": "australia", "dbco": "2507"},
    )
    row = {
        "final_product_code": "TEST",
        "status": "matched",
        "length": {"total_length": 1},
        "pricing": {"quote_currency": "AUD"},
        "unit_price": 100,
        "final_unit_price": 150,
        "sell_price_locked": True,
    }

    result = apply_quote_pricing([row], region="australia", currency="USD")["rows"][0]

    assert result["unit_price"] == 71.66
    assert result["final_unit_price"] == 107.49
