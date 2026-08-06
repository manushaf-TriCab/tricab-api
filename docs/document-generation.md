# Document-generation endpoint

## Contract

```http
POST /api/v1/documents/pdf
Authorization: Bearer <Microsoft Entra access token>
Content-Type: text/plain; charset=utf-8
Accept: application/pdf

<raw .RET./.RER. OCR stream>
```

The `Authorization` header is optional only when development authentication is active. For a short internal test without Azure SQL, use `APP_ENV=development`, `DEV_AUTH_ENABLED=true`, and `USER_REPOSITORY=memory`. Supported request media types are:

- `text/plain`
- `application/octet-stream`
- `application/x-ocr`

The maximum request body is 5 MB. Submitted streams are processed in memory and are not persisted by the document-generation code.

## Success response

The response body contains the PDF bytes and uses these representative headers:

```http
HTTP/1.1 200 OK
Content-Type: application/pdf
Content-Disposition: attachment; filename="order-confirmation.pdf"
Cache-Control: no-store, private
X-OCR-Template: tcord.ocr
X-OCR-Warning-Count: 0
```

The filename and template header vary by document type:

| OCR selector | PDF filename |
|---|---|
| `tcord.ocr` | `order-confirmation.pdf` |
| `tcpick.ocr` | `pick-list.pdf` |
| `tcqu.ocr` | `quotation.pdf` |

## Errors

Errors use the API's normal JSON envelope.

| Status | Meaning |
|---|---|
| `400` | Empty request body |
| `401` | No authenticated identity |
| `403` | Identity is unlinked or the account cannot authenticate |
| `413` | Request exceeds 5 MB |
| `415` | Unsupported request media type |
| `422` | Malformed stream or unsupported OCR selector |
| `500` | Rendering failed unexpectedly |

## Architecture

`tricab-core` owns the reusable `DocumentGeneration` module:

- `LegacyStreamParser` and the legacy record model
- `LegacyDocumentData`
- `PdfRenderer` contract
- `GenerateOcrPdfHandler`

`tricab-api` owns infrastructure and delivery:

- `DompdfPdfRenderer`
- HTML/CSS templates and image assets
- dependency-injection bindings
- authentication and the Slim HTTP action

This split keeps Dompdf, filesystem resources, Slim, and PSR-7 outside Core while allowing other applications to reuse the parser and generation use case with their own `PdfRenderer` adapter.

## Current limitations

- Only `tcord.ocr`, `tcpick.ocr`, and `tcqu.ocr` are allowlisted.
- Current captured examples are single-page documents; multi-page behaviour needs additional fixtures.
- The supplied order fixture has four omitted trailing blank fields and is accepted with a warning.
- Legal-entity branding is not yet configuration-driven.
- The quotation intentionally matches the observed omission of `V4270` body text.
