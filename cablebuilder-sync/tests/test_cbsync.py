from __future__ import annotations

import json
from datetime import datetime, timedelta, timezone

import pytest

from cbsync import cli, sync
from cbsync.archive import Archive, encode, identify, sub_designs
from cbsync.changefeed import default_since, parse_name
from cbsync.store import LocalStore


def design_xml(code: str, alt="0", ver="0", note="", boms=(), itemid=None) -> bytes:
    lines = "".join(
        f"<bom><bomitem>{c}</bomitem><bomunit_erp>{u}</bomunit_erp>"
        f"<bomdesignalternative>0</bomdesignalternative></bom>"
        for c, u in boms
    )
    return (
        f'<?xml version="1.0"?><design><designAlternative>{alt}</designAlternative>'
        f"<version>{ver}</version><header><design>{code}</design><note>{note}</note></header>"
        f"<plantHeader><erp_itemid>{itemid or code}</erp_itemid></plantHeader>"
        f"<layer><layerno>1</layerno>{lines}</layer></design>"
    ).encode()


class FakeCB:
    """Stands in for CableBuilder: a dict of code -> body."""

    def __init__(self, designs: dict[str, bytes], pages=None) -> None:
        self.designs = designs
        self.pages = pages or []
        self.calls: list[str] = []
        self.requests = 0

    def design(self, code, alt, ver):
        self.calls.append(code)
        self.requests += 1
        body = self.designs.get(code)
        return ("full", body) if body else ("failed", None)

    def search_page(self, page, rows=200):
        self.requests += 1
        total = sum(len(p) for p in self.pages)
        return (self.pages[page - 1] if page <= len(self.pages) else []), total


@pytest.fixture
def archive(tmp_path):
    return Archive(LocalStore(tmp_path / "store"))


def test_encode_is_reversible_and_distinguishes_lossy_slug_collisions():
    from urllib.parse import unquote

    a, b = "LM16X-XXXN/1C2.5WH", "LM16X-XXXN 1C2.5WH"
    assert encode(a) != encode(b)  # the old slug mapped both to the same file
    assert unquote(encode(a)) == a and "/" not in encode(a)


def test_identify_and_sub_designs():
    body = design_xml("KL39X/3C", boms=[("LU/3C", "m"), ("RS0002", "kg")])
    ident = identify(body)
    assert (ident.code, ident.alt, ident.ver) == ("KL39X/3C", "0", "0")
    assert sub_designs(body) == [("LU/3C", "0")]  # kg lines are materials


def test_record_writes_once_per_distinct_body_and_keeps_history(archive):
    v1, v2 = design_xml("A/1", note="x"), design_xml("A/1", note="y")
    assert archive.record_design(v1, "full", when="2026-08-06T00:00:00Z")[0] == "new"
    assert archive.record_design(v1, "full", when="2026-10-20T00:00:00Z")[0] == "unchanged"
    outcome, e = archive.record_design(v2, "full", when="2026-10-21T00:00:00Z")
    assert outcome == "changed"
    assert [h["first_seen"] for h in e.history] == ["2026-08-06T00:00:00Z"]
    assert len(list(archive.store.list("designs/"))) == 2
    archive.save()
    again = Archive(archive.store)  # manifest round-trips through the store
    assert again.designs[("A/1", "0", "0")].sha == e.sha
    assert again.read_design(again.designs[("A/1", "0", "0")]) == v2


def test_parse_name():
    c = parse_name("DesignBOMVersion: KN12X-PAXN/2C16OR[A0V1L0]")
    assert (c.code, c.alt, c.ver) == ("KN12X-PAXN/2C16OR", "0", "1")
    assert parse_name("Design: MB_BLACK").code == "MB_BLACK"
    assert parse_name("no colon here") is None


def test_default_since_overlaps_the_watermark():
    assert default_since("2026-10-19T10:00:00Z") == datetime(2026, 10, 19, 8, tzinfo=timezone.utc)
    assert datetime.now(timezone.utc) - default_since(None) < timedelta(days=1, minutes=1)


def test_pull_recurses_into_missing_sub_designs_only(archive):
    archive.record_design(design_xml("HAVE"), "full")
    cb = FakeCB({
        "TOP": design_xml("TOP", boms=[("MID", "m"), ("HAVE", "m"), ("RS1", "kg")]),
        "MID": design_xml("MID", boms=[("GONE", "m")]),
    })
    out = sync.pull(archive, cb, [("TOP", None, None)], log=lambda *_: None)
    assert cb.calls == ["TOP", "MID", "GONE"]  # HAVE is held, RS1 is a material
    assert out["counts"]["new"] == 2 and out["failed"][0]["code"] == "GONE"


def test_codes_cablebuilder_lacks_are_skipped_on_later_runs(archive):
    cb = FakeCB({})
    sync.pull(archive, cb, [("TAPE_PP50-40MM", None, None)], log=lambda *_: None)
    out = sync.pull(archive, cb, [("TAPE_PP50-40MM", None, None)], log=lambda *_: None)
    assert cb.calls == ["TAPE_PP50-40MM"]  # asked once
    assert out["counts"]["skipped: not in CableBuilder"] == 1
    sync.pull(archive, cb, [("TAPE_PP50-40MM", None, None)], skip_unknown=False,
              log=lambda *_: None)
    assert len(cb.calls) == 2  # an explicit pull still asks


def test_pull_maps_d365_item_ids_to_design_codes(archive):
    archive.record_design(design_xml("ALPO25/23-30MM", itemid="TAPE_ALPO25/23-30MM"), "full")
    cb = FakeCB({"ALPO25/23-30MM": design_xml("ALPO25/23-30MM", itemid="TAPE_ALPO25/23-30MM")})
    sync.pull(archive, cb, [("TAPE_ALPO25/23-30MM", None, None)], log=lambda *_: None)
    assert cb.calls == ["ALPO25/23-30MM"]


def test_rotate_wraps_and_filters_alternatives(archive):
    cb = FakeCB({c: design_xml(c) for c in "ABC"},
                pages=[[("A", "0", "0"), ("A", "1", "0")], [("B", "0", "0")]])
    sync.rotate(archive, cb, pages=1, page_size=2, log=lambda *_: None)
    assert archive.get_state("rotate")["next_page"] == 2
    sync.rotate(archive, cb, pages=1, page_size=2, log=lambda *_: None)
    assert archive.get_state("rotate")["next_page"] == 1  # wrapped
    assert cb.calls == ["A", "B"]


def test_nightly_run_key_runs_once_and_respects_the_lock(tmp_path, monkeypatch, capsys):
    store = tmp_path / "store"
    calls = []
    monkeypatch.setattr(cli, "CableBuilder", lambda pause: type("CB", (), {"check": lambda s: 1})())
    monkeypatch.setattr(sync, "nightly", lambda *a, **k: calls.append(1) or {})
    argv = ["--store", str(store), "nightly", "--run-key", "2026-10-21"]
    cli.main(argv)
    cli.main(argv)
    assert calls == [1]  # second call sees the key done
    # a fresh lock held by another instance blocks a new key
    a = Archive(LocalStore(store))
    a.put_state("lock", {"started": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
                         "key": "other"})
    cli.main(["--store", str(store), "nightly", "--run-key", "2026-10-22"])
    assert calls == [1]
    assert "holds the lock" in capsys.readouterr().out


def test_seed_loads_files_with_their_mtime(tmp_path, archive):
    d = tmp_path / "raw" / "design"
    d.mkdir(parents=True)
    (tmp_path / "raw" / "lookup").mkdir()
    (d / "a-1__a0v0.xml").write_bytes(design_xml("A/1"))
    # getDesignHeader's shape: code at the root, plus a `header` child without it
    (d / "b__a0v0.header.xml").write_bytes(
        b'<?xml version="1.0"?><designHeader><design>B</design>'
        b"<designAlternative>0</designAlternative><version>0</version>"
        b"<header><lang>en</lang></header></designHeader>")
    out = sync.seed(archive, tmp_path / "raw", log=lambda *_: None)
    assert out["designs"] == {"new": 2}
    assert archive.designs[("B", "0", "0")].kind == "header"
    runs = json.dumps(out)
    assert "unidentified" in runs
