"""Contract section 4 rules, one at a time, on the fixture and on small extracts."""

import pytest

from conductor_process.model import material_of, parse_form, prefix_of, window


def row(con, sql, *args):
    return con.execute(sql, args).fetchone()


@pytest.mark.parametrize("item,expected", [
    ("NX-1C16", ("core", 1, 16.0)), ("NX-32W.3", ("wires", 32, 0.3)), ("NX-1W1.6", ("wires", 1, 1.6)),
    ("DM-1C1.5", ("core", 1, 1.5)), ("NX-1C500-AA", ("core", 1, 500.0)), ("NX-112W.3", ("wires", 112, 0.3)),
    ("VX-SPECIAL", ("other", None, None)), ("NX-1R8-NEX", ("other", None, None)), ("NX1C16", ("other", None, None)),
])
def test_form_and_size_parse(item, expected):
    assert parse_form(item) == expected


def test_prefix_and_material():
    assert prefix_of("nx-1c16") == "NX" and prefix_of("MDXI") == "MDXI"
    assert [material_of(p) for p in ("NX", "MNM", "DXI", "MDM", "VX", "VDX")] == \
        ["plain", "plain", "tinned", "tinned", "unknown", "unknown"]


def test_window_is_364_days():
    assert window("2026-10-28") == "2025-10-29" and window("") == ""


def test_copper_filter(fx):
    items = {r[0] for r in fx.execute("SELECT item FROM cd_item")}
    assert {"AL-1C16", "NX-1C16-INS", "NX-1C10", "NX-1C500-AA"}.isdisjoint(items)  # prefix, pool, cutting, off map
    assert "VX-SPECIAL" in items
    vx = row(fx, "SELECT stage, material, mica, form, ends, size FROM cd_item WHERE item = 'VX-SPECIAL'")
    assert tuple(vx) == ("wire", "unknown", 0, "other", None, None)
    assert tuple(row(fx, "SELECT prefix, form, ends, size FROM cd_item WHERE item = 'NX-32W.3'")) == \
        ("NX", "wires", 32, 0.3)


def test_pool_falls_back_to_the_order(extract, store):
    extract.order("NX-1C4", None, [("BUNCHING", "BU2")], [("NX-7W.3", 6)], order_pool="25_FCON")
    extract.order("NX-1C6", None, [("BUNCHING", "BU2")], [("NX-7W.3", 6)], order_pool="40_SFG")
    con = store(extract)
    assert [tuple(r) for r in con.execute("SELECT item, pool, stage FROM cd_item")] == [("NX-1C4", "25_FCON", "conductor")]


def test_off_map_latest_order_falls_back_to_an_on_map_one(fx):
    it = row(fx, "SELECT order_no, real_orders, live_orders FROM cd_item WHERE item = 'NX-1C500'")
    assert tuple(it) == ("C500-ST1-1", 3, 3)
    assert [tuple(r) for r in fx.execute("SELECT seq, operation_number, operation, machine FROM cd_operation "
                                         "WHERE item = 'NX-1C500' ORDER BY seq")] == \
        [(1, 10, "ROPESTRAND", "ST1"), (2, 20, "ROPESTRND2", "ST1")]


def test_lu3_only_item_is_excluded_and_becomes_a_material(fx):
    assert row(fx, "SELECT excluded_off_map FROM cd_run")[0] == 1
    assert tuple(row(fx, "SELECT kind FROM cd_input WHERE item = 'NM-1C500-AA' AND input_item = 'NX-1C500-AA'")) == \
        ("material",)
    assert row(fx, "SELECT is_rod FROM cd_material WHERE item = 'NX-1C500-AA'")[0] == 0
    nm = row(fx, "SELECT traces_to_rod, step_count FROM cd_item WHERE item = 'NM-1C500-AA'")
    assert tuple(nm) == (0, 1)


def test_representative_prefers_live_then_newest_then_number(extract, store):
    e = extract
    e.order("NX-1C4", "25_FCON", [("BUNCHING", "BU1")], [("X", 1)], status="Cancelled", date="2026-09-01", no="A1")
    e.order("NX-1C4", "25_FCON", [("BUNCHING", "BU2")], [("X", 1)], date="2025-01-01", no="A2")
    e.order("NX-1C4", "25_FCON", [("BUNCHING", "BU4")], [("X", 1)], date="2025-01-01", no="A3")
    e.order("NX-1C4", "25_FCON", [("BUNCHING", "LU3")], [("X", 1)], date="2026-01-01", no="A4")
    con = store(e)
    assert tuple(row(con, "SELECT order_no, real_orders, live_orders FROM cd_item")) == ("A3", 4, 3)


def test_alternative_machines_are_map_only_and_live_only(fx):
    alt = lambda i: {(r[0], r[1]): r[2] for r in fx.execute(  # noqa: E731
        "SELECT operation, machine, orders FROM cd_alt_machine WHERE item = ?", (i,))}
    assert alt("NX-1C16") == {("BUNCHING", "BU1"): 5, ("BUNCHING", "BU2"): 1}
    assert alt("NX-32W.3") == {("BUNCHING", "BU6"): 2}  # the cancelled BU7 order is not an alternative
    assert alt("NX-1C500") == {("ROPESTRAND", "ST1"): 2, ("ROPESTRND2", "ST1"): 2}  # LU3 is not on the map


def test_52_week_window_and_median_run_size(fx):
    c16 = row(fx, "SELECT orders_52w, run_size FROM cd_item WHERE item = 'NX-1C16'")
    # In: 2026-10-01, 2025-10-21 (first day), 2026-05-01, 2026-06-01 (0 received), BU2 2025-12-02. Out: 2025-10-20.
    assert tuple(c16) == (5, 250.0)  # median of 100, 400, 200, 300 = mean of the middle two
    assert tuple(row(fx, "SELECT orders_52w, run_size FROM cd_item WHERE item = 'NX-32W.3'")) == (2, 15.0)
    assert tuple(row(fx, "SELECT orders_52w, run_size FROM cd_item WHERE item = 'NX-1W1.6'")) == (0, None)


def test_odd_median(extract, store):
    for q in (5, 1, 3):
        extract.order("NX-1C4", "25_FCON", [("BUNCHING", "BU2")], [("X", 1)], date="2026-01-01", received=q,
                      finished="2025-12-30")
    extract.order("NX-1C4", "25_FCON", [("BUNCHING", "BU2")], [("X", 1)], date="2026-01-01", received=50,
                  finished="2026-01-02")  # finished after data_as_of: outside the window
    assert row(store(extract), "SELECT run_size FROM cd_item")[0] == 3.0


def test_materials_and_is_rod(fx):
    mats = {r["item"]: (r["name"], r["unit"], r["is_rod"]) for r in fx.execute("SELECT * FROM cd_material")}
    assert set(mats) == {"NX-1R8-NEX", "TIN-ANODE", "TAPE_MICA-8MM", "TAPE_MICA-30MM", "NX-1C500-AA"}
    assert mats["NX-1R8-NEX"] == ("Rod PCu 8mm Rod PCu 8mm 8mm plain annealed coppe", "kg", 1)
    assert mats["TIN-ANODE"] == (None, None, 0)
    assert sum(v[2] for v in mats.values()) == 1


def test_machine_stats_include_cancelled_and_all_copper_items(fx):
    m = {r["machine"]: (r["items"], r["orders_total"], r["orders_cancelled"], r["orders_52w"])
         for r in fx.execute("SELECT * FROM cd_machine")}
    assert m["BU7"] == (0, 1, 1, 0)
    assert m["BU1"] == (1, 5, 0, 4)  # AL-1C16 is not copper
    assert m["BU2"] == (0, 1, 0, 1)
    assert m["BU6"] == (1, 2, 0, 2)
    assert m["ST1"] == (2, 3, 0, 0)
    assert m["WD4"] == (2, 2, 0, 0)
    assert m["RB2"][:2] == (1, 1) and m["TN1"][:2] == (1, 1)
    assert m["WD3"] == m["BU5"] == m["TP3"] == (0, 0, 0, 0)
    ops = {(r[0], r[1]): r[2] for r in fx.execute("SELECT * FROM cd_machine_operation")}
    assert ops[("ST1", "ROPESTRAND")] == 2 and ops[("ST1", "TAPING")] == 1 and ops[("BU1", "BUNCHING")] == 5
    assert not any(k[0] == "BU7" for k in ops)  # cancelled orders are not counted here


def test_excluded_items_still_count_in_machine_stats(extract, store):
    extract.order("NX-1C500-AA", "25_FCON", [("LAYUP", "LU3"), ("TAPING", "TP1")], [("X", 1)])
    con = store(extract)
    assert row(con, "SELECT orders_total, items FROM cd_machine WHERE machine = 'TP1'")[:] == (1, 0)
    assert row(con, "SELECT count(*) FROM cd_item")[0] == 0


def test_machine_spec_from_the_poster_and_notes_from_the_seed(fx):
    rb2 = row(fx, "SELECT * FROM cd_machine WHERE machine = 'RB2'")
    assert (rb2["d365_id"], rb2["stage_no"], rb2["stage"], rb2["brand"], rb2["note"]) == \
        ("RB1", 1, "Rod breaking", "NIEHOFF", "D365 calls it RB1")
    tp3 = row(fx, "SELECT * FROM cd_machine WHERE machine = 'TP3'")
    assert (tp3["d365_id"], tp3["stage"], tp3["note"], tp3["brand"]) == \
        (None, "Stranding & mica taping", "not in D365", "KABMAK")
    assert row(fx, "SELECT note FROM cd_machine WHERE machine = 'BU1'")[0] is None
    notes = {r[0]: r[1] for r in fx.execute("SELECT machine, note FROM cd_machine WHERE note IS NOT NULL")}
    assert notes == {"RB2": "D365 calls it RB1", "TN1": "running · D365 TL1", "WD5": "running in D365",
                     "WD3": "no orders in D365", "BU7": "no orders in D365", "TP3": "not in D365"}


def test_repeated_line_number_moves_to_the_next_free_number(extract, store):
    no = extract.order("NX-1C4", "25_FCON", [("BUNCHING", "BU2")], [("NX-7W.3", 6), ("NX-7W.4", 1)])
    extract.bom[-1]["LineNumber"] = "1"
    extract.bom.append({"ProductionOrderNumber": no, "LineNumber": "3", "ItemNumber": "NX-1C4",
                        "BOMLineQuantity": "1", "BOMLineQuantityDenominator": "", "BOMLineUnitSymbol": "m",
                        "CsPrdParallelLines": ""})  # self-consumption is dropped
    con = store(extract)
    assert [tuple(r) for r in con.execute("SELECT line, input_item FROM cd_input ORDER BY line")] == \
        [(1, "NX-7W.3"), (2, "NX-7W.4")]


def test_operations_with_no_resource_are_not_steps(extract, store):
    extract.order("NX-1C4", "25_FCON", [("BUNCHING", "BU2"), ("QC", "")], [("X", 1)])
    con = store(extract)
    assert [tuple(r) for r in con.execute("SELECT seq, operation, machine FROM cd_operation")] == [(1, "BUNCHING", "BU2")]


def test_bom_cycle_is_cut(extract, store):
    extract.order("NX-1C4", "25_FCON", [("BUNCHING", "BU2")], [("NX-7W.3", 6)])
    extract.order("NX-7W.3", "15_WIR", [("WIRE_DRAW", "WD4")], [("NX-1C4", 0.1)])
    con = store(extract)
    assert row(con, "SELECT count(*) FROM cd_need WHERE root = 'NX-1C4'")[0] == 2
    assert row(con, "SELECT step_count FROM cd_item WHERE item = 'NX-1C4'")[0] == 2
