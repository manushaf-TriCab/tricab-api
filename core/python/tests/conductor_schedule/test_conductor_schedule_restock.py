"""Demand, kanban, releases, jobs, mica, stock, issue rates (contract 4.3, 4.4)."""

import csv
import json

import pytest

from conductor_schedule import config, rates, source
from conductor_schedule import stock as stock_mod
from conductor_schedule.issues import IssueLog
from cs_support import TODAY, build, cd_support, picking


def rows(con, table="cs_restock", key="item"):
    return {r[key]: r for r in con.execute(f"SELECT * FROM {table}")}


def issue_keys(con):
    return {(x["item"], x["kind"]) for x in con.execute("SELECT item, kind FROM cs_issue")}


def test_r86_demand_and_run_record(built):
    run, con = built
    assert run["demand"] == "r86-order" and run["r86_order_weeks"] == 4.0
    d = rows(con, "cs_demand")
    assert d["NX-1C16"]["weekly_use_m"] == 500 and d["NX-1C16"]["source"] == "r86-order" and d["NX-1C16"]["unplaced"] == 0
    assert d["NX-1C16"]["issue_weekly_m"] == pytest.approx(100) and d["NX-1C16"]["issue_weekly_52w_m"] == pytest.approx(25)
    assert d["DX-1C1.5"]["weekly_use_m"] == 150 and d["DM-1C1.5"]["weekly_use_m"] == 200
    assert d["DM-1C1.5"]["material"] == "tinned" and d["DM-1C1.5"]["mica"] == 1 and d["DM-1C1.5"]["csa_mm2"] == 1.5


def test_r86_bucket_split_by_52_week_issue_share_and_plain_code_fallback(built):
    _, con = built
    d = rows(con, "cs_demand")
    # plain mica C16: 400 m / 4 weeks = 100, shared 300:100 between NM and MNX by 52-week issues.
    assert d["NM-1C16"]["weekly_use_m"] == pytest.approx(75) and d["MNX-1C16"]["weekly_use_m"] == pytest.approx(25)
    # plain mica C95: 280 m / 4 weeks = 70, no history: the plain code NM gets all of it.
    assert d["NM-1C95"]["weekly_use_m"] == pytest.approx(70) and d["MNX-1C95"]["weekly_use_m"] == 0
    assert rows(con)["MNX-1C95"]["status"] == "no_use" and rows(con)["MNX-1C95"]["weeks_on_hand"] is None


def test_spool_and_unplaceable_use_the_issue_rate_and_r86_leftovers_are_stored(built):
    _, con = built
    d = rows(con, "cs_demand")
    assert d["NX-4W.3"]["source"] == "issues" and d["NX-4W.3"]["unplaced"] == 1
    assert d["NX-4W.3"]["weekly_use_m"] == pytest.approx(50) and rows(con)["NX-4W.3"]["form"] == "spool"
    assert rows(con)["NX-4W.3"]["csa_mm2"] is None
    r = {(x["doc"], x["material"], x["mica"], x["csa_mm2"]): x for x in con.execute("SELECT * FROM cs_r86")}
    c70 = r[("order", "plain", 0, 70.0)]
    assert c70["metres"] == 700 and c70["weekly_m"] == 175 and c70["placed_m"] == 0 and c70["unplaced_m"] == 175
    c16 = r[("order", "plain", 0, 16.0)]
    assert c16["weekly_m"] == 500 and c16["placed_m"] == 500 and c16["unplaced_m"] == 0
    assert r[("invoice", "plain", 0, 16.0)]["placed_m"] == 0      # demand uses orders
    assert ("R-86 plain C70", "r86_unplaced") in issue_keys(con)


def test_invoice_demand_and_issue_demand(tmp_path):
    over = tmp_path / "s.json"
    over.write_text(json.dumps({"demand": "r86-invoice"}), encoding="utf-8")
    run, con = build(tmp_path, settings=over)
    d = rows(con, "cs_demand")
    assert run["demand"] == "r86-invoice" and d["NX-1C16"]["weekly_use_m"] == 750 and d["NX-1C16"]["source"] == "r86-invoice"
    con.close()
    over.write_text(json.dumps({"demand": "issues", "rate_window": "52w"}), encoding="utf-8")
    sub = tmp_path / "b"
    sub.mkdir()
    run, con = build(sub, settings=over)
    d = rows(con, "cs_demand")
    assert d["NX-1C16"]["source"] == "issues" and d["NX-1C16"]["weekly_use_m"] == pytest.approx(25) and d["NX-1C16"]["unplaced"] == 0
    con.close()


def test_kanban_is_the_median_of_runs_in_52_weeks_mean_of_middle_two(built):
    _, con = built
    r = rows(con)["NX-1C16"]
    assert r["runs_52w"] == 4 and r["kanban_m"] == 250 and r["kanban_assumed"] == 0   # 100,200,300,400; the 2025 run is out


def test_no_runs_assumes_one_week_of_use_and_flags_it(built):
    _, con = built
    r = rows(con)["DX-1C1.5"]
    assert r["runs_52w"] == 0 and r["kanban_assumed"] == 1 and r["kanban_m"] == 150
    assert r["note"] == "no runs in 52 weeks; kanban size unknown (1 week of use assumed)"
    assert ("DX-1C1.5", "kanban_assumed") in issue_keys(con)


def test_trigger_position_and_release_runs(built):
    _, con = built
    r = rows(con)["NX-1C16"]
    assert r["trigger_m"] == 2000 and r["physical_m"] == 1000 and r["position_m"] == 1000   # QC warehouse left out
    assert r["weeks_on_hand"] == 2 and r["status"] == "release"
    assert r["release_runs"] == 5 and r["release_m"] == 1250 and r["release_kg"] == pytest.approx(1250 * 0.1361)
    d = rows(con)["DX-1C1.5"]
    assert d["release_runs"] == 5 and d["release_m"] == 750 and d["release_kg"] is None      # no kg/m
    assert ("DX-1C1.5", "no_weight") in issue_keys(con)


def test_position_exactly_at_trigger_releases_one_run(tmp_path):
    inv = [("NX-1C16", "MAN", 1750, 250, "m"), ("NX-1R8-NEX", "MAN", 1, 0, "kg")]        # position 2000 = trigger
    _, con = build(tmp_path, inventory=inv)
    r = rows(con)["NX-1C16"]
    assert r["position_m"] == 2000 and r["incoming_m"] == 250 and r["release_runs"] == 1 and r["status"] == "release"
    con.close()
    sub = tmp_path / "b"
    sub.mkdir()
    _, con = build(sub, inventory=[("NX-1C16", "MAN", 1751, 250, "m")])
    r = rows(con)["NX-1C16"]
    assert r["status"] == "ok" and r["release_runs"] == 0 and r["release_m"] == 0
    con.close()


def test_jobs_and_due_dates(built):
    run, con = built
    jobs = [dict(j) for j in con.execute("SELECT * FROM cs_job ORDER BY job_id")]
    assert [j["job_id"] for j in jobs] == list(range(1, len(jobs) + 1))
    keys = [(j["due_date"], j["item"], j["run_no"]) for j in jobs]
    assert keys == sorted(keys)
    nx = [j for j in jobs if j["item"] == "NX-1C16"]
    # stock lasts 2 weeks (14 days); each run adds kanban / weekly use = 0.5 weeks: floor(14 + 3.5 i)
    assert [j["due_date"] for j in nx] == ["2026-11-11", "2026-11-14", "2026-11-18", "2026-11-21", "2026-11-25"]
    assert all(j["metres"] == 250 and j["status"] == "scheduled" for j in nx) and [j["run_no"] for j in nx] == [1, 2, 3, 4, 5]
    dx = [j for j in jobs if j["item"] == "DX-1C1.5"]
    assert [j["due_date"] for j in dx] == ["2026-10-28", "2026-11-04", "2026-11-11", "2026-11-18", "2026-11-25"]   # no stock: today
    assert run["jobs"] == len(jobs) == 10 and run["jobs_scheduled"] == 10


def test_mica_is_written_but_not_scheduled(built):
    run, con = built
    dm = rows(con)["DM-1C1.5"]
    assert dm["status"] == "release" and dm["release_runs"] == 4 and dm["release_m"] == 800 and dm["scheduled"] == 0
    assert dm["note"].endswith("not scheduled: taping not modelled")
    jobs = {j["item"] for j in con.execute("SELECT item FROM cs_job")}
    for item in ("NM-1C16", "MNX-1C16", "NM-1C95", "DM-1C1.5"):
        assert rows(con)[item]["scheduled"] == 0 and item not in jobs
    mica = {x["item"] for x in con.execute("SELECT item FROM cs_issue WHERE kind = 'mica_not_scheduled'")}
    assert {"DM-1C1.5", "NM-1C16", "NM-1C95"} <= mica
    assert con.execute("SELECT COUNT(*) FROM cs_restock WHERE mica = 1 AND scheduled = 1").fetchone()[0] == 0
    # released mica still counts in the totals
    assert run["release_items"] == 7 and run["release_runs"] == 31 and run["restock_items"] == 8


def test_items_without_a_build_tree_are_not_scheduled(built):
    _, con = built
    s = rows(con)["NX-4W.3"]
    assert s["status"] == "release" and s["scheduled"] == 0
    assert ("NX-4W.3", "no_build_tree") in issue_keys(con)
    assert "NX-4W.3" not in {j["item"] for j in con.execute("SELECT item FROM cs_job")}
    assert rows(con)["NX-1C16"]["scheduled"] == 1


def test_store_is_consistent(built):
    run, con = built
    assert con.execute("SELECT COUNT(*) FROM cs_machine").fetchone()[0] == 12
    assert con.execute("PRAGMA user_version").fetchone()[0] == 1
    assert con.execute("SELECT COUNT(*) FROM cs_run").fetchone()[0] == 1
    assert con.execute("PRAGMA foreign_key_check").fetchall() == []
    for k, t in (("ops", "cs_op"), ("passes", "cs_pass"), ("batches", "cs_batch")):
        assert run[k] == con.execute(f"SELECT COUNT(*) FROM {t}").fetchone()[0] > 0
    assert run["restock_items"] == con.execute("SELECT COUNT(*) FROM cs_restock").fetchone()[0]
    assert run["jobs"] == con.execute("SELECT COUNT(*) FROM cs_job").fetchone()[0]
    assert run["release_runs"] == con.execute("SELECT SUM(release_runs) FROM cs_restock").fetchone()[0]
    assert run["release_m"] == pytest.approx(con.execute("SELECT SUM(release_m) FROM cs_restock").fetchone()[0])
    assert run["settings_source"] == "seed" and len(run["settings_hash"]) == 16
    assert run["inventory_exported"] == "2026-10-26" and run["data_as_of"] == "2026-10-20"
    seed = json.loads((config.Path(config.__file__).parent / "seed" / "settings.json").read_text(encoding="utf-8"))
    assert {r["key"] for r in con.execute("SELECT key FROM cs_setting")} == {k for k in seed if not k.startswith("_")}
    assert json.loads(con.execute("SELECT value FROM cs_setting WHERE key = 'refill_weeks'").fetchone()[0]) == 3


def test_refill_weeks_setting_moves_the_trigger(tmp_path):
    over = tmp_path / "s.json"
    over.write_text(json.dumps({"refill_weeks": 1, "safety_weeks": 0.5}), encoding="utf-8")
    run, con = build(tmp_path, settings=over)
    r = rows(con)["NX-1C16"]
    assert r["trigger_m"] == 750 and r["status"] == "ok" and run["settings_source"] == "s.json"
    con.close()


def test_rod_cover_warning_follows_the_setting(tmp_path):
    _, con = build(tmp_path)
    assert not con.execute("SELECT 1 FROM cs_issue WHERE kind = 'rod_cover_low'").fetchall()   # 40 weeks of cover
    con.close()
    over = tmp_path / "s.json"
    over.write_text(json.dumps({"rod_warn_weeks": 50}), encoding="utf-8")
    sub = tmp_path / "b"
    sub.mkdir()
    _, con = build(sub, settings=over)
    row = con.execute("SELECT * FROM cs_issue WHERE kind = 'rod_cover_low'").fetchone()
    assert row["item"] == config.ROD_ITEM and row["severity"] == "warning" and "40.0 weeks" in row["detail"]
    con.close()


# ---- stock ----

def test_stock_conversion_purchased_items_and_qc(tmp_path):
    inv = [("NX-1C16", "MAN", 1000, 100, "m"), ("NX-1C16-S", "MAN", 0.5, 0.25, "t"), ("NX-1C16", "QC", 777, 0, "m"),
           ("NX-1R8-NEX", "MAN", 40000, 900000, "kg"), ("NX-1C50-S", "MAN", 2, 0, "t"), ("DX-1C6", "MAN", 100, 0, "kg")]
    with open(tmp_path / "inventory.csv", "w", newline="", encoding="utf-8-sig") as f:
        w = csv.writer(f)
        w.writerow(["Item number", "Warehouse", "Physical inventory", "Ordered in total", "Inventory unit"])
        w.writerows(inv)
    log = IssueLog()
    st = stock_mod.load(tmp_path, {"NX-1C16": 0.1361}, lambda b: b != "NX-1R8-NEX", log)
    s = st["NX-1C16"]
    assert s.physical == pytest.approx(1000 + 500 / 0.1361) and s.incoming == pytest.approx(100 + 250 / 0.1361)
    assert s.notes == ["incl. purchased NX-1C16-S"]
    assert st[config.ROD_ITEM].physical == 40000 and st[config.ROD_ITEM].incoming == 900000     # rod stays in kg
    assert "NX-1C50" not in st and "DX-1C6" not in st                                           # no kg/m: skipped
    assert {(i, k) for i, k, *_ in log.rows()} == {("NX-1C50", "inventory_unconverted"), ("DX-1C6", "inventory_unconverted")}


# ---- issue rates ----

def test_issue_rates_windows_flows_and_netting(tmp_path):
    cd_support.write_csv(tmp_path / "production-picking-list-lines.csv", source.FILES["production-picking-list-lines.csv"][0], [
        picking("CAB", "NX-1C16", 130, "2026-10-01"), picking("CAB", "NX-1C16", -30, "2026-10-02"),   # returns net
        picking("CAB", "NX-1C16", 10, "2026-10-03", scrap=5),                                            # scrap adds
        picking("CAB", "NX-1C16", 260, "2026-06-01"),            # 149 days: in 26w and 52w, not 13w
        picking("CAB", "NX-1C16", 520, "2026-01-01"),            # 300 days: 52w only
        picking("CAB", "NX-1C16", 999, "2025-10-29"),            # exactly 364 days back: out (window is (today - 364, today])
        picking("WIR", "NX-1W1.6", 100, "2026-10-01"),           # into a copper-making order: internal
        picking("WIR", "NX-1R8-NEX", 650, "2026-10-01", unit="kg"),
        picking("CAB", "AL-1C16", 100, "2026-10-01"),            # not copper
    ])
    orders = {"CAB": ("CABLE-A", "35_WIP"), "WIR": ("NX-1W1.6", "15_WIR")}
    r = rates.compute(tmp_path, {}, orders, TODAY)
    d = r[("NX-1C16", "m", "downstream")]
    assert d["13w"] == pytest.approx(115 / 13) and d["26w"] == pytest.approx((115 + 260) / 26)
    assert d["52w"] == pytest.approx((115 + 260 + 520) / 52)
    assert r[("NX-1W1.6", "m", "internal")]["13w"] == pytest.approx(100 / 13)
    assert r[("NX-1R8-NEX", "kg", "rod_in")]["13w"] == pytest.approx(50)
    assert not [k for k in r if k[0] == "AL-1C16"]
