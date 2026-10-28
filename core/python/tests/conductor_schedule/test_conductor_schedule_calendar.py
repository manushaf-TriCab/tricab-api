"""Calendars: Dynamics weekly pattern, shift override incl. midnight crossing, efficiency, shutdown (contract 4.6)."""

import json
from datetime import datetime, timedelta

import pytest

from conductor_schedule import config
from conductor_schedule import schedule as sched
from conductor_schedule.calendar import MachineCalendar, make_calendars, override_pattern
from conductor_schedule.history import Calendars, week_pattern
from conductor_schedule.issues import IssueLog
from cs_support import MACHINES, build, cal, d, kinds


def settings(**over):
    s = {"shifts": {}, "efficiency": {}, "shutdown": []}
    s.update(over)
    return s


def dynamics(intervals):
    """Calendars with every machine on base BASE and the given 19-25 Oct 2026 intervals {iso date: [(s, e, eff)]}."""
    return Calendars({m: "BASE" for m in MACHINES}, {m: "Machine " + m for m in MACHINES}, {"BASE": intervals})


WEEK = {f"2026-10-{n}": [(6 * 3600, 22 * 3600, 1.0)] for n in range(19, 24)}      # Mon-Fri 06:00-22:00


# ------------------------------------------------------------------ run / productive hours

def test_run_consumes_only_working_intervals_across_weekend():
    st, en = cal().run(datetime(2026, 10, 9, 14), 5.0)       # Fri 14:00: 2 h Fri, 3 h Mon
    assert st == datetime(2026, 10, 9, 14) and en == datetime(2026, 10, 12, 11)


def test_run_starts_at_next_working_instant():
    st, en = cal().run(datetime(2026, 10, 10, 9), 1.0)       # Saturday
    assert st == datetime(2026, 10, 12, 8) and en == datetime(2026, 10, 12, 9)


def test_shutdown_days_are_skipped():
    c = cal(shutdown=[(d("2026-12-19"), d("2027-01-02"))])
    st, en = c.run(datetime(2026, 12, 18, 15), 3.0)          # Fri 1 h, shutdown to Sat 2 Jan, Mon 4 Jan 2 h
    assert en == datetime(2027, 1, 4, 10)
    assert c.productive_hours(datetime(2026, 12, 21), datetime(2027, 1, 2)) == 0


def test_efficiency_stretches_wall_time():
    c = MachineCalendar({0: [(8 * 3600, 16 * 3600, 0.5)]})      # Mondays only, 50%: 4 productive h per day
    st, en = c.run(datetime(2026, 10, 12, 8), 6.0)              # 4 h Monday, 2 h (=4 wall h) the next Monday
    assert en == datetime(2026, 10, 19, 12)
    assert c.hours_per_week() == 4.0


def test_zero_work_returns_first_working_instant():
    st, en = cal().run(datetime(2026, 10, 10, 9), 0.0)
    assert st == en == datetime(2026, 10, 12, 8)


def test_week_pattern_uses_last_full_week_before_today():
    days = {"2026-10-05": [(0, 3600, 1.0)], "2026-10-06": [(0, 7200, 1.0)], "2026-10-12": [(0, 999, 1.0)]}
    pat, monday = week_pattern(days, d("2026-10-13"))           # today is Tue 13 Oct; last full week starts 5 Oct
    assert monday == "2026-10-05" and sorted(pat) == [0, 1]


def test_week_pattern_looks_further_back_when_the_last_week_is_empty():
    pat, monday = week_pattern({"2026-09-29": [(0, 3600, 1.0)]}, d("2026-10-13"))
    assert monday == "2026-09-28" and sorted(pat) == [1]
    assert week_pattern({}, d("2026-10-13")) == ({}, None)


def test_late_days():
    assert sched.late_days(datetime(2026, 10, 20, 10), "2026-10-18") == 2
    assert sched.late_days(datetime(2026, 10, 20, 10), "2026-10-25") == 0
    assert sched.late_days(datetime(2026, 10, 20, 0, 0), "2026-10-19") == 0      # ended at midnight: previous day


# ------------------------------------------------------------------ Dynamics default

def test_dynamics_pattern_and_hours_per_week_and_issue():
    issues = IssueLog()
    cals, meta = make_calendars(dynamics(WEEK), d("2026-10-28"), settings(), issues)
    assert set(cals) == set(config.MACHINES) and cals["RB1"].hours_per_week() == 80.0
    assert meta["RB1"] == {"name": "Machine RB1", "calendar": "BASE", "source": "dynamics", "efficiency": 1.0}
    assert ("BASE", "calendar_pattern") in kinds(issues) and not any(k == "shift_override" for _, k in kinds(issues))
    assert cals["WD4"].intervals(d("2026-10-28"))[0][:2] == (datetime(2026, 10, 28, 6), datetime(2026, 10, 28, 22))
    assert cals["WD4"].intervals(d("2026-10-31")) == []                                  # Saturday


def test_dynamics_interval_efficiency_is_kept():
    cals, _ = make_calendars(dynamics({"2026-10-19": [(0, 36000, 0.5)]}), d("2026-10-28"), settings(), IssueLog())
    assert cals["RB1"].hours_per_week() == 5.0


def test_no_calendar_row_or_no_intervals_is_an_error():
    from conductor_schedule.calendar import CalendarError
    with pytest.raises(CalendarError):
        make_calendars(Calendars(), d("2026-10-28"), settings(), IssueLog())
    with pytest.raises(CalendarError):
        make_calendars(dynamics({}), d("2026-10-28"), settings(), IssueLog())


# ------------------------------------------------------------------ shift override

def test_override_interval_across_midnight_goes_on_the_next_day():
    # Thu 22:00-06:00: 22:00-24:00 on Thursday and 00:00-06:00 on Friday
    pat = override_pattern([3], [["22:00", "06:00"]])
    assert pat == {3: [(22 * 3600, 86400, 1.0)], 4: [(0, 6 * 3600, 1.0)]}


def test_override_sunday_wraps_to_monday_and_end_at_midnight_has_no_next_day_part():
    assert override_pattern([6], [["22:00", "06:00"]]) == {6: [(22 * 3600, 86400, 1.0)], 0: [(0, 6 * 3600, 1.0)]}
    assert override_pattern([2], [["18:00", "00:00"]]) == {2: [(18 * 3600, 86400, 1.0)]}


def test_override_two_shifts_on_four_days():
    pat = override_pattern([0, 1, 2, 3], [["14:00", "22:00"], ["22:00", "06:00"]])
    assert pat[0] == [(14 * 3600, 22 * 3600, 1.0), (22 * 3600, 86400, 1.0)]
    assert pat[1] == [(0, 6 * 3600, 1.0), (14 * 3600, 22 * 3600, 1.0), (22 * 3600, 86400, 1.0)]
    assert pat[4] == [(0, 6 * 3600, 1.0)] and 5 not in pat
    assert sum((e - s) / 3600 for v in pat.values() for s, e, _ in v) == 4 * 16


def test_override_machine_uses_its_own_pattern_and_logs_one_issue():
    s = settings(shifts={"WD4": {"days": [3], "intervals": [["22:00", "06:00"]]}})
    issues = IssueLog()
    cals, meta = make_calendars(dynamics(WEEK), d("2026-10-28"), s, issues)
    assert meta["WD4"]["source"] == "override" and meta["WD4"]["calendar"] == "BASE" and meta["WD5"]["source"] == "dynamics"
    assert cals["WD4"].hours_per_week() == 8.0 and cals["WD5"].hours_per_week() == 80.0
    assert ("WD4", "shift_override") in kinds(issues) and ("WD5", "shift_override") not in kinds(issues)
    # Thursday 29 Oct 22:00 to Friday 30 Oct 06:00: eight working hours across midnight
    st, en = cals["WD4"].run(datetime(2026, 10, 28, 6), 8.0)
    assert (st, en) == (datetime(2026, 10, 29, 22), datetime(2026, 10, 30, 6))
    st, en = cals["WD4"].run(datetime(2026, 10, 28, 6), 9.0)          # one more hour: next Thursday 22:00-23:00
    assert en == datetime(2026, 11, 5, 23)


def test_override_machine_needs_no_dynamics_calendar():
    s = settings(shifts={m: {"days": [0], "intervals": [["08:00", "16:00"]]} for m in config.MACHINES})
    cals, meta = make_calendars(Calendars(), d("2026-10-28"), s, IssueLog())
    assert all(v["source"] == "override" and v["name"] == m for m, v in meta.items())


def test_shutdown_applies_to_override_and_dynamics_alike():
    s = settings(shifts={"WD4": {"days": [0], "intervals": [["08:00", "16:00"]]}},
                 shutdown=[{"from": "2026-10-29", "to": "2026-11-02"}])
    cals, _ = make_calendars(dynamics(WEEK), d("2026-10-28"), s, IssueLog())
    assert cals["WD4"].run(datetime(2026, 10, 28, 6), 1.0)[0] == datetime(2026, 11, 9, 8)    # Mon 2 Nov is shut down
    assert cals["WD5"].run(datetime(2026, 10, 29, 6), 1.0)[0] == datetime(2026, 11, 3, 6)


# ------------------------------------------------------------------ efficiency

def test_machine_efficiency_multiplies_every_interval_and_is_stored():
    s = settings(efficiency={"WD4": 0.8})
    issues = IssueLog()
    cals, meta = make_calendars(dynamics(WEEK), d("2026-10-28"), s, issues)
    assert meta["WD4"]["efficiency"] == 0.8 and meta["WD5"]["efficiency"] == 1.0
    assert cals["WD4"].hours_per_week() == pytest.approx(64.0) and cals["WD5"].hours_per_week() == 80.0
    assert ("WD4", "efficiency_applied") in kinds(issues) and ("WD5", "efficiency_applied") not in kinds(issues)
    # 8 productive hours take 10 wall hours at 80%
    st, en = cals["WD4"].run(datetime(2026, 10, 28, 6), 8.0)
    assert en - st == timedelta(hours=10)


def test_efficiency_stacks_on_dynamics_interval_efficiency_and_on_overrides():
    s = settings(efficiency={"RB1": 0.5, "TL1": 0.5}, shifts={"TL1": {"days": [0], "intervals": [["08:00", "16:00"]]}})
    cals, _ = make_calendars(dynamics({"2026-10-19": [(0, 36000, 0.5)]}), d("2026-10-28"), s, IssueLog())
    assert cals["RB1"].hours_per_week() == 2.5 and cals["TL1"].hours_per_week() == 4.0


# ------------------------------------------------------------------ through a build

def over_file(tmp_path, body):
    p = tmp_path / "over.json"
    p.write_text(json.dumps(body), encoding="utf-8")
    return p


def test_build_stores_dynamics_machines_with_fixture_calendar(built):
    _, con = built
    rows = {r["machine"]: r for r in con.execute("SELECT * FROM cs_machine ORDER BY stage_order")}
    assert list(rows) == list(config.MACHINES) and [r["stage_order"] for r in rows.values()] == list(range(12))
    assert rows["RB1"]["poster_name"] == "RB2" and rows["TL1"]["poster_name"] == "TN1" and rows["WD4"]["poster_name"] == "WD4"
    assert all(r["calendar_source"] == "dynamics" and r["efficiency"] == 1.0 and r["hours_per_week"] == 80.0
               for r in rows.values())
    assert rows["BU1"]["name"] == "Machine BU1" and rows["BU1"]["calendar"] == "BASE"


def test_build_with_shift_override_and_efficiency(tmp_path):
    over = over_file(tmp_path, {"shifts": {"BU1": {"days": [0, 1, 2, 3], "intervals": [["14:00", "22:00"], ["22:00", "06:00"]]}},
                                "efficiency": {"BU2": 0.5, "BU1": 0.5}})
    run, con = build(tmp_path, settings=over)
    m = {r["machine"]: r for r in con.execute("SELECT * FROM cs_machine")}
    assert m["BU1"]["calendar_source"] == "override" and m["BU1"]["efficiency"] == 0.5
    assert m["BU1"]["hours_per_week"] == pytest.approx(4 * 16 * 0.5)
    assert m["BU2"]["calendar_source"] == "dynamics" and m["BU2"]["hours_per_week"] == pytest.approx(40.0)
    issues = {(r["item"], r["kind"]) for r in con.execute("SELECT item, kind FROM cs_issue")}
    assert ("BU1", "shift_override") in issues and ("BU1", "efficiency_applied") in issues and ("BU2", "efficiency_applied") in issues
    # BU1 only works in its override windows (Mon-Thu 14:00 to Fri 06:00); all its passes start inside them
    for p in con.execute("SELECT start_at, end_at FROM cs_pass WHERE machine = 'BU1' AND batch_id IS NULL"):
        s = datetime.fromisoformat(p["start_at"])
        assert (s.weekday() in (0, 1, 2, 3) and s.hour >= 14) or (s.weekday() in (1, 2, 3, 4) and s.hour < 6)
    cap = con.execute("SELECT available_h FROM cs_capacity WHERE machine = 'BU1' AND week = '2026-11-02'").fetchone()[0]
    assert cap == pytest.approx(32.0)


def test_shutdown_setting_moves_the_schedule(tmp_path):
    over = over_file(tmp_path, {"shutdown": [{"from": "2026-10-28", "to": "2026-11-01"}]})
    _, con = build(tmp_path, settings=over)
    first = con.execute("SELECT MIN(start_at) FROM cs_op").fetchone()[0]
    assert first >= "2026-11-02T06:00"
    assert con.execute("SELECT SUM(available_h) FROM cs_capacity WHERE week = '2026-10-26'").fetchone()[0] == 0
