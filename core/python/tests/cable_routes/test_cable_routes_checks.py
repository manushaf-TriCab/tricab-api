"""Each of the seven check kinds fires on a synthetic case, and machine statuses follow the alias map."""

from cable_routes import checks


def rows(con, kind):
    return [dict(r) for r in con.execute("SELECT * FROM cr_check WHERE kind = ? ORDER BY check_id", (kind,))]


def test_family_route_mismatch_missing_expected_op():
    families = [("Armoured cables", ["R2", "XA"], "LAYUP BEDDING ARMOURING SHEATHING")]
    ops = {f"R2-{n}": {"LAYUP", "BEDDING", "SHEATHING"} for n in range(4)}  # no ARMOURING anywhere
    out = checks.family_route_mismatch([(i, "R2") for i in ops], ops, families)
    assert out == [("family_route_mismatch", None, "R2", None, "ARMOURING", "ARMOURING expected for R2; none in Dynamics", 4, 4)]


def test_family_route_mismatch_unexpected_op_and_fifty_percent_threshold():
    families = [("Braided cables", ["HT", "XU"], "LAYUP BRAIDING SHEATHING")]
    ops = {"HT-1": {"LAYUP", "BRAIDING", "SHEATHING", "BEDDING"}, "HT-2": {"LAYUP", "BRAIDING", "SHEATHING", "BEDDING"},
           "XU-1": {"LAYUP", "BRAIDING", "SHEATHING", "BEDDING"}, "XU-2": {"LAYUP", "BRAIDING", "SHEATHING"}}
    out = checks.family_route_mismatch([(i, i[:2]) for i in ops], ops, families)
    assert [(r[2], r[4], r[6], r[7]) for r in out] == [("HT", "BEDDING", 2, 2), ("XU", "BEDDING", 1, 2)]
    assert out[0][5] == "BEDDING in 100% of HT items; the poster family does not list it"


def test_family_route_mismatch_skips_codes_shared_with_an_empty_family_and_nylon_maps_to_sheathing():
    families = [("Multi-core", ["XL"], "LAYUP SHEATHING"), ("SDI", ["XL"], ""), ("Nylon", ["XN"], "NYLON SHEATHING")]
    ops = {"XL-1": set(), "XN-1": {"SHEATHING"}}
    assert checks.family_route_mismatch([("XL-1", "XL"), ("XN-1", "XN")], ops, families) == []


def test_family_checks_catch_the_poster_contradictions_through_the_real_seed(extract, store):
    """FC (no bedding or braid), HT (has bedding), R2 (no armouring), built from the shipped poster families."""
    e = extract

    for code, ops_list in (("FC", ["LAYUP"]), ("HT", ["LAYUP", "BEDDING", "BRAIDING"]), ("R2", ["LAYUP"])):
        item = f"{code}1-AAA/3C2.5"
        sub = f"SUB-{code}"
        e.order(item, "50_FMAN", [("SHEATHING", "EX4")], [(sub, 1)])
        e.order(sub, "40_SFG", [(o, "LU3") for o in ops_list], [("MAT", 1, "kg")])
    con = store(e)
    got = {(r["code"], r["operation"]) for r in rows(con, "family_route_mismatch")}
    assert {("FC", "BEDDING"), ("FC", "BRAIDING"), ("HT", "BEDDING"), ("R2", "ARMOURING")} <= got
    assert ("R2", "BEDDING") in got  # the armoured family expects bedding too
    assert all(r["item"] is None for r in rows(con, "family_route_mismatch"))


def test_machine_status_and_machine_checks(extract, store):
    e = extract
    e.order("F-A", "50_FMAN", [("SHEATHING", "EX4"), ("PRINT", "ZZ9"), ("ROD_BREAK", "RB1"), ("TIN", "TL1")], [("MAT", 1, "kg")])
    con = store(e)
    m = {r["machine"]: dict(r) for r in con.execute("SELECT * FROM cr_machine")}
    assert (m["RB1"]["d365_status"], m["RB1"]["poster_name"], m["RB1"]["d365_id"], m["RB1"]["in_poster"]) == ("alias", "RB2", "RB1", 1)
    assert (m["TL1"]["d365_status"], m["TL1"]["poster_name"]) == ("alias", "TN1")
    assert "RB2" not in m and "TN1" not in m
    assert m["EX4"]["d365_status"] == "routed" and m["EX4"]["made_items"] == 1 and m["EX4"]["finished_items"] == 1
    assert (m["ZZ9"]["d365_status"], m["ZZ9"]["in_poster"], m["ZZ9"]["poster_name"]) == ("not_on_poster", 0, None)
    assert m["TP3"]["d365_status"] == "no_routings" and m["TP3"]["d365_id"] is None
    assert (m["EX8"]["d365_status"], m["EX8"]["hidden"]) == ("retired", 1)
    assert [r["machine"] for r in rows(con, "machine_not_on_poster")] == ["ZZ9"]
    unused = {r["machine"] for r in rows(con, "poster_machine_unused")}
    assert {"TP3", "BR4", "BR5", "BR6"} <= unused and "RB1" not in unused and "EX8" not in unused


def test_routed_to_retired_machine_names_the_order(extract, store):
    e = extract
    e.order("F-A", "50_FMAN", [("SHEATHING", "EX8")], [("MAT", 1, "kg")], status="Cancelled", date="2023-05-05", no="OLD1")
    con = store(e)
    (r,) = rows(con, "routed_to_retired_machine")
    assert (r["item"], r["machine"], r["operation"]) == ("F-A", "EX8", "SHEATHING")
    assert "OLD1" in r["detail"] and "Cancelled" in r["detail"] and "2023-05-05" in r["detail"]


def test_outside_poster_limit_uses_largest_made_input(extract, store):
    e = extract
    e.order("F-A", "50_FMAN", [("INSULATION", "EX6"), ("SHEATHING", "EX6")], [("S-1", 1), ("S-2", 1), ("MAT", 1, "kg")])
    e.order("S-1", "35_WIP", [("OP", "EX1")], [("MAT", 1, "kg")])
    e.order("S-2", "35_WIP", [("OP", "EX1")], [("MAT", 1, "kg")])
    e.product("S-1", 4.0)
    e.product("S-2", 12.04)  # EX6 poster limit is 10 mm
    e.order("F-B", "50_FMAN", [("LAYUP", "BR4")], [("S-3", 1)])  # BR4: 4 to 30 mm
    e.order("S-3", "35_WIP", [("OP", "EX1")], [("MAT", 1, "kg")])
    e.product("S-3", 3.0)
    con = store(e)
    got = {(r["item"], r["machine"]): r["detail"] for r in rows(con, "outside_poster_limit")}
    assert got == {
        ("F-A", "EX6"): "input is 12.0 mm; poster limit, 2023: up to 10 mm",
        ("F-B", "BR4"): "input is 3.0 mm; poster limit, 2023: 4 to 30 mm",
    }


def test_stops_early_and_untraceable_group_by_code_and_reason(extract, store):
    e = extract
    for n in (1, 2):
        e.order(f"TE{n}-AAA/3C2.5", "50_FMAN", [("SHEATHING", "EX4")], [("ZZ-RAW", 1, "kg")])
    e.order("TE3-AAA/3C2.5", "50_FMAN", [("SHEATHING", "EX4")], [("S-W", 1)])
    e.order("S-W", "15_WIR", [("ROD_BREAK", "RB1")], [("NX-1R8", 0.1, "kg")])
    e.order("TE4-AAA/3C2.5", "60_CAT", bom=[("A", 1)], cutting=True)
    e.pools["TE5-AAA/3C2.5"] = "60_CAT"
    con = store(e)
    (early,) = rows(con, "stops_early")
    assert (early["code"], early["affected"], early["total"]) == ("TE", 2, 5)
    untraced = {(r["code"], r["detail"]): (r["affected"], r["total"]) for r in rows(con, "untraceable")}
    assert len(untraced) == 2 and set(untraced.values()) == {(1, 5)}
    assert any("only cutting orders" in d for _, d in untraced) and any("no production orders" in d for _, d in untraced)
    run = con.execute("SELECT check_rows FROM cr_run").fetchone()[0]
    assert run == con.execute("SELECT count(*) FROM cr_check").fetchone()[0]


def test_only_the_seven_kinds_exist(extract, store):
    e = extract
    e.order("F-A", "50_FMAN", [("SHEATHING", "ZZ9")], [("MAT", 1, "kg")])
    con = store(e)
    kinds = {r[0] for r in con.execute("SELECT DISTINCT kind FROM cr_check")}
    assert kinds <= {"family_route_mismatch", "machine_not_on_poster", "poster_machine_unused", "routed_to_retired_machine",
                     "outside_poster_limit", "stops_early", "untraceable"}
