"""Command line, configuration, atomic swap and loud failures."""

import sqlite3

import pytest

from cable_routes import config, store as store_mod
from cable_routes.cli import main
from cable_routes.loader import REQUIRED
from cr_support import Extract, worked_example, write_csv


def make_source(tmp_path):
    return worked_example().write(tmp_path / "dynamics")


def test_build_then_status(tmp_path, capsys):
    src = make_source(tmp_path)
    db = tmp_path / "var" / "x" / "store.sqlite"
    assert main(["build", "--source", str(src), "--db", str(db), "--quiet"]) == 0
    out = capsys.readouterr().out
    assert "finished_total: 1" in out and "source_name: dynamics" in out and str(tmp_path) not in out
    assert db.is_file() and not list(db.parent.glob("*.tmp-*"))
    assert main(["status", "--db", str(db)]) == 0
    assert "trace_to_rod: 1" in capsys.readouterr().out
    con = sqlite3.connect(db)
    assert con.execute("PRAGMA journal_mode").fetchone()[0] in ("delete", "DELETE")
    assert con.execute("SELECT source_name FROM cr_run").fetchone()[0] == "dynamics"  # a basename, never a path


def test_status_without_store_is_a_configuration_error(tmp_path, capsys):
    assert main(["status", "--db", str(tmp_path / "none.sqlite")]) == 2


def test_missing_source_is_exit_2(tmp_path, monkeypatch, capsys):
    monkeypatch.delenv("CABLE_ROUTES_SOURCE_DIR", raising=False)
    monkeypatch.setattr(config, "api_root", lambda: tmp_path)
    assert main(["build", "--db", str(tmp_path / "s.sqlite")]) == 2
    assert "CABLE_ROUTES_SOURCE_DIR" in capsys.readouterr().err
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
                                       ("production-order-headers-raw.csv", "CsIsCuttingOrder")])
def test_missing_column_is_exit_3_naming_file_and_column(tmp_path, capsys, name, drop):
    src = make_source(tmp_path)
    cols = [c for c in REQUIRED[name] if c != drop]
    write_csv(src / name, cols, [])
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
    bad = {"cr_check": (("kind", "detail"), [("not_a_kind", "x")])}  # violates the CHECK constraint
    with pytest.raises(sqlite3.IntegrityError):
        store_mod.write_atomic(db, bad)
    assert db.read_bytes() == before
    assert [p.name for p in tmp_path.glob("s.sqlite*")] == ["s.sqlite"]


def test_failed_foreign_key_check_leaves_the_old_store(tmp_path):
    db = tmp_path / "s.sqlite"
    store_mod.write_atomic(db, {})
    before = db.read_bytes()
    orphan = {"cr_operation": (("item", "seq", "operation_number", "operation", "machine"), [("NOPE", 1, 10, "OP", "M")])}
    with pytest.raises(RuntimeError, match="foreign_key_check"):
        store_mod.write_atomic(db, orphan)
    assert db.read_bytes() == before


def test_rebuild_replaces_the_store_in_full(tmp_path):
    db = tmp_path / "s.sqlite"
    src = make_source(tmp_path)
    assert main(["build", "--source", str(src), "--db", str(db), "--quiet"]) == 0
    e = Extract()
    e.order("F-X", "50_FMAN", [("SHEATHING", "EX4")], [("MAT", 1, "kg")])
    e.write(tmp_path / "second")
    assert main(["build", "--source", str(tmp_path / "second"), "--db", str(db), "--quiet"]) == 0
    con = sqlite3.connect(db)
    assert [r[0] for r in con.execute("SELECT item FROM cr_finished")] == ["F-X"]


def test_config_precedence_flag_env_dotenv_default(tmp_path):
    root = tmp_path / "api"
    root.mkdir()
    (root / ".env").write_text(
        "# comment\nCABLE_ROUTES_DB_PATH=from-dotenv.sqlite\nexport CABLE_ROUTES_SOURCE_DIR=\"~/dyn\"\nOTHER=1\n")
    assert config.resolve_db(None, {}, root) == root / "from-dotenv.sqlite"
    assert config.resolve_db(None, {"CABLE_ROUTES_DB_PATH": "env.sqlite"}, root) == root / "env.sqlite"
    assert config.resolve_db("flag.sqlite", {"CABLE_ROUTES_DB_PATH": "env.sqlite"}, root) == root / "flag.sqlite"
    assert config.resolve_db("/abs/x.sqlite", {}, root).as_posix().endswith("/abs/x.sqlite")
    assert config.resolve_db(None, {}, tmp_path / "empty") == tmp_path / "empty" / "var/cable-routes/cable-routes.sqlite"
    assert config.read_dotenv(root)["CABLE_ROUTES_SOURCE_DIR"] == "~/dyn"
    assert "OTHER" not in config.read_dotenv(root)
