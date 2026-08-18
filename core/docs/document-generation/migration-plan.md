# TriCab Document Generator Replacement Plan

## Objective

Replace the legacy `.ocr` document definitions in `ds/` with a maintainable document-generation capability shared across the TriCab platform.

The replacement will use:

- `D:\TriCab\Projects\tricab-core` for framework-independent document models, business rules, contracts, and use cases.
- `D:\TriCab\Projects\tricab-api` for rendering infrastructure, templates, legacy-data adapters, configuration, and HTTP endpoints.
- A proven PDF renderer rather than a custom PDF engine.

The same rendering implementation must serve every consuming tool. Renderer or template code must not be copied between applications.

## Initial Architectural Direction

```text
Calling application or legacy records
                |
                v
        TriCab Core document model
                |
                v
        GenerateDocumentHandler
                |
                v
        DocumentRenderer contract
                |
                v
    TriCab API rendering implementation
                |
                v
        HTML/CSS -> Chromium -> PDF
```

### Responsibilities

`tricab-core` will own:

- Typed document data and value objects.
- Document-specific business rules and validation.
- Calculations such as totals, tax, payments, and balance due.
- Application use cases for generating documents.
- Contracts implemented by rendering infrastructure.

`tricab-core` must remain independent of Slim, HTTP, Twig, Chromium, Gotenberg, filesystem configuration, and environment variables.

`tricab-api` will own:

- The concrete PDF renderer.
- HTML/CSS templates and static assets.
- Legacy record mapping for inputs such as `S006`, `S009`, and `S019`.
- HTTP request validation, authentication, authorization, and responses.
- Renderer configuration, temporary files, logging, and operational health checks.

Other tools and services should normally call `tricab-api` to generate a document. If multiple PHP applications later require offline or in-process generation, extract the rendering implementation into a separate `tricab/tricab-documents` Composer package instead of duplicating it.

## Decisions Required Before Production Migration

- [ ] Confirm whether PDF is the only required output format.
- [ ] Confirm whether direct PCL/printer output is still in use.
- [ ] Decide whether output must be pixel-identical or only functionally equivalent.
- [ ] Confirm whether the legacy record protocol must remain supported indefinitely or only during migration.
- [ ] Select the production renderer after a proof of concept.
- [ ] Define document retention, audit, and access-control requirements.

The recommended renderer candidate is Chromium through Gotenberg. Browsershot is a possible local integration, and mPDF is the fallback if the runtime must remain PHP-only.

## Proposed Project Structure

### `tricab-core`

```text
src/Documents/
  Module.php
  Domain/
    Document.php
    DocumentType.php
    Address.php
    Money.php
    TaxSummary.php
    Customer.php
    DocumentLine.php
    Invoice.php
    Statement.php
    Quotation.php
    PickList.php
    PackingNote.php
    PurchaseOrder.php
    StockReceipt.php
  Application/
    DTO/
    Exception/
    Handler/
      GenerateDocumentHandler.php
  Contract/
    DocumentRenderer.php
```

The exact model split should follow discovered business concepts rather than mechanically mirroring every legacy array.

### `tricab-api`

```text
src/Documents/
  Http/
    Action/
      GenerateDocumentAction.php
    Request/
      GenerateDocumentRequest.php
  Legacy/
    LegacyRecordMapper.php
    LegacyRecordMappingException.php
  Rendering/
    GotenbergDocumentRenderer.php
    RenderingException.php
    TemplateRegistry.php
    Templates/
      components/
      statement/
      invoice/
      quotation/
    Assets/
      fonts/
      images/
```

## Proposed Core Contract

The initial contract should return PDF bytes without exposing a renderer-specific type:

```php
interface DocumentRenderer
{
    public function render(Document $document, RenderOptions $options): RenderedDocument;
}
```

`RenderedDocument` should contain at least:

- MIME type.
- Suggested filename.
- Binary contents.
- Page count when available.
- Optional renderer metadata for diagnostics, without leaking it into the domain model.

## Proposed API

Initial endpoint:

```http
POST /api/v1/documents/{documentType}/pdf
Content-Type: application/json
Accept: application/pdf
```

Successful response:

```http
200 OK
Content-Type: application/pdf
Content-Disposition: attachment; filename="invoice-12345.pdf"
```

Use consistent JSON error responses for invalid document data, unsupported types, rendering failures, and unavailable rendering infrastructure.

Do not make the HTTP payload a direct representation of `S006`, `S009`, or other positional records. If legacy payloads are temporarily accepted, expose a clearly marked compatibility endpoint or adapter.

## Work Phases

### Phase 0: Discovery and Baseline

- [ ] Identify which of the nine root `.ocr` templates are still used.
- [ ] Identify all current callers and output destinations.
- [ ] Confirm output formats and printer requirements.
- [ ] Locate representative input data for each active template.
- [ ] Generate and preserve representative legacy PDFs as golden fixtures.
- [ ] Document the meaning of every used record and positional field.
- [ ] Record external dependencies such as logos, product thumbnails, fonts, `lngdate`, and `nmfmt`.
- [ ] Separate active behavior from obsolete company-, branch-, and user-specific rules.

Deliverable: a document inventory and legacy field mapping reviewed by business owners.

### Phase 1: Renderer Proof of Concept

- [ ] Create the smallest possible HTML-to-PDF rendering spike.
- [ ] Test A4 portrait and landscape output.
- [ ] Test repeating headers and footers.
- [ ] Test `Page X of Y` numbering.
- [ ] Test tables spanning several pages.
- [ ] Test keep-together and explicit page-break rules.
- [ ] Test local fonts, SVG/PNG logos, and product thumbnails.
- [ ] Test Code 128 barcode rendering.
- [ ] Measure cold and warm rendering behavior.
- [ ] Verify temporary-file cleanup and failure handling.
- [ ] Document deployment requirements.

Deliverable: a short renderer decision record selecting Gotenberg/Chromium, Browsershot, mPDF, or another evaluated engine.

### Phase 2: Core Documents Module

- [ ] Add the `TriCab\Core\Documents` module.
- [ ] Implement shared value objects such as `Money` and `Address`.
- [ ] Implement the base document and line-item concepts.
- [ ] Add the renderer contract and rendered-result type.
- [ ] Implement generation use cases without infrastructure dependencies.
- [ ] Add unit tests for validation, totals, tax, and balance calculations.
- [ ] Run `composer check` in `tricab-core`.

Deliverable: a tested, framework-independent document model and rendering port.

### Phase 3: API Rendering Infrastructure

- [ ] Add the chosen renderer dependency or HTTP client.
- [ ] Implement `DocumentRenderer` in `tricab-api`.
- [ ] Register it in the PHP-DI container.
- [ ] Add configuration with safe defaults and startup validation.
- [ ] Add health diagnostics for the rendering dependency.
- [ ] Create shared template components for page setup, branding, addresses, tables, totals, and page numbers.
- [ ] Add HTTP actions and routes.
- [ ] Add unit and integration tests.
- [ ] Run `composer check` in `tricab-api`.

Deliverable: an authenticated endpoint capable of returning a generated PDF.

### Phase 4: First Vertical Slice — Statement

Migrate `ds/tcstat.ocr` first because it exercises customer addressing, multipage rows, totals, and ageing without the full invoice delivery and tax complexity.

- [ ] Define the statement model.
- [ ] Map legacy statement records.
- [ ] Reproduce current, 30-day, 60-day, and 90+-day ageing.
- [ ] Implement the statement template.
- [ ] Compare representative old and new PDFs.
- [ ] Obtain business review and approval.

Deliverable: production-ready statement generation through the API.

### Phase 5: Second Vertical Slice — Invoice

Migrate `ds/tcinv.ocr` to validate the difficult shared behavior.

- [ ] Define invoice, payment, tax, and delivery models.
- [ ] Support tax and commercial invoice variants.
- [ ] Support copy markers and company-specific branding.
- [ ] Support multipage item tables and final-page totals.
- [ ] Support payment, amount paid, and balance due.
- [ ] Support delivery details, comments, and payment instructions.
- [ ] Compare representative old and new PDFs.
- [ ] Obtain finance and operations approval.

Deliverable: production-ready invoice generation and a reusable foundation for the remaining sales documents.

### Phase 6: Remaining Documents

Migrate and approve each remaining active form independently:

- [ ] `tcord.ocr` — order confirmation.
- [ ] `tcpick.ocr` — pick list and product thumbnails.
- [ ] `tcpkls.ocr` — packing note/delivery order.
- [ ] `tcqu.ocr` — quotation.
- [ ] `tcqu.moq.ocr` — MOQ quotation variant; decide whether it becomes a template option instead of a separate document type.
- [ ] `tcpo.ocr` — purchase order; remove hard-coded historical people and addresses.
- [ ] `tcstkrec.ocr` — stock receipt.
- [ ] `F001` — landscape address/barcode form, if still required.

### Phase 7: Parallel Run and Retirement

- [ ] Run legacy and new generators in parallel for an agreed verification period.
- [ ] Record output differences and classify them as defects or approved changes.
- [ ] Monitor rendering failures, latency, and resource use.
- [ ] Switch callers to the new API incrementally.
- [ ] Preserve the legacy source and approved fixtures as read-only migration evidence.
- [ ] Remove access to the old runtime after rollback is no longer required.
- [ ] Remove workstation-history artifacts from any distributable package.

## Testing Strategy

### Core tests

- Value-object validation.
- Totals, tax, payments, and balance calculations.
- Required data for each document type.
- Company and currency-specific business rules.
- Legacy-to-domain mapping.

### API and renderer tests

- Request validation and authorization.
- Correct MIME type and filename.
- Renderer unavailable and timeout behavior.
- Missing images, fonts, and template errors.
- Page-size, orientation, and page-number verification.
- No unintended outbound network access from document templates.

### Visual regression tests

- Render PDFs into page images in CI.
- Compare approved fixtures at a controlled tolerance.
- Extract and compare important text values independently of the image comparison.
- Verify page count and expected barcode content.
- Keep dynamic dates and identifiers deterministic in fixtures.

## Security and Operational Requirements

- Treat all incoming text as untrusted data and escape it in templates.
- Do not permit arbitrary user-supplied URLs or HTML to reach Chromium.
- Use bundled or allowlisted assets only.
- Apply request size, document line count, timeout, and concurrency limits.
- Avoid logging document contents or sensitive customer data.
- Use dedicated temporary storage and guarantee cleanup.
- Authenticate callers and authorize document access.
- Record enough metadata for operational tracing without storing PDF contents unnecessarily.
- Pin Composer, container, browser, and font versions for reproducible output.

## Primary Risks

| Risk | Mitigation |
|---|---|
| Undocumented positional fields | Build and review an explicit legacy field map before migration. |
| Missing legacy runtime or fixtures | Capture representative inputs and outputs before changing callers. |
| Browser pagination differences | Prove difficult multipage cases early and use visual regression tests. |
| Hidden PCL requirement | Confirm output and printing requirements during Phase 0. |
| Business rules embedded in layout code | Move rules into Core and test them independently. |
| Renderer code duplicated by consumers | Centralize generation in the API; extract one Composer package only if direct reuse is required. |
| Environment-specific rendering | Pin the rendering container, fonts, locale, and timezone. |

## Definition of Done

The replacement is complete when:

- All active legacy forms have an approved new implementation.
- Document rules are represented by typed Core models rather than positional arrays.
- All production callers use the shared API or an approved shared Composer package.
- Automated unit, integration, and visual regression tests pass.
- Rendering is reproducible in the target deployment environment.
- Security, authorization, monitoring, and failure behavior are documented and tested.
- The old `.ocr` runtime is no longer required for production generation.
- The legacy source and golden fixtures are retained according to the agreed archival policy.

## Immediate Next Actions

1. Confirm PDF/PCL and fidelity requirements.
2. Locate sample input/output pairs for `tcstat.ocr` and `tcinv.ocr`.
3. Write the legacy record field map for those two forms.
4. Run a Gotenberg/Chromium proof of concept for a multipage statement.
5. Add the initial `Documents` module skeleton to `tricab-core` only after the proof-of-concept contract is understood.
