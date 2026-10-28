"""Contract 3.2 invariants and the headline numbers on the section 4 fixture."""

import pytest

MAP = ["RB2", "WD3", "WD4", "TN1", "WD5", "BU1", "BU2", "BU6", "BU7", "BU5", "BU4", "TP3", "TP1", "TP2", "ST1"]


def one(con, sql, *args):
    return con.execute(sql, args).fetchone()[0]


def assert_invariants(con):
    """Contract 3.2; shared with the real-data test."""
    assert [r[0] for r in con.execute("SELECT machine FROM cd_machine ORDER BY seq")] == MAP
    assert [r[0] for r in con.execute("SELECT seq FROM cd_machine ORDER BY seq")] == list(range(1, 16))
    for t in ("cd_operation", "cd_alt_machine"):
        assert one(con, f"SELECT count(*) FROM {t} WHERE machine NOT IN (SELECT machine FROM cd_machine)") == 0
    assert one(con, "SELECT count(*) FROM (SELECT item FROM cd_operation GROUP BY item "
                    "HAVING min(seq) <> 1 OR max(seq) <> count(*))") == 0
    assert one(con, "SELECT count(*) FROM cd_input WHERE kind = 'made' AND input_item NOT IN "
                    "(SELECT item FROM cd_item)") == 0
    assert one(con, "SELECT count(*) FROM cd_input WHERE kind = 'material' AND input_item NOT IN "
                    "(SELECT item FROM cd_material)") == 0
    assert one(con, "SELECT count(*) FROM cd_input WHERE kind = 'material' AND input_item IN "
                    "(SELECT item FROM cd_item)") == 0
    assert one(con, "SELECT count(*) FROM cd_need WHERE kind = 'root'") == one(con, "SELECT count(*) FROM cd_item")
    assert one(con, "SELECT count(*) FROM cd_item i JOIN cd_need n ON n.root = i.item AND n.kind = 'root' "
                    "WHERE n.item = i.item AND n.need_per_unit = 1 AND n.depth = 0 AND n.unit = i.unit") \
        == one(con, "SELECT count(*) FROM cd_item")
    assert one(con, "SELECT count(*) FROM cd_need WHERE kind = 'made' AND item NOT IN (SELECT item FROM cd_item)") == 0
    assert one(con, "SELECT count(*) FROM cd_need WHERE kind = 'material' AND item NOT IN "
                    "(SELECT item FROM cd_material)") == 0
    run = con.execute("SELECT * FROM cd_run").fetchone()
    assert run["items"] == one(con, "SELECT count(*) FROM cd_item")
    assert run["finished_items"] == one(con, "SELECT count(*) FROM cd_item WHERE stage IN ('conductor', 'mica_taped')")
    assert run["traced_to_rod"] == one(con, "SELECT count(*) FROM cd_item WHERE traces_to_rod = 1")
    assert run["schema_version"] == 1 and one(con, "PRAGMA user_version") == 1
    assert con.execute("PRAGMA foreign_key_check").fetchall() == []


def test_invariants(fx):
    assert_invariants(fx)


def test_run_record(fx):
    run = fx.execute("SELECT * FROM cd_run").fetchone()
    assert (run["data_as_of"], run["window_from"], run["source_name"]) == ("2026-10-20", "2025-10-21", "dynamics")
    assert (run["items"], run["finished_items"], run["traced_to_rod"], run["excluded_off_map"]) == (11, 5, 10, 1)
    assert dict(fx.execute("SELECT stage, count(*) FROM cd_item GROUP BY stage").fetchall()) == {
        "wire": 5, "bunch": 1, "conductor": 3, "mica_taped": 2}


def test_plain_chain_from_rod(fx):
    need = {r["item"]: (r["need_per_unit"], r["depth"], r["kind"], r["unit"])
            for r in fx.execute("SELECT * FROM cd_need WHERE root = 'NX-1C16'")}
    assert set(need) == {"NX-1C16", "NX-32W.3", "NX-16W.3", "NX-1W1.6", "NX-1R8-NEX"}
    assert need["NX-32W.3"][:3] == (pytest.approx(7.02611), 1, "made")
    assert need["NX-16W.3"][0] == pytest.approx(14.05222)
    assert need["NX-1W1.6"][0] == pytest.approx(14.05222 * 0.26829)
    assert need["NX-1R8-NEX"] == (pytest.approx(14.05222 * 0.26829 * 0.01804), 4, "material", "kg")
    item = fx.execute("SELECT * FROM cd_item WHERE item = 'NX-1C16'").fetchone()
    assert (item["traces_to_rod"], item["step_count"], item["kg_per_m"], item["diameter_mm"]) == (1, 4, 0.1361, 5.18)
    machines = [fx.execute("SELECT machine FROM cd_operation WHERE item = ?", (i,)).fetchone()[0]
                for i in ("NX-1W1.6", "NX-16W.3", "NX-32W.3", "NX-1C16")]
    assert machines == ["RB2", "WD4", "BU6", "BU1"]
    assert tuple(fx.execute("SELECT parallel_ends, qty_per_unit, kind FROM cd_input WHERE item = 'NX-1C16'")
                 .fetchone()) == (7, 7.02611, "made")


def test_tinned_branch_and_tape(fx):
    item = fx.execute("SELECT * FROM cd_item WHERE item = 'DM-1C1.5'").fetchone()
    assert (item["stage"], item["material"], item["mica"], item["traces_to_rod"], item["step_count"]) == \
        ("mica_taped", "tinned", 1, 1, 5)
    ops = dict(fx.execute("SELECT o.item, o.machine FROM cd_operation o JOIN cd_need n ON n.item = o.item "
                          "WHERE n.root = 'DM-1C1.5'").fetchall())
    assert ops == {"DM-1C1.5": "TP2", "DX-1C1.5": "BU4", "DX-7W.3": "WD5", "DX-1W1.6": "TN1", "NX-1W1.6": "RB2"}
    rod = one(fx, "SELECT need_per_unit FROM cd_need WHERE root = 'DM-1C1.5' AND item = 'NX-1R8-NEX'")
    assert rod == pytest.approx(3 * 0.23 * 1 * 0.01804)
