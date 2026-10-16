# AU catalogue gap report

Read-only comparison of the legacy Australia catalogue on www2.tricab.com with the new catalogue on tricab.ai. No products, navigation rows, prices, or publication states were created or changed.

Audit time: 2026-10-15T01:07:11+00:00
Database: tricab-mel / tc

## Executive summary

AUDIT_STATUS: COMPLETE

| Measure | Count |
|---|---:|
| Legacy unique products | 304 |
| Matched unique products | 93 |
| Missing product records | 195 |
| Draft products | 14 |
| Missing category placements | 3 |
| AU visibility issues | 0 |
| Unresolved matches | 16 |

The 93 matched products include the 14 drafts. Drafts have product records, so they are not missing records. The 3 missing placements are published products that already exist under another category. The 16 unresolved matches have no exact product-code match: 15 Marine IEC two-letter cards with several suffix records, and one Instrumentation card with no code.

These unique totals count each product code once. Category tables below count the same code again when it appears in another category. Subcategory rows in `category-summary.csv` go one step further and count each category placement.

| Level | Count | What it counts |
|---|---:|---|
| Unique products | 304 | One row per legacy code, or per uncoded card |
| Main-category memberships | 338 | A code in two main categories counts twice |
| Subcategory placement rows | 359 | A code in two subcategories counts twice |

Placement-row totals from the subcategory file: legacy 359, missing-product rows 233, draft rows 14, placement-gap rows 3, review rows 16. The 233 missing-product rows collapse to 195 unique codes.

## Baseline

Confirmed on tricab-mel / tc before classification:

| Check | Result |
|---|---:|
| Active navigation nodes | 321 |
| Product placements | 164 |
| Active product records | 182 |
| Product-region records | 156 |
| Batch A–D navigation nodes | 77 |
| Batch A–D placements | 71 |
| Batch E display-order updates | 16 |

Product-state fingerprint `98e454e2e588aa7508bce316813cb552ea7721ec19a26ed039eefae6e396b4b7`. Product-region fingerprint `0a4d4dbce6175bb22c5304a1b8155ef3b095443b010a5044b636d2cf4740870a`. These match the Batch E after-snapshot. The audit stopped for a mismatch only if those baseline checks had failed. They passed.

The public AU catalogue (`/api/v1/catalogue/tabs/{products,special-applications}/tree?regionCode=AU&includeProducts=true`) returned 185 visible nodes and 108 product codes. Every legacy card classified PRESENT is in that tree. No legacy card classified DRAFT is in that tree. Five published products are in the database with AU visibility and are absent from the tree because they have no navigation placement: D20, D21, D22, NTP, UPC. Those five codes are not on the legacy homepage card list.

## Category comparison — unique products

A product that sits in two subcategories of the same main category is counted once here. Present means published, AU-visible, and placed in that main category. Matched in the files includes present, draft, and missing-placement products.

| Main category | Legacy unique | Present | Missing products | Drafts | Missing placements | Visibility | Needs review |
|---|---:|---:|---:|---:|---:|---:|---:|
| Accessories | 178 | 34 | 143 | 1 | 0 | 0 | 0 |
| ALI-FLEX System | 13 | 5 | 8 | 0 | 0 | 0 | 0 |
| Control | 17 | 1 | 10 | 6 | 0 | 0 | 0 |
| Electrical Contracting | 8 | 0 | 8 | 0 | 0 | 0 | 0 |
| Instrumentation | 19 | 13 | 1 | 4 | 0 | 0 | 1 |
| Marine IEC LSZH | 18 | 0 | 0 | 0 | 3 | 0 | 15 |
| Marine NEK 606 | 18 | 15 | 3 | 0 | 0 | 0 | 0 |
| Medium Voltage | 5 | 0 | 2 | 3 | 0 | 0 | 0 |
| Solar | 4 | 1 | 3 | 0 | 0 | 0 | 0 |
| Steel/Alum Armour | 5 | 2 | 3 | 0 | 0 | 0 | 0 |
| Submersible/Potable | 3 | 0 | 3 | 0 | 0 | 0 | 0 |
| Switchboard & Panel | 4 | 0 | 4 | 0 | 0 | 0 | 0 |
| Termite Res/Nylon | 7 | 6 | 1 | 0 | 0 | 0 | 0 |
| VFD/VSD | 7 | 4 | 3 | 0 | 0 | 0 | 0 |
| Welding/Battery | 8 | 0 | 8 | 0 | 0 | 0 | 0 |
| Food & Beverage | 3 | 0 | 3 | 0 | 0 | 0 | 0 |
| Industrial & Marine Switchboard Wire | 2 | 1 | 1 | 0 | 0 | 0 | 0 |
| Ship Loaders & Cranes | 5 | 0 | 5 | 0 | 0 | 0 | 0 |
| Shore Power | 1 | 1 | 0 | 0 | 0 | 0 | 0 |
| Temporary Power | 3 | 0 | 3 | 0 | 0 | 0 | 0 |
| Water Treatment | 2 | 0 | 2 | 0 | 0 | 0 | 0 |
| Fire Resistant | 4 | 1 | 3 | 0 | 0 | 0 | 0 |
| Telecoms | 4 | 0 | 4 | 0 | 0 | 0 | 0 |

Each row adds across: legacy unique = present + missing products + drafts + missing placements + visibility + needs review.

## Subcategory comparison

`category-summary.csv` is the subcategory ledger. Present in the table below is matched minus drafts, placement gaps, and visibility issues. A code listed under both Copper and Aluminium is two placement rows and one unique product.

| Main category | Subcategory | Legacy | Matched | Present | Missing | Draft | Placement gaps | Visibility | Review |
|---|---|---:|---:|---:|---:|---:|---:|---:|---:|
| Accessories | lugs-and-links > Links | 4 | 4 | 3 | 0 | 1 | 0 | 0 | 0 |
| Accessories | lugs-and-links > Lugs | 10 | 1 | 1 | 9 | 0 | 0 | 0 | 0 |
| Accessories | terminals > Bootlace / Ferrule | 5 | 0 | 0 | 5 | 0 | 0 | 0 | 0 |
| Accessories | terminals > Pin & Blade | 4 | 2 | 2 | 2 | 0 | 0 | 0 | 0 |
| Accessories | terminals > Quick Connect | 7 | 0 | 0 | 7 | 0 | 0 | 0 | 0 |
| Accessories | terminals > Ring & Fork | 6 | 1 | 1 | 5 | 0 | 0 | 0 | 0 |
| Accessories | heat-shrink-and-cold-shrink > Cold Shrink | 1 | 0 | 0 | 1 | 0 | 0 | 0 | 0 |
| Accessories | heat-shrink-and-cold-shrink > Heat Shrink | 7 | 0 | 0 | 7 | 0 | 0 | 0 | 0 |
| Accessories | glands > Brass | 8 | 3 | 3 | 5 | 0 | 0 | 0 | 0 |
| Accessories | glands > Nylon | 3 | 0 | 0 | 3 | 0 | 0 | 0 | 0 |
| Accessories | glands > Stainless Steel | 2 | 1 | 1 | 1 | 0 | 0 | 0 | 0 |
| Accessories | plug-socket > Connectors | 2 | 0 | 0 | 2 | 0 | 0 | 0 | 0 |
| Accessories | plug-socket > Industrial | 12 | 0 | 0 | 12 | 0 | 0 | 0 | 0 |
| Accessories | plug-socket > CeeForm | 5 | 0 | 0 | 5 | 0 | 0 | 0 | 0 |
| Accessories | plug-socket > PowerSure | 16 | 0 | 0 | 16 | 0 | 0 | 0 | 0 |
| Accessories | plug-socket > Techno Connectors | 8 | 8 | 8 | 0 | 0 | 0 | 0 | 0 |
| Accessories | cable-management > Ducts | 3 | 0 | 0 | 3 | 0 | 0 | 0 | 0 |
| Accessories | cable-management > Cable Protection / Installation Kits | 6 | 0 | 0 | 6 | 0 | 0 | 0 | 0 |
| Accessories | cable-management > Conduits & Fittings | 11 | 11 | 11 | 0 | 0 | 0 | 0 | 0 |
| Accessories | cable-management > Tapes | 3 | 0 | 0 | 3 | 0 | 0 | 0 | 0 |
| Accessories | insulators > Circuit Protection | 2 | 0 | 0 | 2 | 0 | 0 | 0 | 0 |
| Accessories | insulators > Hardware & Fittings | 6 | 1 | 1 | 5 | 0 | 0 | 0 | 0 |
| Accessories | insulators > Wire Management | 4 | 2 | 2 | 2 | 0 | 0 | 0 | 0 |
| Accessories | switches > Push Button | 1 | 0 | 0 | 1 | 0 | 0 | 0 | 0 |
| Accessories | switches > Rotary | 1 | 0 | 0 | 1 | 0 | 0 | 0 | 0 |
| Accessories | switches > Weatherproof | 2 | 0 | 0 | 2 | 0 | 0 | 0 | 0 |
| Accessories | solar-components > Accessories & Wiring | 6 | 1 | 1 | 5 | 0 | 0 | 0 | 0 |
| Accessories | tools-dies > Other Tools | 5 | 0 | 0 | 5 | 0 | 0 | 0 | 0 |
| Accessories | tools-dies > Cable Preparation | 5 | 0 | 0 | 5 | 0 | 0 | 0 | 0 |
| Accessories | tools-dies > Crimpers & Dies | 11 | 0 | 0 | 11 | 0 | 0 | 0 | 0 |
| Accessories | safety-security-accessories > Lockout & Tagout | 4 | 0 | 0 | 4 | 0 | 0 | 0 | 0 |
| Accessories | safety-security-accessories > Site Safety | 1 | 0 | 0 | 1 | 0 | 0 | 0 | 0 |
| Accessories | pre-assembled_lead > Lug Leads | 3 | 0 | 0 | 3 | 0 | 0 | 0 | 0 |
| Accessories | pre-assembled_lead > Plug-Socket Leads | 4 | 4 | 4 | 0 | 0 | 0 | 0 | 0 |
| Accessories | pre-assembled_lead > Ceeform Leads | 2 | 0 | 0 | 2 | 0 | 0 | 0 | 0 |
| Accessories | pre-assembled_lead > Earthing & Bonding Leads | 1 | 0 | 0 | 1 | 0 | 0 | 0 | 0 |
| Accessories | Cable Ties | 9 | 0 | 0 | 9 | 0 | 0 | 0 | 0 |
| ALI-FLEX System | Flexible Cables | 11 | 5 | 5 | 6 | 0 | 0 | 0 | 0 |
| ALI-FLEX System | Solar | 2 | 0 | 0 | 2 | 0 | 0 | 0 | 0 |
| Control | 110V | 6 | 6 | 0 | 0 | 6 | 0 | 0 | 0 |
| Control | 450/750V | 3 | 1 | 1 | 2 | 0 | 0 | 0 | 0 |
| Control | 0.6/1kV | 8 | 0 | 0 | 8 | 0 | 0 | 0 | 0 |
| Electrical Contracting | Copper | 8 | 0 | 0 | 8 | 0 | 0 | 0 | 0 |
| Electrical Contracting | Aluminium | 4 | 0 | 0 | 4 | 0 | 0 | 0 | 0 |
| Instrumentation | 110V | 8 | 8 | 8 | 0 | 0 | 0 | 0 | 0 |
| Instrumentation | 450/750V | 9 | 7 | 5 | 1 | 2 | 0 | 0 | 1 |
| Instrumentation | 0.6/1kV | 2 | 2 | 0 | 0 | 2 | 0 | 0 | 0 |
| Marine IEC LSZH | Shipboard / Power and Control | 4 | 2 | 0 | 0 | 0 | 2 | 0 | 2 |
| Marine IEC LSZH | Shipboard / Instrumentation - Collective | 4 | 0 | 0 | 0 | 0 | 0 | 0 | 4 |
| Marine IEC LSZH | Shipboard / Instrumentation - Individual and Collective | 4 | 0 | 0 | 0 | 0 | 0 | 0 | 4 |
| Marine IEC LSZH | Offshore / Power and Control | 2 | 1 | 0 | 0 | 0 | 1 | 0 | 1 |
| Marine IEC LSZH | Offshore / Instrumentation - Collective | 2 | 0 | 0 | 0 | 0 | 0 | 0 | 2 |
| Marine IEC LSZH | Offshore / Instrumentation - Individual and Collective | 2 | 0 | 0 | 0 | 0 | 0 | 0 | 2 |
| Marine NEK 606 | 0.6/1kV / Power and Control | 6 | 6 | 6 | 0 | 0 | 0 | 0 | 0 |
| Marine NEK 606 | 0.6/1kV / Instrumentation - Collective | 4 | 4 | 4 | 0 | 0 | 0 | 0 | 0 |
| Marine NEK 606 | 0.6/1kV / Instrumentation - Individual | 4 | 4 | 4 | 0 | 0 | 0 | 0 | 0 |
| Marine NEK 606 | 0.6/1kV / VFD/VSD | 4 | 1 | 1 | 3 | 0 | 0 | 0 | 0 |
| Medium Voltage | (category root) | 5 | 3 | 0 | 2 | 3 | 0 | 0 | 0 |
| Solar | Copper | 2 | 1 | 1 | 1 | 0 | 0 | 0 | 0 |
| Solar | Aluminium | 2 | 0 | 0 | 2 | 0 | 0 | 0 | 0 |
| Steel/Alum Armour | Voltage Rating / 0.6/1kV | 3 | 0 | 0 | 3 | 0 | 0 | 0 | 0 |
| Steel/Alum Armour | Voltage Rating / 110V | 2 | 2 | 2 | 0 | 0 | 0 | 0 | 0 |
| Submersible/Potable | Copper | 3 | 0 | 0 | 3 | 0 | 0 | 0 | 0 |
| Switchboard & Panel | Copper | 4 | 0 | 0 | 4 | 0 | 0 | 0 | 0 |
| Termite Res/Nylon | Low Voltage | 7 | 6 | 6 | 1 | 0 | 0 | 0 | 0 |
| Termite Res/Nylon | Aluminium | 2 | 2 | 2 | 0 | 0 | 0 | 0 | 0 |
| VFD/VSD | Split Earth \| 0.6/1kV | 5 | 4 | 4 | 1 | 0 | 0 | 0 | 0 |
| VFD/VSD | Aluminium | 3 | 3 | 3 | 0 | 0 | 0 | 0 | 0 |
| VFD/VSD | Single Earth \| 0.6/1kV | 2 | 0 | 0 | 2 | 0 | 0 | 0 | 0 |
| Welding/Battery | Copper | 8 | 0 | 0 | 8 | 0 | 0 | 0 | 0 |
| Food & Beverage | (category root) | 3 | 0 | 0 | 3 | 0 | 0 | 0 | 0 |
| Industrial & Marine Switchboard Wire | (category root) | 2 | 1 | 1 | 1 | 0 | 0 | 0 | 0 |
| Ship Loaders & Cranes | (category root) | 5 | 0 | 0 | 5 | 0 | 0 | 0 | 0 |
| Shore Power | (category root) | 1 | 1 | 1 | 0 | 0 | 0 | 0 | 0 |
| Temporary Power | (category root) | 3 | 0 | 0 | 3 | 0 | 0 | 0 | 0 |
| Water Treatment | (category root) | 2 | 0 | 0 | 2 | 0 | 0 | 0 | 0 |
| Fire Resistant | (category root) | 4 | 1 | 1 | 3 | 0 | 0 | 0 | 0 |
| Telecoms | (category root) | 4 | 0 | 0 | 4 | 0 | 0 | 0 | 0 |

## Missing products by category

Each code below has no `InternalCode` on an active product. Codes that appear in several categories are repeated here and stored once in `missing-products.csv`.

### Accessories

- **lugs-and-links > Lugs** (9): B06, B05, B12, B37, B23, B38, B20, B22, B27
- **terminals > Bootlace / Ferrule** (5): A01, A03, A20, A40, A02
- **terminals > Pin & Blade** (2): A11, A21
- **terminals > Quick Connect** (7): A30, A33, A31, A32, A37, A38, A34
- **terminals > Ring & Fork** (5): A05, A25, A07, A27, A08
- **heat-shrink-and-cold-shrink > Cold Shrink** (1): G12
- **heat-shrink-and-cold-shrink > Heat Shrink** (7): G01, G03, G06, G08, G10, G07, G15
- **glands > Brass** (5): D13, D14, D11, D15, D29
- **glands > Nylon** (3): D08, D03, D12
- **glands > Stainless Steel** (1): D19
- **plug-socket > Connectors** (2): J84, J70
- **plug-socket > Industrial** (12): J09, J01, J02, J10, J03, J06, J05, J08, J07, J19, J12, J11
- **plug-socket > CeeForm** (5): J30, J32, J31, J33, J34
- **plug-socket > PowerSure** (16): J50, J52, J55, J66, J54, J56, J57, J58, J59, J60, J61, J51, J65, J53, J62, J64
- **cable-management > Ducts** (3): H01, H06, H02
- **cable-management > Cable Protection / Installation Kits** (6): X07, X08, X12, X13, X09, G11
- **cable-management > Tapes** (3): Y01, Y02, Y03
- **insulators > Circuit Protection** (2): E01, E02
- **insulators > Hardware & Fittings** (5): T02, Z41, T01, A51, A52
- **insulators > Wire Management** (2): H01, H06
- **switches > Push Button** (1): J18
- **switches > Rotary** (1): J11
- **switches > Weatherproof** (2): J16, J17
- **solar-components > Accessories & Wiring** (5): X17, D12, J40, Z14, J42
- **tools-dies > Other Tools** (5): Z41, Z12, Z14, Z15, Z28
- **tools-dies > Cable Preparation** (5): Z40, Z02, Z03, Z04, U01
- **tools-dies > Crimpers & Dies** (11): Z01, Z05, Z30, Z24, Z29, Z36, Z34, Z37, Z38, Z33, Z35
- **safety-security-accessories > Lockout & Tagout** (4): S01, S02, S04, S03
- **safety-security-accessories > Site Safety** (1): Y02
- **pre-assembled_lead > Lug Leads** (3): LL1E, LLA1, PS6
- **pre-assembled_lead > Ceeform Leads** (2): PS3C, PSXA
- **pre-assembled_lead > Earthing & Bonding Leads** (1): EBL
- **Cable Ties** (9): W01, W05, W06, W02, W04, X02, X01, X03, Z28

### Other categories

- **ALI-FLEX System** (8): XL, KL, LS, LP, KA, XA, SK, SX
- **Control** (10): CF, WG, DP, EZ, XL, KL, KA, CC, CA, FR
- **Electrical Contracting** (8): XL, KL, LS, LP, CC, FD, FR, EH
- **Instrumentation** (1): WH
- **Marine NEK 606** (3): NFC, NFM, NUM
- **Medium Voltage** (2): V1, V6
- **Solar** (3): SY, SK, SX
- **Steel/Alum Armour** (3): XA, KA, CA
- **Submersible/Potable** (3): KP, KK, KF
- **Switchboard & Panel** (4): LP, KL, LS, EH
- **Termite Res/Nylon** (1): KDT
- **VFD/VSD** (3): EU, KG, DP
- **Welding/Battery** (8): FP, RL, DL, KB, DW, RW, DC, FW
- **Food & Beverage** (3): FB, FV, F1
- **Industrial & Marine Switchboard Wire** (1): LS
- **Ship Loaders & Cranes** (5): SC, AR, T3, T4, E1
- **Temporary Power** (3): H7, P3, P4
- **Water Treatment** (2): OT, WT
- **Fire Resistant** (3): WG, WH, FR
- **Telecoms** (4): DC, HB, FP, KB

## High-priority gaps

Migrate by unique missing code. Create the product once, then add every category listed for that code.

1. **Accessories — 143 missing records.** This is the largest verified gap. The accessory navigation nodes already exist, including Lugs & Links, Terminals, Glands, Plugs & Sockets, Heat Shrink, Tools & Dies, Cable Ties, Switches, and Safety. The largest empty groups are PowerSure (16), Industrial plugs and sockets (12), Crimpers & Dies (11), Cable Ties (9), and Lugs (9). One accessory on the legacy list is already a draft: B07.
2. **Shared cable codes that unblock several categories.** XL, KL, LS, LP, KA, XA, FR, CC, DP, CA, WH, FP, KB, and DC each appear in more than one legacy category. One product record per code covers every category row in `missing-products.csv`.
3. **Categories whose legacy list is entirely missing:** Welding/Battery (8), Ship Loaders & Cranes (5), Switchboard & Panel (4, overlapping LP, KL, LS, EH), Telecoms (4), Food & Beverage (3), Temporary Power (3), Submersible/Potable (3), Water Treatment (2). Electrical Contracting’s 8 codes are the same shared cable codes, so they are covered by wave 2 rather than a separate product set.
4. **Partial categories, after the shared codes:** Control still needs CA, CC, CF, DP, EZ, FR, KA, KL, WG, XL, while KC is present and C1–C6 are drafts. ALI-FLEX System still needs KA, KL, LP, LS, SK, SX, XA, XL, while XU, KU, XN, KN, and EA are present. Solar still needs SK, SX, SY, while SJ is present. Steel/Alum Armour still needs CA, KA, XA, while R2 and R4 are present. VFD/VSD still needs DP, EU, KG. Fire Resistant still needs FR, WG, WH. Marine NEK 606 still needs NFC, NFM, and NUM. Termite Res/Nylon still needs KDT. Instrumentation still needs WH. Medium Voltage still needs V1 and V6. Industrial & Marine Switchboard Wire still needs LS.

Placement-only work, after the missing records: add published products BV (393), DV (394), and PJ (395) to the Marine IEC LSZH power groups. They currently sit on ALI FLEX Marine. The IEC nodes titled BV, DV, and PJ are already linked to the different products BVA, DVA, and PJA. Adding BV, DV, and PJ is a catalogue decision because those node titles already show the short code.

Publishing is separate from creating products. These 14 drafts already match legacy cards: C1, C2, C3, C4, C5, C6, RH, RD, RF, RG, V2, V3, V4, and B07. They stay hidden on the public AU tree, which this audit confirmed.

## Hold for review before any Marine IEC migration

Sixteen cards stayed unresolved. Fifteen are Marine IEC two-letter codes with more than one product formed by adding A, C, M, P, X, or T. The sixteenth, `h07rn-f`, has no code on the card.

- **slug:h07rn-f** in Instrumentation / 450/750V. The legacy card has no verified product code.
- **RR** in Marine IEC LSZH / Shipboard / Power and Control. Legacy code RR was not equated to suffix records: RRA (published, product 131), RRC (draft, product 350), RRP (published, product 437).
- **BH** in Marine IEC LSZH / Shipboard / Power and Control. Legacy code BH was not equated to suffix records: BHA (published, product 109), BHC (draft, product 284), BHX (draft, product 285), BHP (published, product 424).
- **RC** in Marine IEC LSZH / Shipboard / Instrumentation - Collective. Legacy code RC was not equated to suffix records: RCA (published, product 130), RCC (published, product 343), RCM (draft, product 344), RCP (draft, product 345).
- **RS** in Marine IEC LSZH / Shipboard / Instrumentation - Collective. Legacy code RS was not equated to suffix records: RSA (draft, product 132), RSC (draft, product 351), RSP (draft, product 352).
- **ES** in Marine IEC LSZH / Shipboard / Instrumentation - Collective. Legacy code ES was not equated to suffix records: ESA (draft, product 114), ESC (draft, product 302), ESX (draft, product 303), ESP (published, product 426).
- **FJ** in Marine IEC LSZH / Shipboard / Instrumentation - Collective. Legacy code FJ was not equated to suffix records: FJA (draft, product 118), FJC (draft, product 311), FJP (published, product 427).
- **EW** in Marine IEC LSZH / Shipboard / Instrumentation - Individual and Collective. Legacy code EW was not equated to suffix records: EWA (draft, product 115), EWC (draft, product 304), EWP (draft, product 305).
- **EG** in Marine IEC LSZH / Shipboard / Instrumentation - Individual and Collective. Legacy code EG was not equated to suffix records: EGA (draft, product 113), EGC (draft, product 301), EGP (published, product 425).
- **FC** in Marine IEC LSZH / Shipboard / Instrumentation - Individual and Collective. Legacy code FC was not equated to suffix records: FCA (draft, product 116), FCC (draft, product 306), FCP (draft, product 307).
- **FL** in Marine IEC LSZH / Shipboard / Instrumentation - Individual and Collective. Legacy code FL was not equated to suffix records: FLA (draft, product 120), FLP (published, product 428).
- **HT** in Marine IEC LSZH / Offshore / Power and Control. Legacy code HT was not equated to suffix records: HTA (draft, product 122), HTC (draft, product 317), HTM (draft, product 318), HTP (draft, product 319).
- **RB** in Marine IEC LSZH / Offshore / Instrumentation - Collective. Legacy code RB was not equated to suffix records: RBA (draft, product 129), RBC (published, product 340), RBM (published, product 341), RBP (draft, product 342).
- **FK** in Marine IEC LSZH / Offshore / Instrumentation - Collective. Legacy code FK was not equated to suffix records: FKA (draft, product 119), FKC (published, product 312), FKM (published, product 313), FKP (draft, product 314).
- **FZ** in Marine IEC LSZH / Offshore / Instrumentation - Individual and Collective. Legacy code FZ was not equated to suffix records: FZA (draft, product 121), FZC (published, product 315), FZM (published, product 316).
- **FE** in Marine IEC LSZH / Offshore / Instrumentation - Individual and Collective. Legacy code FE was not equated to suffix records: FEA (draft, product 117), FEC (published, product 308), FEM (published, product 309), FEP (draft, product 310).

BV, DV, and PJ are the three exact-code exceptions. Those product records exist. Their IEC category placement is the gap recorded in `missing-category-placements.csv`.

## Duplicate and ambiguous code warnings

The same legacy code is used on more than one URL. Each code is still one unique product. A later migration should open both URLs before authoring the record.

- **BV** — URLs/slugs: rb-shipboard, ri-shipboard. Categories: Marine IEC LSZH.
- **CA** — URLs/slugs: ca-flexible-lszh-rubber, flexible-lszh-control-ca-0-61kv. Categories: Control, Steel/Alum Armour.
- **CC** — URLs/slugs: flexible-lszh-rubber-cc-2, flexible-lszh-rubber-control. Categories: Control, Electrical Contracting.
- **DP** — URLs/slugs: dp-flexible-rubber-control, flexible-rubber-control. Categories: Control, VFD/VSD.
- **DV** — URLs/slugs: rfob-rfcb-shipboard, rfoi-rfci-shipboard. Categories: Marine IEC LSZH.
- **FR** — URLs/slugs: flexible-lszh, flexible-lszh-control-0-61kv. Categories: Control, Electrical Contracting, Fire Resistant.
- **KA** — URLs/slugs: flexible-lszh-control-ka-0-61kv, ka-flexible-rubber. Categories: ALI-FLEX System, Control, Steel/Alum Armour.
- **KF** — URLs/slugs: kf-flexible-lszh-rubber, od-flexible-rubber-braid. Categories: Submersible/Potable.
- **KL** — URLs/slugs: flexible-kl, flexible-rubber-control-kl-0-61kv, flexible-rubber-kl. Categories: ALI-FLEX System, Control, Electrical Contracting, Switchboard & Panel.
- **LP** — URLs/slugs: flexible-rubber-2, lp-earth-2-2. Categories: ALI-FLEX System, Electrical Contracting, Switchboard & Panel.
- **LS** — URLs/slugs: flexible-rubber-sdi, ls-flexible-rubber-sdi. Categories: ALI-FLEX System, Electrical Contracting, Industrial & Marine Switchboard Wire, Switchboard & Panel.
- **XL** — URLs/slugs: flexible-rubber-control-xl-0-61kv, xl-flexible-rubber-sdi. Categories: ALI-FLEX System, Control, Electrical Contracting.

KF is the sharpest case: `kf-flexible-lszh-rubber` and `od-flexible-rubber-braid` are both coded KF. OD already exists as its own published product. KF has no product record. Those two pages need a human check before KF is created or OD is reused.

BV uses `rb-shipboard` and `ri-shipboard`. DV uses `rfob-rfcb-shipboard` and `rfoi-rfci-shipboard`. Both codes match one published product each, currently placed on ALI FLEX Marine.

Combined Marine NEK URLs were split only on `tricab-{code}` tokens. NJC, NJM, NTC, NTM, NVC, NRC, RBC, RBM, FKC, FKM, FZC, FZM, FEC, FEM, and NUC match existing products and are placed. NFC, NFM, and NUM have no product record. NUM was not merged with NUC or NUP. NFC and NFM were not merged with NTP.

`h07rn-f` under Instrumentation 450/750V has no code on the card and no code token in the URL. It stays unresolved.

## Recommended migration order

1. Accessories, starting with PowerSure, Industrial plugs and sockets, Crimpers & Dies, Cable Ties, and Lugs. The destination nodes already exist, and this wave does not depend on the Marine IEC suffix decision.
2. The shared cable codes (XL, KL, LS, LP, and the other multi-category codes listed above), then the remaining single-category cable gaps. Create each code once and place it in every legacy category on its missing-product row.
3. Marine NEK codes NFC, NFM, and NUM only. The other NEK cards on the legacy homepage are already placed.
4. The three IEC placements for BV, DV, and PJ, only after confirming they should appear beside BVA, DVA, and PJA.
5. Publish the 14 existing drafts when their content is accepted. That wave creates no product records.
6. Marine IEC two-letter families stay held until each legacy code is assigned to one suffix record, or a decision is made to keep the suffix records as separate products.

Products already present in every legacy category where they appear should be left as they are. That includes XU, KU, EA, FH, KN, XN, R2, R4, the placed NEK codes, Shore Power SH, and the accessory codes already on their nodes.

## Evidence and limitations

Sources:

- Legacy AU homepage hierarchy and cable card URLs: the parsed www2.tricab.com catalogue capture, joined to the coded card inventory.
- Accessory URLs: the legacy accessory sitemap where the slug was listed.
- New catalogue: read-only export of products, product slugs, navigation nodes, placements, publication status, and AU visibility from tricab-mel / tc.
- Public check: tricab.ai catalogue tree for Australia with products included.
- Batch A–E population counts and fingerprints from the Batch E after-snapshot.

Matching rules:

- Exact `InternalCode` is the match.
- A combined URL contributes one product per `tricab-{code}` token.
- A card with no code inherits a code only when that same slug already has one coded card.
- Two-letter codes with several one-letter suffix records stay unresolved.
- Titles were kept as evidence and were not used to merge two codes.
- Legacy Insulators → Hardware & Fittings and Wire Management follow the completed Batch C switchboard placements.
- A failed page fetch was not treated as evidence that a product is absent. This audit did not re-download every legacy product page.

Limitations:

- The legacy inventory is the Australia homepage card list plus the accessory sitemap. It is the customer-facing AU catalogue used for the navigation population. It is not a dump of every historical WordPress post.
- Six certificate links inside Submersible, Switchboard, and Welding groups were excluded because they are not product cards.
- `LegacyProductId` equals the internal code for most products. Thirty-five products store a WordPress post id instead. That field was not used to merge different codes.
- Customer-facing product page routes vary by catalogue type. Where a placement exists, the public locator used in the evidence is `https://tricab.ai/api/v1/catalogue/nodes/{nodeSlug}?regionCode=AU`. Stored product slugs are in the inventory export.
- Empty navigation branches stay off the public tree. A draft can be placed and still be hidden. That is why the 14 drafts are absent from the 108 public product codes.

## Files

- `reports/au-catalogue-gap-audit/category-summary.csv`
- `reports/au-catalogue-gap-audit/missing-products.csv`
- `reports/au-catalogue-gap-audit/missing-category-placements.csv`
- `reports/au-catalogue-gap-audit/draft-products.csv`
- `reports/au-catalogue-gap-audit/catalogue-gap-report.md`
- `reports/au-catalogue-gap-audit/audit-evidence.json`

No migration was started.
