from __future__ import annotations

import re


COLOUR_ALIASES = {
    "BLK": "BK",
    "BLACK": "BK",
    "BLUE": "BL",
    "RED": "RE",
    "GREEN/YELLOW": "EA",
    "GREEN YELLOW": "EA",
    "G/Y": "EA",
    "GN/YE": "EA",
    "GYW": "EA",
    "EARTH": "EA",
    "ORANGE": "OR",
    "PURPLE": "PU",
    "PINK": "PI",
    "WHITE": "WH",
    # Grey is GY in the catalog; without this alias the 2-char fallback would map
    # "GREY"/"GRAY" to "GR", which is GREEN -- a silent wrong-colour. "GREEN" is
    # pinned to GR for the same reason.
    "GREY": "GY",
    "GRAY": "GY",
    "GREEN": "GR",
    # Both are real catalog colours (LB on 12 products, DB on 69). The 2-char fallback
    # would map them to "LI"/"DA", which are not colour codes at all.
    "LIGHT BLUE": "LB",
    "DARK BLUE": "DB",
}


def normalize_text(text: str) -> str:
    value = str(text or "")
    value = value.replace("\u00a0", " ")
    value = value.replace("\u00c2\u00b0", " deg ").replace("\u00b0", " deg ").replace("\u00ba", " deg ")
    value = value.replace("–", "-").replace("—", "-").replace("×", "x")
    value = value.replace("²", "2").replace("Ø", " diameter ")
    value = re.sub(r"\bSQ\s*MM\b", "mm2", value, flags=re.I)
    value = re.sub(r"\bMM\^2\b", "mm2", value, flags=re.I)
    # European decimal comma on a conductor size: "2,5mm" is 2.5mm2, not 5.
    # Read literally it resolved a 2.5mm2 request to a 4C 5mm2 product (Pro-built
    # Cobar, DIRECTIVE_CAPTURE_PLAN §1.3 row 3).
    #
    # Deliberately requires the "mm" unit right after one or two fractional
    # digits, so the two other things a comma between digits can mean are left
    # alone: a thousands separator ("2,500m" -- three digits, and the unit is m)
    # and a list of circuit numbers ("Cables 2,5 &7 - 70mm2" -- no unit at all).
    value = re.sub(r"(?<![\d.])(\d),(\d{1,2})\s*(?=mm)", r"\1.\2", value, flags=re.I)
    value = re.sub(r"(?<=[0-9])MTRS?\b|\bMTRS?\b", "m", value, flags=re.I)
    value = re.sub(r"(?<=[0-9])MET(?:ER|RE)S?\b|\bMET(?:ER|RE)S?\b", "m", value, flags=re.I)
    # Some sheets glue the conductor straight onto the size ("630mmAL", "70mmCU") with
    # no space, which hides the conductor from every \b-anchored regex downstream
    # (alias substitution, conductor detection, size extraction). Split it back out.
    value = re.sub(r"(?<=[0-9])MM(?=(?:AL|CU)\b)", "MM ", value, flags=re.I)
    value = re.sub(r"\bALU\b|\bALI\b", "AL", value, flags=re.I)
    value = re.sub(r"\bALIFLEX\b", "AL FLEX", value, flags=re.I)
    value = re.sub(r"\bALUMINIUM\b|\bALUMINUM\b", "AL", value, flags=re.I)
    value = re.sub(r"\bCOPPER\b", "CU", value, flags=re.I)
    # "E/W" is trade shorthand for "earth wire" -- an earth marker, not a separate
    # conductor. Expanded to the full word here so every downstream earth check
    # (the extractor's ADDED_EARTH_RE, the splitter's EARTH_RE, the colour map)
    # sees it without each having to learn the abbreviation. Runs before the "/E"
    # rule below so "4C/E/W" collapses the same way "4C/E" does.
    value = re.sub(r"\bE\s*/\s*W\b", "EARTH", value, flags=re.I)
    value = re.sub(r"(?<=C)\s*&\s*EARTH\b", "+E", value, flags=re.I)
    value = re.sub(r"(?<=C)\s*&\s*(?=(?:\d+\s*)?E\b)", "+", value, flags=re.I)
    # "<n> core and earth" -> "<n>C+E". Glue the count to the C: the separator varies
    # ("4 core", "4-core", "4core"), but the extractor's core regex only reads the glued
    # "<n>C" form. Run this before the bare "core and earth" rule below, or that rule
    # eats "core and earth" first and strands the count -- which silently collapsed the
    # cable to 1C.
    value = re.sub(r"\b(\d+)[\s\-]*CORE[\s\-]*(?:AND|\+|/)[\s\-]*EARTH\b", r"\1C+E", value, flags=re.I)
    value = re.sub(r"\bCORE\s+AND\s+EARTH\b", "C+E", value, flags=re.I)
    # Some customers separate the earth with a slash instead of a plus ("4C/E",
    # "4C/EARTH"). It means the same thing -- an earth counted as a core -- but the
    # "/" form reached the extractor untouched, so CORE_PLUS_E_RE never fired and
    # the cable silently quoted as a 4C. The leading digit + C is required so a
    # slash-separated construction string ("XLPE/PVC") can never match.
    value = re.sub(r"\b(\d+)\s*C\s*/\s*(?:E|EARTH)\b", r"\1C+E", value, flags=re.I)
    value = re.sub(r"\b(\d+)[\s\-]*CORE\b", r"\1C", value, flags=re.I)
    value = re.sub(r"\b(\d+)\s*PAIR\b", r"\1P", value, flags=re.I)
    value = re.sub(r"\b(\d+)\s*TRIAD\b", r"\1T", value, flags=re.I)
    value = re.sub(r"\b(\d+)\s*QUAD\b", r"\1Q", value, flags=re.I)
    value = re.sub(r"\bX\s*-\s*HF\s*-\s*110\b|\bXHF110\b", "X-HF-110", value, flags=re.I)
    value = re.sub(r"\bX\s*-\s*90\b|\bX90\b", "X-90", value, flags=re.I)
    value = re.sub(r"\bV\s*-\s*90\b|\bV90\b", "V-90", value, flags=re.I)
    value = re.sub(r"\s+", " ", value).strip()
    return value


def int_if_whole(value: str | int | float | None) -> int | float | None:
    if value in ("", None):
        return None
    try:
        number = float(value)
    except (TypeError, ValueError):
        return None
    return int(number) if number.is_integer() else number


def canonical_number(value: str | int | float | None) -> str:
    if value is None:
        return ""
    text = str(value).strip()
    if not text:
        return ""
    text = text.replace(",", "")
    try:
        number = float(text)
    except ValueError:
        return text
    if number.is_integer():
        return str(int(number))
    return str(number).rstrip("0").rstrip(".")


def canonical_colour(value: str | None) -> str:
    if not value:
        return ""
    text = re.sub(r"[^A-Z/ ]", "", value.upper()).strip()
    return COLOUR_ALIASES.get(text, text[:2])
