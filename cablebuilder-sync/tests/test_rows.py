from __future__ import annotations

import json
from datetime import date
from decimal import Decimal

from cbsync.rows import D, Problems, S, convert, design_rows, lookup_rows

DESIGN = b"""<?xml version="1.0"?><design>
<designAlternative>0</designAlternative><version>0</version>
<header><design>KL39X-CAXN/3C2.5BK</design><standard>Cable - Low Voltage Power</standard>
<weight>175.6902</weight><cuweight>65.985</cuweight><lastupdate>06-Aug-2026</lastupdate></header>
<plantHeader><erp_itemid>KL39X-CAXN/3C2.5BK</erp_itemid><erp_itemgroupid>FINMAN</erp_itemgroupid>
<calculatedminlength>500</calculatedminlength><approved>0</approved><outofdate>1</outofdate></plantHeader>
<layer><layerno>1</layerno><process>SHEATHING</process><process>Extrusion</process>
<laylength>9223372036854776000</laylength>
<operation><operation>10</operation><task>SHEATHING</task><machine>EX5</machine>
<speed>18.37</speed><runtime>0.907</runtime><setuptime>0.75</setuptime>
<resources><resource><resource>EX5</resource><lotsize>1500</lotsize>
<erp_processcategoryid>540EX5RUN</erp_processcategoryid><erp_runtime>54.42</erp_runtime></resource></resources>
</operation>
<bom><bomitem>LUKL39X-CAXN/3C2.5</bomitem><bomunit_erp>m</bomunit_erp><bomqty_erp>1000</bomqty_erp>
<bomqtyserie_erp>1000</bomqtyserie_erp><bomdesignalternative>0</bomdesignalternative><bompos_erp>0.510</bompos_erp></bom>
<bom><bomitem>RS0002</bomitem><bomunit_erp>kg</bomunit_erp><bomqty_erp>79.56</bomqty_erp>
<bomqtyserie_erp>1000</bomqtyserie_erp><bomscrapbqpu>8</bomscrapbqpu></bom>
</layer></design>"""


def test_design_rows_types_the_promoted_columns_and_keeps_everything_in_json():
    p = Problems()
    rows = design_rows(DESIGN, p)
    d = rows["design_versions"][0]
    assert d["erp_item_group"] == "FINMAN" and d["weight_kg_per_km"] == Decimal("175.690200")
    assert d["cb_last_update"] == date(2026, 8, 6)
    assert d["approved"] is False and d["out_of_date"] is True
    assert json.loads(d["attributes_json"])["plantHeader"]["calculatedminlength"] == "500"
    lay = rows["layers"][0]
    assert (lay["process"], lay["process_detail"]) == ("SHEATHING", "Extrusion")
    assert lay["lay_length_mm"] is None  # Long.MAX_VALUE placeholder, not a length
    op = rows["operations"][0]
    assert (op["operation_seq"], op["machine"], op["speed_m_per_min"]) == (10, "EX5", Decimal("18.370000"))
    res = rows["resources"][0]
    assert res["erp_process_category_id"] == "540EX5RUN" and res["lot_size_m"] == Decimal("1500.000")
    b = rows["bom_lines"]
    assert [x["item_code"] for x in b] == ["LUKL39X-CAXN/3C2.5", "RS0002"]
    assert b[0]["erp_position"] == "0.510" and b[1]["scrap_pct"] == Decimal("8.000000")
    assert p.counts == {}


def test_json_keeps_non_empty_design_fields_only_and_child_rows_have_none():
    body = DESIGN.replace(b"<standard>", b"<emptyfield></emptyfield><standard>")
    rows = design_rows(body)
    header = json.loads(rows["design_versions"][0]["attributes_json"])["header"]
    assert "emptyfield" not in header and header["standard"] == "Cable - Low Voltage Power"
    for t in ("layers", "operations", "resources", "bom_lines"):
        assert all("attributes_json" not in r for r in rows[t])


def test_values_that_do_not_fit_are_nulled_and_reported():
    p = Problems()
    assert convert("x" * 70, S(64), "t", "c", p) is None
    assert convert("12345678901234", D(18, 6), "t", "c", p) is None  # 14 integer digits > 12
    assert convert("abc", D(18, 6), "t", "c", p) is None
    assert sorted(r for (_, _, r) in p.counts) == [
        "longer than 64", "not a number", "too large for DECIMAL(18,6)"]


def test_header_only_documents_give_a_design_row_without_layers():
    body = (b'<?xml version="1.0"?><designHeader><design>B</design><standard>Raw Materials</standard>'
            b"<designAlternative>0</designAlternative><version>0</version>"
            b"<header><lang>en</lang></header></designHeader>")
    rows = design_rows(body)
    assert rows["design_versions"][0]["standard"] == "Raw Materials"
    assert rows["layers"] == [] and rows["bom_lines"] == []


def test_lookup_rows_split_on_the_cell_and_row_delimiters():
    body = (b'<?xml version="1.0"?><lookuptables><lookuptable><tablecode>94794140</tablecode>'
            b"<description>Machine Table</description><body>machine|#+|rate|#+|\nEX5|#+|120|#+|\n"
            b"</body></lookuptable></lookuptables>")
    meta, rows = lookup_rows(body)
    assert meta["tablecode"] == "94794140" and rows == [["machine", "rate"], ["EX5", "120"]]
