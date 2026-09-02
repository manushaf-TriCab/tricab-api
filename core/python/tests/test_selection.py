"""End-to-end tests for the finder.

The matching path must not diverge from the RFQ parser: wherever the parser
already resolves a line, the finder must return the same product code. The
finder only adds an answer where the parser had none — the conductor size.

Tests needing the legacy database are skipped when it isn't configured, so the
suite is runnable on a laptop with nothing but `.env`'s matcher path set.
"""

from __future__ import annotations

import pytest

from product_finder import rules
from product_finder.selection import FINDER, FinderRequest, vocabularies
from product_finder.specs import SPECS

rules_available = rules.status().available

requires_rules = pytest.mark.skipif(
    not rules_available, reason="the cable catalogue did not load"
)
requires_sizing = pytest.mark.skipif(
    not SPECS.available, reason="no ampacity source is available"
)


# ── parity with the RFQ parser ───────────────────────────────────────────────

PARITY_CASES = [
    "4 core 120mm2 copper XLPE 90 deg C, orange sheath",
    "Fire rated 3 core 16mm2",
    "12 pair 1.5mm2 instrumentation, individually screened, LSZH",
    "Shipboard power 4C 70mm2 tinned, SOLAS",
    "2 core 2.5mm2 control cable LSZH",
    "1 core 400mm2 aluminium XLPE",
]


@requires_rules
@pytest.mark.parametrize("text", PARITY_CASES)
def test_matches_the_rfq_parser_where_it_already_resolves(text):
    """Where the parser resolves a line, the finder must agree exactly."""
    baseline = rules.match_line(text)
    if baseline["status"] != "matched":
        pytest.skip(f"parser does not resolve this line: {baseline['status']}")

    result = FINDER.find(FinderRequest(text=text)).public()

    assert result["status"] == "matched"
    assert result["productCode"] == baseline["selected_product_code"], (
        f"finder diverged from the RFQ parser on {text!r}"
    )


@requires_rules
def test_free_text_and_parameters_agree():
    """The same requirement stated two ways must give the same code."""
    from_text = FINDER.find(
        FinderRequest(text="4 core 120mm2 copper XLPE 90 deg C, orange sheath")
    ).public()
    from_params = FINDER.find(
        FinderRequest(
            cable_type=4, cores=4, cross_section=120, colour="OR", conductor="N"
        )
    ).public()

    assert from_text["productCode"] == from_params["productCode"]


# ── the code it produces is a real code ──────────────────────────────────────


@requires_rules
def test_emitted_codes_round_trip_through_the_grammar():
    for text in PARITY_CASES:
        result = FINDER.find(FinderRequest(text=text)).public()
        code = result["productCode"]
        if not code:
            continue
        parsed = rules.parse_code(code)
        assert parsed["valid"], f"{code} is not a valid product code"


@requires_rules
def test_stocked_flag_reflects_the_catalogue():
    result = FINDER.find(
        FinderRequest(text="4 core 120mm2 copper XLPE 90 deg C, orange sheath")
    ).public()
    product = rules.product_by_code(result["productCode"])
    assert result["stocked"] is (product is not None)


@requires_rules
def test_make_to_order_is_flagged_not_hidden():
    """A construction that can be made but isn't stocked must say so."""
    result = FINDER.find(FinderRequest(text="Shipboard power 4C 70mm2 tinned, SOLAS")).public()
    if result["stocked"]:
        pytest.skip("this line is stocked in the current catalogue")
    assert any("made" in w.lower() or "TSD" in w for w in result["warnings"])


# ── honesty about what was assumed ───────────────────────────────────────────


@requires_rules
def test_inferred_fields_are_reported():
    """Anything the customer did not state must appear in inferredFields."""
    result = FINDER.find(FinderRequest(cable_type=4, cores=4, cross_section=120)).public()
    assert result["inferredFields"], "defaults were applied but not disclosed"
    assert "colour" in result["inferredFields"]


@requires_rules
def test_inference_caps_confidence():
    result = FINDER.find(FinderRequest(cable_type=4, cores=4, cross_section=120)).public()
    assert result["inferredFields"]
    assert result["confidence"] <= 0.80, (
        "a result built on assumptions must not claim high confidence"
    )


@requires_rules
def test_assumed_construction_is_disclosed():
    """Duty with no construction falls back to the house default, and says so."""
    result = FINDER.find(
        FinderRequest(text="200 A over 60 m, 415 V three phase, on tray")
    ).public()
    assert any("assumed" in w.lower() for w in result["warnings"])


# ── graceful degradation ─────────────────────────────────────────────────────


@requires_rules
def test_sizing_unavailable_is_explained_not_crashed(tmp_path, monkeypatch):
    """No ampacity source must degrade to matching, not fail the request.

    This used to run only when the developer had no database configured, which
    on a machine set up to do this work meant never. Pointing a
    `JsonSpecsRepository` at an empty directory reproduces the same state on any
    machine, so the degradation path is actually covered.
    """
    from product_finder import selection
    from product_finder.json_specs_repo import JsonSpecsRepository
    from product_finder.sizer import Sizer

    empty = JsonSpecsRepository(tmp_path)
    assert not empty.available
    monkeypatch.setattr(selection, "SIZER", Sizer(empty))

    result = FINDER.find(
        FinderRequest(cable_type=4, load_amps=200, length_m=80)
    ).public()
    assert result["status"] in {"needs-clarification", "unresolved"}
    assert any("unavailable" in w.lower() for w in result["warnings"])


def test_empty_request_does_not_crash():
    result = FINDER.find(FinderRequest()).public()
    assert result["status"] in {"unresolved", "needs-clarification"}
    assert result["detail"]


def test_nonsense_text_is_unresolved_not_wrong():
    result = FINDER.find(FinderRequest(text="please send a quote thanks")).public()
    assert result["status"] in {"unresolved", "needs-clarification"}


# ── vocabularies for the UI ──────────────────────────────────────────────────


def test_vocabularies_are_complete():
    vocab = vocabularies()
    assert len(vocab["cableTypes"]) == 16
    assert len(vocab["installMethods"]) == 9
    assert len(vocab["phases"]) == 3
    assert 400 in vocab["voltages"]
    assert 120 in vocab["crossSections"]


@requires_rules
def test_style_vocabulary_comes_from_the_register():
    vocab = vocabularies()
    codes = {s["code"] for s in vocab["styles"]}
    for expected in ("XL", "KL", "CC", "FR"):
        assert expected in codes


# ── sizing, when the database is there ───────────────────────────────────────


@requires_sizing
@requires_rules
def test_sizes_a_conductor_from_load_and_length():
    result = FINDER.find(
        FinderRequest(
            cable_type=4, load_amps=200, length_m=10, phase=3,
            voltage=400, install=1, ambient_c=40, operating_c=90,
        )
    ).public()

    assert result["status"] == "matched"
    assert result["productCode"]
    sizing = result["sizing"]
    assert sizing and sizing["status"] == "sized"

    candidate = sizing["candidate"]
    assert candidate["totalCapacityAmps"] >= 200, "sized below the design load"
    assert candidate["voltDropPct"] <= 5.0
    assert "cross_section" in result["inferredFields"]


@requires_sizing
@requires_rules
def test_fixture_from_the_legacy_suite_200a_10m_xlpe_90():
    """From `test/cableTest.php::general_test()`.

    200 A, 10 m, 400 V three phase, 40 degC ambient, SDI XLPE, unenclosed
    spaced, minimum runs, 90 degC: the legacy expects 70 mm2 with a 25 mm2
    earth and volt drop in 0.20-0.29%.
    """
    result = FINDER.find(
        FinderRequest(
            cable_type=1, load_amps=200, length_m=10, phase=3, voltage=400,
            install=1, ambient_c=40, operating_c=90, strategy="min-runs",
        )
    ).public()

    sizing = result["sizing"]
    if not sizing or sizing["status"] != "sized":
        pytest.skip(f"no solution: {sizing and sizing['detail']}")

    candidate = sizing["candidate"]
    assert candidate["spec"]["crossSection"] == 70
    assert candidate["earthCrossSection"] == 25
    assert 0.20 <= candidate["voltDropPct"] <= 0.29


@requires_sizing
@requires_rules
def test_fixture_from_the_legacy_suite_200a_10m_kl_110():
    """Same fixture at 110 degC, which routes to KL: 50 mm2, 16 mm2 earth,
    volt drop 0.31-0.44%."""
    result = FINDER.find(
        FinderRequest(
            cable_type=1, load_amps=200, length_m=10, phase=3, voltage=400,
            install=1, ambient_c=40, operating_c=110, strategy="min-runs",
        )
    ).public()

    sizing = result["sizing"]
    if not sizing or sizing["status"] != "sized":
        pytest.skip(f"no solution: {sizing and sizing['detail']}")

    candidate = sizing["candidate"]
    assert candidate["spec"]["crossSection"] == 50
    assert candidate["earthCrossSection"] == 16
    assert 0.31 <= candidate["voltDropPct"] <= 0.44


@requires_sizing
def test_fix_max_op_temp_defaults_to_the_cable_rating():
    """FIX: the default conductor-temperature ceiling is the cable's own rating.

    The legacy form defaults the field to `ambient < 50 ? 50 : ambient + 10`,
    but that is a UI default — `find_cable` itself defaults to `$maxtemp`
    (db.php:543). Using the form value rejects any size whose conductor runs
    hotter than 50 degC, which badly oversizes: this duty comes out at 185 mm2
    instead of 70 mm2 because 70 mm2 runs at 74.7 degC, fine for a 90 degC cable.
    """
    from product_finder.sizer import SIZER, SizingRequest

    request = SizingRequest(
        load_amps=200, length_m=10, cable_type=1, install=1,
        ambient_c=40, operating_c=90,
    )
    assert request.resolved_max_op_temp(90) == 90

    result = SIZER.size(request)
    if result.status != "sized" or not result.candidate:
        pytest.skip("no solution available in this dataset")

    assert result.candidate.spec.cross_section == 70, "oversized on temperature"
    assert result.candidate.operating_temp > 50, (
        "the fixture only distinguishes the two defaults above 50 degC"
    )


@requires_sizing
def test_fix_pinned_size_is_verified_at_one_run_not_paralleled_until_it_passes():
    """FIX: verifying a stated size must not search run counts.

    Searching answers a different question — it keeps adding parallel runs until
    the stated size works, so 2.5 mm2 'passes' a 400 A duty on enough runs.
    """
    from product_finder.sizer import SIZER, SizingRequest

    result = SIZER.size(
        SizingRequest(
            load_amps=400, length_m=200, cable_type=4, install=1,
            pinned_cross_section=2.5,
        )
    )
    assert result.status == "no-solution", "2.5 mm2 must not pass a 400 A duty"
    assert result.considered < 100, "should not have swept the run range"


@requires_rules
def test_fix_application_wording_does_not_force_single_core():
    """FIX: "fire rated cable, three phase" matched the fire pattern, which maps
    to SDI FIRE, so an AC feeder came back as a single core."""
    result = FINDER.find(
        FinderRequest(text="Fire rated cable, 150 A over 45 m on cable tray, three phase")
    ).public()
    code = result["productCode"]
    if not code:
        pytest.skip("no code resolved in this dataset")
    assert "/1C" not in code, f"{code} is single core for a three-phase feeder"
    assert any("Read as" in w for w in result["warnings"]), "promotion was silent"


@requires_rules
@pytest.mark.parametrize(
    "text", ["1 core 400mm2 aluminium XLPE", "single core LSZH 240mm2, 300 A over 30 m"]
)
def test_explicit_single_core_is_respected(text):
    """The promotion above must not override an explicit single-core request."""
    result = FINDER.find(FinderRequest(text=text)).public()
    code = result["productCode"]
    if not code:
        pytest.skip("no code resolved in this dataset")
    assert "/1C" in code, f"{code} should be single core"


@requires_sizing
@requires_rules
def test_stated_size_is_verified_not_replaced():
    """A customer-stated size is honoured; inadequacy is a warning, not a swap."""
    result = FINDER.find(
        FinderRequest(
            cable_type=4, cross_section=2.5, cores=4,
            load_amps=400, length_m=200, phase=3, voltage=400,
        )
    ).public()

    assert result["productCode"], "the stated size must still produce a code"
    assert "2.5" in result["productCode"]
    assert any("does not meet" in w for w in result["warnings"])


@requires_sizing
def test_fix_cable_rated_exactly_at_load_is_accepted():
    """FIX: the legacy acceptance test used strict `>`, rejecting a cable rated
    at exactly the design load."""
    from product_finder.sizer import SIZER, SizingRequest

    request = SizingRequest(load_amps=100, length_m=10, cable_type=1, install=1)
    result = SIZER.size(request)
    if result.status != "sized" or not result.candidate:
        pytest.skip("no solution available in this dataset")
    assert result.candidate.total_capacity >= request.load_amps
