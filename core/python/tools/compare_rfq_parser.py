#!/usr/bin/env python3
"""Run a corpus of RFQs through the source parser and `rfq_parser`, and diff the results.

`sync_rfq_parser.py` proves the code is the source's code; this proves the
answers are the source's answers. Each file goes through `file_parser.parse_file`
and `pipeline.match_items` on both sides, and the two result lists must be equal
field for field.

    python3 tools/compare_rfq_parser.py --matcher /path/to/rfq/wt-main-verified \\
        --corpus "/path/to/rfq/sample rfq" [--text-lines]

Both sides run in their own process with the same interpreter, the AI paths off,
and **the same deterministic cost API**: every code is a stocked cable priced from
a hash of its code, so pricing, MOQ and prepack rules run identically with no
network. The source side gets a throwaway SQLite store, since importing its
pipeline builds one.

Nothing is written into this tree. The corpus is customer data: results stay in
memory and only differences are printed, as field paths and short values.

`--text-lines` also feeds each non-empty line of every `.txt` file through
`match_items` as a pasted line — the paste path the first slice uses.
"""

from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
import tempfile
from pathlib import Path

HERE = Path(__file__).resolve().parent.parent
SUFFIXES = {".xlsx", ".xlsm", ".xls", ".csv", ".txt", ".pdf", ".docx"}

# Fields that legitimately differ between two runs of the same code.
VOLATILE = {"api_timestamp", "cached"}

WORKER = r'''
import hashlib, json, os, sys
from pathlib import Path

mode, listing = sys.argv[1], sys.argv[2]
if mode == "source":
    from matcher import pipeline, cost_api
    from matcher.file_parser import parse_file
    from matcher.extractor import pair_circuit_label_lines
else:
    from rfq_parser import pipeline, cost_api
    from rfq_parser.file_parser import parse_file
    from tricab_cable_rules.extractor import pair_circuit_label_lines

def fake_get(self, url):
    from urllib.parse import urlparse, parse_qs
    code = parse_qs(urlparse(url).query).get("productCode", [""])[0]
    digest = int(hashlib.sha256(code.encode()).hexdigest()[:6], 16)
    price = round(1 + (digest % 5000) / 100, 2)
    return json.dumps({"success": True, "timestamp": "fixed",
                       "data": {"calculatedCostPerMeter": price, "status": "2304",
                                "defaultCost": price, "defaultLength": 6000}}).encode()

cost_api.CostApiClient._http_get = fake_get
cost_api.CostApiClient._cached = lambda self, key: None
cost_api.CostApiClient._store = lambda self, key, result, ttl=None: None

def items_from_file(path):
    out = []
    for idx, item in enumerate(parse_file(path)):
        out.append({"text": item["text"],
                    "source": {"file_name": path.name, "sheet": item.get("sheet"),
                               "row_number": item.get("row_number") or idx + 1},
                    "context": item.get("context") or [],
                    "metadata": item.get("metadata") or {}})
    return out

def items_from_lines(lines):
    return [{"text": text, "source": {"line_number": i + 1}, "context": [],
             "metadata": {"reference": ref} if ref else {}}
            for i, (text, ref) in enumerate(pair_circuit_label_lines(lines))]

for line in open(listing, encoding="utf-8"):
    kind, name = line.rstrip("\n").split("\t", 1)
    path = Path(name)
    try:
        if kind == "file":
            items = items_from_file(path)
        else:
            items = items_from_lines([l for l in path.read_text(encoding="utf-8", errors="replace").splitlines() if l.strip()])
        results = pipeline.match_items(items, budget=pipeline.new_llm_budget(line_count=len(items)))
        record = {"key": kind + ":" + name, "items": items, "results": results}
    except Exception as exc:
        record = {"key": kind + ":" + name, "error": type(exc).__name__ + ": " + str(exc)[:200]}
    sys.stdout.write(json.dumps(record, default=str, sort_keys=True) + "\n")
'''


def _strip(value):
    if isinstance(value, dict):
        return {k: _strip(v) for k, v in value.items() if k not in VOLATILE}
    if isinstance(value, list):
        return [_strip(v) for v in value]
    return value


def _diff(a, b, path="", out=None, limit=8):
    out = [] if out is None else out
    if len(out) >= limit:
        return out
    if isinstance(a, dict) and isinstance(b, dict):
        for key in sorted(set(a) | set(b)):
            if key not in a or key not in b:
                out.append(f"{path}.{key}: only in {'platform' if key in b else 'source'}")
            else:
                _diff(a[key], b[key], f"{path}.{key}", out, limit)
    elif isinstance(a, list) and isinstance(b, list):
        if len(a) != len(b):
            out.append(f"{path}: {len(a)} vs {len(b)} entries")
        for i, (x, y) in enumerate(zip(a, b)):
            _diff(x, y, f"{path}[{i}]", out, limit)
    elif a != b:
        out.append(f"{path}: {str(a)[:60]!r} vs {str(b)[:60]!r}")
    return out


def _run(mode: str, listing: Path, matcher: Path, store: Path) -> dict[str, dict]:
    env = {
        **os.environ,
        "RFQ_READER_ENABLED": "false",
        "RFQ_AGENT_ENABLED": "false",
        "RFQ_NORMALIZER_ENABLED": "false",
        "OPENROUTER_API_KEY": "",
        "FOUNDRY_ENDPOINT": "",
        "RFQ_CATALOG_CACHE": "0",
    }
    if mode == "source":
        env.update(PYTHONPATH=str(matcher), RFQ_STORE_BACKEND="sqlite", REVIEW_LOG_DB=str(store), ACCESS_DB="")
        cwd = matcher
    else:
        env.update(PYTHONPATH=str(HERE))
        cwd = HERE
    proc = subprocess.run(
        [sys.executable, "-c", WORKER, mode, str(listing)],
        cwd=cwd, env=env, capture_output=True, text=True, check=False,
    )
    if proc.returncode != 0:
        raise SystemExit(f"{mode} worker failed:\n{proc.stderr[-3000:]}")
    records = {}
    for line in proc.stdout.splitlines():
        record = json.loads(line)
        records[record.pop("key")] = _strip(record)
    return records


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("--matcher", required=True, help="RFQ parser checkout (the source)")
    parser.add_argument("--corpus", required=True, help="folder of RFQ files; searched recursively")
    parser.add_argument("--exclude", action="append", default=["Results"], help="folder names to skip")
    parser.add_argument("--text-lines", action="store_true", help="also match each .txt line as pasted text")
    args = parser.parse_args(argv)

    matcher, corpus = Path(args.matcher).resolve(), Path(args.corpus).resolve()
    files = sorted(
        p for p in corpus.rglob("*")
        if p.is_file() and p.suffix.lower() in SUFFIXES and not set(args.exclude) & set(p.relative_to(corpus).parts)
    )
    entries = [("file", p) for p in files]
    if args.text_lines:
        entries += [("lines", p) for p in files if p.suffix.lower() == ".txt"]

    with tempfile.TemporaryDirectory() as tmp:
        listing = Path(tmp) / "listing.tsv"
        listing.write_text("".join(f"{kind}\t{path}\n" for kind, path in entries), encoding="utf-8")
        source = _run("source", listing, matcher, Path(tmp) / "store.sqlite3")
        platform = _run("platform", listing, matcher, Path(tmp) / "unused.sqlite3")

    same = rows = 0
    failures = []
    for kind, path in entries:
        key = f"{kind}:{path}"
        a, b = source.get(key), platform.get(key)
        label = f"{kind} {path.relative_to(corpus)}"
        diffs = _diff(a, b)
        if diffs:
            failures.append((label, diffs))
        else:
            same += 1
            rows += len((a or {}).get("results") or [])
    errors = sum(1 for r in source.values() if "error" in r)
    print(f"{same} of {len(entries)} inputs identical ({rows} result rows); {errors} raised on both sides alike")
    for label, diffs in failures:
        print(f"  DIFFERS  {label}")
        for d in diffs:
            print(f"           {d}")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
