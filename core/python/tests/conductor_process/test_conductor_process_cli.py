"""Command line, configuration, atomic swap and loud failures (as Cable routes contract 3.3, 4.1, 4.2)."""

import sqlite3

import pytest

from cable_routes.loader import REQUIRED
from conductor_process import config, store as store_mod
from conductor_process.cli import main
from conductor_process.source import EXTRA, HEADERS
from cd_support import Extract, factory, write_csv


def make_source(tmp_path):
    return factory().write(tmp_path / "dynamics")


def test_build_then_status(tmp_path, capsys):
    src = make_source(tmp_path)
    db = tmp_path / "var" / "x" / "store.sqlite"
    assert main(["build", "--source", str(src), "--db", str(db), "--quiet"]) == 0
    out = capsys.readouterr().out
    assert "items: 11" in out and "source_name: dynamics" in out and str(tmp_path) not in out
    assert db.is_file() and not list(db.parent.glob("*.tmp-*"))
    assert main(["status", "--db", str(db)]) == 0
    assert "excluded_off_map: 1" in capsys.readouterr().out
    con = sqlite3.connect(db)
    assert con.execute("PRAGMA journal_mode").fetchone()[0] in ("delete", "DELETE")
    assert con.execute("SELECT source_name FROM cd_run").fetchone()[0] == "dynamics"  # a basename, never a path


def test_build_logs_one_line_per_stage(tmp_path, capsys):
    assert main(["build", "--source", str(make_source(tmp_path)), "--db", str(tmp_path / "s.sqlite")]) == 0
    out = capsys.readouterr().out
    for word in ("reading dynamics", "model:", "explosion:", "machines:", "materials:", "left out: NX-1C500-AA"):
        assert word in out


def test_status_without_store_is_a_configuration_error(tmp_path):
    assert main(["status", "--db", str(tmp_path / "none.sqlite")]) == 2


def test_missing_source_is_exit_2(tmp_path, monkeypatch, capsys):
    monkeypatch.delenv("CONDUCTOR_PROCESS_SOURCE_DIR", raising=False)
    monkeypatch.setattr(config, "api_root", lambda: tmp_path)
    assert main(["build", "--db", str(tmp_path / "s.sqlite")]) == 2
    assert "CONDUCTOR_PROCESS_SOURCE_DIR" in capsys.readouterr().err
    assert main(["build", "--source", str(tmp_path / "nope"), "--db", str(tmp_path / "s.sqlite")]) == 2
    assert not (tmp_path / "s.sqlite").exists()


def test_bad_usage_is_exit_2():
    assert main(["bogus"]) == 2


def test_missing_file_is_exit_3_naming_the_file_and_writes_nothing(tmp_path, capsys):
    src = make_source(tmp_path)
    (src / "released-products.csv").unlink()
    db = tmp_path / "out" / "s.sqlite"
    assert main(["build", "--source", str(src), "--db", str(db), "--quiet"]) == 3
    assert "released-products.csv" in capsys.readouterr().err
    assert not db.exists() and not db.parent.exists()


@pytest.mark.parametrize("name,drop", [("production-bom-lines-raw.csv", "BOMLineQuantityDenominator"),
                                       ("manufactured-items.csv", "pool"),
                                       (HEADERS, "CsReceivedQuantity"),
                                       (HEADERS, "ReportedAsFinishedDate")])
def test_missing_column_is_exit_3_naming_file_and_column(tmp_path, capsys, name, drop):
    src = make_source(tmp_path)
    cols = REQUIRED[name] + (EXTRA[1:] if name == HEADERS else ())
    write_csv(src / name, [c for c in cols if c != drop], [])
    db = tmp_path / "s.sqlite"
    assert main(["build", "--source", str(src), "--db", str(db), "--quiet"]) == 3
    err = capsys.readouterr().err
    assert name in err and drop in err
    assert not db.exists()


def test_failed_build_leaves_the_old_store_and_no_temp_file(tmp_path):
    src = make_source(tmp_path)
    db = tmp_path / "s.sqlite"
    assert main(["build", "--source", str(src), "--db", str(db), "--quiet"]) == 0
    before = db.read_bytes()
    bad = {"cd_item": (("item", "pool", "stage", "prefix", "material", "mica", "form", "unit", "order_no",
                        "order_status", "order_date", "real_orders", "live_orders", "orders_52w", "traces_to_rod",
                        "step_count"), [("X", "P", "not_a_stage", "NX", "plain", 0, "core", "m", "W", "C", "D",
                                         1, 1, 0, 0, 1)])}
    with pytest.raises(sqlite3.IntegrityError):
        store_mod.write_atomic(db, bad)
    assert db.read_bytes() == before
    assert [p.name for p in tmp_path.glob("s.sqlite*")] == ["s.sqlite"]


def test_failed_foreign_key_check_leaves_the_old_store(tmp_path):
    db = tmp_path / "s.sqlite"
    store_mod.write_atomic(db, {})
    before = db.read_bytes()
    orphan = {"cd_operation": (("item", "seq", "operation_number", "operation", "machine"), [("NOPE", 1, 10, "OP", "M")])}
    with pytest.raises(RuntimeError, match="foreign_key_check"):
        store_mod.write_atomic(db, orphan)
    assert db.read_bytes() == before


def test_rebuild_replaces_the_store_in_full(tmp_path):
    db = tmp_path / "s.sqlite"
    assert main(["build", "--source", str(make_source(tmp_path)), "--db", str(db), "--quiet"]) == 0
    e = Extract()
    e.order("NX-1C4", "25_FCON", [("BUNCHING", "BU2")], [("NX-7W.3", 6)])
    e.write(tmp_path / "second")
    assert main(["build", "--source", str(tmp_path / "second"), "--db", str(db), "--quiet"]) == 0
    con = sqlite3.connect(db)
    assert [r[0] for r in con.execute("SELECT item FROM cd_item")] == ["NX-1C4"]
    assert con.execute("SELECT count(*) FROM cd_machine").fetchone()[0] == 15


def test_config_precedence_flag_env_dotenv_default(tmp_path):
    root = tmp_path / "api"
    root.mkdir()
    (root / ".env").write_text(
        "# comment\nCONDUCTOR_PROCESS_DB_PATH=from-dotenv.sqlite\nexport CONDUCTOR_PROCESS_SOURCE_DIR=\"~/dyn\"\n"
        "CABLE_ROUTES_DB_PATH=other.sqlite\n")
    assert config.resolve_db(None, {}, root) == root / "from-dotenv.sqlite"
    assert config.resolve_db(None, {"CONDUCTOR_PROCESS_DB_PATH": "env.sqlite"}, root) == root / "env.sqlite"
    assert config.resolve_db("flag.sqlite", {"CONDUCTOR_PROCESS_DB_PATH": "env.sqlite"}, root) == root / "flag.sqlite"
    assert config.resolve_db("/abs/x.sqlite", {}, root).as_posix().endswith("/abs/x.sqlite")
    assert config.resolve_db(None, {}, tmp_path / "empty") == \
        tmp_path / "empty" / "var/conductor/conductor-process.sqlite"
    assert config.read_dotenv(root)["CONDUCTOR_PROCESS_SOURCE_DIR"] == "~/dyn"
    assert "CABLE_ROUTES_DB_PATH" not in config.read_dotenv(root)
    src = tmp_path / "src"
    src.mkdir()
    assert config.resolve_source(None, {"CONDUCTOR_PROCESS_SOURCE_DIR": str(src)}, root) == src.resolve()
