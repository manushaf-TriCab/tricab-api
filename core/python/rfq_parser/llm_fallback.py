# Generated from the RFQ parser's matcher/llm_fallback.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
from __future__ import annotations

from . import model_settings

import json
import os
import re
import urllib.error
import urllib.request
from dataclasses import dataclass
from typing import Any

from rfq_parser.cable_notation import CORE_COUNT_NOTATION
from tricab_cable_rules.normalize import canonical_number, int_if_whole


OPENROUTER_URL = "https://openrouter.ai/api/v1/chat/completions"
DEFAULT_MODEL = "openai/gpt-4o-mini"

# Shared field-semantics description for both the reactive per-line fallback below
# and the up-front batch normalizer (matcher.normalizer) -- the two prompts differ
# only in their batching/splitting instructions, but must agree on what each field
# means, or the resolver-facing fields dict ends up inconsistent depending on
# which path handled a given line. These fields are merged straight into the
# resolver's own fields dict (see matcher.resolver.match_line's field_overrides) --
# there is no free-text round trip, so precision here matters more than prose.
FIELD_SEMANTICS = (
    "The fields you return are used directly to drive catalog matching -- there "
    "is no further text parsing, so fill them precisely rather than writing a "
    "parser-friendly sentence. Return only facts present in the text; do not "
    "invent product codes. Use null for unknown values. You are responsible for "
    "two categories of field, and both matter: (1) the cable description (core_type, "
    "core_count, cross_section_mm2, conductor, earth handling, screen/armoured/fire, "
    "cable_type_hint) which drives product-code selection, and (2) quantity/logistics "
    "(quantity, number_of_lengths, length_each, unit, circuit_label) which drive "
    "pricing and the quote comment, not product-code selection -- get both right, "
    "and reason through all of them (not just the description) in the reasoning "
    "field before concluding. reasoning is your own scratchpad: briefly work through "
    "the row's own text first -- core count/type, size, conductor, which of "
    "earth/screen/armour/fire are literally stated vs. absent, whether a total/cut "
    "length or cut count or circuit label is stated -- before filling in any other "
    "field; it is never read downstream, so think out loud rather than jumping "
    "straight to an answer. core_type is only how cores are grouped: C=plain cores, "
    "P=pairs, T=triads, Q=quads. core_count is "
    "the total core number, already including any earth core per house "
    "convention (e.g. '3PH+N+E' is core_count=5, core_type=C). An earth may be "
    "written with a plus or a slash and may be spelled 'E', 'E/W' (earth wire) "
    "or 'earth' -- '4C+E', '4C/E' and '4C + E/W' are the SAME cable, "
    "core_count=5 core_type=C with has_earth_core true. When core_type "
    "is P/T/Q, core_count counts the GROUPS, never the individual conductors: "
    "'4 pair' is core_count=4 core_type=P; '8 triad' is core_count=8 "
    "core_type=T (never 24). Multiple "
    "split earths still count as ONE earth core, never as separate cores: "
    "'3C + 3E 16mm2' is core_count=4 (3 main + 1 earth, NEVER 3+3=6), "
    "core_type=C, with "
    "earth_multiplicity=3 -- put the split count in earth_multiplicity and "
    "never add it into core_count. If the text states an explicit core count "
    "(e.g. '2C') and no earth wording, core_count is EXACTLY that number -- "
    "never bump it to a nearby size you believe is more standard or "
    "manufacturable; whether a product exists at that count is the engine's "
    "problem, not yours. conductor is Cu/Al/TinnedCu only when the "
    "material is actually stated (copper/Cu, aluminium/alu/Al, tinned copper); "
    "never infer it from the size, voltage or cable type -- leave it null. "
    "recognized_standard is the name of a known cable-construction standard "
    "(e.g. 'MCMK', 'NYY', 'H07RN-F') only when you actually recognize one in "
    "the text -- leave it null for a customer's own made-up label. "
    "standard_sheath is the sheath material (PVC or LSZH) that standard "
    "implies, only when you are confident and recognized_standard is set; "
    "otherwise null, and a house default will apply. description is a short "
    "human-readable summary for audit display only -- it is never re-parsed, "
    "so phrase it however is clearest. Put any explanation or assumption in "
    "notes. 'SDI' in a cable description means Single Double Insulated (a "
    "construction type) -- it is not screening; never set screen_requested "
    "from 'SDI' alone. Screen abbreviations you WILL see (set screen_requested "
    "= true for any of them, and never invent other expansions -- e.g. 'OAS' is "
    "NOT 'Open Area System'): OS = overall (collective) screen; "
    "Orange Circular, Orange Circ, O/C and OC mean orange circular power cable, "
    "using the XL/KL/CC temperature/sheath ladder; they do not imply screening. "
    "IS = individual screen; IOS / IOC = individual + overall screen; "
    "OAS = overall armoured screen; IOAS = individual + overall armoured "
    "screen. 'OAS' and 'IOAS' additionally mean armoured_requested = true (the "
    "'A' is armour). circuit_label is a FROM/TO location pairing or a short "
    "tag (e.g. 'Circuit 3') identifying which circuit/run the row belongs to, "
    "only when the row's own text actually states one -- never invent one, and "
    "never pull it from current_section/context (those supply cable facts "
    "only, never routing information). A circuit label is routing info only, "
    "never evidence of the cable type -- do not set cable_type_hint from the "
    "equipment names inside a FROM/TO label. "
    "drum_mark is a drum/reel identifier the customer wants printed on the "
    "delivered drum -- 'drum mark ABC123', 'Drum No. DR-7781'. Copy the "
    "identifier only, without the words 'drum' or 'mark'. It is NOT a circuit "
    "label and NOT a cable fact: it never affects the product code, and a "
    "FROM/TO pairing is never a drum mark. Set it only when the row's own text "
    "names one and never invent one -- it is a cutting instruction that "
    "reaches the factory. When a row states both a circuit label and a drum "
    "mark, set both; they occupy different parts of the quote comment. "
    "customer_note is text the customer or the salesperson wrote about this "
    "line that belongs on the quote beside it -- most often a lead time or "
    "delivery promise ('4 weeks ex Melbourne', '6 drums: 4 weeks ex "
    "Melbourne; remaining 42: 9 weeks from order'), and also handling or "
    "delivery instructions stated for the row. COPY IT VERBATIM, character for "
    "character, from the row's own text or its Notes / Remarks / Lead Time "
    "cell. Never summarize it, never rephrase it, never translate it and never "
    "write one of your own: it is checked against the source text and dropped "
    "if it is not found there. It is not for anything you concluded -- your own "
    "observations, assumptions and doubts belong in notes, which is a different "
    "field and never reaches the quote. Leave customer_note null when the row "
    "carries no such text. It never affects the product code, the quantity or "
    "the price. Do NOT put a circuit label, a drum mark, a cut breakdown or a "
    "bare quantity in it -- those have their own fields, and repeating them "
    "here prints them on the quote twice. "
    "Cut arithmetic must reconcile AND be complete: quantity = "
    "number_of_lengths x length_each. Whenever the row gives you two of the "
    "three, compute and return the third rather than leaving it null -- '210m "
    "in 6 cuts' is quantity=210, number_of_lengths=6, length_each=35, and "
    "returning the total and the cut count while leaving length_each null "
    "loses the cut breakdown that gets printed on the quote. If the three you "
    "would return do not multiply out, do not adjust one to force it: return "
    "what the row actually says and explain the discrepancy in notes. "
    "A row may state a MAINS length and a separate, shorter EARTH length "
    "without saying how many cuts the mains is in -- '140m for the mains and "
    "35m for the earths'. The earth length is the length of ONE run of the "
    "route, so when the mains total divides by it exactly, that quotient is "
    "the mains cut count: 140/35 = 4, giving a mains entry of "
    "number_of_lengths=4 length_each=35 quantity=140 plus an earth entry of "
    "number_of_lengths=1 length_each=35. Apply this ONLY when the division is "
    "exact and the earth length is the shorter of the two; otherwise leave the "
    "mains as the single stated total. Because it derives a cut structure the "
    "customer did not spell out, it always needs a human to confirm: say in "
    "notes that the mains cut count came from dividing the mains total by the "
    "earth run length. "
    "A row written as \"Nx SIZE1 + [Mx] SIZE2 "
    "earth\" (e.g. '8x 185mm + 1x 185mm earth', '6x 185mm + 70mm Earth') "
    "describes N single-core phase conductors of SIZE1 run in parallel, PLUS a "
    "separate earth conductor of SIZE2 (often a reduced size) -- this is NOT a "
    "multicore cable and NOT an earth-only cable. Split it into exactly two "
    "entries: one with core_count=1, is_earth_only=false, "
    "cross_section_mm2=SIZE1, number_of_lengths=N; and one with core_count=1, "
    "is_earth_only=true, cross_section_mm2=SIZE2, number_of_lengths=M (M is 1 "
    "when no leading count is stated before the earth size). The earth entry's "
    "conductor MUST match the phase entry's conductor -- an Aluminium-phase "
    "row's earth conductor is Aluminium too, never assumed to be a different "
    "material. The SAME split applies to "
    # Not a paragraph of this prompt any more: the attachment reader needs the same
    # notation and was re-deriving it wrongly. `matcher/cable_notation.py` owns it, and
    # the rendered string here is unchanged to the byte -- this prompt is cached.
    + CORE_COUNT_NOTATION
    + " Split into a phase "
    "entry (core_count=1, is_earth_only=false, number_of_lengths = A*B) and an "
    "earth entry (core_count=1, is_earth_only=true, number_of_lengths = A, where A "
    "is the leading bundle count, or 1 if none); the earth's size is whatever the "
    "row states for it, otherwise leave cross_section null and note it. \"Trefoil\" "
    "(or \"Nx 1 core Trefoil\") describes single-core "
    "conductors laid in triangular formation -- it is NEVER a multicore cable, "
    "even though the row's own \"1 core\" wording can look like a core-count "
    "statement. When the row states a clean \"Nx 1 core Trefoil\" plus a "
    "separately-sized earth reference, split it the same way as the parallel "
    "single-core case above: N single-core phase entries (or one entry with "
    "number_of_lengths=N) plus one earth entry. Some cable-schedule rows combine "
    "an extra leading run-count with the trefoil count (e.g. \"2 x 300mm2 ... "
    "3 x 1 core Trefoil\", where \"2x\" and \"3x\" describe two different "
    "things -- how many parallel circuit sets, and how many single cores per "
    "trefoil bundle). Do not guess how these combine if it is not clear from the "
    "row's own wording -- extract the sizes/counts you ARE sure of, lower "
    "confidence, and explain the ambiguity in notes rather than inventing a "
    "specific total. Never emit two entries describing the SAME conductors: an "
    "alternative reading of an ambiguous row belongs in notes, never as an "
    "extra entry (each entry becomes its own priced quote line). A row that does not describe an actual "
    "cable/product requirement at all -- commercial or contract terms (payment, "
    "warranty, security, liquidated damages, defect liability), lead-time/delivery "
    "schedules, incoterms, offer/supplier-contact info, section headings, "
    "notes or instructions to the supplier about the quote/database itself "
    "(e.g. 'these codes need to be added in your database'), or any "
    "other document boilerplate -- is not a cable line: return an empty entries "
    "list for it rather than inventing a description or guessing at fields just "
    "because the row contains numbers or looks tabular. Per-piece cable "
    "accessories/fittings -- lugs, glands, copper barrel links, crimp links, "
    "terminals, BS95 items, heat-shrink, ties -- are also not cable lines: "
    "return an empty entries list for them, not a default cable. \"CONDUIT WIRE\" "
    "AND \"CONDUIT CABLE\" ARE CABLES, not accessories: the wire pulled THROUGH the "
    "duct, not the duct. Read them as a cable line and extract their size and length "
    "normally -- and green/yellow conduit wire (\"G/Y\") is an EARTH wire, so set "
    "is_earth_only true. Conduit on its own IS an accessory (\"100mm PVC conduit\", "
    "\"conduit saddle\", \"conduit gland\") and returns an empty entries list. The "
    "distinction is the word after it: a real 300m 6.0mm conduit wire G/Y earth was "
    "dropped from a quote entirely by reading it as a fitting. Each row also carries "
    "current_section: the sheet "
    "heading/label the row itself sits directly under (e.g. 'CONTROLS Cable "
    "Types', 'VSD CABLES'). It also carries context: older ancestor headings "
    "from further up the sheet, for background only -- current_section alone "
    "is authoritative for the row, so never let a context entry override or "
    "outvote current_section. When the row's own text is too sparse to tell "
    "the cable type (a bare '1.5mm2' with no core count or keywords), set "
    "cable_type_hint from current_section (e.g. Control, VSD/VFD, "
    "Instrumentation) the same way you would read a table caption. A "
    "current_section heading that states CONSTRUCTION facts -- a conductor "
    "material ('Al/XLPE cables' -> conductor Al), an earth designation "
    "('Cu/PVC earth' -> is_earth_only true, conductor Cu), a sheath (LSZH), "
    "armour, a screen, or a fire rating -- applies those facts to every row "
    "sitting under it whose own text is silent on that axis: fill conductor / "
    "is_earth_only / armoured_requested / screen_requested / "
    "fire_rated_requested from the heading exactly as if the heading's words "
    "were part of the row. The row's own text always wins over any heading on "
    "a conflict, and current_section always wins over an older context "
    "heading. Never invent a core count, size, length or quantity from a "
    "heading -- construction facts, cable_type_hint and style_series_code "
    "(see below) are the only things that may come from "
    "current_section/context. "
    "Rows in this same batch that share the "
    "identical current_section are one table section pasted together -- read "
    "them as a group the way a person would if they saw the whole block at "
    "once: if any row in that group states the cable type in its own text, "
    "apply the same cable_type_hint to every bare/sparse row in the group "
    "too, not just the one row that stated it. That inherited "
    "cable_type_hint/style_series_code (plus the section-heading construction "
    "facts above) is the ONLY thing that may cross rows: "
    "every output row's entries must otherwise be built from that row's own "
    "text alone -- never copy a size, count, length or extra entry from a "
    "neighbouring row in the batch, and never emit an entry under one index "
    "that actually describes another row's cable. style_series_code is a house "
    "style/series code the text explicitly names -- either inline in the "
    "row's own text ('KL Series X-HF-110/PVC 110 C', 'XU Series XLPE/SCN/PVC') "
    "or as a current_section heading naming a code immediately followed by "
    "construction shorthand (temperature/sheath letters), e.g. 'Aluminium "
    "KL-XH-F 110C AL' or 'Copper KL-X-HF-110'. Like cable_type_hint, this MAY "
    "come from current_section rather than the row's own text -- a heading "
    "naming a series applies to every row sitting under it, even when that "
    "row's own text has zero style/series wording of its own. Copy the code "
    "EXACTLY as the short alphanumeric token that leads the name, immediately "
    "before the hyphen or the word 'Series' -- never a different substring "
    "from later in the code that merely sounds like a style (from "
    "'KL-XH-F', the code is 'KL', never 'XH' or 'HF'; from 'KL-X-HF-110', it "
    "is still 'KL'). Switchboard or panel wording is an application context, "
    "not a Control cable type by itself. If you are not looking at an actual short code token in "
    "that leading position, leave style_series_code null rather than "
    "guessing -- it will be checked against the real product catalog before "
    "use, so under-reporting is safe, over-guessing isn't."
)

_SYSTEM_PROMPT_TEXT = (
    "You extract cable requirements from RFQ text for deterministic catalog "
    "matching. " + FIELD_SEMANTICS + " If one line describes multiple cable "
    "types, split it into separate entries."
)


@dataclass
class LlmEntry:
    description: str
    fields: dict[str, Any]
    confidence: float
    notes: str = ""
    reasoning: str = ""


@dataclass
class LlmParseResult:
    enabled: bool
    attempted: bool
    ok: bool
    model: str
    entries: list[LlmEntry]
    error: str = ""


NON_CABLE_ENTRY_RE = re.compile(
    r"\b(?:not|non)[-\s]?cable\b|\bnot\s+an?\s+(?:actual\s+)?cable\b|"
    r"\bdoes\s+not\s+describe\s+(?:an?\s+)?(?:actual\s+)?cable\b",
    re.I,
)


def entry_declares_non_cable(entry: LlmEntry) -> bool:
    """True when the model emitted an entry but explicitly said it is not a cable.

    The prompt asks for non-cable rows to use an empty entries list. This guard is
    for non-compliant responses that still include cable-shaped fields such as a
    size and conductor; those fields must not be allowed to default into a cable
    product code.
    """
    haystack = " ".join(
        str(part or "")
        for part in (entry.notes, entry.reasoning, entry.description)
    )
    return bool(NON_CABLE_ENTRY_RE.search(haystack))


def openrouter_enabled() -> bool:
    if os.environ.get("OPENROUTER_ENABLED", "").strip().lower() in {"0", "false", "no", "off"}:
        return False
    return bool(os.environ.get("OPENROUTER_API_KEY"))


def fallback_threshold() -> float:
    return _float_env("OPENROUTER_CONFIDENCE_THRESHOLD", 0.60)


def assisted_threshold() -> float:
    return _float_env("OPENROUTER_ASSISTED_THRESHOLD", 0.75)


def _cable_length_missing(result: dict[str, Any]) -> bool:
    """True when a cable line carries no length signal at all (no stated length/total
    and no table quantity folded into it). Per Manusha (2026-07-07) such a line must
    reach the AI -- to give it a chance to read a length/cut the regex missed --
    BEFORE the 1m unit-rate default is applied. Recognises both the pre-default state
    (no length keys, as seen by the reactive fallback) and the post-default state
    (``length_defaulted`` flag, as seen by the batch normalizer's pre-filter, which
    inspects the trial result after apply_default_cable_length has already run)."""
    if result.get("item_type") != "cable":
        return False
    if result.get("length_defaulted"):
        return True
    length = result.get("length") or {}
    return not any(
        length.get(key) not in (None, "")
        for key in ("length_each", "length_each_m", "total_length", "total_length_m")
    )


def _cable_needs_ai(result: dict[str, Any], *, unknown_needs_keywords: bool) -> bool:
    if result.get("item_type") in {"accessory", "admin", "comms", "not_sold"}:
        return False
    if result.get("fields", {}).get("exact_product_code"):
        return False

    # A confidently-matched cable that is only missing its length still goes to the
    # AI: regex found no length/cut, so let the model try before the 1m default lands.
    if _cable_length_missing(result):
        return True

    confidence = float(result.get("confidence") or 0)
    status = result.get("status")
    warnings = result.get("warnings") or []
    raw_text = str(result.get("source", {}).get("original_text") or result.get("raw_text") or "")
    compound_hint = "+" in raw_text and re.search(r"\b\d+\s*x\b", raw_text, re.I)
    if result.get("item_type") == "unknown":
        cableish_unknown = _looks_worth_llm(raw_text) if unknown_needs_keywords else True
    else:
        cableish_unknown = False

    if confidence < fallback_threshold() and (result.get("item_type") == "cable" or cableish_unknown):
        return True
    if confidence < assisted_threshold() and (
        result.get("item_type") == "cable"
        and (status in {"unresolved", "needs_clarification", "multiple_candidates"} or warnings or compound_hint)
    ):
        return True
    return False


def should_try_llm(result: dict[str, Any]) -> bool:
    if not openrouter_enabled():
        return False
    return _cable_needs_ai(result, unknown_needs_keywords=True)


def needs_ai_normalization(result: dict[str, Any]) -> bool:
    """Whether a regex-only result needs AI help before being trusted at all.

    Used by the batch normalizer's pre-filter (see matcher.pipeline.match_items)
    to decide which rows even reach the AI -- a row regex already resolves
    confidently (like a well-formed "0.6/1kV ... CU 25mm" line) should never
    touch the AI at all: cheaper, and a plain deterministic answer is a smaller
    risk surface than even a well-grounded AI-inferred one (a real misfire
    showed the AI's own guess corrupting an otherwise-solid regex match).

    Same "cable" branch logic as should_try_llm, but the "item_type == unknown"
    branch drops the _looks_worth_llm keyword requirement: unlike the reactive
    per-line fallback (where that filter exists to avoid an entire extra API
    call for text that's probably not even a cable line), the batch normalizer
    amortises its cost across many rows per call, so it's worth casting a wider
    net to catch non-standard notations regex can't classify at all (e.g. a
    bare "5x10mm2 MCMK" that regex doesn't recognize as a cable at all).
    """
    return _cable_needs_ai(result, unknown_needs_keywords=False)


# Conductor materials as they appear in a section heading ("Al/XLPE cables",
# "Copper Cables"). Word-bounded so ALARM/ALTRO never read as aluminium.
_CONTEXT_CONDUCTOR_RE = re.compile(
    r"\b(?:AL|ALUMINI?UM|CU|COPPER|TINNED(?:\s+COPPER)?|TCW)\b", re.I
)


def context_supplies_missing_construction(
    context: list[str] | None, results: list[dict[str, Any]]
) -> bool:
    """True when a section heading above this row states a construction fact the
    row's own regex parse had to assume instead.

    The batch normalizer's pre-filter skips rows regex resolves confidently --
    but regex extraction only ever reads the row's own text, so "95mm x 525mtr"
    under an "Al/XLPE cables" heading resolves "confidently" as a copper power
    cable and the AI (the only reader of current_section) never gets a say.
    This check routes exactly those rows to the AI: a heading names a conductor
    material, an earth designation, armour, a screen, or a fire/LSZH rating,
    and the trial result assumed (or lacks) that same axis. FIELD_SEMANTICS
    then tells the model how to inherit the heading's facts; this helper only
    decides that the AI should look.
    """
    headings = " ".join(str(value or "") for value in (context or [])).strip()
    if not headings:
        return False
    cable_rows = [row for row in results if row.get("item_type") == "cable"]
    if not cable_rows:
        return False

    def _assumed(field_key: str, inferred_key: str | None = None) -> bool:
        for row in cable_rows:
            fields = row.get("fields") or {}
            if inferred_key and inferred_key in (row.get("inferred_fields") or []):
                return True
            if not fields.get(field_key):
                return True
        return False

    if _CONTEXT_CONDUCTOR_RE.search(headings) and _assumed("conductor", "conductor"):
        return True
    if _EXPLICIT_FEATURE_PATTERNS["earth"].search(headings) and not any(
        (row.get("fields") or {}).get("earth") or (row.get("fields") or {}).get("earth_only")
        for row in cable_rows
    ):
        return True
    if _EXPLICIT_FEATURE_PATTERNS["armoured"].search(headings) and _assumed("armoured", "armour"):
        return True
    if _EXPLICIT_FEATURE_PATTERNS["screen"].search(headings) and _assumed("screen"):
        return True
    if _EXPLICIT_FEATURE_PATTERNS["fire"].search(headings) and _assumed("fire", "sheath"):
        return True
    return False


def _openrouter_chat(payload: dict[str, Any], *, timeout: float) -> tuple[dict[str, Any] | None, str | None]:
    """POST a chat-completions payload to OpenRouter.

    Returns ``(data, error)``: on success ``data`` is the decoded JSON response
    and ``error`` is ``None``; on failure ``data`` is ``None`` and ``error`` is a
    human-readable message. Shared by the reactive parse fallback and the up-front
    normalizer so the auth/headers/timeout/error handling lives in one place.
    """
    request = urllib.request.Request(
        OPENROUTER_URL,
        data=json.dumps(payload).encode("utf-8"),
        headers={
            "Authorization": f"Bearer {os.environ['OPENROUTER_API_KEY']}",
            "Content-Type": "application/json",
            "HTTP-Referer": os.environ.get("OPENROUTER_SITE_URL", "http://127.0.0.1:5000"),
            "X-Title": os.environ.get("OPENROUTER_APP_NAME", "TriCab RFQ Parser"),
        },
        method="POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            return json.loads(response.read().decode("utf-8")), None
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8", errors="replace")
        return None, f"OpenRouter HTTP {exc.code}: {body[:400]}"
    except (urllib.error.URLError, TimeoutError, json.JSONDecodeError, OSError) as exc:
        return None, f"OpenRouter request failed: {exc}"


def parse_with_openrouter(
    raw_text: str,
    context: list[str] | None = None,
    previous_fields: dict[str, Any] | None = None,
) -> LlmParseResult:
    model = model_settings.get("OPENROUTER_MODEL", DEFAULT_MODEL)
    if not openrouter_enabled():
        return LlmParseResult(False, False, False, model, [], "OpenRouter is not configured.")

    payload = {
        "model": model,
        "messages": [
            {
                "role": "system",
                "content": _SYSTEM_PROMPT_TEXT,
            },
            {
                "role": "user",
                "content": json.dumps(
                    {
                        "rfq_text": str(raw_text or ""),
                        "current_section": (context or [None])[-1],
                        "nearby_context": context or [],
                        "first_pass_fields": previous_fields or {},
                    },
                    ensure_ascii=True,
                ),
            },
        ],
        "max_tokens": 900,
        "response_format": {
            "type": "json_schema",
            "json_schema": {
                "name": "rfq_cable_parse",
                "strict": True,
                "schema": _schema(),
            },
        },
    }

    # Not setting "temperature": newer Claude models (e.g. claude-sonnet-5) don't list
    # it in supported_parameters; with require_parameters below, sending it makes
    # OpenRouter 404 "no endpoints found" on every call instead of just dropping it.
    if os.environ.get("OPENROUTER_REQUIRE_PARAMETERS", "true").lower() in {"1", "true", "yes"}:
        payload["provider"] = {"require_parameters": True}

    timeout = _float_env("OPENROUTER_TIMEOUT_SECONDS", 20)
    data, error = _openrouter_chat(payload, timeout=timeout)
    if error:
        return LlmParseResult(True, True, False, model, [], error)

    try:
        content = data["choices"][0]["message"]["content"]
        parsed = _loads_json_object(content)
        entries = [_entry_from_payload(item) for item in parsed.get("entries", [])]
        entries = [entry for entry in entries if entry.description.strip()]
        if not entries:
            return LlmParseResult(True, True, False, model, [], "OpenRouter returned no parseable cable entries.")
        return LlmParseResult(True, True, True, data.get("model") or model, entries)
    except (KeyError, TypeError, ValueError, json.JSONDecodeError) as exc:
        return LlmParseResult(True, True, False, model, [], f"Could not parse OpenRouter response: {exc}")


_EXPLICIT_FEATURE_PATTERNS = {
    "armoured": re.compile(r"\b(?:SWA|AWA|ARMOU?RED|STEEL\s+WIRE\s+ARMOU?R|ALUMINI?UM\s+WIRE\s+ARMOU?R|OAS|IOAS)\b", re.I),
    "screen": re.compile(
        r"\b(?:SCREEN(?:ED)?|SCR|VSD|VFD|EMC|O/?S|I/?S|IOS|IOC|OAS|IOAS|OVERALL\s+SCREEN|INDIVIDUAL\s+SCREEN)\b",
        re.I,
    ),
    "fire": re.compile(r"\b(?:FIRE|FR\b|WS52W|X-?125|R-?125|HF-?110|LOW\s+SMOKE|LSZH|HFFR)\b", re.I),
    # An earth claim (has_earth_core) must be grounded the same way -- otherwise a
    # hallucinated split-earth count, combined with a wrongly-guessed screen flag
    # (e.g. the model reading "SDI" as implying screened), can misroute a plain
    # power line into the screened/VSD family (XU/KU/EU/EA), which needs exactly
    # that combination and nothing else to trigger.
    # ``<size>mm E`` is the common shorthand for a separately-sized earth conductor
    # ("4x 1c 70mm + 25mm E", "185mm + 70mm E XLPE"). Without the size-prefixed form
    # here, the bare ``\+ ... E`` alternative misses it (the "mm" between the digits
    # and the E breaks it), so the grounding gate wrongly strips a correctly-read
    # earth-only split-off and the resolver quotes it as an ordinary phase core.
    # ``E/W`` (earth wire) and the slash-separated ``4C/E`` are the same signal as
    # ``+E``; both are grounding evidence here. normalize_text rewrites them, but
    # this gate reads the RAW row and heading text, so it has to know them too --
    # otherwise a correctly-read earth on an "E/W" row is stripped as invented.
    "earth": re.compile(
        r"\bEARTH\b|\bECC\b|\bG/?Y\b|\bGN\s*/\s*YE\b|\bGREEN\s*/?\s*YELLOW\b|\+\s*\d*\s*E\b"
        r"|\b\d+(?:\.\d+)?\s*mm2?\s*E\b|\bE\s*/\s*W\b|\b\d+\s*C\s*/\s*E\b",
        re.I,
    ),
    # explicit_style_hint is deliberately NOT grounded here (no row-text-must-contain-it
    # check), same as cable_type_hint -- both are allowed to come from
    # current_section/context rather than the row's own text (see FIELD_SEMANTICS). Its
    # real grounding is matcher.resolver._validated_style_code's catalog-membership
    # check, not text-matching: a code the model invented gets silently dropped there
    # regardless of what source_text says.
}

_UNIT_SYNONYMS = {
    "M": ("M", "MTR", "MTRS", "METRE", "METRES", "METER", "METERS"),
    "FT": ("FT", "FEET", "FOOT"),
    "MM": ("MM", "MILLIMETRE", "MILLIMETRES", "MILLIMETER", "MILLIMETERS"),
}


def _number_grounded(value: Any, source_text: str) -> bool:
    """Whether a numeric value the LLM returned actually appears in ``source_text``.

    Pricing-critical fields (quantity/number_of_lengths/length_each) get the same
    "must be grounded in the customer's own words" treatment as the construction
    flags below -- an ungrounded hallucinated cut count or length flows straight
    into price with nothing else to catch it. Tolerant of leading-zero/decimal
    formatting differences (e.g. the model normalizing a customer's ".75" to
    "0.75") by also checking the leading-zero-stripped form.
    """
    if value in (None, ""):
        return True
    try:
        number = float(value)
    except (TypeError, ValueError):
        return False
    text = str(source_text or "")
    for token in {str(int_if_whole(number)), str(number)}:
        if re.search(rf"(?<!\d){re.escape(token)}(?!\d)", text):
            return True
        if token.startswith("0.") and re.search(rf"(?<!\d){re.escape(token[1:])}(?!\d)", text):
            return True
    return False


def _unit_grounded(unit: Any, source_text: str) -> bool:
    if not unit:
        return True
    text = str(source_text or "").upper()
    tokens = _UNIT_SYNONYMS.get(str(unit).strip().upper(), (str(unit).strip().upper(),))
    return any(re.search(rf"\b{re.escape(token)}\b", text) for token in tokens)


def _label_grounded(label: Any, source_text: str) -> bool:
    if not label:
        return True
    normalized_label = re.sub(r"\s+", " ", str(label)).strip().upper()
    normalized_text = re.sub(r"\s+", " ", str(source_text or "")).upper()
    return bool(normalized_label) and normalized_label in normalized_text


def _control_hint_grounded(source_text: str, context: list[str] | None = None) -> bool:
    haystack = " ".join([str(source_text or "")] + [str(value or "") for value in (context or [])])
    return bool(re.search(r"\bcontrols?\b", haystack, re.I))


def _triple_reconciles(fields: dict[str, Any]) -> bool:
    """``quantity == number_of_lengths * length_each``, within the same rounding slack
    ``agent._cut_mismatch`` allows. Proportional with an absolute floor, so a 3-decimal
    per-cut length does not fail while a genuinely wrong cut count always does."""
    try:
        quantity = float(fields.get("quantity"))
        count = float(fields.get("number_of_lengths"))
        each = float(fields.get("length_each"))
    except (TypeError, ValueError):
        return False
    return abs(quantity - count * each) <= max(0.5, 0.01 * abs(quantity))


def sanitize_llm_entry(entry: LlmEntry, source_text: str, context: list[str] | None = None) -> LlmEntry:
    """Discard high-impact construction flags the LLM inferred without explicit
    source text. Length/count parsing can be assisted; armour/screen/fire rating,
    earth handling, and a claimed cable-standard's sheath material, must be
    grounded in the customer's own words because they change the product family.
    The section headings in ``context`` count as the customer's own words for
    construction flags -- FIELD_SEMANTICS tells the model to inherit them from a
    heading ('Cu/PVC earth'), so the grounding check must look where the model
    was told to read. Quantity/length/unit and the circuit_label stay grounded
    against the row's own text alone (a heading never carries a row's quantity)
    -- see :func:`_number_grounded`/:func:`_unit_grounded`/:func:`_label_grounded`."""
    fields = dict(entry.fields or {})
    notes = entry.notes or ""
    ignored: list[str] = []
    construction_haystack = " ".join(
        [str(source_text or "")] + [str(value or "") for value in (context or [])]
    )

    # The cut triple is grounded by literal substring, with one exception: a number the
    # model DERIVED from two numbers that ARE in the text is arithmetic, not invention.
    # FIELD_SEMANTICS now asks for the third number whenever the row states two ("210m in
    # 6 cuts" -> length_each 35), and a substring check rejects exactly that, because 35
    # never appears in the text. Stripping it silently undid the instruction: the row
    # reached the quote with a cut count and no cut length, so `_format_cuts` had nothing
    # to print and the breakdown vanished with no warning -- the same "dropped by the
    # merge without a word" failure the agent's translation bug caused.
    #
    # The exception is deliberately narrow. All three must be present, the OTHER two must
    # both be grounded in the row's own text, and the three must reconcile; a third number
    # that does not multiply out is dropped exactly as before, so this cannot launder a
    # guess. Verified live 2026-09-10 on "210m in 6 cuts": terra returns 210/6/35, 35 is
    # kept because 6 x 35 == 210 and both 210 and 6 are in the text.
    triple = ("quantity", "number_of_lengths", "length_each")
    grounded = {
        key: _number_grounded(fields.get(key), source_text)
        for key in triple
        if fields.get(key) is not None
    }
    have_all_three = all(fields.get(key) is not None for key in triple)
    for key in triple:
        if fields.get(key) is None or grounded.get(key):
            continue
        others = [other for other in triple if other != key]
        if have_all_three and all(grounded.get(other) for other in others) and _triple_reconciles(fields):
            continue
        fields[key] = None
        ignored.append(key)
    if fields.get("unit") and not _unit_grounded(fields.get("unit"), source_text):
        fields["unit"] = None
        ignored.append("unit")
    if fields.get("circuit_label") and not _label_grounded(fields.get("circuit_label"), source_text):
        fields["circuit_label"] = None
        ignored.append("circuit_label")
    # A drum mark is a factory cutting instruction, so it is grounded the same way a
    # circuit label is and against the row's own text alone: an invented drum number
    # reaches the 4js comment and then the drum, where nothing downstream can catch it.
    if fields.get("drum_mark") and not _label_grounded(fields.get("drum_mark"), source_text):
        fields["drum_mark"] = None
        ignored.append("drum_mark")
    # customer_note is a verbatim transcription and is grounded as one. This check is
    # what makes a free-text field on the quote safe: the model is told to copy the
    # customer's own words, and anything it composed instead fails the substring test and
    # is dropped. Grounded against the row's own text alone -- a heading is not this
    # row's note.
    if fields.get("customer_note") and not _label_grounded(fields.get("customer_note"), source_text):
        fields["customer_note"] = None
        ignored.append("customer_note")
    for key, pattern in _EXPLICIT_FEATURE_PATTERNS.items():
        if fields.get(key) is True and not pattern.search(construction_haystack):
            fields[key] = None
            ignored.append(key)

    # earth_count/earth_only ride on the same grounding as "earth" above: a
    # split-earth multiplicity or an earth-only line with no earth indicator at
    # all in the text is exactly the kind of hallucination this guards against.
    earth_grounded = bool(_EXPLICIT_FEATURE_PATTERNS["earth"].search(construction_haystack))
    if fields.get("earth_count") and not earth_grounded:
        fields["earth_count"] = None
        ignored.append("earth_count")
    if fields.get("earth_only") and not earth_grounded:
        fields["earth_only"] = False
        ignored.append("earth_only")

    if fields.get("cable_type_hint") == "Fire Resistant" and fields.get("fire") is None:
        fields["cable_type_hint"] = None
    if fields.get("cable_type_hint") == "Control" and not _control_hint_grounded(source_text, context):
        fields["cable_type_hint"] = None
        ignored.append("cable_type_hint")

    # A "recognized cable standard" claim (e.g. MCMK) must name-check the source
    # text, or the sheath material it implies is discarded too -- otherwise the
    # model could hallucinate a standard name just to justify a sheath guess.
    standard = fields.get("recognized_standard")
    if (fields.get("pvc") or fields.get("lszh")) and not (
        standard and re.search(re.escape(standard), construction_haystack, re.I)
    ):
        fields["pvc"] = None
        fields["lszh"] = None
        ignored.append("standard_sheath")

    if ignored:
        addition = "Ignored inferred LLM flag(s) not present in source text: " + ", ".join(ignored)
        notes = f"{notes}; {addition}" if notes else addition

    return LlmEntry(
        description=entry.description,
        fields=fields,
        confidence=entry.confidence,
        notes=notes,
        reasoning=entry.reasoning,
    )


def apply_llm_fields(result: dict[str, Any], entry: LlmEntry, model: str) -> dict[str, Any]:
    fields = result.setdefault("fields", {})
    length = result.setdefault("length", {})

    if entry.fields.get("quantity") not in ("", None):
        length["quantity"] = entry.fields["quantity"]
        fields["quantity"] = entry.fields["quantity"]
    if entry.fields.get("number_of_lengths") not in ("", None):
        length["number_of_lengths"] = entry.fields["number_of_lengths"]
        fields["number_of_lengths"] = entry.fields["number_of_lengths"]
    if entry.fields.get("length_each") not in ("", None):
        length["length_each"] = entry.fields["length_each"]
        fields["length_each"] = entry.fields["length_each"]
    if entry.fields.get("unit"):
        length["unit"] = entry.fields["unit"]
        fields["unit"] = entry.fields["unit"]

    try:
        number = float(length.get("number_of_lengths"))
        each = float(length.get("length_each"))
        if number and each and not length.get("total_length"):
            total = number * each
            length["total_length"] = int(total) if total.is_integer() else total
            fields["total_length"] = length["total_length"]
    except (TypeError, ValueError):
        pass

    result["parse_tier"] = "llm"
    result["llm"] = {
        "model": model,
        "confidence": entry.confidence,
        "notes": entry.notes,
        "reasoning": entry.reasoning,
        "fields": entry.fields,
    }
    if entry.notes:
        warnings = result.setdefault("warnings", [])
        warning = f"LLM note: {entry.notes}"
        if warning not in warnings:
            warnings.append(warning)
    return result


def mark_regex_result(result: dict[str, Any]) -> dict[str, Any]:
    result.setdefault("parse_tier", "regex")
    return result


def mark_llm_attempt_failed(result: dict[str, Any], parse_result: LlmParseResult) -> dict[str, Any]:
    result.setdefault("parse_tier", "regex")
    result["llm"] = {
        "attempted": parse_result.attempted,
        "ok": False,
        "model": parse_result.model,
        "error": parse_result.error,
    }
    if parse_result.error:
        warnings = result.setdefault("warnings", [])
        warning = f"LLM fallback unavailable: {parse_result.error}"
        if warning not in warnings:
            warnings.append(warning)
    return result


def _entry_schema() -> dict[str, Any]:
    """JSON-schema for a single normalized cable entry (no product_code field).

    Shared by the reactive parse fallback (``_schema``) and the up-front
    normalizer, so both speak the exact same per-entry shape. Field names here
    map directly onto the resolver's own fields dict (see
    :func:`matcher.resolver.match_line`'s ``field_overrides``): there is no
    free-text round trip, so precise, resolver-shaped fields matter more than
    a "parser-friendly sentence" in ``description``.
    """
    nullable_string = {"type": ["string", "null"]}
    nullable_number = {"type": ["number", "null"]}
    nullable_boolean = {"type": ["boolean", "null"]}
    # anyOf, not {"type": ["string", "null"], "enum": [...]}: strict-mode schema
    # validation (Anthropic via OpenRouter) rejects "enum" paired with a type array.
    core_type_field = {"anyOf": [{"type": "string", "enum": ["C", "P", "T", "Q"]}, {"type": "null"}]}
    conductor_field = {"anyOf": [{"type": "string", "enum": ["Cu", "Al", "TinnedCu"]}, {"type": "null"}]}
    sheath_field = {"anyOf": [{"type": "string", "enum": ["PVC", "LSZH"]}, {"type": "null"}]}
    return {
        "type": "object",
        "additionalProperties": False,
        "properties": {
            "reasoning": {"type": "string"},
            "description": {"type": "string"},
            "quantity": nullable_number,
            "number_of_lengths": nullable_number,
            "length_each": nullable_number,
            "unit": nullable_string,
            "circuit_label": nullable_string,
            # The other half of the quote comment. Before this existed the model read
            # drum marks and had nowhere to put them: asked to translate "Drum mark
            # ABC123: 4C 25mm2 Cu XLPE 250m", terra returned the assumption "Drum mark
            # ABC123 is not treated as a circuit label" -- correctly refusing to force it
            # into `circuit_label`, and dropping it (verified live 2026-09-09).
            "drum_mark": nullable_string,
            # The third comment part. Named for the customer rather than for lead time
            # specifically: the real column is spelled `Lead Time / Note` and carries
            # delivery promises, handling instructions and remarks alike, and a
            # `lead_time`-only field would drop the neighbouring cases for the same cost.
            # Kept safe by definition, not by narrowness -- FIELD_SEMANTICS requires a
            # verbatim copy and `_label_grounded` enforces it against the source text, so
            # this is transcription, never the model's own prose.
            "customer_note": nullable_string,
            "core_count": nullable_number,
            "core_type": core_type_field,
            "cross_section_mm2": nullable_number,
            "conductor": conductor_field,
            "has_earth_core": nullable_boolean,
            "earth_multiplicity": nullable_number,
            "is_earth_only": {"type": "boolean"},
            "cable_type_hint": nullable_string,
            "screen_requested": nullable_boolean,
            "armoured_requested": nullable_boolean,
            "fire_rated_requested": nullable_boolean,
            "recognized_standard": nullable_string,
            "standard_sheath": sheath_field,
            "style_series_code": nullable_string,
            "confidence": {"type": "number"},
            "notes": {"type": "string"},
        },
        "required": [
            "reasoning",
            "description",
            "quantity",
            "number_of_lengths",
            "length_each",
            "unit",
            "circuit_label",
            "drum_mark",
            "customer_note",
            "core_count",
            "core_type",
            "cross_section_mm2",
            "conductor",
            "has_earth_core",
            "earth_multiplicity",
            "is_earth_only",
            "cable_type_hint",
            "screen_requested",
            "armoured_requested",
            "fire_rated_requested",
            "recognized_standard",
            "standard_sheath",
            "style_series_code",
            "confidence",
            "notes",
        ],
    }


def _schema() -> dict[str, Any]:
    return {
        "type": "object",
        "additionalProperties": False,
        "properties": {
            "entries": {
                "type": "array",
                "items": _entry_schema(),
            }
        },
        "required": ["entries"],
    }


_CONDUCTOR_CODES = {"CU": "N", "AL": "A", "TINNEDCU": "D"}


def _entry_from_payload(item: dict[str, Any]) -> LlmEntry:
    """Build the resolver-ready field-override dict directly from the model's
    structured answer -- no intermediate free-text representation. Keys here
    (count, cross_section, conductor, earth, screen, ...) match exactly what
    :func:`matcher.resolver.match_line` reads from its own fields dict, so
    they can be merged straight in with no re-parsing step in between.
    """
    core_count = int_if_whole(item.get("core_count"))
    recognized_standard = str(item.get("recognized_standard") or "").strip() or None
    standard_sheath = item.get("standard_sheath") if recognized_standard else None
    conductor_code = _CONDUCTOR_CODES.get(str(item.get("conductor") or "").strip().upper())
    cross_section_mm2 = item.get("cross_section_mm2")

    fields = {
        "quantity": item.get("quantity"),
        "number_of_lengths": item.get("number_of_lengths"),
        "length_each": item.get("length_each"),
        "unit": item.get("unit"),
        "circuit_label": str(item.get("circuit_label") or "").strip() or None,
        "drum_mark": str(item.get("drum_mark") or "").strip() or None,
        "customer_note": str(item.get("customer_note") or "").strip() or None,
        "count": core_count,
        "core_type": item.get("core_type") or ("C" if core_count else None),
        "cross_section": canonical_number(cross_section_mm2) if cross_section_mm2 is not None else None,
        "conductor": conductor_code,
        "earth": True if item.get("has_earth_core") else None,
        "earth_count": int_if_whole(item.get("earth_multiplicity")),
        "earth_only": bool(item.get("is_earth_only")),
        "cable_type_hint": _canonical_cable_type(item.get("cable_type_hint"), item.get("fire_rated_requested")),
        "screen": True if item.get("screen_requested") else None,
        "armoured": True if item.get("armoured_requested") else None,
        "fire": True if item.get("fire_rated_requested") else None,
        "pvc": True if standard_sheath == "PVC" else None,
        "lszh": True if standard_sheath == "LSZH" else None,
        "recognized_standard": recognized_standard,
        "explicit_style_hint": str(item.get("style_series_code") or "").strip().upper() or None,
    }
    confidence = max(0.0, min(1.0, float(item.get("confidence") or 0.0)))
    return LlmEntry(
        str(item.get("description") or ""),
        fields,
        confidence,
        str(item.get("notes") or ""),
        reasoning=str(item.get("reasoning") or ""),
    )


def _loads_json_object(content: Any) -> dict[str, Any]:
    if isinstance(content, dict):
        return content
    text = str(content or "").strip()
    if text.startswith("```"):
        text = re.sub(r"^```(?:json)?\s*", "", text)
        text = re.sub(r"\s*```$", "", text)
    match = re.search(r"\{.*\}", text, re.S)
    if match:
        text = match.group(0)
    parsed = json.loads(text)
    if not isinstance(parsed, dict):
        raise ValueError("response was not a JSON object")
    return parsed


def _canonical_cable_type(value: Any, fire_rated: Any) -> str | None:
    if fire_rated:
        return "Fire Resistant"
    text = str(value or "").strip().lower()
    mapping = {
        "fire": "Fire Resistant",
        "fire rated": "Fire Resistant",
        "fire resistant": "Fire Resistant",
        # "Radox" is the brand name customers use for the fire-rated range
        # (confirmed 2026-08-03) -- mirrors the regex signal in extractor.py.
        "radox": "Fire Resistant",
        "vsd": "VSD/VFD",
        "vfd": "VSD/VFD",
        "emc": "VSD/VFD",
        "control": "Control",
        "instrument": "Instrumentation",
        "instrumentation": "Instrumentation",
        "solar": "Solar",
        "welding": "Welding",
        "building wire": "Building Wire",
        "power": "Power",
    }
    return mapping.get(text, value if value else None)


def _float_env(name: str, default: float) -> float:
    try:
        return float(os.environ.get(name, default))
    except (TypeError, ValueError):
        return default


def _looks_worth_llm(text: str) -> bool:
    upper = str(text or "").upper()
    if not upper.strip():
        return False
    has_cable_word = re.search(r"\b(CABLE|CU|COPPER|AL|ALUMINIUM|ALUMINUM|EARTH|SCREEN|FIRE|VSD|VFD)\b", upper)
    has_dimension = re.search(r"\b\d+(?:\.\d+)?\s*(?:M|MM2|MM)\b", upper)
    has_quantity = re.search(r"\b\d+\s*X\b", upper)
    return bool(has_cable_word and (has_dimension or has_quantity))
