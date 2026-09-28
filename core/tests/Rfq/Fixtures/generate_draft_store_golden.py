"""Golden output of the source RFQ draft store, for the PHP port's parity tests.

Runs the source's own `DraftStore` against a throwaway SQLite file with the
clock and uuid4 frozen, and records exactly what it writes: the draft, its
lines, its row edits and its activity, after each save. The PHP port
(`core/src/Rfq/Domain/Service/DraftRowSet.php` and friends) must reproduce it
byte for byte, ids included, which pins the order it generates ids in too.

    RFQ_MATCHER_PATH=../../rfq/wt-main-verified \\
        python core/tests/Rfq/Fixtures/generate_draft_store_golden.py

Needs the source's requirements installed. Regenerate only when the source's
store changes on purpose, and say so in the commit.
"""

from __future__ import annotations

import json
import os
import sqlite3
import sys
import tempfile
from datetime import datetime, timezone
from pathlib import Path

source = Path(os.environ.get("RFQ_MATCHER_PATH", "")).resolve()
if not (source / "matcher" / "draft_store.py").is_file():
    sys.exit("Set RFQ_MATCHER_PATH to an RFQ parser checkout.")
sys.path.insert(0, str(source))

import matcher.draft_store as ds  # noqa: E402

FROZEN = datetime(2026, 9, 28, 1, 2, 3, 456789, tzinfo=timezone.utc)


class FrozenDateTime(datetime):
    @classmethod
    def now(cls, tz=None):  # noqa: D401 - matches datetime.now
        return FROZEN if tz is not None else FROZEN.replace(tzinfo=None)


class Ids:
    def __init__(self) -> None:
        self.n = 0

    def __call__(self):
        self.n += 1
        value = f"{self.n:032x}"

        class _U:
            hex = value

        return _U()


ids = Ids()
ds.datetime = FrozenDateTime
ds.uuid.uuid4 = ids


class Actor:
    def __init__(self, email: str, role: str, branch: str) -> None:
        self.email = email
        self.role = role
        self.branch = branch


EDIT_TEXT_CASES = [
    None, "", "  ", "100", "100.0", " 100 ", "1e3", "1E-5", "0.00001234", "123456", "1234567",
    "0.1", "1/2", "abc", "Ø 2.5mm²", "1_000", "inf", "-Infinity", "nan", "+5", "-0", ".5", "5.",
    "0x10", "12,5", "2.5e+10", "999999.5", "0.0001", "0.00009999995", "1e16", "3.14159265",
    100, 100.0, -0.0, 0, 1.5, 2.675, 1e-7, 123456789, 0.000123456789, True, False,
    ["a", 1], {"k": "v"}, [], {},
]

ROWS_V1 = [
    {   # a cable: total length is the quantity, cuts are counted
        "raw_text": "10 x 100m 2C 2.5mm TPS",
        "source": {"original_text": "10 x 100m 2C 2.5mm TPS", "row_number": 1},
        "final_product_code": "c1-tps2c2.5",
        "length": {"total_length": 1000, "number_of_lengths": 10, "length_each": 100},
        "unit_price": 1.25, "margin_percent": 30, "final_unit_price": 1.79,
        "status": "matched", "line_note": "",
    },
    {   # an accessory: the unit count is the quantity
        "raw_text": "50 cable ties",
        "selected_product_code": "ACC-TIE-300",
        "length": {"number_of_lengths": 50},
        "unit_price": 0.12, "margin_percent": 40, "final_unit_price": 0.2,
        "status": "accessory",
    },
    {
        "raw_text": "Ø 16mm² × 3 core, flexible — see note",
        "source": {"original_text": "Ø 16mm² × 3 core, flexible — see note"},
        "final_product_code": "",
        "length": {"total_length": "", "number_of_lengths": 2, "length_each_m": 25.5},
        "status": "needs_review", "line_note": "ask about colour / insulation",
        "score": float("nan"),
    },
    {
        "raw_text": "4x 2.5 earth",
        "final_product_code": "C1-E2.5GY",
        "parent_product_code": "c1-e2.5",
        "length": {"total_length": 400.0, "number_of_lengths": 4, "length_each": 100.0},
        "unit_price": 0.5, "margin_percent": 25.0, "final_unit_price": 0.67, "status": "matched",
    },
]

HEADER_V1 = {"customer_code": "12345", "customer_name": "ACME Electrical", "region": "VIC",
             "contact": "Sam", "cusref": "PO-1", "author": "rep"}


def dump(store_path: str, draft_id: str) -> dict:
    conn = sqlite3.connect(store_path)
    conn.row_factory = sqlite3.Row
    q = lambda sql: [dict(r) for r in conn.execute(sql, (draft_id,)).fetchall()]  # noqa: E731
    out = {
        "draft": q("select * from rfq_draft_quotes where id = ?")[0],
        "rows": q("select id, draft_id, row_order, row_data, system_row_data, system_snapshot_valid, line_uid "
                  "from rfq_draft_rows where draft_id = ? order by row_order"),
        "edits": q("select * from rfq_draft_row_edits where draft_id = ? order by id"),
        "activity": q("select * from rfq_draft_activity where draft_id = ? order by id"),
    }
    conn.close()
    return out


def main() -> None:
    golden: dict = {
        "source": str(source.name),
        "frozen_now": FROZEN.isoformat(),
        "edit_text": [{"in": v, "in_json": json.dumps(v), "out": ds._edit_text(v)}
                      for v in EDIT_TEXT_CASES if not (isinstance(v, float) and v != v)],
        "tracked": [{"row": r, "values": ds._tracked_row_values(r), "summary": ds._line_summary(r)}
                    for r in ROWS_V1],
        "json_dumps": [{"value": v, "text": json.dumps(v)} for v in (
            ROWS_V1[0], HEADER_V1, {"a": [1, 2.0, "x/y"], "b": None, "c": True, "é": "ü\n\"\t"},
            {"n": 1e16, "m": 0.1, "big": 12345678901234567890, "neg": -1.5e-7})],
        "json_dumps_nan": json.dumps(ROWS_V1[2]),
        "scenarios": [],
    }
    tmp = tempfile.mkdtemp()
    path = os.path.join(tmp, "store.sqlite3")
    store = ds.DraftStore(Path(path))
    actor = Actor("rep@tricab.com", "sales", "VIC")

    # 1. create, then an edit save that adds, deletes, reorders and edits.
    ids.n = 0
    draft = store.save_draft("web", "ACME RFQ", HEADER_V1, ROWS_V1, None,
                             creator_email=" Rep@TriCab.com ", branch="vic")
    s1 = {"name": "create", "ids_used": ids.n, "state": dump(path, draft)}
    after_create = s1["state"]
    saved = [json.loads(r["row_data"]) for r in after_create["rows"]]
    v2 = [
        saved[3],                                               # moved to the front
        {**saved[0], "length": {**saved[0]["length"], "total_length": "1000.0"}},  # same number
        {**saved[1], "margin_percent": 35},                     # margin edit
        {"raw_text": "extra glands", "final_product_code": "acc-gland-20", "length": {"number_of_lengths": 6},
         "status": "manual"},                                   # added by hand
        # saved[2] deleted
    ]
    header_v2 = {**HEADER_V1, "region": "NSW", "customer_name": "ACME Electrical Pty Ltd", "phone": "0400"}
    ids.n = 100
    result = store.update_draft(draft, header_v2, v2, expected_revision=1, actor=actor, view_mode="simple")
    golden["scenarios"].append(s1)
    golden["scenarios"].append({"name": "edit", "ids_start": 100, "ids_used": ids.n - 100, "input_rows": v2,
                                "input_header": header_v2, "result": result, "state": dump(path, draft)})

    # 2. a draft saved before line_uid existed: positional matching, once.
    ids.n = 200
    legacy = store.save_draft("email", "legacy", HEADER_V1, ROWS_V1[:3], {"from": "c@example.com"},
                              creator_email="", branch="")
    conn = sqlite3.connect(path)
    for rid, data in conn.execute("select id, row_data from rfq_draft_rows where draft_id = ?", (legacy,)).fetchall():
        row = json.loads(data)
        row.pop("line_uid", None)
        conn.execute("update rfq_draft_rows set line_uid = null, row_data = ? where id = ?", (json.dumps(row), rid))
    conn.commit()
    conn.close()
    before = dump(path, legacy)
    v2_legacy = [json.loads(r["row_data"]) for r in before["rows"]]
    v2_legacy[0]["line_note"] = "checked"
    v2_legacy = v2_legacy[:2] + [{"raw_text": "new", "length": {"number_of_lengths": 1}}]
    ids.n = 300
    store.update_draft(legacy, HEADER_V1, v2_legacy, expected_revision=1, actor=None, view_mode="")
    golden["scenarios"].append({"name": "legacy_positional", "ids_start": 300, "ids_used": ids.n - 300,
                                "before": before, "input_rows": v2_legacy, "state": dump(path, legacy)})

    # 3. a stale revision is refused and changes nothing.
    try:
        store.update_draft(draft, header_v2, v2, expected_revision=1, actor=actor)
        conflict = None
    except ds.DraftConflict as exc:
        conflict = exc.current_revision
    golden["conflict_current_revision"] = conflict

    out = Path(__file__).with_name("draft-store-golden.json")
    out.write_text(json.dumps(golden, indent=1, ensure_ascii=False, default=str) + "\n", encoding="utf-8")
    print(f"wrote {out} ({out.stat().st_size} bytes)")


if __name__ == "__main__":
    main()
