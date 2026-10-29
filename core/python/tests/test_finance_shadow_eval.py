"""The shadow evaluation's comparison and cost rules (`tools/finance_shadow_eval.py`). No database, blob or model."""

from __future__ import annotations

import importlib.util
from pathlib import Path

_SPEC = importlib.util.spec_from_file_location(
    "finance_shadow_eval", Path(__file__).resolve().parent.parent / "tools" / "finance_shadow_eval.py",
)
shadow = importlib.util.module_from_spec(_SPEC)
_SPEC.loader.exec_module(shadow)


def test_fields_compare_by_meaning_not_spelling():
    assert shadow._same("company", "TriCab (Australia) Pty Ltd", "TRICAB AUSTRALIA PTY. LTD.")
    assert not shadow._same("company", "TriCab (Australia) Pty Ltd", "TriCab NZ Limited")
    assert shadow._same("total_amount", "1,100.00".replace(",", ""), 1100)
    assert not shadow._same("total_amount", 1100.0, 1100.5)
    assert shadow._same("invoice_date", "01/09/2026", "2026-09-01")
    assert shadow._same("invoice_no", "INV-0042", "inv 0042")
    assert not shadow._same("invoice_no", "INV-0042", "INV-0043")
    assert shadow._same("currency", None, "AUD")


def test_ap_compares_fields_only_when_both_call_it_an_invoice():
    case = {"purpose": "ap_classify_extract", "stored": {"is_invoice": True, "company": "A Pty Ltd", "total_amount": 5}}
    assert shadow._compare(case, {"is_invoice": False}) == {"is_invoice": False}
    both = shadow._compare(case, {"is_invoice": True, "company": "A", "total_amount": 5.0})
    assert both["is_invoice"] and both["company"] and both["total_amount"]


def test_ar_classify_compares_the_three_decisions():
    case = {"purpose": "ar_classify", "stored": {
        "follow_up_category": "dispute", "proposed_response_type": "email", "follow_up_status": "needs_review"}}
    result = {"follow_up_category": "dispute", "proposed_response_type": "phone", "follow_up_status": "needs_review"}
    assert shadow._compare(case, result) == {"category": True, "method": False, "status": True}


def test_cost_uses_a_reported_cost_first_then_the_price_list():
    assert shadow._cost("google/gemini-2.5-flash", {"cost": 0.0012}) == 0.0012
    assert abs(shadow._cost("claude-haiku-5-5", {"input_tokens": 1_000_000, "output_tokens": 1_000_000}) - 0.60) < 1e-9
    assert shadow._cost("typesafe/jev-1.13", {"prompt_tokens": 10}) is None


def test_files_are_typed_by_their_bytes():
    assert shadow._sniff(b"%PDF-1.7 ...", "application/octet-stream") == "application/pdf"
    assert shadow._sniff(b"\xff\xd8\xff\xe0", "image/jpg") == "image/jpeg"
    assert shadow._sniff(b"PK\x03\x04", "application/zip") is None
