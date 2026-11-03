"""The unattended refresh: D365 extract, validation, atomic swap, off-by-default switch."""

import io
import json
import sqlite3
import urllib.error
from urllib.parse import parse_qs, urlparse

import pytest

from cable_routes import d365, refresh as rf
from cable_routes.cli import main
from cr_support import Extract, worked_example


class FakeD365:
    """Stands in for urlopen: serves an Extract as OData pages of 2 rows, with a nextLink."""

    def __init__(self, ex: Extract, fail=None):
        self.ex, self.fail, self.calls, self.auth = ex, list(fail or []), [], set()
        self.data = {
            "ProductionOrderHeaders": [dict(h, ProductionPoolId=ex.pools[h["ItemNumber"]]) for h in ex.headers], "ProductionOrderBillOfMaterialLines": ex.bom,
            "ProductionOrderRouteOperations": ex.route, "ReleasedProductsV2": list(ex.products.values()),
            "PurchaseOrderLinesV2": ex.po}

    def __call__(self, req, timeout=0):
        url = req.full_url
        self.calls.append(url)
        self.auth.add(req.get_header("Authorization"))
        if self.fail:
            code = self.fail.pop(0)
            raise urllib.error.HTTPError(url, code, "x", {}, io.BytesIO(b"{}"))
        u = urlparse(url)
        entity = u.path.rsplit("/", 1)[1]
        q = parse_qs(u.query)
        assert q["$filter"] == ["dataAreaId eq 'AU1O'"] and q["cross-company"] == ["true"]
        cols = q["$select"][0].split(",")
        skip = int(q.get("skip", ["0"])[0])
        rows = self.data[entity][skip:skip + 2]
        body = {"value": [{c: r.get(c) for c in cols} for r in rows]}
        if skip + 2 < len(self.data[entity]):
            body["@odata.nextLink"] = f"{url.split('&skip=')[0]}&skip={skip + 2}"
        return io.BytesIO(json.dumps(body).encode())


def client(fake, **kw):
    return d365.Client(environ={"D365_TOKEN": "tok"}, sleep=lambda s: None, opener=fake, **kw)


def test_extract_pages_and_matches_a_local_extract(tmp_path):
    ex = worked_example()
    fake = FakeD365(ex)
    counts = d365.extract(tmp_path / "x", client(fake))
    assert counts["production-order-headers-raw.csv"] == len(ex.headers) > 2
    assert counts["manufactured-items.csv"] == len(ex.pools)
    assert fake.auth == {"Bearer tok"}
    # Same store from the pulled folder as from the folder the test builder writes.
    a, b = tmp_path / "a.sqlite", tmp_path / "b.sqlite"
    local = ex.write(tmp_path / "local")
    assert main(["build", "--source", str(local), "--db", str(a), "--quiet"]) == 0
    assert main(["build", "--source", str(tmp_path / "x"), "--db", str(b), "--quiet"]) == 0
    q = "SELECT item, pool, trace_status, step_count FROM cr_finished ORDER BY item"
    assert sqlite3.connect(a).execute(q).fetchall() == sqlite3.connect(b).execute(q).fetchall()


def test_retries_a_blip_then_gives_up_and_refuses_403(tmp_path):
    ex = worked_example()
    assert d365.extract(tmp_path / "ok", client(FakeD365(ex, fail=[503, 429]))) is not None
    with pytest.raises(d365.D365Error, match="5 tries"):
        d365.extract(tmp_path / "bad", client(FakeD365(ex, fail=[503] * 9)))
    with pytest.raises(d365.D365Error, match="read access to ProductionOrderHeaders"):
        d365.extract(tmp_path / "no", client(FakeD365(ex, fail=[403])))


def test_no_credentials_is_a_clear_error():
    with pytest.raises(d365.D365Error, match="no Dynamics credentials"):
        d365.get_token("https://x", {})


def test_pool_rule():
    h = lambda n, item, pool, date: {"ProductionOrderNumber": n, "ItemNumber": item, "ProductionPoolId": pool,
                                     "ScheduledDate": date}
    got = d365.pool_by_item([h("2", "A", "", "2025"), h("1", "A", "35_WIP", "2024"), h("3", "A", "40_SFG", "2026"),
                             h("4", "B", "", "2025")])
    assert got == {"A": "40_SFG", "B": "?"}


def run_refresh(db, source=None, **kw):
    out = []
    code = rf.run(db, source, kw.pop("if_enabled", False), kw.pop("allow_shrink", False), out.append, **kw)
    return code, out


def test_first_refresh_creates_the_store_and_logs_a_summary(tmp_path):
    db = tmp_path / "var" / "cr.sqlite"
    code, out = run_refresh(db, client=client(FakeD365(worked_example())))
    assert code == 0 and db.is_file()
    assert any("refresh ok" in l and "1 finished" in l for l in out) and any("no previous store" in l for l in out)
    assert sorted(p.name for p in db.parent.iterdir()) == ["cr.sqlite", "cr.sqlite.lock"]


def test_off_by_default_and_on_with_the_setting(tmp_path):
    db = tmp_path / "cr.sqlite"
    assert run_refresh(db, if_enabled=True, environ={}, client=client(FakeD365(worked_example())))[0] == 3
    assert not db.exists()
    assert run_refresh(db, if_enabled=True, environ={rf.ENV_ENABLED: "false"})[0] == 3
    code, _ = run_refresh(db, if_enabled=True, environ={rf.ENV_ENABLED: "true"},
                          client=client(FakeD365(worked_example())))
    assert code == 0 and db.is_file()


def test_second_refresh_keeps_the_previous_store(tmp_path):
    db = tmp_path / "cr.sqlite"
    run_refresh(db, client=client(FakeD365(worked_example())))
    code, out = run_refresh(db, client=client(FakeD365(worked_example())))
    assert code == 0 and (tmp_path / "cr.sqlite.prev").is_file() and any("(was " in l for l in out)


def small_extract():
    e = Extract()
    e.order("KB1X-PASN/1C1BK", "50_FMAN", [("SHEATHING", "EX4")], [("RS0002", 0.2, "kg")])
    return e


def test_collapsed_refresh_is_refused_and_live_store_is_untouched(tmp_path):
    db = tmp_path / "cr.sqlite"
    big = worked_example()
    for k in range(10):
        big.order(f"KC{k}X-PASN/1C1BK", "50_FMAN", [("SHEATHING", "EX4")], [("RS0002", 0.2, "kg")])
    run_refresh(db, client=client(FakeD365(big)))
    before = db.read_bytes()
    code, out = run_refresh(db, client=client(FakeD365(small_extract())))
    assert code == 2 and any("REFUSED" in l and "keeping the live store" in l for l in out)
    assert db.read_bytes() == before
    assert sorted(p.name for p in tmp_path.iterdir()) == ["cr.sqlite", "cr.sqlite.lock"]
    code, _ = run_refresh(db, allow_shrink=True, client=client(FakeD365(small_extract())))
    assert code == 0 and db.read_bytes() != before


def test_empty_extract_is_refused(tmp_path):
    db = tmp_path / "cr.sqlite"
    code, out = run_refresh(db, client=client(FakeD365(Extract())))
    assert code == 2 and not db.exists() and "no production orders" in out[-1]


def test_unreachable_dynamics_is_exit_4_and_live_store_stays(tmp_path):
    db = tmp_path / "cr.sqlite"
    run_refresh(db, client=client(FakeD365(worked_example())))
    before = db.read_bytes()
    code, out = run_refresh(db, client=client(FakeD365(worked_example(), fail=[403])))
    assert code == 4 and db.read_bytes() == before and "read access" in out[-1]
    assert sorted(p.name for p in tmp_path.iterdir()) == ["cr.sqlite", "cr.sqlite.lock"]


def test_validate_rejects_missing_schema(tmp_path):
    bad = tmp_path / "bad.sqlite"
    sqlite3.connect(bad).execute("CREATE TABLE cr_run (x)").connection.commit()
    with pytest.raises(rf.Refused, match="missing tables"):
        rf.validate(bad, tmp_path / "none", 0.8)
    junk = tmp_path / "junk.sqlite"
    junk.write_bytes(b"not a database" * 100)
    with pytest.raises(rf.Refused):
        rf.validate(junk, tmp_path / "none", 0.8)


def test_lock_held_is_exit_5(tmp_path):
    import fcntl
    db = tmp_path / "cr.sqlite"
    with open(tmp_path / "cr.sqlite.lock", "w") as f:
        fcntl.flock(f, fcntl.LOCK_EX)
        code, out = run_refresh(db, client=client(FakeD365(worked_example())))
    assert code == 5 and not db.exists()


def test_cli_refresh_from_folder_and_ratio_setting(tmp_path, monkeypatch):
    src = worked_example().write(tmp_path / "dynamics")
    db = tmp_path / "cr.sqlite"
    assert main(["refresh", "--source", str(src), "--db", str(db)]) == 0 and db.is_file()
    monkeypatch.delenv(rf.ENV_ENABLED, raising=False)
    assert main(["refresh", "--source", str(src), "--db", str(db), "--if-enabled"]) == 3
    assert main(["refresh", "--source", str(tmp_path / "nope"), "--db", str(db)]) == 2
    assert rf.ratio({rf.ENV_RATIO: "0.5"}) == 0.5 and rf.ratio({rf.ENV_RATIO: "9"}) == 0.8 and rf.ratio({}) == 0.8
