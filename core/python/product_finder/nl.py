"""Free-text understanding.

Two tiers, in order:

1. **Regex.** The RFQ parser's `extract_entities()` for everything about the
   cable's construction, plus the patterns below for the electrical duty it
   never needed to read — load, run length, ambient, installation method,
   phase, power factor, permitted volt drop.
2. **AI.** Only for fields tier 1 could not fill, and only when
   `PRODUCT_FINDER_LLM_ENABLED=true`. It returns structured fields and nothing
   else; it never chooses a style or a product code. That stays deterministic.

With no API key the tool still works — it just needs tidier text.
"""

from __future__ import annotations

import json
import logging
import re
import urllib.error
import urllib.request
from contextlib import contextmanager
from contextvars import ContextVar
from dataclasses import dataclass, field
from typing import Any

from .config import CONFIG
from .constants import CABLE_TYPES, INSTALL_METHODS

log = logging.getLogger(__name__)

_runtime_model: ContextVar[str | None] = ContextVar("product_finder_model", default=None)


@contextmanager
def model_snapshot(model: str | None):
    """Keep the internal PHP-selected model local to this request."""
    token = _runtime_model.set(model)
    try:
        yield
    finally:
        _runtime_model.reset(token)

_NUM = r"(\d+(?:\.\d+)?)"

# ── electrical duty patterns ─────────────────────────────────────────────────

LOAD_RE = re.compile(
    rf"(?:load|current|draw(?:ing)?|rated(?:\s+at)?|carry(?:ing)?)\D{{0,20}}{_NUM}\s*(?:a\b|amp)"
    rf"|{_NUM}\s*(?:a\b|amps?\b|ampere)",
    re.IGNORECASE,
)

LENGTH_RE = re.compile(
    rf"(?:run|length|distance|cable\s+run|over|away|route)\D{{0,20}}{_NUM}\s*(?:m\b|metre|meter)"
    rf"|{_NUM}\s*(?:m\b|metres?\b|meters?\b)\s*(?:run|long|away|length)",
    re.IGNORECASE,
)

VOLTAGE_RE = re.compile(
    rf"{_NUM}\s*(kv|v)\b",
    re.IGNORECASE,
)

AMBIENT_RE = re.compile(
    rf"(?:ambient|soil|ground|air)\D{{0,20}}{_NUM}\s*(?:deg|°|c\b)"
    rf"|{_NUM}\s*(?:deg\s*c|°c)\D{{0,15}}ambient",
    re.IGNORECASE,
)

POWER_FACTOR_RE = re.compile(
    rf"(?:power\s*factor|p\.?f\.?)\D{{0,10}}(0?\.\d+|1\.0+|1\b)",
    re.IGNORECASE,
)

VOLT_DROP_RE = re.compile(
    rf"{_NUM}\s*%\s*(?:max(?:imum)?\s*)?(?:volt(?:age)?\s*drop|vd)"
    rf"|(?:volt(?:age)?\s*drop|vd)\D{{0,15}}{_NUM}\s*%",
    re.IGNORECASE,
)

RUNS_RE = re.compile(
    rf"{_NUM}\s*(?:parallel\s*)?runs?\b|(?:parallel\s*)?runs?\D{{0,10}}{_NUM}",
    re.IGNORECASE,
)

FAULT_TIME_RE = re.compile(
    rf"(?:fault|clearing|disconnect(?:ion)?)\D{{0,20}}{_NUM}\s*(?:s\b|sec)",
    re.IGNORECASE,
)

PHASE_PATTERNS: tuple[tuple[re.Pattern[str], int], ...] = (
    (re.compile(r"\b(?:3|three)[\s-]*(?:phase|ph|φ)\b", re.IGNORECASE), 3),
    (re.compile(r"\b(?:1|single)[\s-]*(?:phase|ph|φ)\b", re.IGNORECASE), 4),
    (re.compile(r"\bd\.?c\.?\b|\bdirect\s+current\b", re.IGNORECASE), 5),
)

# Installation is read from two independent signals rather than one ordered
# pattern list. A phrase like "in the ground in a conduit" contains both, and a
# first-match list gets it wrong whichever order you choose — matching "conduit"
# first gives conduit-in-air, which is a very different derating from buried.
BURIED_RE = re.compile(
    r"\bburied\b|\bunderground\b|\bin\s+(?:the\s+)?ground\b|\bdirect\s+burial\b"
    r"|\btrench(?:ed|ing)?\b|\bbelow\s+ground\b|\bin\s+(?:the\s+)?dirt\b",
    re.IGNORECASE,
)
CONDUIT_RE = re.compile(r"\bconduit\b|\bduct(?:ed|ing)?\b|\bpipe\b", re.IGNORECASE)
TOUCHING_RE = re.compile(r"\btouching\b|\bbunched\b|\bin\s+contact\b", re.IGNORECASE)
SPACED_RE = re.compile(r"\bspaced\b|\bwith\s+spacing\b|\bseparated\b", re.IGNORECASE)
SUN_RE = re.compile(r"exposed\s+to\s+(?:the\s+)?sun|direct\s+sun|solar\s+exposed", re.IGNORECASE)
TRAY_RE = re.compile(
    r"cable\s+tray|\btray\b|\bladder\b|\bunenclosed\b|\bopen\s+air\b|\bcatenary\b",
    re.IGNORECASE,
)
IEC_RE = re.compile(r"\biec\b", re.IGNORECASE)


def detect_install(text: str) -> int | None:
    """Resolve the installation method from buried / conduit / spacing signals."""
    if IEC_RE.search(text):
        return 8

    buried = bool(BURIED_RE.search(text))
    conduit = bool(CONDUIT_RE.search(text))
    touching = bool(TOUCHING_RE.search(text))

    if buried and conduit:
        # 3 = buried in spaced conduit, 7 = buried in touching conduit.
        return 7 if touching else 3
    if buried:
        return 9 if touching else 4
    if conduit:
        return 5  # conduit in air
    if SUN_RE.search(text):
        return 6
    if TRAY_RE.search(text):
        return 2 if touching else 1
    return None

# Construction wording -> the calculator's cable-type index.
TYPE_PATTERNS: tuple[tuple[re.Pattern[str], int], ...] = (
    (re.compile(r"switchboard|panel\s+wire", re.I), 0),
    (re.compile(r"\bvsd\b|\bvfd\b|variable\s+(?:speed|frequency)\s+drive", re.I), 9),
    (re.compile(r"offshore", re.I), 12),
    (re.compile(r"shipboard.*braid|braid.*shipboard", re.I), 11),
    (re.compile(r"shipboard|marine|solas|\bdnv\b|lloyds", re.I), 10),
    (re.compile(r"pump.*braid|braid.*pump", re.I), 8),
    (re.compile(r"\bpump\b", re.I), 7),
    (re.compile(r"fire\s*(?:rated|resistant|resisting)|circuit\s+integrity", re.I), 3),
    (re.compile(r"\blszh\b|low\s+smoke|halogen[\s-]*free", re.I), 2),
)


@dataclass
class ParsedRequirement:
    """What we understood, and how."""

    text: str
    fields: dict[str, Any] = field(default_factory=dict)
    electrical: dict[str, Any] = field(default_factory=dict)
    sources: dict[str, str] = field(default_factory=dict)  # field -> "regex" | "ai"
    warnings: list[str] = field(default_factory=list)
    used_ai: bool = False

    def public(self) -> dict[str, Any]:
        return {
            "text": self.text,
            "fields": {k: v for k, v in self.fields.items() if v not in (None, "", False)},
            "electrical": self.electrical,
            "sources": self.sources,
            "warnings": self.warnings,
            "usedAi": self.used_ai,
        }


def _first_number(match: re.Match[str] | None) -> float | None:
    if not match:
        return None
    for group in match.groups():
        if group:
            try:
                return float(group)
            except ValueError:
                continue
    return None


def extract_electrical(text: str) -> dict[str, Any]:
    """Pull the duty parameters the RFQ parser's extractor does not read."""
    out: dict[str, Any] = {}

    load = _first_number(LOAD_RE.search(text))
    if load and 0 < load <= 6500:
        out["load_amps"] = load

    length = _first_number(LENGTH_RE.search(text))
    if length and length > 0:
        out["length_m"] = length

    volt_match = VOLTAGE_RE.search(text)
    if volt_match:
        value = float(volt_match.group(1))
        if volt_match.group(2).lower() == "kv":
            value *= 1000
        out["voltage"] = int(value)

    ambient = _first_number(AMBIENT_RE.search(text))
    if ambient is not None and -20 <= ambient <= 90:
        out["ambient_c"] = ambient

    pf = _first_number(POWER_FACTOR_RE.search(text))
    if pf and 0 < pf <= 1:
        out["power_factor"] = pf

    vd = _first_number(VOLT_DROP_RE.search(text))
    if vd and 0 < vd <= 20:
        out["max_volt_drop_pct"] = vd

    runs = _first_number(RUNS_RE.search(text))
    if runs and 1 <= runs <= 100:
        out["fixed_runs"] = int(runs)

    fault = _first_number(FAULT_TIME_RE.search(text))
    if fault and 0 < fault <= 10:
        out["fault_duration_s"] = fault

    for pattern, phase in PHASE_PATTERNS:
        if pattern.search(text):
            out["phase"] = phase
            break

    install = detect_install(text)
    if install is not None:
        out["install"] = install

    for pattern, cable_type in TYPE_PATTERNS:
        if pattern.search(text):
            out["cable_type"] = cable_type
            break

    if re.search(r"\balumini?um\b|\bal\s+conductor\b", text, re.IGNORECASE):
        out["aluminium_active"] = True

    return out


# ── AI tier ──────────────────────────────────────────────────────────────────

_AI_SCHEMA_HINT = """Return ONLY a JSON object. Include a key only if the text
states or clearly implies it. Never guess.

{
  "load_amps": number,
  "length_m": number,
  "voltage": integer,
  "phase": 3 | 4 | 5,
  "ambient_c": number,
  "install": integer,
  "cable_type": integer,
  "power_factor": number,
  "max_volt_drop_pct": number,
  "fixed_runs": integer,
  "aluminium_active": boolean,
  "cross_section": number,
  "count": integer,
  "notes": string
}

phase: 3 = three phase, 4 = single phase, 5 = DC
install: %s
cable_type: %s
"""


def _ai_prompt() -> str:
    installs = ", ".join(f"{k} = {v['label']}" for k, v in sorted(INSTALL_METHODS.items()))
    types = ", ".join(f"{k} = {v}" for k, v in sorted(CABLE_TYPES.items()))
    return _AI_SCHEMA_HINT % (installs, types)


def ai_extract(text: str, already_known: dict[str, Any]) -> dict[str, Any]:
    """Ask the model for the fields regex could not find.

    Returns {} on any failure — a missing AI tier is never fatal.
    """
    cfg = CONFIG.llm
    if not cfg.usable:
        return {}

    known = ", ".join(sorted(already_known)) or "none"
    payload = {
        "model": _runtime_model.get() or cfg.model,
        "temperature": 0,
        "messages": [
            {
                "role": "system",
                "content": (
                    "You extract cable specification parameters from customer "
                    "enquiries for TriCab. You never select a product or a cable "
                    "style — you only read values that are present in the text.\n\n"
                    + _ai_prompt()
                ),
            },
            {
                "role": "user",
                "content": (
                    f"Already extracted by regex (do not repeat these): {known}\n\n"
                    f"Customer enquiry:\n{text}"
                ),
            },
        ],
    }

    request = urllib.request.Request(
        cfg.base_url,
        data=json.dumps(payload).encode("utf-8"),
        headers={
            "Authorization": f"Bearer {cfg.api_key}",
            "Content-Type": "application/json",
            "HTTP-Referer": cfg.referer,
            "X-Title": cfg.app_title,
        },
        method="POST",
    )

    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            body = json.loads(response.read().decode("utf-8"))
    except (urllib.error.URLError, TimeoutError, ValueError) as exc:
        log.warning("AI extraction failed: %s", exc)
        return {}

    try:
        content = body["choices"][0]["message"]["content"]
    except (KeyError, IndexError, TypeError):
        return {}

    match = re.search(r"\{.*\}", content, re.DOTALL)
    if not match:
        return {}
    try:
        parsed = json.loads(match.group(0))
    except ValueError:
        return {}

    return parsed if isinstance(parsed, dict) else {}


# ── the combined entry point ─────────────────────────────────────────────────

_ELECTRICAL_KEYS = {
    "load_amps", "length_m", "voltage", "phase", "ambient_c", "install",
    "cable_type", "power_factor", "max_volt_drop_pct", "fixed_runs",
    "aluminium_active", "fault_duration_s",
}


def parse_requirement(text: str, allow_ai: bool = True) -> ParsedRequirement:
    """Read a free-text enquiry into construction fields plus electrical duty."""
    result = ParsedRequirement(text=text)

    # Tier 1a — construction, via the shared selection rules.
    try:
        from . import rules

        extracted = rules.extract_fields(text)
        result.fields = dict(extracted.get("fields") or {})
        result.fields["_normalized_text"] = extracted.get("normalized_text", text)
        for key, value in result.fields.items():
            if value not in (None, "", False):
                result.sources[key] = "regex"
        result.warnings.extend(extracted.get("warnings") or [])
    except Exception as exc:  # noqa: BLE001 - degrade, don't fail
        result.warnings.append(f"Construction extraction unavailable: {exc}")

    # Tier 1b — electrical duty.
    electrical = extract_electrical(text)
    result.electrical = electrical
    for key in electrical:
        result.sources[key] = "regex"

    # Tier 2 — AI for the gaps.
    if allow_ai and CONFIG.llm.usable:
        known = set(result.electrical) | {
            k for k, v in result.fields.items() if v not in (None, "", False)
        }
        gaps = _ELECTRICAL_KEYS - known
        if gaps:
            ai_fields = ai_extract(text, known)
            result.used_ai = bool(ai_fields)
            for key, value in ai_fields.items():
                if value in (None, "", []):
                    continue
                if key in _ELECTRICAL_KEYS and key not in result.electrical:
                    result.electrical[key] = value
                    result.sources[key] = "ai"
                elif key in {"cross_section", "count"} and not result.fields.get(key):
                    result.fields[key] = value
                    result.sources[key] = "ai"

    return result
