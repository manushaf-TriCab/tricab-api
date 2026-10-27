"""Against the real D365 extract. Skipped unless CABLE_ROUTES_SOURCE_DIR names an extract folder.

The expected numbers come from the plan: 4,497 finished, 2,862 traced, 2,689 to_rod.
"""

import os
import sqlite3
from pathlib import Path

import pytest

from cable_routes.build import run_build
from cable_routes.loader import load

SOURCE = os.path.expanduser(os.environ.get("CABLE_ROUTES_SOURCE_DIR", ""))
pytestmark = pytest.mark.skipif(not (SOURCE and Path(SOURCE).is_dir()), reason="CABLE_ROUTES_SOURCE_DIR is not set to an extract folder")


@pytest.fixture(scope="module")
def src():
    return load(Path(SOURCE))


def build(src, tmp_path):
    db = tmp_path / "real.sqlite"
    run_build(src, Path(SOURCE).name, db)
    con = sqlite3.connect(db)
    con.row_factory = sqlite3.Row
    return con


@pytest.fixture(scope="module")
def con(src, tmp_path_factory):
    c = build(src, tmp_path_factory.mktemp("real"))
    yield c
    c.close()


def test_finished_and_traced_counts(con):
    run = con.execute("SELECT * FROM cr_run").fetchone()
    assert run["finished_total"] == 4497
    assert run["finished_traced"] == 2862
    assert con.execute("SELECT count(*) FROM cr_finished").fetchone()[0] == 4497
    assert run["untraceable_cutting_only"] + run["untraceable_no_orders"] == 1635
    assert run["data_as_of"] == "2026-09-04"


def test_to_rod(con):
    # 2,689 counts items with the placeholder pool "?" as made (contract rule 4); cable pools only would give 2,672.
    assert con.execute("SELECT trace_to_rod FROM cr_run").fetchone()[0] == 2689


def test_poster_contradictions_appear_in_family_route_mismatch(con):
    codes = {r[0] for r in con.execute("SELECT code FROM cr_check WHERE kind = 'family_route_mismatch'")}
    assert {"FC", "HT", "R2"} <= codes


def test_store_invariants(con):
    assert con.execute("SELECT count(*) FROM cr_need WHERE kind = 'finished'").fetchone()[0] == 2862
    assert con.execute("SELECT count(*) FROM (SELECT item FROM cr_operation GROUP BY item HAVING min(seq) <> 1 "
                       "OR max(seq) <> count(*))").fetchone()[0] == 0
    assert con.execute("SELECT count(*) FROM cr_input WHERE kind = 'material' AND input_item NOT IN "
                       "(SELECT item FROM cr_material)").fetchone()[0] == 0
    assert con.execute("PRAGMA foreign_key_check").fetchall() == []
