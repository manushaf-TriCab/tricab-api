"""Command line, exit codes, configuration precedence, source checks, atomic swap, copy tool."""

import csv
import sqlite3
import sys
from pathlib import Path

import pytest

from conductor_schedule import config, store as store_mod
from conductor_schedule.cli import main
from conductor_schedule.source import FILES
from cs_support import make_source

TOOLS = Path(__file__).resolve().parents[2] / "tools"


def args(tmp_path, folder, *extra):
    return ["build", "--source", str(folder), "--db", str(tmp_path / "var" / "s.sqlite"), "--today", "2026-10-28", *extra]


def test_build_then_status(tmp_path, source_dir, capsys):
    assert main(args(tmp_path, source_dir, "--quiet")) == 0
    out = capsys.readouterr().out
    assert "restock_items: 8" in out and "source_name: cs-source" in out and str(tmp_path) not in out
    db = tmp_path / "var" / "s.sqlite"
    assert db.is_file() and not list(db.parent.glob("*.tmp-*"))
    assert main(["status", "--db", str(db)]) == 0
    assert "r86_order_file: R-86_ORD_MTD_2610" in capsys.readouterr().out
    con = sqlite3.connect(db)
    assert con.execute("PRAGMA journal_mode").fetchone()[0].lower() == "delete"
    con.close()


def test_build_logs_stages(tmp_path, source_dir, capsys):
    assert main(args(tmp_path, source_dir)) == 0
    out = capsys.readouterr().out
    for word in ("reading cs-source", "r86 order:", "process tree", "issue rates", "restock: 8 items, 10 jobs"):
        assert word in out


def test_status_without_store_is_exit_2(tmp_path):
    assert main(["status", "--db", str(tmp_path / "none.sqlite")]) == 2


def test_missing_source_and_bad_today_are_exit_2(tmp_path, source_dir, monkeypatch, capsys):
    monkeypatch.delenv(config.ENV_SOURCE, raising=False)
    monkeypatch.setattr(config, "api_root", lambda: tmp_path)
    assert main(["build", "--db", str(tmp_path / "s.sqlite")]) == 2
    assert config.ENV_SOURCE in capsys.readouterr().err
    assert main(["build", "--source", str(tmp_path / "nope"), "--db", str(tmp_path / "s.sqlite")]) == 2
    assert main(args(tmp_path, source_dir) + ["--today", "28/10/2026"]) == 2
    assert not (tmp_path / "s.sqlite").exists()
    assert main(["nonsense"]) == 2


def test_environment_and_dotenv_precedence(tmp_path):
    (tmp_path / ".env").write_text(f"{config.ENV_DB}=from-dotenv.sqlite\n{config.ENV_SOURCE}='{tmp_path}'\n", encoding="utf-8")
    assert config.resolve_db(None, {}, tmp_path) == tmp_path / "from-dotenv.sqlite"
    assert config.resolve_db(None, {config.ENV_DB: "env.sqlite"}, tmp_path) == tmp_path / "env.sqlite"
    assert config.resolve_db("flag.sqlite", {config.ENV_DB: "env.sqlite"}, tmp_path) == tmp_path / "flag.sqlite"
    assert config.resolve_db(None, {}, tmp_path / "empty") == tmp_path / "empty" / config.DEFAULT_DB
    assert config.resolve_source(None, {}, tmp_path) == tmp_path.resolve()


def test_missing_column_is_exit_3_and_names_file_and_column(tmp_path, source_dir, capsys):
    path = source_dir / "production-picking-list-lines.csv"
    lines = path.read_text(encoding="utf-8").splitlines()
    lines[0] = lines[0].replace("ScrapBOMQuantity,", "")
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")
    assert main(args(tmp_path, source_dir, "--quiet")) == 3
    assert "production-picking-list-lines.csv: missing column ScrapBOMQuantity" in capsys.readouterr().err
    assert not (tmp_path / "var" / "s.sqlite").exists()


@pytest.mark.parametrize("name", sorted(FILES))
def test_every_required_file_is_checked(tmp_path, source_dir, name, capsys):
    (source_dir / name).unlink()
    assert main(args(tmp_path, source_dir, "--quiet")) == 3
    assert f"missing file {name}" in capsys.readouterr().err


@pytest.mark.parametrize("name", sorted(FILES))
def test_every_required_column_is_checked(tmp_path, source_dir, name, capsys):
    col = FILES[name][0][-1]
    p = source_dir / name
    with open(p, newline="", encoding="utf-8-sig") as f:
        rows = list(csv.reader(f))
    k = rows[0].index(col)
    with open(p, "w", newline="", encoding="utf-8") as f:
        csv.writer(f).writerows([r[:k] + r[k + 1:] for r in rows])
    assert main(args(tmp_path, source_dir, "--quiet")) == 3
    assert f"{name}: missing column {col}" in capsys.readouterr().err


def test_missing_r86_file_is_exit_3(tmp_path, source_dir, capsys):
    (source_dir / "r86" / "R-86_INV_MTD_2610").unlink()
    assert main(args(tmp_path, source_dir, "--quiet")) == 3
    assert "no R-86 invoice" in capsys.readouterr().err


def test_headers_are_all_checked_before_the_slow_read(tmp_path, source_dir, monkeypatch):
    from conductor_schedule import rates
    monkeypatch.setattr(rates, "compute", lambda *a, **k: pytest.fail("a slow read ran before the header checks"))
    (source_dir / "work-calendars.csv").write_text("CalendarId\n", encoding="utf-8")
    assert main(args(tmp_path, source_dir, "--quiet")) == 3


def test_failed_build_leaves_the_old_store_and_no_temp_file(tmp_path, source_dir, monkeypatch, capsys):
    assert main(args(tmp_path, source_dir, "--quiet")) == 0
    db = tmp_path / "var" / "s.sqlite"
    before = db.read_bytes()
    real = sqlite3.connect

    def boom(*a, **k):
        raise RuntimeError("disk full")

    monkeypatch.setattr(store_mod.sqlite3, "connect", boom)
    assert main(args(tmp_path, source_dir, "--quiet")) == 1
    monkeypatch.setattr(store_mod.sqlite3, "connect", real)
    assert "build failed" in capsys.readouterr().err
    assert db.read_bytes() == before and not list(db.parent.glob("*.tmp-*"))


def test_copy_tool_builds_a_folder_the_engine_accepts(tmp_path, capsys):
    sys.path.insert(0, str(TOOLS))
    try:
        import make_schedule_source as tool
    finally:
        sys.path.remove(str(TOOLS))
    src = make_source(tmp_path, "origin")
    snap, dyn, inv = tmp_path / "snap", tmp_path / "dyn", tmp_path / "inv"
    for d in (snap, dyn, inv):
        d.mkdir()
    for n in tool.SNAPSHOT_FILES:
        (snap / n).write_bytes((src / n).read_bytes())
    for n in tool.DYNAMICS_FILES:
        (dyn / n).write_bytes((src / n).read_bytes())
    (inv / "full-inventory.csv").write_bytes((src / "inventory.csv").read_bytes())
    (inv / "summary.json").write_bytes((src / "inventory-meta.json").read_bytes())
    dest = tmp_path / "dest"
    base = [str(dest), "--dynamics", str(dyn), "--snapshot", str(snap), "--inventory", str(inv), "--r86", str(src / "r86")]
    assert tool.main(base) == 0
    out = capsys.readouterr().out
    assert "r86 order: R-86_ORD_MTD_2610 printed 2026-10-29T20:01:57" in out and (dest / "r86" / "R-86_INV_MTD_2610").is_file()
    assert (dest / "inventory.csv").is_file() and (dest / "inventory-meta.json").is_file()
    assert main(args(tmp_path, dest, "--quiet")) == 0
    # existing files are kept unless --force
    (dest / "work-calendars.csv").write_text("x", encoding="utf-8")
    assert tool.main(base) == 3 and "kept     work-calendars.csv" in capsys.readouterr().out
    assert tool.main(base + ["--force"]) == 0
    # a source column missing: exit 3; a missing source file: exit 3 and nothing written; a bad folder: exit 2
    (snap / "released-products.csv").write_text("ItemNumber\n", encoding="utf-8")
    assert tool.main([str(tmp_path / "d2")] + base[1:]) == 3
    assert tool.main([str(tmp_path / "d3"), "--dynamics", str(tmp_path / "nope")] + base[3:]) == 2
    (snap / "production-order-headers.csv").unlink()
    assert tool.main([str(tmp_path / "d4")] + base[1:]) == 3
    assert not (tmp_path / "d4").exists()
