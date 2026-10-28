"""Jobs to passes from the process build tree (contract 4.5) and history eligibility (port of the prototype op tests)."""

import pytest

from conductor_process.build import COLS as PROCESS_COLS
from conductor_schedule import config
from conductor_schedule.issues import IssueLog
from conductor_schedule.passes import Tree, build_ops
from cs_support import H_BASIC, ROD, chain_tree, hist, job, kinds, tree


def ops_of(jobs, tr=None, h=None, yields=None):
    issues = IssueLog()
    ops, failed = build_ops(jobs, tr or chain_tree(), hist(**H_BASIC) if h is None else h, issues, yields)
    return ops, failed, issues


def test_chain_is_one_op_with_four_passes_root_first():
    ops, failed, _ = ops_of([job(1)])
    assert failed == {} and len(ops) == 1
    o = ops[0]
    assert [p.item for p in o.passes] == ["C", "B", "W", "F"]
    assert [p.depth for p in o.passes] == [0, 1, 2, 3]
    assert o.passes[1].parent is o.passes[0] and o.passes[0].parent is None
    assert o.metres == 1000.0 and o.op_id == 1 and o.required_by == "2026-11-02"


def test_rod_and_out_kg_and_implied_yield():
    o = ops_of([job(1)])[0][0]
    assert o.rod_kg == pytest.approx(18.0)
    assert o.out_kg == pytest.approx(1000.0 * 0.03)
    assert o.out_kg / o.rod_kg == pytest.approx(30.0 / 18.0)


def test_no_weight_gives_no_out_kg_and_no_rod_edge_gives_no_rod_kg():
    t = tree({"C": "25_FCON"}, {"C": [("OTHER", 1.0, "kg")]})
    o = ops_of([job(1)], t, hist(item_jobs={"C": {("BU1", "BUNCHING"): 1}}))[0][0]
    assert o.out_kg is None and o.rod_kg is None


def test_quantities_follow_qty_per_unit_down_the_tree():
    t = tree({"C": "25_FCON", "B": "20_BUN"}, {"C": [("B", 7.5)], "B": [(ROD, 0.002)]})
    o = ops_of([job(1, metres=100)], t,
               hist(item_jobs={"C": {("BU1", "BUNCHING"): 1}, "B": {("BU2", "BUNCHING"): 1}}))[0][0]
    assert [p.quantity for p in o.passes] == [100.0, 750.0]
    assert o.rod_kg == pytest.approx(1.5)


def test_one_pass_per_edge_occurrence_and_a_cycle_is_cut():
    t = tree({"C": "25_FCON", "W": "15_WIR", "X": "15_WIR"},
             {"C": [("W", 1), ("X", 2)], "W": [("X", 1), ("C", 1)], "X": [(ROD, 0.01)]})
    h = hist(item_jobs={i: {("WD4", "WIRE_DRAW"): 1} for i in "CWX"})
    o = ops_of([job(1, metres=10)], t, h)[0][0]
    assert [(p.item, p.quantity) for p in o.passes] == [("C", 10), ("W", 10), ("X", 10), ("X", 20)]   # C under W is cut
    assert o.rod_kg == pytest.approx(0.1 + 0.2)


def test_non_stage_inputs_are_not_passes():
    t = tree({"C": "25_FCON", "T": "30_TAPE"}, {"C": [("T", 1)]})
    o = ops_of([job(1)], t, hist(item_jobs={"C": {("BU1", "BUNCHING"): 1}}))[0][0]
    assert [p.item for p in o.passes] == ["C"]


def test_yield_grows_every_input_below_the_step():
    # bunching at 80%: the bunch B stays 1000 m, everything below it needs 1250 m, and the rod grows with it
    base = ops_of([job(1)])[0][0]
    grown = ops_of([job(1)], yields={"bunching": 0.8})[0][0]
    q = {p.item: p.quantity for p in grown.passes}
    assert q == {"C": 1000.0, "B": 1000.0, "W": pytest.approx(1250.0), "F": pytest.approx(1250.0)}
    assert grown.rod_kg == pytest.approx(base.rod_kg * 1.25)


def test_conductor_yield_grows_the_bunch_and_below():
    q = {p.item: p.quantity for p in ops_of([job(1)], yields={"conductor": 0.5})[0][0].passes}
    assert q == {"C": 1000.0, "B": 2000.0, "W": 2000.0, "F": 2000.0}


def test_default_yield_changes_nothing():
    a = ops_of([job(1)])[0][0]
    b = ops_of([job(1)], yields=dict.fromkeys(config.YIELD_STEPS, 1.0))[0][0]
    assert [p.quantity for p in a.passes] == [p.quantity for p in b.passes] and a.rod_kg == b.rod_kg


def test_steps_for_yield():
    assert config.step_of("NX-1W1.6", "15_WIR") == "rod_break" and config.step_of("DX-1W1.6", "15_WIR") == "tinning"
    assert config.step_of("NX-8W.3", "15_WIR") == "wire_draw" and config.step_of("NX-64W.3", "20_BUN") == "bunching"
    assert config.step_of("NX-1C240", "25_FCON") == "conductor" and config.step_of(ROD, "00_RM") is None


def test_wire_1_6_yield_steps_use_the_item_not_the_pool_alone():
    t = tree({"DX-1C1.5": "25_FCON", "DX-1W1.6": "15_WIR", "NX-1W1.6": "15_WIR"},
             {"DX-1C1.5": [("DX-1W1.6", 1)], "DX-1W1.6": [("NX-1W1.6", 1)], "NX-1W1.6": [(ROD, 0.01)]})
    h = hist(item_jobs={"DX-1C1.5": {("BU4", "BUNCHING"): 1}, "DX-1W1.6": {("TL1", "TINNING"): 1},
                        "NX-1W1.6": {("RB1", "ROD_BREAK"): 1}})
    o = ops_of([job(1, "DX-1C1.5", 100)], t, h, {"tinning": 0.5, "rod_break": 0.8})[0][0]
    # the tinned wire is made by tinning (50%), so its input NX-1W1.6 is doubled; NX-1W1.6 by rod break (80%)
    assert {p.item: p.quantity for p in o.passes} == {"DX-1C1.5": 100, "DX-1W1.6": 100, "NX-1W1.6": 200}
    assert o.rod_kg == pytest.approx(200 * 0.01 / 0.8)


def test_kg_input_is_converted_with_kg_per_m_else_used_as_is():
    t = tree({"C": "25_FCON", "B": "20_BUN", "U": "20_BUN"}, {"C": [("B", 0.5, "kg"), ("U", 2, "kg")]},
             kg_per_m={"B": 0.05})
    h = hist(item_jobs={i: {("BU1", "BUNCHING"): 1} for i in "CBU"})
    ops, _, issues = ops_of([job(1, metres=100)], t, h)
    p = {x.item: x for x in ops[0].passes}
    assert (p["B"].quantity, p["B"].unit) == (pytest.approx(1000.0), "m")          # 0.5 kg per m / 0.05 kg per m x 100 m
    assert (p["U"].quantity, p["U"].unit) == (200.0, "kg")
    assert ("B", "qty_converted") in kinds(issues) and ("U", "qty_unit") in kinds(issues)


def test_item_without_history_uses_pool_and_logs():
    h = hist(item_jobs={}, rates=H_BASIC["rates"], pool_jobs={"25_FCON": {("BU1", "BUNCHING"): 10}})
    t = tree({"X": "25_FCON"}, {"X": [(ROD, 0.01)]})
    ops, _, issues = ops_of([job(1, "X", 100)], t, h)
    assert ops[0].passes[0].eligible == [("BU1", "BUNCHING")]
    assert ("X", "pool_fallback") in kinds(issues)


def test_no_history_anywhere_drops_the_job_as_no_machine():
    t = tree({"X": "25_FCON"}, {"X": [(ROD, 0.01)]})
    ops, failed, issues = ops_of([job(1, "X", 100), job(2, "X", 50)], t, hist())
    assert ops == [] and failed == {1: "no_machine", 2: "no_machine"} and ("X", "no_machine") in kinds(issues)


def test_a_child_without_machine_drops_the_whole_job_only():
    t = tree({"C": "25_FCON", "Z": "15_WIR", "D": "25_FCON"}, {"C": [("Z", 1)], "D": [(ROD, 0.01)]})
    h = hist(item_jobs={"C": {("BU1", "BUNCHING"): 1}, "D": {("BU1", "BUNCHING"): 1}})
    ops, failed, _ = ops_of([job(1, "C"), job(2, "D")], t, h)
    assert [o.item for o in ops] == ["D"] and failed == {1: "no_machine"}


def test_ops_follow_job_order():
    ops, _, _ = ops_of([job(3, due="2026-12-01"), job(1, due="2023-01-01")])
    assert [o.op_id for o in ops] == [1, 3] and [o.required_by for o in ops] == ["2023-01-01", "2026-12-01"]


def test_eligible_needs_ten_percent_of_jobs():
    h = hist(item_jobs={"I": {("BU1", "BUNCHING"): 89, ("BU2", "BUNCHING"): 10, ("BU4", "BUNCHING"): 1}})
    assert [m for m, _ in h.eligible("I", "25_FCON")[0]] == ["BU1", "BU2"]       # 10/100 qualifies, 1/100 does not


def test_rate_borrows_costing_resource():
    h = hist(rates={("WIRE_DRAW", "WD4"): (60, 1000)}, alias={("WIRE_DRAW", "WD3"): "WD4"})
    r, how = h.rate("WIRE_DRAW", "WD3")
    assert r.per_hour == 1000 and how == "costing:WD4"
    assert h.rate("NOPE", "WD3") == (None, "none")


def test_operation_median_when_nothing_else():
    h = hist(rates={("WIRE_DRAW", "WD4"): (60, 1000), ("WIRE_DRAW", "WD5"): (60, 3000), ("WIRE_DRAW", "WD6"): (60, 2000)})
    r, how = h.rate("WIRE_DRAW", "WD3")
    assert r.per_hour == 2000 and how == "operation_median"


def _row(table, **kw):
    r = [None] * len(PROCESS_COLS[table])
    for k, v in kw.items():
        r[PROCESS_COLS[table].index(k)] = v
    return tuple(r)


def test_tree_from_process_tables():
    tables = {"cd_item": (PROCESS_COLS["cd_item"], [_row("cd_item", item="C", pool="25_FCON", traces_to_rod=1)]),
              "cd_input": (PROCESS_COLS["cd_input"], [_row("cd_input", item="C", input_item=ROD, qty_per_unit=0.5,
                                                           unit="kg", kind="material")]),
              "cd_material": (PROCESS_COLS["cd_material"], [_row("cd_material", item=ROD, is_rod=1),
                                                            _row("cd_material", item="TAPE", is_rod=0)])}
    t = Tree.from_tables(tables, {"C": 0.1})
    assert t.pool == {"C": "25_FCON"} and t.rods == {ROD} and t.traces == {"C": 1}
    assert t.inputs["C"] == [(ROD, 0.5, "kg", "material")] and t.kg_per_m == {"C": 0.1}


def test_built_fixture_passes_follow_the_process_tree(built):
    _, con = built
    op = con.execute("SELECT job_id FROM cs_job WHERE item = 'NX-1C16' ORDER BY job_id").fetchone()[0]
    first = con.execute("SELECT * FROM cs_pass WHERE op_id = ? ORDER BY depth", (op,)).fetchall()
    assert [p["item"] for p in first][:3] == ["NX-1C16", "NX-32W.3", "NX-16W.3"]
    assert [p["depth"] for p in first][:3] == [0, 1, 2] and first[0]["parent_pass_id"] is None
    assert first[0]["machine"] in ("BU1", "BU2") and first[0]["unit"] == "m"
    # 250 m of NX-1C16 needs 7.02611 m of NX-32W.3 per metre (parallel ends are not multiplied)
    assert first[1]["quantity"] == pytest.approx(250 * 7.02611)


def test_off_map_and_untraced_items_get_no_job(built):
    _, con = built
    jobs = {r[0] for r in con.execute("SELECT item FROM cs_job")}
    assert "NM-1C16" not in jobs and "NM-1C95" not in jobs
    assert con.execute("SELECT scheduled FROM cs_restock WHERE item = 'NM-1C16'").fetchone()[0] == 0
