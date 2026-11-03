"""Time the Quotegen inspector over a directory of RFQ files.

    python tools/benchmark_quotegen_inspector.py "/path/to/sample rfq" [--url http://127.0.0.1:8090 --token T] [--out results.md]

Read-only on the inputs. Without --url it calls the inspector functions in
process; with --url it uploads to a running container and calls it over HTTP.
"""
from __future__ import annotations

import argparse
import json
import os
import statistics
import sys
import time
import urllib.request
from collections import defaultdict
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from quotegen_inspector.inventory import inventory  # noqa: E402
from quotegen_inspector.tools import render, run_tool  # noqa: E402

FIRST_TOOLS = {
    "message/rfc822": [("eml_overview", {})],
    "application/vnd.ms-outlook": [("msg_overview", {})],
    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet": [("xlsx_overview", {})],
    "application/vnd.ms-excel": [("xls_overview", {})],
    "application/pdf": [("pdf_overview", {}), ("pdf_text", {"page": 1}), ("pdf_tables", {"page": 1})],
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document": [("docx_overview", {})],
    "image/png": [("image_info", {})], "image/jpeg": [("image_info", {})],
    "text/csv": [("text_read", {})], "text/plain": [("text_read", {})],
}
XLSX = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"


def timed(fn):
    t = time.perf_counter()
    try:
        r = fn()
        return time.perf_counter() - t, r, None
    except Exception as e:
        return time.perf_counter() - t, None, f"{type(e).__name__}: {str(e)[:100]}"


def http(url, token, method, path, body=None, raw=None, headers=None):
    h = {"Authorization": f"Bearer {token}", **(headers or {})}
    data = raw
    if body is not None:
        data, h["Content-Type"] = json.dumps(body).encode(), "application/json"
    req = urllib.request.Request(url + path, data=data, method=method, headers=h)
    with urllib.request.urlopen(req, timeout=180) as r:
        return r.read()


def local_runner(path):
    name = {}
    d, inv, e = timed(lambda: inventory(str(path)))
    name["inventory"] = (d, e)
    if e:
        return inv, name
    media = inv["mediaType"]
    for tool, args in FIRST_TOOLS.get(media, []):
        a = dict(args)
        if media == XLSX:
            pass
        d, r, e = timed(lambda: run_tool(str(path), media, tool, a))
        name[tool] = (d, e)
        if media == XLSX and tool == "xlsx_overview" and r and r["sheets"]:
            sh = r["sheets"][0]["name"]
            d, _, e = timed(lambda: run_tool(str(path), media, "xlsx_read", {"sheet": sh, "range": None}))
            name["xlsx_read"] = (d, e)
    arg = {"sheet": None}
    if media == XLSX:
        d, o, _ = timed(lambda: run_tool(str(path), media, "xlsx_overview", {}))
        if o and o["sheets"]:
            arg = {"sheet": o["sheets"][0]["name"], "range": "A1:J40"}
    if inv["renderable"]:
        d, _, e = timed(lambda: render(str(path), media, arg if media == XLSX else {"page": 1, "scale": 1.0}))
        name["render"] = (d, e)
    return inv, name


def remote_runner(path, url, token):
    name = {}
    d, raw, e = timed(lambda: http(url, token, "POST", "/files", raw=path.read_bytes(), headers={"X-Filename": path.name}))
    name["upload+inventory"] = (d, e)
    if e:
        return {"mediaType": "?"}, name
    meta = json.loads(raw)
    media = meta["mediaType"]
    for tool, args in FIRST_TOOLS.get(media, []):
        d, r, e = timed(lambda: http(url, token, "POST", "/inspect", {"fileId": meta["fileId"], "tool": tool, "args": args}))
        name[tool] = (d, e)
    if meta["renderable"]:
        body = {"fileId": meta["fileId"], "page": 1, "scale": 1.0}
        if media == XLSX:
            ov = json.loads(http(url, token, "POST", "/inspect", {"fileId": meta["fileId"], "tool": "xlsx_overview", "args": {}}))
            body = {"fileId": meta["fileId"], "sheet": ov["result"]["sheets"][0]["name"], "range": "A1:J40"}
        d, r, e = timed(lambda: http(url, token, "POST", "/render", body))
        name["render"] = (d, e)
    return meta, name


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("directory")
    ap.add_argument("--url")
    ap.add_argument("--token", default=os.environ.get("QG_TOKEN", ""))
    ap.add_argument("--out")
    a = ap.parse_args()
    files = sorted(p for p in Path(a.directory).rglob("*") if p.is_file() and not p.name.startswith("."))
    by_type = defaultdict(lambda: defaultdict(list))
    errors = defaultdict(list)
    counts = defaultdict(int)
    t_all = time.perf_counter()
    for p in files:
        meta, steps = remote_runner(p, a.url, a.token) if a.url else local_runner(p)
        media = meta.get("mediaType", "?")
        counts[media] += 1
        for step, (d, e) in steps.items():
            by_type[media][step].append(d)
            if e:
                errors[media].append(f"{p.name} {step}: {e}")
    lines = [f"mode: {'http ' + a.url if a.url else 'in-process'}; files: {len(files)}; wall: {time.perf_counter() - t_all:.1f}s", "",
             "| media type | files | step | median ms | p95 ms | max ms |", "|---|---|---|---|---|---|"]
    for media in sorted(by_type):
        for step, ds in by_type[media].items():
            ms = sorted(x * 1000 for x in ds)
            p95 = ms[min(len(ms) - 1, int(len(ms) * 0.95))]
            lines.append(f"| {media} | {counts[media]} | {step} | {statistics.median(ms):.0f} | {p95:.0f} | {ms[-1]:.0f} |")
    lines += ["", f"errors: {sum(len(v) for v in errors.values())}"]
    for media, es in errors.items():
        lines += [f"- {media}: {len(es)}"] + [f"  - {e}" for e in es[:8]]
    text = "\n".join(lines)
    print(text)
    if a.out:
        Path(a.out).write_text(text + "\n")


if __name__ == "__main__":
    main()
