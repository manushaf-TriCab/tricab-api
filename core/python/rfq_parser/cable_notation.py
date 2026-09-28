# Generated from the RFQ parser's matcher/cable_notation.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
"""Cable notation, with one owner.

Two prompts need to know how many conductors a token like `4x4x1c+e` denotes: the
normalizer, which turns customer row text into resolver fields, and the attachment reader,
which decides what a spreadsheet row is asking for. The knowledge belongs to neither of
them, so it lives here and both import it.

It was not always so, and the cost is on the record. The reader was given a paragraph
hand-written mid-measurement, and it got two things wrong that the normalizer has right:

- `2c+e` was read as three runs and multiplied by three. It is ONE multicore cable, and its
  route length is its length -- a 3x over-quote on every such row (`23-Allison` row 4, +320 m).
- `3x4x1c+e` was read as one earth instead of three, on some runs and not others -- a
  310 m under-quote that moved between runs on a settled prompt, invisible because the run
  that got it right was rejected by validation for an unrelated reason.

**`CORE_COUNT_NOTATION` is a verbatim slice of `llm_fallback.FIELD_SEMANTICS`**, extracted
by index and never retyped. The normalizer's system prompt carries `cache_control` and runs
to ~53K characters, so a single changed character invalidates that cache on every email that
arrives. `tests/test_cable_notation.py` pins the rendered strings against snapshots in
`tests/fixtures/prompts/` for exactly that reason.

Deliberately not shared: the sentences around this slice that map notation onto the
normalizer's own output fields (`core_count`, `is_earth_only`, `number_of_lengths`). Those
are that prompt's contract with the resolver, and the reader has a different schema. What is
shared is what the customer's notation MEANS, which is the same fact for every reader of it.
"""

from __future__ import annotations

# Verbatim. Reads as a noun phrase followed by its rules, so each prompt supplies its own
# lead-in: the normalizer's is "The SAME split applies to ", the reader's names the column.
CORE_COUNT_NOTATION = (
    'the glued single-core forms "Nx1C+E" and "AxBx1C+E" (e.g. \'4x1c+e\' or '
    "'3x4x1c+e'): here '1c' ALWAYS means a single-core cable (a multicore is "
    "written only as 2C/3C/4C…, never 1C), and '+E' is a SEPARATE earth "
    "conductor, never a counted core -- so '4x1c+e' is 4 single-core actives + "
    "1 earth, and '3x4x1c+e' is 3 bundles of 4 => 12 single-core actives + 3 "
    'earths (one earth per bundle).'
)


ENGINEERING_SCHEDULE_NOTATION = """
For schedules with separate Cable Size/Active Size and Neutral Size columns:
- SDI, S.D.I. and Flex S.D.I. describe separate single-core cables. This schedule
  convention is three phases. N SETS OF S in the active column means 3*N cables
  of S mm2; the neutral column contributes its own set count. A bare size means
  one per phase or one neutral. Merge active and neutral only when their specs agree.
- The Earth Size column has its OWN set count: 6 SETS OF 300 active and neutral,
  4 SETS OF 120 earth, route 15m => 360m mains and 60m earth. Never multiply a
  stated total again. Preserve the route as the cut length.
- Separate earths default to copper, even beside aluminium actives. Use default
  earth selection rules; X-125 and R-110 mains require LS earths. Do not force the
  mains insulation onto ordinary separate earths.
- Explicit 4C&E / 4C+E is ONE multicore with integral earth. A sized Earth column
  describes that integral earth, not an additional cable. Preserve reduced earth
  size. Do not multiply route length by the number of cores.
- Preserve insulation designations verbatim, including LSFLEX XL-20 (not 20C).
  Equipment names and SAFETY SERVICES do not by themselves request fire rating.
"""
