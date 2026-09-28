from __future__ import annotations

import re
from typing import Any

from .accessories import accessory_quantity_from_text, find_accessory_code
from .cable_code import parse_code
from .normalize import canonical_colour, canonical_number, normalize_text


# Who put a value into the fields dict. `explicit_fields` used to be `set(fields)` --
# every *populated* key, not every key the customer *stated* -- so a house default and an
# LLM guess carried the same authority as a fact the customer typed, and
# `violates_hard_constraints` turned all three into hard filters alike. Only
# FIELD_SOURCE_CUSTOMER earns that authority.
#
# FIELD_SOURCE_AGENT is deliberately NOT promoted to customer by grounding. Grounding
# proves a word appeared somewhere in the row or its headings; on a switchboard line
# naming three colours it cannot tell you the model picked the right one.
FIELD_SOURCE_CUSTOMER = "customer"   # read from the customer's own literal text
FIELD_SOURCE_REGEX = "regex"         # derived by the extractor, not stated
FIELD_SOURCE_AGENT = "agent"         # proposed by the LLM/agent
FIELD_SOURCE_DEFAULT = "default"     # a house/style default the resolver supplied


ACCESSORY_RE = re.compile(
    r"\b(lugs?|glands?|heat\s*shrink|heatshrink|ties?|cable\s*ties?|bootlace|bellmouth|ripcord"
    r"|barrel\s+links?|crimp\s+links?|copper\s+links?|copper\s+barrel\s+links?|bs\s*95"
    # "reducer" was already here; "reducing link" is the same product spelled the way
    # customers actually write it, and without it "400mm2 reducing link to 240mm2" carried
    # no accessory hint at all and resolved to XL-PAXN/1C400OR as `matched` -- a silent
    # wrong cable, not a dropped row. Bare "links?" stays out: across the 6,748-line file
    # corpus it fires on 13 lines, 11 already covered by the barrel/crimp/copper spellings
    # above and one of the rest a Modern Slavery questionnaire asking for "a link".
    r"|reducing\s+links?|bi-?\s?metallic\s+links?"
    # "Conduit wire" names the CABLE -- green/yellow conduit wire is an earth wire, and
    # `73-Sohil` asks for 300 m of it. Conduit itself is an accessory. "wiring" is covered
    # as well as "wire"/"cable": the spelling should not decide whether a 6mm2 earth is
    # quoted as a cable or routed to the accessory panel with no product code.
    # Other fitting nouns (e.g. glands) still match independently.
    r"|terminals?|insulators?|split\s*loom|loom\s*tube|conduits?(?![\s-]+(?:wir(?:e|es|ing)|cables?)\b)|saddles?|reducers?|enlargers?"
    r"|end\s*caps?|breakouts?|plugs?|sockets?|isolators?|switches?|cable\s*mounts?|stockings?"
    # Crimp-termination vocabulary (docs/EMAIL_CORPUS_AUDIT.md 4.6). Without these
    # the bore size on the line reads as a conductor size and the row resolves to a
    # cable: "1 bag x 25mm2 single ferrule" returned XL-PAXN/1C25OR as `matched`,
    # and "2 bags x 6.4mm female spade" returned XL-PAXN/1C6.4OR.
    r"|ferrules?|spades?|bullets?|splice\s*connectors?|piggy\s*backs?|crimps?)\b",
    re.I,
)
# "cut"/"cutting" is NOT general accessory vocabulary. "@ 55 meters per cut" is the
# commonest way a contractor states a cut length, and while these tokens sat unguarded
# in ACCESSORY_RE every such line was routed to the accessory panel with no product
# code -- the mains vanished from the quote entirely (Chris/Dan RFQ 2026-08-26, where
# every main read "4x 1Cx 120mm X-HF-110 @ 55 meters per cut"). Kept as a separate
# hint, gated on the line carrying no cable evidence, so a standalone cutting-charge
# line still reaches the accessory panel.
CUT_SERVICE_RE = re.compile(r"\b(?:cutting|cuts?)\b", re.I)
# A quantity counted in packs is accessory grammar: cable is quoted in metres, and
# nothing sold by the bag is a cable. This is the same prepack vocabulary the
# pricing layer already models. "roll" and "drum" are deliberately absent -- cable
# IS sold that way -- and the hint is only applied to lines carrying no cable
# construction evidence, so "2 bags x 4C 6mm2 XLPE" stays a cable.
PACK_QUANTITY_RE = re.compile(
    r"\b\d+\s*(?:bags?|boxe?s?|packe?ts?|pkts?|packs?|individual)\b\s*(?:x|of)?\b",
    re.I,
)
# Evidence that a line describes a cable rather than a bagged accessory: a core
# count, or any construction/insulation word.
_CABLE_EVIDENCE_RE = re.compile(
    # "1Cx 120mm" -- a core count written straight against the size -- is ordinary
    # contractor notation, but \b\d+\s*C\b misses it: the trailing "x" leaves no word
    # boundary after the C. X-HF-110 is insulation wording and counts as evidence too.
    r"\b\d+\s*C(?:\s*\+\s*\d*\s*E)?\b|\b\d+\s*C\s*(?=x\s*\d)"
    r"|\b\d+\s*(?:core|cores|pair|pairs|triad|triads)\b"
    r"|\b(?:x-hf|xlpe|pvc|swa|awa|lszh|hffr|flex|sdi|tps|screened|armoured|armored|sheath)\b",
    re.I,
)
ADMIN_RE = re.compile(
    r"\b(project|supplier|quote number|purchase order|date:|company:|unit price|total price|terms|compliance|delivery)\b",
    re.I,
)
# Communications / data cables (fibre optic, structured data). TriCab does NOT
# manufacture these, so they are flagged "not available" rather than forced into a
# copper power/control style.
COMMS_RE = re.compile(
    r"\b(single\s*mode|singlemode|multi\s*mode|multimode|fibre|fiber|optical\s*fib(?:re|er)"
    r"|cat\s*-?\s*[567][ae]?|category\s*[567]|os[12]\b|om[1-4]\b|rj45|ethernet)\b",
    re.I,
)
PRODUCT_CODE_RE = re.compile(r"\b[A-Z0-9]{1,3}[A-Z]?-[A-Z0-9]+/\d+(?:\.\d+)?[A-Z][0-9A-Z.]*[A-Z]{1,3}\b")
# Sales sometimes appends a spool/reel length directly onto a product code with no
# separator, e.g. "LS-XXXD/1C1.5EA200" for the LS-XXXD/1C1.5EA product cut to 200m.
# Captured as a distinct pattern (base code + trailing digits) rather than folded
# into PRODUCT_CODE_RE, since callers of PRODUCT_CODE_RE rely on .findall()
# returning bare match strings, not tuples. The base code is validated against the
# catalog before use (see find_exact_product_code), so this isn't confused with a
# code family whose real code just happens to end in digits with no colour suffix
# (e.g. "KP-PDXN/4C120", where the digits are load-bearing, not a length suffix).
PRODUCT_CODE_WITH_LENGTH_RE = re.compile(
    r"\b([A-Z0-9]{1,3}[A-Z]?-[A-Z0-9]+/\d+(?:\.\d+)?[A-Z][0-9A-Z.]*[A-Z]{1,3})(\d{2,5})\b"
)
# Square-millimetre unit, as customers actually spell it. "sqmm" / "sq.mm" /
# "sq mm" are routine in RFQs routed through Indian and Gulf offices, and were
# invisible to every size rule until 2026-08-18: the line was captured only when
# some OTHER token (a "4C", a "1 Core") happened to carry the cable signal, and
# even then its cross-section came back None, so it could not resolve
# (docs/EMAIL_CORPUS_AUDIT.md 4.5). Kept as one constant so the signal regex,
# the size parser and the splitter cannot drift apart again.
# `mm` immediately followed by a conductor word carries no word boundary, so "400mmAu" and
# "95mmAl" lost their size entirely and resolved to nothing -- 22 rows on one real RFQ whose
# subject line reads "Aluminum Mains Cables". The lookahead adds that case without loosening
# `mm\b`, so "400mmABC" is still not a size.
SQ_MM = r"(?:sq\.?\s*mm|mm2|mm\b|mm(?=(?:CU|AL|AU)\b))"
SIZE_RE = re.compile(r"\b(\d+(?:\.\d+)?)\s*" + SQ_MM, re.I)
# Rated voltage, e.g. "0.6/1kV", "600/1000V", "1.9/3.3kV", "11kV", "33kV". The
# second number of a pair is U (phase-to-phase); a lone number is U directly.
# `volts` spelled out and a trailing AC/DC both read as the same rating: "110 volts" and
# "110VAC" are how customers write 110 V, and neither matched while the unit was a bare
# `(kv|v)\b` -- the \b failed against the following letter, so the row carried no voltage
# at all. `LV`/`MV`/`HV` are deliberately NOT mapped to a band: they are class words, not
# ratings, and inventing 600/1000 from "LV" is exactly what FIELD_SEMANTICS forbids the
# agent from doing. The unit group stays group(3) so the kV scale test is unchanged, and
# the optional tail cannot swallow a following token ("110 VSD" still does not match,
# because `\b` after the unit fails against the S).
VOLTAGE_RE = re.compile(
    r"\b(\d+(?:\.\d+)?)\s*(?:/\s*(\d+(?:\.\d+)?))?\s*(kv|v)(?:olts?)?(?:\s*[/-]?\s*(?:ac|dc))?\b",
    re.I,
)
# See the conductor block in extract_entities: aluminium abbreviated "Au", but only where
# it is attached to a size, so an address's country code cannot be read as a material.
SIZE_ATTACHED_AU_RE = re.compile(r"\b\d+(?:\.\d+)?\s*(?:MM2?|SQ\.?\s*MM)\s*AU\b", re.I)
BARE_SIZE_RE = re.compile(r"\b(\d+(?:\.\d+)?)\s+(?=(?:CU|AL|XLPE|PVC|FIRE|VSD|VFD|SCREEN|SCR)\b)", re.I)
CORE_PLUS_E_RE = re.compile(r"\b(\d+)\s*C\s*\+\s*(\d+)?\s*E\b", re.I)
# A "+ EARTH" / "+ E" that is NOT adjacent to the core count (e.g. "4C 70mm Al + EARTH")
# is still an earth conductor added to this one cable. Any earth quoted as its own
# run has already been peeled off by the splitter before extraction, so a surviving
# "+ earth" here is always additive: TriCab counts it as an extra core (4C -> 5C).
ADDED_EARTH_RE = re.compile(r"\+\s*(\d+)?\s*(?:E|EARTH|ECC|G/?Y|GN\s*/\s*YE|GREEN\s*/?\s*YELLOW)\b", re.I)
# A standalone parenthesized "(E)" / "(EARTH)" suffix, e.g. "1C 10mm2 Cu PVC (E)".
# This is the same "the customer marked this core as earth" signal as ADDED_EARTH_RE's
# "+E", but the leading "+" is gone by the time this line reaches extract_entities:
# the splitter already peeled a compound "... + 1C 10mm2 Cu PVC (E)" into its own
# entry, consuming the "+". Without this, a split-off earth line is indistinguishable
# from an ordinary 1-core cable and gets the wrong colour/style entirely.
EARTH_SUFFIX_RE = re.compile(r"\(\s*(?:E|EARTH|ECC|G/?Y|GN\s*/\s*YE|GREEN\s*/?\s*YELLOW)\s*\)\s*$", re.I)
# The same "this conductor is the earth" signal without the brackets: a bare trailing "E"
# pinned to a conductor size ("120mm2 E", "1C 120mm2 E CU", "95mm Cu E"). That is how an
# earth-size column or an earth summary row is written in a spreadsheet, and without this
# the row read as an ordinary single-core power cable -- wrong style (XL rather than LP),
# wrong colour (OR rather than EA) and wrong price. A lone "E" is far too generic to mean
# an earth anywhere else in a line (a stray initial, a revision letter, a unit fragment),
# so it has to end the line, separated from the size by nothing but conductor words.
#
# The conductor word can fall on either side of the "E" -- both "95mm2 E Cu" and
# "95mm Cu E" occur, and an earlier version of this pattern only allowed the first, so
# the far more common "<size> Cu E" ordering still went through as a power cable.
#
# Deliberately NOT applied to a stated multicore ("4C 16mm2 E"): there the "E" is the "+E"
# shorthand, which changes the CORE COUNT (TriCab counts the earth as a core, so 4C+E is a
# 5C cable), not the colour of a 4C. Reading it here would quote the wrong cable, so that
# form still needs its explicit "+" (CORE_PLUS_E_RE / ADDED_EARTH_RE).
_EARTH_CONDUCTOR_WORD = r"(?:CU|AL|TC|TACW|TINNED|FLEX|WIRE)"
BARE_EARTH_SIZE_RE = re.compile(
    r"\b\d+(?:\.\d+)?\s*(?:MM2?|SQ\.?\s*MM)"
    rf"(?:\s*{_EARTH_CONDUCTOR_WORD})*"
    r"\s*E\b"
    rf"(?:\s+{_EARTH_CONDUCTOR_WORD})*\s*$",
    re.I,
)
# No space allowed between the count and "C" -- a space almost always means a
# temperature statement instead ("90 C", "110 C"), never core count (which
# normalize_text already tightens from "N CORE" to "NC" with no space).
CORE_RE = re.compile(r"\b(\d+)C\b", re.I)
G_CORE_RE = re.compile(r"\b(\d+)G(\d+(?:\.\d+)?)\b", re.I)
# Single-core conductors laid in triangular (trefoil) formation -- always single-core
# construction, e.g. "3 x 1 core Trefoil" (cable-schedule Configuration-column wording).
TREFOIL_RE = re.compile(r"\bTREFOIL\b", re.I)
SCHEDULE_LEADING_SPEC_RE = re.compile(
    r"\b(?:ACTIVE\s*)?(?:(?P<active_runs>\d+)\s*[x×]\s*)?"
    r"(?P<size>\d+(?:\.\d+)?)\s*MM2?\s*(?P<material>CU|AL)\b",
    re.I,
)
SCHEDULE_NEUTRAL_SPEC_RE = re.compile(
    r"\bNEUTRAL\s*(?:(?P<neutral_runs>\d+)\s*[x×]\s*)?"
    r"(?P<size>\d+(?:\.\d+)?)\s*MM2?\s*(?P<material>CU|AL)?\b",
    re.I,
)
SCHEDULE_TREFOIL_CONFIG_RE = re.compile(r"\b(?P<runs>\d+)\s*[x×]\s*1\s*C(?:ORE)?\s*TREFOIL\b", re.I)
SCHEDULE_MULTICORE_CONFIG_RE = re.compile(r"\b(?P<cores>\d+)\s*C(?:ORE)?\s*(?P<formation>FLAT|CIRCULAR)\b", re.I)
PAIR_RE = re.compile(r"\b(\d+)\s*(?:P|PR|PAIR|PAIRS)\b", re.I)
TRIAD_RE = re.compile(r"\b(\d+)\s*(?:TRIADS|TRIAD|TR|T)\b", re.I)
QUAD_RE = re.compile(r"\b(\d+)\s*(?:Q|QUAD|QUADS)\b", re.I)
# (?<![\d.]) instead of \b so a length glued onto an x with no space ("...Flex
# x16m", 08-Julie's chained notation) still reads as 16 m; \b sees x|1 as one
# word and misses it entirely. Sizes ("400mm") stay unmatched -- the first m is
# followed by another m, never a boundary.
LENGTH_RE = re.compile(r"(?<![\d.])(\d+(?:\.\d+)?)\s*m\b", re.I)
RUNS_RE = re.compile(r"\b(\d+)\s*x\s*(\d+(?:\.\d+)?)\s*m\b|\b(\d+)\s*x\s*(\d+(?:\.\d+)?)\s*m\s*runs?\b", re.I)
DRUM_RE = re.compile(r"\b(\d+)\s*(?:x\s*)?(?:drums?|runs?|lengths?|cuts?)\s*(?:x|of)?\s*(\d+(?:\.\d+)?)\s*m\b", re.I)
# DRUM_RE only fires when the length sits right after the drum count ("4 drums of 75m").
# Written as a sentence, the cable description gets in between -- "4 drums of 400mm Cu XLPE
# at 75 m each drum" -- and the count of 4 was being dropped, quoting a single 75 m drum.
# So also recognise a drum count on its own and let the length branches below multiply by
# it, exactly as they already do for LEADING_NO_COUNT_RE's "12no." runs.
#
# A digit must immediately precede the noun, which is what keeps the trailing "each drum"
# (and the "75 m" before it) from being read as a count.
DRUM_COUNT_RE = re.compile(r"\b(\d+)\s*(?:x\s*)?(?:drums?|reels?|coils?)\b", re.I)
# "640m x 240mm 1C AL flex (8 cuts)" -- a cut count with no length of its own. Unlike
# every other count in this function it DIVIDES rather than multiplies: the stated
# length is the total and the count says how many pieces it arrives in, so 640 m in
# 8 cuts is 8 x 80 m, not 8 x 640 m. "8 cuts of 40m" names both and is DRUM_RE's job.
#
# Nothing read this before, in any phrasing, which made it the most explicit thing a
# customer can say about quantity and the one the text path could not hear. It matters
# past the comment: `pipeline._prepack_applies` treats a row with no explicit cuts as
# bulk and lets whole-spool rounding rewrite its lengths, and spools cannot be joined
# into the continuous 80 m run the customer actually asked for. The table path has had
# this since `file_parser.select_quantity`; this is the same rule for prose.
CUT_COUNT_RE = re.compile(r"\b(\d+)\s*(?:off\s+)?cuts?\b", re.I)
TRAILING_BARE_LENGTH_RE = re.compile(r"(?:\|\s*|\s+)(\d+(?:\.\d+)?)\s*$")
# A trailing unitless "8 X 40" (often a quantity cell joined onto the row text,
# 44-Amanda) is 8 cuts of 40 m, not a 40 m single -- and never a size: the
# leading pipe/space boundary keeps "2.5mm2 x 500" from matching its "2".
TRAILING_CUTS_RE = re.compile(r"(?:\|\s*|\s+)(\d+)\s*[x×]\s*(\d+(?:\.\d+)?)\s*$", re.I)
# "12no." / "12 No" prefix: that many parallel runs of the cable that follows
# (80-Sohil), analogous to a leading "12 x".
LEADING_NO_COUNT_RE = re.compile(r"^\s*(\d+)\s*no\.?s?\b", re.I)
# "Nx SIZE1 + [Mx] SIZE2 earth" -- parallel single-core conductors run in parallel per
# circuit (standard notation for large Al/Cu feeders), NOT a multicore cable and NOT an
# earth-only cable. N phase conductors of SIZE1 + M (usually 1) earth conductor(s) of
# SIZE2 (often a reduced size, e.g. "6x185mm + 70mm Earth"). Each side is a SEPARATE
# single-core product -- this must become two line items, which a single fields dict
# can't model, so this only prevents the false-confident earth-only collapse below and
# flags the row for AI to split (see FIELD_SEMANTICS in llm_fallback.py).
PARALLEL_SINGLE_CORE_RE = re.compile(
    r"^\s*(\d+)\s*x\s*(\d+(?:\.\d+)?)\s*mm2?\s*\+\s*(?:(\d+)\s*x\s*)?(\d+(?:\.\d+)?)\s*mm2?\s*earth\b",
    re.I,
)
# Parallel single-core feeder runs with the counts glued straight onto the "1C"
# core token: "4x1C" (4 single-core runs), "3x4x1C" (3 circuits x 4 conductors =
# 12 runs), "3x - 4x1C" (a stray dash between the two counts). Every conductor is
# the SAME single-core product, so both multipliers are a run/length count, never
# a core count -- the product itself stays 1C. Unlike PARALLEL_SINGLE_CORE_RE
# above, the phases and their earth are on SEPARATE lines here, so each line is
# one product and resolves confidently (no AI split needed). Critically, the
# glued "x1C" has no word boundary between the "x" and the "1", so CORE_RE below
# never sees the core token at all -- without this the whole row silently
# collapses to a single 1C cut and every conductor after the first is dropped.
# The trailing alternative matches the glued "1Cx SIZE" notation ("4x 1Cx 120mm"),
# where the x that introduces the size leaves no word boundary after the C. Without
# it the leading multiplier was invisible and a 4-run feeder quoted as one run.
SINGLE_CORE_RUNS_RE = re.compile(
    r"(?:(?P<circuits>\d+)\s*[x×]\s*[-–]?\s*)?(?P<cores>\d+)\s*[x×]\s*1\s*C(?:\b|(?=\s*[x×]\s*\d))",
    re.I,
)
# A leading run multiplier on an earth-only feeder line: "3x 240mm earth" -> 3
# earth conductors, "3x -25mm earth" -> 3. Mirrors SINGLE_CORE_RUNS_RE for the
# phase runs; the count is a run/length count on the single earth product.
LEADING_RUN_MULT_RE = re.compile(r"^\s*(\d+)\s*[x×*]\s*[-–]?\s*(?=\d)", re.I)
# The +EARTH counterpart of SINGLE_CORE_RUNS_RE: "Nx1C+E" is NOT N single-core
# runs but one N-core-plus-earth MULTICORE cable -- TriCab counts the earth as a
# core, so "4x1c+e" is a 5C cable (like "4C+E"). A leading "Mx" is then the
# number of identical cables ("3x4x1c+e" -> 3 cables of 5C). The trailing "+E"
# is what flips the read from parallel single cores to a bundled multicore.
SINGLE_CORE_PLUS_EARTH_RE = re.compile(
    r"(?:(?P<cables>\d+)\s*[x×]\s*[-–]?\s*)?(?P<cores>\d+)\s*[x×]\s*1\s*C\s*"
    r"\+\s*(?P<earth>\d+)?\s*(?:E|EARTH|ECC|G/?Y|GN\s*/\s*YE|GREEN\s*/?\s*YELLOW)\b",
    re.I,
)
# A bundled multicore written as phase cores plus a reduced-size extra core, the
# core sizes given with a single trailing "mm2": "4x10+1x4mm2" is a 5-core cable
# (4 x 10mm2 + 1 x 4mm2 earth/neutral) whose product cross-section is the PHASE
# size (10), not the reduced earth (4). Distinct from PARALLEL_SINGLE_CORE_RE
# below, which needs the word "earth" and mm on both sides and stays two products
# (parallel single-core phases + a separate earth run) -- so that shape is checked
# first and this pattern only fires when there is no such "earth" split.
MULTICORE_PLUS_CORE_RE = re.compile(
    r"(?:(?P<cables>\d+)\s*[x×]\s*[-–]?\s*)?"
    r"(?P<cores>\d+)\s*[x×]\s*(?P<size>\d+(?:\.\d+)?)\s*(?:mm2?)?\s*"
    r"\+\s*(?P<extra>\d+)\s*[x×]\s*(?P<size2>\d+(?:\.\d+)?)\s*mm2?\b",
    re.I,
)
# A leading cable multiplier on a MULTICORE row: "3x 4c+e" / "3x4c+e" / "3 x 4c"
# means that many identical multicore cables (a number_of_lengths count), NOT
# single-core runs. The glued "3x4c" also defeats the boundary in CORE_RE /
# CORE_PLUS_E_RE (no boundary between the "x" and the core digit), so the core
# count is recovered from here when those missed it. The single-core "1c" forms
# (Nx1C, Nx1C+E) are handled elsewhere, so the caller ignores a 1-core token.
MULTICORE_CABLE_MULT_RE = re.compile(
    r"^\s*(?P<cables>\d+)\s*[x×]\s*[-–]?\s*(?P<cores>\d+)\s*C\b"
    r"(?P<earth>\s*\+\s*(?P<earthnum>\d+)?\s*(?:E|EARTH|ECC|G/?Y|GN\s*/\s*YE|GREEN\s*/?\s*YELLOW)\b)?",
    re.I,
)
# Circuit label / description that appears before the cable spec, separated by : or –.
# e.g. "Circuit 1: 4C 70mm Cu XLPE 150m" → "Circuit 1"
#      "Main Board → DB1 – 4C 35mm Cu"   → "Main Board → DB1"
NOTE_PREFIX_RE = re.compile(
    r"^(.{2,80}?)\s*(?::|–|—|-{1,2})\s+"
    r"(?=\d+\s*[cpgtq]\b|\d+(?:\.\d+)?\s*mm|\d+(?:\.\d+)?\s*kv"
    r"|\b(?:cu|al|xlpe|pvc|fire|vsd|control|power)\b)",
    re.I,
)
# The label needs a boundary on BOTH sides. Without the trailing `\b`, `TO` matched
# inside the word "total" -- the commonest word in an RFQ length clause -- and the
# capture returned "tal" as the circuit label: "8 x 240m (1920m total)" produced the
# comment `tal) (8 x 240)`, on 9 of 178 lines of the text corpus. Worse, because
# `_route_label` prefers any non-empty regex label over the AI's, a junk `tal)` beat a
# correctly-read `MSSB-L3-4` from terra and suppressed it (verified live 2026-09-09).
OCR_ROUTE_FIELD_RE = re.compile(
    r"\b(?P<label>FROM|TO|CABLE\s*NO)\b\s*[:=]?\s*(?P<value>[^,|]+)",
    re.I,
)
# Circuit label given on its own line immediately above the cable description,
# rather than on the same line before a : or - separator (see NOTE_PREFIX_RE
# above) -- common when a schedule's tag column reflows onto its own line in
# plain-text/PDF-copied text, e.g.
#     MSSB-L3-4-E
#     1 X 131m x 16mm Fire Rated 5core
# A line only counts as a label when it has no cable signal of its own (size/
# core/voltage/AWG) and looks like a short alnum tag; it is only folded into
# the following line when THAT line does carry cable signal, so ordinary
# section headings or prose never get mistaken for a label-to-be-consumed.
CABLE_SIGNAL_RE = re.compile(
    r"\d+\s*mm²|\d+\s*mm2?\b|\d+\s*sq\.?\s*mm"
    r"|\b\d+\s*c(?:\s*\+\s*e)?\b"
    r"|\b\d+\s*(?:core|cores|pair|pairs|pr|triad|triads|quad|quads)\b"
    r"|\b\d+(?:\.\d+)?\s*/?\s*\d*(?:\.\d+)?\s*k?v\b"
    r"|\bawg\s*\d+",
    re.I,
)
CIRCUIT_LABEL_LINE_RE = re.compile(r"^(?=.*[A-Z])(?=.*\d)(?=.*[-_/])[A-Z0-9][A-Z0-9._/-]{2,}$", re.I)

# --- Marine / offshore ----------------------------------------------------------
# Any signal that the line belongs to the marine range (data/catalog/marine_offshore.xlsx).
# The range is opt-in in the resolver, so these tokens are the ONLY way in -- which makes
# a false positive worse than a miss. Deliberately excluded as too ambiguous: "ABS" (also
# a plastic), bare "LR" and bare "RIG", and bare "P1"/"P5"/"P8" (NEK cable types, but also
# ordinary config codes).
MARINE_RE = re.compile(
    r"\bMARINE\b|\bSHIP\s*BOARD\b|\bSHIPBOARD\b|\bVESSEL\b|\bSHIPYARD\b|\bNAVAL\b|\bSOLAS\b"
    r"|\bIEC\s*60092\b|\b60092\b|\bDNV(?:[\s-]*GL)?\b|\bLLOYDS\b|\bLLOYD'S\b|\bRINA\b|\bCLASS\s*NK\b"
    r"|\bOFFSHORE\b|\bFPSO\b|\bTOPSIDE\b|\bNEK|\bTS\s*606\b|\bSHF\s*MUD\b",
    re.I,
)
# NEK TS606 (Norwegian offshore) -- the N-series. Its cable types are written "P1/P8",
# "P5/P12", "RU P18", "BU P17"; those compound forms are safe, bare "P1" is not.
NEK_RE = re.compile(
    r"\bNEK|\bTS\s*606\b|\bP1\s*/\s*P8\b|\bP5\s*/\s*P12\b|\bRU\s*P18\b|\bBU\s*P17\b|\bP1[278]\b",
    re.I,
)
# Offshore (braid armour over a bedding) as opposed to shipboard.
MARINE_OFFSHORE_RE = re.compile(r"\bOFFSHORE\b|\bFPSO\b|\bTOPSIDE\b|\bMUD\b|\bDRILL", re.I)

# Marine jacket (zone-1 sheath) codes, per TriCab's marine jacket table. Ordered most
# specific first -- "NEK606 SHF-2" must beat the bare "SHF-2" rule, and "SHF MUD" must
# beat "SHF 1"/"SHF 2". PVC (F) requires an explicit sheath/jacket word: a bare "PVC" in
# a cable description is nearly always the insulation compound ("XLPE/PVC"), not the
# jacket, and would otherwise hard-filter the line onto an uncatalogued F variant.
MARINE_JACKET_PATTERNS = [
    ("C", re.compile(r"\bNEK\s*606\s*SHF[\s-]*2\b", re.I)),
    ("M", re.compile(r"\bNEK\s*606\s*MUD\b|\bSHF\s*MUD\b|\bMUD\s*(?:RESISTANT|JACKET|SHEATH)\b", re.I)),
    ("B", re.compile(r"\bSHF[\s-]*2\b", re.I)),
    ("A", re.compile(r"\bSHF[\s-]*1\b", re.I)),
    ("D", re.compile(r"\bSW\s*4\b", re.I)),
    ("E", re.compile(r"\bTPE\b", re.I)),
    ("P", re.compile(r"\bLOW[\s-]*FRICTION\b", re.I)),
    ("T", re.compile(r"\bANTI[\s-]*TERMITE\b|\bANTI[\s-]*RODENT\b|\bTERMITE\b|\bRODENT\b", re.I)),
    ("F", re.compile(r"\bPVC\s*(?:SHEATH|SHEATHED|JACKET|JACKETED|OUTER)\b|\b(?:SHEATH|JACKET)\s*:?\s*PVC\b", re.I)),
]


def find_exact_product_code(text: str, known_codes: set[str]) -> tuple[str, float | None] | None:
    """Return ``(code, embedded_length_m)`` if ``text`` contains a valid product
    product code, optionally with a spool/reel length appended directly onto the
    end (see PRODUCT_CODE_WITH_LENGTH_RE). CSV-known codes and grammar-valid
    generated codes both pass. Returns ``None`` if no valid code is found.
    Shared by :func:`extract_entities` (the deterministic exact-code
    shortcut) and the pipeline's pre-filter that keeps exact-code lines away from
    the AI normalizer entirely -- re-deriving fields from an already-explicit code
    is a correctness risk, not a helpful translation.
    """
    upper = text.upper()
    for code in PRODUCT_CODE_RE.findall(upper):
        if code in known_codes or parse_code(code).get("valid"):
            return code, None
    for match in PRODUCT_CODE_WITH_LENGTH_RE.finditer(upper):
        base_code, length_suffix = match.group(1), match.group(2)
        if base_code in known_codes or parse_code(base_code).get("valid"):
            return base_code, float(length_suffix)
    return None


def flattened_schedule_fields(upper: str) -> dict[str, Any]:
    """Recover construction from OCR-flattened schedule rows.

    Vision OCR sometimes emits one row like
    ``2 x 300mm2 Cu, X-90 XLPE, 3 x 1 core Trefoil 40m`` after losing the
    original Active/Configuration column labels. In that shape, ``Trefoil`` and
    ``Flat`` are formation words from the schedule, not catalog style families.
    """
    leading = SCHEDULE_LEADING_SPEC_RE.search(upper)
    if not leading:
        return {}

    fields: dict[str, Any] = {
        "cross_section": canonical_number(leading.group("size")),
        "conductor": "A" if leading.group("material").upper().startswith("AL") else "N",
    }
    active_runs = leading.group("active_runs")
    active_parallel_runs = int(active_runs or 1)
    fields["active_parallel_runs"] = active_parallel_runs

    trefoil = SCHEDULE_TREFOIL_CONFIG_RE.search(upper)
    if trefoil:
        phase_cores = int(trefoil.group("runs"))
        neutral = SCHEDULE_NEUTRAL_SPEC_RE.search(upper)
        neutral_runs = int((neutral.group("neutral_runs") if neutral else None) or active_parallel_runs)
        total_cuts = (phase_cores * active_parallel_runs) + neutral_runs
        fields.update(
            {
                "schedule_formation": "trefoil",
                "trefoil": True,
                "raw_core_expression": trefoil.group(0),
                "count": 1,
                "core_type": "C",
                "phase_cores": phase_cores,
                "neutral_parallel_runs": neutral_runs,
                "neutral_inferred": not bool(neutral),
                "number_of_lengths": total_cuts,
                # A schedule row's Length is a route: switchboard to DB. Each run is a
                # continuous cut, never a bulk figure to be made up from spools.
                "cuts_explicit": True,
            }
        )
        return fields

    multicore = SCHEDULE_MULTICORE_CONFIG_RE.search(upper)
    if multicore:
        fields.update(
            {
                "schedule_formation": multicore.group("formation").lower(),
                "raw_core_expression": multicore.group(0),
                "count": int(multicore.group("cores")),
                "core_type": "C",
            }
        )
        return fields

    return {}


# A style keyword glued to a preceding tag segment -- "DB-SOLAR", "MSSB-CONTROL",
# "DB-FIRE-2" -- is part of a circuit or switchboard label, not a description of
# the cable. A destination name must never steer the style ladder: MacKillop's
# "DB-JNL-GF.1 > DB-SOLAR XLPE Cu 25mm2" resolved to the solar style SY-P1XN
# while its twelve sibling rows went to XL-PAXN, and the aluminium twin returned
# SK-PAXA as `matched`, so nothing flagged it (docs/EMAIL_CORPUS_AUDIT.md 4.4).
#
# Only a LEADING glue is excluded. Trailing hyphens are ordinary spec grammar
# ("FIRE-RATED", "X-HF-110"), so guarding those too would break real lines.
#
# "/" is deliberately NOT a tag joiner: it separates construction layers, where
# the keyword is a real signal. Measured over the audit corpus -- every one of
# the 3 "-" occurrences is an equipment tag (DB-SOLAR, DB-SOLAR, DB-PV), and
# every one of the 16 "/" occurrences is a genuine spec ("PVC/SWA/PVC/ESCS/FR",
# "VSD/EMC 120mm2"). Including "/" here suppressed the fire flag on ten real
# armoured-instrument lines and flipped them to a different style as `matched`.
#
# Fixed width, as Python requires of a lookbehind.
NOT_TAG_SEGMENT = r"(?<![A-Z0-9][-._])"


def spec_search(pattern: str, text: str):
    """Search for a style keyword, ignoring occurrences inside equipment tags."""
    return re.search(NOT_TAG_SEGMENT + r"(?:" + pattern + r")", text)


def extract_entities(raw_text: str, known_codes: set[str]) -> dict[str, Any]:
    text = normalize_text(raw_text)
    upper = text.upper()
    fields: dict[str, Any] = {}
    warnings: list[str] = []
    schedule_fields = flattened_schedule_fields(upper)

    exact_match = find_exact_product_code(upper, known_codes)
    if exact_match:
        fields["exact_product_code"], embedded_length = exact_match
        if embedded_length is not None:
            fields["exact_product_code_length_m"] = embedded_length
    accessory_code = find_accessory_code(upper)
    if accessory_code:
        fields["exact_accessory_code"] = accessory_code
        fields["accessory_hint"] = True

    # Customers/resellers often echo TriCab's own style naming verbatim, e.g.
    # "KL Series X-HF-110/PVC 110 C" or "XU Series XLPE/SCN/PVC". Capture the
    # candidate code here; the resolver validates it against the real catalog
    # style codes (this module has no catalog access) before trusting it.
    series_match = re.search(r"\b([A-Z][A-Z0-9])\s*SERIES\b", upper)
    if series_match:
        fields["explicit_style_hint"] = series_match.group(1)

    if COMMS_RE.search(text):
        fields["comms_hint"] = True
    if ACCESSORY_RE.search(text):
        fields["accessory_hint"] = True
    elif CUT_SERVICE_RE.search(text) and not _CABLE_EVIDENCE_RE.search(text):
        # A bare "cutting charge" / "cuts" line with no cable construction on it.
        fields["accessory_hint"] = True
    elif PACK_QUANTITY_RE.search(text) and not _CABLE_EVIDENCE_RE.search(text):
        # Bagged, with nothing that says cable: an accessory whose noun this
        # vocabulary does not know yet ("1 bag x female bullet", "4 bags x 4.6 x
        # 300mm" for ties). Routing it to the accessory panel puts it in front of
        # a human; leaving it unhinted quoted it as cable, which the ties did --
        # "10 bags x 7.6 x 300mm" came back XL-PAXN/1C300OR, `matched`.
        fields["accessory_hint"] = True
    if ADMIN_RE.search(text):
        fields["admin_hint"] = True

    if re.search(r"\b(CU|COPPER)\b", upper):
        fields["conductor"] = "N"
    if re.search(r"\b(TC|TACW|TINNED)\b", upper):
        fields["conductor"] = "D"
    if re.search(r"\b(AL|ALUMINIUM|ALUMINUM)\b", upper):
        fields["conductor"] = "A"
    # "Au" for aluminium, which is a customer's abbreviation and not the chemical symbol
    # for gold -- there is no gold cable in the catalog. Deliberately anchored to a size
    # rather than matched as a bare word: `\bAU\b` alone would read the country code in a
    # delivery address ("YATALA QLD AU") as a conductor material. Only "400mmAu" /
    # "400mm2 Au" -- a number, a unit, then the token -- qualifies.
    elif SIZE_ATTACHED_AU_RE.search(upper):
        fields["conductor"] = "A"

    if re.search(r"\bEARTH\b|\bECC\b|\bG/Y\b|\bGN\s*/\s*YE\b|\bGREEN\s*/?\s*YELLOW\b", upper):
        fields["earth"] = True
    if re.search(r"\bSWITCH\s*BOARD\b|\bSWITCHBOARD\b|\bPANEL\s+WIRE\b", upper):
        fields["switchboard"] = True
    # "Single insulated" = an unsheathed conductor, which in this catalog is exactly three
    # styles (LP/LS/UP -- the only ones with an empty Sheathing column). It is the OPPOSITE
    # of "SDI" (Single DOUBLE Insulated, a sheathed single core), so the DOUBLE spellings
    # are excluded rather than matched: "single/double insulated" must not land here. The
    # phrase used to be inert prose, which let a "SINGLE INSULATED FLEXIBLE HF-110" heading
    # resolve on its HF-110 alone -- straight onto the sheathed KL rung of the temperature
    # ladder. See rank_styles for how the three are told apart.
    orange_circular = bool(spec_search(r"\bORANGE\s+CIRC(?:ULAR)?\b|\bO/?C\b", upper))
    if orange_circular:
        fields["circular"] = True
    if SINGLE_INSULATED_RE.search(upper):
        fields["single_insulated"] = True
    if re.search(
        r"\bSCREEN(?:ED)?\b|\bSCR\b|\bSCN\b|\bVSD\b|\bVFD\b"
        r"|\bO/?S\b|\bOAS\b|\bI/S\b|\bIOS\b|\bIOC\b|\bIOAS\b|\bI\s*&\s*O\b"
        r"|\bOVERALL\s+SCREEN\b|\bINDIVIDUAL\s+SCREEN\b|\bCOLLECTIVE\s+SCREEN\b",
        upper,
    ):
        # OS = overall (collective) screen; IS = individual; IOS/IOC/IOAS = both.
        # OAS = overall (collective) armoured screen -- IOAS without the individual screen.
        # EMC selects the drive-style ladder below, but does not independently
        # specify screen construction.
        fields["screen"] = True
        # Collective vs individual+collective drives the instrumentation R-series
        # (Coll = R1/RE..., Ind+Coll = R3/RH...). "Individual" implies individual+overall
        # in that range -- there is no individual-only style -- so it maps to ind+coll.
        if re.search(r"\bI/S\b|\bINDIVIDUAL\b|\bIOS\b|\bIOC\b|\bIOAS\b|\bI\s*&\s*O\b", upper):
            fields["screen_type"] = "ind+coll"
        elif re.search(r"\bO/?S\b|\bOAS\b|\bOVERALL\b|\bCOLLECTIVE\b|\bCOLL\b", upper):
            fields["screen_type"] = "coll"
        # IOAS/OAS additionally denote an armoured screen.
        if re.search(r"\bIOAS\b|\bOAS\b", upper):
            fields["armoured"] = True
    if re.search(r"\bSWA\b|\bSTEEL\s+WIRE\s+ARMO(?:U)?R\b", upper):
        fields["armoured"] = True
        fields["armour_type"] = "SWA"
    elif re.search(r"\bAWA\b|\bALUMINI?UM\s+WIRE\s+ARMO(?:U)?R\b", upper):
        fields["armoured"] = True
        fields["armour_type"] = "AWA"
    elif re.search(r"\bARMO(?:U)?RED\b", upper):
        fields["armoured"] = True
    if re.search(r"\bFLAT\b", upper) and not schedule_fields.get("schedule_formation"):
        fields["style_hint"] = "FD"
    elif re.search(r"\bCIRCULAR\b|\bCIRC\b", upper):
        # "Circ"/"circular" is the opposite of flat; many cables differ from a flat
        # variant only by this word. Record it so the resolver excludes the flat style.
        # (\bCIRC\b won't match "circuit" -- no word boundary after CIRC there.)
        fields["circular"] = True
    # Fire is a cross-cutting property (like armour/screen), not only a family: a fire
    # instrument cable is still instrumentation but must resolve to the fire style (WH).
    # Keep a standalone flag so the pair/triad override below can reclassify the family
    # without losing the fire signal. (X-125 is excluded -- it is also the LSZH instrument
    # insulation code, so it must not by itself imply fire.)
    # "FR" is fire-rated (confirmed 2026-06-30). The family routes the fire pick: VSD/VFD
    # -> FH, instrumentation -> WH, everything else -> the FR style (resolver logic).
    # "RADOX" is a brand name customers use for the fire-rated range (confirmed by
    # Manusha 2026-08-03): treat it as a fire signal, so a Radox SDI single resolves to
    # FR (red) and a Radox line's split earth is promoted from LP to LS.
    if spec_search(r"\bFIRE\b|\bWS52W\b|\bFR\b|\bRADOX\b", upper):
        fields["fire"] = True
    # EMC routes to the VSD/VFD family too: every VSD/VFD cable is an EMC cable
    # (confirmed 2026-07-02), and the EMC-titled style (DP) shares the VSD/VFD
    # cable_type. So "EMC ... drive/power cable" with no explicit VSD word still
    # lands in the screened-drive family (XU/KU/EU/EA/DP), not plain power.
    if spec_search(r"\bVSD\b|\bVFD\b|\bEMC\b|\bVARIABLE SPEED\b|\bVARIABLE FREQUENCY\b", upper):
        fields["cable_type_hint"] = "VSD/VFD"
    elif spec_search(r"\bFIRE\b|\bWS52W\b|\bX-?125\b|\bFR\b|\bRADOX\b", upper) and not spec_search(
        r"\bSIGNAL\b|\bALARM\b|\bEWIS\b", upper
    ):
        # FR style is for non-VSD/non-instrument fire applications; VSD keeps its hint
        # above (and resolves to FH), pairs/triads keep theirs (and resolve to WH). A fire
        # signal/alarm (e.g. "fire alarm cable") is excluded here so it falls to the Signal
        # branch -> WG, the fire-rated signal cable (the fire flag is still set above).
        fields["cable_type_hint"] = "Fire Resistant"
    elif spec_search(r"\bCONTROL\b", upper):
        fields["cable_type_hint"] = "Control"
    elif spec_search(r"\bINSTRUMENT", upper):
        fields["cable_type_hint"] = "Instrumentation"
    elif spec_search(r"\bSIGNAL\b|\bALARM\b|\bEWIS\b", upper):
        # Signal/alarm (plain core) -> WG, the fire-rated signal cable (confirmed TriCab
        # 2026-06-30). When pairs/triads are present the override below reclassifies these
        # to Instrumentation instead.
        fields["cable_type_hint"] = "Signal"
    elif spec_search(r"\bSOLAR\b|\bPV\b", upper):
        fields["cable_type_hint"] = "Solar"
    elif spec_search(r"\bWELD", upper):
        fields["cable_type_hint"] = "Welding"
    elif spec_search(r"\bMV\b|\bMEDIUM\s+VOLTAGE\b", upper):
        fields["cable_type_hint"] = "MV Power"
    elif spec_search(r"\bLV\s+POWER\b|\bPOWER\s+CABLES?\b", upper):
        fields["cable_type_hint"] = "Power"
    elif spec_search(r"\bBUILDING WIRE\b|\bSDI\b|\bTPS\b|\bSWITCH\s*BOARD\b|\bSWITCHBOARD\b|\bPANEL\s+WIRE\b", upper):
        fields["cable_type_hint"] = "Building Wire" if ("EARTH" in upper or fields.get("switchboard")) else "Power"
    elif spec_search(r"\bMULTI[\s-]?CORE\s+FLEX", upper):
        # A flexible multicore power cable (e.g. "Multicore flexible 0.6/1kV"). Without
        # this, no type signal is set and the ranker cannot separate XL (power) from XN
        # (nylon termite-resistant control); reached only after CONTROL etc. are ruled out.
        fields["cable_type_hint"] = "Power"

    if orange_circular and not fields.get("cable_type_hint"):
        fields["cable_type_hint"] = "Power"

    # X-HF-110 is a 110C insulation signal for the standard PVC temperature
    # ladder (KL), not a sheath statement. Only explicit LSZH/HFFR wording, or
    # the rubber R-HF/HF-R forms used in construction schedules, should set the
    # LSZH gate.
    # LS0Z and LSHF are the spellings used in the style sheet's own Bedding column
    # ("HFS-110-TP or LSZH or HFFR or LS0Z or LSHF or Low Smoke Halogen Free"); only
    # LS0H was listed here, so those two missed.
    if re.search(
        r"\bLSZH\b|\bHFFR\b|\bLOW SMOKE\b|\bHALOGEN FREE\b|\bLS0H\b|\bLS0Z\b|\bLSHF\b"
        r"|\bR-?HF-?110\b|\bHF-?110-?R\b",
        upper,
    ):
        fields["lszh"] = True
    if re.search(r"\bPVC\b", upper):
        fields["pvc"] = True
    # Braid is now an explicit-only trigger (EMC no longer implies it): it selects the
    # braided instrumentation styles KE/KH at 450/750 V.
    if re.search(r"\bBRAID(?:ED|ING)?\b", upper):
        fields["braid"] = True
    # NB: armour does NOT force a style here. The armoured construction is chosen by the
    # temperature ladder in the resolver house default (no temp/90 -> XA, 110 -> KA,
    # >110/LSZH -> CA), mirroring the unarmoured XL/KL/CC rule. A blanket PVC+armour->KA
    # used to live here, which wrongly forced the 110C KA onto plain 90C armour requests.
    # Termite/nylon/rodent resistance requested. This selects the nylon family in the
    # resolver: the standard extruded-nylon styles XN/KN/CN (nylon is part of the style
    # itself -- no code suffix) by the temperature ladder (no temp/90 -> XN, 110 -> KN,
    # >110/LSZH -> CN). The separate "T" jacket SUFFIX (XDT/KDT/CDT) belongs to the SDI
    # building-wire family XD/KD/CD and is applied via `sheath` (see the CD rule below),
    # not this flag.
    # "NJ" is the nylon-jacket abbreviation used in SDI descriptions, e.g.
    # "500mm2 1C Cu Flexible SDI PVC/XLPE-110/NJ" (reported by Neil O'Loughlin
    # 2026-08-14: that line must be KN, the 110C nylon series, and was resolving to XL).
    # It is gated on the line carrying a cable signal so a two-letter token cannot fire
    # on an address or a customer reference.
    if re.search(r"\bTERMITE\b|\bRODENT\b|\bNYLON\b", upper) or (
        re.search(r"\bNJ\b", upper) and CABLE_SIGNAL_RE.search(upper)
    ):
        fields["termite"] = True
    if (
        re.search(r"\b(?:TERMITE|NYLON)\b", upper)
        and re.search(r"\b(?:LSZH|HFFR|LOW SMOKE|HALOGEN FREE|LS0H|HF-?110)\b", upper)
        and re.search(r"\b(?:FLEX(?:IBLE)?|CLASS\s*5)\b", upper)
    ):
        fields["style_hint"] = "CD"
        fields["sheath"] = "T"

    # --- Marine / offshore ------------------------------------------------------
    # The marine range (IEC 60092 shipboard, offshore braid-armour, NEK TS606) is
    # opt-in: the resolver skips every marine style unless this flag is set, so an
    # ordinary industrial RFQ can never be pulled into it. Tokens are deliberately
    # unambiguous -- "ABS" (also a plastic), bare "LR" and bare "RIG" are excluded
    # because they fire on ordinary lines.
    if MARINE_RE.search(upper):
        fields["marine"] = True
        # NEK first: a NEK TS606 line is offshore too, but its N-series is distinct.
        if NEK_RE.search(upper):
            fields["marine_family"] = "nek"
        elif MARINE_OFFSHORE_RE.search(upper):
            fields["marine_family"] = "offshore"
        else:
            # Shipboard is the base case (matches rfq_api.php's calculator, where
            # shipboard is the default marine family and offshore is the opt-in).
            fields["marine_family"] = "shipboard"
        # Jacket (zone-1 sheath) code, per TriCab's marine jacket table. Confirmed
        # against the catalog: A dominates the shipboard/offshore range, while the
        # NEK N-series stocks only C and M -- so an unstated jacket is left to the
        # style's own default_sheath_code (A for shipboard/offshore, C for NEK)
        # rather than being forced to A here.
        for code, pattern in MARINE_JACKET_PATTERNS:
            if pattern.search(upper):
                fields["sheath"] = code
                break
        # Termite/rodent resistance on a marine cable is the "T" jacket, NOT the
        # mainstream nylon family (XN/KN/CN): those styles are not marine-approved.
        # Drop the flag so the nylon ladder in the resolver stays out of it.
        if fields.get("termite"):
            fields.pop("termite", None)
            if fields.get("style_hint") == "CD":
                fields.pop("style_hint", None)

    voltages, voltage_is_pair = extract_voltage_reading(upper)
    if voltages:
        fields["voltage_set"] = sorted(voltages)  # JSON-safe; used for the gate
        fields["voltage_u"] = max(voltages)  # display: the phase-to-phase rating
        # Written as U0/U rather than a lone number -- see extract_voltage_reading.
        fields["voltage_is_pair"] = voltage_is_pair

    earth_in_count = False  # True once the core count already accounts for the earth
    core_match = CORE_PLUS_E_RE.search(upper)
    if core_match:
        # TriCab counts the earth as a core in the product code: "4C + E" is a 5C cable
        # (resolves to a 5C style). The earth attached to the core spec is part of one
        # cable; an earth quoted as its own item ("1 4C cable + 1 E") is split upstream.
        base = int(core_match.group(1))
        extra = int(core_match.group(2) or 1)
        fields["raw_core_expression"] = core_match.group(0)
        # Split earths count as a SINGLE earth-core in the product code (confirmed with
        # TriCab 2026-06-30): "3C+3E" -> 4C, "4C+E" -> 5C. The earth always adds exactly
        # one core regardless of how many split earths are quoted.
        fields["count"] = base + 1
        fields["earth"] = True
        # Earth multiplicity drives DP (single earth) vs XU (multiple/split earths) in the
        # screened/VSD family, so the raw count is preserved here even though the core count
        # only gains one.
        fields["earth_count"] = extra
        fields["core_type"] = "C"
        earth_in_count = True
    else:
        g_match = G_CORE_RE.search(upper)
        if g_match:
            # "4G70" (IEC) already counts the green/yellow earth within the 4 cores.
            fields["raw_core_expression"] = g_match.group(0)
            fields["count"] = int(g_match.group(1))
            fields["cross_section"] = canonical_number(g_match.group(2))
            fields["earth"] = True
            fields["core_type"] = "C"
            earth_in_count = True
        else:
            match = CORE_RE.search(upper)
            if match:
                fields["raw_core_expression"] = match.group(0)
                fields["count"] = int(match.group(1))
                fields["core_type"] = "C"

    if TREFOIL_RE.search(upper) and not schedule_fields:
        # "Trefoil" (single-core conductors laid in triangular formation) always means
        # single-core construction -- it never describes a multicore cable. But
        # normalize_text's "N core" -> "NC" tightening (needed elsewhere for genuine
        # multicore counts, e.g. "4 core Flat" -> 4C) makes the trailing "1 core" in
        # "3 x 1 core Trefoil" look exactly like a real core-count token to CORE_RE just
        # above, which set count/core_type/raw_core_expression from the WRONG number --
        # the true trefoil-bundle multiplicity (and any leading parallel-run count, e.g.
        # "2 x 300mm2") is silently discarded, and the row confidently resolves as an
        # ordinary single-core cable with no warning at all. Clear what CORE_RE set and
        # flag it instead: never let a trefoil row resolve on the regex path alone.
        fields.pop("count", None)
        fields.pop("core_type", None)
        fields.pop("raw_core_expression", None)
        fields["trefoil"] = True
        warnings.append(
            "Trefoil (single-core) construction detected; needs AI to determine the "
            "correct run count and split into separate line items."
        )

    for regex, core_type in ((PAIR_RE, "P"), (TRIAD_RE, "T"), (QUAD_RE, "Q")):
        match = regex.search(upper)
        if match:
            fields["count"] = int(match.group(1))
            fields["core_type"] = core_type
            # Pairs/triads/quads are an instrumentation construct. Default the type
            # there when nothing more specific was stated, and also override a
            # "Control" hint: a screened pair labelled "control cable" is really an
            # instrument cable (control cables use numbered C-cores, not pairs).
            if not fields.get("comms_hint") and fields.get("cable_type_hint") in (None, "Control", "Fire Resistant", "Signal"):
                # Pairs/triads/quads are always instrumentation (incl. a fire instrument
                # cable). The fire signal is preserved in fields["fire"] so the resolver
                # can still route to the fire instrument style (WH).
                fields["cable_type_hint"] = "Instrumentation"
            break

    # Promote a non-adjacent added earth ("4C 70 + EARTH") to an extra core. Skipped
    # when the count already includes the earth ("4C+E", "4G70") or for pair/triad/quad
    # instrument cables (where an earth is a drain/screen, not a counted core).
    if not earth_in_count and fields.get("core_type") == "C" and fields.get("count"):
        added_earth = ADDED_EARTH_RE.search(upper)
        if added_earth:
            n = int(added_earth.group(1) or 1)
            # Split earths add a single earth-core (see the adjacent-earth branch above).
            fields["count"] += 1
            fields["earth"] = True
            fields["earth_count"] = n
            fields["earth_added_core"] = True

    # Glued "Nx1C"-style feeder notation the core regexes above can't see (the
    # glued "x1C"/"x10" has no word boundary before the core count). Two families,
    # split by whether a "+earth"/extra core is attached:
    #   * bundled MULTICORE  -> one cable of (cores + 1)C; a leading "Mx" counts
    #     identical cables. "4x1c+e" -> 5C, "3x4x1c+e" -> 3 x 5C, "4x10+1x4mm2"
    #     -> 5C @ the phase size.
    #   * parallel SINGLE-CORE runs (no attached earth) -> one 1C product repeated
    #     N times. "4x1C" -> 4 runs, "3x4x1C" -> 12 runs.
    # The "Nx SIZEmm + [Mx] SIZE earth" split shape (PARALLEL_SINGLE_CORE_RE) is
    # two products and is handled downstream, so it is checked first and excluded
    # here. Skipped entirely for schedule/trefoil rows and when a core count was
    # already resolved above.
    parallel_runs = None   # bare Nx1C -> this many single-core runs
    cable_count = None     # leading Mx on a multicore -> this many identical cables

    # Leading cable multiplier on a MULTICORE "Nc"/"Nc+e" row (see
    # MULTICORE_CABLE_MULT_RE). Runs even when the core count was already resolved
    # above (spaced "3 x 4c+e"), and recovers the count for the glued "3x4c+e" the
    # boundary-anchored core regexes can't see. Excludes 1-core tokens -- those are
    # single-core forms handled by the block below.
    mcm = (
        MULTICORE_CABLE_MULT_RE.match(upper)
        if not schedule_fields and not fields.get("trefoil")
        else None
    )
    if mcm and int(mcm.group("cores")) >= 2:
        cores = int(mcm.group("cores"))
        has_earth = bool(mcm.group("earth"))
        if not fields.get("count"):
            fields["raw_core_expression"] = mcm.group(0).strip()
            fields["count"] = cores + 1 if has_earth else cores  # earth is a counted core
            fields["core_type"] = "C"
            if has_earth:
                fields["earth"] = True
                fields["earth_count"] = int(mcm.group("earthnum") or 1)
        cable_count = int(mcm.group("cables"))

    if (
        not schedule_fields
        and not fields.get("trefoil")
        and not fields.get("count")
        and not PARALLEL_SINGLE_CORE_RE.search(upper)
    ):
        pe_match = SINGLE_CORE_PLUS_EARTH_RE.search(upper)
        mc_match = MULTICORE_PLUS_CORE_RE.search(upper) if not pe_match else None
        if pe_match:
            # "Nx1C+E" / "AxBx1C+E": per Sales, "1c" is ALWAYS single-core and the
            # trailing "+E" is a SEPARATE earth cable, not a 5th core -- so this is a
            # parallel single-core feeder that must become TWO line items (the N
            # single-core actives, plus one earth conductor per bundle), never one
            # multicore. Flag it for the AI split exactly like PARALLEL_SINGLE_CORE_RE
            # and leave count unresolved so the resolver reports no confident single
            # product. A multicore stays reserved for explicit "Nc" notation (2C/3C…).
            circuits = int(pe_match.group("cables") or 1)
            cores = int(pe_match.group("cores"))
            fields["raw_core_expression"] = pe_match.group(0)
            fields["parallel_single_core"] = True
            fields["earth"] = True
            fields["active_run_count"] = circuits * cores
            fields["earth_run_count"] = circuits * int(pe_match.group("earth") or 1)
        elif mc_match:
            fields["raw_core_expression"] = mc_match.group(0)
            # Phase cores + a single reduced earth/neutral core (split earths still
            # add exactly one core, per the 4C+E rule); the reduced size is dropped
            # in favour of the phase cross-section for the product code.
            fields["count"] = int(mc_match.group("cores")) + 1
            fields["earth"] = True
            fields["earth_count"] = int(mc_match.group("extra") or 1)
            fields["core_type"] = "C"
            fields["cross_section"] = canonical_number(mc_match.group("size"))
            fields["reduced_earth_size"] = canonical_number(mc_match.group("size2"))
            if mc_match.group("cables"):
                cable_count = int(mc_match.group("cables"))
        else:
            sc_match = SINGLE_CORE_RUNS_RE.search(upper)
            if sc_match:
                circuits = int(sc_match.group("circuits") or 1)
                cores = int(sc_match.group("cores"))
                parallel_runs = circuits * cores
                fields.setdefault("raw_core_expression", sc_match.group(0))
                fields["count"] = 1
                fields["core_type"] = "C"
            else:
                # A bare leading run count followed by a conductor size is a set of
                # parallel 1C conductors even when the customer uses "*" rather than
                # "x": "24* 400mm ... @ 110M". The product is still one core; the
                # multiplier belongs to the requested cuts/total length. This also
                # handles the earth half after a compound mains/earth sentence splits.
                lead = LEADING_RUN_MULT_RE.match(upper)
                if lead:
                    parallel_runs = int(lead.group(1))
                    # The asterisk form is unambiguously the supplier's bare
                    # single-core run notation.  Keep the older spaced "N x
                    # <length>" path unresolved here so the resolver retains its
                    # established "defaulted to 1C" confirmation warning.
                    if "*" in lead.group(0):
                        fields["count"] = 1
                        fields["core_type"] = "C"

    # A leading "Nx" on a SINGLE-CORE line is N parallel runs even when the core count
    # was already resolved above. The block overhead is gated on `not fields.get("count")`,
    # so both "4x 1Cx 120mm" and the spaced "4 x 1C 120mm" -- whose "1C" the core regex
    # reads straight away -- used to skip it and quote one run instead of four, silently
    # under-quoting the line by the run count. Anchored at the start of the row so only a
    # genuine leading multiplier counts, and left to MULTICORE_CABLE_MULT_RE above for
    # anything with two or more cores.
    if (
        parallel_runs is None
        and cable_count is None
        and not schedule_fields
        and not fields.get("trefoil")
        and fields.get("count") == 1
        and not fields.get("parallel_single_core")
        and not PARALLEL_SINGLE_CORE_RE.search(upper)
    ):
        lead_sc = SINGLE_CORE_RUNS_RE.match(upper)
        if lead_sc:
            parallel_runs = int(lead_sc.group("circuits") or 1) * int(lead_sc.group("cores"))

    if "cross_section" not in fields:
        sizes = [canonical_number(value) for value in SIZE_RE.findall(upper)]
        if not sizes:
            sizes = [canonical_number(value) for value in BARE_SIZE_RE.findall(upper)]
        if sizes:
            # Prefer a size adjacent to cable descriptors, not accessory bolt diameters later in the row.
            fields["cross_section"] = sizes[0]
            if len(set(sizes)) > 1:
                fields["alternate_sizes"] = sizes[1:]

    fields.update(extract_quantity(text))
    if fields.get("accessory_hint"):
        quantity = accessory_quantity_from_text(text, fields.get("exact_accessory_code"))
        for key in ("length_each_m", "total_length_m", "uom"):
            fields.pop(key, None)
        if quantity is not None:
            fields["quantity"] = quantity
            fields["number_of_lengths"] = quantity
    if schedule_fields:
        fields.update(schedule_fields)
        if fields.get("length_each_m") is not None and fields.get("number_of_lengths"):
            total = float(fields["length_each_m"]) * float(fields["number_of_lengths"])
            fields["total_length_m"] = int(total) if total.is_integer() else total

    # Fold the leading multiplier into the run/length count: N identical items of
    # the stated per-item length total N x length. For a bare-1C feeder that item
    # is a single-core run; for a bundled multicore it is a whole cable. Overrides
    # the single-cut default extract_quantity set, and flags the inference so the
    # rep can confirm the count (the multiplier is easy to omit or misread across
    # the "Nx Mx" chain).
    run_multiplier = parallel_runs or cable_count
    if run_multiplier and run_multiplier > 1:
        fields["number_of_lengths"] = run_multiplier
        each = fields.get("length_each_m")
        if each not in (None, ""):
            total = float(each) * run_multiplier
            fields["total_length_m"] = int(total) if float(total).is_integer() else total
        if parallel_runs:
            fields["parallel_run_count"] = parallel_runs
            warnings.append(
                f"Parallel single-core feeder: read the leading multiplier as {parallel_runs} "
                "parallel runs of the same conductor; confirm the run count and total length."
            )
        else:
            warnings.append(
                f"Read the leading multiplier as {cable_count} identical "
                f"{fields.get('count')}C cables; confirm the cable count and total length."
            )

    colour = extract_colour(upper) or ("OR" if orange_circular else "")
    if colour:
        fields["colour"] = colour

    if not fields.get("earth") and EARTH_SUFFIX_RE.search(upper):
        fields["earth"] = True
    # A bare trailing "E" is an earth only on a single-conductor line -- see
    # BARE_EARTH_SIZE_RE for why a stated multicore is left to the explicit "+E".
    if (
        not fields.get("earth")
        and not fields.get("exact_product_code")
        and fields.get("count") in (None, 1)
        and BARE_EARTH_SIZE_RE.search(upper)
    ):
        fields["earth"] = True

    if fields.get("parallel_single_core") or PARALLEL_SINGLE_CORE_RE.search(upper):
        fields["parallel_single_core"] = True
        warnings.append(
            "Parallel single-core notation (separate phase + earth conductors) detected; "
            "needs AI to split into separate line items."
        )
        # Deliberately skip the earth-only collapse below and leave count/style
        # unresolved -- a single fields dict can't represent two products, and this
        # row must NOT report a confident match that hides the phase runs.
    elif fields.get("earth") and fields.get("count") == 1:
        fields["earth_only"] = True
    elif fields.get("earth") and not fields.get("count") and fields.get("cross_section"):
        fields["count"] = 1
        fields["core_type"] = fields.get("core_type") or "C"
        fields["earth_only"] = True
    if not fields.get("count") and not fields.get("exact_product_code") and not fields.get("accessory_hint"):
        warnings.append("Core count was not found.")
    if not fields.get("cross_section") and not fields.get("exact_product_code") and not fields.get("accessory_hint"):
        warnings.append("Conductor cross-section was not found.")

    # extract_entities only ever reads the row's own literal text, so everything it set is
    # a customer statement as far as this layer can tell. What this does NOT yet separate
    # is a *derived* regex field (a conductor inferred from a style word, say) -- that
    # reclassification is case C4, §2 of CABLE_SELECTION_FIX_ORDER, and it carries its own
    # corpus delta. Marking these `customer` reproduces today's behaviour for the regex
    # path exactly, which is what keeps this step's diff attributable.
    provenance = {key: FIELD_SOURCE_CUSTOMER for key in fields}

    return {
        "raw_text": str(raw_text or ""),
        "normalized_text": text,
        "fields": fields,
        "warnings": warnings,
        "provenance": provenance,
    }


def extract_quantity(text: str) -> dict[str, Any]:
    """Cuts and lengths.

    ``cuts_explicit`` records that the RFQ asked for *discrete runs* ("4 x 55m",
    "4 drums of 75m", 12 parallel runs) rather than a bulk figure ("700m"). Both
    end up as length_each x number_of_lengths, so without this flag a 55 m run and
    55 m of bulk cable are indistinguishable downstream -- and prepack rounding
    treats them very differently (see _prepack_applies in matcher.pipeline).
    """
    result: dict[str, Any] = {}
    # "12no. 1C x 300mm2 ..." asks for 12 parallel runs; remember the count so
    # whichever length branch fires below multiplies it in instead of
    # defaulting to a single cut.
    no_count = LEADING_NO_COUNT_RE.match(text)
    runs_prefix = int(no_count.group(1)) if no_count else None

    # "4 drums of ... at 75 m each": the count and the length are separated by the cable
    # description, so carry the count forward for whichever length branch fires below.
    drum_count = DRUM_COUNT_RE.search(text)
    drum_prefix = int(drum_count.group(1)) if drum_count else None
    multiplier = runs_prefix or drum_prefix or 1

    # A bare cut count ("(8 cuts)"), applied only to the branches below that read a
    # single stated length -- there it is the total, and this says how many pieces it
    # is cut into. Suppressed when a multiplier is already in play: "12no. ... 8 cuts"
    # states the quantity twice in two directions, and guessing which one wins is how a
    # quantity error gets made confidently. That row keeps today's behaviour and its
    # existing warnings.
    cut_match = CUT_COUNT_RE.search(text)
    cut_count = int(cut_match.group(1)) if cut_match else None
    if cut_count is not None and (cut_count < 2 or multiplier > 1):
        cut_count = None

    def _with_cuts(total):
        """``total`` split into ``cut_count`` pieces, or None when no count applies.

        The total is kept exactly as stated and only ``length_each_m`` is derived, so
        this corrects the cut breakdown without moving the metres anything is priced
        on. An uneven split is left uneven -- 650 m in 8 cuts is a real request, and
        rounding it here would silently change what gets shipped.
        """
        if not cut_count:
            return None
        return {
            "number_of_lengths": cut_count,
            "length_each_m": total / cut_count,
            "total_length_m": total,
            "uom": "m",
            "cuts_explicit": True,
        }

    drum = DRUM_RE.search(text)
    if drum:
        result["number_of_lengths"] = int(drum.group(1))
        result["length_each_m"] = float(drum.group(2))
        result["total_length_m"] = result["number_of_lengths"] * result["length_each_m"]
        result["uom"] = "m"
        result["cuts_explicit"] = True
        return result

    run = RUNS_RE.search(text)
    if run:
        number = run.group(1) or run.group(3)
        length = run.group(2) or run.group(4)
        result["number_of_lengths"] = int(number)
        result["length_each_m"] = float(length)
        result["total_length_m"] = result["number_of_lengths"] * result["length_each_m"]
        result["uom"] = "m"
        result["cuts_explicit"] = True
        return result

    lengths = [float(value) for value in LENGTH_RE.findall(text)]
    if lengths:
        split = _with_cuts(lengths[0])
        if split:
            result.update(split)
            return result
        # In lines like "30m x 16mm", the first metre quantity is the cable length.
        result["length_each_m"] = lengths[0]
        result["number_of_lengths"] = multiplier
        result["total_length_m"] = lengths[0] * result["number_of_lengths"]
        result["uom"] = "m"
        # A bare "700m" is bulk; "12no. ... 50m" is twelve discrete 50 m runs.
        if multiplier > 1:
            result["cuts_explicit"] = True
        return result

    trailing_cuts = TRAILING_CUTS_RE.search(text)
    if trailing_cuts and SIZE_RE.search(text[: trailing_cuts.start()]):
        result["number_of_lengths"] = int(trailing_cuts.group(1))
        result["length_each_m"] = float(trailing_cuts.group(2))
        result["total_length_m"] = result["number_of_lengths"] * result["length_each_m"]
        result["uom"] = "m"
        result["cuts_explicit"] = True
        return result

    trailing_length = TRAILING_BARE_LENGTH_RE.search(text)
    if trailing_length and SIZE_RE.search(text[: trailing_length.start()]):
        # Some RFQs omit the metre unit and place the length after the cable description.
        length = float(trailing_length.group(1))
        split = _with_cuts(length)
        if split:
            result.update(split)
            return result
        result["length_each_m"] = length
        result["number_of_lengths"] = multiplier
        result["total_length_m"] = length * result["number_of_lengths"]
        result["uom"] = "m"
        if multiplier > 1:
            result["cuts_explicit"] = True
        return result

    leading_count = re.search(
        r"^\s*(\d+)\s*x\s+(?=\d+\s*C\b|\d+(?:\.\d+)?\s*(?:mm2|mm\b))",
        text,
        re.I,
    )
    if leading_count:
        result["number_of_lengths"] = int(leading_count.group(1))
        result["quantity"] = int(leading_count.group(1))
    elif runs_prefix:
        result["number_of_lengths"] = runs_prefix
        result["quantity"] = runs_prefix
    elif drum_prefix:
        # "1 drum of 120mm flex Earth" -- a drum count with no length anywhere. Record the
        # count; the length stays unknown rather than being borrowed from a neighbour.
        result["number_of_lengths"] = drum_prefix
        result["quantity"] = drum_prefix
    elif re.search(r"\bQTY\b|\bQUANTITY\b", text, re.I):
        number = re.search(r"\b(\d+(?:\.\d+)?)\b", text)
        if number:
            result["quantity"] = float(number.group(1))

    return result


# "Single insulated" = an unsheathed conductor, which in this catalog is exactly three
# styles (LP/LS/UP -- the only ones with an empty Sheathing column). It is the OPPOSITE of
# "SDI" (Single DOUBLE Insulated, a sheathed single core), so the DOUBLE spellings are
# excluded rather than matched: "single/double insulated" must not match here. Shared with
# resolver.rank_styles, which also tests it against the section heading -- the phrase is
# routinely a heading over bare "200 MTR - 2.5MM Yellow" rows that carry no spec of their
# own, and a heading never reaches this function's per-row fields.
SINGLE_INSULATED_RE = re.compile(r"\bSINGLE[\s-]+INSULAT(?:ED|ION)\b", re.I)

# A separated "LS flex" names the LS single-insulated style. Keep this narrower than
# the compact LSFLEX trade-name grammar below: "LSFLEX R-70" and "LSFLEX XL-20" are
# insulation designations used on sheathed mains cables, not requests for the LS style.
LS_FLEX_STYLE_RE = re.compile(r"\bLS[\s-]+FLEX\b", re.I)

# "R-125" is the LS style's OWN insulation designation, and the only one that is: searching
# the Insulation column of every style in the register, R-125 -> ['LS'] alone, where R-70 is
# claimed by 24 sheathed styles and XL-20 by 9. So unlike the shared trade name, this
# designation identifies a style outright -- an unsheathed 110C switchboard/panel wire.
# Kept separate from LS_FLEX_STYLE_RE above rather than folded into it: that regex's
# narrowness is what keeps "LSFLEX R-70" on a sheathed aluminium submain, and widening it to
# the brand word would undo that. R-125 also stays in HIGH_TEMP_RE below -- it genuinely is a
# 110C marker; the point is that the style reading must be consulted BEFORE the temperature
# ladder, which otherwise reads the row as merely hot and routes it to the sheathed KL rung.
# The bare designation carries the signal on its own, so the LSFLEX-prefixed spellings only
# need to cover the forms that drop the R ("LSFLEX125", "LSFLEX 125").
LS_R125_STYLE_RE = re.compile(
    r"\bR-?125\b"
    r"|\bLSFLEX\W*(?:\(R\)\W*)?(?:R[\s-]*)?125\b",
    re.I,
)


# Signals that a cable runs hotter than 90 deg C: 110/125 deg C insulation codes,
# an explicit >90 deg C rating, or a plain "high temp" request. Used to decide that
# an earth conductor must be the LSZH/110C earth style (LS), not the standard LP.
HIGH_TEMP_RE = re.compile(
    # "LSFLEX R-70" is TriCab's own trade name for the 110C rubber insulation, not an
    # AS/NZS designation: every rubber style lists it beside its synonyms in one
    # Insulation cell ("LSFLEX R-70 or X-HF-110 or 110 deg C"). Read with the bare 3808
    # grammar below it parses as "rubber at 70C", which dropped a whole aluminium
    # submains schedule off the 110C rung onto the 90C XL default -- TriCab quoted it
    # KL-PAXA. Keyed on the full branded phrase so a bare "R-70" keeps its grammar
    # reading; the \W* absorbs the registered-trade-mark sign in "LSFLEX(R) R-70".
    r"\bLSFLEX\W*(?:\(R\)\W*)?R-?70\b"
    r"|\bX-?125\b|\bX-?HF-?110\b|\bR-?125\b|\bR-?HF-?110\b|\bHF-?110\b"  # >90C insulation codes
    r"|\b(?:9[1-9]|[12]\d\d)\s*(?:°|deg\w*\.?)\s*c?\b"                  # 110°C / 110 deg / 110 deg C / 110 degrees
    r"|\b(?:9[1-9]|[12]\d\d)\s*c\b"                                    # 110C (no degree word)
    r"|\bHIGH\s*TEMP\w*\b",                                            # "high temp[erature]"
    re.I,
)


# --- AS/NZS 3808 insulation designation ---------------------------------------------
# These notations are a GRAMMAR, not a list -- which is why enumerating instances kept
# missing cases (HIGH_TEMP_RE above spells out the HF family at 110 and the bare forms at
# 125, so `X-HF-125` fell through while `X-HF-110` matched). The form is
# `<material>[-<modifier>]-<temperature>`:
#     material   V = PVC, X = XLPE, R = elastomer/rubber
#     modifier   HF halogen-free, E ethylene, EP = EPR, S = silicone
#     temperature 75 / 90 / 105 / 110 / 125 / 150
# Verified against the Insulation/Bedding columns of data/catalog/current_cables.xlsx,
# which carry X-125, X-HF-110, X-90, X-HF-90, R-70, R-125, R-E-110, R-EP-125, V-90-HT,
# HFS-110-TP and friends.
#
# The temperature slot is a WHITELIST, never a bare \d{2,3}: the same sheet contains the
# LSFLEX brand designations `XL-20` and `R-20`, where the number is not a temperature at
# all. `5V90` is also deliberately excluded -- that is a *sheath* designation whose leading
# digit is the thickness class, a different slot from insulation.
_INSULATION_TEMPS = r"(?:70|75|90|105|110|125|150)"
_INSULATION_MATERIAL = {"V": "PVC", "X": "XLPE", "R": "elastomer"}
# `X-90`, `X-HF-125`, `R-EP-125`, `V-90-HT`. The lookbehind keeps the material letter
# standalone so product codes (XL-PAXN, KL-...) and `5V90` cannot match.
_ASNZS_DESIGNATION_RE = re.compile(
    rf"(?<![A-Z0-9])([VXR])-(?:(?:HF|E|EP|S)-)?({_INSULATION_TEMPS})(?:-HT)?(?![0-9])",
    re.I,
)
# Spelled-out insulation plus its temperature: `XLPE-90`, `PVC/XLPE-110/NJ`, `EPR 125`.
# The `mm` guard stops a size being read as a temperature ("XLPE 150mm2" is 150mm\u00b2, not
# 150 deg C) -- 150 is the one whitelisted value that is also a common cross-section.
_NAMED_INSULATION_RE = re.compile(
    rf"\b(XLPE|EPR|PVC)[-/\s]?({_INSULATION_TEMPS})\b(?!\s*mm)(?![0-9])",
    re.I,
)
# Bare halogen-free forms carrying no material letter: `HF-110`, `HF125`, `HFS-110-TP`.
_HF_DESIGNATION_RE = re.compile(rf"\bHFS?-?({_INSULATION_TEMPS})(?![0-9])", re.I)
# "RADOX" is a brand for the radiation-crosslinked high-temperature range. Customers
# sometimes name only the brand, so it has to carry the temperature on its own (confirmed
# by Manusha 2026-08-16). It remains a *fire* signal as well -- this is additive.
_RADOX_RE = re.compile(r"\bRADOX\b", re.I)


def insulation_designation(text: str) -> tuple[str | None, int | None]:
    """Parse an AS/NZS 3808 insulation designation into ``(material, temp_c)``.

    Either half may be ``None``: ``HF-110`` states a temperature with no material,
    ``XLPE`` on its own states a material with no temperature. Returns the HIGHEST
    temperature found, so a line naming two insulations resolves to the hotter one --
    the cable has to survive the worst case stated.
    """
    value = str(text or "")
    material: str | None = None
    temps: list[int] = []

    for match in _ASNZS_DESIGNATION_RE.finditer(value):
        material = material or _INSULATION_MATERIAL.get(match.group(1).upper())
        temps.append(int(match.group(2)))
    for match in _NAMED_INSULATION_RE.finditer(value):
        material = material or match.group(1).upper()
        temps.append(int(match.group(2)))
    for match in _HF_DESIGNATION_RE.finditer(value):
        temps.append(int(match.group(1)))

    # Bare material words with no temperature attached. Order matters: "PVC/XLPE" names
    # the sheath first and the insulation second, so XLPE has to win.
    if material is None:
        for word, name in (("XLPE", "XLPE"), ("EPR", "EPR"), ("PVC", "PVC")):
            if re.search(rf"\b{word}\b", value, re.I):
                material = name
                break

    return material, (max(temps) if temps else None)


def mentions_high_temp(text: str) -> bool:
    """True when the text indicates an operating temperature above 90 deg C."""
    value = str(text or "").replace("\u00c2\u00b0", " deg ").replace("\u00b0", " deg ").replace("\u00ba", " deg ")
    if HIGH_TEMP_RE.search(value):
        return True
    if _RADOX_RE.search(value):
        return True
    _, temp = insulation_designation(value)
    return temp is not None and temp > 90


def extract_voltages(upper: str) -> set:
    """Rated voltage(s) in volts stated in the RFQ, or empty if none. Returns both
    numbers of a pair (``0.6/1kV`` -> {600, 1000}) so the gate matches the style on
    either U0 or U; a lone value (``11kV`` -> {11000}, ``600V`` -> {600}) returns one.
    Mirrors the style-side parser for consistent comparison."""
    return extract_voltage_reading(upper)[0]


def extract_voltage_reading(upper: str) -> tuple[set, bool]:
    """``extract_voltages`` plus whether the customer wrote it as a **slash pair**.

    The shape matters as much as the numbers. A slash pair (``300/500V``, ``0.6/1kV``) is
    cable-rating notation -- U0/U, the thing the register's voltage column also states. A
    bare single (``415V``, ``240V``) is far more often the *system* voltage of the
    installation, and the standard cable for a 415 V system is 600/1000 V, not the 450/750
    that "satisfy-or-exceed" would pick for it. So only a pair may be substituted; see
    ``_voltage_gate_set`` in the resolver.

    ``1500/1500 V`` is why this cannot be inferred from ``len(voltage_set)``: it is a real
    register band whose two numbers are equal, so it collapses to a one-element set while
    still being a pair.
    """
    match = VOLTAGE_RE.search(upper)
    if not match:
        return set(), False
    stated_as_pair = match.group(2) is not None
    scale = 1000 if match.group(3).upper() == "KV" else 1
    volts = set()
    for group in (match.group(1), match.group(2)):
        if group is None:
            continue
        try:
            value = float(group) * scale
        except ValueError:
            continue
        # A lone "...V" below 100 is almost never a cable rating; ignore to avoid
        # false gates (the smallest real class here is 110 V).
        if value >= 100:
            volts.add(int(round(value)))
    return volts, stated_as_pair


def extract_colour(upper: str) -> str:
    # Word-boundary match, not a bare substring test: a plain `in` check matched colour
    # words hidden inside other tokens (e.g. "RED" inside "ARMOU-RED" -> phantom red).
    # "GREEN" is checked AFTER the green/yellow (earth) variants so "GREEN/YELLOW"
    # resolves to EA, not a bare green.
    # The green/yellow (earth) spellings stay AHEAD of the bare "GREEN"/"YELLOW" entries
    # so an earth colour resolves to EA rather than to one of its two halves. YELLOW and
    # BROWN/PINK/LIGHT BLUE were missing entirely, so a "10MM Yellow" building-wire line
    # silently took the style's default colour instead (OR on KL, EA on LS).
    if re.search(r"\b(?:GREEN\s*/?\s*YELLOW|G/Y|GN\s*/\s*YE)\b", upper):
        return "EA"
    for token in ["BLACK", "BLK", "LIGHT BLUE", "DARK BLUE", "BLUE", "RED", "ORANGE",
                  "WHITE", "PURPLE", "GREY", "GRAY",
                  "GREEN/YELLOW", "GREEN YELLOW", "GN/YE", "G/Y", "GREEN",
                  "YELLOW", "BROWN", "PINK"]:
        if re.search(rf"\b{re.escape(token)}\b", upper):
            return canonical_colour(token)
    match = re.search(r"\b(BK|BL|BR|OR|RE|EA|WH|GY|GR|YE|PU|PI|LB|RB)\b", upper)
    return canonical_colour(match.group(1)) if match else ""


def extract_line_note(raw_text: str) -> str:
    """Return a circuit label / description prefix from a free-text RFQ line.

    Looks for text before a : or – separator that precedes the cable spec,
    e.g. "Circuit 1: 4C 70mm Cu XLPE 150m" → "Circuit 1".
    Returns "" when no label is found or the prefix looks like the cable spec itself.
    """
    text = str(raw_text or "").strip()
    route_note = extract_ocr_route_note(text)
    if route_note:
        return route_note
    match = NOTE_PREFIX_RE.match(text)
    if not match:
        return ""
    prefix = match.group(1).strip(" \t–—-:")
    # A prefix that is just a bare number (e.g. a row number "1:") is not a label.
    if re.fullmatch(r"\d+", prefix):
        return ""
    return prefix


def looks_like_circuit_label_line(line: str) -> bool:
    """True if ``line`` reads as a standalone circuit tag (e.g. "MSSB-L3-4-E"):
    short, alphanumeric with separators, no spaces. Callers are expected to
    have already ruled out cable signal (see CABLE_SIGNAL_RE) before treating
    a match as an actual label -- this only checks the tag's own shape."""
    value = str(line or "").strip()
    if not value or len(value) > 40:
        return False
    return bool(CIRCUIT_LABEL_LINE_RE.fullmatch(value))


def pair_circuit_label_lines(lines: list[str]) -> list[tuple[str, str]]:
    """Fold a standalone circuit-label line into the cable-description line
    that immediately follows it, returning ``(text, reference)`` pairs in
    order. A label is only folded when the very next line carries cable
    signal; anything else (including a label never followed by a cable line)
    passes through unchanged with an empty reference, so no input line is
    ever silently dropped.
    """
    pairs: list[tuple[str, str]] = []
    pending_label = ""
    for raw in lines:
        line = str(raw or "").strip()
        if not line:
            continue
        if CABLE_SIGNAL_RE.search(line):
            pairs.append((line, pending_label))
            pending_label = ""
            continue
        if looks_like_circuit_label_line(line):
            if pending_label:
                pairs.append((pending_label, ""))
            pending_label = line
            continue
        if pending_label:
            pairs.append((pending_label, ""))
            pending_label = ""
        pairs.append((line, ""))
    if pending_label:
        pairs.append((pending_label, ""))
    return pairs


# A line that is nothing but a quantity: "200", "2 X 50M", "50m". Folded into
# the spec line above it when that line has cable signal but no length of its
# own -- the alternating spec-line/qty-line style (07-Ajith, 10-Neil) otherwise
# yields a spec item with no quantity and an unmatchable bare-number item.
BARE_QUANTITY_LINE_RE = re.compile(
    r"^\s*(?:\d+\s*[x×]\s*)?\d+(?:\.\d+)?\s*(?:m|mtrs?|metres?|meters?)?\s*$", re.I
)


def pair_schedule_lines(lines: list[str]) -> list[tuple[str, str]]:
    """Label pairing plus quantity-line folding for free-text RFQs.

    Runs :func:`pair_circuit_label_lines` first, then merges a bare
    quantity-only line into the immediately preceding line when that line
    reads as a cable spec (or names a product code) and carries no length
    signal of its own. Lines that don't fit the pattern pass through
    unchanged, so nothing is ever dropped."""
    pairs = pair_circuit_label_lines(lines)
    folded: list[tuple[str, str]] = []
    for text, reference in pairs:
        if folded and BARE_QUANTITY_LINE_RE.match(text):
            prev_text, prev_reference = folded[-1]
            is_spec = bool(
                CABLE_SIGNAL_RE.search(prev_text) or PRODUCT_CODE_RE.search(prev_text.upper())
            )
            has_length = bool(
                LENGTH_RE.search(prev_text)
                or RUNS_RE.search(prev_text)
                or DRUM_RE.search(prev_text)
                or TRAILING_CUTS_RE.search(prev_text)
            )
            if is_spec and not has_length and not BARE_QUANTITY_LINE_RE.match(prev_text):
                folded[-1] = (f"{prev_text} {text.strip()}", prev_reference)
                continue
        folded.append((text, reference))
    return folded


def extract_ocr_route_note(text: str) -> str:
    fields: dict[str, str] = {}
    for match in OCR_ROUTE_FIELD_RE.finditer(text):
        label = re.sub(r"\s+", " ", match.group("label").strip().lower())
        value = re.sub(
            r"\s+\b(?:from|to|cable\s*no|active|neutral|earth|insulation|configuration)\b.*$",
            "",
            match.group("value").strip(),
            flags=re.I,
        ).strip(" ,|")
        if value:
            fields[label] = value
    reference = fields.get("cable no", "")
    from_v = fields.get("from", "")
    to_v = fields.get("to", "")
    route = f"{from_v} > {to_v}" if from_v and to_v else (from_v or to_v)
    if reference and route:
        return f"{reference}: {route}"
    return reference or route
