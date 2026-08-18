# Legacy OCR Record Dictionary

Status: Working migration specification  
Last reviewed: 2026-08-05  
Scope: `ds/` document definitions and the audited 4JS serializers that produce their input records

## Purpose

This document describes the line-oriented record protocol consumed by the legacy `.ocr` document definitions. It is intended to support:

- validating the current production contract;
- deciding whether a standard PHP document library can replace the renderer;
- designing a shared package for `tricab-core`, `tricab-api`, and other services;
- creating representative compatibility fixtures and regression tests;
- separating active contract elements from historical or unresolved ones.

The dictionary documents the existing wire format. It does not recommend carrying all of its overloaded positional fields into a new public API.

## Evidence and confidence

Meaning in this document is classified as follows:

| Status | Meaning |
|---|---|
| **Confirmed** | Directly established by a current 4JS serializer and/or a consuming `.ocr` template. |
| **Inferred** | Strongly indicated by variable names, calculations, labels, or rendering behaviour, but not explicitly named as a contract. |
| **Unresolved** | No active producer was found, producer and consumer disagree, or business confirmation is still required. |

When sources disagree, the active 4JS serializer is treated as the best description of what is emitted, while the `.ocr` template is treated as the best description of what is currently consumed.

## Primary sources

| Source | Use |
|---|---|
| `D:\TriCab\document-generator\ds\` | Record consumers, rendering rules, conditional behaviour, and layout. |
| `D:\TriCab\side-projects\4js-main\src\modules\M010ppni.4gl` | Order, invoice, pick-list, packing-note, and stock-receipt serializers. |
| `D:\TriCab\side-projects\4js-main\src\modules\M112qotpub.4gl` | Quotation serializer. |
| `D:\TriCab\side-projects\4js-main\src\modules\M053dbrp.4gl` | Debtor-statement serializer. |
| `D:\TriCab\side-projects\4js-main\src\modules\M072ocr.4gl` | Record identifiers and formatting helpers. |
| `D:\TriCab\side-projects\4js-main\src\modules\M054sys.4gl` | Variable identifiers, document-type codes, and event codes. |
| `D:\TriCab\side-projects\4js-main\audit-docs\` | Supporting flow documentation. |
| `D:\TriCab\document-generator\examples\` | Captured streams paired with rendered order-confirmation, pick-list, and quotation PDFs. |

## Validated example fixtures

The following examples were inspected as UTF-8 streams and visually rendered PDFs on 2026-08-05.

| Fixture | Stream | PDF | Evidence status |
|---|---|---|---|
| Order confirmation | `examples/tcord.ocr.txt` | `examples/tcord.pdf` | Same business document. The publication time/user differs, so this is not the exact same rendering event. |
| Pick list | `examples/tcpick.ocr.txt` | `examples/tcpick.pdf` | Same business document. The publication time differs, so this is not the exact same rendering event. |
| Quotation | `examples/tcquo.ocr.txt` | `examples/tcquo.pdf` | Content and publication data align as an exact or near-exact pair. |

All three PDFs are one-page A4 documents produced by **Print Control Report Writer / PDFlib 3.02**. The captured stream files are valid UTF-8 without a byte-order mark and use CRLF line endings. These observations describe the supplied files and are not yet proof that production requires those exact encoding or newline choices.

### Confirmed by these fixtures

- The stream begins with a `.RET.` template-selection directive before the first `.RER.` record.
- V identifiers are serialized in short decimal form, such as `.RER.V4203`, not zero-padded to eight visible digits.
- S006 and S009 field counts and document-specific variants match the audited serializers.
- Company code `7` represents **TriCab (Australia) Pty. Ltd** in the supplied examples, with the office address supplied separately by S007.
- Order S016/6301 supplies the entered event, while S018/6308 supplies the publication event.
- Pick S006 fields 21–25 visibly control the reference suffix, credit-hold warning, manufacturer column, and early-delivery warning behaviour.
- Item-level V4203 records immediately following S009 render as line comments; their label is visible when nonblank.
- Quote S026 drives copper/aluminium market headings, units, and displayed USD/AUD prices.
- Quote S006 field 1 is the internal ID while field 2 is the customer-visible quote reference.
- The current quotation template does not render the supplied V4270 body text; the record causes the spool-note/table routine to run instead.

### Fixture integrity warning

The final S009 record in `tcord.ocr.txt` declares 18 fields but the captured file ends after field 14. The four trailing fields are blank in the serializer and appear to have been trimmed while the example was copied or saved. Treat this as an incomplete capture, not evidence that production accepts short fixed records.

## Wire-format conventions

### Template-selection directive

Captured streams begin with a line such as:

```text
.RET./l/ocr/ps//tcord.ocr
```

The text after `.RET.` selects the deployed `.ocr` definition. The three supplied examples select `tcord.ocr`, `tcpick.ocr`, and `tcqu.ocr`. A replacement can map this directive to a known document renderer, but should reject or quarantine unexpected paths rather than treating them as arbitrary filesystem input.

### Fixed records

A fixed record is emitted as:

```text
.RER.S006
26
<field 1>
<field 2>
...
<field 26>
```

The first line is the record identifier, the second line is the field count, and exactly that many field lines should follow. A strict parser should enforce this. Diagnostic tooling may optionally pad missing trailing fields only to inspect damaged captures, as required by the supplied order example.

### Variable text records

A variable text record is emitted as `.RER.V` plus the decimal variable identifier, for example:

```text
.RER.V4270
<field count>
<document id>
<item number>
<label>
<text line 1>
...
```

### Indexing

The 4JS serializer comments and this dictionary's **Wire** column are one-based. The `.ocr` arrays are zero-based.

```text
wire field 1 == s006[0]
wire field 2 == s006[1]
```

### Scalar formatting

| Value | Legacy representation |
|---|---|
| Date | `dd/mm/yy` unless a producer supplies an already formatted string. |
| Time | `hh:mm`. |
| Boolean | Usually `Y`/`N`; some fields also use blank for false/not applicable. |
| Money | A mixture of raw numeric strings and preformatted display strings. Preserve exact source output for compatibility tests. |
| Identifier | Usually a decimal string, even when it is numeric in the database. |
| Null | Generally serialized as an empty line, but blank, a single space, and missing data may behave differently in templates. |
| Text encoding | Supplied captures are UTF-8 without BOM. Preserve Unicode characters such as `²` and `Ø`. Production requirement still needs confirmation. |
| Line ending | Supplied captures use CRLF. The protocol is line-oriented; readers should safely accept CRLF and LF while preserving field content. |

### Blank-value warning

Do not normalize empty strings, one-space strings, nulls, or omitted fields until fixtures prove they are equivalent. Some template conditions compare literal values, and some fields are deliberately populated with a single space.

## Protocol catalogue

| Record | Working name | Current use | Status |
|---|---|---|---|
| S001 | Standalone address/barcode form | `F001` | Unresolved producer |
| S006 | Document header | Most current templates | Confirmed, document-specific variants |
| S007 | Company address | Most current templates | Confirmed |
| S008 | Customer address | Most current templates | Confirmed |
| S009 | Document item | Order, invoice, quote, pick, packing, stock receipt | Confirmed, document-specific variants |
| S010 | Document control | Generated handler exists | Unresolved/possibly obsolete |
| S011 | Sales operator/contact | Quote | Confirmed |
| S012 | Customer contact | Most current templates | Confirmed, document-specific variants |
| S013 | Delivery-to address | Order and warehouse documents | Confirmed |
| S014 | Supplemental item | No active producer/consumer found | Unresolved/possibly obsolete |
| S015 | Despatch details | Order, invoice, warehouse documents | Confirmed variants |
| S016 | Primary item/document event | Current templates | Confirmed |
| S017 | Debtor detail | No active producer/consumer found | Unresolved/possibly obsolete |
| S018 | Secondary/current event | Current templates | Confirmed |
| S019 | Tax item | Tax-bearing documents | Confirmed |
| S020 | Document/order contact | Order and warehouse document footers | Confirmed |
| S021 | Statement trailer | Statement | Confirmed |
| S022 | Statement customer header | Statement | Confirmed |
| S023 | Statement invoice item | Statement | Confirmed |
| S024 | Statement row labels | Statement | Confirmed |
| S025 | Statement row values | Statement | Confirmed |
| S026 | Quote metal-price extension | Quote | Confirmed; emitted manually |
| V4202 | Special delivery instructions | Document/item text | Confirmed identifier |
| V4203 | Comments | Document/item text | Confirmed identifier |
| V4204 | Customer/product print text | Document/item text | Confirmed; checksum variant exists |
| V4238 | Processing instructions | Document/item text | Confirmed identifier |
| V4270 | Document notes | Document-level text | Confirmed identifier |
| V4276 | Payment-method description | Document-level text | Confirmed identifier |
| V4281 | Packing instructions | Document/item text | Confirmed identifier |
| V4293 | Payment-terms description | Document-level text | Confirmed identifier |
| V9241 | Cable print-line instructions | Pick output from newer 4JS | Producer confirmed; consumer unresolved |
| V9245 | Warehouse/bin location | Item-level text | Confirmed identifier |
| Named PO protocol | `sup`, `info`, `inv`, `dlv`, `acnt`, `record`, `header` | `tcpo.ocr` | Unresolved/possibly historical |

## S006 — document header

S006 is not one stable schema. It is a family of document-specific positional records whose later fields are heavily overloaded.

### Variants

| Document | Field count | Distinguishing fields |
|---|---:|---|
| Order confirmation | 20 | Field 4 publication/report time; field 17 order-created date; field 19 due date; field 20 sales office. |
| Quotation | 21 | Field 4 quote time; field 17 valid-until date; field 20 copy/draft marker; field 21 sales office. |
| Invoice | 23 | Field 4 outstanding/due amount; tax-inclusive flag `Y`; Incoterm; copy marker; suffix/check digit; production warehouse; WIP/database state. |
| Pick list / packing note / stock receipt | 26 | Due date, copy marker, suffix/check digit, credit hold, PR batch, manufacturer-display flag, early-delivery flag, production warehouse. |
| Debtor statement | 17 | Account-office identifier, account/customer data, payment terms, and AP email. Total is subsequently supplied by S021. |

### Field dictionary

| Wire | `.ocr` | Working meaning | Variants and source behaviour | Type/format | Status |
|---:|---:|---|---|---|---|
| 1 | `[0]` | Primary document identifier | Quote/order/invoice/document ID. Statement uses account-office ID. | Integer-like string | Confirmed |
| 2 | `[1]` | Display reference | Usually document reference; quote may use its display reference. Statement emits blank and the geometry code defaults it from field 1. | String | Confirmed |
| 3 | `[2]` | Document date | Document date or today's date for statement. | `dd/mm/yy` | Confirmed |
| 4 | `[3]` | Time or monetary status | Order/quote: time. Invoice: outstanding/due amount. Generic warehouse documents and statement: blank. | `hh:mm`, money, or blank | Confirmed; overloaded |
| 5 | `[4]` | Company/legal-entity code | `cod.cus`; templates use literal company codes for branding and legal text. | Integer-like code | Confirmed value; entity meanings unresolved |
| 6 | `[5]` | Company registration/ACN | Legal registration value printed in company header/footer. | String | Confirmed |
| 7 | `[6]` | VAT/ABN/tax registration | Tax registration value. | String | Confirmed |
| 8 | `[7]` | Entry/sales operator login or alpha | Pick renders this directly as **Entered By**. Order carries the entering operator login but renders the full name from S016 when available. Quote carries the sales-operator alpha while S011 supplies the visible name/contact. | String | Confirmed role; exact `opa` source definition unresolved |
| 9 | `[8]` | Customer/account identifier | Numeric customer/debtor ID. | Integer-like string | Confirmed |
| 10 | `[9]` | Customer account code | External or mnemonic account code. | String | Confirmed |
| 11 | `[10]` | Customer purchase order/reference | Customer-provided reference. Blank where not applicable. | String | Confirmed |
| 12 | `[11]` | Currency | Document currency code. | Usually ISO-style code | Confirmed |
| 13 | `[12]` | Formatted net/document total | Preformatted total for most documents. Statement initially blank and receives its displayed total from S021. | Display money string | Confirmed |
| 14 | `[13]` | Payment-terms code | Internal payment-terms identifier/code. | String/code | Confirmed |
| 15 | `[14]` | Payment-terms name | Human-readable payment-terms name. Blank in statement. | String | Confirmed |
| 16 | `[15]` | Payment-terms supplemental text | Medium/extra description. Blank in statement. | String | Confirmed |
| 17 | `[16]` | Date or AP email | Order: order-created date. Quote: valid-until date. Statement: accounts-payable email. | Date, email, or blank | Confirmed; overloaded |
| 18 | `[17]` | Tax-inclusive flag | Invoice emits `Y`; warehouse/generic documents emit `N`; quote/order use document-specific or blank behaviour. | `Y`, `N`, or blank | Confirmed |
| 19 | `[18]` | Due date or Incoterm | Order/generic: requested/delivery due date. Invoice: Incoterm. Quote: blank. | Date, code/string, or blank | Confirmed; overloaded |
| 20 | `[19]` | Copy/draft marker or sales office | Quote/invoice/generic: copy status. Order: sales-office identifier. | Enum/code/string | Confirmed; overloaded |
| 21 | `[20]` | Document suffix/check digit or quote office | Invoice/generic: suffix/check digit. Quote: office. | String/code | Confirmed; overloaded |
| 22 | `[21]` | Credit hold or production warehouse | Warehouse documents: `Y` prints a prominent **CREDIT HOLD** footer. Invoice: production-warehouse ID. | Boolean/code | Confirmed; overloaded |
| 23 | `[22]` | PR batch or source state | Warehouse documents: production-run batch. Invoice: WIP/database source status. | String/code | Confirmed; overloaded |
| 24 | `[23]` | Manufacturer-code display flag | On the supplied pick list, `Y` adds the **Manuf** column and renders S009 field 15. Other warehouse-template meanings still need review. | `Y`/other | Confirmed for pick |
| 25 | `[24]` | Early-delivery permission | On pick output, `N` prints **NO EARLIER**; `Y` suppresses the warning. | `Y`/`N` | Confirmed for pick |
| 26 | `[25]` | Production warehouse | Production-warehouse identifier for generic warehouse documents. | Code/string | Confirmed |

### Renderer mutations and fallbacks

- Statement rendering may copy field 1 into blank field 2.
- Statement totals are supplied later by S021 rather than being complete in S006.
- Company code values control branding and legal text. Literal codes `7`, `16`, `19`, and `20` were found and require a maintained entity lookup.
- Copy/draft markers affect visible watermarks or labels rather than merely carrying metadata.
- Company code `7` is confirmed as TriCab (Australia) Pty. Ltd in the supplied order, pick, and quotation examples. S007 selects the displayed office address independently.
- The generic-document suffix in field 21 is appended to field 1 with a hyphen in pick-list titles and footer references.
- Publication timestamps belong to the publication event. The order and pick PDF examples were generated in later publication events than their supplied streams, so their action time/user should not be compared as a single transaction.

### Copy/draft values

| Value | Meaning | Status |
|---|---|---|
| `D` | Draft | Confirmed in quote flow |
| `C` | Copy | Confirmed in quote flow |
| `Y` | Copy | Confirmed alternative in quote flow |
| `N` | Normal/original | Confirmed in quote flow |
| blank | Normal/original | Confirmed in quote flow |

## S007 — company address

| Wire | `.ocr` | Meaning | Type/format | Status |
|---:|---:|---|---|---|
| 1 | `[0]` | Parent document ID | Integer-like string | Confirmed |
| 2 | `[1]` | Company/legal-entity name | String | Confirmed |
| 3–7 | `[2]`–`[6]` | Company address lines | Preformatted line strings | Confirmed |
| 8 | `[7]` | Sixth address line or sales-office identifier, depending on producer | String/code | Confirmed; overloaded |

Field counts vary: order, quote, and generic document serializers emit 8 fields; invoice and statement serializers emit 7. Address lines are already presentation-oriented and should eventually be replaced with a structured address plus a compatibility formatter.

## S008 — customer address

| Wire | `.ocr` | Meaning | Type/format | Status |
|---:|---:|---|---|---|
| 1 | `[0]` | Parent document ID | Integer-like string | Confirmed |
| 2 | `[1]` | Customer/account name | String | Confirmed |
| 3–7 | `[2]`–`[6]` | Customer address lines | Preformatted line strings | Confirmed |

The five address slots may be blank individually. Their source-level postal components and joining rules still need to be documented.

## S009 — document item

Like S006, S009 is a family of schemas rather than a single stable item record.

### Variants

| Document | Field count | Distinguishing fields |
|---|---:|---|
| Order confirmation | 18 | Formatted net at field 10; pack quantity/size, warehouse, and due date at fields 11–14. |
| Invoice | 15 | Formatted net at field 15. |
| Pick list / packing note / stock receipt | 18 | Manufacturer stock code, substitution flag, formatted net, and bend radius at fields 15–18. |
| Quotation | 19 | Technical specification, bend radius, product class, formatted total, duplicate bend radius, and pack size. |

### Field dictionary

| Wire | `.ocr` | Working meaning | Variants and source behaviour | Type/format | Status |
|---:|---:|---|---|---|---|
| 1 | `[0]` | Parent document ID | All variants. | Integer-like string | Confirmed |
| 2 | `[1]` | Item/line number | All variants; used to associate V records. | Integer-like string | Confirmed |
| 3 | `[2]` | Product code | Stock/product identifier. | String | Confirmed |
| 4 | `[3]` | Colour | Product colour or blank. | String | Confirmed |
| 5 | `[4]` | Quantity | Ordered, invoiced, picked, packed, or received quantity according to document. | Numeric display string | Confirmed |
| 6 | `[5]` | Unit | Unit of measure. | Code/string | Confirmed |
| 7 | `[6]` | Unit/net price | Unit price shown by the document. | Numeric/display money string | Confirmed |
| 8 | `[7]` | Raw line net | Unformatted or differently formatted line net used by calculations/display. | Numeric string | Confirmed |
| 9 | `[8]` | Catalogue/product description | Primary line description. | String | Confirmed |
| 10 | `[9]` | Formatted total or technical specification | Order: formatted line net. Quote: technical specification. Invoice/generic: blank. | Money/string/blank | Confirmed; overloaded |
| 11 | `[10]` | Pack quantity or bend radius | Order/invoice/generic: pack quantity. Quote: bend radius. | Numeric/string | Confirmed; overloaded |
| 12 | `[11]` | Pack size or product class | Order/invoice/generic: pack size. Quote: product class. | Numeric/string/code | Confirmed; overloaded |
| 13 | `[12]` | Warehouse or formatted total | Order/invoice/generic: warehouse. Quote: formatted line total. | Code or display money | Confirmed; overloaded |
| 14 | `[13]` | Due date | Order/invoice/generic. Quote leaves blank. | `dd/mm/yy` or blank | Confirmed |
| 15 | `[14]` | Formatted invoice total or manufacturer stock code | Invoice: formatted line net. Generic warehouse documents: manufacturer stock code. Order/quote: blank. | Money/string/blank | Confirmed; overloaded |
| 16 | `[15]` | Stock substitution flag | Generic warehouse documents only. | Boolean-like | Confirmed |
| 17 | `[16]` | Formatted total | Generic warehouse documents only. | Display money string | Confirmed |
| 18 | `[17]` | Bend radius | Generic warehouse documents; quote repeats bend radius here. | Numeric/string | Confirmed |
| 19 | `[18]` | Pack size | Quote only. | Numeric/string | Confirmed |

### Confirmed rendering behaviour

- Order confirmation renders field 9 as the description, fields 3–4 as product code/colour, field 13 as warehouse, fields 11–12 as `pack quantity X pack size`, fields 5–7 as quantity/unit/unit price, field 10 as total, and field 14 as a long-form estimated-delivery date.
- Pick list renders field 15 as the manufacturer stock code only when S006 field 24 is `Y`.
- The current pick template does not visibly render S009 fields 16–18, although the serializer supplies them.
- Quotation renders field 13 as the displayed total. The supplied quote carries pack size in field 19, but the current `tcqu.ocr` item routine does not display that field separately.

### Known issue: MOQ quotation

`tcqu.moq.ocr` prints S009 field 7 (`s009[6]`) for both **Unit Price** and **MoQ Price**. No separate MOQ-price field was identified in the serializer. This may be intentional, an old layout workaround, or a defect; it must be resolved using a raw stream and matching production output.

## S010 — document control

An S010 handler or generated definition exists, but no active producer/use was confirmed in the audited paths.

Status: **Unresolved/possibly obsolete**.

Required action: identify an actual raw stream containing S010 or explicitly retire it from the replacement contract.

## S011 — sales operator/contact

Observed in quotation output.

| Wire | `.ocr` | Meaning | Type/format | Status |
|---:|---:|---|---|---|
| 1 | `[0]` | Parent document ID | Integer-like string | Confirmed |
| 2 | `[1]` | Sales-contact name | String | Confirmed |
| 3 | `[2]` | Email | Email string | Confirmed |
| 4 | `[3]` | Mobile | Phone string | Confirmed |

## S012 — customer contact

| Wire | `.ocr` | Working meaning | Variant behaviour | Status |
|---:|---:|---|---|---|
| 1 | `[0]` | Parent document ID | All variants | Confirmed |
| 2 | `[1]` | Contact/attention display | May contain a name alone or a preformatted name-and-phone value; the pick fixture renders it verbatim after **Attn:**. | Confirmed |
| 3 | `[2]` | Phone or AP email | Quote: phone. Invoice: AP email only for email delivery. Order/generic/statement: usually blank. | Confirmed; overloaded |
| 4 | `[3]` | Mobile/reserved | Quote supplies a blank mobile slot; other variants generally blank. | Confirmed |
| 5 | `[4]` | Fax/effective AP fax | Quote/order/generic/statement: fax. Invoice: effective accounts-payable fax. | Confirmed |
| 6 | `[5]` | Email | Quote/order six-field variants. | Confirmed |

Quote and order variants emit 6 fields. Invoice, generic document, and statement variants emit 5 fields.

## S013 — delivery-to address

| Wire | `.ocr` | Meaning | Type/format | Status |
|---:|---:|---|---|---|
| 1 | `[0]` | Parent document ID | Integer-like string | Confirmed |
| 2 | `[1]` | Delivery name | String | Confirmed |
| 3–7 | `[2]`–`[6]` | Delivery address lines | Preformatted line strings | Confirmed |
| 8 | `[7]` | Alternate delivery-account code | Populated when different from the ordering account. | Confirmed |

## S014 — supplemental item

The protocol identifier is defined as `docitmsup`, but no active producer or current consumer was found.

Status: **Unresolved/possibly obsolete**.

Required action: search retained production samples and archived applications before excluding it from the compatibility layer.

## S015 — despatch details

| Wire | `.ocr` | Meaning | Type/format | Status |
|---:|---:|---|---|---|
| 1 | `[0]` | Parent document ID | Integer-like string | Confirmed |
| 2 | `[1]` | Carrier/delivery means | String | Confirmed |
| 3 | `[2]` | Consignment note | String | Confirmed |
| 4 | `[3]` | Dispatch date | Date string | Confirmed |
| 5 | `[4]` | Weight | Numeric display string | Confirmed |
| 6 | `[5]` | Weight unit | Unit code/string | Confirmed |
| 7 | `[6]` | Shipment reference | String | Confirmed |
| 8 | `[7]` | Shipment date | Date string | Confirmed |
| 9 | `[8]` | Tailgate flag | Boolean-like value | Confirmed |

Variant counts: order emits only fields 1–2; invoice emits fields 1–8; pick/packing/stock-style generic documents emit all 9 fields.

## S016 and S018 — document/item events

S016 and S018 share an event shape, but their roles are template-specific rather than universally “primary” and “secondary.” Some producers emit only the first five fields.

- In the supplied order stream, S016/6301 is the entered event and S018/6308 is the publication/print event.
- In the supplied pick stream, the five-field S016 selects the publication label while the full S018 supplies the displayed publication date/time.

| Wire | `.ocr` | Meaning | Type/format | Status |
|---:|---:|---|---|---|
| 1 | `[0]` | Parent document ID | Integer-like string | Confirmed |
| 2 | `[1]` | Item number | Usually `0` for a document-level event. | Integer-like string | Confirmed |
| 3 | `[2]` | Event code | See event-code table below. | Integer code | Confirmed |
| 4 | `[3]` | Event date | `dd/mm/yy` | Confirmed |
| 5 | `[4]` | Event time | `hh:mm` | Confirmed |
| 6 | `[5]` | User ID | String/code | Confirmed |
| 7 | `[6]` | User/contact name | String | Confirmed |
| 8 | `[7]` | Phone | Phone string | Confirmed |
| 9 | `[8]` | Mobile | Phone string | Confirmed |
| 10 | `[9]` | Fax | Phone string | Confirmed |
| 11 | `[10]` | Email | Email string | Confirmed |

### Event codes

| Code | Meaning |
|---:|---|
| 6301 | Added/entered |
| 6302 | Changed |
| 6303 | Deleted |
| 6304 | Faxed |
| 6305 | Emailed |
| 6306 | Follow-up |
| 6307 | Cancelled |
| 6308 | Printed |
| 6309 | Sent |
| 6310 | Confirmed |
| 6311 | Received |
| 6312 | Collected |
| 6313 | Delivered |
| 6314 | Published |

## S017 — debtor detail

The protocol identifier is defined as `dbdtl`, but no active producer or current consumer was found.

Status: **Unresolved/possibly obsolete**.

It may have been superseded by the statement-specific S021–S025 record group.

## S019 — tax item

| Wire | `.ocr` | Meaning | Type/format | Status |
|---:|---:|---|---|---|
| 1 | `[0]` | Parent document ID | Integer-like string | Confirmed |
| 2 | `[1]` | Tax line/item number | Integer-like string | Confirmed |
| 3 | `[2]` | Tax code/product | Tax identifier or product representing the tax line. | Confirmed |
| 4 | `[3]` | Tax/category description | Human-readable label. | Confirmed |
| 5 | `[4]` | Taxable base/subtotal | Numeric/display money string. | Confirmed |
| 6 | `[5]` | Tax rate or item price | Producer-dependent numeric value. | Confirmed value; semantic distinction needs fixture validation |
| 7 | `[6]` | Raw tax amount | Numeric string. | Confirmed |
| 8 | `[7]` | Formatted tax amount | Display money string. | Confirmed |

Do not merge fields 7 and 8 until exact rounding and currency formatting rules are reproduced in tests.

## S020 — document/order contact

| Wire | `.ocr` | Meaning | Type/format | Status |
|---:|---:|---|---|---|
| 1 | `[0]` | Parent document ID | Integer-like string | Confirmed |
| 2 | `[1]` | Customer order/contact display | Order and warehouse document footers; may include a preformatted phone number. | Confirmed |

## Statement record group: S021–S025

These records form a stateful group. Their order and grouping by account/customer matter.

### S021 — statement trailer/summary

| Wire | `.ocr` | Meaning | Type/format | Status |
|---:|---:|---|---|---|
| 1 | `[0]` | Account/customer ID | Integer-like string | Confirmed |
| 2 | `[1]` | Total outstanding | Display money string | Confirmed |
| 3 | `[2]` | Invoice count | Integer-like string | Confirmed |

The renderer may use field 2 to complete or replace the initially blank S006 total.

### S022 — statement customer header

| Wire | `.ocr` | Meaning | Type/format | Status |
|---:|---:|---|---|---|
| 1 | `[0]` | Account-office ID | Integer-like string | Confirmed |
| 2 | `[1]` | Customer ID | Integer-like string | Confirmed |
| 3 | `[2]` | Account code | String | Confirmed |
| 4 | `[3]` | Customer name | String | Confirmed |
| 5 | `[4]` | Branch/suburb | String | Confirmed |

### S023 — statement invoice item

| Wire | `.ocr` | Meaning | Type/format | Status |
|---:|---:|---|---|---|
| 1 | `[0]` | Account-office ID | Integer-like string | Confirmed |
| 2 | `[1]` | Invoice/reference | String | Confirmed |
| 3 | `[2]` | Duplicate/display reference | Often duplicates field 2. | Confirmed value; reason unresolved |
| 4 | `[3]` | Invoice date | Date string | Confirmed |
| 5 | `[4]` | Customer order/reference | String | Confirmed |
| 6 | `[5]` | Currency | Currency code | Confirmed |
| 7 | `[6]` | Original invoice amount | Display money string | Confirmed |
| 8 | `[7]` | Outstanding amount | Display money string | Confirmed |
| 9 | `[8]` | Age | Age bucket/day count representation | Confirmed value; exact unit needs validation |
| 10 | `[9]` | Payment-terms override | String/code | Confirmed |

### S024 — statement row labels

| Wire | `.ocr` | Meaning | Type/format | Status |
|---:|---:|---|---|---|
| 1 | `[0]` | Group/customer ID | Integer-like string | Confirmed |
| 2 | `[1]` | Row number | Integer-like string | Confirmed |
| 3 | `[2]` | Row label | String | Confirmed |
| 4+ | `[3+]` | Ageing-column labels | Strings | Confirmed |

### S025 — statement row values

| Wire | `.ocr` | Meaning | Type/format | Status |
|---:|---:|---|---|---|
| 1 | `[0]` | Group/customer ID | Integer-like string | Confirmed |
| 2 | `[1]` | Row number | Integer-like string | Confirmed |
| 3 | `[2]` | Row label/key | String | Confirmed |
| 4+ | `[3+]` | Ageing-column values | Numeric/display money strings | Confirmed |

## S026 — quotation metal prices

S026 is emitted manually by the quotation path rather than through the normal fixed-record helper.

| Wire | `.ocr` | Meaning | Type/format | Status |
|---:|---:|---|---|---|
| 1 | `[0]` | Quote ID | Integer-like string | Confirmed |
| 2 | `[1]` | Copper price in USD | Numeric/display price | Confirmed |
| 3 | `[2]` | Copper price in AUD | Numeric/display price | Confirmed |
| 4 | `[3]` | Pricing unit | `TONN` or `LBS` | Confirmed |
| 5 | `[4]` | Market source | `LME` or `COMEX` | Confirmed |
| 6 | `[5]` | Aluminium price in USD | Numeric/display price | Confirmed |
| 7 | `[6]` | Aluminium price in AUD | Numeric/display price | Confirmed |

## Variable text records

### Common shape

Unless stated otherwise, V records use:

| Wire | Meaning | Notes |
|---:|---|---|
| 1 | Parent document ID | Links text to the current document. |
| 2 | Item number | `0` means document-level; a positive line number attaches text to an item. |
| 3 | Label | Heading or semantic label supplied by the producer. May be blank. |
| 4+ | Text lines | One or more lines, with original line boundaries significant to layout. |

V records generally follow the document or S009 item to which they belong. A replacement parser should preserve their order, label, line breaks, and item association. The supplied pick and quotation examples confirm that item-level V4203 records render immediately below the corresponding S009 line. A nonblank label such as `Print_prms` is rendered visibly; blank labels produce unheaded text.

### Identifier dictionary

| Record | 4JS field/name | Meaning | Scope and special behaviour | Status |
|---|---|---|---|---|
| V4202 | `spdf.sinst` | Special delivery instructions | Document- or item-associated multiline text. | Confirmed |
| V4203 | `spdf.com` | Comments | Item-level display is confirmed by pick and quotation pairs. Label is visible when nonblank. | Confirmed by fixture |
| V4204 | `spdf.ptx` | Customer/product print text | Generic document variant may carry an additional checksum in field 5. | Confirmed; checksum semantics need documentation |
| V4238 | `spdf.pinst` | Processing instructions | Multiline processing/manufacturing text. | Confirmed |
| V4270 | `spdf.note` | Document notes / quotation spool-note trigger | Quote emits it at stream end. Current `tcqu.ocr` discards the supplied body text and invokes `spoolnote()` instead. Other templates can render V4270 normally. | Producer and quote behaviour confirmed |
| V4276 | `spdf.paym` | Payment-method description | Document-level display text. | Confirmed |
| V4281 | `spdf.pkinst` | Packing instructions | Document- or item-associated multiline text. | Confirmed |
| V4293 | `spdf.ptmds` | Payment-terms description | Order fixture supplies document-level terms. The order template also constructs a payment-terms comment from S006 field 15, so terms may appear twice in different layout regions. | Confirmed by fixture |
| V9241 | `spdf.cplinst` | Cable print-line instructions | Emitted by newer 4JS pick logic, but no handler exists in the current `tcpick.ocr`. | Producer confirmed; consumer unresolved |
| V9245 | `spdf.location` | Warehouse/bin location | Four-field form: document ID, item, label, location. | Confirmed |

### V4270 quotation behaviour

The supplied quotation ends with a six-field V4270 containing a `NOTES` label and three body lines. None of those lines appear in the paired PDF. In `tcqu.ocr`, the normal `comment(rec, ...)` calls are disabled and the handler invokes `spoolnote()` instead. Therefore a faithful legacy renderer must distinguish between:

- the semantic note content carried by V4270; and
- the current quotation layout side effect, which uses the record as an end-of-document spool-note/table trigger.

The replacement should retain the note content even if a compatibility renderer intentionally reproduces the legacy omission.

### V4204 checksum variant

At least one generic-document path can emit:

```text
document id
item number
label
print text
checksum
```

The checksum's algorithm, display purpose, and required compatibility are unresolved.

### V9241 compatibility gap

The newer 4JS producer emits cable print-line instructions for pick documents, but the audited `tcpick.ocr` does not define a V9241 consumer. Determine whether production has a newer template, silently ignores the record, or loses this information.

## S001 / F001 — standalone address and barcode form

`F001` consumes an S001-like array with positions 1–14 (zero-based indexes 0–13), but no current producer was located.

| Wire | `.ocr` | Known use | Status |
|---:|---:|---|---|
| 1 | `[0]` | Present but exact meaning not confirmed | Unresolved |
| 2 | `[1]` | Barcode value | Inferred from renderer |
| 3–6 | `[2]`–`[5]` | Address/form content | Unresolved |
| 7–10 | `[6]`–`[9]` | Candidate lines selected/emphasized by template logic | Inferred |
| 11 | `[10]` | Long field that may be split by the template | Inferred |
| 12–14 | `[11]`–`[13]` | Additional address/content lines | Inferred |

Status: **Unresolved producer and business purpose**. Preserve only if a production caller or retained sample proves it is active.

## Legacy named purchase-order protocol (`tcpo.ocr`)

Unlike the S/V protocol, `tcpo.ocr` uses named record blocks. No matching active producer was found in the audited paths.

### `sup` — supplier

| Index | Meaning |
|---:|---|
| 0 | Supplier name |
| 1–4 | Supplier address lines |
| 5 | Attention/contact |
| 6 | Phone |
| 7 | Fax |

### `info` — purchase-order information

| Index | Meaning |
|---:|---|
| 0 | Purchase-order number |
| 1 | Order date |
| 2 | Required-by date |
| 3 | Delivery means |
| 4 | Currency |
| 5 | Entity/branding code such as `qld`, `nz`, `sas`, or default |
| 6 | Unused/reserved |
| 7 | `tcus` marker; exact meaning unresolved |

### `inv` — invoice address

Indexes 0–4 are invoice-address lines.

### `dlv` — delivery address

Indexes 0–4 are delivery-address lines.

### `acnt` — accounting and approval

| Index | Meaning |
|---:|---|
| 0 | Payment terms |
| 1 | Unused/reserved |
| 2 | GST/tax amount |
| 3 | Total |
| 4 | Generated by |
| 5 | Reviewed by |
| 6 | Authorized by |
| 7 | Account allocation |

### `record` — purchase-order item

| Index | Meaning |
|---:|---|
| 0 | Item number |
| 1 | Description |
| 2 | Quantity |
| 3 | Unit |
| 4 | Unit price |
| 5 | Total |
| 6 | Additional description |

### `header` — dynamic sections

The header contains a count followed by dynamic section names that dispatch to the named blocks above. This protocol must be treated as separate from S/V parsing until a producer is found.

Status: **Unresolved/possibly historical**.

## Document-type codes

| Code | Document |
|---:|---|
| 1806 | Order OCR |
| 1814 | Invoice OCR |
| 1821 | Quotation OCR |
| 1822 | Pick-list OCR |
| 1823 | Packing-note OCR |
| 1824 | Stock-receipt OCR |
| 1825 | Debtor-statement OCR |

## Record order and multiplicity

The stream is stateful. The following is a working lifecycle model, not yet a grammar that should reject all other orders.

| Record/group | Typical multiplicity | Ordering dependency |
|---|---|---|
| `.RET.` selector | One at stream start | Selects the deployed document definition before any records are consumed. |
| S006 | One per rendered document/account group | Establishes document-level state. |
| S007, S008, S011–S013, S015, S020 | Zero or one per relevant document | Normally after S006 and before or around item rendering. |
| S009 | Zero or many | Starts/updates current item state. The first S009 can trigger headings and address rendering. |
| Item-level V records | Zero or many per S009 | Normally follow the S009 whose document/item identifiers they repeat. |
| S016, S018 | Zero or many | May be document- or item-related; short and full forms exist. |
| S019 | Zero or many | Tax lines normally appear with totals. |
| S021–S025 | Statement-specific grouped records | Group order matters; S021 supplies summary/trailer data. |
| V4270 quote notes | Zero or one observed record with multiple lines | Quote producer emits this at stream end; current `tcqu.ocr` uses it to invoke spool-note/table rendering and does not display its body. |

The future parser should retain original record order even after constructing a typed document model. This permits byte/behaviour-level compatibility diagnostics and protects against template-side state transitions that have not yet been discovered.

## Current template coverage

| Template | Working document type | Principal records |
|---|---|---|
| `tcinv.ocr` | Invoice | S006–S009, S012, S015–S016, S018–S019, relevant V records |
| `tcord.ocr` | Order confirmation | S006–S009, S012–S013, S015–S016, S018–S020, relevant V records; paired example received |
| `tcpick.ocr` | Pick list | S006–S009, S012–S013, S015–S016, S018–S020, V records except the unresolved V9241 gap; paired example received |
| `tcpkls.ocr` | Packing note/delivery order | Generic warehouse-document record family |
| `tcpo.ocr` | Purchase order | Separate named protocol |
| `tcqu.ocr` | Quotation | S006–S009, S011–S012, S016, S018–S019, S026, relevant V records; paired example received |
| `tcqu.moq.ocr` | MOQ quotation | Quote family, with unresolved duplicate price display |
| `tcstat.ocr` | Debtor statement | S006–S008, S012, S021–S025 |
| `tcstkrec.ocr` | Stock receipt | Generic warehouse-document record family |
| `F001` | Address/barcode form | S001-like standalone record; producer unresolved |

## Compatibility rules for a replacement

The first replacement implementation should preserve these behaviours explicitly:

1. Parse the count line and require exactly the declared fields, including trailing blanks. Keep a separate diagnostic mode for inspecting damaged captures; do not silently accept short production records.
2. Preserve raw field values alongside typed values.
3. Parse and validate the `.RET.` selector against an allowlisted template mapping; never execute an arbitrary path from the stream.
4. Select S006 and S009 schemas by document type, not merely by record ID.
5. Preserve V-record ordering, labels, item association, and text line breaks.
6. Keep raw numeric values distinct from preformatted display amounts.
7. Accept CRLF and LF input and decode the agreed production encoding explicitly; UTF-8 is confirmed only for the supplied captures.
8. Apply legacy date, time, currency, rounding, and blank-value formatting in a dedicated compatibility layer.
9. Model company/legal-entity branding as data rather than PHP/template conditionals on unexplained numeric literals.
10. Log unknown S/V records, surplus fields, and unsupported renderer side effects; do not silently discard them during migration.
11. Retain a raw-stream fixture and an approved rendered artifact for each supported scenario.
12. Avoid exposing positional arrays as the public API of the shared package.

## Recommended future model boundaries

The new shared package should translate legacy records into typed concepts such as:

- `DocumentHeader`
- `LegalEntity`
- `Address`
- `CustomerContact`
- `DeliveryDetails`
- `DocumentLine`
- `TaxLine`
- `DocumentEvent`
- `InstructionBlock`
- `StatementAccount`
- `StatementInvoice`
- `Money` and `FormattedMoney`

Keep a separate `LegacyOcrAdapter` responsible for S/V identifiers, positional mappings, exact blank semantics, and legacy formatting. This prevents an accidental recreation of the current protocol as the permanent PHP domain API.

## Outstanding information required

The dictionary can be made production-authoritative when the following items are supplied or confirmed.

### System and deployment

- [ ] Confirm that `D:\TriCab\side-projects\4js-main` matches the 4JS version currently producing production documents.
- [ ] List which of the ten `ds/` document definitions are still invoked in production.
- [ ] Identify every current caller, including batch jobs, desktop clients, APIs, and scheduled tasks.
- [ ] Confirm whether PDF is the only required output or whether PCL, direct printing, email attachment, archival, or other output modes remain required.
- [ ] Confirm the PHP versions and framework/package constraints in `tricab-core` and `tricab-api`.

### Business meanings and ownership

- [ ] Complete the company-code mapping. Code `7` is now confirmed as TriCab (Australia) Pty. Ltd; codes `16`, `19`, `20`, and any others still need legal entity, registrations, branding, bank details, and document rules.
- [ ] Confirm the database/source definition of S006 field 8 / `opa`; its visible role as an entry/sales operator login or alpha is now confirmed.
- [ ] Complete the S006 fields 22–26 enum mapping. Pick behaviour confirms field 22 credit hold, field 24 manufacturer display, and field 25 early-delivery permission; PR-batch values and production-warehouse meanings remain to be confirmed.
- [ ] Confirm the unit and calculation of S023 field 9 (statement invoice age).
- [ ] Confirm whether S019 field 6 is always a tax rate, an item price, or varies by document.
- [ ] Document the V4204 checksum algorithm and whether it is displayed, scanned, or used only internally.

### Protocol status

- [ ] Decide whether S010, S014, and S017 are active, retained for compatibility, or obsolete.
- [ ] Find the current producer and business owner for S001/F001, or approve its retirement.
- [ ] Find the current producer and business owner for `tcpo.ocr`, or approve its retirement.
- [ ] Resolve the V9241 producer/consumer mismatch for pick lists.
- [ ] Resolve whether lines beginning with `.` that appear disabled in templates are comments, historical code, or feature switches.
- [ ] Confirm whether templates exist outside `D:\TriCab\document-generator\ds` or are deployed/generated into another location.

### Known display ambiguities

- [ ] Resolve the duplicate Unit Price/MoQ Price source in `tcqu.moq.ocr`.
- [ ] Confirm why S023 contains two invoice/reference fields and when they differ.
- [ ] Confirm all copy/draft values outside the quotation flow.
- [ ] Confirm whether blank and one-space values are deliberately distinguished in production.
- [ ] Confirm the exact address-line construction and overflow rules for company, customer, delivery, invoice, and supplier addresses.
- [ ] Confirm whether overloaded S006/S009 layouts are contractual or merely implementation accidents that can be normalized behind an adapter.

### Samples and acceptance evidence

- [x] Receive initial raw streams and rendered PDFs for order confirmation, pick list, and quotation.
- [ ] Capture an exact same-publication-event pair for order confirmation and pick list; the supplied pairs have later PDF publication times/users.
- [ ] Supply raw streams and matching rendered outputs for invoice, packing note, stock receipt, statement, and purchase order/F001 if active.
- [ ] Include original, copy, and draft examples where supported.
- [ ] Include domestic and international addresses, long names, blank address lines, and overflow cases.
- [ ] Include taxable, tax-exempt, multi-tax, rounding, discount, and credit/negative-value examples.
- [ ] Include multi-currency examples and every active legal entity.
- [ ] Include long and multiline V4202/V4203/V4204/V4238/V4270/V4276/V4281/V4293/V9241/V9245 content where applicable.
- [ ] Include multi-page documents, page breaks near totals, and documents with no item lines where allowed.
- [ ] Include statement ageing groups, zero balances, credits, and multiple account/customer groups.
- [ ] Include warehouse scenarios for substitution, credit hold, PR batch, early delivery, production warehouse, manufacturer codes, and bin locations.
- [ ] Define which differences are acceptable in replacement output: exact pixels, page geometry, text/content equivalence, or business approval.

## Minimum fixture matrix

| Document | Received | Minimum baseline fixtures | Additional risk fixture |
|---|---:|---:|---|
| Invoice | 0 | 3 | Multi-page, tax rounding, copy, Incoterm, AP email/fax |
| Order confirmation | 1 | 3 | Exact-event pair, long delivery address, partial/early delivery, multiple due dates |
| Quotation | 1 | 3 | Draft/copy, COMEX S026, long notes whose intended visibility is confirmed |
| MOQ quotation | 0 | 2 | Different unit and MOQ prices to expose current ambiguity |
| Pick list | 1 | 3 | Exact-event pair, V9241 instructions, substitution, bin locations |
| Packing note | 0 | 3 | Tailgate/carrier/consignment and multi-page content |
| Stock receipt | 0 | 2 | Manufacturer codes, warehouse and batch fields |
| Statement | 0 | 3 | Multiple ageing buckets, credits, multiple customers/groups |
| Purchase order | 0 | 1 if active | All named blocks and approval fields |
| F001 form | 0 | 1 if active | Barcode plus long split address field |

## Change-control rule

Any newly discovered record, field variant, enum value, or renderer mutation should be added here with:

- the exact producer and consumer paths;
- an anonymized raw-stream excerpt;
- an approved rendered example;
- its document-type context;
- confirmed blank/null behaviour;
- a regression fixture identifier;
- a confidence status and business owner.
