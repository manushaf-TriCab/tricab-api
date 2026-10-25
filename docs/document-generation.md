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

Rendering lives in [tricab/docgen](https://github.com/tricab/docgen), a private Composer package that other PHP projects use as well: the document model, the 4js `.ocr` reader, the templates, the stylesheet and the images. The record dictionary for the `.ocr` fields is in that repository (`docs/legacy-record-dictionary.md`).

`tricab-api` owns delivery:

- `GenerateOcrPdfService`: `.ocr` stream → `OcrDocumentReader` → `DocGen::render()`, with the download names above
- `QuoteDetailsToDocGenQuoteMapper` and `GenerateQuoteTemplatePdfService` for `GET /api/v1/quotes/{id}/document`, which renders a quote from the quote read model
- dependency-injection bindings
- authentication and the Slim HTTP actions

Installing the package needs read access to `tricab/docgen`:

- **Locally:** your own GitHub access to `tricab/docgen`, given to Composer once with `composer config --global github-oauth.github.com "$(gh auth token)"` (or a personal token).
- **GitHub Actions:** the `DOCGEN_DEPLOY_KEY` secret, the private half of a read-only deploy key on `tricab/docgen` (Settings → Deploy keys). `.github/actions/docgen-ssh` loads it, and Composer clones docgen over SSH from source; the release build drops its `.git` folder. A deploy key belongs to the repository, so it doesn't expire or depend on any one person. To rotate it: generate a new key pair, add the public key to docgen, replace the secret, then delete the old deploy key.

## Current limitations

- Only `tcord.ocr`, `tcpick.ocr`, and `tcqu.ocr` are allowlisted.
- Order confirmations and pick lists always print "Page 1 of 1"; quotations number their pages.
- The supplied order fixture has four omitted trailing blank fields and is accepted with a warning.
- Legal-entity branding is not yet configuration-driven.
- The quotation intentionally matches the observed omission of `V4270` body text.
