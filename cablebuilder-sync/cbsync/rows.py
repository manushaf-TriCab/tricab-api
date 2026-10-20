"""Design XML to rows for the [cablebuilder] schema
(database/scripts/development/create-cablebuilder-schema.sql).

Each table is a list of (column, source, type). Source is the XML leaf tag,
"ph:<tag>" for a plantHeader leaf, or None for a column the loader fills
itself (keys, blob path, timestamps). A value goes into its typed column only
when it parses and fits; otherwise the column is NULL and the problem is
reported. For a design, every non-empty original is in attributes_json either
way; layers, operations, resources and BOM lines have typed columns only, and
their full detail stays in the Blob document.

The same specs drive check_fit(), which runs the mapping over the archive
and reports values that would not fit, so a column type is decided against
the data and not guessed.
"""

from __future__ import annotations

import json
import xml.etree.ElementTree as ET
from datetime import datetime
from decimal import Decimal, InvalidOperation
from typing import Any

# type specs: ("str", maxlen) | ("dec", precision, scale) | ("int",) | ("bit",) | ("date",)
S = lambda n: ("str", n)  # noqa: E731
D = lambda p, s: ("dec", p, s)  # noqa: E731
INT, BIT, DATE = ("int",), ("bit",), ("date",)
# Java's Long.MAX_VALUE, printed as a double (9223372036854776000): CableBuilder
# uses it as "no value" (12 lay lengths in the August pull).
JAVA_LONG_MAX = Decimal("9.2e18")

DESIGN_COLUMNS = [
    ("standard", "standard", S(64)),
    ("specification", "specification", S(64)),
    ("item_type", "itemtype", S(32)),
    ("item_nature", "itemnature", S(32)),
    ("material_type", "materialtype", S(100)),
    ("voltage", "voltage", S(32)),
    ("colour", "colour", S(64)),
    ("description", "description", S(400)),
    ("outer_diameter_mm", "outerdiam", D(18, 6)),
    ("weight_kg_per_km", "weight", D(18, 6)),
    ("cu_weight_kg_per_km", "cuweight", D(18, 6)),
    ("al_weight_kg_per_km", "alweight", D(18, 6)),
    ("erp_item_id", "ph:erp_itemid", S(64)),
    ("erp_item_group", "ph:erp_itemgroupid", S(16)),
    ("erp_item_template", "ph:erp_itemtemplate", S(32)),
    ("erp_category_name", "ph:erp_categoryname", S(64)),
    ("erp_bom_id", "ph:erp_bomid", S(64)),
    ("erp_route_id", "ph:erp_routeid", S(64)),
    ("erp_bom_id_bc", "ph:erp_bomid1", S(64)),
    ("erp_route_id_bc", "ph:erp_routeid1", S(64)),
    ("erp_site_bc", "ph:erp_site1", S(8)),
    ("erp_unit", "ph:erp_unit", S(8)),
    ("erp_from_unit", "ph:erp_fromunit", S(8)),
    ("erp_to_unit", "ph:erp_tounit", S(8)),
    ("erp_from_unit_factor", "ph:erp_fromunitfactor", D(18, 6)),
    ("calc_min_length_m", "ph:calculatedminlength", D(18, 3)),
    ("calc_max_length_m", "ph:calculatedmaxlength", D(18, 3)),
    ("std_order_length_m", "ph:erp_inv_stdorderlength", D(18, 3)),
    ("status", "ph:status", S(32)),
    ("approval_stage", "ph:approvalStage", S(16)),
    ("approved", "ph:approved", BIT),
    ("out_of_date", "ph:outofdate", BIT),
    ("site_validation", "ph:sitevalidation", S(16)),
    ("cb_last_update", "lastupdate", DATE),
    ("cb_last_update_by", "lastupdby", S(64)),
]

LAYER_COLUMNS = [
    ("layer_no", "layerno", INT),
    ("process", "process", S(32)),
    ("process_detail", "process_2", S(64)),
    ("machine_allocated", "machineallocated", S(32)),
    ("nom_diam_mm", "nomdiam", D(18, 6)),
    ("nom_weight_kg_per_km", "nomweight", D(18, 6)),
    ("fixed_scrap_factor", "fixedscrapfactor", D(18, 6)),
    ("lay_length_mm", "laylength", D(18, 6)),
]

OPERATION_COLUMNS = [
    ("operation_seq", "operation", INT),
    ("task", "task", S(32)),
    ("task_description", "taskDescription", S(64)),
    ("machine", "machine", S(16)),
    ("machine_new", "machinenew", S(16)),
    ("workcentre", "workcentre", S(32)),
    ("speed_m_per_min", "speed", D(18, 6)),
    ("run_time_h_per_km", "runtime", D(18, 6)),
    ("setup_time_h", "setuptime", D(18, 6)),
    ("efficiency_pct", "efficiency", D(9, 3)),
    ("run_occupation", "runoccupation", D(9, 3)),
    ("setup_occupation", "setupoccupation", D(9, 3)),
    ("erp_operation_next", "erp_operationnext", INT),
    ("erp_excess_scrap_m", "erp_operationexcessscrap", D(18, 3)),
    ("erp_start_scrap_m", "erp_operationstartscrap", D(18, 3)),
    ("erp_mid_scrap_m", "erp_operationmidscrap", D(18, 3)),
    ("erp_end_scrap_m", "erp_operationendscrap", D(18, 3)),
]

RESOURCE_COLUMNS = [
    ("resource", "resource", S(32)),
    ("machine", "machine", S(16)),
    ("speed_m_per_min", "speed", D(18, 6)),
    ("run_time_h_per_km", "runtime", D(18, 6)),
    ("setup_time_h", "setuptime", D(18, 6)),
    ("setup_time_h_per_km", "setuptimeperkm", D(18, 6)),
    ("lot_size_m", "lotsize", D(18, 3)),
    ("excess_qty_m", "resexcessqty", D(18, 3)),
    ("start_scrap_m", "resstartscrap", D(18, 3)),
    ("mid_scrap_m", "resmidscrap", D(18, 3)),
    ("end_scrap_m", "resendscrap", D(18, 3)),
    ("drum_change_length_m", "drumchangelength", D(18, 3)),
    ("extruder_output_lookup", "extoutputlu", S(64)),
    ("extruder_output_kg_per_h", "extruderoutput", D(18, 3)),
    ("erp_resource", "erp_resource", S(16)),
    ("erp_resource_group", "erp_resourcegroup", S(16)),
    ("erp_route_group", "erp_routegroup", S(16)),
    ("erp_route_id", "erp_routeid", S(64)),
    ("erp_route_name", "erp_routename", S(100)),
    ("erp_process_category_id", "erp_processcategoryid", S(16)),
    ("erp_setup_category_id", "erp_setupcategoryid", S(16)),
    ("erp_run_time_min", "erp_runtime", D(18, 6)),
    ("erp_setup_time_min", "erp_setuptime", D(18, 6)),
]

BOM_COLUMNS = [
    ("item_code", "bomitem", S(64)),
    ("item_alternative", "bomdesignalternative", INT),
    ("description", "description", S(400)),
    ("qty", "bomqty_erp", D(28, 10)),
    ("qty_series", "bomqtyserie_erp", D(18, 4)),
    ("unit", "bomunit_erp", S(8)),
    ("net_weight", "bomnetweight", D(28, 10)),
    ("scrap_pct", "bomscrapbqpu", D(18, 6)),
    ("erp_consumption", "bomconsumption_erp", S(32)),
    ("erp_reservation", "bomreservation_erp", S(32)),
    ("erp_position", "bompos_erp", S(16)),  # a position code such as 0.510
    ("erp_warehouse", "bomwarehouse_erp", S(16)),
    ("erp_warehouse_bc", "bomwarehouse_erp1", S(16)),
    ("erp_bom_type", "bomtype_erp", S(16)),
]


class Problems:
    """Values that did not fit their column, counted per (table, column, reason)."""

    def __init__(self) -> None:
        self.counts: dict[tuple[str, str, str], int] = {}
        self.examples: dict[tuple[str, str, str], str] = {}

    def add(self, table: str, column: str, reason: str, example: str) -> None:
        k = (table, column, reason)
        self.counts[k] = self.counts.get(k, 0) + 1
        self.examples.setdefault(k, example[:80])


def convert(raw: str | None, spec: tuple, table: str, column: str,
            problems: Problems | None) -> Any:
    if raw is None:
        return None
    v = raw.strip()
    if v == "":
        return None
    kind = spec[0]

    def bad(reason: str) -> None:
        if problems is not None:
            problems.add(table, column, reason, v)

    if kind == "str":
        if len(v) > spec[1]:
            bad(f"longer than {spec[1]}")
            return None
        return v
    if kind == "int":
        try:
            d = Decimal(v)
        except InvalidOperation:
            bad("not a number")
            return None
        if d != d.to_integral_value() or abs(d) > 2_147_483_647:
            bad("not an INT")
            return None
        return int(d)
    if kind == "dec":
        try:
            d = Decimal(v)
        except InvalidOperation:
            bad("not a number")
            return None
        if not d.is_finite():
            bad("not finite")
            return None
        if abs(d) >= JAVA_LONG_MAX:
            return None  # CableBuilder's placeholder for "none" (e.g. lay length of a straight layer)
        precision, scale = spec[1], spec[2]
        q = d.quantize(Decimal(1).scaleb(-scale))  # SQL Server rounds to the scale
        digits = len(q.as_tuple().digits) - max(0, -q.as_tuple().exponent)
        if max(digits, 0) > precision - scale:
            bad(f"too large for DECIMAL({precision},{scale})")
            return None
        return q
    if kind == "bit":
        if v in ("0", "1"):
            return v == "1"
        if v.lower() in ("true", "false"):
            return v.lower() == "true"
        bad("not 0/1")
        return None
    if kind == "date":
        try:
            return datetime.strptime(v, "%d-%b-%Y").date()
        except ValueError:
            bad("not dd-Mon-yyyy")
            return None
    raise ValueError(spec)


def leaves(el: ET.Element | None, skip: tuple[str, ...] = ()) -> dict[str, str]:
    """Leaf children as a dict; a repeated tag gets _2, _3 (as parse.py)."""
    out: dict[str, str] = {}
    seen: dict[str, int] = {}
    if el is None:
        return out
    for c in el:
        if c.tag in skip or len(c):
            continue
        seen[c.tag] = seen.get(c.tag, 0) + 1
        key = c.tag if seen[c.tag] == 1 else f"{c.tag}_{seen[c.tag]}"
        out[key] = (c.text or "").strip()
    return out


def _typed(table: str, cols: list, values: dict[str, str], problems: Problems | None) -> dict:
    return {col: convert(values.get(src), spec, table, col, problems) for col, src, spec in cols}


def design_rows(body: bytes, problems: Problems | None = None) -> dict[str, list[dict]]:
    """Rows for every table from one design document, without the keys the
    loader assigns (design_version_id, blob path, timestamps)."""
    root = ET.fromstring(body)
    header_el = root.find("header")
    if root.tag == "designHeader":  # getDesignHeader: fields sit on the root
        header = leaves(root)
        plant = {}
    else:
        header = leaves(header_el)
        plant = leaves(root.find("plantHeader"))
    merged = dict(header)
    merged.update({f"ph:{k}": v for k, v in plant.items()})
    err = root.find("errors")
    design = _typed("design_versions", DESIGN_COLUMNS, merged, problems)
    design["validation_errors"] = (" ".join(err.itertext()).strip() or None) if err is not None else None
    # Non-empty values only: 231 of the 476 fields are always empty, and
    # dropping blanks halves the column (measured on the August pull).
    design["attributes_json"] = json.dumps(
        {"header": {k: v for k, v in header.items() if v},
         "plantHeader": {k: v for k, v in plant.items() if v}},
        ensure_ascii=False, separators=(",", ":"))

    layers, operations, resources, boms = [], [], [], []
    for li, lay in enumerate(root.findall("layer")):
        lv = leaves(lay, skip=("bom", "operation"))
        layers.append({"layer_seq": li, **_typed("layers", LAYER_COLUMNS, lv, problems)})
        for oi, op in enumerate(lay.findall("operation")):
            ov = leaves(op, skip=("resources",))
            operations.append({"layer_seq": li, "op_index": oi,
                               **_typed("operations", OPERATION_COLUMNS, ov, problems)})
            res = op.find("resources")
            for ri, r in enumerate(res.findall("resource") if res is not None else []):
                rv = leaves(r)
                resources.append({"layer_seq": li, "op_index": oi, "res_index": ri,
                                  **_typed("resources", RESOURCE_COLUMNS, rv, problems)})
        for bi, b in enumerate(lay.findall("bom")):
            bv = leaves(b)
            # item_code may be NULL (1 line in the August pull): kept, not dropped
            boms.append({"layer_seq": li, "bom_index": bi,
                         **_typed("bom_lines", BOM_COLUMNS, bv, problems)})
    return {"design_versions": [design], "layers": layers, "operations": operations,
            "resources": resources, "bom_lines": boms}


def lookup_rows(body: bytes) -> tuple[dict, list[list[str]]]:
    """Metadata and grid rows of a lookup table (cells joined by |#+|, rows
    terminated by |#+| and a newline; see parse.py)."""
    lt = ET.fromstring(body).find("lookuptable")
    g = lambda n: (lt.findtext(n) or "").strip() if lt is not None else ""  # noqa: E731
    rows = []
    for line in (lt.findtext("body") or "").split("\n") if lt is not None else []:
        if not line.strip():
            continue
        cells = line.split("|#+|")
        if cells and cells[-1] == "":
            cells.pop()
        rows.append([c.strip() for c in cells])
    meta = {"tablecode": g("tablecode"), "description": g("description"),
            "standard": g("standard"), "plant": g("plant")}
    return meta, rows


def check_fit(archive, log=print) -> dict:
    """Run the mapping over every current design in the archive; report what
    would not fit the schema, and primary-key collisions."""
    problems = Problems()
    totals: dict[str, int] = {}
    pk_dupes = 0
    for i, entry in enumerate(archive.designs.values(), 1):
        rows = design_rows(archive.read_design(entry), problems)
        for t, rs in rows.items():
            totals[t] = totals.get(t, 0) + len(rs)
        keys = [(r["layer_seq"], r["bom_index"]) for r in rows["bom_lines"]]
        pk_dupes += len(keys) - len(set(keys))
        if i % 5000 == 0:
            log(f"  {i}/{len(archive.designs)}")
    issues = sorted(
        ({"table": t, "column": c, "reason": r, "rows": n, "example": problems.examples[(t, c, r)]}
         for (t, c, r), n in problems.counts.items()),
        key=lambda x: -x["rows"])
    return {"rows": totals, "primary_key_collisions": pk_dupes, "values_not_fitting": issues}
