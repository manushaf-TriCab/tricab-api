"""R-86 parser (port of R86ReportParser.php) and the latest-file rule (contract 2.2)."""

import os

import pytest

from cable_routes.loader import SourceError
from conductor_schedule import r86
from conductor_schedule.cli import main
from cs_support import R86_ORD_ROWS, make_source, r86_text, write_r86


def test_parse_header_rows_totals_and_form_feed():
    rep = r86.parse(r86_text("order", R86_ORD_ROWS), "x")
    assert rep.doc == "order" and rep.site == "TRI.MWH" and rep.company == "ALL"
    assert rep.period_from == "2026-10-01" and rep.period_to is None
    assert rep.printed == "2026-10-29T20:01:57"
    assert [r.label for r in rep.rows] == ["C1.5", "C16", "C70", "C95", "A10"]
    assert rep.totals == (2700, 680, 490, 600, 800, 5, 3440, 165)
    assert rep.weeks == 4.0


def test_unknown_style_column_is_outside_totals_and_not_demand():
    rep = r86.parse(r86_text("order", R86_ORD_ROWS))
    c16 = next(r for r in rep.rows if r.label == "C16")
    assert c16.unknown_style_m == 55 and c16.values[0] == 2000
    assert rep.buckets()[("plain", 0, 16.0)] == 2000 and rep.buckets()[("plain", 1, 16.0)] == 400


def test_buckets_are_copper_only_and_keyed_by_material_mica_csa():
    b = r86.parse(r86_text("order", R86_ORD_ROWS)).buckets()
    assert b == {("tinned", 0, 1.5): 600, ("tinned", 1, 1.5): 800, ("plain", 0, 16.0): 2000, ("plain", 1, 16.0): 400,
                 ("plain", 0, 70.0): 700, ("plain", 1, 95.0): 280}     # aluminium A10 and zero cells left out


def test_decimal_sizes():
    rep = r86.parse(r86_text("invoice", {"C.5": (10, 0, 1, 20, 0, 2, 0, 0), "C.75": (5, 0, 1, 0, 0, 0, 0, 0)}))
    assert {k[2] for k in rep.buckets()} == {0.5, 0.75}


def test_weeks_elapsed_is_days_over_seven_or_one():
    assert r86.parse(r86_text("order", R86_ORD_ROWS, printed="27Oct'26  20:01:57")).weeks == pytest.approx(26 / 7)
    assert r86.parse(r86_text("order", R86_ORD_ROWS, printed="01Oct'26  08:00:00")).weeks == 1.0


@pytest.mark.parametrize("mutate, needle", [
    (lambda t: t.replace("2700m", "2701m"), "printed total"),
    (lambda t: t + "garbage line\n", "not recognised"),
    (lambda t: t.replace("A10 ", "C16 ", 1), "repeats size"),
    (lambda t: "\n".join(l for l in t.split("\n") if not l.startswith("Total:")), "Total line is missing"),
    (lambda t: t.replace("Period: 01Oct'26->", "Period: 31Foo'26->"), "unreadable date"),
    (lambda t: t.replace("DocTyp: order data", "DocTyp: quote data"), "not recognised"),
    (lambda t: "\n".join(l for l in t.split("\n") if not l.startswith("Csa")), "column header"),
])
def test_rejects_malformed_files(mutate, needle):
    with pytest.raises(SourceError) as e:
        r86.parse(mutate(r86_text("order", R86_ORD_ROWS)), "R-86_ORD_MTD_2610")
    assert needle in str(e.value) and "R-86_ORD_MTD_2610" in str(e.value)


def test_latest_file_by_printed_then_period_then_mtime_then_name(tmp_path):
    d = tmp_path / "r86"
    older = write_r86(tmp_path, "R-86_ORD_MTD_2609", r86_text("order", R86_ORD_ROWS, printed="28Sep'26  10:00:00", frm="01Sep'26"))
    newer = write_r86(tmp_path, "R-86_ORD_MTD_2610", r86_text("order", R86_ORD_ROWS, printed="27Oct'26  20:01:57"))
    write_r86(tmp_path, "R-86_INV_MTD_2610", r86_text("invoice", R86_ORD_ROWS))
    os.utime(newer, (1, 1))                      # an older mtime does not matter: printed wins
    found = r86.discover(d)
    assert [r.name for r in found["order"]] == ["R-86_ORD_MTD_2610", "R-86_ORD_MTD_2609"]
    # same printed timestamp: the later period start, then mtime, then the name, descending
    t = r86_text("order", R86_ORD_ROWS, printed="27Oct'26  20:01:57")
    write_r86(tmp_path, "R-86_ORD_MTD_A", t)
    write_r86(tmp_path, "R-86_ORD_MTD_B", t)
    os.utime(d / "R-86_ORD_MTD_A", (5, 5)), os.utime(d / "R-86_ORD_MTD_B", (5, 5)), os.utime(d / "R-86_ORD_MTD_2610", (9, 9))
    assert [r.name for r in r86.discover(d)["order"]][:3] == ["R-86_ORD_MTD_2610", "R-86_ORD_MTD_B", "R-86_ORD_MTD_A"]


def test_type_comes_from_the_header_and_a_filename_mismatch_is_an_error(tmp_path):
    write_r86(tmp_path, "R-86_ORD_MTD_2610", r86_text("invoice", R86_ORD_ROWS))
    with pytest.raises(SourceError, match="file name says order"):
        r86.discover(tmp_path / "r86")
    write_r86(tmp_path, "export1", r86_text("order", R86_ORD_ROWS))      # no hint in the name: the header decides
    (tmp_path / "r86" / "R-86_ORD_MTD_2610").unlink()
    write_r86(tmp_path, "export2", r86_text("invoice", R86_ORD_ROWS))
    found = r86.discover(tmp_path / "r86")
    assert [r.name for r in found["order"]] == ["export1"] and [r.name for r in found["invoice"]] == ["export2"]


def test_folder_needs_an_order_and_an_invoice_file(tmp_path):
    write_r86(tmp_path, "R-86_ORD_MTD_2610", r86_text("order", R86_ORD_ROWS))
    with pytest.raises(SourceError, match="no R-86 invoice"):
        r86.discover(tmp_path / "r86")


def test_build_logs_older_files_period_mismatch_and_short_period(tmp_path):
    folder = make_source(tmp_path)
    write_r86(folder, "R-86_ORD_MTD_2609", r86_text("order", R86_ORD_ROWS, printed="28Sep'26  10:00:00", frm="01Sep'26"))
    write_r86(folder, "R-86_INV_MTD_2610", r86_text("invoice", R86_ORD_ROWS, printed="05Oct'26  09:00:00", frm="03Oct'26"))
    from cs_support import build
    run, con = build(tmp_path, folder)
    issues = {(r["item"], r["kind"]): r for r in con.execute("SELECT * FROM cs_issue")}
    assert issues[("R-86_ORD_MTD_2609", "r86_older_ignored")]["severity"] == "info"
    assert issues[("(all)", "r86_period_mismatch")]["severity"] == "warning"
    assert issues[("R-86_INV_MTD_2610", "r86_short_period")]["severity"] == "warning"    # 2 days = 0.29 weeks
    assert run["r86_order_file"] == "R-86_ORD_MTD_2610" and run["r86_order_weeks"] == 4.0
    assert run["r86_invoice_from"] == "2026-10-03" and run["r86_invoice_printed"] == "2026-10-05T09:00:00"


def test_unparseable_r86_file_is_exit_3(tmp_path):
    folder = make_source(tmp_path)
    (folder / "r86" / "R-86_ORD_MTD_2610").write_text("not an r86 file\n", encoding="ascii")
    assert main(["build", "--source", str(folder), "--db", str(tmp_path / "s.sqlite"), "--today", "2026-10-28"]) == 3
