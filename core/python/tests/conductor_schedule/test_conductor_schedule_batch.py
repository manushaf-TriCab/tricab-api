"""Wire batching (CONTRACT-BATCH), minimum levels and 1.6 mm stock points (CONTRACT-TRACKING), spool pooling
(CONTRACT-POOL): port of the prototype batch tests."""

import pytest

from conductor_schedule import config, passes
from conductor_schedule import schedule as sched
from conductor_schedule.batch import history_policies, spool_key
from conductor_schedule.issues import IssueLog
from cs_support import H_BASIC, ROD, build, cal, chain_tree, d, hist, job, kinds, tree


def all_cals():
    return {m: cal() for m in config.MACHINES}


def two_jobs():
    return [job(1, due="2026-11-02"), job(2, due="2026-11-09")]


def batched(jobs, sizes, tr=None, h=None, today="2026-10-12", **kw):
    tr = tr or chain_tree(0.5)
    h = hist(**H_BASIC) if h is None else h
    issues = IssueLog()
    ops, _ = passes.build_ops(jobs, tr, h, issues)
    return sched.schedule(ops, h, all_cals(), d(today), issues, batch_sizes=sizes,
                          batch_rules={i: "history" for i in sizes}, **kw) + (issues,)


def test_two_ops_share_one_wire_batch_and_its_feeder():
    done, batches, served, _ = batched(two_jobs(), {"W": 5000.0, "F": 4000.0})
    assert [(b.item, b.metres) for b in batches] == [("W", 5000.0), ("F", 4000.0)]
    w, f = batches
    assert w.allocated == 2000.0 and w.remaining == 3000.0             # two ops x 1000 m, the rest carried as surplus
    assert f.allocated == 2500.0                                       # W batch of 5000 needs 5000 x 500/1000 of F
    assert f.available_at(2500.0) <= w.start < f.end                   # W starts once F has made W's share
    assert served[w.batch_id] == served[f.batch_id] == {1, 2}
    for op in done:
        _c, _b, wp, fp = op.passes
        assert wp.batch is w and fp.batch is f
    assert done[0].passes[2].end == w.available_at(1000.0) < done[1].passes[2].end == w.available_at(2000.0) < w.end
    assert w.setup_h == 1.0 and w.run_h == 5.0                         # one setup for both ops


def test_batch_too_small_for_a_draw_makes_several_batches():
    done, batches, _s, _ = batched(two_jobs(), {"W": 600.0})
    ws = [b for b in batches if b.item == "W"]
    assert [b.metres for b in ws] == [600.0, 600.0, 600.0, 600.0]      # 1000 + 1000 m in 600 m lots
    assert done[0].passes[2].batch is ws[1] and done[1].passes[2].batch is ws[3]
    assert sum(b.allocated for b in ws) == 2000.0


def test_no_size_is_lot_for_lot_and_logged():
    _done, batches, _s, issues = batched(two_jobs(), {})
    assert [(b.item, b.metres, b.rule) for b in batches if b.item == "W"] == [("W", 1000.0, "lot_for_lot")] * 2
    assert ("W", "no_batch_size") in kinds(issues)


def test_batching_off_keeps_wire_on_the_op():
    done, batches, _s, _ = batched(two_jobs(), {"W": 5000.0}, batching=False)
    assert batches == [] and all(p.batch is None and p.run_h > 0 for o in done for p in o.passes)


def row(item, recv, start, fin, status="Completed", pool="15_WIR"):
    return {"ProductionPoolId": pool, "ItemNumber": item, "ProductionOrderStatus": status,
            "ScheduledQuantity": "5250000", "CsReceivedQuantity": str(recv),
            "ScheduledStartDate": start + "T12:00:00Z", "ReportedAsFinishedDate": fin + "T12:00:00Z"}


def test_history_policies_use_received_quantity_and_lead_time():
    rows = [row("W", q, "2026-05-01", "2026-05-15") for q in (800, 900, 1000, 1100, 1200)]       # 14-day refill
    rows += [row("W", 9999, "2026-05-01", "2026-05-02", status="Released")]                        # not finished
    rows += [row("W", 9999, "2024-05-01", "2024-05-02")]                                           # too old
    rows += [row("X", 50, "2026-05-01", "2026-05-01")] * 2                                          # too few orders
    rows += [row("Z", 10, "2026-05-01", "2026-05-02", pool="20_BUN")] * 9                           # not wire
    pol = history_policies(rows, d("2026-10-13"))
    assert set(pol) == {"W", "X"}
    w = pol["W"]
    assert (w.batch_m, w.batch_rule, w.orders) == (1000.0, "history", 5)
    assert w.weekly_use_m == 5000.0 / 52 and w.refill_days == 14.0
    assert w.min_m == 5000.0 / 52 * 2 and w.min_rule == "history"
    x = pol["X"]
    assert (x.batch_m, x.batch_rule, x.min_m, x.min_rule) == (None, "lot_for_lot", 0.0, "none")


def test_refill_days_has_a_floor_of_one_and_an_even_count_takes_the_mean_of_the_middle_two():
    rows = [row("W", q, "2026-05-01", "2026-05-01") for q in (100, 200, 300, 400, 500, 600)]
    w = history_policies(rows, d("2026-10-13"))["W"]
    assert w.batch_m == 350.0 and w.refill_days == 1.0 and w.min_m == pytest.approx(2100 / 52 / 7)


def minned(jobs, stock, sizes, mins):
    st = dict(stock)
    done, batches, _s, _i = batched(jobs, sizes, stock=st, min_levels=mins)
    return done, batches, st


def test_min_level_tops_up_before_stock_runs_out():
    # W: 2500 m on hand, min 2000, batches of 1000. Op 1 draws 1000 -> 1500 left < 2000 -> one top-up batch first.
    done, batches, left = minned(two_jobs(), {"W": 2500.0}, {"W": 1000.0, "F": 4000.0}, {"W": 2000.0})
    ws = [b for b in batches if b.item == "W"]
    assert [b.reason for b in ws] == ["min_level", "min_level"]            # op 2 also takes it below the minimum
    assert left["W"] == 500.0 and sum(b.remaining for b in ws) == 2000.0   # 2500 left: at or above the 2000 minimum
    assert [p.item for p in done[0].passes] == ["C", "B"]                  # op 1's wire came from stock


def test_shortage_reason_when_the_draw_itself_is_not_covered():
    _done, batches, _left = minned(two_jobs(), {}, {"W": 1000.0, "F": 4000.0}, {"W": 500.0})
    ws = [b for b in batches if b.item == "W"]
    assert ws[0].reason == "shortage"
    assert sum(b.remaining for b in ws) >= 500.0


def test_wire_stock_is_drawn_before_any_batch():
    st = {"W": 1200.0}
    done, batches, _s, _ = batched(two_jobs(), {"W": 5000.0, "F": 4000.0}, stock=st)
    w2 = done[1].passes[2]
    assert [p.item for p in done[0].passes] == ["C", "B"]                  # op 1's wire all from stock
    assert w2.stock_m == 200.0 and w2.quantity == 1000.0 and w2.batch is not None
    assert [b.allocated for b in batches if b.item == "W"] == [800.0]
    assert st["W"] == 0.0


def test_feeder_stock_feeds_a_new_batch():
    st = {"F": 10000.0}
    _done, batches, _s, _ = batched(two_jobs(), {"W": 5000.0, "F": 4000.0}, stock=st)
    assert [b.item for b in batches] == ["W"]                              # no F batch needed
    assert batches[0].stock_feed == {"F": 2500.0} and st["F"] == 7500.0


# ---------------------------------------------------------------- spool pooling (CONTRACT-POOL.md)

S8, S16, S7, ROD16 = "NX-8W.3", "NX-16W.3", "NX-7W.3", "NX-1W1.6"
POOL_TREE = tree(
    {"C": "25_FCON", "C16": "25_FCON", "NX-64W.3": "20_BUN", "B16": "20_BUN", S8: "15_WIR", S16: "15_WIR", S7: "15_WIR",
     ROD16: "15_WIR"},
    {"C": [("NX-64W.3", 1)], "NX-64W.3": [(S8, 1)], "C16": [("B16", 1)], "B16": [(S16, 1)],
     S8: [(ROD16, 1)], S16: [(ROD16, 1)], S7: [(ROD16, 1)], ROD16: [(ROD, 0.009)]})
H_POOL = dict(
    item_jobs={"C": {("BU1", "BUNCHING"): 10}, "C16": {("BU1", "BUNCHING"): 10}, "NX-64W.3": {("BU2", "BUNCHING"): 10},
               "B16": {("BU2", "BUNCHING"): 10}, S8: {("WD4", "WIRE_DRAW"): 10}, S16: {("WD4", "WIRE_DRAW"): 10},
               S7: {("WD4", "WIRE_DRAW"): 10}, ROD16: {("RB1", "ROD_BREAK"): 10}},
    rates=H_BASIC["rates"])


def spool_jobs(items=("C", "C")):
    """One conductor job per entry: C goes through the 8W spool, C16 through the 16W spool."""
    return [job(k + 1, it, 1000.0, "2026-11-0%d" % (k + 2)) for k, it in enumerate(items)]


def pooled(jobs, stock, sizes=None, mins=None, **kw):
    h = hist(**H_POOL)
    issues = IssueLog()
    ops, _ = passes.build_ops(jobs, POOL_TREE, h, issues)
    st = dict(stock)
    done, batches, _s = sched.schedule(ops, h, all_cals(), d("2026-10-12"), issues, batch_sizes=sizes or {},
                                       batch_rules={}, stock=st, min_levels=mins, **kw)
    return done, batches, st, issues


def test_bunching_takes_another_spool_by_strand_count():
    # 1000 m of 8W = 8000 strand-m; 600 m of 16W on hand = 9600 strand-m -> 500 m of 16W, no wire made
    done, batches, left, issues = pooled(spool_jobs(("C",)), {S16: 600.0}, sizes={S8: 5000.0, ROD16: 4000.0})
    assert batches == [] and [p.item for p in done[0].passes] == ["C", "NX-64W.3"]
    assert left[S16] == pytest.approx(100.0)
    assert (S8, "spool_substituted") in kinds(issues)


def test_no_pool_draws_the_spool_the_bom_names():
    done, batches, left, issues = pooled(spool_jobs(("C",)), {S16: 600.0}, sizes={S8: 5000.0, ROD16: 4000.0}, pool=False)
    assert [b.item for b in batches][:1] == [S8] and left[S16] == 600.0
    assert (S8, "spool_substituted") not in kinds(issues)


def test_part_from_substitute_rest_from_a_batch_of_the_named_spool():
    # 200 m of 16W = 3200 strand-m covers 400 m of the 1000 m of 8W; a batch of 8W makes the other 600 m
    done, batches, left, _ = pooled(spool_jobs(("C",)), {S16: 200.0}, sizes={S8: 5000.0, ROD16: 4000.0}, batch_items=None)
    w = done[0].passes[2]
    assert w.stock_m == pytest.approx(400.0) and left[S16] == pytest.approx(0.0)
    assert [(b.item, b.allocated) for b in batches if b.item == S8] == [(S8, pytest.approx(600.0))]


def test_surplus_of_one_spool_batch_serves_another_spool():
    # job 1 needs 16W: a 5000 m batch leaves 4000 m; job 2's 1000 m of 8W takes 500 m of that surplus
    done, batches, _left, _ = pooled(spool_jobs(("C16", "C")), {}, sizes={S16: 5000.0, S8: 5000.0, ROD16: 100000.0})
    assert [b.item for b in batches if b.item in (S8, S16)] == [S16]
    b16 = next(b for b in batches if b.item == S16)
    assert b16.allocated == pytest.approx(1500.0)
    assert done[1].passes[2].batch is b16


def test_root_spool_goes_to_a_cable_as_named():
    # a spool that is the job itself (direct to a cable) is not interchangeable
    done, batches, left, _ = pooled([job(1, S8, 1000.0)], {S16: 600.0}, sizes={S8: 5000.0, ROD16: 4000.0})
    assert left[S16] == 600.0 and batches[0].item == S8


def test_pooled_minimum_counts_every_spool():
    # minimum 1000 m of 8W (8000 strand-m); 16W stock 2000 m = 32000 strand-m: draw 8000 and still above -> no batch
    _done, batches, left, _ = pooled(spool_jobs(("C",)), {S16: 2000.0}, sizes={S8: 5000.0, ROD16: 4000.0}, mins={S8: 1000.0})
    assert batches == [] and left[S16] == pytest.approx(1500.0)


def test_pooling_without_batching_takes_substitute_stock():
    done, _b, left, _ = pooled(spool_jobs(("C",)), {S7: 2000.0}, batching=False)
    assert [p.item for p in done[0].passes] == ["C", "NX-64W.3"]
    assert left[S7] == pytest.approx(2000.0 - 8000.0 / 7)


def test_spool_key_and_wire_counts():
    assert spool_key(S8) == spool_key(S16) == ("NX", ".3") != spool_key("DX-8W.3")
    assert spool_key("NX-88W.3") is None and spool_key("C") is None


# ---------------------------------------------------------------- stock points (CONTRACT-TRACKING.md)

def test_only_1_6mm_is_batched_and_spools_are_made_per_job():
    h = hist(**H_POOL)
    issues = IssueLog()
    ops, _ = passes.build_ops(spool_jobs(("C", "C")), POOL_TREE, h, issues)
    done, batches, _s = sched.schedule(ops, h, all_cals(), d("2026-10-12"), issues,
                                       batch_sizes={S8: 5000.0, ROD16: 4000.0}, batch_rules={}, stock={},
                                       min_levels={S8: 9000.0}, batch_items=config.is_stock_wire)
    assert {b.item for b in batches} == {ROD16}                         # no 8W batch, so no 8W minimum either
    for op in done:
        c, bunch, spool, rod16 = op.passes
        assert spool.batch is None and spool.machine == "WD4" and spool.quantity == 1000.0 and spool.setup_h > 0
        assert rod16.batch is batches[0] and spool.start >= rod16.end   # the job's drawing waits for its 1.6 mm


def test_is_stock_wire():
    assert config.is_stock_wire("NX-1W1.6") and config.is_stock_wire("DX-1W1.6")
    assert not config.is_stock_wire("NX-1W1.6X") and not config.is_stock_wire(S8) and not config.is_stock_wire("NX-1C16")


# ---------------------------------------------------------------- tracking through a build

def over(tmp_path, body):
    import json
    p = tmp_path / "over.json"
    p.write_text(json.dumps(body), encoding="utf-8")
    return p


def test_built_fixture_batches_only_1_6mm_and_shares_them(built):
    run, con = built
    items = {r[0] for r in con.execute("SELECT item FROM cs_batch")}
    assert items <= {"NX-1W1.6", "DX-1W1.6"} and run["batches"] == con.execute("SELECT COUNT(*) FROM cs_batch").fetchone()[0] > 0
    spools = con.execute("SELECT COUNT(*) FROM cs_pass WHERE item IN ('NX-16W.3', 'DX-7W.3') AND batch_id IS NOT NULL").fetchone()[0]
    assert spools == 0                                              # 0.3 spools are made per job
    for p in con.execute("SELECT * FROM cs_pass WHERE batch_id IS NOT NULL"):
        assert p["setup_h"] == 0 and p["run_h"] == 0
    for b in con.execute("SELECT * FROM cs_batch"):
        assert b["allocated_m"] + b["surplus_m"] == pytest.approx(b["metres"])


def test_batch_size_and_minimum_settings_replace_history(tmp_path):
    f = over(tmp_path, {"batch_sizes": {"NX-1W1.6": 123456}, "min_levels": {"NX-1W1.6": 1000}})
    _, con = build(tmp_path, settings=f)
    w = con.execute("SELECT * FROM cs_wire_policy WHERE item = 'NX-1W1.6'").fetchone()
    assert (w["batch_m"], w["batch_rule"], w["min_m"], w["min_rule"]) == (123456, "config", 1000, "config")
    assert all(b["metres"] == 123456 and b["batch_rule"] == "config" for b in con.execute("SELECT * FROM cs_batch WHERE item = 'NX-1W1.6'"))


def test_no_batch_size_with_too_few_orders_is_lot_for_lot(tmp_path):
    _, con = build(tmp_path)
    w = {r["item"]: r for r in con.execute("SELECT * FROM cs_wire_policy")}
    assert w["NX-1W1.6"]["batch_rule"] == "lot_for_lot" and w["NX-1W1.6"]["orders"] == 0     # one old order in the fixture
    assert ("NX-1W1.6", "no_batch_size") in {(r["item"], r["kind"]) for r in con.execute("SELECT item, kind FROM cs_issue")}


def test_yield_setting_grows_wire_and_rod(tmp_path):
    q = "SELECT rod_kg FROM cs_op WHERE item = 'NX-1C16' ORDER BY op_id"
    _, base = build(tmp_path / "a")
    _, grown = build(tmp_path / "b", settings=over(tmp_path, {"yields": {"bunching": 0.8}}))
    # NX-32W.3 is bunched: its 16W.3 input and the rod below it grow by 1 / 0.8
    assert grown.execute(q).fetchone()[0] == pytest.approx(base.execute(q).fetchone()[0] * 1.25)
    assert ("(all)", "yield_applied") in {(r["item"], r["kind"]) for r in grown.execute("SELECT item, kind FROM cs_issue")}
    assert ("(all)", "yield_applied") not in {(r["item"], r["kind"]) for r in base.execute("SELECT item, kind FROM cs_issue")}
