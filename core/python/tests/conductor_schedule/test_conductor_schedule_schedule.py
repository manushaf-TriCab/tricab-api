"""Finite-capacity forward schedule and CONTRACT-STOCK (port of the prototype scheduler tests)."""

from datetime import datetime

import pytest

from conductor_schedule import config, passes
from conductor_schedule import schedule as sched
from conductor_schedule.issues import IssueLog
from cs_support import H_BASIC, ROD, build, cal, chain_tree, d, hist, job, kinds, tree


def all_cals():
    return {m: cal() for m in config.MACHINES}


def ops_of(jobs, tr=None, h=None):
    issues = IssueLog()
    ops, _failed = passes.build_ops(jobs, tr or chain_tree(), hist(**H_BASIC) if h is None else h, issues)
    return ops, issues


def run_sched(jobs, cals=None, tr=None, h=None, today="2026-10-12", **kw):
    ops, issues = ops_of(jobs, tr, h)
    done, batches, served = sched.schedule(ops, hist(**H_BASIC) if h is None else h, cals or all_cals(), d(today), issues, **kw)
    return done, issues, ops


def test_children_finish_before_parent_starts():
    done, _, _ = run_sched([job(1)])
    c, b, w, f = done[0].passes
    assert f.end <= w.start and w.end <= b.start and b.end <= c.start
    assert done[0].end_at == c.end and done[0].start_at == f.start
    assert done[0].status == "scheduled"


def test_pass_durations_are_setup_plus_quantity_over_rate():
    done, _, _ = run_sched([job(1)])
    c, b, w, f = done[0].passes
    assert (c.setup_h, c.run_h) == (0.5, 2.0)                     # BU1: 30 min, 1000 m / 500 per h
    assert (w.setup_h, w.run_h) == (0.0, 0.0) and (f.setup_h, f.run_h) == (0.0, 0.0)   # hours sit on the batch
    assert (w.batch.setup_h, w.batch.run_h) == (1.0, 1.0)
    assert (f.batch.setup_h, f.batch.run_h) == (0.75, 0.5)
    assert (c.operation_factor, c.product_factor) == (1.0, 1.0)
    assert c.base_rate == 500 and c.machine == "BU1"


def test_schedule_starts_at_today_0600_but_inside_calendar():
    done, _, _ = run_sched([job(1)], today="2026-10-12")
    assert done[0].passes[3].start == datetime(2026, 10, 12, 8)   # calendar opens at 08:00


def test_earliest_finish_machine_is_chosen():
    h = hist(item_jobs={"C": {("BU1", "BUNCHING"): 10, ("BU2", "BUNCHING"): 10}},
             rates={("BUNCHING", "BU1"): (0, 100), ("BUNCHING", "BU2"): (0, 1000)})
    t = tree({"C": "25_FCON"}, {"C": [(ROD, 0.001)]})
    done, _, _ = run_sched([job(1)], tr=t, h=h)
    assert done[0].passes[0].machine == "BU2"                      # 1 h beats 10 h


def test_busy_fast_machine_loses_to_idle_slow_machine_only_when_it_finishes_earlier():
    h = hist(item_jobs={"C": {("BU1", "BUNCHING"): 10, ("BU2", "BUNCHING"): 10}},
             rates={("BUNCHING", "BU1"): (0, 100), ("BUNCHING", "BU2"): (0, 1000)})
    t = tree({"C": "25_FCON"}, {"C": [(ROD, 0.001)]})
    jobs = [job(i + 1, metres=4000, due="2026-11-0%d" % (i + 1)) for i in range(3)]
    done, _, _ = run_sched(jobs, tr=t, h=h)
    # first two on BU2 (4 h each = 8 h day), third: BU2 free Tue 08:00 -> ends 12:00; BU1 0+40 h -> stays on BU2
    assert [o.passes[0].machine for o in done] == ["BU2", "BU2", "BU2"]
    assert done[1].passes[0].start == done[0].passes[0].end       # same machine runs back to back


def test_tie_goes_to_the_earlier_stage_order():
    h = hist(item_jobs={"C": {("BU2", "BUNCHING"): 10, ("BU1", "BUNCHING"): 10}},
             rates={("BUNCHING", "BU1"): (0, 500), ("BUNCHING", "BU2"): (0, 500)})
    t = tree({"C": "25_FCON"}, {"C": [(ROD, 0.001)]})
    assert run_sched([job(1)], tr=t, h=h)[0][0].passes[0].machine == "BU1"


def test_machine_is_not_double_booked():
    done, _, _ = run_sched([job(1, metres=500), job(2, metres=500, due="2026-11-03")])
    a, b = done[0].passes[0], done[1].passes[0]
    assert a.machine == b.machine == "BU1" and b.start >= a.end


def test_job_without_any_rate_is_dropped_and_logged():
    h = hist(item_jobs=H_BASIC["item_jobs"], rates={})
    done, issues, ops = run_sched([job(1)], h=h)
    assert done == [] and ops[0].status == "no_rate" and any(k == "no_rate" for _i, k in kinds(issues))


def test_late_days_on_scheduled_op():
    done, _, _ = run_sched([job(1, due="2026-10-10")])            # ends Mon 12 Oct 15:45
    assert done[0].late_days == 2


def test_jobs_are_scheduled_in_job_id_order_not_due_order():
    done, _, _ = run_sched([job(2, due="2026-11-01"), job(1, due="2026-12-01")])
    assert [o.op_id for o in done] == [1, 2] and done[1].passes[0].start >= done[0].passes[0].end


# ---------------------------------------------------------------- Dynamics on-hand first (CONTRACT-STOCK.md)

def two_jobs():
    return [job(1, due="2026-11-02"), job(2, due="2026-11-09")]


def stocked(jobs, stock, sizes=None, root_stock=True):
    st = dict(stock)
    done, issues, ops = run_sched(jobs, tr=chain_tree(0.5), batch_sizes=sizes or {}, batch_rules={}, stock=st,
                                  root_stock=root_stock)
    return done, st, issues, ops


def test_conductor_stock_covers_first_job_and_part_of_second():
    done, left, issues, ops = stocked(two_jobs(), {"C": 1500.0})
    assert [o.op_id for o in done] == [2] and ops[0].status == "from_stock" and ops[1].status == "scheduled"
    assert ("C", "job_from_stock") in kinds(issues)
    c, b, w, f = done[0].passes
    assert (c.stock_m, c.quantity) == (500.0, 500.0) and done[0].stock_m == 500.0
    assert b.quantity == 500.0 and w.quantity == 500.0                     # children shrink with the remainder
    assert left["C"] == 0.0


def test_the_jobs_own_item_is_not_drawn_when_restock_already_netted_it():
    done, left, issues, ops = stocked(two_jobs(), {"C": 1500.0}, root_stock=False)
    assert [o.op_id for o in done] == [1, 2] and left["C"] == 1500.0 and done[0].stock_m == 0.0
    assert ("C", "job_from_stock") not in kinds(issues)


def test_bunch_stock_removes_the_subtree_below_it():
    done, _left, issues, _ = stocked(two_jobs(), {"B": 1000.0})
    assert [p.item for p in done[0].passes] == ["C"] and done[0].passes[0].children == []
    assert [p.item for p in done[1].passes] == ["C", "B", "W", "F"]
    assert ("B", "covered_by_stock") in kinds(issues)


def test_partial_stock_shrinks_the_pass_and_its_children():
    done, left, _, _ = stocked([job(1)], {"B": 400.0})
    c, b, w, f = done[0].passes
    assert (b.stock_m, b.quantity, w.quantity, f.quantity) == (400.0, 600.0, 600.0, pytest.approx(300.0))
    assert left["B"] == 0.0


# ---------------------------------------------------------------- end to end through a build

def test_built_fixture_orders_ops_by_job_and_respects_machines(built):
    run, con = built
    ops = [dict(r) for r in con.execute("SELECT * FROM cs_op ORDER BY op_id")]
    assert len(ops) == run["ops"] == run["jobs_scheduled"] == 10
    for op in ops:
        assert op["start_at"] <= op["end_at"] and op["start_at"] >= "2026-10-28T06:00"
    # one pass at a time per machine (batch passes carry 0 hours and share their batch's slot)
    by_machine = {}
    for p in con.execute("SELECT machine, start_at, end_at, batch_id FROM cs_pass WHERE batch_id IS NULL ORDER BY start_at"):
        by_machine.setdefault(p["machine"], []).append((p["start_at"], p["end_at"]))
    for rows in by_machine.values():
        for (s1, e1), (s2, e2) in zip(rows, rows[1:]):
            assert e1 <= s2
    assert run["horizon_end"] == max(o["end_at"] for o in ops)[:10]


def test_late_ops_counted(built):
    run, con = built
    late = con.execute("SELECT COUNT(*) FROM cs_op WHERE late_days > 0").fetchone()[0]
    assert run["late_ops"] == late
