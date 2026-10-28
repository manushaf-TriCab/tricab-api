"""Against the real D365 extract. Skipped unless CONDUCTOR_PROCESS_SOURCE_DIR names an extract folder.

Expected facts were checked on the 2026-10-28 snapshot with the Cable routes engine (contract section 4).
"""

import os
import sqlite3
from pathlib import Path

import pytest

from conductor_process.build import run_build
from conductor_process.source import load
from test_conductor_process_invariants import assert_invariants

SOURCE = os.path.expanduser(os.environ.get("CONDUCTOR_PROCESS_SOURCE_DIR", ""))
pytestmark = pytest.mark.skipif(not (SOURCE and Path(SOURCE).is_dir()),
                                reason="CONDUCTOR_PROCESS_SOURCE_DIR is not set to an extract folder")


@pytest.fixture(scope="module")
def con(tmp_path_factory):
    src, extra = load(Path(SOURCE))
    db = tmp_path_factory.mktemp("real") / "real.sqlite"
    run_build(src, extra, Path(SOURCE).name, db)
    c = sqlite3.connect(db)
    c.row_factory = sqlite3.Row
    yield c
    c.close()


def test_invariants_and_15_machines(con):
    assert_invariants(con)
    assert con.execute("SELECT count(*) FROM cd_machine").fetchone()[0] == 15


def test_no_off_map_machine_leaks_in(con):
    for t in ("cd_operation", "cd_alt_machine", "cd_machine_operation"):
        leaked = con.execute(f"SELECT DISTINCT machine FROM {t} WHERE machine NOT IN "
                             "(SELECT machine FROM cd_machine)").fetchall()
        assert leaked == [], t
    assert con.execute("SELECT count(*) FROM cd_operation WHERE machine IN ('LU3', 'RB1', 'TL1')").fetchone()[0] == 0


def test_nx_1c16_traces_to_rod(con):
    assert con.execute("SELECT traces_to_rod FROM cd_item WHERE item = 'NX-1C16'").fetchone()[0] == 1
    chain = [tuple(r) for r in con.execute(
        "SELECT n.item, o.machine FROM cd_need n JOIN cd_operation o ON o.item = n.item "
        "WHERE n.root = 'NX-1C16' ORDER BY n.depth DESC")]
    assert chain == [("NX-1W1.6", "RB2"), ("NX-8W.3", "WD4"), ("NX-32W.3", "BU6"), ("NX-1C16", "BU1")]
    rod = con.execute("SELECT need_per_unit FROM cd_need WHERE root = 'NX-1C16' AND item = 'NX-1R8-NEX'").fetchone()[0]
    assert rod == pytest.approx(0.1361, abs=0.0005)


def test_lu3_items(con):
    assert [r[0] for r in con.execute("SELECT DISTINCT machine FROM cd_operation WHERE item IN ('NX-1C500', 'NX-1C630')")] \
        == ["ST1"]
    assert con.execute("SELECT count(*) FROM cd_item WHERE item = 'NX-1C500-AA'").fetchone()[0] == 0
    assert 1 <= con.execute("SELECT excluded_off_map FROM cd_run").fetchone()[0] <= 5


def test_item_count(con):
    assert 150 <= con.execute("SELECT items FROM cd_run").fetchone()[0] <= 170
