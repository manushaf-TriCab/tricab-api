"""Settings: seed, override precedence, validation (exit 2) and the hash (contract 3)."""

import json

import pytest

from conductor_schedule import config, settings
from conductor_schedule.cli import main


def write(tmp_path, data, name="over.json"):
    p = tmp_path / name
    p.write_text(json.dumps(data), encoding="utf-8")
    return p


def test_seed_is_the_contract_defaults():
    eff, source, h = settings.load()
    assert source == "seed" and len(h) == 16
    assert eff["refill_weeks"] == 3 and eff["safety_weeks"] == 1 and eff["rate_window"] == "13w"
    assert eff["demand"] == "r86-order" and eff["r86_min_weeks"] == 2 and eff["rod_warn_weeks"] == 8
    assert eff["yields"] == {s: 1.0 for s in config.YIELD_STEPS}
    assert eff["shifts"] == {} and eff["efficiency"] == {} and eff["batch_sizes"] == {} and eff["min_levels"] == {}
    assert eff["shutdown"][0]["from"] == "2026-12-19" and eff["shutdown"][0]["to"] == "2027-01-02"
    assert not [k for k in eff if k.startswith("_")]          # example blocks are not settings


def test_override_replaces_top_level_keys_and_merges_yields(tmp_path):
    p = write(tmp_path, {"refill_weeks": 5, "yields": {"tinning": 0.9}, "shutdown": [], "_note": "ignored"})
    eff, source, h = settings.load(p)
    assert source == "over.json" and eff["refill_weeks"] == 5 and eff["safety_weeks"] == 1
    assert eff["yields"]["tinning"] == 0.9 and eff["yields"]["rod_break"] == 1.0
    assert eff["shutdown"] == [] and "_note" not in eff
    assert h != settings.load()[2]
    assert settings.load(p)[2] == h                            # stable


@pytest.mark.parametrize("data, needle", [
    ({"refill_weeks": -1}, "refill_weeks"),
    ({"safety_weeks": "1"}, "safety_weeks"),
    ({"safety_weeks": True}, "safety_weeks"),
    ({"rate_window": "10w"}, "rate_window"),
    ({"demand": "orders"}, "demand"),
    ({"r86_min_weeks": 0}, "r86_min_weeks"),
    ({"rod_warn_weeks": 0}, "rod_warn_weeks"),
    ({"yields": {"mica": 1.0}}, "unknown step"),
    ({"yields": {"tinning": 0}}, "yields.tinning"),
    ({"yields": {"tinning": 1.2}}, "yields.tinning"),
    ({"batch_sizes": {"NX-1C16": 100}}, "batch_sizes"),
    ({"batch_sizes": {"NX-1W1.6": -5}}, "batch_sizes.NX-1W1.6"),
    ({"min_levels": {"": 5}}, "min_levels"),
    ({"shifts": {"XX9": {"days": [0], "intervals": [["06:00", "14:00"]]}}}, "unknown machine"),
    ({"shifts": {"RB1": {"days": [], "intervals": [["06:00", "14:00"]]}}}, "shifts.RB1.days"),
    ({"shifts": {"RB1": {"days": [0, 0], "intervals": [["06:00", "14:00"]]}}}, "shifts.RB1.days"),
    ({"shifts": {"RB1": {"days": [7], "intervals": [["06:00", "14:00"]]}}}, "shifts.RB1.days"),
    ({"shifts": {"RB1": {"days": [0], "intervals": []}}}, "shifts.RB1.intervals"),
    ({"shifts": {"RB1": {"days": [0], "intervals": [["6:00", "14:00"]]}}}, "shifts.RB1.intervals"),
    ({"shifts": {"RB1": {"days": [0], "intervals": [["24:00", "14:00"]]}}}, "shifts.RB1.intervals"),
    ({"shifts": {"RB1": {"days": [0], "intervals": [["06:00", "06:00"]]}}}, "start equals end"),
    ({"shifts": {"RB1": {"days": [0], "intervals": [["06:00", "14:00"], ["13:00", "20:00"]]}}}, "overlap"),
    ({"shifts": {"RB1": {"days": [0], "intervals": [["22:00", "06:00"]]}}, "efficiency": {}}, None),   # valid overnight
    ({"efficiency": {"RB1": 0}}, "efficiency.RB1"),
    ({"efficiency": {"RB1": 1.5}}, "efficiency.RB1"),
    ({"efficiency": {"ZZ": 0.8}}, "unknown machine"),
    ({"shutdown": [{"from": "2026-12-20", "to": "2026-12-19"}]}, "from after to"),
    ({"shutdown": [{"from": "x", "to": "2026-12-19"}]}, "ISO dates"),
    ({"shutdwon": []}, "unknown key"),
])
def test_validation(tmp_path, data, needle):
    p = write(tmp_path, data)
    if needle is None:
        settings.load(p)
        return
    with pytest.raises(config.ConfigError) as e:
        settings.load(p)
    assert needle in str(e.value)


def test_overnight_shift_split_and_overlap_after_midnight():
    assert settings.split_intervals([0], [["22:00", "06:00"]]) == {0: [(1320, 1440)], 1: [(0, 360)]}
    assert settings.split_intervals([6], [["22:00", "00:00"]]) == {6: [(1320, 1440)]}
    # Monday 22:00-06:00 spills onto Tuesday 00:00-06:00, which a 05:00-10:00 shift overlaps.
    s, _, _ = settings.load()
    s["shifts"] = {"RB1": {"days": [0, 1], "intervals": [["22:00", "06:00"], ["05:00", "10:00"]]}}
    with pytest.raises(config.ConfigError, match="overlap"):
        settings._validate(s)


def test_missing_or_invalid_file_is_exit_2(tmp_path, source_dir, capsys):
    db = str(tmp_path / "s.sqlite")
    assert main(["build", "--source", str(source_dir), "--db", db, "--settings", str(tmp_path / "none.json")]) == 2
    bad = tmp_path / "bad.json"
    bad.write_text("{nope", encoding="utf-8")
    assert main(["build", "--source", str(source_dir), "--db", db, "--settings", str(bad)]) == 2
    assert "bad.json" in capsys.readouterr().err
    assert main(["build", "--source", str(source_dir), "--db", db, "--settings", str(write(tmp_path, {"demand": "x"}))]) == 2
    assert "demand" in capsys.readouterr().err
    assert not (tmp_path / "s.sqlite").exists()


def test_settings_path_precedence(tmp_path, monkeypatch):
    env = tmp_path / "env.json"
    env.write_text("{}", encoding="utf-8")
    flag = tmp_path / "flag.json"
    flag.write_text("{}", encoding="utf-8")
    dotenv = tmp_path / "dotenv.json"
    dotenv.write_text("{}", encoding="utf-8")
    (tmp_path / ".env").write_text(f"{config.ENV_SETTINGS}={dotenv}\n", encoding="utf-8")
    assert config.resolve_settings(None, {}, tmp_path) == dotenv
    assert config.resolve_settings(None, {config.ENV_SETTINGS: str(env)}, tmp_path) == env
    assert config.resolve_settings(str(flag), {config.ENV_SETTINGS: str(env)}, tmp_path) == flag
    assert config.resolve_settings(None, {}, tmp_path / "empty") is None
