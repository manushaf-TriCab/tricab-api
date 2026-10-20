"""Load the Blob archive into the [cablebuilder] schema in Azure SQL.

The archive manifest says which body of each design is current. The loader
compares that with what the database holds, and only touches what differs:

    new body          insert it and its layers, operations, resources and BOM
                      lines; the previous current row of that design becomes
                      history (is_current = 0, attributes_json = NULL)
    body seen before  (a design changed and changed back) reactivate that row
    same body         update last_checked_at only if it moved

So the first run loads everything and a nightly run loads the handful of
designs the sync changed. Lookup tables and run records are loaded the same
way. Each batch of designs is one transaction, so a failed run leaves whole
designs or nothing, and the next run picks up where it stopped.

Connection: the same settings the tc-web slots already carry (DB_HOST,
DB_DATABASE, DB_PORT, DB_AUTH_MODE), so the loader adds none of its own.
DB_AUTH_MODE is managed-identity on the slots (the core slot's identity has
database-wide read and write in tc, measured 2026-10-06), entra-cli locally
(az login), or sql-login (DB_USERNAME, DB_PASSWORD) for a local container.
The serverless database may be paused; connecting retries while it resumes.
"""

from __future__ import annotations

import json
import os
import shutil
import struct
import subprocess
import time
from datetime import datetime

import requests

from .archive import Archive, gunzip
from .rows import (BOM_COLUMNS, DESIGN_COLUMNS, LAYER_COLUMNS, OPERATION_COLUMNS,
                   RESOURCE_COLUMNS, design_rows, lookup_rows)

SQL_RESOURCE = "https://database.windows.net/"
SQL_COPT_SS_ACCESS_TOKEN = 1256
BATCH = 200  # designs per transaction


# ---------------------------------------------------------------- connection


def sql_token(mode: str) -> str:
    if mode == "managed-identity":
        r = requests.get(os.environ["IDENTITY_ENDPOINT"],
                         params={"resource": SQL_RESOURCE, "api-version": "2019-08-01"},
                         headers={"X-IDENTITY-HEADER": os.environ["IDENTITY_HEADER"]}, timeout=60)
        r.raise_for_status()
        return r.json()["access_token"]
    if mode == "entra-cli":
        az = shutil.which("az") or shutil.which("az.cmd")
        out = subprocess.run([az, "account", "get-access-token", "--resource", SQL_RESOURCE,
                              "--query", "accessToken", "-o", "tsv"],
                             capture_output=True, text=True, timeout=120)
        if out.returncode != 0:
            raise SystemExit(f"az could not get a SQL token: {out.stderr.strip()[:300]}")
        return out.stdout.strip()
    raise ValueError(mode)


def connect(env: dict | None = None, attempts: int = 6):
    import pyodbc

    env = os.environ if env is None else env
    host, database = env.get("DB_HOST", ""), env.get("DB_DATABASE", "")
    if not host or not database:
        raise SystemExit("DB_HOST and DB_DATABASE must be set.")
    mode = env.get("DB_AUTH_MODE", "sql-login")
    drivers = [d for d in pyodbc.drivers() if "ODBC Driver" in d and "SQL Server" in d]
    driver = env.get("CBSYNC_ODBC_DRIVER") or (sorted(drivers)[-1] if drivers else None)
    if not driver:
        raise SystemExit("No Microsoft ODBC Driver for SQL Server is installed.")
    trust = "yes" if env.get("DB_TRUST_SERVER_CERTIFICATE", "false").lower() in ("1", "true", "yes") else "no"
    cs = (f"Driver={{{driver}}};Server=tcp:{host},{env.get('DB_PORT', '1433')};"
          f"Database={database};Encrypt=yes;TrustServerCertificate={trust};Connection Timeout=30")
    attrs = {}
    if mode == "sql-login":
        cs += f";Uid={env.get('DB_USERNAME', '')};Pwd={env.get('DB_PASSWORD', '')}"
    else:
        raw = sql_token(mode).encode("utf-16-le")
        attrs[SQL_COPT_SS_ACCESS_TOKEN] = struct.pack(f"<I{len(raw)}s", len(raw), raw)
    for attempt in range(attempts):
        try:
            return pyodbc.connect(cs, autocommit=False, attrs_before=attrs)
        except pyodbc.Error as exc:
            # 40613: a serverless database resuming. Anything else is real.
            if "40613" not in str(exc) or attempt == attempts - 1:
                raise
            time.sleep(20)


# ---------------------------------------------------------------- binding


def _sqltype(spec: tuple):
    import pyodbc

    kind = spec[0]
    if kind == "str":
        return (pyodbc.SQL_WVARCHAR, spec[1], 0)
    if kind == "dec":
        return (pyodbc.SQL_DECIMAL, spec[1], spec[2])
    if kind == "int":
        return (pyodbc.SQL_INTEGER, 0, 0)
    if kind == "bigint":
        return (pyodbc.SQL_BIGINT, 0, 0)
    if kind == "bit":
        return (pyodbc.SQL_BIT, 0, 0)
    if kind == "date":
        return (pyodbc.SQL_TYPE_DATE, 0, 0)
    if kind == "datetime":
        return (pyodbc.SQL_TYPE_TIMESTAMP, 27, 7)
    if kind == "max":
        return (pyodbc.SQL_WVARCHAR, 0, 0)  # size 0 binds NVARCHAR(MAX) under fast_executemany
    raise ValueError(spec)


BIGINT, INT = ("bigint",), ("int",)
STAMP, MAX = ("datetime",), ("max",)

DESIGN_KEYS = [("design_code", ("str", 64)), ("design_alternative", INT), ("design_version", INT),
               ("body_sha256", ("str", 64)), ("document_kind", ("str", 6)), ("blob_path", ("str", 400)),
               ("first_seen_at", STAMP), ("last_checked_at", STAMP)]
TABLES = {
    "design_versions": DESIGN_KEYS + [(c, s) for c, _, s in DESIGN_COLUMNS]
    + [("validation_errors", MAX), ("attributes_json", MAX)],
    "layers": [("design_version_id", BIGINT), ("layer_seq", INT)]
    + [(c, s) for c, _, s in LAYER_COLUMNS],
    "operations": [("design_version_id", BIGINT), ("layer_seq", INT), ("op_index", INT)]
    + [(c, s) for c, _, s in OPERATION_COLUMNS],
    "resources": [("design_version_id", BIGINT), ("layer_seq", INT), ("op_index", INT),
                  ("res_index", INT)] + [(c, s) for c, _, s in RESOURCE_COLUMNS],
    "bom_lines": [("design_version_id", BIGINT), ("layer_seq", INT), ("bom_index", INT)]
    + [(c, s) for c, _, s in BOM_COLUMNS],
}


def _insert(cur, table: str, rows: list[dict]) -> None:
    if not rows:
        return
    cols = TABLES[table]
    cur.setinputsizes([_sqltype(s) for _, s in cols])
    cur.executemany(
        f"INSERT INTO [cablebuilder].[{table}] ({', '.join(f'[{c}]' for c, _ in cols)}) "
        f"VALUES ({', '.join('?' for _ in cols)})",
        [[r.get(c) for c, _ in cols] for r in rows])
    cur.setinputsizes(None)


def _stamp(iso: str) -> datetime:
    return datetime.strptime(iso, "%Y-%m-%dT%H:%M:%SZ")


# ---------------------------------------------------------------- designs


def load_designs(archive: Archive, conn, log=print, batch: int = BATCH) -> dict:
    cur = conn.cursor()
    cur.fast_executemany = True
    cur.execute("SELECT design_version_id, design_code, design_alternative, design_version, "
                "body_sha256, is_current, last_checked_at FROM [cablebuilder].[design_versions]")
    by_body: dict[tuple, tuple] = {}
    current: dict[tuple, tuple] = {}
    for vid, code, alt, ver, sha, is_cur, checked in cur.fetchall():
        by_body[(code, alt, ver, sha)] = (vid, bool(is_cur))
        if is_cur:
            current[(code, alt, ver)] = (vid, sha, checked)

    todo, touch = [], []
    for e in archive.designs.values():
        key = (e.code, int(e.alt), int(e.ver))
        have = current.get(key)
        if have and have[1] == e.sha:
            if have[2] is None or _stamp(e.last_checked) > have[2]:
                touch.append((_stamp(e.last_checked), have[0]))
            continue
        todo.append(e)

    counts = {"inserted": 0, "reactivated": 0, "superseded": 0, "checked": len(touch)}
    for start in range(0, len(todo), batch):
        part = todo[start:start + batch]
        parsed = [(e, design_rows(archive.read_design(e))) for e in part]
        supersede = [(_stamp(e.last_checked), e.code, int(e.alt), int(e.ver))
                     for e in part if (e.code, int(e.alt), int(e.ver)) in current]
        if supersede:
            cur.executemany(
                "UPDATE [cablebuilder].[design_versions] SET is_current = 0, superseded_at = ?, "
                "attributes_json = NULL WHERE design_code = ? AND design_alternative = ? "
                "AND design_version = ? AND is_current = 1", supersede)
            counts["superseded"] += len(supersede)

        fresh, back = [], []
        for e, rows in parsed:
            old = by_body.get((e.code, int(e.alt), int(e.ver), e.sha))
            (back if old else fresh).append((e, rows, old))
        for e, rows, old in back:
            cur.execute("UPDATE [cablebuilder].[design_versions] SET is_current = 1, superseded_at = NULL, "
                        "attributes_json = ?, last_checked_at = ? WHERE design_version_id = ?",
                        rows["design_versions"][0]["attributes_json"], _stamp(e.last_checked), old[0])
        counts["reactivated"] += len(back)

        _insert(cur, "design_versions", [
            {"design_code": e.code, "design_alternative": int(e.alt), "design_version": int(e.ver),
             "body_sha256": e.sha, "document_kind": e.kind, "blob_path": e.blob,
             "first_seen_at": _stamp(e.first_seen), "last_checked_at": _stamp(e.last_checked),
             **rows["design_versions"][0]}
            for e, rows, _ in fresh])
        ids: dict[tuple, int] = {}
        if fresh:
            shas = [e.sha for e, _, _ in fresh]
            cur.execute("SELECT design_version_id, design_code, design_alternative, design_version, "
                        "body_sha256 FROM [cablebuilder].[design_versions] WHERE is_current = 1 AND "
                        f"body_sha256 IN ({', '.join('?' for _ in shas)})", shas)
            ids = {(c, a, v, s): i for i, c, a, v, s in cur.fetchall()}
        children: dict[str, list[dict]] = {t: [] for t in ("layers", "operations", "resources", "bom_lines")}
        for e, rows, _ in fresh:
            vid = ids[(e.code, int(e.alt), int(e.ver), e.sha)]
            for t in children:
                children[t] += [{"design_version_id": vid, **r} for r in rows[t]]
        for t in ("layers", "operations", "resources", "bom_lines"):  # parents before children
            _insert(cur, t, children[t])
        conn.commit()
        counts["inserted"] += len(fresh)
        for e, _, _ in fresh:
            current[(e.code, int(e.alt), int(e.ver))] = (None, e.sha, None)
        log(f"  designs {min(start + batch, len(todo))}/{len(todo)}  {counts}")

    for start in range(0, len(touch), 1000):
        cur.executemany("UPDATE [cablebuilder].[design_versions] SET last_checked_at = ? "
                        "WHERE design_version_id = ?", touch[start:start + 1000])
    conn.commit()
    return counts


# ---------------------------------------------------------------- lookups, runs


def load_lookups(archive: Archive, conn) -> dict:
    cur = conn.cursor()
    cur.fast_executemany = True
    cur.execute("SELECT tablecode, body_sha256, lookup_table_version_id, is_current "
                "FROM [cablebuilder].[lookup_table_versions]")
    have = {(int(t), s): (i, bool(c)) for t, s, i, c in cur.fetchall()}
    counts = {"inserted": 0, "reactivated": 0, "unchanged": 0}
    for code, lk in archive.lookups.items():
        t = int(code)
        hit = have.get((t, lk["sha"]))
        if hit and hit[1]:
            counts["unchanged"] += 1
            continue
        cur.execute("UPDATE [cablebuilder].[lookup_table_versions] SET is_current = 0 "
                    "WHERE tablecode = ? AND is_current = 1", t)
        if hit:
            cur.execute("UPDATE [cablebuilder].[lookup_table_versions] SET is_current = 1 "
                        "WHERE lookup_table_version_id = ?", hit[0])
            counts["reactivated"] += 1
        else:
            meta, grid = lookup_rows(gunzip(archive.store.get(lk["blob"])))
            cur.execute(
                "INSERT INTO [cablebuilder].[lookup_table_versions] (tablecode, description, standard, "
                "plant, body_sha256, blob_path, row_count, col_count, first_seen_at, last_checked_at) "
                "OUTPUT INSERTED.lookup_table_version_id VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                t, meta["description"][:200] or None, meta["standard"] or None, meta["plant"] or None,
                lk["sha"], lk["blob"], len(grid), max((len(r) for r in grid), default=0),
                _stamp(lk["first_seen"]), _stamp(lk["last_checked"]))
            vid = cur.fetchone()[0]
            if grid:
                cur.executemany("INSERT INTO [cablebuilder].[lookup_rows] (lookup_table_version_id, "
                                "row_no, cells_json) VALUES (?, ?, ?)",
                                [(vid, i, json.dumps(r, ensure_ascii=False)) for i, r in enumerate(grid)])
            counts["inserted"] += 1
        conn.commit()
    return counts


def load_runs(archive: Archive, conn) -> dict:
    cur = conn.cursor()
    cur.execute("SELECT run_record_blob FROM [cablebuilder].[sync_runs]")
    have = {r[0] for r in cur.fetchall()}
    n = 0
    for name in archive.store.list("runs/"):
        if name in have or not name.endswith(".json"):
            continue
        s = json.loads(archive.store.get(name))
        # nightly/rotate keep counts under "pull"; pull/lookups at the top; seed under "designs"
        c = (s.get("pull") or {}).get("counts") or s.get("counts") or s.get("designs") or {}
        c = c if isinstance(c, dict) else {}
        cur.execute("INSERT INTO [cablebuilder].[sync_runs] (command, started_at, seconds, "
                    "run_record_blob, designs_new, designs_changed, designs_failed, summary_json) "
                    "VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    s.get("command", "?"), _stamp(s["started"]), s.get("seconds"), name,
                    c.get("new"), c.get("changed"), c.get("failed"),
                    json.dumps(s, ensure_ascii=False, default=str))
        n += 1
    conn.commit()
    return {"inserted": n}


def load(archive: Archive, conn, log=print) -> dict:
    return {"designs": load_designs(archive, conn, log=log),
            "lookups": load_lookups(archive, conn),
            "runs": load_runs(archive, conn)}
