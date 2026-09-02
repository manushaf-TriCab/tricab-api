"""Tests for the ported electrical maths.

These need no database — every function under test is pure.

The `test_fix_*` cases each pin one defect listed in PLAN.md §6 that the legacy
calculator has and this port does not. If one of them fails, the bug has been
reintroduced.
"""

from __future__ import annotations

import math

import pytest

from product_finder import sizing
from product_finder.constants import core_options, is_sdi, suggest_styles


# ── conductor resistance and geometry ────────────────────────────────────────


def test_equivalent_diameter_matches_area():
    for csa in (1.5, 16, 120, 400):
        dia = sizing.equivalent_diameter(csa)
        assert math.isclose(math.pi * (dia / 2) ** 2, csa, rel_tol=1e-9)


def test_dc_res_rises_with_temperature():
    cold = sizing.dc_res(120, 20, 100)
    hot = sizing.dc_res(120, 90, 100)
    assert hot > cold
    # 0.00393/degC referenced to 20 degC over a 70 degC rise.
    assert math.isclose(hot / cold, 1 + 0.00393 * 70, rel_tol=1e-6)


def test_dc_res_scales_inversely_with_area():
    small = sizing.dc_res(50, 90, 100)
    large = sizing.dc_res(100, 90, 100)
    assert small > large


def test_aluminium_has_higher_resistance_than_copper():
    assert sizing.dc_res(120, 90, 100, is_al=True) > sizing.dc_res(120, 90, 100)


def test_fix_dc_res_does_not_truncate_cross_section():
    """FIX: the legacy code passed `intval($xa)` on some paths.

    That sent 2.5 mm2 to key 2 — absent from the table — so it silently took the
    default resistivity, while the volt-drop path used the real 2.5 entry. The
    same cable had two resistances depending on which function you came from.
    """
    resistivity_2p5 = sizing.RESISTIVITY[2.5][0]
    expected = (resistivity_2p5 * (1 + 0.00393 * (90 - 20)) * 100) / 2.5
    assert math.isclose(sizing.dc_res(2.5, 90, 100), expected, rel_tol=1e-9)

    # And it must differ from what truncation to 2 would have produced.
    truncated = (
        sizing.RESISTIVITY_DEFAULT[0] * (1 + 0.00393 * (90 - 20)) * 100
    ) / 2
    assert not math.isclose(sizing.dc_res(2.5, 90, 100), truncated, rel_tol=1e-6)


# ── voltage drop ─────────────────────────────────────────────────────────────


def test_volt_drop_three_phase_uses_root_three():
    single = sizing.volt_drop(90, 120, 200, 100, od=20, phase=4)
    three = sizing.volt_drop(90, 120, 200, 100, od=20, phase=3)
    # Single phase uses factor 2, three phase sqrt(3).
    assert math.isclose(three / single, math.sqrt(3) / 2, rel_tol=0.02)


def test_volt_drop_dc_is_resistive_only():
    """DC ignores reactance, so overall diameter cannot change the answer."""
    thin = sizing.volt_drop(90, 120, 200, 100, od=10, phase=5)
    thick = sizing.volt_drop(90, 120, 200, 100, od=40, phase=5)
    assert thin == thick


def test_volt_drop_grows_with_length():
    short = sizing.volt_drop(90, 120, 200, 50, od=20)
    long = sizing.volt_drop(90, 120, 200, 200, od=20)
    assert long > short * 3


def test_volt_drop_zero_voltage_is_safe():
    assert sizing.volt_drop(90, 120, 200, 100, voltage=0) == 0.0


# ── derating ─────────────────────────────────────────────────────────────────


@pytest.mark.parametrize(
    "runs,expected",
    [(1, 1.0), (2, 0.90), (5, 0.75), (9, 0.68)],
)
def test_buried_conduit_derating_table(runs, expected):
    assert sizing.derating_factor(7, runs, single_core=True) == expected


def test_buried_conduit_formula_is_continuous_with_its_table():
    """Beyond 9 runs the source switches from a table to a power law."""
    at_nine = sizing.derating_factor(7, 9, single_core=True)
    at_ten = sizing.derating_factor(7, 10, single_core=True)
    assert at_ten < at_nine
    assert math.isclose(at_ten, (1.0072 * 10) ** -0.1780, rel_tol=1e-9)
    assert abs(at_ten - at_nine) < 0.02  # no cliff at the join


def test_buried_touching_differs_by_core_count():
    assert sizing.derating_factor(9, 3, single_core=True) == 0.66
    assert sizing.derating_factor(9, 3, single_core=False) == 0.70


def test_buried_touching_flattens_past_the_table():
    assert sizing.derating_factor(9, 20, single_core=True) == 0.43
    assert sizing.derating_factor(9, 20, single_core=False) == 0.44


def test_iec_derates_only_beyond_six_runs():
    assert sizing.derating_factor(8, 6, single_core=True) == 1.0
    assert sizing.derating_factor(8, 7, single_core=True) == 0.85


def test_tray_installs_have_no_multi_run_derating():
    for install in (1, 2, 5, 6):
        assert sizing.derating_factor(install, 8, single_core=True) == 1.0


# ── re-rating ────────────────────────────────────────────────────────────────


def test_rerate_is_neutral_when_ambient_matches():
    assert sizing.rerate(100, table_ambient=40, max_temp=90, actual_ambient=40) == 100


def test_rerate_penalises_a_hotter_ambient():
    assert sizing.rerate(100, table_ambient=40, max_temp=90, actual_ambient=50) < 100


def test_rerate_denominator_depends_on_rating():
    """110 degC cables use 149; everything else 91."""
    at_90 = sizing.rerate(100, 40, 90, 30)
    at_110 = sizing.rerate(100, 40, 110, 30)
    assert at_90 > at_110  # the same 10 degC benefit is worth more at 90


# ── operating temperature ────────────────────────────────────────────────────


def test_op_temp_at_full_load_reaches_the_rating():
    assert sizing.op_temp(ambient=40, load=100, max_load=100, rating=90) == 90


def test_op_temp_at_no_load_is_ambient():
    assert sizing.op_temp(ambient=40, load=0, max_load=100, rating=90) == 40


def test_op_temp_is_quadratic_in_load():
    half = sizing.op_temp(ambient=40, load=50, max_load=100, rating=90)
    assert math.isclose(half, 40 + 50 * 0.25)


# ── earth sizing ─────────────────────────────────────────────────────────────


@pytest.mark.parametrize(
    "active,expected",
    [(6, 2.5), (25, 6), (50, 16), (120, 35), (240, 95), (630, 120)],
)
def test_earth_size_copper_copper(active, expected):
    assert sizing.earth_size(active) == expected


def test_earth_size_varies_by_material_pairing():
    active = 120
    cu_cu = sizing.earth_size(active, is_al_active=False, is_al_earth=False)
    cu_al = sizing.earth_size(active, is_al_active=False, is_al_earth=True)
    al_cu = sizing.earth_size(active, is_al_active=True, is_al_earth=False)
    assert cu_al > cu_cu  # aluminium earth must be larger for the same duty
    assert al_cu < cu_al


def test_aluminium_earth_never_below_25mm2():
    for active in (6, 10, 16, 25, 50):
        assert sizing.earth_size(active, is_al_earth=True) >= 25


def test_fix_earth_size_returns_an_earth_size_on_both_paths():
    """FIX: the legacy `earth()` had two exits returning different quantities.

    The direct path returned a value from the table (an earth CSA); the fallback
    path returned a table *key* (a conductor CSA). Here every path returns an
    earth CSA, so it is always smaller than the active it protects.
    """
    for active in (6, 25, 120, 400, 1000, 2000):
        earth = sizing.earth_size(active)
        assert 0 < earth <= active, f"{active} -> {earth}"


# ── fault rating ─────────────────────────────────────────────────────────────


def test_fault_constant_selects_the_lp_column():
    """Style LP reads the 160 degC column; everything else the 250."""
    assert sizing.fault_constant(90, is_al=False, style="LP") == 99.9
    assert sizing.fault_constant(90, is_al=False, style="XL") == 143


def test_fault_constant_switches_material():
    assert sizing.fault_constant(90, is_al=True, style="XL") == 94.5


def test_fix_fault_constant_returns_none_when_unlisted():
    """FIX: the legacy lookup returned an empty string for an unlisted
    temperature, which silently produced a fault rating of zero. A missing
    k-constant is now reported as missing."""
    assert sizing.fault_constant(97, is_al=False, style="XL") is None
    assert sizing.fault_rating(120, 1, 97, 1.0, "XL") is None


def test_fault_rating_scales_with_area_and_runs():
    one = sizing.fault_rating(120, 1, 90, 1.0, "XL")
    two = sizing.fault_rating(120, 2, 90, 1.0, "XL")
    assert one is not None and two is not None
    assert math.isclose(two, one * 2, rel_tol=1e-6)


def test_fault_rating_falls_with_duration():
    fast = sizing.fault_rating(120, 1, 90, 0.2, "XL")
    slow = sizing.fault_rating(120, 1, 90, 1.0, "XL")
    assert fast is not None and slow is not None
    assert fast > slow


# ── spacing ──────────────────────────────────────────────────────────────────


def test_tray_width_grows_with_runs():
    one = sizing.required_space(20, install=1, runs=1, single_core=False)
    three = sizing.required_space(20, install=1, runs=3, single_core=False)
    assert three["value"] > one["value"]
    assert one["kind"] == "tray"


def test_conduit_selection_picks_a_standard_size():
    result = sizing.required_space(20, install=5, runs=1, single_core=False)
    assert result["kind"] == "conduit"
    assert result["value"] in {size for size, _ in sizing.CONDUIT_SIZES}


def test_fix_space_reports_no_fit_structurally():
    """FIX: the legacy returned the string 'not found' where a number belonged."""
    result = sizing.required_space(500, install=5, runs=4, single_core=True)
    assert result["value"] is None
    assert "no standard conduit" in result["detail"]


# ── style and core-count rules ───────────────────────────────────────────────


def test_fix_three_phase_multicore_can_size_3c_plus_earth():
    """FIX: the legacy hardwired three-phase multi to 4 active cores and then
    queried `cable_specs.nc = 4`, so a 3C+E cable could never be sized."""
    options = core_options(cable_type=4, phase=3)
    assert 3 in options, "3C+E must be searchable"
    assert 4 in options
    assert options[0] == 4, "4C stays the first preference"


def test_fix_fire_pool_excludes_the_vsd_style():
    """FIX: the legacy fire pool was ('FR','FH'). FH is a VSD/VFD style, so a
    plain fire-rated request could return a drive cable."""
    for cable_type in (3, 6):
        styles, _earth, _op = suggest_styles(cable_type, phase=3, operating=90)
        assert "FH" not in styles
        assert styles == ("FR",)


def test_fix_every_type_sets_an_earth_style():
    """FIX: the legacy switch fell through at `case 3:`, leaving Multi FIRE,
    both pump types and VFD with no earth style — Multi FIRE reported no earth
    at all."""
    for cable_type in range(16):
        _styles, earth, _op = suggest_styles(cable_type, phase=3, operating=90)
        assert earth, f"cable type {cable_type} has no earth style"


def test_fix_marine_keeps_its_110c_rating():
    """FIX: the legacy forced marine to 90 degC. The register rates BV/DV/PJ
    at -40 to 110."""
    for cable_type in (10, 11, 12, 13, 14, 15):
        _styles, _earth, operating = suggest_styles(cable_type, phase=3, operating=90)
        assert operating == 110


def test_fix_switchboard_maps_to_ls_at_both_temperatures():
    """FIX: the legacy mapped Switchboard@90 to LP, whose register title is
    'Building Wire'. LS is 'Switchboard/Panel Wire'."""
    for operating in (90, 110):
        styles, _earth, _op = suggest_styles(0, phase=3, operating=operating)
        assert styles == ("LS",)


def test_lszh_is_only_manufactured_at_110():
    for cable_type in (2, 5):
        _styles, _earth, operating = suggest_styles(cable_type, phase=3, operating=90)
        assert operating == 110


def test_iec_single_types_are_single_core():
    assert is_sdi(10) and is_sdi(11) and is_sdi(12)
    assert not is_sdi(13) and not is_sdi(14) and not is_sdi(15)


# ── installation-method reading ──────────────────────────────────────────────


@pytest.mark.parametrize(
    "text,expected",
    [
        ("in the ground in a conduit", 3),
        ("buried in touching conduit", 7),
        ("direct buried", 4),
        ("buried touching", 9),
        ("underground run", 4),
        ("run in conduit in air", 5),
        ("on cable tray", 1),
        ("touching on a ladder", 2),
        ("exposed to the sun", 6),
        ("IEC installation", 8),
        ("no installation stated", None),
    ],
)
def test_install_detection(text, expected):
    from product_finder.nl import detect_install

    assert detect_install(text) == expected


def test_fix_ground_and_conduit_together_reads_as_buried():
    """FIX: installation was a first-match pattern list, so "in the ground in a
    conduit" matched the conduit rule and came out as conduit-in-air. Buried and
    conduit are independent signals and are now read as such — the derating
    difference between the two is large."""
    from product_finder.nl import detect_install

    assert detect_install("in the ground in a conduit") == 3
    assert detect_install("in a conduit") == 5
