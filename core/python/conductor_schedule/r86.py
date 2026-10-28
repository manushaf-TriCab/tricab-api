"""R-86 "Conductor summary" print (4js M086conda.4gl): parser (port of R86ReportParser.php) and the latest-file pick.

Every line must be recognised and the size lines must add up to the printed totals, or the file is rejected (exit 3).
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from datetime import date
from pathlib import Path

from cable_routes.loader import SourceError

VERSION = "r86-v1"
MONTHS = {m: i for i, m in enumerate(("Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"), 1)}
NAMES = ("pacw NOmica", "pacw mica", "pacw wght", "tacw NOmica", "tacw mica", "tacw wght", "alumin NOmica", "alumin wght")
_D = r"\d{2}[A-Z][a-z]{2}'\d{2}"
_RULE = re.compile(r"^[\s_]*_{5,}[\s_a-z]*$")
_DASH = re.compile(r"^[\s-]+$")
_HEAD = re.compile(rf"^(\S+)\s+R-86\s+Conductor summary\s+-\s+Period:\s*({_D})->({_D})?\s*$")
_PRINT = re.compile(rf"^\((v[\d.]+)\)\s+Printed:\s*({_D})\s+(\d{{2}}:\d{{2}}:\d{{2}})\s+by:\s*(\S+)\s+page:\s*\d+\s*$")
_SEL = re.compile(r"^Selected:\s+Dbco:(\S+)\s+DocTyp:\s*(order|invoice) data\s*$")
_COLS = re.compile(r"^Csa\s+NOmica_(\w+)\s+mica_(\w+)\s+wght_(\w+)\s+NOmica_(\w+)\s+mica_(\w+)\s+wght_(\w+)\s+NOmica_(\w+)\s+wght_(\w+)\s+X$")
_SIZE = re.compile(r"^([CA])([0-9./]+)((?:\s+-?\d+){8})(?:\s+(-?\d+))?$")
_TOTAL = re.compile(r"^Total:" + r"".join(r"\s+(-?\d+)(\w+)" for _ in range(8)) + "$")


@dataclass
class Row:
    label: str            # C1.5, A10
    system: str           # 'C' copper, 'A' aluminium
    values: tuple         # pacw no-mica m, pacw mica m, pacw kg, tacw no-mica m, tacw mica m, tacw kg, alu m, alu kg
    unknown_style_m: int | None   # the optional 10th column "X": outside the totals, not demand

    @property
    def csa(self) -> float | None:
        try:
            return float(self.label[1:])
        except ValueError:
            return None


@dataclass
class Report:
    doc: str              # 'order' | 'invoice'
    site: str
    company: str
    period_from: str      # YYYY-MM-DD
    period_to: str | None
    printed: str          # YYYY-MM-DDTHH:MM:SS
    rows: list = field(default_factory=list)
    totals: tuple = ()
    name: str = ""
    mtime: float = 0.0

    @property
    def weeks(self) -> float:
        days = (date.fromisoformat(self.printed[:10]) - date.fromisoformat(self.period_from)).days
        return days / 7 if days else 1.0

    def buckets(self) -> dict:
        """{(material, mica, csa): metres} for copper rows with metres; the unknown-style column is not demand."""
        out: dict = {}
        for r in self.rows:
            if r.system != "C" or r.csa is None:
                continue
            for material, mica, m in (("plain", 0, r.values[0]), ("plain", 1, r.values[1]),
                                      ("tinned", 0, r.values[3]), ("tinned", 1, r.values[4])):
                if m:
                    key = (material, mica, r.csa)
                    out[key] = out.get(key, 0) + m
        return out


def _date(text: str, line_no: int, problems: list) -> str:
    month = MONTHS.get(text[2:5])
    try:
        if month is None:
            raise ValueError
        return date(2000 + int(text[6:8]), month, int(text[0:2])).isoformat()
    except ValueError:
        problems.append(f"line {line_no} has an unreadable date {text!r}")
        return ""


def _same(meta: dict, key: str, value: str, problems: list) -> None:
    if key in meta and meta[key] != value:
        problems.append(f"the {key} changes between pages ({meta[key]}, then {value})")
    meta[key] = value


def parse(text: str, name: str = "") -> Report:
    """Parse one R-86 file. Raises SourceError listing the problems found."""
    meta: dict = {}
    rows: list[Row] = []
    sums = [0] * 8
    totals = None
    problems: list[str] = []
    units = None
    for i, raw in enumerate(re.split(r"\r\n|\n|\r", text), 1):
        line = raw.replace("\f", "").rstrip()
        if not line or _RULE.match(line) or _DASH.match(line):
            continue
        m = _HEAD.match(line)
        if m:
            _same(meta, "site", m[1], problems)
            _same(meta, "period_from", _date(m[2], i, problems), problems)
            _same(meta, "period_to", _date(m[3], i, problems) if m[3] else "", problems)
            continue
        m = _PRINT.match(line)
        if m:
            _same(meta, "version", m[1], problems)
            _same(meta, "printed", f"{_date(m[2], i, problems)}T{m[3]}", problems)
            continue
        m = _SEL.match(line)
        if m:
            _same(meta, "company", m[1], problems)
            _same(meta, "doc", m[2], problems)
            continue
        m = _COLS.match(line)
        if m:
            q = {m[1], m[2], m[4], m[5], m[7]}
            w = {m[3], m[6], m[8]}
            if len(q) != 1 or len(w) != 1:
                problems.append(f"line {i} mixes units")
            units = units or (m[1], m[3])
            continue
        m = _SIZE.match(line)
        if m:
            vals = tuple(int(x) for x in m[3].split())
            label = m[1] + m[2]
            if any(r.label == label for r in rows):
                problems.append(f"line {i} repeats size {label}")
            rows.append(Row(label, m[1], vals, int(m[4]) if m[4] is not None else None))
            sums = [a + b for a, b in zip(sums, vals)]
            continue
        m = _TOTAL.match(line)
        if m:
            if totals is not None:
                problems.append(f"line {i} is a second Total line")
            totals = tuple(int(m[k]) for k in range(1, 17, 2))
            continue
        problems.append(f"line {i} is not recognised: {line.strip()[:60]!r}")
    for key in ("site", "period_from", "printed", "company", "doc"):
        if key not in meta:
            problems.append(f"the report header is missing its {key}")
    if units is None:
        problems.append("the column header line (Csa NOmica_... X) is missing")
    if not rows:
        problems.append("there are no size lines")
    if totals is None:
        problems.append("the Total line is missing")
    elif list(totals) != sums:
        for k, (printed, added) in enumerate(zip(totals, sums)):
            if printed != added:
                problems.append(f"{NAMES[k]}: the size lines add up to {added} but the printed total is {printed}")
    if problems:
        head = f"{name}: " if name else ""
        raise SourceError(f"{head}R-86 file rejected, {len(problems)} problem(s): " + "; ".join(problems[:5]))
    return Report(meta["doc"], meta["site"], meta["company"], meta["period_from"], meta.get("period_to") or None,
                  meta["printed"], rows, totals, name)


def discover(folder: Path) -> dict[str, list[Report]]:
    """Parse every file in the r86 folder: {'order': [...], 'invoice': [...]}, each newest first (contract 2.2).

    Order: printed timestamp, period start, file mtime, file name, all descending. The header's DocTyp decides the type;
    a file name that says the other type is an error.
    """
    folder = Path(folder)
    if not folder.is_dir():
        raise SourceError(f"missing folder {folder.name}/ (R-86 files)")
    found: dict[str, list[Report]] = {"order": [], "invoice": []}
    for p in sorted(folder.iterdir()):
        if not p.is_file() or p.name.startswith("."):
            continue
        rep = parse(p.read_text(encoding="ascii", errors="replace"), p.name)
        rep.mtime = p.stat().st_mtime
        hint = "order" if "_ORD_" in p.name.upper() else "invoice" if "_INV_" in p.name.upper() else None
        if hint and hint != rep.doc:
            raise SourceError(f"{p.name}: file name says {hint} but the header says DocTyp: {rep.doc} data")
        found[rep.doc].append(rep)
    for doc, reps in found.items():
        if not reps:
            raise SourceError(f"{folder.name}/: no R-86 {doc} file")
        reps.sort(key=lambda r: (r.printed, r.period_from, r.mtime, r.name), reverse=True)
    return found
