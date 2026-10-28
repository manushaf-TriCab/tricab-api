"""Section 5 invariants on built fixture stores, job statuses, stock rows, load and capacity."""

import json

import pytest

from conductor_schedule import config, source
from cs_support import build, cd_support, make_source


def q(con, sql, *a):
    return con.execute(sql, a).fetchall()


def test_invariants(built):
    run, con = built
    assert q(con, "SELECT COUNT(*) FROM cs_run")[0][0] == 1
    assert [r[0] for r in q(con, "SELECT machine FROM cs_machine ORDER BY stage_order")] == list(config.MACHINES)
    assert q(con, "SELECT op_id FROM cs_op WHERE op_id NOT IN (SELECT job_id FROM cs_job)") == []
    assert q(con, "SELECT machine FROM cs_pass WHERE machine NOT IN (SELECT machine FROM cs_machine)") == []
    assert q(con, "SELECT machine FROM cs_batch WHERE machine NOT IN (SELECT machine FROM cs_machine)") == []
    # job status 'scheduled' iff an op exists
    assert q(con, "SELECT job_id FROM cs_job WHERE (status = 'scheduled') <> (job_id IN (SELECT op_id FROM cs_op))") == []
    # release_runs jobs per scheduled release item (minus from_stock)
    for r in q(con, "SELECT item, release_runs FROM cs_restock WHERE status = 'release' AND scheduled = 1"):
        assert q(con, "SELECT COUNT(*) FROM cs_job WHERE item = ?", r[0])[0][0] == r[1]
    assert q(con, "SELECT item FROM cs_restock WHERE scheduled = 1 AND mica = 1") == []
    assert {r[0] for r in q(con, "SELECT item FROM cs_wire_policy")} <= {"NX-1W1.6", "DX-1W1.6"}
    assert q(con, "SELECT 1 FROM cs_load l JOIN cs_capacity c USING (machine, week) WHERE l.hours > c.available_h + 1e-9") == []
    assert q(con, "SELECT 1 FROM cs_load WHERE (machine, week) NOT IN (SELECT machine, week FROM cs_capacity)") == []
    assert [tuple(r) for r in q(con, "PRAGMA integrity_check")] == [("ok",)] and q(con, "PRAGMA foreign_key_check") == []
    # counters equal the table counts
    assert run["ops"] == q(con, "SELECT COUNT(*) FROM cs_op")[0][0] == run["jobs_scheduled"]
    assert run["passes"] == q(con, "SELECT COUNT(*) FROM cs_pass")[0][0]
    assert run["batches"] == q(con, "SELECT COUNT(*) FROM cs_batch")[0][0]
    assert run["late_ops"] == q(con, "SELECT COUNT(*) FROM cs_op WHERE late_days > 0")[0][0]
    assert run["jobs"] == q(con, "SELECT COUNT(*) FROM cs_job")[0][0]
    assert run["horizon_end"] == q(con, "SELECT MAX(end_at) FROM cs_op")[0][0][:10]


def test_op_and_pass_consistency(built):
    _, con = built
    for op in q(con, "SELECT * FROM cs_op"):
        ps = q(con, "SELECT * FROM cs_pass WHERE op_id = ?", op["op_id"])
        assert op["pass_count"] == len(ps)
        assert op["end_at"] == max(p["end_at"] for p in ps) and op["start_at"] == min(p["start_at"] for p in ps)
        root = [p for p in ps if p["parent_pass_id"] is None]
        assert len(root) == 1 and root[0]["item"] == op["item"] and root[0]["depth"] == 0
        for p in ps:
            assert json.loads(p["eligible"]) and p["machine"] in json.loads(p["eligible"])
            if p["parent_pass_id"] is not None:
                parent = next(x for x in ps if x["pass_id"] == p["parent_pass_id"])
                assert p["end_at"] <= parent["start_at"] or p["batch_id"] is not None


def test_draws_point_at_real_consumers(built):
    _, con = built
    for dr in q(con, "SELECT * FROM cs_draw"):
        if dr["consumer_kind"] == "op":
            assert q(con, "SELECT 1 FROM cs_op WHERE op_id = ?", dr["consumer_id"])
        else:
            assert q(con, "SELECT 1 FROM cs_batch WHERE batch_id = ?", dr["consumer_id"])
    for b in q(con, "SELECT * FROM cs_batch"):
        assert b["allocated_m"] == pytest.approx(sum(r[0] for r in q(con, "SELECT metres FROM cs_draw WHERE batch_id = ?", b["batch_id"])))


def test_stock_rows_rod_and_points(built):
    _, con = built
    rows = {r["item"]: r for r in q(con, "SELECT * FROM cs_stock")}
    rod = rows["NX-1R8-NEX"]
    assert (rod["point"], rod["unit"], rod["on_hand_m"], rod["used_m"]) == ("rod", "kg", None, None)
    assert rod["on_hand_kg"] == 40000 and rod["incoming_kg"] == 900000 and rod["weekly_use"] == pytest.approx(1000.0)
    assert rows["NX-1C16"]["point"] == "conductor" and rows["NX-1C16"]["on_hand_m"] == 1000 and rows["NX-1C16"]["used_m"] == 0
    assert rows["NX-1C16"]["kg_per_m"] == pytest.approx(0.1361) and rows["NX-1C16"]["on_hand_kg"] == pytest.approx(136.1)
    assert rows["NX-4W.3"]["point"] == "spool"
    assert all(r["unit"] == "m" or r["item"] == "NX-1R8-NEX" for r in rows.values())


def test_load_is_split_by_week_and_capacity_covers_26_weeks(built):
    _, con = built
    weeks = [r[0] for r in q(con, "SELECT DISTINCT week FROM cs_capacity ORDER BY week")]
    assert weeks[0] == "2026-10-26" and len(weeks) >= config.CAPACITY_WEEKS
    cap = {(r["machine"], r["week"]): r["available_h"] for r in q(con, "SELECT * FROM cs_capacity")}
    assert len(cap) == 12 * len(weeks)
    assert cap[("BU1", "2026-11-09")] == pytest.approx(80.0)          # Mon-Fri 06:00-22:00
    assert cap[("BU1", "2026-12-21")] == 0                            # Christmas shutdown week
    booked = sum(r[0] for r in q(con, "SELECT hours FROM cs_load"))
    work = sum(r[0] for r in q(con, "SELECT setup_h + run_h FROM cs_pass WHERE batch_id IS NULL")) + \
        sum(r[0] for r in q(con, "SELECT setup_h + run_h FROM cs_batch"))
    assert booked == pytest.approx(work, rel=1e-3)


def test_first_week_capacity_starts_at_today_0600(built):
    _, con = built
    # Wed 28 Oct 06:00 to Fri 22:00: 3 x 16 h
    assert q(con, "SELECT available_h FROM cs_capacity WHERE machine = 'BU1' AND week = '2026-10-26'")[0][0] == pytest.approx(48.0)


def test_horizon_and_shutdown_issue(tmp_path):
    folder = make_source(tmp_path, runs=None)
    over = tmp_path / "o.json"
    over.write_text(json.dumps({"shutdown": [{"from": "2026-10-28", "to": "2026-11-03"}]}), encoding="utf-8")
    run, con = build(tmp_path, folder=folder, settings=over)
    assert run["horizon_end"] >= "2026-11-04" and q(con, "SELECT MIN(start_at) FROM cs_op")[0][0] >= "2026-11-04T06:00"
    assert ("(all)", "shutdown_in_horizon") in {(r["item"], r["kind"]) for r in con.execute("SELECT item, kind FROM cs_issue")}


def test_jobs_without_a_machine_or_a_rate_are_marked(tmp_path):
    folder = make_source(tmp_path)
    cd_support.write_csv(folder / "production-route-jobs-raw.csv", source.FILES["production-route-jobs-raw.csv"][0], [])
    run, con = build(tmp_path, folder=folder)
    assert run["jobs"] > 0 and run["jobs_scheduled"] == 0 and run["ops"] == 0
    assert {r[0] for r in q(con, "SELECT DISTINCT status FROM cs_job")} == {"no_machine"}
    assert q(con, "SELECT scheduled FROM cs_restock WHERE item = 'NX-1C16'")[0][0] == 0
    assert "no_machine" in {r["kind"] for r in con.execute("SELECT kind FROM cs_issue")}
    assert q(con, "SELECT COUNT(*) FROM cs_machine")[0][0] == 12 and run["horizon_end"] == "2026-10-28"


def test_jobs_without_a_rate_are_no_rate(tmp_path):
    folder = make_source(tmp_path, rates=[])
    run, con = build(tmp_path, folder=folder)
    assert run["jobs_scheduled"] == 0 and {r[0] for r in q(con, "SELECT DISTINCT status FROM cs_job")} == {"no_rate"}
    assert "no_rate" in {r["kind"] for r in con.execute("SELECT kind FROM cs_issue")}
    assert q(con, "SELECT COUNT(*) FROM cs_restock WHERE scheduled = 0 AND mica = 0 AND status = 'release'")[0][0] >= 2


def test_missing_dynamics_calendar_is_a_source_error(tmp_path):
    from cable_routes.loader import SourceError
    folder = make_source(tmp_path, days=[])
    with pytest.raises(SourceError):
        build(tmp_path, folder=folder)


def test_job_covered_by_stock_of_a_feeder_is_still_scheduled_but_the_pass_is_dropped(tmp_path):
    inv = [("NX-1C16", "MAN", 1000, 0, "m"), ("NX-32W.3", "MAN", 100000, 0, "m"), ("NX-1R8-NEX", "MAN", 40000, 0, "kg")]
    _, con = build(tmp_path, inventory=inv)
    op = q(con, "SELECT job_id FROM cs_job WHERE item = 'NX-1C16' ORDER BY job_id")[0][0]
    items = [r[0] for r in q(con, "SELECT item FROM cs_pass WHERE op_id = ?", op)]
    assert items == ["NX-1C16"]                                       # bunch stock covers the whole subtree
    assert ("NX-32W.3", "covered_by_stock") in {(r["item"], r["kind"]) for r in con.execute("SELECT item, kind FROM cs_issue")}
    used = q(con, "SELECT used_m FROM cs_stock WHERE item = 'NX-32W.3'")[0][0]
    assert used > 0 and used <= 100000
