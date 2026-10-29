#!/usr/bin/env python3
"""Shadow evaluation of Claude Haiku 5.5 on Foundry against the finance purposes' current models (plan O4/O5).

Replays real Tri-AP and Tri-AR mail through `finance_ai` on the Foundry path and compares the answers with what is
stored. Production is untouched: nothing here writes to the database, the mailboxes or the blob store, and the
purposes' provider settings are not read (every call names its provider).

**What "stored" means.** Neither app keeps the model's first answer once a person edits a record, and in the
60 days before 16 Oct 2026 almost nobody had (AP: 710 invoices, none completed or approved, one edited; AR: 1,373
emails, two sent, two edited). So the stored values are the current models' own answers (Gemini 2.5 Flash for AP and
the remittances, Jev 1.13 for AR classification), plus the few a person changed. Each case records whether a person
touched it. Agreement with the stored value is therefore agreement with the current model, not accuracy; where the
two disagree, `adjudicate` asks a stronger Claude model, reading the same document, which answer is right, and
`report` lists the disagreements for a person to check.

    # Settings come from the environment, or from --env-file on any step (a shell `source` breaks the blob
    # connection string at its semicolons).
    # 1. Export the cases (reads tricab-mel and the finance blob container; no model calls)
    python3 tools/finance_shadow_eval.py export --days 60 --out ~/finance-shadow-eval
    # 2. Run Haiku 5.5 on them (Foundry; costs money)
    python3 tools/finance_shadow_eval.py run --provider foundry --out ~/finance-shadow-eval
    # 3. Have a stronger model judge the disagreements (Foundry; costs money)
    python3 tools/finance_shadow_eval.py adjudicate --model claude-sonnet-5-5 --out ~/finance-shadow-eval
    # 4. Write report.md
    python3 tools/finance_shadow_eval.py report --out ~/finance-shadow-eval

`estimate` prints the case counts and an upper cost bound before anything is spent.

Needs, beyond `requirements.txt`: `pyodbc` and the ODBC Driver 18 plus `az login` (the database token), and
`azure-storage-blob` with `BLOB_CONNECTION_STRING` (the finance container). Foundry calls use `FOUNDRY_ENDPOINT` with
`FOUNDRY_AUTH_MODE=api-key` and `FOUNDRY_API_KEY` locally, as `platform/local-db/env.live` sets them. The output
directory holds customer mail and invoices: keep it outside every repository and delete it when the decision is made.
Every step can be re-run; `run` and `adjudicate` skip the cases they already have.
"""

from __future__ import annotations

import argparse
import base64
import hashlib
import json
import os
import random
import re
import statistics
import struct
import subprocess
import sys
import threading
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import date, datetime
from decimal import Decimal
from pathlib import Path
from typing import Any

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from finance_ai import provider, purposes, registry  # noqa: E402
from finance_ai.purposes import ap as ap_purposes  # noqa: E402
from finance_ai.purposes import ar as ar_purposes  # noqa: E402

DB_SERVER = "tricab-mel.database.windows.net"
DB_NAME = "tc"

# Per million tokens. Haiku 5.5 is the 100K-or-fewer prompt rate (every finance prompt is far below 100K); Foundry
# bills Claude at Anthropic's list rates. Gemini 2.5 Flash is OpenRouter's list rate. Jev 1.13 has no published rate.
PRICES = {
    "claude-haiku-5-5": (0.10, 0.50),
    "claude-sonnet-5-5": (2.00, 10.00),
    "claude-opus-5-5": (4.00, 20.00),
    "google/gemini-2.5-flash": (0.30, 2.50),
}

# The 92.5% gate (99 of 107 invoices billed to the right entity, Finance Phase 6 Q9).
GATE = 0.925

AP_FIELDS = ("invoice_no", "vendor", "company", "invoice_date", "due_date", "po_number", "currency",
             "net_amount", "tax_amount", "total_amount", "tax_id", "vendor_email")
# The fields an adjudicator is asked about; the rest are compared but not judged.
AP_KEY_FIELDS = ("is_invoice", "invoice_no", "company", "invoice_date", "total_amount", "vendor")
REMITTANCE_FIELDS = ("is_remittance", "payer_name", "payment_date", "payment_reference", "currency", "total_amount")


# --------------------------------------------------------------------------
# Small helpers
# --------------------------------------------------------------------------


def _json_default(value: Any) -> Any:
    if isinstance(value, Decimal):
        return float(value)
    if isinstance(value, (datetime, date)):
        return value.isoformat()
    if isinstance(value, bytes):
        return base64.b64encode(value).decode()
    raise TypeError(type(value).__name__)


def _write_jsonl(path: Path, rows: list[dict[str, Any]]) -> None:
    with path.open("w", encoding="utf-8") as handle:
        for row in rows:
            handle.write(json.dumps(row, default=_json_default, ensure_ascii=False) + "\n")


def _read_jsonl(path: Path) -> list[dict[str, Any]]:
    if not path.exists():
        return []
    return [json.loads(line) for line in path.read_text(encoding="utf-8").splitlines() if line.strip()]


_append_lock = threading.Lock()


def _append_jsonl(path: Path, row: dict[str, Any]) -> None:
    with _append_lock, path.open("a", encoding="utf-8") as handle:
        handle.write(json.dumps(row, default=_json_default, ensure_ascii=False) + "\n")


def _sniff(raw: bytes, declared: str | None) -> str | None:
    """The type `registry.file_part` accepts, from the bytes (many attachments are stored as octet-stream)."""
    if raw.startswith(b"%PDF"):
        return "application/pdf"
    if raw.startswith(b"\x89PNG"):
        return "image/png"
    if raw.startswith(b"\xff\xd8\xff"):
        return "image/jpeg"
    if raw[:4] in (b"II*\x00", b"MM\x00*"):
        return "image/tiff"
    declared = (declared or "").split(";")[0].strip().lower()
    return declared if declared in registry.FILE_TYPES else None


def _norm_text(value: Any) -> str:
    return re.sub(r"[^a-z0-9]", "", str(value or "").lower())


def _norm_company(value: Any) -> str:
    text = re.sub(r"\b(pty|ltd|limited|p/l|inc|llc|pte)\b", "", str(value or "").lower())
    return re.sub(r"[^a-z0-9]", "", text)


def _amount(value: Any) -> float | None:
    if value is None or value == "":
        return None
    try:
        return round(float(value), 2)
    except (TypeError, ValueError):
        return None


def _same(field: str, a: Any, b: Any) -> bool:
    if field.endswith("_amount"):
        x, y = _amount(a), _amount(b)
        return x == y or (x is not None and y is not None and abs(x - y) < 0.011)
    if field in ("invoice_date", "due_date", "payment_date"):
        return (ap_purposes.normalize_date(a) or None) == (ap_purposes.normalize_date(b) or None)
    if field == "company":
        return _norm_company(a) == _norm_company(b)
    if field in ("is_invoice", "is_remittance"):
        return bool(a) == bool(b)
    if field == "currency":
        return str(a or "AUD").upper() == str(b or "AUD").upper()
    return _norm_text(a) == _norm_text(b)


def _cost(model: str | None, usage: dict[str, Any] | None) -> float | None:
    if not usage:
        return None
    if isinstance(usage.get("cost"), (int, float)):
        return float(usage["cost"])
    price = PRICES.get(model or "")
    if price is None:
        return None
    tokens_in = (usage.get("input_tokens") or usage.get("prompt_tokens") or 0) + (usage.get("cache_read_input_tokens") or 0) \
        + (usage.get("cache_creation_input_tokens") or 0)
    tokens_out = usage.get("output_tokens") or usage.get("completion_tokens") or 0
    return (tokens_in * price[0] + tokens_out * price[1]) / 1_000_000


# --------------------------------------------------------------------------
# export
# --------------------------------------------------------------------------


def _connect():
    import pyodbc

    token = json.loads(subprocess.check_output(
        ["az", "account", "get-access-token", "--resource", "https://database.windows.net/", "-o", "json"],
    ))["accessToken"].encode("utf-16-le")
    return pyodbc.connect(
        f"Driver={{ODBC Driver 18 for SQL Server}};Server=tcp:{DB_SERVER},1433;Database={DB_NAME};Encrypt=yes;",
        attrs_before={1256: struct.pack("<i", len(token)) + token},  # SQL_COPT_SS_ACCESS_TOKEN
        timeout=90,
    )


def _rows(cursor, sql: str, *params: Any) -> list[dict[str, Any]]:
    cursor.execute(sql, *params)
    columns = [c[0] for c in cursor.description]
    return [dict(zip(columns, row)) for row in cursor.fetchall()]


class _Files:
    """Attachment bytes from the finance container, cached on disk by blob path."""

    def __init__(self, out: Path):
        from azure.storage.blob import BlobServiceClient

        connection = os.environ.get("BLOB_CONNECTION_STRING", "").strip()
        if not connection:
            raise SystemExit("BLOB_CONNECTION_STRING is not set (source platform/local-db/env.live).")
        self.container = BlobServiceClient.from_connection_string(connection).get_container_client(
            os.environ.get("BLOB_CONTAINER_FINANCE", "finance-attachments"))
        self.dir = out / "files"
        self.dir.mkdir(parents=True, exist_ok=True)

    def get(self, blob_path: str) -> tuple[str, bytes]:
        key = hashlib.sha256(blob_path.encode()).hexdigest()[:32]
        cached = self.dir / key
        if not cached.exists():
            cached.write_bytes(self.container.download_blob(blob_path).readall())
        return key, cached.read_bytes()


def _options(cursor) -> dict[str, list[dict[str, str]]]:
    out: dict[str, list[dict[str, str]]] = {"category": [], "method": []}
    for row in _rows(cursor, "SELECT list_name, value, label, description FROM finance.ar_option_lists "
                             "WHERE active = 1 ORDER BY list_name, sort_order, value"):
        out.setdefault(row["list_name"], []).append(
            {"value": row["value"], "label": row["label"] or "", "description": row["description"] or ""})
    return out


def _companies(cursor) -> list[dict[str, str]]:
    # ApExtractService::companies(): the active companies, as {name, sites, former_names}.
    return [{"name": r["name"], "sites": r["sites"] or "", "former_names": r["former_names"] or ""}
            for r in _rows(cursor, "SELECT name, sites, former_names FROM finance.invoice_companies WHERE is_active = 1 "
                                   "ORDER BY created_date DESC")]


def cmd_export(args: argparse.Namespace) -> None:
    out = Path(args.out).expanduser()
    out.mkdir(parents=True, exist_ok=True)
    cursor = _connect().cursor()
    files = _Files(out)
    rng = random.Random(args.seed)
    cases: list[dict[str, Any]] = []
    skipped: dict[str, int] = {}
    window = "DATEADD(day, -?, SYSUTCDATETIME())"

    options = _options(cursor)
    companies = _companies(cursor)

    # --- Tri-AR: classification and prose, from the emails themselves ---
    ar = _rows(cursor, f"""
        SELECT id, email_date, sender_name, sender_email, subject, body, customer_name, ai_summary,
               follow_up_category, proposed_response_type, classification_confidence, follow_up_status, status,
               created_date, updated_date
          FROM finance.ar_email_records
         WHERE deleted_at IS NULL AND email_date >= {window}
         ORDER BY email_date""", args.days)
    for row in ar:
        touched = row["status"] != "new" or (row["updated_date"] and row["created_date"]
                                             and (row["updated_date"] - row["created_date"]).total_seconds() > 300)
        email = {k: row[k] or "" for k in ("sender_name", "sender_email", "subject", "body")}
        cases.append({
            "id": f"ar_classify:{row['id']}", "purpose": "ar_classify",
            "data": {**email, "categories": options["category"], "methods": options["method"]},
            "stored": {k: row[k] for k in ("follow_up_category", "proposed_response_type", "classification_confidence",
                                           "follow_up_status")},
            "touched": bool(touched), "date": row["email_date"],
        })
    for row in rng.sample(ar, min(args.prose_sample, len(ar))):
        cases.append({
            "id": f"ar_prose:{row['id']}", "purpose": "ar_prose",
            "data": {k: row[k] or "" for k in ("sender_name", "sender_email", "subject", "body")},
            "stored": {"customer_name": row["customer_name"], "ai_summary": row["ai_summary"]},
            "touched": row["status"] != "new", "date": row["email_date"],
        })

    # --- Tri-AR: remittance extraction, one case per attachment the scan read ---
    remittances = _rows(cursor, f"""
        SELECT x.id, x.is_remittance, x.payer_name, x.payment_date, x.payment_reference, x.currency, x.total_amount,
               x.extraction_confidence, x.reconciles, x.extraction_status,
               a.blob_path, a.original_name, a.content_type, r.subject, r.sender_name, r.sender_email, r.email_date
          FROM finance.ar_email_attachments x
          JOIN finance.attachments a ON a.id = x.attachment_id
          JOIN finance.ar_email_records r ON r.id = x.email_record_id
         WHERE r.deleted_at IS NULL AND r.email_date >= {window} AND x.extraction_status = 'extracted'
         ORDER BY r.email_date""", args.days)
    lines: dict[str, list[dict[str, Any]]] = {}
    for line in _rows(cursor, "SELECT email_attachment_id, line_no, invoice_number, invoice_date, gross_amount, "
                              "discount_amount, paid_amount FROM finance.ar_remittance_lines ORDER BY line_no"):
        lines.setdefault(line["email_attachment_id"], []).append(line)
    for row in remittances:
        _add_file_case(cases, skipped, files, row, "ar_remittance", {
            "subject": row["subject"] or "", "sender": f"{row['sender_name'] or ''} <{row['sender_email'] or ''}>".strip(),
        }, {
            **{k: row[k] for k in REMITTANCE_FIELDS}, "reconciles": row["reconciles"],
            "line_items": [{k: l[k] for k in ("invoice_number", "invoice_date", "gross_amount", "discount_amount",
                                              "paid_amount")} for l in lines.get(row["id"], [])],
        }, touched=False)

    # --- Tri-AP: every attachment the scan read in the window, the invoices with what is stored for them ---
    attachments = _rows(cursor, f"""
        SELECT x.id, x.is_invoice, x.invoice_id, a.blob_path, a.original_name, x.content_type, e.subject,
               e.sender_name, e.sender_email, e.received_at
          FROM finance.invoice_email_attachments x
          JOIN finance.invoice_emails e ON e.id = x.email_record_id
          JOIN finance.attachments a ON a.id = x.file_id
         WHERE e.deleted_at IS NULL AND e.received_at >= {window}
         ORDER BY e.received_at""", args.days)
    invoices = {r["id"]: r for r in _rows(cursor, f"""
        SELECT id, status, approval_status, created_date, updated_date, {', '.join(AP_FIELDS)}
          FROM finance.invoice_invoices WHERE deleted_at IS NULL AND email_date >= {window}""", args.days)}
    non_invoices = [a for a in attachments if not a["is_invoice"]]
    keep_non = {a["id"] for a in rng.sample(non_invoices, min(args.non_invoice_sample, len(non_invoices)))}
    for row in attachments:
        invoice = invoices.get(row["invoice_id"]) if row["invoice_id"] else None
        if row["is_invoice"] and invoice is None:
            skipped["ap invoice row missing or deleted"] = skipped.get("ap invoice row missing or deleted", 0) + 1
            continue
        if not row["is_invoice"] and row["id"] not in keep_non:
            continue
        stored = {"is_invoice": bool(row["is_invoice"])}
        touched = False
        if invoice:
            stored.update({k: invoice[k] for k in AP_FIELDS})
            stored["status"] = invoice["status"]
            touched = invoice["status"] in ("Completed", "Exported") or invoice["approval_status"] not in (None, "Not requested") \
                or (invoice["updated_date"] - invoice["created_date"]).total_seconds() > 600
        _add_file_case(cases, skipped, files, {**row, "email_date": row["received_at"]}, "ap_classify_extract", {
            "subject": row["subject"] or "", "sender": f"{row['sender_name'] or ''} <{row['sender_email'] or ''}>".strip(),
            "companies": companies,
        }, stored, touched=touched)

    _write_jsonl(out / "cases.jsonl", cases)
    counts: dict[str, int] = {}
    for case in cases:
        counts[case["purpose"]] = counts.get(case["purpose"], 0) + 1
    (out / "export.json").write_text(json.dumps({
        "exported_at": datetime.now().isoformat(timespec="seconds"), "days": args.days, "counts": counts,
        "touched": sum(1 for c in cases if c["touched"]), "skipped": skipped,
    }, indent=2))
    print(json.dumps({"counts": counts, "skipped": skipped}, indent=2))


def _add_file_case(cases, skipped, files, row, purpose, data, stored, *, touched) -> None:
    try:
        key, raw = files.get(row["blob_path"])
    except Exception as exc:  # noqa: BLE001 - a missing blob is a skipped case, counted
        skipped[f"{purpose}: blob unreadable ({type(exc).__name__})"] = skipped.get(f"{purpose}: blob unreadable ({type(exc).__name__})", 0) + 1
        return
    media = _sniff(raw, row.get("content_type"))
    if media is None:
        skipped[f"{purpose}: not a PDF or image"] = skipped.get(f"{purpose}: not a PDF or image", 0) + 1
        return
    cases.append({
        "id": f"{purpose}:{row['id']}", "purpose": purpose,
        "data": {**data, "file": {"name": row["original_name"] or "attachment", "content_type": media, "file_key": key}},
        "stored": stored, "touched": touched, "date": row["email_date"], "bytes": len(raw),
    })


def _hydrate(case: dict[str, Any], out: Path) -> dict[str, Any]:
    data = dict(case["data"])
    if "file" in data:
        f = dict(data["file"])
        f["data"] = base64.b64encode((out / "files" / f.pop("file_key")).read_bytes()).decode()
        data["file"] = f
    return data


# --------------------------------------------------------------------------
# estimate
# --------------------------------------------------------------------------


def cmd_estimate(args: argparse.Namespace) -> None:
    """An upper bound, not a forecast: about 1,600 tokens per PDF page (assumed at 2 pages per 150 KB) and the full
    `max_tokens` worth of thinking on 25% of calls are both pessimistic."""
    out = Path(args.out).expanduser()
    cases = _read_jsonl(out / "cases.jsonl")
    model_in, model_out = PRICES[args.model]
    total, rows = 0.0, {}
    for case in cases:
        pages = max(1, round(case.get("bytes", 0) / 75_000)) if "file" in case["data"] else 0
        text = len(json.dumps(case["data"].get("body", ""))) // 4 + 1500
        tokens_in = text + pages * 1600
        tokens_out = 1500 if case["purpose"] != "ar_classify" else 600
        cost = (tokens_in * model_in + tokens_out * model_out) / 1_000_000
        total += cost
        n, c = rows.get(case["purpose"], (0, 0.0))
        rows[case["purpose"]] = (n + 1, c + cost)
    for name, (n, c) in sorted(rows.items()):
        print(f"{name:22} {n:5} cases  ≤ ${c:7.2f}")
    print(f"{'total':22} {len(cases):5} cases  ≤ ${total:7.2f}  ({args.model})")


# --------------------------------------------------------------------------
# run
# --------------------------------------------------------------------------


class _Budget:
    """Stops new calls once the evaluation's Foundry spend (every results and verdicts file so far) reaches the cap."""

    def __init__(self, out: Path, cap: float | None):
        self.cap = cap
        self.lock = threading.Lock()
        self.spent = sum(_cost(r.get("trace", {}).get("model"), r.get("trace", {}).get("usage")) or 0
                         for r in _read_jsonl(out / "results_foundry.jsonl"))
        self.spent += sum(_cost(r.get("model"), r.get("usage")) or 0 for r in _read_jsonl(out / "verdicts.jsonl"))

    def allows(self) -> bool:
        with self.lock:
            return self.cap is None or self.spent < self.cap

    def add(self, cost: float | None) -> None:
        with self.lock:
            self.spent += cost or 0


def _register() -> None:
    purposes.register_ap()
    purposes.register_ar()


def cmd_run(args: argparse.Namespace) -> None:
    out = Path(args.out).expanduser()
    _register()
    cases = [c for c in _read_jsonl(out / "cases.jsonl") if not args.purposes or c["purpose"] in args.purposes]
    results_path = out / f"results_{args.provider}.jsonl"
    done = {r["id"] for r in _read_jsonl(results_path) if args.retry_errors is False or "result" in r}
    todo = [c for c in cases if c["id"] not in done][: args.limit or None]
    print(f"{len(todo)} cases to run on {args.provider} ({len(done)} already done)")

    budget = _Budget(out, args.budget)
    print(f"spent so far ${budget.spent:.2f}" + (f" of ${args.budget:.2f}" if args.budget else ""))

    def one(case: dict[str, Any]) -> dict[str, Any] | None:
        if not budget.allows():
            return None
        purpose = registry.get(case["purpose"])
        data = _hydrate(case, out)
        trace: dict[str, Any] = {}
        row: dict[str, Any] = {"id": case["id"], "purpose": case["purpose"]}
        try:
            prompt = purpose.build_prompt(data)
            parts = purpose.build_parts(data) if purpose.build_parts else None
            request = purpose.build_request(data) if purpose.build_request else None
            # The deployment's per-minute limits answer bursts with 429; wait and try again rather than count it.
            for attempt in range(8):
                trace.clear()
                try:
                    row["result"] = provider.complete_json(
                        purpose, prompt, correlation_id=f"shadow-{case['id']}", data=data, parts=parts,
                        request=request, provider=args.provider, trace=trace,
                    )
                    break
                except provider.ProviderError as exc:
                    if "429" not in exc.message or attempt == 7:
                        raise
                    row["throttled"] = attempt + 1
                    time.sleep(min(60, 5 * 2 ** attempt) * (0.5 + random.random()))
        except provider.ProviderError as exc:
            row["error"] = {"code": exc.code, "message": exc.message}
        except registry.InvalidData as exc:
            row["error"] = {"code": "invalid_data", "message": str(exc)}
        row["trace"] = trace
        budget.add(_cost(trace.get("model"), trace.get("usage")))
        _append_jsonl(results_path, row)
        return row

    errors = stopped = 0
    with ThreadPoolExecutor(max_workers=args.workers) as pool:
        for i, future in enumerate(as_completed([pool.submit(one, c) for c in todo]), 1):
            row = future.result()
            stopped += row is None
            errors += row is not None and "error" in row
            if i % 25 == 0 or i == len(todo):
                print(f"  {i}/{len(todo)} ({errors} errors, ${budget.spent:.2f} spent)", flush=True)
    if stopped:
        print(f"budget reached: {stopped} cases not run")


# --------------------------------------------------------------------------
# Comparison
# --------------------------------------------------------------------------


def _compare(case: dict[str, Any], result: dict[str, Any]) -> dict[str, bool]:
    stored = case["stored"]
    if case["purpose"] == "ar_classify":
        return {
            "category": result["follow_up_category"] == stored["follow_up_category"],
            "method": result["proposed_response_type"] == stored["proposed_response_type"],
            "status": result["follow_up_status"] == stored["follow_up_status"],
        }
    if case["purpose"] == "ar_prose":
        return {"customer_name": _norm_company(result["customer_name"]) == _norm_company(stored["customer_name"])}
    if case["purpose"] == "ar_remittance":
        out = {f: _same(f, result.get(f), stored.get(f)) for f in REMITTANCE_FIELDS}
        if stored.get("is_remittance") and result.get("is_remittance"):
            mine = sorted(_norm_text(l["invoice_number"]) for l in result["line_items"])
            theirs = sorted(_norm_text(l["invoice_number"]) for l in stored["line_items"])
            out["line_invoice_numbers"] = mine == theirs
        return out
    if case["purpose"] == "ap_classify_extract":
        out = {"is_invoice": bool(result.get("is_invoice")) == bool(stored["is_invoice"])}
        if stored["is_invoice"] and result.get("is_invoice"):
            out.update({f: _same(f, result.get(f), stored.get(f)) for f in AP_FIELDS})
        return out
    return {}


# --------------------------------------------------------------------------
# adjudicate
# --------------------------------------------------------------------------

JUDGE_PROMPT = """You check two automated readings of the same {thing} against the document itself.

{context}

For each field below, the two readings disagree. Read the document and say which reading is correct: "A", "B",
"both" (they mean the same thing), or "neither". Judge only from the document{extra}. Be strict about numbers and
invoice numbers: one wrong digit is wrong.

{fields}
"""


def _judge_schema(fields: list[str]) -> dict[str, Any]:
    verdict = registry.strict_object({
        "verdict": {"type": "string", "enum": ["A", "B", "both", "neither"]},
        "correct_value": registry.nullable({"type": "string"}),
        "reason": {"type": "string"},
    })
    return registry.strict_object({f: verdict for f in fields})


def _judge_case(case: dict[str, Any], mine: dict[str, Any], out: Path, model: str, rng: random.Random) -> dict[str, Any] | None:
    agreement = _compare(case, mine)
    fields = [f for f, ok in agreement.items() if not ok]
    if case["purpose"] == "ap_classify_extract":
        fields = [f for f in fields if f in AP_KEY_FIELDS]
    elif case["purpose"] == "ar_remittance":
        fields = [f for f in fields if f in ("is_remittance", "total_amount", "payment_date", "line_invoice_numbers")]
    elif case["purpose"] == "ar_classify":
        fields = [f for f in fields if f in ("category", "method")]
    else:
        return None
    if not fields:
        return None
    # Which reading is A is chosen at random, so a judge's leaning to the first answer cannot favour either model.
    haiku_is_a = rng.random() < 0.5
    stored = case["stored"]

    def reading(source: dict[str, Any], field: str) -> Any:
        if case["purpose"] == "ar_classify":
            return source.get({"category": "follow_up_category", "method": "proposed_response_type"}[field])
        if field == "line_invoice_numbers":
            return [l.get("invoice_number") for l in source.get("line_items") or []]
        return source.get(field)

    lines = []
    for f in fields:
        a, b = (reading(mine, f), reading(stored, f)) if haiku_is_a else (reading(stored, f), reading(mine, f))
        lines.append(f"- {f}: A = {json.dumps(a, default=_json_default)}; B = {json.dumps(b, default=_json_default)}")
    data = _hydrate(case, out)
    if case["purpose"] == "ar_classify":
        thing, extra = "email", " and the category and method definitions"
        context = ar_purposes._build_classify_prompt(data).split("Answer three questions")[0]
        content: list[dict[str, Any]] = []
    else:
        thing = "invoice" if case["purpose"] == "ap_classify_extract" else "remittance advice"
        extra = ""
        context = f"Email subject: {data.get('subject', '')}\nEmail sender: {data.get('sender', '')}"
        if case["purpose"] == "ap_classify_extract":
            context += ap_purposes.company_clause(data.get("companies"))
            context += "\nis_invoice means an invoice or tax invoice to be processed for payment (not a statement, PO, " \
                       "remittance, quote or receipt with nothing due)."
        content = [provider.foundry_block(registry.file_part(data["file"]))]
    content.append({"type": "text", "text": JUDGE_PROMPT.format(thing=thing, context=context, extra=extra, fields="\n".join(lines))})
    payload = {
        "model": model, "max_tokens": 16000, "messages": [{"role": "user", "content": content}],
        "output_config": {"effort": "high", "format": {"type": "json_schema", "schema": _judge_schema(fields)}},
    }
    row: dict[str, Any] = {"id": case["id"], "purpose": case["purpose"], "model": model, "haiku_is_a": haiku_is_a}
    try:
        message = provider._foundry_messages_create(payload, 180)
        text = "".join(b.get("text", "") for b in message.get("content") or [] if b.get("type") == "text")
        verdicts = json.loads(text) if message.get("stop_reason") == "end_turn" else {}
        row["usage"] = message.get("usage")
        row["verdicts"] = {
            f: {**v, "winner": {"A": "haiku" if haiku_is_a else "current", "B": "current" if haiku_is_a else "haiku"}.get(v["verdict"], v["verdict"])}
            for f, v in verdicts.items()
        }
        if not verdicts:
            row["error"] = {"code": "no_verdict", "message": str(message.get("stop_reason"))}
    except provider.ProviderError as exc:
        row["error"] = {"code": exc.code, "message": exc.message}
    return row


def cmd_adjudicate(args: argparse.Namespace) -> None:
    out = Path(args.out).expanduser()
    _register()
    cases = {c["id"]: c for c in _read_jsonl(out / "cases.jsonl")}
    mine = {r["id"]: r for r in _read_jsonl(out / "results_foundry.jsonl") if "result" in r}
    path = out / "verdicts.jsonl"
    done = {r["id"] for r in _read_jsonl(path) if "verdicts" in r}
    rng = random.Random(args.seed)
    todo = [(cases[i], mine[i]["result"]) for i in mine if i in cases and i not in done]
    todo = [t for t in todo if any(not ok for ok in _compare(*t).values())][: args.limit or None]
    print(f"{len(todo)} disagreements to judge with {args.model}")
    seeds = [rng.random() for _ in todo]

    budget = _Budget(out, args.budget)
    print(f"spent so far ${budget.spent:.2f}" + (f" of ${args.budget:.2f}" if args.budget else ""))

    def one(item, seed):
        if not budget.allows():
            return None
        row = _judge_case(item[0], item[1], out, args.model, random.Random(seed))
        if row:
            budget.add(_cost(row.get("model"), row.get("usage")))
            _append_jsonl(path, row)
        return row

    with ThreadPoolExecutor(max_workers=args.workers) as pool:
        futures = [pool.submit(one, t, s) for t, s in zip(todo, seeds)]
        for i, future in enumerate(as_completed(futures), 1):
            future.result()
            if i % 25 == 0 or i == len(futures):
                print(f"  {i}/{len(futures)} (${budget.spent:.2f} spent)", flush=True)


# --------------------------------------------------------------------------
# report
# --------------------------------------------------------------------------


def _pct(n: int, d: int) -> str:
    return f"{n}/{d} ({100 * n / d:.1f}%)" if d else "n/a"


def _quantile(values: list[float], q: float) -> float | None:
    if not values:
        return None
    values = sorted(values)
    return values[min(len(values) - 1, int(q * len(values)))]


def cmd_report(args: argparse.Namespace) -> None:
    out = Path(args.out).expanduser()
    cases = {c["id"]: c for c in _read_jsonl(out / "cases.jsonl")}
    results = {r["id"]: r for r in _read_jsonl(out / "results_foundry.jsonl")}
    current = {r["id"]: r for r in _read_jsonl(out / "results_openrouter.jsonl")}
    verdicts = {r["id"]: r for r in _read_jsonl(out / "verdicts.jsonl") if "verdicts" in r}
    export = json.loads((out / "export.json").read_text()) if (out / "export.json").exists() else {}
    md: list[str] = []
    w = md.append

    w("# Finance AI shadow evaluation: Claude Haiku 5.5 on Foundry\n")
    w(f"Cases exported {export.get('exported_at', '?')} from tricab-mel, mail of the last {export.get('days', '?')} days. "
      f"Report written {datetime.now().isoformat(timespec='minutes')}.\n")
    w("**Read this first.** Agreement is with the **stored answer**: what the current model wrote at the time "
      "(Gemini 2.5 Flash for AP and remittances, Jev 1.13 for AR classification). People had changed almost none of them "
      f"({export.get('touched', '?')} of the exported cases had been touched by a person). It is not accuracy. Where "
      "the two disagree, a stronger model (Claude Sonnet 5.5) read the same document and said which answer was right. "
      "**The judge's verdicts are not human-checked results**: the judge is itself a model, every \"estimated accuracy\" "
      "below rests on it, and the disagreements list at the end is for a person to check. **The 92.5% AP comparison is "
      "provisional** until a person-checked invoice set exists (Finance Phase 6 B4).\n")

    by_purpose: dict[str, list[str]] = {}
    for cid, case in cases.items():
        by_purpose.setdefault(case["purpose"], []).append(cid)

    # --- summary ---
    w("## Summary by purpose\n")
    w("| Purpose | Cases | Haiku answered | Agrees with stored (main field) | Judge on disagreements: Haiku right / current right / both / neither | Haiku p50 / p95 latency | Haiku cost per call |")
    w("|---|---|---|---|---|---|---|")
    main_field = {"ap_classify_extract": "company", "ar_classify": "category", "ar_remittance": "total_amount", "ar_prose": "customer_name"}
    for name in sorted(by_purpose):
        ids = by_purpose[name]
        ran = [results[i] for i in ids if i in results]
        ok = [r for r in ran if "result" in r]
        field = main_field.get(name)
        comparable = [(cases[r["id"]], r["result"]) for r in ok]
        agreements = [_compare(c, res) for c, res in comparable]
        with_field = [a for a in agreements if field in a]
        agree = sum(1 for a in with_field if a[field])
        tally = {"haiku": 0, "current": 0, "both": 0, "neither": 0}
        for r in ok:
            v = verdicts.get(r["id"], {}).get("verdicts", {}).get(_judge_field(name, field))
            if v:
                tally[v["winner"]] = tally.get(v["winner"], 0) + 1
        latencies = [r["trace"]["latency_ms"] for r in ok if "latency_ms" in r.get("trace", {})]
        costs = [c for c in (_cost(r["trace"].get("model"), r["trace"].get("usage")) for r in ok) if c is not None]
        w(f"| `{name}` | {len(ids)} | {_pct(len(ok), len(ran))} | {field}: {_pct(agree, len(with_field))} | "
          f"{tally['haiku']} / {tally['current']} / {tally['both']} / {tally['neither']} | "
          f"{_fmt_ms(_quantile(latencies, .5))} / {_fmt_ms(_quantile(latencies, .95))} | "
          f"{'$%.5f' % statistics.mean(costs) if costs else 'n/a'} |")
    w("")

    # --- AP gate ---
    _report_ap(w, cases, results, verdicts, by_purpose.get("ap_classify_extract", []))
    _report_ar_classify(w, cases, results, verdicts, by_purpose.get("ar_classify", []))
    _report_remittance(w, cases, results, verdicts, by_purpose.get("ar_remittance", []))
    _report_prose(w, cases, results, by_purpose.get("ar_prose", []))
    _report_cost(w, cases, results, current, verdicts)
    _report_errors(w, results)
    _report_disagreements(w, cases, results, verdicts, args.list_limit)

    (out / "report.md").write_text("\n".join(md) + "\n", encoding="utf-8")
    print(f"wrote {out / 'report.md'}")


def _judge_field(purpose: str, field: str | None) -> str | None:
    return {"category": "category"}.get(field, field) if purpose != "ar_prose" else None


def _fmt_ms(value: float | None) -> str:
    return "n/a" if value is None else f"{value / 1000:.1f} s"


def _field_accuracy(cases, results, verdicts, ids, field: str) -> tuple[int, int, int, int, int]:
    """(agree, haiku_judged_right, current_judged_right, unjudged, total) over the cases where `field` is compared.
    Estimated accuracy counts an agreement as right for both (a shared mistake is invisible here)."""
    agree = haiku = cur = unjudged = total = 0
    for i in ids:
        r = results.get(i)
        if not r or "result" not in r:
            continue
        cmp = _compare(cases[i], r["result"])
        if field not in cmp:
            continue
        total += 1
        if cmp[field]:
            agree += 1
            continue
        v = verdicts.get(i, {}).get("verdicts", {}).get(field)
        if not v:
            unjudged += 1
        elif v["winner"] in ("haiku", "both"):
            haiku += 1
            cur += v["winner"] == "both"
        elif v["winner"] == "current":
            cur += 1
    return agree, haiku, cur, unjudged, total


def _report_ap(w, cases, results, verdicts, ids) -> None:
    if not ids:
        return
    w("## Tri-AP: invoice classification and extraction (`ap_classify_extract`)\n")
    invoices = [i for i in ids if cases[i]["stored"]["is_invoice"]]
    others = [i for i in ids if not cases[i]["stored"]["is_invoice"]]
    w(f"{len(invoices)} attachments the current model filed as invoices, {len(others)} sampled from those it judged not "
      "invoices. Field agreement is over invoices both models call invoices.\n")
    w("**The 92.5% gate (provisional)** is the share of invoices billed to the right entity (`company`). It cannot be "
      "passed for real here: there is no person-checked set, and an agreement where both models are wrong is invisible. "
      "Estimated accuracy below "
      "counts agreement as right for both models and gives each disagreement to whichever the judge chose "
      "(`both` counts for both; unjudged disagreements count against both).\n")
    w("| Field | Agree | Haiku est. accuracy | Current est. accuracy | Unjudged | Gate |")
    w("|---|---|---|---|---|---|")
    for field in ("is_invoice", "company", "invoice_no", "total_amount", "invoice_date", "vendor", *[
            f for f in AP_FIELDS if f not in ("company", "invoice_no", "total_amount", "invoice_date", "vendor")]):
        agree, haiku, cur, unjudged, total = _field_accuracy(cases, results, verdicts, ids, field)
        if not total:
            continue
        judged = field in AP_KEY_FIELDS
        h = f"{100 * (agree + haiku) / total:.1f}%" if judged else "—"
        c = f"{100 * (agree + cur) / total:.1f}%" if judged else "—"
        gate = ""
        if field == "company":
            gate = "provisional pass" if (agree + haiku) / total >= GATE else "provisional fail"
        w(f"| {field} | {_pct(agree, total)} | {h} | {c} | {unjudged if judged else '—'} | {gate} |")
    w("")
    if not others:
        w("No attachment judged not an invoice could be included: the scan stores no file for those (only invoices get a "
          "`file_id`), so this evaluation cannot measure Haiku's false positives. A live shadow run would.\n")
    fp = sum(1 for i in others if i in results and results[i].get("result", {}).get("is_invoice"))
    fn = sum(1 for i in invoices if i in results and "result" in results[i] and not results[i]["result"].get("is_invoice"))
    w(f"Haiku called {fp} of the {len(others)} sampled non-invoices an invoice, and {fn} of the {len(invoices)} stored "
      "invoices not an invoice (the judge's view of each is in the disagreements list).\n")


def _report_ar_classify(w, cases, results, verdicts, ids) -> None:
    if not ids:
        return
    w("## Tri-AR: email classification (`ar_classify`)\n")
    w("Jev 1.13 through OpenRouter's Decisions API today; on Foundry the same three questions in one prompt with the "
      "Settings lists as enums, read by the same `parse_classification` and thresholds.\n")
    w("| Field | Agree | Haiku est. accuracy | Jev est. accuracy | Unjudged |")
    w("|---|---|---|---|---|")
    for field in ("category", "method", "status"):
        agree, haiku, cur, unjudged, total = _field_accuracy(cases, results, verdicts, ids, field)
        if field == "status":
            w(f"| status (needs review vs in progress) | {_pct(agree, total)} | — | — | — |")
        else:
            w(f"| {field} | {_pct(agree, total)} | {100 * (agree + haiku) / total:.1f}% | {100 * (agree + cur) / total:.1f}% | {unjudged} |" if total else f"| {field} | n/a | | | |")
    statuses = {"haiku": 0, "jev": 0}
    for i in ids:
        r = results.get(i)
        if r and "result" in r:
            statuses["haiku"] += r["result"]["follow_up_status"] == "needs_review"
            statuses["jev"] += cases[i]["stored"]["follow_up_status"] == "needs_review"
    n = sum(1 for i in ids if i in results and "result" in results[i])
    w(f"\nSent to review: Haiku {_pct(statuses['haiku'], n)}, Jev {_pct(statuses['jev'], n)}.\n")
    # Confusion of the main disagreements.
    pairs: dict[tuple[str, str], int] = {}
    for i in ids:
        r = results.get(i)
        if r and "result" in r and r["result"]["follow_up_category"] != cases[i]["stored"]["follow_up_category"]:
            key = (cases[i]["stored"]["follow_up_category"], r["result"]["follow_up_category"])
            pairs[key] = pairs.get(key, 0) + 1
    if pairs:
        w("Most common category disagreements (Jev → Haiku):\n")
        for (a, b), n in sorted(pairs.items(), key=lambda kv: -kv[1])[:10]:
            w(f"- {a} → {b}: {n}")
        w("")


def _report_remittance(w, cases, results, verdicts, ids) -> None:
    if not ids:
        return
    w("## Tri-AR: remittance extraction (`ar_remittance`)\n")
    w("No person-confirmed remittance lines exist, so this is agreement with Gemini's stored extraction.\n")
    w("| Field | Agree | Haiku est. accuracy | Gemini est. accuracy | Unjudged |")
    w("|---|---|---|---|---|")
    for field in (*REMITTANCE_FIELDS, "line_invoice_numbers"):
        agree, haiku, cur, unjudged, total = _field_accuracy(cases, results, verdicts, ids, field)
        judged = field in ("is_remittance", "total_amount", "payment_date", "line_invoice_numbers")
        if total:
            w(f"| {field} | {_pct(agree, total)} | {f'{100 * (agree + haiku) / total:.1f}%' if judged else '—'} | "
              f"{f'{100 * (agree + cur) / total:.1f}%' if judged else '—'} | {unjudged if judged else '—'} |")
    reconcile = 0
    n = 0
    for i in ids:
        r = results.get(i)
        if r and "result" in r and r["result"]["is_remittance"] and r["result"]["total_amount"] is not None:
            n += 1
            paid = sum(l["paid_amount"] or 0 for l in r["result"]["line_items"])
            reconcile += abs(paid - r["result"]["total_amount"]) < 0.02
    stored_rec = sum(1 for i in ids if cases[i]["stored"].get("reconciles"))
    w(f"\nLines add up to the total: Haiku {_pct(reconcile, n)}; Gemini's stored extractions marked reconciling: "
      f"{stored_rec} of {len(ids)}.\n")


def _report_prose(w, cases, results, ids) -> None:
    if not ids:
        return
    ok = [results[i] for i in ids if i in results and "result" in results[i]]
    w("## Tri-AR: summary and suggested reply (`ar_prose`)\n")
    agree = sum(_compare(cases[r["id"]], r["result"])["customer_name"] for r in ok)
    placeholders = sum(bool(re.search(r"\[[^\]]+\]", r["result"]["proposed_response"])) for r in ok)
    w(f"A sample of {len(ids)} emails. Customer name agrees with the stored one on {_pct(agree, len(ok))}. "
      f"Drafts still carrying a [placeholder] after the parser: {placeholders}. Draft quality has no stored truth; "
      "the first five pairs are below for reading.\n")
    for r in ok[:5]:
        case = cases[r["id"]]
        w(f"<details><summary>{case['data']['subject'][:90]}</summary>\n")
        w(f"**Stored summary:** {case['stored']['ai_summary']}\n\n**Haiku summary:** {r['result']['ai_summary']}\n")
        w(f"**Haiku reply:**\n\n```\n{r['result']['proposed_response']}\n```\n</details>\n")


def _report_cost(w, cases, results, current, verdicts) -> None:
    w("## Cost\n")
    per_email: dict[str, list[float]] = {}
    tokens: dict[str, list[tuple[int, int]]] = {}
    for r in results.values():
        if "result" not in r:
            continue
        usage = r["trace"].get("usage") or {}
        c = _cost(r["trace"].get("model"), usage)
        if c is not None:
            per_email.setdefault(r["purpose"], []).append(c)
            tokens.setdefault(r["purpose"], []).append((usage.get("input_tokens", 0), usage.get("output_tokens", 0)))
    w("| Purpose | Haiku mean input / output tokens | Haiku $ per call | Gemini 2.5 Flash $ per call at the same tokens (estimate) |")
    w("|---|---|---|---|")
    for name, costs in sorted(per_email.items()):
        ti = statistics.mean(t[0] for t in tokens[name])
        to = statistics.mean(t[1] for t in tokens[name])
        gem = (ti * PRICES["google/gemini-2.5-flash"][0] + to * PRICES["google/gemini-2.5-flash"][1]) / 1e6
        w(f"| `{name}` | {ti:,.0f} / {to:,.0f} | ${statistics.mean(costs):.5f} | "
          f"{'n/a (Jev has no published rate)' if name == 'ar_classify' else f'${gem:.5f}'} |")
    judge_cost = sum(_cost(v.get("model"), v.get("usage")) or 0 for v in verdicts.values())
    total = sum(sum(c) for c in per_email.values())
    w(f"\nThis evaluation: Haiku ${total:.2f}, judge ${judge_cost:.2f}. The Gemini column prices Haiku's token counts "
      "at Gemini's rates (the tokenisers differ, and Haiku's output includes its thinking), so it is a rough guide.\n")
    if current:
        lat = [r["trace"]["latency_ms"] for r in current.values() if "result" in r and "latency_ms" in r["trace"]]
        w(f"Current model re-run on {len(current)} cases: p50 {_fmt_ms(_quantile(lat, .5))}, p95 {_fmt_ms(_quantile(lat, .95))}.\n")


def _report_errors(w, results) -> None:
    errors: dict[tuple[str, str], int] = {}
    for r in results.values():
        if "error" in r:
            key = (r["purpose"], r["error"]["code"] + ": " + r["error"]["message"][:80])
            errors[key] = errors.get(key, 0) + 1
    if errors:
        w("## Haiku failures\n")
        for (purpose, reason), n in sorted(errors.items(), key=lambda kv: -kv[1]):
            w(f"- `{purpose}` {reason}: {n}")
        w("")


def _report_disagreements(w, cases, results, verdicts, limit) -> None:
    w("## Disagreements for a person to check\n")
    w("Case ids are `purpose:row id` (the AP id is `finance.invoice_email_attachments.id`, the remittance id "
      "`finance.ar_email_attachments.id`, the AR id `finance.ar_email_records.id`). Values are shortened.\n")
    shown = 0
    for cid, v in sorted(verdicts.items()):
        if shown >= limit:
            w(f"\n…and {len(verdicts) - shown} more in verdicts.jsonl.")
            break
        case = cases.get(cid)
        r = results.get(cid, {}).get("result")
        if not case or not r:
            continue
        for field, verdict in v["verdicts"].items():
            w(f"- `{cid}` **{field}**: judge says **{verdict['winner']}** "
              f"({str(verdict.get('correct_value'))[:40]!r}): {verdict['reason'][:160]}")
        shown += 1
    w("")


# --------------------------------------------------------------------------


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    sub = parser.add_subparsers(dest="command", required=True)

    p = sub.add_parser("export", help="read the cases from tricab-mel and the blob store (no model calls)")
    p.add_argument("--out", required=True)
    p.add_argument("--days", type=int, default=60)
    p.add_argument("--non-invoice-sample", type=int, default=150, help="AP attachments judged not invoices to include")
    p.add_argument("--prose-sample", type=int, default=40, help="AR emails to run ar_prose on")
    p.add_argument("--seed", type=int, default=20261016)
    p.set_defaults(func=cmd_export)

    p = sub.add_parser("estimate", help="print the case counts and an upper cost bound")
    p.add_argument("--out", required=True)
    p.add_argument("--model", default="claude-haiku-5-5", choices=sorted(PRICES))
    p.set_defaults(func=cmd_estimate)

    p = sub.add_parser("run", help="run the cases through one provider")
    p.add_argument("--out", required=True)
    p.add_argument("--provider", choices=provider.PROVIDERS, default=provider.FOUNDRY)
    p.add_argument("--purposes", nargs="*")
    p.add_argument("--limit", type=int, default=0)
    p.add_argument("--workers", type=int, default=6)
    p.add_argument("--retry-errors", action="store_true", help="run again the cases that failed before")
    p.add_argument("--budget", type=float, help="stop starting calls once the evaluation's Foundry spend reaches this (USD)")
    p.set_defaults(func=cmd_run)

    p = sub.add_parser("adjudicate", help="have a stronger model judge the disagreements")
    p.add_argument("--out", required=True)
    p.add_argument("--model", default="claude-sonnet-5-5")
    p.add_argument("--limit", type=int, default=0)
    p.add_argument("--workers", type=int, default=4)
    p.add_argument("--seed", type=int, default=20261016)
    p.add_argument("--budget", type=float, help="stop starting calls once the evaluation's Foundry spend reaches this (USD)")
    p.set_defaults(func=cmd_adjudicate)

    p = sub.add_parser("report", help="write report.md")
    p.add_argument("--out", required=True)
    p.add_argument("--list-limit", type=int, default=150)
    p.set_defaults(func=cmd_report)

    for p in sub.choices.values():
        p.add_argument("--env-file", help="read settings from this .env file (e.g. platform/local-db/env.live) first")
    args = parser.parse_args()
    if args.env_file:
        from dotenv import load_dotenv

        load_dotenv(args.env_file, override=False)
    args.func(args)


if __name__ == "__main__":
    main()
