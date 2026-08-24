# Phase 12.7 Batch A.1 — Category Resolution

Generated: 2026-08-20T23:20:17+00:00

TARGET_LIVE_VALIDATION = **AVAILABLE**

Server: tricab-mel · Database: tc · Environment: NON-PRODUCTION

Legacy writes: **0** · Product/Variant/Catalogue mutations: **0** · Batch A apply: **NOT RUN**

## Counts

| Decision | Count |
|---|---:|
| APPROVED_MAP_EXISTING | 4 |
| APPROVED_CREATE | 0 |
| PROPOSED_CREATE | 9 |
| REVIEW | 86 |
| NOT_A_PRODUCT_CATEGORY | 106 |
| REJECT | 2 |
| CONFLICT | 0 |

Previous REVIEW: 108

## A. Strong Product Category proposals (PROPOSED_CREATE)

PROPOSED_CREATE is **not** operator approval. Do not create on target yet.

| Code | Name | Family | Product count | Confidence | Source term |
|---|---|---|---:|---|---|
| CONDUITS-FITTINGS | Conduits & Fittings | ACCESSORIES | 11 | high | Conduits & Fittings (term 614) |
| FLEX-CONDUIT | Flexible Conduit | ACCESSORIES | 10 | high | Flexible Conduit (term 538) |
| TECHNO-CONNECTORS | Techno Connectors | ACCESSORIES | 8 | high | Techno Connectors (term 584) |
| TERMINALS | Terminals | ACCESSORIES | 6 | high | Terminals (term 512) |
| BOOTLACE-FERRULE | Bootlace / Ferrule | ACCESSORIES | 5 | high | Bootlace / Ferrule (term 602) |
| GLANDS | Glands | ACCESSORIES | 5 | high | Glands (term 514) |
| CONDUIT-GLANDS | Conduit Glands | ACCESSORIES | 4 | medium | Conduit Glands (term 519) |
| LINKS | Links | ACCESSORIES | 4 | medium | Links (term 600) |
| PLUG-SOCKET-LEADS | Plug-Socket Leads | ACCESSORIES | 4 | medium | Plug-Socket Leads (term 630) |

## B. Map to existing Categories

- **VFD/VSD** → `VFD` (exact_normalized_name_match_existing_category)
- **VFD/VSD** → `VFD` (exact_normalized_name_match_existing_category)
- **VFD/VSD** → `VFD` (exact_normalized_name_match_existing_category)
- **VFD / VSD** → `VFD` (exact_normalized_name_match_existing_category)

## C. Not Product Categories

### CATALOGUE_NAVIGATION (5)

- Crimpers & Dies (term 625, usage 11) — semantic_type_catalogue_navigation
- Hardware & Fittings (term 619, usage 6) — semantic_type_catalogue_navigation
- Cable Preparation (term 624, usage 5) — semantic_type_catalogue_navigation
- Lockout & Tagout (term 627, usage 4) — semantic_type_catalogue_navigation
- Bootlace/ Ferrule Crimper(new) (term 555, usage 1) — semantic_type_catalogue_navigation

### UNKNOWN (5)

- Quick Connect (term 605, usage 7) — semantic_type_unknown
- Accessories & Wiring (term 623, usage 6) — semantic_type_unknown
- Other Tools (term 626, usage 5) — semantic_type_unknown
- Power (term 400, usage 5) — semantic_type_unknown
- Power (term 372, usage 4) — semantic_type_unknown

## D. Duplicate / merge candidates

Do **not** auto-merge on normalized text alone.

- `110v` → EXACT_DUPLICATE · terms: 399,396,426 · raw: 110V
- `450 750v` → EXACT_DUPLICATE · terms: 398,395,403 · raw: 450/750V
- `06 1kv` → EXACT_DUPLICATE · terms: 394,410,419,397,402 · raw: 0.6/1kV
- `copper` → EXACT_DUPLICATE · terms: 642,644 · raw: Copper
- `power and control` → HTML_ENTITY_VARIANT · terms: 319,502,306,332,312,317,293 · raw: Power and Control | Power &amp; Control
- `fire resistant` → EXACT_DUPLICATE · terms: 438,379 · raw: Fire Resistant
- `glands` → EXACT_DUPLICATE · terms: 514,542 · raw: Glands
- `medium voltage` → EXACT_DUPLICATE · terms: 427,482 · raw: Medium Voltage
- `power` → EXACT_DUPLICATE · terms: 400,372 · raw: Power
- `aluminium` → EXACT_DUPLICATE · terms: 643,645 · raw: Aluminium
- `battery` → EXACT_DUPLICATE · terms: 528,505 · raw: Battery
- `instrumentation collective` → EXACT_DUPLICATE · terms: 307,320,313,325,333 · raw: Instrumentation - Collective
- `instrumentation individual and collective` → EXACT_DUPLICATE · terms: 308,321,314,326 · raw: Instrumentation - Individual and Collective
- `19 33kv` → DISPLAY_VARIANT · terms: 391,387,408,418,404 · raw: 1.9/3.3kV | 19/33kV
- `127 22kv` → EXACT_DUPLICATE · terms: 388,405,415 · raw: 12.7/22kV
- `38 66kv` → EXACT_DUPLICATE · terms: 390,407,417 · raw: 3.8/6.6kV
- `635 11kv` → EXACT_DUPLICATE · terms: 389,406,416 · raw: 6.35/11kV
- `vfd` → EXACT_DUPLICATE · terms: 503,500 · raw: VFD
- `vfd vsd` → DISPLAY_VARIANT · terms: 373,324,336,311 · raw: VFD/VSD | VFD / VSD
- `solar` → EXACT_DUPLICATE · terms: 647,445 · raw: Solar
- `welding` → EXACT_DUPLICATE · terms: 504,429,443 · raw: Welding
- `switchboard panel wire` → EXACT_DUPLICATE · terms: 309,322 · raw: Switchboard/Panel Wire

## E. Still ambiguous (REVIEW)

- Cable Protection / Installation Kits (term 616, usage 6, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Power and Control (term 319, usage 6, family CABLE) — product_category_candidate_with_suggested_match_operator_must_map [MEDIUM]
- CeeForm (term 612, usage 5, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Power & Control (term 502, usage 5, family CABLE) — product_category_candidate_with_suggested_match_operator_must_map [MEDIUM]
- Ship Loaders & Cranes (term 634, usage 5, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Battery (term 528, usage 4, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Control & Instrumentation (term 539, usage 4, family CABLE) — product_category_candidate_with_suggested_match_operator_must_map [MEDIUM]
- Instrumentation - Collective (term 307, usage 4, family CABLE) — product_category_candidate_with_suggested_match_operator_must_map [MEDIUM]
- Instrumentation - Collective (term 320, usage 4, family CABLE) — product_category_candidate_with_suggested_match_operator_must_map [MEDIUM]
- Instrumentation - Individual and Collective (term 308, usage 4, family CABLE) — product_category_candidate_with_suggested_match_operator_must_map [MEDIUM]
- Instrumentation - Individual and Collective (term 321, usage 4, family CABLE) — product_category_candidate_with_suggested_match_operator_must_map [MEDIUM]
- Power and Control (term 306, usage 4, family CABLE) — product_category_candidate_with_suggested_match_operator_must_map [MEDIUM]
- Power and Control (term 332, usage 4, family CABLE) — product_category_candidate_with_suggested_match_operator_must_map [MEDIUM]
- Ties (term 536, usage 4, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Wire Management (term 618, usage 4, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Ducts (term 615, usage 3, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Food & Beverage (term 639, usage 3, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Glands (term 542, usage 3, family ACCESSORIES) — category_code_collision_no_silent_suffix [MEDIUM]
- Hook-Up Wire (term 485, usage 3, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Lug Leads (term 629, usage 3, family ACCESSORIES) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Shorepower (term 521, usage 3, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Tape (term 526, usage 3, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Tapes (term 633, usage 3, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Temporary Power (term 635, usage 3, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Thermoset (term 493, usage 3, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- VFD (term 503, usage 3, family CABLE) — product_category_candidate_with_suggested_match_operator_must_map [MEDIUM]
- Battery (term 505, usage 2, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Cables (term 568, usage 2, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Ceeform Leads (term 631, usage 2, family ACCESSORIES) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Circuit Protection (term 617, usage 2, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Connectors (term 613, usage 2, family ACCESSORIES) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Control/Instrument (term 376, usage 2, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- DC Power (term 442, usage 2, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Instrumentation - Collective (term 313, usage 2, family CABLE) — product_category_candidate_with_suggested_match_operator_must_map [MEDIUM]
- Instrumentation - Collective (term 325, usage 2, family CABLE) — product_category_candidate_with_suggested_match_operator_must_map [MEDIUM]
- Instrumentation - Collective (term 333, usage 2, family CABLE) — product_category_candidate_with_suggested_match_operator_must_map [MEDIUM]
- Instrumentation - Individual (term 334, usage 2, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Instrumentation - Individual and Collective (term 314, usage 2, family CABLE) — product_category_candidate_with_suggested_match_operator_must_map [MEDIUM]
- Instrumentation - Individual and Collective (term 326, usage 2, family CABLE) — product_category_candidate_with_suggested_match_operator_must_map [MEDIUM]
- Power and Control (term 312, usage 2, family CABLE) — product_category_candidate_with_suggested_match_operator_must_map [MEDIUM]
- Power and Control (term 317, usage 2, family CABLE) — product_category_candidate_with_suggested_match_operator_must_map [MEDIUM]
- Shipboard / Offshore (term 258, usage 2, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Slotted Ducts (term 550, usage 2, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Stainless Steel (term 610, usage 2, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Thermocouple Cable (term 488, usage 2, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- UL1426 ABYC Boat Cable (term 523, usage 2, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Variable Drive (term 441, usage 2, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- VFD (term 500, usage 2, family CABLE) — product_category_candidate_with_suggested_match_operator_must_map [MEDIUM]
- Water Treatment (term 640, usage 2, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Weatherproof (term 621, usage 2, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- ANDERSON Connectors (term 594, usage 1, family ACCESSORIES) — ambiguous_or_insufficient_evidence_for_create [LOW]
- ANDERSON Plug leads(new) (term 560, usage 1, family ACCESSORIES) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Appliance Inlet(new) (term 556, usage 1, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Bootlace Reels(new) (term 564, usage 1, family ACCESSORIES) — ambiguous_or_insufficient_evidence_for_create [LOW]
- braided sleeve(new) (term 573, usage 1, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Brass Nickel Plated Cable Gland(new) (term 587, usage 1, family ACCESSORIES) — ambiguous_or_insufficient_evidence_for_create [LOW]
- CEEFORM Switched Outlet(new) (term 558, usage 1, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Clamps (term 518, usage 1, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Cold Shrink (term 607, usage 1, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Conductive Grease (term 546, usage 1, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Earth Bars(new) (term 571, usage 1, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Earth Bonding Leads(new) (term 590, usage 1, family ACCESSORIES) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Earth Leads(new) (term 562, usage 1, family ACCESSORIES) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Earthing & Bonding (term 335, usage 1, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Earthing & Bonding Leads (term 632, usage 1, family ACCESSORIES) — ambiguous_or_insufficient_evidence_for_create [LOW]
- EPDM Cold Shrink Tube(new) (term 575, usage 1, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Heatshrink Breakouts(new) (term 566, usage 1, family ACCESSORIES) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Heavy Duty Connector(new) (term 586, usage 1, family ACCESSORIES) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Mounting Enclosure(new) (term 580, usage 1, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Narrow Ducts(new) (term 596, usage 1, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Offset Eye Cable Stocking(new) (term 583, usage 1, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Pre-assembled Leads(new) (term 576, usage 1, family ACCESSORIES) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Push Button (term 622, usage 1, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Push Button Switch(new) (term 577, usage 1, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Rotary (term 620, usage 1, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Shore Power (term 638, usage 1, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Single Compartment Ducts(new) (term 581, usage 1, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Single Eye Cable Stocking(new) (term 582, usage 1, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Site Safety (term 628, usage 1, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Slotted Ducts(new) (term 553, usage 1, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Temp (term 636, usage 1, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Twilight Switch(new) (term 572, usage 1, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Twisted Cable(new) (term 569, usage 1, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Weatherproof Isolator(new) (term 578, usage 1, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Weatherproof Switch(new) (term 579, usage 1, family CABLE) — ambiguous_or_insufficient_evidence_for_create [LOW]
- Power & Control (term 293, usage 4, family CABLE) — product_category_candidate_with_suggested_match_operator_must_map [MEDIUM]

## F. Rejected / bad legacy data

- News (term 1) — obsolete_taxonomy_term
- Kennards Store (term 567) — obsolete_taxonomy_term

## Series re-evaluation

| Decision | Count |
|---|---:|
| APPROVED_MAP_EXISTING | 0 |
| APPROVED_CREATE | 0 |
| PROPOSED_CREATE | 0 |
| REVIEW | 390 |
| INVALID | 0 |
| SOURCE_ONLY | 0 |
| REJECT | 0 |
| CONFLICT | 0 |

Series remain blocked until Categories are operator-approved (PROPOSED_CREATE does not unlock Series).

## Final Batch A dry-run

Live target validated: YES — operator may run dry-run (still no `--apply`).

## NEXT ACTION

WAIT FOR EXPLICIT FINAL BATCH A APPLY APPROVAL

VFD → existing VFD mapping remains in approved set and is **not applied**.

