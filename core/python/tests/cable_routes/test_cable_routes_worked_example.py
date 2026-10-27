"""Plan section 4 / contract 8.4: KA9X-PASN/5C16BK, the nine-line chain and the copper check."""

import pytest

from cr_support import worked_example

F = "KA9X-PASN/5C16BK"


@pytest.fixture
def con(store):
    return store(worked_example())


def need(con, item, unit=None):
    rows = con.execute("SELECT unit, need_per_unit FROM cr_need WHERE finished = ? AND item = ?", (F, item)).fetchall()
    if unit is None:
        assert len(rows) == 1, rows
        return rows[0]["need_per_unit"]
    return {r["unit"]: r["need_per_unit"] for r in rows}[unit]


def test_traced_to_rod_with_ten_steps_and_fifteen_made_items(con):
    row = con.execute("SELECT * FROM cr_finished WHERE item = ?", (F,)).fetchone()
    assert (row["trace_status"], row["reason"], row["step_count"], row["max_depth"]) == ("to_rod", None, 10, 8)
    made = con.execute("SELECT count(*) FROM cr_need WHERE finished = ? AND kind IN ('finished','made')", (F,)).fetchone()[0]
    assert made == 15
    assert con.execute("SELECT count(*) FROM cr_item").fetchone()[0] == 15


def test_quantity_chain(con):
    assert need(con, "NX-1C16") == pytest.approx(4 * 1.0099)  # the four 1C16 cores
    assert need(con, "NX-32W.3") == pytest.approx(4 * 1.0099 * 7.02611)
    assert need(con, "NX-8W.3") == pytest.approx(4 * 1.0099 * 7.02611 * 4.00232)
    cores_path = 4 * 1.0099 * 7.02611 * 4.00232 * 0.26829
    earth_path = 1.0139 * 6.0 * 0.2  # fixture only
    assert need(con, "NX-1W1.6") == pytest.approx(cores_path + earth_path)


def test_copper_check_matches_net_product_weight(con):
    # 7.02611 x 4.00232 x 0.26829 x 0.01804 = 0.1361 kg of rod per metre of NX-1C16
    rod_total = need(con, "NX-1R8-NEX", "kg")
    earth_rod = 1.0139 * 6.0 * 0.2 * 0.01804
    per_metre_of_nx1c16 = (rod_total - earth_rod) / need(con, "NX-1C16")
    assert per_metre_of_nx1c16 == pytest.approx(0.1361, abs=0.0005)
    kg = con.execute("SELECT kg_per_m FROM cr_item WHERE item = 'NX-1C16'").fetchone()[0]
    assert kg == pytest.approx(0.1361)


def test_shared_sub_item_need_is_the_sum_over_parents(con):
    # RS0002 feeds both the finished cable (0.21541 kg) and the bedding item (0.08933 kg), each needed 1 m per metre
    assert need(con, "RS0002", "kg") == pytest.approx(0.21541 + 0.08933)


def test_rod_flag_depth_and_materials(con):
    rod = con.execute("SELECT * FROM cr_need WHERE finished = ? AND item = 'NX-1R8-NEX'", (F,)).fetchone()
    assert (rod["is_rod"], rod["kind"], rod["depth"]) == (1, "material", 9)
    other = con.execute("SELECT is_rod FROM cr_need WHERE finished = ? AND item = 'RS0002'", (F,)).fetchone()
    assert other["is_rod"] == 0
    m = con.execute("SELECT * FROM cr_material WHERE item = 'NX-1R8-NEX'").fetchone()
    assert m["conductor"] == 1 and m["name"] == "Rod PCu 8mm Rod PCu 8mm 8mm plain annealed coppe"
    assert con.execute("SELECT name, conductor FROM cr_material WHERE item = 'RS0002'").fetchone()[:] == (None, 0)


def test_finished_row_is_one_per_unit(con):
    row = con.execute("SELECT * FROM cr_need WHERE finished = ? AND item = ?", (F, F)).fetchall()
    assert len(row) == 1
    assert (row[0]["kind"], row[0]["need_per_unit"], row[0]["depth"], row[0]["unit"]) == ("finished", 1.0, 0, "m")


def test_parallel_ends_are_kept_but_not_multiplied(con):
    r = con.execute("SELECT qty_per_unit, parallel_ends FROM cr_input WHERE item = 'NX-1C16'").fetchone()
    assert (r["qty_per_unit"], r["parallel_ends"]) == (7.02611, 7)


def test_operations_are_gapless_per_item(con):
    for r in con.execute("SELECT item, count(*) n, max(seq) m, min(seq) lo FROM cr_operation GROUP BY item"):
        assert r["lo"] == 1 and r["n"] == r["m"]
    steps = con.execute("SELECT operation, machine FROM cr_operation WHERE item = ?", (F,)).fetchall()
    assert [tuple(s) for s in steps] == [("SHEATHING", "EX4")]
