"""Order selection, quantities, cycles and trace status on small synthetic extracts."""

import pytest

from cable_routes import explode as ex_mod


def status(con, item):
    return con.execute("SELECT trace_status, reason, reason_items FROM cr_finished WHERE item = ?", (item,)).fetchone()


def test_cutting_order_is_never_representative(extract, store):
    e = extract
    e.order("F-A", "50_FMAN", [("SHEATHING", "EX4")], [("X-1", 1)], date="2025-01-01", no="REAL")
    e.order("F-A", "50_FMAN", [("CUT", "CT1")], [("F-B", 1)], date="2026-06-01", cutting=True, no="CUTTING")
    con = store(e)
    assert con.execute("SELECT order_no, real_orders FROM cr_item WHERE item = 'F-A'").fetchone()[:] == ("REAL", 1)
    assert [r[0] for r in con.execute("SELECT machine FROM cr_operation WHERE item = 'F-A'")] == ["EX4"]


def test_only_cutting_or_self_consuming_orders_means_cutting_only(extract, store):
    e = extract
    e.order("F-CUT", "50_FMAN", bom=[("A", 1)], cutting=True)
    e.order("F-SELF", "50_FMAN", bom=[("F-SELF", 1)])
    e.order("F-NOBOM", "60_CAT")
    e.pools["F-NONE"] = "50_FMAN"
    con = store(e)
    for item in ("F-CUT", "F-SELF", "F-NOBOM"):
        assert tuple(status(con, item))[:2] == ("untraceable", "cutting_only"), item
    assert tuple(status(con, "F-NONE"))[:2] == ("untraceable", "no_orders")
    assert con.execute("SELECT count(*) FROM cr_item").fetchone()[0] == 0


def test_representative_order_prefers_live_then_newest_then_number(extract, store):
    e = extract
    e.order("F-A", "50_FMAN", [("OP", "M1")], [("X", 1)], status="Cancelled", date="2026-09-01", no="A1")
    e.order("F-A", "50_FMAN", [("OP", "M2")], [("X", 1)], status="Completed", date="2025-01-01", no="A2")
    e.order("F-A", "50_FMAN", [("OP", "M3")], [("X", 1)], status="Released", date="2025-01-01", no="A3")
    e.order("F-A", "50_FMAN", [("OP", "M4")], [("X", 1)], status="Released", date="2024-01-01", no="A4")
    con = store(e)
    row = con.execute("SELECT order_no, order_status, order_date, real_orders, live_orders FROM cr_item").fetchone()
    assert tuple(row) == ("A3", "Released", "2025-01-01", 4, 3)
    alt = {(r["machine"], r["orders"]) for r in con.execute("SELECT * FROM cr_alt_machine")}
    assert alt == {("M2", 1), ("M3", 1), ("M4", 1)}  # the cancelled order's machine is not an alternative


@pytest.mark.parametrize("denominator,expected", [("", 10.0), ("0", 10.0), ("abc", 10.0), ("4", 2.5), ("1000", 0.01)])
def test_denominator_zero_blank_or_junk_counts_as_one(extract, store, denominator, expected):
    e = extract
    e.order("F-A", "50_FMAN", [("OP", "EX4")], [("MAT", 10, "kg", denominator)])
    con = store(e)
    assert con.execute("SELECT qty_per_unit FROM cr_input").fetchone()[0] == pytest.approx(expected)


def test_self_consumption_is_dropped_and_parallel_defaults_to_one(extract, store):
    e = extract
    e.order("F-A", "50_FMAN", [("OP", "EX4")], [("F-A", 5), ("MAT", 2, "kg", "", 0)])
    con = store(e)
    rows = con.execute("SELECT input_item, parallel_ends FROM cr_input").fetchall()
    assert [tuple(r) for r in rows] == [("MAT", 1)]


def test_operations_sort_numerically_not_as_text(extract, store):
    e = extract
    no = e.order("F-A", "50_FMAN", [("A", "M1"), ("B", "M2")], [("X", 1)])
    for r in e.route:
        if r["OperationId"] == "A":
            r["OperationNumber"] = "100"  # text order would put 100 before 20
    con = store(e)
    assert [r[0] for r in con.execute("SELECT operation FROM cr_operation ORDER BY seq")] == ["B", "A"]
    assert no


def test_cycle_terminates_and_is_counted(extract, store):
    e = extract
    e.order("F-A", "50_FMAN", [("OP", "EX4")], [("S-B", 2)])
    e.order("S-B", "40_SFG", [("OP", "EX3")], [("S-C", 3), ("MAT", 1, "kg")])
    e.order("S-C", "40_SFG", [("OP", "LU3")], [("S-B", 5), ("F-A", 7), ("MAT2", 1, "kg")])  # back to B and to the root
    con = store(e)
    assert con.execute("SELECT cycle_edges_cut FROM cr_run").fetchone()[0] == 2
    needs = {r["item"]: r["need_per_unit"] for r in con.execute("SELECT * FROM cr_need WHERE finished = 'F-A'")}
    assert needs["S-B"] == pytest.approx(2) and needs["S-C"] == pytest.approx(6)
    assert needs["F-A"] == 1.0
    assert con.execute("SELECT max_depth FROM cr_finished WHERE item = 'F-A'").fetchone()[0] == 2


def test_explode_function_cuts_backward_edges_only():
    graph = {"R": [("A", 2, "m"), ("B", 1, "m")], "A": [("B", 3, "m")], "B": [("A", 4, "m"), ("M", 1, "kg")]}
    tree = ex_mod.explode("R", graph, {"R": "m", "A": "m", "B": "m"}, "m")
    # DFS goes R, A, B; B's edge back to A is the only backward edge
    assert tree.cut == {("B", "A")}
    assert tree.need["A"]["m"] == 2
    assert tree.need["B"]["m"] == pytest.approx(1 + 2 * 3)  # a diamond: R->B directly and R->A->B
    assert tree.need["M"]["kg"] == pytest.approx(7)
    assert tree.depth == {"R": 0, "A": 1, "B": 2, "M": 3}


def test_needs_in_different_units_are_never_added_or_converted(extract, store):
    e = extract
    e.order("F-A", "50_FMAN", [("OP", "EX4")], [("S-B", 1, "m"), ("S-B", 0.5, "kg"), ("MAT", 1, "kg")])
    e.order("S-B", "40_SFG", [("OP", "EX3")], [("MAT", 10, "kg")])
    e.product("S-B", unit="m")
    con = store(e)
    rows = {(r["item"], r["unit"]): r["need_per_unit"] for r in con.execute("SELECT * FROM cr_need WHERE finished = 'F-A'")}
    assert rows[("S-B", "m")] == 1 and rows[("S-B", "kg")] == 0.5
    # the kg need of S-B is not an output need, so it does not feed S-B's own inputs: MAT = 1 + 10 * 1 (m only)
    assert rows[("MAT", "kg")] == pytest.approx(11)


@pytest.mark.parametrize("case,expected_status,reason", [
    ("rod", "to_rod", None),
    ("conductor", "to_purchased_conductor", "purchased_conductor"),
    ("early", "stops_early", "no_rod_in_chain"),
])
def test_trace_status(extract, store, case, expected_status, reason):
    e = extract
    leaf = {"rod": [("ROD_BREAK", "RB1")], "conductor": [("BUNCHING", "BU1")], "early": [("BUNCHING", "BU1")]}[case]
    material = {"rod": "NX-1R8", "conductor": "NX-1R8-NEX", "early": "ZZ-FILLER"}[case]
    e.order("F-A", "50_FMAN", [("SHEATHING", "EX4")], [("S-W", 1)])
    e.order("S-W", "15_WIR", leaf, [(material, 0.1, "kg")])
    con = store(e)
    row = status(con, "F-A")
    assert (row["trace_status"], row["reason"]) == (expected_status, reason)
    if reason:
        assert row["reason_items"] == material
    else:
        assert row["reason_items"] is None


def test_reason_items_are_capped_at_five_and_sorted(extract, store):
    e = extract
    e.order("F-A", "50_FMAN", [("SHEATHING", "EX4")], [(f"NX-{n}", 1, "kg") for n in range(8, 0, -1)] + [("DX-9", 1, "kg")])
    con = store(e)
    row = status(con, "F-A")
    assert row["trace_status"] == "to_purchased_conductor"
    assert row["reason_items"] == "DX-9,NX-1,NX-2,NX-3,NX-4"


def test_rod_beats_purchased_conductor(extract, store):
    e = extract
    e.order("F-A", "50_FMAN", [("SHEATHING", "EX4")], [("S-W", 1), ("N1-300", 1, "kg")])
    e.order("S-W", "15_WIR", [("ROD_BREAK", "RB1")], [("NX-1R8", 0.1, "kg")])
    assert status(store(e), "F-A")["trace_status"] == "to_rod"


def test_non_cable_pool_item_is_a_material_not_expanded(extract, store):
    e = extract
    e.order("F-A", "50_FMAN", [("SHEATHING", "EX4")], [("COMPOUND", 0.2, "kg")])
    e.order("COMPOUND", "10_CBC", [("ROD_BREAK", "RB1")], [("RAW", 1, "kg")])
    con = store(e)
    assert status(con, "F-A")["trace_status"] == "stops_early"
    assert con.execute("SELECT kind FROM cr_input WHERE input_item = 'COMPOUND'").fetchone()[0] == "material"
    assert con.execute("SELECT count(*) FROM cr_item WHERE item = 'COMPOUND'").fetchone()[0] == 0


def test_made_items_nothing_finished_uses_are_not_stored(extract, store):
    e = extract
    e.order("F-A", "50_FMAN", [("SHEATHING", "EX4")], [("S-B", 1)])
    e.order("S-B", "40_SFG", [("OP", "EX3")], [("M", 1, "kg")])
    e.order("S-ORPHAN", "40_SFG", [("OP", "EX3")], [("M", 1, "kg")])
    con = store(e)
    assert {r[0] for r in con.execute("SELECT item FROM cr_item")} == {"F-A", "S-B"}


def test_run_record_counts(extract, store):
    e = extract
    e.order("KA9X-PASN/5C16BK", "50_FMAN", [("SHEATHING", "EX4")], [("S-W", 1)], date="2026-09-04")
    e.order("S-W", "15_WIR", [("ROD_BREAK", "RB1")], [("NX-1R8", 0.1, "kg")], date="1900-01-01")
    e.order("TE-OTHER", "60_CAT", [("OP", "EX4")], [("S-W", 1)], date="2026-08-01")
    e.order("ZZ-NOORDER", "60_CAT", cutting=True, bom=[("A", 1)])
    con = store(e)
    run = con.execute("SELECT * FROM cr_run").fetchone()
    assert (run["finished_total"], run["finished_traced"], run["made_items"]) == (3, 2, 3)
    assert (run["trace_to_rod"], run["untraceable_cutting_only"], run["parsed_fit"], run["parsed_other"]) == (2, 1, 1, 2)
    assert (run["data_as_of"], run["source_name"], run["schema_version"]) == ("2026-09-04", "dynamics", 1)
    assert con.execute("PRAGMA user_version").fetchone()[0] == 1
